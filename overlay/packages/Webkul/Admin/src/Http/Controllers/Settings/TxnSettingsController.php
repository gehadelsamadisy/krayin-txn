<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\BookingEmail;
use Webkul\Admin\Support\Contact\SlotSuggestionException;
use Webkul\Admin\Support\Contact\SlotSuggestionService;
use Webkul\Admin\Support\Contact\TxnConnectionTester;
use Webkul\Admin\Support\Contact\TxnSettings;

/**
 * Settings > TXN Settings: Company & sender, Services, Service area &
 * scheduling, Follow-ups, Emails, Connections (Support\Contact\TxnSettings).
 * One route pair per screen (own permissions); the screen comes from the route.
 * Connections is locked until "Edit keys" is confirmed (unlocked=1) and has
 * Test buttons (TxnConnectionTester).
 * "reset" in the form puts settings back to their defaults: a key, an email
 * group ("emails:link") or "all" for the whole screen.
 */
class TxnSettingsController extends Controller
{
    public function show(string $screen): View
    {
        abort_unless(isset(TxnSettings::SCREENS[$screen]), 404);

        $keys = TxnSettings::keys($screen);

        return view("admin::settings.txn.{$screen}", [
            'screen' => $screen,
            'title' => TxnSettings::SCREENS[$screen][0],
            'info' => TxnSettings::SCREENS[$screen][1],
            // A secret's value never goes to the page (secretStatus() shows its ending).
            'values' => collect($keys)->mapWithKeys(fn ($key) => [$key => TxnSettings::isSecret($key) ? null : config($key)])->all(),
            'defaults' => collect($keys)->mapWithKeys(fn ($key) => [$key => TxnSettings::isSecret($key) ? null : TxnSettings::default($key)])->all(),
            'saved' => DB::table('txn_settings')->whereIn('key', $keys)->pluck('key')->flip()->all(),
            'fields' => array_intersect_key(TxnSettings::fields(), array_flip($keys)),
            'canEdit' => bouncer()->hasPermission("settings.txn.{$this->aclKey($screen)}.edit"),
            'log' => DB::table('txn_settings_log as l')
                ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
                ->where('l.area', "settings:{$screen}")
                ->orderByDesc('l.id')
                ->limit(30)
                ->get(['l.created_at', 'u.name as user', 'l.item', 'l.old_value', 'l.new_value']),
        ] + match ($screen) {
            'emails' => $this->emailExtras(),
            'connections' => $this->connectionExtras($keys),
            'safety' => $this->safetyExtras($keys),
            default => [],
        });
    }

    /** Screens that open locked and save only after "Edit" is confirmed (unlocked=1). */
    private const LOCKED_SCREENS = ['connections', 'safety'];

    public function update(Request $request, string $screen): RedirectResponse
    {
        abort_unless(isset(TxnSettings::SCREENS[$screen]), 404);

        if (in_array($screen, self::LOCKED_SCREENS, true)) {
            return $this->updateLocked($request, $screen);
        }

        return $this->saveScreen($request, $screen);
    }

    /**
     * Connections and Safety: saved only when the screen was unlocked
     * (confirmed) and the user may edit it. Typed secrets are never kept in
     * the session when the save fails.
     */
    private function updateLocked(Request $request, string $screen): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission("settings.txn.{$screen}.edit"), 403);

        $route = "admin.settings.txn.{$screen}";

        if ($request->input('unlocked') !== '1') {
            return redirect()->route($route)
                ->withErrors(['settings' => ['Unlock the page and confirm before changing anything.']]);
        }

        try {
            return $this->saveScreen($request, $screen);
        } catch (ValidationException $exception) {
            $secretInputs = array_map([$this, 'inputName'], array_filter(TxnSettings::keys($screen), [TxnSettings::class, 'isSecret']));

            return redirect()->route($route)
                ->withErrors($exception->errors())
                ->withInput($request->except(array_merge($secretInputs, ['_token', '_method', 'unlocked', 'confirm_off'])));
        }
    }

    /**
     * Safety: guards, test lists and import option. Turning a guard OFF (so
     * customers are contacted) needs the typed confirmation; turning one on
     * never does.
     */
    private function safety(Request $request): array
    {
        $values = [];
        $turningOff = [];

        foreach (TxnSettings::keys('safety') as $key) {
            $type = TxnSettings::fields()[$key][1];
            $typed = $request->input($this->inputName($key));

            $values[$key] = in_array($type, ['guard', 'bool'], true) ? ((string) $typed === '1') : (string) $typed;

            if ($type === 'guard' && $values[$key] === false && config($key, true)) {
                $turningOff[] = TxnSettings::GUARDS[$key];
            }
        }

        $errors = $turningOff && trim((string) $request->input('confirm_off')) !== TxnSettings::GUARD_OFF_CONFIRMATION
            ? ['To turn off the '.implode(' and ', $turningOff).', type '.TxnSettings::GUARD_OFF_CONFIRMATION.' in the box at the bottom. Nothing was saved.']
            : [];

        return [$values, $errors];
    }

    /** Safety screen: the names of the test contacts, so the IDs are readable. */
    private function safetyExtras(array $keys): array
    {
        $ids = (array) config('calcom.test_person_ids', []);

        return [
            'inputNames' => collect($keys)->mapWithKeys(fn ($key) => [$key => $this->inputName($key)])->all(),
            'testPeople' => $ids ? DB::table('persons')->whereIn('id', $ids)->pluck('name', 'id')->all() : [],
            'guardKeys' => TxnSettings::GUARDS,
            'confirmText' => TxnSettings::GUARD_OFF_CONFIRMATION,
        ];
    }

    private function saveScreen(Request $request, string $screen): RedirectResponse
    {
        $userId = auth()->guard('user')->id();

        if ($reset = (string) $request->input('reset', '')) {
            $keys = match (true) {
                $reset === 'all' => TxnSettings::keys($screen),
                str_starts_with($reset, 'emails:') => array_filter(TxnSettings::keys($screen), fn ($key) => str_starts_with($key, 'lead_messages.templates.'.substr($reset, 7).'.')),
                default => array_intersect([$reset], TxnSettings::keys($screen)),
            };

            // A guard only changes through its switch (turning it off needs the
            // typed confirmation), never through a reset to the .env default.
            $keys = array_diff($keys, array_keys(TxnSettings::GUARDS));

            $changes = TxnSettings::save($screen, array_fill_keys($keys, null), $userId);

            return $this->back($screen, $changes ? "Back to the default ({$changes} ".($changes === 1 ? 'setting' : 'settings').').' : 'Already the default.');
        }

        [$values, $errors] = match ($screen) {
            'company' => [$this->company($request), []],
            'services' => [$this->services($request), []],
            'area' => $this->area($request),
            'follow-ups' => $this->followUps($request),
            'emails' => [$this->emails($request), []],
            'connections' => $this->connections($request),
            'safety' => $this->safety($request),
        };

        $changes = TxnSettings::save($screen, $values, $userId, $errors);

        return $this->back($screen, $changes ? "Saved ({$changes} ".($changes === 1 ? 'change' : 'changes').').' : 'Nothing changed.');
    }

    /**
     * Test button on the Connections screen: checks one service with the
     * values on the form (a blank secret = the current one). JSON; never
     * sends an SMS or email and never returns a secret.
     */
    public function testConnection(Request $request): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('settings.txn.connections.edit'), 403);

        $test = (string) $request->input('service', '');

        if (! in_array($test, TxnConnectionTester::TESTS, true)) {
            return response()->json(['ok' => false, 'message' => 'Unknown test.'], 422);
        }

        if ($request->input('unlocked') !== '1') {
            return response()->json(['ok' => false, 'message' => 'Click "Edit keys" and confirm first.'], 403);
        }

        return response()->json((new TxnConnectionTester($this->connectionFormValues($request)))->run($test));
    }

    /** Form field name of a setting: "sms.auth_token" -> "sms__auth_token". */
    private function inputName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** The values on the Connections form; a blank secret means "keep the current one". */
    private function connectionFormValues(Request $request): array
    {
        $values = [];

        foreach (TxnSettings::keys('connections') as $key) {
            $typed = $request->input($this->inputName($key));
            $values[$key] = TxnSettings::isSecret($key) && trim((string) $typed) === '' ? config($key) : $typed;
        }

        return $values;
    }

    /**
     * Connections save: plain fields as typed, secrets only when something
     * was typed (blank = keep). The chosen mailer must have its login.
     */
    private function connections(Request $request): array
    {
        $values = [];

        foreach (TxnSettings::keys('connections') as $key) {
            $typed = (string) $request->input($this->inputName($key), '');

            if (TxnSettings::isSecret($key) && trim($typed) === '') {
                continue;
            }

            // An untouched field is not checked or saved again, so a placeholder
            // left in .env (e.g. CHANGE_ME) never blocks saving the other fields.
            if (! TxnSettings::isSecret($key) && trim($typed) === trim((string) config($key))) {
                continue;
            }

            $values[$key] = $typed;
        }

        // Only when the mailer is being changed: the new one must have its login.
        $current = in_array(config('mail.default'), ['array', 'log'], true) ? TxnSettings::default('mail.default') : config('mail.default');

        if (($values['mail.default'] ?? '') === $current) {
            return [$values, []];
        }

        $effective = $this->connectionFormValues($request);
        $need = [
            'smtp' => ['mail.mailers.smtp.host', 'mail.mailers.smtp.port', 'mail.mailers.smtp.username', 'mail.mailers.smtp.password'],
            'graph' => ['graph_mail.tenant_id', 'graph_mail.client_id', 'graph_mail.client_secret'],
        ][$values['mail.default'] ?? ''] ?? [];
        $missing = array_filter($need, fn ($key) => trim((string) $effective[$key]) === '');
        $errors = $missing
            ? ['Mailer '.TxnSettings::MAILERS[$values['mail.default']].': fill in '.implode(', ', array_map(fn ($key) => TxnSettings::fields()[$key][2], $missing)).' first, or emails would stop.']
            : [];

        return [$values, $errors];
    }

    /** Connections screen: cards, field names, and each secret's status (never the value). */
    private function connectionExtras(array $keys): array
    {
        return [
            'groups' => TxnSettings::CONNECTION_GROUPS,
            'inputNames' => collect($keys)->mapWithKeys(fn ($key) => [$key => $this->inputName($key)])->all(),
            'secrets' => collect($keys)->filter([TxnSettings::class, 'isSecret'])->mapWithKeys(fn ($key) => [$key => TxnSettings::secretStatus($key)])->all(),
        ];
    }

    /** Permission key part: "follow-ups" -> "follow_ups". */
    private function aclKey(string $screen): string
    {
        return str_replace('-', '_', $screen);
    }

    private function back(string $screen, string $message): RedirectResponse
    {
        return redirect()->route("admin.settings.txn.{$this->aclKey($screen)}")->with('success', $message);
    }

    private function company(Request $request): array
    {
        return collect(['company', 'rep_name', 'rep_cell', 'office_phone', 'accent'])
            ->mapWithKeys(fn ($name) => ["lead_messages.{$name}" => (string) $request->input($name, '')])
            ->all();
    }

    /** Rows (keywords, category, flow, confirmation line, order) -> the service list and the service lines. */
    private function services(Request $request): array
    {
        $rows = collect((array) $request->input('rows', []))
            ->filter(fn ($row) => is_array($row) && empty($row['remove']))
            ->sortBy(fn ($row) => is_numeric($row['order'] ?? null) ? (float) $row['order'] : PHP_INT_MAX)
            ->values();

        $lines = $rows
            ->filter(fn ($row) => trim((string) ($row['category'] ?? '')) !== '' && trim((string) ($row['line'] ?? '')) !== '')
            ->mapWithKeys(fn ($row) => [trim($row['category']) => (string) $row['line']])
            ->all();
        $lines['default'] = (string) $request->input('default_line', '');

        return [
            'lead_triage.services' => $rows->map(fn ($row) => [
                'match' => (string) ($row['keywords'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'flow' => (string) ($row['flow'] ?? 'standard'),
            ])->all(),
            'lead_messages.service_lines' => $lines,
        ];
    }

    /**
     * Area, out-of-area message and slot settings. A changed address is looked
     * up once (Geocodio) and its point stored; otherwise the typed point stays.
     */
    private function area(Request $request): array
    {
        $errors = [];
        $current = (array) config('slot_finder.service_area');
        $address = trim((string) $request->input('area_address', ''));
        $lat = $request->input('area_lat');
        $lon = $request->input('area_lon');

        if ($address !== '' && $address !== (string) ($current['address'] ?? '')) {
            try {
                $point = app(SlotSuggestionService::class)->locatePlace($address);
            } catch (SlotSuggestionException $exception) {
                $point = null;
                $errors[] = 'Service area: '.$exception->getMessage();
            }

            if ($point) {
                [$lat, $lon] = [$point['lat'], $point['lon']];
            } elseif (! $errors) {
                $errors[] = "Service area: \"{$address}\" was not found on the map. Check the spelling, or type the latitude and longitude.";
            }
        }

        $values = [
            'slot_finder.service_area' => ['address' => $address, 'center_lat' => $lat, 'center_lon' => $lon, 'miles' => $request->input('area_miles')],
            'lead_triage.out_of_area_message' => (string) $request->input('out_of_area_message', ''),
            'slot_finder.slot_times' => (string) $request->input('slot_times', ''),
        ];

        foreach (['slots_to_offer', 'search_days', 'min_notice_minutes', 'visit_minutes', 'max_slots_per_day', 'max_extra_drive_minutes'] as $name) {
            $values["slot_finder.{$name}"] = $request->input($name);
        }

        return [$values, $errors];
    }

    /** Follow-up hours must go up: reminder, then final, then Lost. */
    private function followUps(Request $request): array
    {
        $values = [];

        foreach (['reminder_after_hours', 'final_after_hours', 'lost_after_hours', 'min_hours_before_slot', 'lost_reason'] as $name) {
            $values["follow_ups.{$name}"] = $request->input($name);
        }

        [$reminder, $final, $lost] = [(int) $values['follow_ups.reminder_after_hours'], (int) $values['follow_ups.final_after_hours'], (int) $values['follow_ups.lost_after_hours']];
        $errors = $reminder < $final && $final < $lost
            ? []
            : ['Follow-ups: the hours must go up: reminder first, then the final email, then Lost.'];

        return [$values, $errors];
    }

    private function emails(Request $request): array
    {
        $values = [];

        foreach (TxnSettings::EMAIL_PARTS as $group => [, $parts]) {
            foreach (array_keys($parts) as $part) {
                $values["lead_messages.templates.{$group}.{$part}"] = (string) $request->input("parts.{$group}.{$part}", '');
            }
        }

        return $values;
    }

    /**
     * The Emails screen's previews: each email rendered with a sample
     * customer (the real templates), plus the sample placeholder values so
     * the page can update the preview while typing.
     */
    private function emailExtras(): array
    {
        $tz = config('slot_finder.timezone');
        $start = now($tz)->addDays(2)->setTime(9, 0);
        $slots = [$start->format('Y-m-d\TH:i'), $start->copy()->setTime(13, 0)->format('Y-m-d\TH:i'), $start->copy()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i')];
        $mail = app(BookingEmail::class);
        $sample = fn (string $kind, array $times, ?string $url) => $this->sampleData($mail, $kind, $tz, $times, $url);

        $previews = [
            'link' => ['Booking link', $sample('link', $slots, 'https://cal.com/example')],
            'reminder' => ['Reminder (times open)', $sample('reminder', $slots, 'https://cal.com/example')],
            'reminder_no_times' => ['Reminder (no times left)', $sample('reminder', [], null)],
            'final' => ['Final', $sample('final', [], null)],
        ];

        return [
            'placeholders' => TxnSettings::PLACEHOLDERS,
            'sampleVars' => $mail->vars($previews['link'][1]),
            'previews' => collect($previews)->map(fn ($preview) => [
                'label' => $preview[0],
                'kind' => $preview[1]['kind'],
                'offer' => $preview[1]['kind'] !== 'final' && $preview[1]['url'] && $preview[1]['times'],
                'html' => view('admin::emails.booking-link', $preview[1])->render(),
            ])->all(),
        ];
    }

    private function sampleData(BookingEmail $mail, string $kind, string $tz, array $slots, ?string $url): array
    {
        $data = [
            'kind' => $kind,
            'firstName' => 'Jane',
            'service' => 'gutter',
            'times' => collect($slots)->map(fn ($slot) => \Carbon\CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slot, $tz)->format('l, M j \a\t g:i A'))->all(),
            'timezoneLabel' => $tz === 'America/Chicago' ? 'Central Time' : $tz,
            'url' => $url,
            'sender' => config('lead_messages'),
        ];

        return $data + $mail->texts($kind, $data) + ['parts' => $mail->sharedParts($data)];
    }
}
