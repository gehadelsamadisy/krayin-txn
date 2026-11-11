<?php

namespace Webkul\Admin\Http\Controllers\Contact\Persons;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Webkul\Admin\DataGrids\Contact\PersonDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\AttributeForm;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Resources\PersonResource;
use Webkul\Admin\Models\Worker;
use Webkul\Admin\Support\Contact\BookingEmail;
use Webkul\Admin\Support\Contact\CalcomApiException;
use Webkul\Admin\Support\Contact\FollowUpService;
use Webkul\Admin\Support\Contact\CalcomBookingLinkService;
use Webkul\Admin\Support\Contact\LeadStageService;
use Webkul\Admin\Support\Contact\PersonGridColumnPreferences;
use Webkul\Admin\Support\Contact\SlotSuggestionException;
use Webkul\Admin\Support\Contact\SlotSuggestionService;
use Webkul\Admin\Support\Contact\SmsSender;
use Webkul\Contact\Models\Person;
use Webkul\Contact\Repositories\PersonRepository;

class PersonController extends Controller
{
    /**
     * Create a new class instance.
     *
     * @return void
     */
    public function __construct(
        protected PersonRepository $personRepository,
        protected CalcomBookingLinkService $bookingLinkService,
        protected SlotSuggestionService $slotSuggestionService,
        protected LeadStageService $leadStageService
    ) {
        request()->request->add(['entity_type' => 'persons']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(PersonDataGrid::class)->process();
        }

        $personGridColumns = [
            'id' => trans('admin::app.contacts.persons.index.datagrid.id'),
            'person_name' => trans('admin::app.contacts.persons.index.datagrid.name'),
            'emails' => trans('admin::app.contacts.persons.index.datagrid.emails'),
            'contact_numbers' => trans('admin::app.contacts.persons.index.datagrid.contact-numbers'),
            'job_title' => 'Job Title',
            'sales_owner' => 'Sales Owner',
            'organization' => trans('admin::app.contacts.persons.index.datagrid.organization-name'),
        ];

        foreach (PersonGridColumnPreferences::customAttributes() as $attribute) {
            $personGridColumns[
                PersonGridColumnPreferences::attributeColumn((int) $attribute->id)
            ] = $attribute->name;
        }

        return view('admin::contacts.persons.index', [
            'visibleColumns' => PersonGridColumnPreferences::visibleColumns(),
            'personGridColumns' => $personGridColumns,
        ]);
    }

    public function updateColumns(Request $request): RedirectResponse
    {
        $availableColumns = PersonGridColumnPreferences::availableColumns();

        $validated = $request->validate([
            'visible_columns' => ['required', 'array', 'min:1'],
            'visible_columns.*' => [
                'required',
                'string',
                Rule::in($availableColumns),
            ],
        ]);

        PersonGridColumnPreferences::save(
            array_values(array_unique($validated['visible_columns']))
        );

        return redirect()
            ->route('admin.contacts.persons.index')
            ->with('success', 'Persons list columns updated for all admins.');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('admin::contacts.persons.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AttributeForm $request): RedirectResponse|JsonResponse
    {
        Event::dispatch('contacts.person.create.before');

        $person = $this->personRepository->create($request->all());

        Event::dispatch('contacts.person.create.after', $person);

        if (request()->ajax()) {
            return response()->json([
                'data' => $person,
                'message' => trans('admin::app.contacts.persons.index.create-success'),
            ]);
        }

        session()->flash('success', trans('admin::app.contacts.persons.index.create-success'));

        return redirect()->route('admin.contacts.persons.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): View
    {
        $person = $this->personRepository->findOrFail($id);

        return view('admin::contacts.persons.view', compact('person'));
    }

    public function createBookingLink(int $id): View
    {
        $person = $this->personRepository->findOrFail($id);
        // Opened from a lead page ("?lead=ID"): the link is for that lead.
        $lead = $this->bookingLead($person, request()->query('lead'));
        $workers = Worker::query()
            ->where('is_active', true)
            ->whereNotNull('home_latitude')
            ->whereNotNull('home_longitude')
            ->orderBy('name')
            ->get();

        // A created link: the email that will go with it, editable before sending.
        $draft = session("booking_link_draft.{$person->id}");
        $email = null;

        if ($draft) {
            $email = app(BookingEmail::class)->data(
                $person,
                $this->bookingLead($person, $draft['lead_id'] ?? null),
                $draft['timezone'],
                $draft['slots'] ?? [],
                $draft['url']
            );
            $email = array_merge($email, array_filter([
                'subject' => old('subject'),
                'intro' => old('intro'),
                'closing' => old('closing'),
            ], fn ($value) => $value !== null));
        }

        return view('admin::contacts.persons.booking-link', [
            'email' => $email,
            'emailPreview' => $email ? view('admin::emails.booking-link', $email)->render() : null,
            'person' => $person,
            'lead' => $lead,
            'emails' => $this->personEmails($person),
            'workers' => $workers,
            'serviceTypes' => $workers
                ->flatMap(fn (Worker $worker) => $worker->services ?? [])
                ->unique()
                ->sort()
                ->values(),
            'askChoices' => $this->bookingLinkService->askChoices($person),
            'addressPlan' => $this->bookingLinkService->addressPlan($person),
            'defaultTimezone' => config('calcom.default_timezone', 'America/Chicago'),
            'calcomConfigured' => filled(config('calcom.api_key')),
            'customerEmailEnabled' => $this->customerLinkAllowed($person),
            'singleTestAttempt' => config('calcom.single_test_attempt', true),
            'draftLink' => session("booking_link_draft.{$person->id}"),
        ]);
    }

    /**
     * Suggested times for the booking link form. Only reads; nothing is
     * created in Cal.com and nothing is sent. "address" and "now" override
     * the person's address and the current time, for testing.
     */
    public function suggestBookingSlots(Request $request, int $id): JsonResponse
    {
        $person = $this->personRepository->findOrFail($id);
        $validated = $request->validate([
            'address' => ['nullable', 'string', 'max:255'],
            'now' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'service' => [
                'nullable',
                'string',
                Rule::in(Worker::query()
                    ->where('is_active', true)
                    ->get()
                    ->flatMap(fn (Worker $worker) => $worker->services ?? [])
                    ->unique()
                    ->values()
                    ->all()),
            ],
        ]);
        $timezone = config('slot_finder.timezone');
        $now = isset($validated['now'])
            ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $validated['now'], $timezone)
            : CarbonImmutable::now($timezone);

        try {
            $result = $this->slotSuggestionService->suggestForPerson(
                $person,
                $validated['address'] ?? null,
                $now,
                $validated['service'] ?? null
            );
        } catch (SlotSuggestionException $exception) {
            return response()->json(['status' => 'ERROR', 'message' => $exception->getMessage()], 422);
        }

        return response()->json($result + ['now' => $now->format('D M j, g:i A'), 'timezone' => $timezone]);
    }

    /**
     * Step 1: create the Cal.com link and show it on the form. No email.
     */
    public function createBookingLinkOnly(Request $request, int $id): RedirectResponse
    {
        $person = $this->personRepository->findOrFail($id);

        if (!$this->customerLinkAllowed($person)) {
            return back()
                ->withInput()
                ->withErrors([
                    'integration' => 'This form is not authorized to create or send a booking link to this contact.',
                ]);
        }

        $validated = $request->validate([
            'timezone' => ['required', 'timezone'],
            'slots' => ['required', 'array', 'min:1', 'max:20'],
            'slots.*' => ['required', 'date_format:Y-m-d\TH:i'],
            // One worker per time, in the same order: the worker of the time
            // the customer books gets the visit.
            'slot_workers' => ['required', 'array', 'size:'.count((array) $request->input('slots'))],
            'slot_workers.*' => [
                'required',
                'integer',
                Rule::exists('workers', 'id')->where(
                    fn ($query) => $query
                        ->where('is_active', true)
                        ->whereNotNull('home_latitude')
                        ->whereNotNull('home_longitude')
                ),
            ],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['required', 'string', Rule::in($this->bookingLinkService->choiceCodes($person))],
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id')->where('person_id', $person->id)],
        ], [
            'slot_workers.*.required' => 'Choose a worker for each time.',
        ]);

        $this->validateSlots($validated['slots'], $validated['timezone']);
        $slotWorkers = array_combine($validated['slots'], array_map('intval', array_values($validated['slot_workers'])));

        try {
            $bookingUrl = $this->bookingLinkService->createBookingLink(
                $person,
                $slotWorkers,
                $validated['timezone'],
                $validated['fields'] ?? [],
                isset($validated['lead_id']) ? (int) $validated['lead_id'] : null
            );
        } catch (CalcomApiException $exception) {
            return back()
                ->withInput()
                ->withErrors(['integration' => $exception->getMessage()]);
        }

        // Only the link created here can be emailed in step 2.
        session()->put("booking_link_draft.{$person->id}", [
            'url' => $bookingUrl,
            'link_id' => $this->bookingLinkService->lastLinkId,
            'timezone' => $validated['timezone'],
            'slots' => $validated['slots'],
            'slot_workers' => $slotWorkers,
            'lead_id' => $validated['lead_id'] ?? null,
        ]);

        return redirect()
            ->route('admin.contacts.persons.booking_link.create', array_filter([$person->id, 'lead' => $validated['lead_id'] ?? null]))
            ->withInput();
    }

    /**
     * Step 2: email the link created in step 1.
     */
    public function sendBookingLink(Request $request, int $id): RedirectResponse
    {
        $person = $this->personRepository->findOrFail($id);
        $testRecipients = config('calcom.test_recipient_emails', []);

        if (!$this->customerLinkAllowed($person)) {
            return back()
                ->withInput()
                ->withErrors([
                    'integration' => 'This form is not authorized to create or send a booking link to this contact.',
                ]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            // The email text, as edited on the form (times, button and
            // signature are added from the link and can't be changed).
            'subject' => ['required', 'string', 'max:200'],
            'intro' => ['required', 'string', 'max:3000'],
            'closing' => ['nullable', 'string', 'max:3000'],
            // The times on the form when Send was clicked (they may have
            // changed since the link was created).
            'slots' => ['nullable', 'array', 'min:1', 'max:20'],
            'slots.*' => ['required', 'date_format:Y-m-d\TH:i'],
            'slot_workers' => ['required_with:slots', 'array', 'size:'.count((array) $request->input('slots'))],
            'slot_workers.*' => [
                'required',
                'integer',
                Rule::exists('workers', 'id')->where(
                    fn ($query) => $query
                        ->where('is_active', true)
                        ->whereNotNull('home_latitude')
                        ->whereNotNull('home_longitude')
                ),
            ],
        ], [
            'slots.min' => 'Keep at least one time.',
            'slots.*.required' => 'Fill in or remove the empty time.',
            'slot_workers.*.required' => 'Choose a worker for each time.',
        ]);

        if (!in_array(strtolower($validated['email']), $testRecipients, true)) {
            return back()->withErrors(['email' => 'This address is not an allowed test recipient.']);
        }
        $draft = session("booking_link_draft.{$person->id}");

        if (!$draft) {
            return back()->withErrors(['integration' => 'Create the link first, then email it.']);
        }

        // Times added, removed or changed after the link was created: the
        // link's Cal.com times are updated first, so the email and the
        // booking page show the same times.
        if (! empty($validated['slots'])) {
            $this->validateSlots($validated['slots'], $draft['timezone']);
            $slotWorkers = array_combine($validated['slots'], array_map('intval', array_values($validated['slot_workers'])));
            ksort($slotWorkers);
            $current = array_map('intval', $draft['slot_workers'] ?? []);
            ksort($current);

            if ($slotWorkers !== $current) {
                try {
                    if (empty($draft['link_id'])) {
                        throw new CalcomApiException('This link was created before its times could be changed. Create the link again with the new times.');
                    }

                    $this->bookingLinkService->updateSlots((int) $draft['link_id'], $slotWorkers, $draft['timezone']);
                } catch (CalcomApiException $exception) {
                    return back()->withInput()->withErrors(['integration' => $exception->getMessage()]);
                }

                $draft['slots'] = array_keys($slotWorkers);
                $draft['slot_workers'] = $slotWorkers;
                session()->put("booking_link_draft.{$person->id}", $draft);
            }
        }

        if (
            config('calcom.single_test_attempt', true)
            && !Cache::add('calcom.test-email.sent', $person->id, now()->addYears(10))
        ) {
            return back()
                ->withInput()
                ->withErrors([
                    'integration' => 'The single authorized test attempt has already been used.',
                ]);
        }

        $bookingUrl = $draft['url'];
        session()->forget("booking_link_draft.{$person->id}");

        // The link's lead (sent from a lead page), for the service name and
        // the stage move below.
        $lead = $this->bookingLead($person, $draft['lead_id'] ?? null);

        $bookingEmail = app(BookingEmail::class);

        try {
            $bookingEmail->send(
                'link',
                $validated['email'],
                array_merge(
                    $bookingEmail->data($person, $lead, $draft['timezone'], $draft['slots'] ?? [], $bookingUrl),
                    [
                        'subject' => $validated['subject'],
                        'intro' => $validated['intro'],
                        'closing' => $validated['closing'] ?? '',
                    ]
                )
            );
        } catch (TransportExceptionInterface $exception) {
            Log::error('Krayin could not deliver a Cal.com booking link.', [
                'person_id' => $person->id,
                'exception_class' => get_class($exception),
            ]);

            return redirect()
                ->route('admin.contacts.persons.view', $person->id)
                ->with('booking_link', $bookingUrl)
                ->with('booking_link_error', 'The booking link was created, but Krayin could not send the email. Copy the link below and send it manually.');
        }

        // Emailed: the customer has been contacted (only the link's lead when
        // it was sent from a lead page).
        try {
            $this->leadStageService->linkSent($person, $lead);
        } catch (Exception $exception) {
            Log::error('Booking link sent, but the leads could not be moved to Contacted.', [
                'person_id' => $person->id,
                'exception_class' => get_class($exception),
            ]);
        }

        // 24h / 72h follow-ups and Lost for this link (php artisan txn:follow-ups).
        try {
            app(FollowUpService::class)->start($person, $lead, $validated['email'], $bookingUrl);
        } catch (Exception $exception) {
            Log::error('Booking link sent, but its follow-ups could not be scheduled.', [
                'person_id' => $person->id,
                'error' => $exception->getMessage(),
            ]);
        }

        // Spec: with a phone number, text the customer too (shorter version).
        $firstName = strtok(trim((string) $person->name), ' ') ?: 'there';
        $sms = app(SmsSender::class)->send(
            $person,
            "Hi {$firstName}, this is ".config('sms.signature').'. Pick a time for your free, no-obligation estimate here: '.$bookingUrl,
            $lead
        );
        $smsNote = match ($sms['status']) {
            'sent' => " and texted to {$sms['to']}",
            'skipped' => ' (no text: no phone number in international format)',
            default => " (text not sent: {$sms['error']})",
        };

        if ($lead) {
            return redirect()
                ->route('admin.leads.view', $lead->id)
                ->with('success', "The booking link was emailed{$smsNote}.");
        }

        return redirect()
            ->route('admin.contacts.persons.view', $person->id)
            ->with('booking_link', $bookingUrl)
            ->with('success', "The booking link was emailed{$smsNote}.");
    }

    /** The person's lead with this id, or null (no id, or not this person's lead). */
    private function bookingLead(Person $person, mixed $leadId)
    {
        return is_numeric($leadId) ? $person->leads()->whereKey((int) $leadId)->first() : null;
    }

    /** Links are only created and sent for the configured test contacts. */
    private function customerLinkAllowed(Person $person): bool
    {
        $testRecipients = config('calcom.test_recipient_emails', []);

        return config('calcom.allow_customer_email', false)
            && in_array((int) $person->id, config('calcom.test_person_ids', []), true)
            && array_intersect($testRecipients, array_map('strtolower', $this->personEmails($person))) !== [];
    }

    private function personEmails(Person $person): array
    {
        return collect($person->emails ?? [])
            ->pluck('value')
            ->filter(fn($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    private function validateSlots(array $slots, string $timezone): void
    {
        $starts = [];

        foreach ($slots as $slot) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slot, $timezone);

            if (!$start || $start->format('Y-m-d\TH:i') !== $slot) {
                throw ValidationException::withMessages([
                    'slots' => 'Enter valid appointment dates and times.',
                ]);
            }

            if ($start->minute !== 0) {
                throw ValidationException::withMessages([
                    'slots' => 'Each slot must start on the hour, for example 14:00.',
                ]);
            }

            $end = $start->addHour();

            if (
                $start->isPast()
                || $end->toDateString() !== $start->toDateString()
                || $end->offset !== $start->offset
            ) {
                throw ValidationException::withMessages([
                    'slots' => 'Each slot must be in the future and fit within one local calendar day.',
                ]);
            }

            $starts[] = $start;
        }

        usort($starts, fn($left, $right) => $left->getTimestamp() <=> $right->getTimestamp());

        for ($index = 1; $index < count($starts); $index++) {
            if ($starts[$index]->lessThan($starts[$index - 1]->addHour())) {
                throw ValidationException::withMessages([
                    'slots' => 'Appointment slots must not overlap.',
                ]);
            }
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $person = $this->personRepository->findOrFail($id);

        return view('admin::contacts.persons.edit', compact('person'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AttributeForm $request, int $id): RedirectResponse|JsonResponse
    {
        Event::dispatch('contacts.person.update.before', $id);

        $person = $this->personRepository->update($request->all(), $id);

        Event::dispatch('contacts.person.update.after', $person);

        if (request()->ajax()) {
            return response()->json([
                'data' => $person,
                'message' => trans('admin::app.contacts.persons.index.update-success'),
            ], 200);
        }

        session()->flash('success', trans('admin::app.contacts.persons.index.update-success'));

        return redirect()->route('admin.contacts.persons.index');
    }

    /**
     * Search person results.
     */
    public function search(): JsonResource
    {
        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $persons = $this->personRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->findWhereIn('user_id', $userIds);
        } else {
            $persons = $this->personRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->all();
        }

        return PersonResource::collection($persons);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $person = $this->personRepository->findOrFail($id);

        if (
            $person->leads
            && $person->leads->count() > 0
        ) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }

        try {
            Event::dispatch('contacts.person.delete.before', $person);

            $person->delete();

            Event::dispatch('contacts.person.delete.after', $person);

            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-success'),
            ], 200);

        } catch (Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * Mass destroy the specified resources from storage.
     */
    public function massDestroy(MassDestroyRequest $request): JsonResponse
    {
        try {
            $persons = $this->personRepository->findWhereIn('id', $request->input('indices', []));

            $deletedCount = 0;

            $blockedCount = 0;

            foreach ($persons as $person) {
                if (
                    $person->leads
                    && $person->leads->count() > 0
                ) {
                    $blockedCount++;

                    continue;
                }

                Event::dispatch('contact.person.delete.before', $person);

                $this->personRepository->delete($person->id);

                Event::dispatch('contact.person.delete.after', $person);

                $deletedCount++;
            }

            $statusCode = 200;

            switch (true) {
                case $deletedCount > 0 && $blockedCount === 0:
                    $message = trans('admin::app.contacts.persons.index.all-delete-success');

                    break;

                case $deletedCount > 0 && $blockedCount > 0:
                    $message = trans('admin::app.contacts.persons.index.partial-delete-warning');

                    break;

                case $deletedCount === 0 && $blockedCount > 0:
                    $message = trans('admin::app.contacts.persons.index.none-delete-warning');

                    $statusCode = 400;

                    break;

                default:
                    $message = trans('admin::app.contacts.persons.index.no-selection');

                    $statusCode = 400;

                    break;
            }

            return response()->json(['message' => $message], $statusCode);
        } catch (Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }
    }
}
