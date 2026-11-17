<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixes two things left by 2026_11_18_000200_txn_pipeline_v2 on databases
 * where it already ran:
 * - duplicate stage positions (its reorder counted only rows that changed, so
 *   a stage already in place made the next one share its number). Krayin's
 *   board keys columns by position, so a duplicate hid a column (Raw Lead);
 * - "Won – Job Sold" with an en dash becomes "Won - Job Sold" (plain hyphen).
 *
 * Leads are not touched. down() only puts the old name back; rolling back
 * 000200 afterwards restores the original positions from its snapshot.
 */
return new class extends Migration
{
    private const ORDER = [
        'new', 'needs-review', 'waiting-roof-report', 'roof-report-received', 'contacted',
        'appointment-set', 'appointment-canceled', 'prospect', 'negotiation', 'won', 'lost',
    ];

    private const OLD_WON = 'Won – Job Sold';

    private const NEW_WON = 'Won - Job Sold';

    public function up(): void
    {
        DB::transaction(function () {
            foreach ($this->pipelineIds() as $pipelineId) {
                $this->reorder($pipelineId);
            }

            // "Won <any dash> Job Sold", however the dash was stored; a name staff typed themselves is left alone.
            DB::table('lead_pipeline_stages')->where('code', 'won')->where('name', 'like', 'Won%Job Sold')->update(['name' => self::NEW_WON]);
        });
    }

    public function down(): void
    {
        DB::table('lead_pipeline_stages')->where('code', 'won')->where('name', self::NEW_WON)->update(['name' => self::OLD_WON]);
    }

    /** The default pipeline and, if it exists, Roof Replacement (found by its stage code). */
    private function pipelineIds(): array
    {
        $default = DB::table('lead_pipelines')->where('is_default', 1)->value('id')
            ?? DB::table('lead_pipelines')->orderBy('id')->value('id');
        $roof = DB::table('lead_pipeline_stages')->where('code', 'waiting-roof-report')->value('lead_pipeline_id');

        return array_values(array_unique(array_filter([(int) $default, (int) $roof])));
    }

    /** TXN stages first in ORDER, then any other stage in its old order: 1, 2, 3 ... with no gaps or repeats. */
    private function reorder(int $pipelineId): void
    {
        $stages = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->get(['id', 'code', 'sort_order']);
        $rank = array_flip(self::ORDER);

        $sorted = $stages->sortBy([
            fn ($a, $b) => ($rank[$a->code] ?? PHP_INT_MAX) <=> ($rank[$b->code] ?? PHP_INT_MAX),
            fn ($a, $b) => $a->sort_order <=> $b->sort_order,
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();

        foreach ($sorted as $index => $stage) {
            DB::table('lead_pipeline_stages')->where('id', $stage->id)->update(['sort_order' => $index + 1]);
        }
    }
};
