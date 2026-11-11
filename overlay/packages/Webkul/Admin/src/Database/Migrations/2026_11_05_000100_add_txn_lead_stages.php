<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The TXN lead stages (CRM spec: Raw Lead → Contacted → Pre-Qualified →
 * Appointment Set → Appointment Confirmed, plus Rescheduled and Canceled),
 * added to the default pipeline. Krayin's "New" stage becomes "Raw Lead"
 * (code unchanged); Krayin's own stages stay after the TXN ones.
 */
return new class extends Migration
{
    private const ADDED = [
        'contacted'               => 'Contacted',
        'pre-qualified'           => 'Pre-Qualified',
        'appointment-set'         => 'Appointment Set',
        'appointment-rescheduled' => 'Appointment Rescheduled',
        'appointment-confirmed'   => 'Appointment Confirmed',
        'appointment-canceled'    => 'Appointment Canceled',
    ];

    private const ORDER = [
        'new', 'contacted', 'pre-qualified', 'appointment-set', 'appointment-rescheduled',
        'appointment-confirmed', 'appointment-canceled', 'follow-up', 'prospect', 'negotiation', 'won', 'lost',
    ];

    private const KRAYIN_ORDER = ['new', 'follow-up', 'prospect', 'negotiation', 'won', 'lost'];

    public function up(): void
    {
        $pipelineId = $this->defaultPipelineId();

        if (! $pipelineId) {
            return;
        }

        foreach (self::ADDED as $code => $name) {
            $exists = DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', $code)
                ->exists();

            if (! $exists) {
                DB::table('lead_pipeline_stages')->insert([
                    'code'             => $code,
                    'name'             => $name,
                    'probability'      => 100,
                    'sort_order'       => 0,
                    'lead_pipeline_id' => $pipelineId,
                ]);
            }
        }

        DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('code', 'new')
            ->update(['name' => 'Raw Lead']);

        $this->reorder($pipelineId, self::ORDER);
    }

    public function down(): void
    {
        $pipelineId = $this->defaultPipelineId();

        if (! $pipelineId) {
            return;
        }

        $stages = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId);
        $newId = (clone $stages)->where('code', 'new')->value('id');
        $addedIds = (clone $stages)->whereIn('code', array_keys(self::ADDED))->pluck('id');

        // Leads in a removed stage go back to the first stage.
        DB::table('leads')->whereIn('lead_pipeline_stage_id', $addedIds)->update(['lead_pipeline_stage_id' => $newId]);
        DB::table('lead_pipeline_stages')->whereIn('id', $addedIds)->delete();
        DB::table('lead_pipeline_stages')->where('id', $newId)->update(['name' => 'New']);

        $this->reorder($pipelineId, self::KRAYIN_ORDER);
    }

    private function defaultPipelineId(): ?int
    {
        $id = DB::table('lead_pipelines')->where('is_default', 1)->value('id')
            ?? DB::table('lead_pipelines')->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    private function reorder(int $pipelineId, array $order): void
    {
        foreach ($order as $position => $code) {
            DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', $code)
                ->update(['sort_order' => $position + 1]);
        }
    }
};
