<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text messages with customers (Support\Replies\SmsInbox; Leads > SMS
 * messages). Incoming texts arrive from Twilio at POST /txn/sms-inbound;
 * texts Krayin sends (SmsSender) are kept too, so a thread is complete.
 * customer_number is the customer's side in E.164 (+17135550100), the key
 * of a thread. needs_staff: the number belongs to several contacts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('txn_sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_sid', 64)->unique();
            $table->string('direction', 3);           // in, out
            $table->string('customer_number', 20);
            $table->string('our_number', 40)->nullable();
            $table->text('body')->nullable();
            $table->json('media')->nullable();        // [{url, type}]
            $table->unsignedInteger('person_id')->nullable();
            $table->unsignedInteger('lead_id')->nullable();
            $table->boolean('needs_staff')->default(false);
            $table->unsignedInteger('sent_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['customer_number', 'sent_at']);
            $table->index('lead_id');
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_sms_messages');
    }
};
