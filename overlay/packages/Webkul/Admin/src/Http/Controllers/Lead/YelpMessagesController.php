<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\ServiceHealth;
use Webkul\Admin\Support\Contact\YelpMessageStore;
use Webkul\Admin\Support\Contact\YelpReplySender;

/**
 * Leads > Yelp messages: every Yelp conversation saved from Zapier (lead
 * database, yelp_message), newest first, with the chosen one as a chat.
 * Opening a chat marks its messages read. reply(): a message typed under the
 * chat (here or on the lead's Yelp card), sent through YelpReplySender.
 */
class YelpMessagesController extends Controller
{
    /** POST leads/yelp-messages/{chat}/reply (permission yelp_messages.reply). Back to the page it came from. */
    public function reply(Request $request, string $chat, YelpReplySender $sender): RedirectResponse
    {
        $result = $sender->send($chat, (string) $request->input('text', ''), auth()->guard('user')->user());

        $back = back();

        // Keep the typed text when nothing was sent, so it isn't lost.
        if ($result['status'] !== 'sent' && $result['status'] !== 'failed') {
            $back = $back->withInput($request->only('text'));
        }

        return $back->with($result['status'] === 'sent' ? 'success' : 'warning', $result['message']);
    }

    public function index(Request $request, YelpMessageStore $store): View
    {
        $show = in_array($request->query('show'), ['unread', 'unmatched'], true) ? $request->query('show') : 'all';
        $q = trim((string) $request->query('q', ''));
        $chosen = trim((string) $request->query('chat', ''));
        $error = null;
        $conversations = collect();
        $thread = collect();

        if (! ServiceHealth::hasKey('msg_db')) {
            $error = 'The Yelp messages database is not set up. Add its login on Settings > TXN Settings > Connections > Yelp messages database.';
        } else {
            try {
                $conversations = $store->conversations();
                $leads = YelpMessageStore::leadsFor($conversations->pluck('source_lead_id'));

                $conversations = $conversations->map(function ($row) use ($leads) {
                    $row->lead = $leads[$row->source_lead_id] ?? null;
                    $row->name = $row->lead?->person?->name
                        ?: (! YelpMessageStore::fromBusiness($row->user_type) ? $row->user_name : null)
                        ?: 'Yelp lead '.$row->source_lead_id;

                    return $row;
                });

                $counts = [
                    'all' => $conversations->count(),
                    'unread' => $conversations->where('unread', '>', 0)->count(),
                    'unmatched' => $conversations->whereNull('lead')->count(),
                ];

                $conversations = $conversations
                    ->filter(fn ($row) => match ($show) {
                        'unread' => $row->unread > 0,
                        'unmatched' => ! $row->lead,
                        default => true,
                    })
                    ->filter(fn ($row) => $q === '' || str_contains(mb_strtolower($row->name.' '.($row->lead?->title ?? '').' '.$row->source_lead_id.' '.$row->text), mb_strtolower($q)))
                    ->values();

                if ($chosen === '' && $conversations->isNotEmpty()) {
                    $chosen = $conversations->first()->source_lead_id;
                }

                if ($chosen !== '') {
                    $thread = $store->thread($chosen);
                    $store->markRead($chosen);
                }

                ServiceHealth::ok('msg_db');
            } catch (Throwable $exception) {
                ServiceHealth::failed('msg_db', 'Krayin could not read the Yelp messages: '.$exception->getMessage());
                $error = 'Krayin could not read the Yelp messages database. Use Test on Settings > TXN Settings > Connections > Yelp messages database.';
            }
        }

        // A chat without messages yet (New chat, or the Chat button on a lead / contact).
        $current = $conversations->firstWhere('source_lead_id', $chosen);

        if (! $current && $chosen !== '') {
            $chosenLead = YelpMessageStore::leadsFor([$chosen])->first();
            $current = (object) ['source_lead_id' => $chosen, 'lead' => $chosenLead, 'name' => $chosenLead?->person?->name ?: 'Yelp lead '.$chosen];
        }

        // "New chat": Yelp leads in Krayin, found by the contact's name or the lead title.
        $newChat = $request->boolean('new');
        $find = trim((string) $request->query('find', ''));
        $picker = $newChat ? self::yelpLeads($find) : collect();

        return view('admin::leads.yelp-messages', [
            'conversations' => $conversations,
            'counts' => $counts ?? ['all' => 0, 'unread' => 0, 'unmatched' => 0],
            'current' => $current,
            'thread' => $thread,
            'replyUrl' => self::replyUrl($current?->source_lead_id),
            'filters' => compact('show', 'q', 'chosen'),
            'newChat' => $newChat,
            'find' => $find,
            'picker' => $picker,
            'withMessages' => $conversations->pluck('source_lead_id')->flip(),
            'receiving' => (bool) config('automation.yelp.enabled'),
            'error' => $error,
        ]);
    }

    /**
     * Yelp leads in Krayin (title tag "[TXN-YELP-<id>]"), newest first, with
     * their contact; filtered by contact name or lead title. Each row:
     * source_lead_id, lead, name.
     */
    public static function yelpLeads(string $find = '', int $limit = 30): \Illuminate\Support\Collection
    {
        $like = '%'.addcslashes($find, '%_\\').'%';

        return \Webkul\Lead\Models\Lead::query()->with('person')
            ->where('title', 'like', '%[TXN-YELP-%')
            ->when($find !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('title', 'like', $like)
                ->orWhereHas('person', fn ($p) => $p->where('name', 'like', $like))))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($lead) => (object) [
                'source_lead_id' => YelpMessageStore::sourceLeadId($lead),
                'lead' => $lead,
                'name' => $lead->person?->name ?: $lead->title,
            ])
            ->filter(fn ($row) => $row->source_lead_id)
            ->values();
    }

    /**
     * "Reply in Yelp" link of a Yelp lead: lead.reply_url in the lead database
     * (TXNHome saves Yelp's link_to_reply_in_yelp there), read with the
     * read-only lead login. Null when unknown or unreachable.
     */
    public static function replyUrl(?string $sourceLeadId): ?string
    {
        if (! $sourceLeadId || ! ServiceHealth::hasKey('lead_db')) {
            return null;
        }

        try {
            $url = (string) \Illuminate\Support\Facades\DB::connection('lead_db')->table('lead')
                ->where('source', 'YELP')->where('source_lead_id', $sourceLeadId)->value('reply_url');

            return str_starts_with($url, 'https://') ? $url : null;
        } catch (Throwable) {
            return null;
        }
    }
}
