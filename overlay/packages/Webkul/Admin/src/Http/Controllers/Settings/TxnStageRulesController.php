<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\StageRoles;

/**
 * Settings > TXN Settings > Stage rules: which stage of each pipeline plays
 * which TXN role (StageRoles). Stages themselves are added, removed, renamed
 * and reordered in Krayin's own pipeline editor; this screen only assigns the
 * roles the automation follows, warns about gaps, and logs every change
 * (table txn_settings_log, area "stage_roles").
 */
class TxnStageRulesController extends Controller
{
    private const AREA = 'stage_roles';

    /** Krayin's closing stages: never given a role. */
    private const CLOSING = ['won', 'lost'];

    public function __construct(private StageRoles $roles) {}

    public function index(): View
    {
        $pipelines = $this->pipelines();
        $stages = $this->stagesByPipeline();
        $roles = $this->roles->all();

        return view('admin::settings.txn.stage-rules', [
            'pipelines' => $pipelines,
            'stages' => $stages,
            'roles' => $roles,
            'roleOptions' => StageRoles::ROLES,
            'closing' => self::CLOSING,
            'leadCounts' => DB::table('leads')->selectRaw('lead_pipeline_stage_id, COUNT(*) AS n')
                ->groupBy('lead_pipeline_stage_id')->pluck('n', 'lead_pipeline_stage_id'),
            'warnings' => $this->warnings($pipelines, $stages, $roles),
            'log' => DB::table('txn_settings_log as l')
                ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
                ->where('l.area', self::AREA)
                ->orderByDesc('l.id')
                ->limit(25)
                ->get(['l.created_at', 'u.name as user', 'l.item', 'l.old_value', 'l.new_value']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $input = (array) $request->input('roles', []);
        $stages = DB::table('lead_pipeline_stages')->get(['id', 'code', 'name', 'lead_pipeline_id'])->keyBy('id');
        $pipelineNames = DB::table('lead_pipelines')->pluck('name', 'id');
        $current = $this->roles->all();

        // Only stages on the form; anything else keeps its role.
        $wanted = $current;
        $errors = [];

        foreach ($input as $stageId => $role) {
            $stageId = (int) $stageId;
            $role = (string) $role;
            $stage = $stages->get($stageId);

            if (! $stage) {
                continue; // deleted meanwhile in the pipeline editor
            }

            if ($role !== '' && ! array_key_exists($role, StageRoles::ROLES)) {
                $errors[] = "Unknown role for \"{$stage->name}\".";

                continue;
            }

            if ($role !== '' && in_array($stage->code, self::CLOSING, true)) {
                $errors[] = "\"{$stage->name}\" is one of Krayin's closing stages and can't have a role.";

                continue;
            }

            if ($role === '') {
                unset($wanted[$stageId]);
            } else {
                $wanted[$stageId] = $role;
            }
        }

        // One stage per role in each pipeline; Waiting for roof report in one pipeline only.
        $byPipeline = [];
        $roofPipelines = [];

        foreach ($wanted as $stageId => $role) {
            $stage = $stages->get($stageId);

            if (! $stage) {
                continue;
            }

            $byPipeline[$stage->lead_pipeline_id][$role][] = $stage->name;

            if ($role === StageRoles::WAITING_ROOF_REPORT) {
                $roofPipelines[$stage->lead_pipeline_id] = true;
            }
        }

        foreach ($byPipeline as $pipelineId => $byRole) {
            foreach ($byRole as $role => $names) {
                if (count($names) > 1) {
                    $errors[] = '"'.StageRoles::label($role).'" is given to more than one stage in '
                        .($pipelineNames[$pipelineId] ?? "pipeline {$pipelineId}").': '.implode(', ', $names).'. Give it to one stage only.';
                }
            }
        }

        if (count($roofPipelines) > 1) {
            $errors[] = '"'.StageRoles::label(StageRoles::WAITING_ROOF_REPORT).'" can be used in one pipeline only (it marks the roof pipeline).';
        }

        if ($errors) {
            throw ValidationException::withMessages(['roles' => $errors]);
        }

        $changes = 0;
        $userId = auth()->guard('user')->id();

        DB::transaction(function () use ($stages, $pipelineNames, $current, $wanted, $userId, &$changes) {
            foreach ($stages as $stageId => $stage) {
                $old = $current[$stageId] ?? null;
                $new = $wanted[$stageId] ?? null;

                if ($old === $new) {
                    continue;
                }

                if ($new === null) {
                    DB::table('txn_stage_roles')->where('lead_pipeline_stage_id', $stageId)->delete();
                } else {
                    DB::table('txn_stage_roles')->updateOrInsert(
                        ['lead_pipeline_stage_id' => $stageId],
                        ['role' => $new, 'updated_at' => now(), 'created_at' => now()]
                    );
                }

                DB::table('txn_settings_log')->insert([
                    'user_id' => $userId,
                    'area' => self::AREA,
                    'item' => ($pipelineNames[$stage->lead_pipeline_id] ?? 'Pipeline').' / '.$stage->name,
                    'old_value' => StageRoles::label($old),
                    'new_value' => StageRoles::label($new),
                    'created_at' => now(),
                ]);

                $changes++;
            }
        });

        return redirect()
            ->route('admin.settings.txn.stage_rules')
            ->with('success', $changes ? "Stage rules saved ({$changes} ".($changes === 1 ? 'change' : 'changes').').' : 'Nothing changed.');
    }

    /** Default pipeline first. */
    private function pipelines(): Collection
    {
        return DB::table('lead_pipelines')->orderByDesc('is_default')->orderBy('id')->get(['id', 'name', 'is_default']);
    }

    private function stagesByPipeline(): Collection
    {
        return DB::table('lead_pipeline_stages')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'code', 'name', 'sort_order', 'lead_pipeline_id'])->groupBy('lead_pipeline_id');
    }

    /**
     * Gaps the automation would run into, in plain words.
     *
     * @return list<string>
     */
    private function warnings(Collection $pipelines, Collection $stages, array $roles): array
    {
        $warnings = [];
        $roofId = $this->roles->pipelineWith(StageRoles::WAITING_ROOF_REPORT);

        foreach ($pipelines as $pipeline) {
            $pipelineStages = $stages->get($pipeline->id, collect());
            $has = $pipelineStages->map(fn ($stage) => $roles[$stage->id] ?? null)->filter()->values()->all();

            $required = match (true) {
                (bool) $pipeline->is_default => StageRoles::REQUIRED,
                (int) $pipeline->id === $roofId => [...StageRoles::REQUIRED, StageRoles::ROOF_REPORT_RECEIVED],
                default => [],
            };

            foreach (array_diff($required, $has) as $role) {
                $warnings[] = "{$pipeline->name}: no stage has the role \"".StageRoles::label($role).'". '
                    .StageRoles::ROLES[$role][1].' That move is skipped until a stage gets this role.';
            }

            // Krayin's board keys its columns by position: a repeated number hides a column.
            foreach ($pipelineStages->groupBy('sort_order')->filter(fn ($group) => $group->count() > 1) as $position => $group) {
                $warnings[] = "{$pipeline->name}: ".$group->pluck('name')->implode(' and ')." share position {$position}, so the Leads board shows only one of them. Reorder the stages in Settings > Pipelines.";
            }
        }

        if (! $roofId) {
            $warnings[] = 'No stage has the role "'.StageRoles::label(StageRoles::WAITING_ROOF_REPORT).'": roof replacements stay in the main pipeline with the next action "Get the roof report".';
        }

        return $warnings;
    }
}
