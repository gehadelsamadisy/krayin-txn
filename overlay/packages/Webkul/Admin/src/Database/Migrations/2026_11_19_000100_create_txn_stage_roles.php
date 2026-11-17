<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage roles (Support\Contact\StageRoles): the TXN automation finds its
 * stages by role, not by stage code, so admins can add, remove and rename
 * stages in Krayin's pipeline editor (Settings > Pipelines) and choose which
 * stage plays which role in Settings > TXN Settings > Stage rules.
 *
 * - txn_stage_roles: one role per stage at most; deleting a stage in Krayin
 *   removes its role with it (foreign key, cascade).
 * - txn_settings_log: every change made on a TXN settings screen.
 *
 * Filled from today's stage codes, so nothing changes on day one.
 */
return new class extends Migration
{
    /** Role => the stage code that plays it today (role names are those codes). */
    private const ROLES = [
        'new', 'needs-review', 'waiting-roof-report', 'roof-report-received',
        'contacted', 'appointment-set', 'appointment-canceled',
    ];

    public function up(): void
    {
        Schema::create('txn_stage_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('lead_pipeline_stage_id')->unique();
            $table->foreign('lead_pipeline_stage_id')->references('id')->on('lead_pipeline_stages')->cascadeOnDelete();
            $table->string('role', 50);
            $table->timestamps();
        });

        Schema::create('txn_settings_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('area', 50)->index();
            $table->string('item');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $stages = DB::table('lead_pipeline_stages')->whereIn('code', self::ROLES)->get(['id', 'code']);

        DB::table('txn_stage_roles')->insert($stages->map(fn ($stage) => [
            'lead_pipeline_stage_id' => $stage->id,
            'role' => $stage->code,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_settings_log');
        Schema::dropIfExists('txn_stage_roles');
    }
};
