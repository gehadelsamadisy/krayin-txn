<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Replies\SmsInbox;

/**
 * Leads > SMS messages: text conversations with customers (txn_sms_messages),
 * one per customer number, newest first, with the chosen one as a chat.
 * Opening a chat marks its texts read. reply(): a text typed under the chat,
 * sent through SmsSender (SMS guard and test numbers apply).
 */
class SmsMessagesController extends Controller
{
    public function index(Request $request, SmsInbox $inbox): View
    {
        $show = in_array($request->query('show'), ['unread', 'staff', 'unmatched'], true) ? $request->query('show') : 'all';
        $q = trim((string) $request->query('q', ''));
        $chosen = trim((string) $request->query('chat', ''));
        $ready = SmsInbox::ready();

        $conversations = $ready ? $inbox->conversations() : collect();
        $counts = [
            'all' => $conversations->count(),
            'unread' => $conversations->where('unread', '>', 0)->count(),
            'staff' => $conversations->where('needs_staff', true)->count(),
            'unmatched' => $conversations->filter(fn ($row) => ! $row->person_id && ! $row->needs_staff)->count(),
        ];

        $conversations = $conversations
            ->filter(fn ($row) => match ($show) {
                'unread' => $row->unread > 0,
                'staff' => $row->needs_staff,
                'unmatched' => ! $row->person_id && ! $row->needs_staff,
                default => true,
            })
            ->filter(fn ($row) => $q === '' || str_contains(mb_strtolower($row->name.' '.$row->customer_number.' '.($row->lead->title ?? '').' '.$row->body), mb_strtolower($q)))
            ->values();

        if ($chosen === '' && $conversations->isNotEmpty()) {
            $chosen = $conversations->first()->customer_number;
        }

        $current = $conversations->firstWhere('customer_number', $chosen);
        $thread = collect();

        if ($current) {
            $thread = $inbox->thread($chosen);
            $inbox->markRead($chosen);
        }

        return view('admin::leads.sms-messages', [
            'conversations' => $conversations,
            'counts' => $counts,
            'current' => $current,
            'thread' => $thread,
            'staffContacts' => $current && $current->needs_staff ? $this->contactsWith($chosen) : collect(),
            'filters' => compact('show', 'q', 'chosen'),
            'receiving' => (bool) config('automation.sms_intake.enabled'),
            'ready' => $ready,
        ]);
    }

    /** Longest reply (Twilio allows 1600 characters; 160 per SMS part). */
    public const MAX_LENGTH = 1600;

    /**
     * POST leads/sms-messages/reply (permission sms_messages.reply): a text
     * typed under a conversation, sent to that number through SmsSender (the
     * SMS guard and test numbers apply). Only for a number that belongs to
     * exactly one contact. Back to the conversation.
     */
    public function reply(Request $request, SmsInbox $inbox, \Webkul\Admin\Support\Contact\SmsSender $sender): \Illuminate\Http\RedirectResponse
    {
        $number = (string) $request->input('number', '');
        $text = trim((string) $request->input('text', ''));
        $back = redirect()->route('admin.leads.sms_messages', ['chat' => $number]);

        $conversation = SmsInbox::ready() ? $inbox->conversations()->firstWhere('customer_number', $number) : null;
        $person = $conversation && $conversation->person_id ? \Webkul\Contact\Models\Person::query()->find($conversation->person_id) : null;

        $problem = match (true) {
            $text === '' => 'Type a message first.',
            mb_strlen($text) > self::MAX_LENGTH => 'The message is too long (at most '.self::MAX_LENGTH.' characters).',
            ! $conversation => 'That conversation no longer exists.',
            $conversation->needs_staff => 'This number is on several contacts: keep it on the right contact first, then reply.',
            ! $person => 'This number is not on a contact yet: add it to the customer\'s contact first, then reply.',
            default => null,
        };

        if ($problem) {
            return $back->withInput($request->only('text'))->with('warning', $problem);
        }

        $lead = $conversation->lead ? \Webkul\Lead\Models\Lead::query()->find($conversation->lead->id) : null;
        $result = $sender->send($person, $text, $lead, $number);

        return match ($result['status']) {
            'sent' => $back->with('success', "Text sent to {$result['to']}."),
            'blocked' => $back->withInput($request->only('text'))->with('warning', "Not sent: the SMS guard is on and {$result['to']} is not an allowed test number (Settings > TXN Settings > Safety)."),
            default => $back->withInput($request->only('text'))->with('error', 'Not sent: '.($result['error'] ?? 'unknown error').'.'),
        };
    }

    /** The contacts sharing a number (for "Needs staff"). */
    private function contactsWith(string $number): \Illuminate\Support\Collection
    {
        $ids = SmsInbox::personsWithNumber($number);

        return \Illuminate\Support\Facades\DB::table('persons')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }
}
