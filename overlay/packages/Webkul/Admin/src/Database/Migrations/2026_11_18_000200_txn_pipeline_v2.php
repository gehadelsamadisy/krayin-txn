<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TXN pipeline v2, in the default pipeline and in Roof Replacement:
 *
 *   Raw Lead → Needs Review → (roof: Waiting for Roof Report → Roof Report
 *   Received) → Contacted → Appointment Scheduled → Canceled → Visit Done →
 *   Estimate Sent → Won - Job Sold → Lost
 *
 * Stage codes stay the same (only names change), so code that moves leads by
 * code keeps working. Removed: Pre-Qualified and Krayin's Follow-up (leads →
 * Raw Lead), Appointment Rescheduled and Appointment Confirmed (leads →
 * Appointment Scheduled). Open leads with the "Needs review" tag → Needs Review.
 *
 * Every stage is saved to txn_stage_snapshots and every moved lead to
 * txn_stage_moves first, so down() restores the stages exactly and puts back
 * each lead that hasn't moved since.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_11_18_000200_txn_pipeline_v2';

    private const NEEDS_REVIEW = ['code' => 'needs-review', 'name' => 'Needs Review'];

    private const NAMES = [
        'new'                  => 'Raw Lead',
        'appointment-set'      => 'Appointment Scheduled',
        'appointment-canceled' => 'Canceled',
        'prospect'             => 'Visit Done',
        'negotiation'          => 'Estimate Sent',
        'won'                  => 'Won - Job Sold',
    ];

    /** Removed stage => where its leads go. */
    private const REMOVED = [
        'pre-qualified'           => 'new',
        'follow-up'               => 'new',
        'appointment-rescheduled' => 'appointment-set',
        'appointment-confirmed'   => 'appointment-set',
    ];

    /** Stages whose tagged leads go to Needs Review (where the lead check decides). */
    private const REVIEWABLE = ['new', 'waiting-roof-report', 'roof-report-received'];

    private const ORDER = [
        'new', 'needs-review', 'waiting-roof-report', 'roof-report-received', 'contacted',
        'appointment-set', 'appointment-canceled', 'prospect', 'negotiation', 'won', 'lost',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach ($this->pipelineIds() as $pipelineId) {
                $this->snapshot($pipelineId);
                $this->addNeedsReview($pipelineId);

                foreach (self::NAMES as $code => $name) {
                    $this->stages($pipelineId)->where('code', $code)->update(['name' => $name]);
                }

                foreach (self::REMOVED as $code => $target) {
                    $this->moveLeads($pipelineId, $this->leadsInStage($pipelineId, $code), $code, $target);
                    $this->deleteEmptyStage($pipelineId, $code);
                }

                foreach (self::REVIEWABLE as $code) {
                    $this->moveLeads($pipelineId, $this->taggedLeadsInStage($pipelineId, $code), $code, self::NEEDS_REVIEW['code']);
                }

                $this->reorder($pipelineId);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $snapshots = DB::table('txn_stage_snapshots')->where('migration', self::MIGRATION)->get()->groupBy('lead_pipeline_id');

            foreach ($snapshots as $pipelineId => $stages) {
                // Removed stages back (new ids), then every name, order and probability as before.
                foreach ($stages as $stage) {
                    if (! $this->stages($pipelineId)->where('code', $stage->code)->exists()) {
                        DB::table('lead_pipeline_stages')->insert([
                            'code' => $stage->code, 'name' => $stage->code, 'probability' => $stage->probability,
                            'sort_order' => $stage->sort_order, 'lead_pipeline_id' => $pipelineId,
                        ]);
                    }
                }

                // Two passes: a temporary name first, so swapping names never hits the unique index.
                foreach ($stages as $stage) {
                    $this->stages($pipelineId)->where('code', $stage->code)->update(['name' => "~{$stage->code}"]);
                }

                foreach ($stages as $stage) {
                    $this->stages($pipelineId)->where('code', $stage->code)
                        ->update(['name' => $stage->name, 'probability' => $stage->probability, 'sort_order' => $stage->sort_order]);
                }

                // Leads back, only those still where this migration put them.
                $ids = $this->stages($pipelineId)->pluck('id', 'code');

                foreach (DB::table('txn_stage_moves')->where('migration', self::MIGRATION)->where('lead_pipeline_id', $pipelineId)->get() as $move) {
                    DB::table('leads')
                        ->where('id', $move->lead_id)
                        ->where('lead_pipeline_id', $pipelineId)
                        ->where('lead_pipeline_stage_id', $ids[$move->to_stage_code] ?? 0)
                        ->update(['lead_pipeline_stage_id' => $ids[$move->from_stage_code]]);
                }

                // Leads that reached Needs Review after the migration: back to Raw Lead.
                if (isset($ids[self::NEEDS_REVIEW['code']], $ids['new'])) {
                    DB::table('leads')->where('lead_pipeline_stage_id', $ids[self::NEEDS_REVIEW['code']])
                        ->update(['lead_pipeline_stage_id' => $ids['new']]);
                }

                $this->deleteEmptyStage((int) $pipelineId, self::NEEDS_REVIEW['code']);
            }

            DB::table('txn_stage_moves')->where('migration', self::MIGRATION)->delete();
            DB::table('txn_stage_snapshots')->where('migration', self::MIGRATION)->delete();
        });
    }

    /** The default pipeline and, if it exists, Roof Replacement (found by its stage code). */
    private function pipelineIds(): array
    {
        $default = DB::table('lead_pipelines')->where('is_default', 1)->value('id')
            ?? DB::table('lead_pipelines')->orderBy('id')->value('id');
        $roof = DB::table('lead_pipeline_stages')->where('code', 'waiting-roof-report')->value('lead_pipeline_id');

        return array_values(array_unique(array_filter([(int) $default, (int) $roof])));
    }

    private function stages(int $pipelineId)
    {
        return DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId);
    }

    private function stageId(int $pipelineId, string $code): ?int
    {
        $id = $this->stages($pipelineId)->where('code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    private function snapshot(int $pipelineId): void
    {
        DB::table('txn_stage_snapshots')->insert($this->stages($pipelineId)->get()->map(fn ($stage) => [
            'migration' => self::MIGRATION,
            'lead_pipeline_id' => $pipelineId,
            'code' => $stage->code,
            'name' => $stage->name,
            'probability' => $stage->probability,
            'sort_order' => $stage->sort_order,
        ])->all());
    }

    private function addNeedsReview(int $pipelineId): void
    {
        if (! $this->stageId($pipelineId, self::NEEDS_REVIEW['code'])) {
            DB::table('lead_pipeline_stages')->insert(self::NEEDS_REVIEW + [
                'probability' => 100,
                'sort_order' => 0,
                'lead_pipeline_id' => $pipelineId,
            ]);
        }
    }

    private function leadsInStage(int $pipelineId, string $code): array
    {
        $stageId = $this->stageId($pipelineId, $code);

        return $stageId ? DB::table('leads')->where('lead_pipeline_stage_id', $stageId)->pluck('id')->all() : [];
    }

    private function taggedLeadsInStage(int $pipelineId, string $code): array
    {
        $stageId = $this->stageId($pipelineId, $code);
        $tagId = DB::table('tags')->where('name', config('lead_triage.review_tag.name', 'Needs review'))->value('id');

        if (! $stageId || ! $tagId) {
            return [];
        }

        return DB::table('leads')
            ->where('lead_pipeline_stage_id', $stageId)
            ->whereIn('id', DB::table('lead_tags')->where('tag_id', $tagId)->select('lead_id'))
            ->pluck('id')->all();
    }

    private function moveLeads(int $pipelineId, array $leadIds, string $from, string $to): void
    {
        $targetId = $this->stageId($pipelineId, $to);

        if (! $leadIds) {
            return;
        }

        if (! $targetId) {
            throw new RuntimeException("Pipeline {$pipelineId} has no stage '{$to}' for the leads in '{$from}'.");
        }

        DB::table('txn_stage_moves')->insert(array_map(fn ($id) => [
            'migration' => self::MIGRATION,
            'lead_id' => $id,
            'lead_pipeline_id' => $pipelineId,
            'from_stage_code' => $from,
            'to_stage_code' => $to,
            'created_at' => now(),
        ], $leadIds));

        DB::table('leads')->whereIn('id', $leadIds)->update(['lead_pipeline_stage_id' => $targetId]);
    }

    /** Never deletes a stage that still holds leads. */
    private function deleteEmptyStage(int $pipelineId, string $code): void
    {
        $stageId = $this->stageId($pipelineId, $code);

        if (! $stageId) {
            return;
        }

        if (DB::table('leads')->where('lead_pipeline_stage_id', $stageId)->exists()) {
            throw new RuntimeException("Stage '{$code}' in pipeline {$pipelineId} still has leads; not deleted.");
        }

        DB::table('lead_pipeline_stages')->where('id', $stageId)->delete();
    }

    /**
     * TXN stages first in ORDER; any other stage (made by staff) after them,
     * in its old order: 1, 2, 3 ... with no repeats (Krayin's board keys its
     * columns by position, so a repeat hides a column).
     */
    private function reorder(int $pipelineId): void
    {
        $stages = $this->stages($pipelineId)->get(['id', 'code', 'sort_order']);
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
