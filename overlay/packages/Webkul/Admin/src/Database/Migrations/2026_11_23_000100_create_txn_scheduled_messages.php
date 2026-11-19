<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The follow-up schedule (Support\Automation\MessageScheduler): one row per
 * planned step of a booking link's follow-ups, e.g. "Reminder to Jane, Thu
 * 10:14 AM". Each pending row has a delayed job (Laravel's database queue,
 * queue "txn-schedule") that fires at due_at; the row is the truth, so a
 * cancelled or re-timed row makes its old job do nothing.
 * Leads > Scheduled messages shows and edits them.
 *
 * Also creates Laravel's jobs / failed_jobs tables if Krayin has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('txn_scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);                 // reminder, final, lost
            $table->unsignedInteger('follow_up_id');    // txn_follow_ups.id
            $table->unsignedInteger('person_id');
            $table->unsignedInteger('lead_id')->nullable();
            $table->timestamp('due_at');                // app time zone (UTC)
            $table->string('status', 12)->default('pending'); // pending, sending, sent, cancelled, failed
            $table->string('reason', 500)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('done_by', 120)->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index('follow_up_id');
            $table->index('lead_id');
            $table->index('person_id');
        });

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_scheduled_messages');
        // jobs / failed_jobs are left: Krayin may use them too.
    }
};
