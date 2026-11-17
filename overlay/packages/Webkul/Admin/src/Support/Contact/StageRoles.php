<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\DB;

/**
 * Which stage plays which TXN role (table txn_stage_roles, set in
 * Settings > TXN Settings > Stage rules). The automation (LeadStageService,
 * the lead check, follow-ups, imports) finds stages only through these roles,
 * so stages can be renamed, added and removed in Krayin's pipeline editor.
 *
 * A role is held by at most one stage per pipeline; Waiting for roof report
 * by one pipeline only (that pipeline is "the roof pipeline"). Won and Lost
 * are Krayin's own closing stages (found by their codes `won` and `lost`).
 * No caching: every answer comes from the database, so a change on the
 * settings screen applies at once.
 */
final class StageRoles
{
    public const NEW = 'new';

    public const NEEDS_REVIEW = 'needs-review';

    public const WAITING_ROOF_REPORT = 'waiting-roof-report';

    public const ROOF_REPORT_RECEIVED = 'roof-report-received';

    public const CONTACTED = 'contacted';

    public const SCHEDULED = 'appointment-set';

    public const CANCELED = 'appointment-canceled';

    /** Role => [label, what the automation does with it]. Order = settings screen order. */
    public const ROLES = [
        self::NEW => ['New lead', 'Imported leads land here; the lead check moves a fixed lead back here.'],
        self::NEEDS_REVIEW => ['Needs review', 'The lead check moves leads with a problem here.'],
        self::WAITING_ROOF_REPORT => ['Waiting for roof report', 'The lead check moves roof replacements here (this pipeline becomes the roof pipeline).'],
        self::ROOF_REPORT_RECEIVED => ['Roof report received', 'Set by staff; the lead check then treats the lead like a standard one.'],
        self::CONTACTED => ['Contacted', 'Sending a booking link moves the lead here; follow-ups run on leads here.'],
        self::SCHEDULED => ['Appointment scheduled', 'A Cal.com booking or reschedule moves the lead here.'],
        self::CANCELED => ['Canceled', 'A Cal.com cancellation moves the lead here.'],
    ];

    /** Roles every pipeline that receives leads needs, for the warnings on the settings screen. */
    public const REQUIRED = [self::NEW, self::NEEDS_REVIEW, self::CONTACTED, self::SCHEDULED, self::CANCELED];

    public static function label(?string $role): string
    {
        return $role ? (self::ROLES[$role][0] ?? $role) : 'None';
    }

    public function roleOf(?int $stageId): ?string
    {
        if (! $stageId) {
            return null;
        }

        $role = DB::table('txn_stage_roles')->where('lead_pipeline_stage_id', $stageId)->value('role');

        return $role ?: null;
    }

    /** The stage with this role in this pipeline. */
    public function stageId(int $pipelineId, string $role): ?int
    {
        $id = DB::table('txn_stage_roles as r')
            ->join('lead_pipeline_stages as s', 's.id', '=', 'r.lead_pipeline_stage_id')
            ->where('s.lead_pipeline_id', $pipelineId)
            ->where('r.role', $role)
            ->value('s.id');

        return $id ? (int) $id : null;
    }

    /** Every stage (all pipelines) holding one of these roles. */
    public function stageIds(array $roles): array
    {
        return DB::table('txn_stage_roles')->whereIn('role', $roles)->pluck('lead_pipeline_stage_id')->map(fn ($id) => (int) $id)->all();
    }

    /** The pipeline that has a stage with this role (used for the roof pipeline). */
    public function pipelineWith(string $role): ?int
    {
        $id = DB::table('txn_stage_roles as r')
            ->join('lead_pipeline_stages as s', 's.id', '=', 'r.lead_pipeline_stage_id')
            ->where('r.role', $role)
            ->orderBy('s.lead_pipeline_id')
            ->value('s.lead_pipeline_id');

        return $id ? (int) $id : null;
    }

    /** stage id => role, for the settings screen. */
    public function all(): array
    {
        return DB::table('txn_stage_roles')->pluck('role', 'lead_pipeline_stage_id')->mapWithKeys(fn ($role, $id) => [(int) $id => $role])->all();
    }
}
