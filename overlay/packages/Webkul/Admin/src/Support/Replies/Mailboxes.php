<?php

namespace Webkul\Admin\Support\Replies;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Admin\Support\Contact\TxnSettings;

/**
 * The mailboxes Krayin reads customer replies from (table txn_mailboxes,
 * Settings > TXN Settings > Mailboxes).
 *
 * - The main sender (mail.from.address) is always in the list (is_main). Its
 *   login is the one on Connections (graph_mail.*), never copied here.
 * - Every other mailbox Krayin uses to contact customers is added with its
 *   own tenant ID, client ID and client secret (encrypted with APP_KEY,
 *   never sent back to the page, masked "…a1b2" in the change log).
 * - missingLogins(): addresses Krayin has sent customer email from that have
 *   no login here, so replies to them would be missed.
 */
final class Mailboxes
{
    public const AREA = 'settings:mailboxes';

    public static function ready(): bool
    {
        try {
            return Schema::hasTable('txn_mailboxes');
        } catch (Throwable) {
            return false;
        }
    }

    public static function mainAddress(): string
    {
        return strtolower(trim((string) config('mail.from.address')));
    }

    /** Every mailbox, main sender first. Makes sure the main sender has its row. */
    public static function all(): Collection
    {
        if (! self::ready()) {
            return collect();
        }

        self::syncMain();

        return DB::table('txn_mailboxes')->orderByDesc('is_main')->orderBy('address')->get();
    }

    public static function find(int $id): ?object
    {
        return self::ready() ? DB::table('txn_mailboxes')->find($id) : null;
    }

    /**
     * The main sender's row follows mail.from.address: a new address gets a
     * row, and a row for an older main address stays (it may still get
     * replies) but now needs its own login.
     */
    public static function syncMain(): void
    {
        $main = self::mainAddress();

        if ($main === '' || ! filter_var($main, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        DB::table('txn_mailboxes')->where('is_main', true)->where('address', '!=', $main)->update(['is_main' => false, 'updated_at' => now()]);

        if (! DB::table('txn_mailboxes')->where('address', $main)->exists()) {
            DB::table('txn_mailboxes')->insert([
                'address' => $main,
                'name' => 'Main sender',
                'provider' => 'graph',
                'is_main' => true,
                'read_replies' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('txn_mailboxes')->where('address', $main)->where('is_main', false)->update(['is_main' => true, 'updated_at' => now()]);
        }
    }

    /**
     * The login used to read a mailbox: the main sender's from Connections,
     * the others from their row. Null when something is missing.
     *
     * @return array{tenant: string, client: string, secret: string}|null
     */
    public static function login(object $mailbox): ?array
    {
        $login = $mailbox->is_main
            ? ['tenant' => (string) config('graph_mail.tenant_id'), 'client' => (string) config('graph_mail.client_id'), 'secret' => (string) config('graph_mail.client_secret')]
            : ['tenant' => (string) $mailbox->tenant_id, 'client' => (string) $mailbox->client_id, 'secret' => (string) self::decrypt($mailbox->client_secret)];

        foreach ($login as $value) {
            if (trim($value) === '' || $value === 'CHANGE_ME') {
                return null;
            }
        }

        return array_map('trim', $login);
    }

    public static function client(object $mailbox): ?GraphMailbox
    {
        $login = self::login($mailbox);

        return $login ? new GraphMailbox($mailbox->address, $login['tenant'], $login['client'], $login['secret']) : null;
    }

    /** "…a1b2" for the page: the stored secret's ending only. */
    public static function secretEnding(object $mailbox): ?string
    {
        $secret = $mailbox->is_main ? (string) config('graph_mail.client_secret') : (string) self::decrypt($mailbox->client_secret);

        return $secret === '' ? null : TxnSettings::mask($secret);
    }

    /** Every address Krayin reads from (lower case), to skip our own emails. */
    public static function addresses(): array
    {
        return self::all()->pluck('address')->map(fn ($a) => strtolower($a))->push(self::mainAddress())->filter()->unique()->values()->all();
    }

    /**
     * Addresses Krayin has sent customer email from (Mail > Sent) that have no
     * mailbox with a working login here.
     *
     * @return array<int, string>
     */
    public static function missingLogins(): array
    {
        $covered = self::all()->filter(fn ($m) => $m->read_replies && self::login($m))->pluck('address')->map(fn ($a) => strtolower($a))->all();

        return DB::table('emails')
            ->where('folders', 'like', '%"sent"%')
            ->whereNotNull('from')
            ->distinct()
            ->limit(500)
            ->pluck('from')
            ->map(fn ($from) => self::addressOf($from))
            ->filter(fn ($a) => $a && ! in_array($a, $covered, true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** The address in an emails.from value (JSON string, JSON list, or plain). */
    public static function addressOf(mixed $from): ?string
    {
        $value = is_string($from) ? (json_decode($from, true) ?? $from) : $from;
        $value = is_array($value) ? ($value[0] ?? $value['address'] ?? $value['email'] ?? null) : $value;
        $value = is_array($value) ? ($value['address'] ?? $value['email'] ?? null) : $value;

        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (preg_match('/<([^>]+)>/', $value, $match)) {
            $value = trim($match[1]);
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /**
     * Adds or changes a mailbox. A blank secret keeps the stored one. The
     * main sender's login is not stored here (it is on Connections).
     *
     * @return array{0: ?int, 1: array<int, string>} [id, errors]
     */
    public static function save(?int $id, array $input, ?int $userId): array
    {
        $current = $id ? self::find($id) : null;

        if ($id && ! $current) {
            return [null, ['That mailbox no longer exists.']];
        }

        $isMain = (bool) ($current->is_main ?? false);
        $address = strtolower(trim((string) ($input['address'] ?? $current->address ?? '')));
        $values = [
            'address' => $isMain ? $current->address : $address,
            'name' => mb_substr(trim((string) ($input['name'] ?? '')), 0, 100) ?: null,
            'read_replies' => ! empty($input['read_replies']),
        ];
        $errors = [];

        if (! filter_var($values['address'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Address: type a valid email address.';
        } elseif (DB::table('txn_mailboxes')->where('address', $values['address'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $errors[] = "{$values['address']} is already in the list.";
        }

        if (! $isMain) {
            $guid = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

            foreach (['tenant_id' => 'Tenant ID', 'client_id' => 'Client ID'] as $field => $label) {
                $values[$field] = trim((string) ($input[$field] ?? ''));

                if (! preg_match($guid, $values[$field])) {
                    $errors[] = "{$label}: a GUID like 1b2c3d4e-0000-0000-0000-000000000000.";
                }
            }

            $secret = trim((string) ($input['client_secret'] ?? ''));

            if ($secret !== '') {
                $values['client_secret'] = Crypt::encryptString($secret);
            } elseif (! $current || ! self::decrypt($current->client_secret)) {
                $errors[] = 'Client secret: paste the secret of the app.';
            }
        }

        if ($errors) {
            return [null, $errors];
        }

        $values += ['updated_by' => $userId, 'updated_at' => now()];

        if ($current) {
            DB::table('txn_mailboxes')->where('id', $id)->update($values);
        } else {
            $id = DB::table('txn_mailboxes')->insertGetId($values + ['provider' => 'graph', 'is_main' => false, 'created_at' => now()]);
        }

        self::logChanges($current, (object) array_merge((array) $current, $values), $userId, isset($secret) && $secret !== '' ? $secret : null);

        return [$id, []];
    }

    /** Removes a mailbox (not the main sender's). */
    public static function delete(int $id, ?int $userId): bool
    {
        $mailbox = self::find($id);

        if (! $mailbox || $mailbox->is_main) {
            return false;
        }

        DB::table('txn_mailboxes')->where('id', $id)->delete();
        self::log($userId, $mailbox->address, 'read replies', 'removed');

        return true;
    }

    /** Status after a check: when, what it found, or what went wrong. */
    public static function markChecked(int $id, ?string $result, ?string $error, mixed $receivedUntil = null): void
    {
        $values = ['last_checked_at' => now(), 'last_result' => $result ? mb_substr($result, 0, 300) : null, 'last_error' => $error ? mb_substr($error, 0, 500) : null];

        if ($receivedUntil) {
            $values['received_until'] = $receivedUntil;
        }

        DB::table('txn_mailboxes')->where('id', $id)->update($values);
    }

    private static function logChanges(?object $before, object $after, ?int $userId, ?string $newSecret): void
    {
        if (! $before) {
            self::log($userId, $after->address, 'added', 'reads replies: '.($after->read_replies ? 'on' : 'off').($newSecret ? ', secret '.TxnSettings::mask($newSecret) : ''));

            return;
        }

        $labels = ['address' => 'address', 'name' => 'name', 'read_replies' => 'read replies', 'tenant_id' => 'tenant ID', 'client_id' => 'client ID'];

        foreach ($labels as $field => $label) {
            if (! property_exists($after, $field)) {
                continue;
            }

            $old = $field === 'read_replies' ? ((bool) ($before->{$field} ?? false) ? 'on' : 'off') : (string) ($before->{$field} ?? '');
            $new = $field === 'read_replies' ? ((bool) $after->{$field} ? 'on' : 'off') : (string) ($after->{$field} ?? '');

            if ($old !== $new) {
                self::log($userId, "{$after->address}: {$label}", $old === '' ? '(empty)' : $old, $new === '' ? '(empty)' : $new);
            }
        }

        if ($newSecret) {
            $old = self::decrypt($before->client_secret ?? null);
            self::log($userId, "{$after->address}: client secret", $old ? TxnSettings::mask($old) : '(none)', TxnSettings::mask($newSecret));
        }
    }

    private static function log(?int $userId, string $item, string $old, string $new): void
    {
        DB::table('txn_settings_log')->insert([
            'user_id' => $userId,
            'area' => self::AREA,
            'item' => mb_substr("Mailbox {$item}", 0, 250),
            'old_value' => $old,
            'new_value' => $new,
            'created_at' => now(),
        ]);
    }

    private static function decrypt(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }
}
