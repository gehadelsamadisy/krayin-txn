<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Contact\Models\Person;

/**
 * Moves a person's open leads to the TXN stage that matches what just
 * happened (migration 2026_11_05_000100_add_txn_lead_stages). Won and lost
 * leads are never moved. Each move is recorded in the lead's history (a
 * "system" activity with the old and new stage and the time).
 */
final class LeadStageService
{
    public const CONTACTED = 'contacted';

    public const APPOINTMENT_SET = 'appointment-set';

    public const APPOINTMENT_RESCHEDULED = 'appointment-rescheduled';

    public const APPOINTMENT_CANCELED = 'appointment-canceled';

    private const CLOSED = ['won', 'lost'];

    public const PRE_QUALIFIED = 'pre-qualified';

    /** Roof replacement: waiting for the roof report (set by the lead check). */
    public const WAITING_ROOF_REPORT = 'waiting-roof-report';

    /** Roof replacement: report in (set by staff); a booking link can follow. */
    public const ROOF_REPORT_RECEIVED = 'roof-report-received';

    private const LINK_SENT_FROM = ['new', self::PRE_QUALIFIED, self::ROOF_REPORT_RECEIVED, self::APPOINTMENT_CANCELED];

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

    /** Lead check passed (service known, location fine, can be sent a link): only from Raw Lead. */
    public function preQualified($lead): bool
    {
        return $this->moveLead($lead, self::PRE_QUALIFIED, ['new']);
    }

    /**
     * Lead check: roof replacement with nothing else wrong. Moves it from Raw
     * Lead or Pre-Qualified into the Roof Replacement pipeline (the one with
     * the roof stages; migration 2026_11_08_000100), so only roof leads show
     * those stages.
     */
    public function waitingForRoofReport($lead): bool
    {
        return $this->moveLead($lead, self::WAITING_ROOF_REPORT, ['new', self::PRE_QUALIFIED], $this->roofPipelineId());
    }

    /** Lead check: a roof-pipeline lead whose service is no longer roof replacement goes back to the main pipeline. */
    public function backToStandard($lead): bool
    {
        return $this->moveLead($lead, self::PRE_QUALIFIED, [self::WAITING_ROOF_REPORT, self::ROOF_REPORT_RECEIVED], $this->defaultPipelineId());
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

    public function rescheduled(Person $person, $lead = null): int
    {
        return $lead ? (int) $this->moveLead($lead, self::APPOINTMENT_RESCHEDULED) : $this->move($person, self::APPOINTMENT_RESCHEDULED);
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

        if (in_array($current, self::CLOSED, true) || $current === $code) {
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
