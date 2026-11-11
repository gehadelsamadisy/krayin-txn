<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_workers', function (Blueprint $table) {
            $table->unsignedInteger('activity_id');
            $table->unsignedBigInteger('worker_id');

            $table->primary(['activity_id', 'worker_id']);
            $table->foreign('activity_id')->references('id')->on('activities')->cascadeOnDelete();
            $table->foreign('worker_id')->references('id')->on('workers')->cascadeOnDelete();
        });

        DB::table('activity_workers')->insertUsing(
            ['activity_id', 'worker_id'],
            DB::table('activities')
                ->whereNotNull('worker_id')
                ->select('id', 'worker_id')
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_workers');
    }
};
