<?php

namespace Webkul\Admin\Http\Controllers\Contact;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Automation\Automation;
use Webkul\Admin\Support\Contact\CalcomBookingSyncService;
use Webkul\Admin\Support\Contact\PipelineEvents;

/**
 * POST /txn/calcom-webhook (AppServiceProvider; "api" group, no session).
 * Cal.com calls it when a booking is created, rescheduled or cancelled.
 *
 * - Off (Settings > TXN Settings > Automation > Cal.com webhook): 404.
 * - Signed: X-Cal-Signature-256 = HMAC-SHA256 of the raw body with the
 *   webhook secret (set the same secret in Cal.com). Wrong or missing: 401.
 * - The booking is read again from the Cal.com API by its uid and saved with
 *   CalcomBookingSyncService::upsert, exactly like the Sync button (which
 *   stays as the backup). Runs after the response, under the sync lock.
 */
class CalcomWebhookController extends Controller
{
    private const EVENTS = ['BOOKING_CREATED', 'BOOKING_RESCHEDULED', 'BOOKING_CANCELLED', 'BOOKING_REJECTED', 'BOOKING_REQUESTED'];

    public function __invoke(Request $request): JsonResponse
    {
        if (! Automation::webhookOn()) {
            return response()->json(['message' => 'not found'], 404);
        }

        $secret = (string) config('automation.webhook.secret');
        $signature = (string) $request->header('X-Cal-Signature-256');

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), strtolower($signature))) {
            Log::warning('Cal.com webhook refused: bad or missing signature.');

            return response()->json(['message' => 'unauthorized'], 401);
        }

        $trigger = strtoupper((string) $request->input('triggerEvent'));
        $uid = (string) ($request->input('payload.uid') ?? '');

        // Cal.com's "Ping test" and events we don't use: accepted, nothing done.
        if (! in_array($trigger, self::EVENTS, true) || $uid === '') {
            return response()->json(['message' => 'ignored'], 200);
        }

        dispatch(function () use ($uid, $trigger) {
            $lock = Cache::lock('calcom-booking-sync', 120);

            try {
                $lock->block(60);
            } catch (Throwable) {
                Log::warning("Cal.com webhook {$trigger} {$uid}: the sync lock was busy; press Sync to bring it in.");

                return;
            }

            try {
                PipelineEvents::as('Cal.com webhook', 'webhook', function () use ($uid) {
                    $sync = app(CalcomBookingSyncService::class);
                    $sync->upsert($sync->fetchBooking($uid));
                });
            } catch (Throwable $exception) {
                Log::error("Cal.com webhook {$trigger} {$uid}: not saved.", ['error' => $exception->getMessage()]);
                PipelineEvents::as('Cal.com webhook', 'webhook', fn () => PipelineEvents::record('automation_failed', "Cal.com booking {$uid} ({$trigger}) not saved: ".$exception->getMessage().' Press Sync to bring it in.', ['uid' => $uid, 'trigger' => $trigger]));
            } finally {
                $lock->release();
            }
        })->afterResponse();

        return response()->json(['message' => 'accepted'], 202);
    }
}
