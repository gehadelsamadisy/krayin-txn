<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per "Send booking link" Cal.com event type: which worker goes
     * with each offered time. The worker of the time the customer books is
     * assigned when the booking syncs.
     */
    public function up(): void
    {
        Schema::create('calcom_booking_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('person_id');
            $table->string('event_type_slug')->unique();
            $table->unsignedBigInteger('event_type_id')->nullable();
            $table->string('timezone');
            // {"2026-11-05T09:00": 1, ...}: local start time => worker id.
            $table->json('slot_workers');
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calcom_booking_links');
    }
};
