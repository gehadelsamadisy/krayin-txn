<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Replies\SmsInbox;

/**
 * Leads > SMS messages: text conversations with customers (txn_sms_messages),
 * one per customer number, newest first, with the chosen one as a chat.
 * Opening a chat marks its texts read. Read-only: replies are sent as before
 * (booking-link and follow-up texts through SmsSender).
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

    /** The contacts sharing a number (for "Needs staff"). */
    private function contactsWith(string $number): \Illuminate\Support\Collection
    {
        $ids = SmsInbox::personsWithNumber($number);

        return \Illuminate\Support\Facades\DB::table('persons')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }
}
