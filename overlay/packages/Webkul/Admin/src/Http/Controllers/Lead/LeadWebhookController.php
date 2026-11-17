<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Webkul\Admin\Support\Contact\LeadDbImportService;

/**
 * POST /txn/lead-arrived {"source": "YELP", "source_lead_id": "…"}, header
 * X-TXN-Token (config lead_triage.webhook_token). TXNHome calls it after
 * saving a lead to the lead database. Only the lead's key is sent; the lead
 * itself is read from the lead database, after the reply, so TXNHome never
 * waits. Route: AppServiceProvider.
 */
class LeadWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('lead_triage.webhook_token');

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-TXN-Token'))) {
            return response()->json(['message' => 'unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'source' => 'required|string|in:ANGI,YELP,angi,yelp',
            'source_lead_id' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $source = strtoupper($request->input('source'));
        $sourceLeadId = (string) $request->input('source_lead_id');

        dispatch(function () use ($source, $sourceLeadId) {
            try {
                $result = app(LeadDbImportService::class)->importOne($source, $sourceLeadId);
            } catch (Throwable $exception) {
                // Not retried here: "Import from lead database" or txn:import-leads brings it in.
                Log::error("Lead webhook {$source} {$sourceLeadId}: import failed.", ['exception' => $exception]);

                return;
            }

            Log::info("Lead webhook {$source} {$sourceLeadId}: {$result['rows']} found, {$result['created']} new, "
                ."{$result['updated']} updated, {$result['unchanged']} unchanged, {$result['skipped']} skipped.",
                $result['errors'] ? ['errors' => $result['errors']] : []);
        })->afterResponse();

        return response()->json(['message' => 'accepted'], 202);
    }
}
