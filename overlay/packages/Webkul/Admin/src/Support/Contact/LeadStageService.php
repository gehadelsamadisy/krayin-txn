<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Contact\Models\Person;

/**
 * Moves a person's open leads to the stage that matches what just happened.
 * Stages are found by their TXN role (StageRoles, set in Settings > TXN
 * Settings > Stage rules), never by name or code, so admins can rename, add
 * and remove stages in Krayin's pipeline editor. A move whose role no stage
 * holds in that pipeline is skipped (the settings screen warns about it).
 * Won and lost leads are never moved. Each move is recorded in the lead's
 * history (a "system" activity with the old and new stage and the time).
 *
 * Default stages: Raw Lead → Needs Review → (roof: Waiting for Roof Report →
 * Roof Report Received) → Contacted → Appointment Scheduled → Canceled →
 * Visit Done → Estimate Sent → Won - Job Sold → Lost. Visit Done, Estimate
 * Sent and Won are set by staff on the board (Cal.com visits are read-only).
 */
final class LeadStageService
{
    public const RAW_LEAD = StageRoles::NEW;

    public const NEEDS_REVIEW = StageRoles::NEEDS_REVIEW;

    public const CONTACTED = StageRoles::CONTACTED;

    public const APPOINTMENT_SET = StageRoles::SCHEDULED;

    public const APPOINTMENT_CANCELED = StageRoles::CANCELED;

    public const WAITING_ROOF_REPORT = StageRoles::WAITING_ROOF_REPORT;

    public const ROOF_REPORT_RECEIVED = StageRoles::ROOF_REPORT_RECEIVED;

    /** Krayin's closing stages, by code (not roles). */
    private const CLOSED = ['won', 'lost'];

    /** Pseudo-role for Krayin's Lost stage (found by its code). */
    private const LOST = 'lost';

    /** Staff may still send a link by hand to a lead in Needs Review. */
    private const LINK_SENT_FROM = [self::RAW_LEAD, self::NEEDS_REVIEW, self::ROOF_REPORT_RECEIVED, self::APPOINTMENT_CANCELED];

    public function __construct(private StageRoles $roles) {}

    /** The role of the lead's current stage, or null (a stage staff added without a role, Won, Lost ...). */
    public function roleOf($lead): ?string
    {
        return $this->roles->roleOf((int) $lead->lead_pipeline_stage_id);
    }

    /**
     * Booking link emailed: only leads not yet contacted, or whose appointment
     * was canceled. With $lead (link sent from the lead page): that lead only.
     */
    public function linkSent(Person $person, $lead = null): int
    {
        return $lead
            ? (int) $this->moveLead($lead, self::CONTACTED, self::LINK_SENT_FROM)
            : $this->move($person, self::CONTACTED, self::LINK_SENT_FROM);
    }

    /** Lead check passed after a problem was fixed: Needs Review → New lead (ready for a booking link). */
    public function readyForLink($lead): bool
    {
        return $this->moveLead($lead, self::RAW_LEAD, [self::NEEDS_REVIEW]);
    }

    /**
     * Lead check found a problem: into Needs Review, in the lead's own
     * pipeline. A lead in Roof Report Received stays there (only tagged), so
     * fixing the problem doesn't send it back to Waiting for Roof Report.
     */
    public function needsReview($lead): bool
    {
        return $this->moveLead($lead, self::NEEDS_REVIEW, [self::RAW_LEAD, self::WAITING_ROOF_REPORT]);
    }

    /**
     * Lead check: roof replacement with nothing else wrong. Moves it from New
     * lead or Needs Review into the roof pipeline (the one whose stage has
     * the Waiting for roof report role), so only roof leads show those stages.
     */
    public function waitingForRoofReport($lead): bool
    {
        return $this->moveLead($lead, self::WAITING_ROOF_REPORT, [self::RAW_LEAD, self::NEEDS_REVIEW], $this->roofPipelineId());
    }

    /** Lead check: a roof-pipeline lead whose service is no longer roof replacement goes back to the main pipeline's New lead stage. */
    public function backToStandard($lead): bool
    {
        if (! $this->inRoofPipeline($lead)) {
            return false;
        }

        return $this->moveLead($lead, self::RAW_LEAD, [self::RAW_LEAD, self::NEEDS_REVIEW, self::WAITING_ROOF_REPORT, self::ROOF_REPORT_RECEIVED], $this->defaultPipelineId());
    }

    public function inRoofPipeline($lead): bool
    {
        $roofId = $this->roofPipelineId();

        return $roofId !== null && (int) $lead->lead_pipeline_id === $roofId;
    }

    private function roofPipelineId(): ?int
    {
        return $this->roles->pipelineWith(self::WAITING_ROOF_REPORT);
    }

    private function defaultPipelineId(): ?int
    {
        $id = DB::table('lead_pipelines')->where('is_default', 1)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Follow-ups ran out with no booking: Contacted → Lost, with Krayin's
     * lost reason and closed date filled in (as its own "mark as lost" does).
     */
    public function lost($lead, string $reason): bool
    {
        if (! $this->moveLead($lead, self::LOST, [self::CONTACTED])) {
            return false;
        }

        $lead->update(['lost_reason' => $reason, 'closed_at' => now()]);

        return true;
    }

    /** Booking events: the link's lead when it has one, else all the person's leads. */
    public function booked(Person $person, $lead = null): int
    {
        return $lead ? (int) $this->moveLead($lead, self::APPOINTMENT_SET) : $this->move($person, self::APPOINTMENT_SET);
    }

    /** A reschedule keeps (or puts) the lead in Appointment Scheduled; the new time is on the visit itself. */
    public function rescheduled(Person $person, $lead = null): int
    {
        return $lead ? (int) $this->moveLead($lead, self::APPOINTMENT_SET) : $this->move($person, self::APPOINTMENT_SET);
    }

    public function canceled(Person $person, $lead = null): int
    {
        return $lead ? (int) $this->moveLead($lead, self::APPOINTMENT_CANCELED) : $this->move($person, self::APPOINTMENT_CANCELED);
    }

    /**
     * @param  array|null  $fromRoles  only leads now in a stage with one of these roles; null = any open stage
     * @return int leads moved
     */
    private function move(Person $person, string $role, ?array $fromRoles = null): int
    {
        $moved = 0;

        foreach ($person->leads()->with('stage')->get() as $lead) {
            $moved += $this->moveLead($lead, $role, $fromRoles) ? 1 : 0;
        }

        return $moved;
    }

    /**
     * @param  string  $role  the target stage's role (or self::LOST)
     * @param  int|null  $pipelineId  move into this pipeline's stage with $role; null = the lead's own pipeline
     * @return bool whether the lead moved
     */
    private function moveLead($lead, string $role, ?array $fromRoles = null, ?int $pipelineId = null): bool
    {
        $lead->loadMissing('stage');

        if (in_array($lead->stage?->code, self::CLOSED, true)) {
            return false;
        }

        if ($fromRoles !== null && ! in_array($this->roleOf($lead), $fromRoles, true)) {
            return false;
        }

        $pipelineId ??= (int) $lead->lead_pipeline_id;
        $stages = $lead->stage()->getRelated()->newQuery();
        $targetId = $role === self::LOST
            ? (clone $stages)->where('lead_pipeline_id', $pipelineId)->where('code', 'lost')->value('id')
            : $this->roles->stageId($pipelineId, $role);

        // No stage with that role here (the settings screen warns), or already there.
        if (! $targetId || (int) $targetId === (int) $lead->lead_pipeline_stage_id) {
            return false;
        }

        $target = (clone $stages)->find($targetId);
        $from = $lead->stage;

        Event::dispatch('lead.update.before', $lead->id);

        $lead->update([
            'lead_pipeline_id'       => $target->lead_pipeline_id,
            'lead_pipeline_stage_id' => $target->id,
        ]);
        $lead->setRelation('stage', $target);
        $lead->unsetRelation('pipeline');

        // Krayin logs stage changes made in its UI, but not ones made in code
        // (its model change log skips the stage), so the move is recorded
        // here exactly like Krayin's own "Updated Stage : A → B" entries.
        $history = ActivityProxy::modelClass()::create([
            'type'       => 'system',
            'title'      => trans('admin::app.activities.updated', ['attribute' => 'Stage']),
            'is_done'    => 1,
            'user_id'    => auth()->id(),
            'additional' => json_encode([
                'attribute' => 'Stage',
                'old' => ['value' => $from?->id, 'label' => $from?->name],
                'new' => ['value' => $target->id, 'label' => $target->name],
            ]),
        ]);
        $lead->activities()->attach($history->id);

        Event::dispatch('lead.update.after', $lead);

        return true;
    }
}
