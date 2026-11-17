<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Contact\Models\Person;

/**
 * Moves a person's open leads to the TXN stage that matches what just
 * happened (stages: migrations 2026_11_05_000100_add_txn_lead_stages and
 * 2026_11_18_000200_txn_pipeline_v2). Won and lost leads are never moved.
 * Each move is recorded in the lead's history (a "system" activity with the
 * old and new stage and the time).
 *
 * Raw Lead → Needs Review → (roof: Waiting for Roof Report → Roof Report
 * Received) → Contacted → Appointment Scheduled → Canceled → Visit Done →
 * Estimate Sent → Won - Job Sold → Lost. Stage codes are the old ones (only
 * the names changed), e.g. Visit Done is still `prospect`. Visit Done,
 * Estimate Sent and Won are set by staff on the board (Cal.com visits are
 * read-only in Krayin, so nothing ticks them done).
 */
final class LeadStageService
{
    public const RAW_LEAD = 'new';

    /** The lead check found a problem a person has to look at. */
    public const NEEDS_REVIEW = 'needs-review';

    public const CONTACTED = 'contacted';

    /** "Appointment Scheduled" (code from when it was "Appointment Set"). */
    public const APPOINTMENT_SET = 'appointment-set';

    /** "Canceled". */
    public const APPOINTMENT_CANCELED = 'appointment-canceled';

    private const CLOSED = ['won', 'lost'];

    /** Roof replacement: waiting for the roof report (set by the lead check). */
    public const WAITING_ROOF_REPORT = 'waiting-roof-report';

    /** Roof replacement: report in (set by staff); a booking link can follow. */
    public const ROOF_REPORT_RECEIVED = 'roof-report-received';

    /** Staff may still send a link by hand to a lead in Needs Review. */
    private const LINK_SENT_FROM = [self::RAW_LEAD, self::NEEDS_REVIEW, self::ROOF_REPORT_RECEIVED, self::APPOINTMENT_CANCELED];

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

    /** Lead check passed after a problem was fixed: Needs Review → Raw Lead (ready for a booking link). */
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
     * Lead check: roof replacement with nothing else wrong. Moves it from Raw
     * Lead or Needs Review into the Roof Replacement pipeline (the one with
     * the roof stages; migration 2026_11_08_000100), so only roof leads show
     * those stages.
     */
    public function waitingForRoofReport($lead): bool
    {
        return $this->moveLead($lead, self::WAITING_ROOF_REPORT, [self::RAW_LEAD, self::NEEDS_REVIEW], $this->roofPipelineId());
    }

    /** Lead check: a roof-pipeline lead whose service is no longer roof replacement goes back to the main pipeline's Raw Lead. */
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

    /** The pipeline that has the roof stages (found by stage code, so renaming it is fine). */
    private function roofPipelineId(): ?int
    {
        $id = DB::table('lead_pipeline_stages')->where('code', self::WAITING_ROOF_REPORT)->value('lead_pipeline_id');

        return $id ? (int) $id : null;
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
        if (! $this->moveLead($lead, 'lost', [self::CONTACTED])) {
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
     * @param  array|null  $fromCodes  only leads now in one of these stages; null = any open stage
     * @return int leads moved
     */
    private function move(Person $person, string $code, ?array $fromCodes = null): int
    {
        $moved = 0;

        foreach ($person->leads()->with('stage', 'pipeline.stages')->get() as $lead) {
            $moved += $this->moveLead($lead, $code, $fromCodes) ? 1 : 0;
        }

        return $moved;
    }

    /**
     * @param  int|null  $pipelineId  move into this pipeline's stage with $code; null = the lead's own pipeline
     * @return bool whether the lead moved
     */
    private function moveLead($lead, string $code, ?array $fromCodes = null, ?int $pipelineId = null): bool
    {
        $lead->loadMissing('stage', 'pipeline.stages');
        $current = $lead->stage?->code;

        // Same code counts as "already there" only within the same pipeline
        // (Raw Lead in Roof Replacement → Raw Lead in the main pipeline is a move).
        $samePipeline = $pipelineId === null || (int) $lead->lead_pipeline_id === $pipelineId;

        if (in_array($current, self::CLOSED, true) || ($current === $code && $samePipeline)) {
            return false;
        }

        if ($fromCodes !== null && ! in_array($current, $fromCodes, true)) {
            return false;
        }

        $target = $pipelineId
            ? $lead->stage()->getRelated()->newQuery()->where('lead_pipeline_id', $pipelineId)->where('code', $code)->first()
            : $lead->pipeline?->stages->firstWhere('code', $code);

        if (! $target) {
            return false;
        }

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
