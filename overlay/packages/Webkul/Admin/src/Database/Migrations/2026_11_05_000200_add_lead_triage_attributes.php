<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lead fields written by the lead check (Support\Contact\LeadTriageService):
 * service category, flow, location result, missing info, next action and
 * when it last ran. They show in the lead page's "About Lead" panel.
 */
return new class extends Migration
{
    private const ATTRIBUTES = [
        'txn_service_category' => ['Service Category', 'text', 101],
        'txn_flow'             => ['Flow', 'text', 102],
        'txn_location'         => ['Location Check', 'text', 103],
        'txn_missing'          => ['Missing Info', 'text', 104],
        'txn_next_action'      => ['Next Action', 'text', 105],
        'txn_checked_at'       => ['Checked At', 'datetime', 106],
    ];

    public function up(): void
    {
        foreach (self::ATTRIBUTES as $code => [$name, $type, $sort]) {
            if (DB::table('attributes')->where('entity_type', 'leads')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('attributes')->insert([
                'code'            => $code,
                'name'            => $name,
                'type'            => $type,
                'entity_type'     => 'leads',
                'sort_order'      => $sort,
                'is_required'     => 0,
                'is_unique'       => 0,
                'quick_add'       => 0,
                'is_user_defined' => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('attributes')
            ->where('entity_type', 'leads')
            ->whereIn('code', array_keys(self::ATTRIBUTES))
            ->pluck('id');

        DB::table('attribute_values')->whereIn('attribute_id', $ids)->delete();
        DB::table('attributes')->whereIn('id', $ids)->delete();
    }
};
