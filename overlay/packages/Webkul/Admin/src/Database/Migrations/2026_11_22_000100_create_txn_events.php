<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline event log (Support\Contact\PipelineEvents): one row per step of
 * the lead pipeline, by a person or by automation: leads imported and
 * checked, times suggested, link created and sent (email / SMS), follow-ups,
 * bookings received, rescheduled and cancelled, automation skips and
 * failures. Shown at Leads > Event log and on each lead and contact.
 * Kept forever (small rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('txn_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('event', 40);
            // Staff name, "Automation", "TXNHome webhook", "Cal.com webhook", "Scheduler", "Command".
            $table->string('actor', 120);
            $table->unsignedInteger('user_id')->nullable();
            // button, webhook, scheduler, command, automation
            $table->string('source', 20);
            $table->unsignedInteger('lead_id')->nullable();
            $table->unsignedInteger('person_id')->nullable();
            $table->string('summary', 500);
            $table->json('data')->nullable();

            $table->index('occurred_at');
            $table->index(['event', 'occurred_at']);
            $table->index('lead_id');
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_events');
    }
};
