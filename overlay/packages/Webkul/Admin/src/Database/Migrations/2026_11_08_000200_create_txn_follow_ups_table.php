<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per emailed booking link: the follow-up sequence for it
 * (Support\Contact\FollowUpService). 24h reminder, 72h final, then Lost;
 * stopped when the customer books, the lead leaves Contacted, or a newer
 * link is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('txn_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('person_id')->index();
            $table->unsignedInteger('lead_id')->nullable()->index();
            $table->unsignedBigInteger('booking_link_id')->nullable();
            $table->string('email');
            // The link the customer can still use (a reminder may swap in a fresh one).
            $table->string('booking_url', 500);
            $table->timestamp('link_sent_at');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('final_sent_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_follow_ups');
    }
};
