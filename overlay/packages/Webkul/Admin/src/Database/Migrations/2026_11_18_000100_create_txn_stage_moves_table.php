<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a stage migration be undone exactly (used by
 * 2026_11_18_000200_txn_pipeline_v2):
 * - txn_stage_snapshots: every stage as it was before (name, order, probability);
 * - txn_stage_moves: every lead it moved, from where and to where.
 * In its own migration: creating tables can't share a transaction with the
 * lead moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('txn_stage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('migration');
            $table->unsignedInteger('lead_pipeline_id');
            $table->string('code');
            $table->string('name');
            $table->integer('probability');
            $table->integer('sort_order');
        });

        Schema::create('txn_stage_moves', function (Blueprint $table) {
            $table->id();
            $table->string('migration');
            $table->unsignedInteger('lead_id')->index();
            $table->unsignedInteger('lead_pipeline_id');
            $table->string('from_stage_code');
            $table->string('to_stage_code');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_stage_moves');
        Schema::dropIfExists('txn_stage_snapshots');
    }
};
