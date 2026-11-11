<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lead a booking link was sent for ("Send booking link" on the lead page).
 * When its booking syncs, only that lead gets the visit and changes stage.
 * Null for links sent from the contact page: all the contact's leads, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calcom_booking_links', function (Blueprint $table) {
            $table->unsignedInteger('lead_id')->nullable()->after('person_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('calcom_booking_links', function (Blueprint $table) {
            $table->dropColumn('lead_id');
        });
    }
};
