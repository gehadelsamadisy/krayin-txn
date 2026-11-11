<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Cal.com schedule behind a booking link, so its times can be changed
 * after the link is created (times added or removed before it is emailed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calcom_booking_links', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_id')->nullable()->after('event_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('calcom_booking_links', function (Blueprint $table) {
            $table->dropColumn('schedule_id');
        });
    }
};
