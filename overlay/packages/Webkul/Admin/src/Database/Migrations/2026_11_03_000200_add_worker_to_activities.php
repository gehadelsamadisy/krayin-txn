<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The worker doing an appointment, and where it is, so the slot finder and
 * the worker's route can use Krayin's appointments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->index(['worker_id', 'schedule_from']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['worker_id']);
            $table->dropIndex(['worker_id', 'schedule_from']);
            $table->dropColumn(['worker_id', 'latitude', 'longitude']);
        });
    }
};
