<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\PipelineEvents;
use Webkul\Admin\Support\Replies\EmailReplyImporter;
use Webkul\Admin\Support\Replies\Mailboxes;

/**
 * Settings > TXN Settings > Mailboxes: the mailboxes Krayin reads customer
 * replies from (Support\Replies\Mailboxes), each with its own Microsoft 365
 * login. Test signs in and reads one email; Check now imports new replies
 * of one mailbox at once. Secrets never go back to the page.
 */
class TxnMailboxesController extends Controller
{
    public function index(): View
    {
        $mailboxes = Mailboxes::all();

        return view('admin::settings.txn.mailboxes', [
            'mailboxes' => $mailboxes->map(fn ($m) => (object) [
                'row' => $m,
                'hasLogin' => (bool) Mailboxes::login($m),
                'ends' => Mailboxes::secretEnding($m),
            ]),
            'missing' => Mailboxes::missingLogins(),
            'intakeOn' => (bool) config('automation.email_intake.enabled'),
            'minutes' => (int) config('automation.email_intake.minutes', 2),
            'canEdit' => bouncer()->hasPermission('settings.txn.mailboxes.edit'),
            'log' => DB::table('txn_settings_log as l')
                ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
                ->where('l.area', Mailboxes::AREA)
                ->orderByDesc('l.id')
                ->limit(30)
                ->get(['l.created_at', 'u.name as user', 'l.item', 'l.old_value', 'l.new_value']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return $this->save($request, $id);
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->authorizeEdit();

        $mailbox = Mailboxes::find($id);
        $ok = Mailboxes::delete($id, auth()->guard('user')->id());

        return redirect()->route('admin.settings.txn.mailboxes')
            ->with($ok ? 'success' : 'error', $ok ? "Removed {$mailbox->address}. Krayin no longer reads it." : 'The main sender can\'t be removed: change the sender on Connections instead.');
    }

    /** Test button: signs in and reads one email. JSON; nothing changes. */
    public function test(int $id): JsonResponse
    {
        $this->authorizeEdit();

        $mailbox = Mailboxes::find($id);
        $client = $mailbox ? Mailboxes::client($mailbox) : null;

        if (! $client) {
            return response()->json(['ok' => false, 'message' => $mailbox ? 'Missing: tenant ID, client ID or client secret.' : 'That mailbox no longer exists.']);
        }

        $result = $client->test();

        if (! $result['ok']) {
            Mailboxes::markChecked($mailbox->id, null, $result['message']);
        }

        return response()->json($result);
    }

    /** Check now: imports new emails of one mailbox (works without the scheduler). */
    public function check(int $id): RedirectResponse
    {
        $this->authorizeEdit();

        $results = PipelineEvents::as(auth()->guard('user')->user()?->name ?? 'Staff', 'button', fn () => app(EmailReplyImporter::class)->run($id));
        $result = $results[0] ?? ['ok' => false, 'message' => 'That mailbox no longer exists.', 'address' => ''];

        return redirect()->route('admin.settings.txn.mailboxes')
            ->with($result['ok'] ? 'success' : 'error', trim(($result['address'] ? "{$result['address']}: " : '').$result['message']));
    }

    private function save(Request $request, ?int $id): RedirectResponse
    {
        $this->authorizeEdit();

        [$savedId, $errors] = Mailboxes::save($id, $request->only(['address', 'name', 'tenant_id', 'client_id', 'client_secret', 'read_replies']), auth()->guard('user')->id());

        if ($errors) {
            return redirect()->route('admin.settings.txn.mailboxes')
                ->withErrors(['mailbox' => $errors])
                ->withInput($request->except(['client_secret', '_token', '_method']) + ['editing' => $id ?? 'new']);
        }

        $mailbox = Mailboxes::find($savedId);

        return redirect()->route('admin.settings.txn.mailboxes')
            ->with('success', ($id ? 'Saved ' : 'Added ').$mailbox->address.'. Use Test to check that Krayin can read it.');
    }

    private function authorizeEdit(): void
    {
        abort_unless(bouncer()->hasPermission('settings.txn.mailboxes.edit'), 403);
    }
}
