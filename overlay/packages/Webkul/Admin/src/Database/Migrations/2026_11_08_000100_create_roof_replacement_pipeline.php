<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A separate "Roof Replacement" pipeline, so only roof leads show the roof
 * stages. It has the default pipeline's stages (same codes, so stage moves
 * by code keep working) plus "Waiting for Roof Report" and "Roof Report
 * Received" right after Pre-Qualified. The lead check moves roof
 * replacements into it (LeadStageService::waitingForRoofReport).
 */
return new class extends Migration
{
    private const NAME = 'Roof Replacement';

    private const ROOF_STAGES = [
        'waiting-roof-report'  => 'Waiting for Roof Report',
        'roof-report-received' => 'Roof Report Received',
    ];

    private const AFTER = 'pre-qualified';

    public function up(): void
    {
        $default = DB::table('lead_pipelines')->where('is_default', 1)->first()
            ?? DB::table('lead_pipelines')->orderBy('id')->first();

        if (! $default || DB::table('lead_pipelines')->where('name', self::NAME)->exists()) {
            return;
        }

        $pipelineId = DB::table('lead_pipelines')->insertGetId([
            'name'        => self::NAME,
            'is_default'  => 0,
            'rotten_days' => $default->rotten_days,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $stages = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $default->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['code', 'name', 'probability'])
            ->map(fn ($stage) => (array) $stage)
            ->all();

        $roof = array_map(
            fn ($code, $name) => ['code' => $code, 'name' => $name, 'probability' => 100],
            array_keys(self::ROOF_STAGES),
            self::ROOF_STAGES
        );

        $at = array_search(self::AFTER, array_column($stages, 'code'), true);
        $at = $at === false ? count($stages) : $at + 1;
        array_splice($stages, $at, 0, $roof);

        foreach (array_values($stages) as $position => $stage) {
            DB::table('lead_pipeline_stages')->insert($stage + [
                'sort_order'       => $position + 1,
                'lead_pipeline_id' => $pipelineId,
            ]);
        }
    }

    public function down(): void
    {
        $pipelineId = DB::table('lead_pipelines')->where('name', self::NAME)->value('id');
        $defaultId = DB::table('lead_pipelines')->where('is_default', 1)->value('id');

        if (! $pipelineId || ! $defaultId) {
            return;
        }

        // Leads go back to the default pipeline's stage with the same code;
        // the roof stages map to Pre-Qualified.
        $defaultStages = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $defaultId)->pluck('id', 'code');

        foreach (DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->get() as $stage) {
            $target = $defaultStages[$stage->code] ?? $defaultStages[self::AFTER] ?? null;

            DB::table('leads')->where('lead_pipeline_stage_id', $stage->id)->update([
                'lead_pipeline_id'       => $defaultId,
                'lead_pipeline_stage_id' => $target,
            ]);
        }

        DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->delete();
        DB::table('lead_pipelines')->where('id', $pipelineId)->delete();
    }
};
