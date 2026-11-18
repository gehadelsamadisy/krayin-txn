<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services Krayin uses that are not working right now
 * (Support\Contact\ServiceHealth): one row per service with a login/connection
 * failure ("failed") or used without a key ("no_key"). A working service has
 * no row. Shown as a bar on every admin page and on Connections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('txn_service_status', function (Blueprint $table) {
            $table->id();
            $table->string('service', 30)->unique();
            $table->string('state', 10);
            $table->text('message');
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_service_status');
    }
};
