<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Values changed on the Settings > TXN Settings screens
 * (Support\Contact\TxnSettings). One row per setting; a setting with no row
 * uses its default from the code (overlay/config/*.php and .env). Saved
 * values are put over the config at start-up, so the code reading config()
 * needs no change. Changes are logged in txn_settings_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('txn_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->json('value');
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('txn_settings');
    }
};
