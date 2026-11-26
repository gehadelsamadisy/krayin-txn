<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mailboxes Krayin reads customer replies from (Settings > TXN Settings >
 * Mailboxes, Support\Replies\Mailboxes). One row per address, each with its
 * own Microsoft 365 app login. The main sender (mail.from.address) gets a
 * row too (is_main), but its login stays on Connections (graph_mail.*), so
 * its tenant/client/secret columns stay empty. client_secret is encrypted
 * with APP_KEY. received_until is where the next check starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('txn_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('address', 191)->unique();
            $table->string('name', 100)->nullable();
            // graph = Microsoft 365. Others (gmail, imap) can be added later.
            $table->string('provider', 20)->default('graph');
            $table->boolean('is_main')->default(false);
            $table->string('tenant_id', 64)->nullable();
            $table->string('client_id', 64)->nullable();
            $table->text('client_secret')->nullable();
            $table->boolean('read_replies')->default(true);
            $table->timestamp('received_until')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_result', 300)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_mailboxes');
    }
};
