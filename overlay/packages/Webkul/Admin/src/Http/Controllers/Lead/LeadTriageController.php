<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\LeadCsvImportService;
use Webkul\Admin\Support\Contact\LeadTriageService;
use Webkul\Lead\Models\Lead;

/**
 * "Re-check lead" (lead page) and "Check new leads" (leads list): run the
 * lead check (LeadTriageService). Same as `php artisan txn:triage-leads`.
 * "Import leads (CSV)" (leads list): LeadCsvImportService.
 */
class LeadTriageController extends Controller
{
    public function check(int $id, LeadTriageService $triage): RedirectResponse
    {
        $result = $triage->check(Lead::findOrFail($id));

        // Toasts don't wrap: the full reason is in the lead's Next Action field.
        return back()->with('success', $result['review']
            ? 'Lead checked: needs review. The reason is under the lead title.'
            : 'Lead checked: '.strtok($result['next_action'], '(').'.');
    }

    /**
     * Every lead in Raw Lead, until the time budget runs out (each check can
     * look up a map location; the server stops requests after 300 s). Leads
     * left over stay in Raw Lead for the next click.
     */
    public function checkNew(LeadTriageService $triage): RedirectResponse
    {
        $leads = Lead::query()->whereHas('stage', fn ($query) => $query->where('code', 'new'))->orderBy('id')->get();
        $deadline = microtime(true) + config('lead_triage.import.check_seconds', 200);
        $ready = $review = $left = 0;

        foreach ($leads as $lead) {
            if (microtime(true) >= $deadline) {
                $left++;

                continue;
            }

            $triage->check($lead)['review'] ? $review++ : $ready++;
        }

        if ($leads->isEmpty()) {
            return back()->with('success', 'No new leads to check.');
        }

        $checked = $ready + $review;
        $message = "Checked {$checked} new leads: {$ready} ready for a booking link, {$review} need review.";

        return back()->with('success', $left ? "{$message} {$left} left: press Check new leads again." : $message);
    }

    /** Upserts the leads in the chosen CSV file; the result is shown above the leads list. */
    public function import(Request $request, LeadCsvImportService $import): RedirectResponse
    {
        $file = $request->file('file');

        if (! $file || ! $file->isValid()) {
            return back()->with('error', 'Choose a CSV file to import.');
        }

        $name = $file->getClientOriginalName();

        if (strtolower($file->getClientOriginalExtension()) !== 'csv' || $file->getSize() > 5 * 1024 * 1024) {
            return back()->with('error', "{$name} is not a CSV file of 5 MB or less.");
        }

        try {
            $result = $import->import($file->getRealPath());
        } catch (RuntimeException $exception) {
            $result = ['errors' => [$exception->getMessage()], 'stopped' => true];
        }

        $result['file'] = $name;

        if ($result['errors']) {
            return back()->with('lead_import', $result)->with('error', "{$name}: nothing imported. See the problems above the leads.");
        }

        return back()->with('lead_import', $result)
            ->with('success', "Leads imported: {$result['created']} new, {$result['updated']} updated, {$result['unchanged']} unchanged.");
    }
}
