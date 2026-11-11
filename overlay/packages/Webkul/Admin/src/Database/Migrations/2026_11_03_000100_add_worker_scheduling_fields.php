<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('start_address')->nullable();
            $table->decimal('home_latitude', 10, 7)->nullable();
            $table->decimal('home_longitude', 10, 7)->nullable();
            $table->json('work_hours')->nullable();
            $table->unsignedSmallInteger('priority')->default(1);
            $table->json('services')->nullable();
            $table->unsignedSmallInteger('max_visits_per_day')->nullable();
            $table->string('calendar_color', 7)->nullable();
        });

        Schema::create('worker_time_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('type', 30);
            $table->string('note', 1000)->nullable();
            $table->timestamps();

            $table->index(['worker_id', 'start_date', 'end_date']);
        });

        Schema::create('worker_schedule_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->json('default_work_hours');
            $table->json('holidays');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_schedule_settings');
        Schema::dropIfExists('worker_time_offs');

        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn([
                'start_address',
                'home_latitude',
                'home_longitude',
                'work_hours',
                'priority',
                'services',
                'max_visits_per_day',
                'calendar_color',
            ]);
        });
    }
};
