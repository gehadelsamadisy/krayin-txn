<?php

namespace Webkul\Admin\Http\Controllers\Contact;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\CalcomApiException;
use Webkul\Admin\Support\Contact\CalcomBookingSyncService;

/**
 * "Sync Cal.com bookings": copies new, moved and cancelled Cal.com bookings
 * into Krayin now (manual by decision: nothing syncs in the background).
 * Same work as
 * `php artisan calcom:sync-bookings`.
 */
class CalcomSyncController extends Controller
{
    public function sync(CalcomBookingSyncService $sync): RedirectResponse
    {
        // One sync at a time: two at once could copy a booking twice.
        $lock = Cache::lock('calcom-booking-sync', 120);

        if (! $lock->get()) {
            return back()->with('warning', 'A Cal.com sync is already running. Try again in a moment.');
        }

        try {
            $counts = $sync->sync();
        } catch (CalcomApiException $exception) {
            return back()->with('error', $exception->getMessage());
        } finally {
            $lock->release();
        }

        $changes = $counts['created'] + $counts['updated'];

        return back()->with('success', $changes
            ? "Cal.com synced: {$counts['created']} new, {$counts['updated']} changed booking(s)."
            : 'Cal.com synced: no new or changed bookings.');
    }
}
