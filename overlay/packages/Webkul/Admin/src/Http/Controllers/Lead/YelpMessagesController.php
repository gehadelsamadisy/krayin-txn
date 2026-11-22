<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\ServiceHealth;
use Webkul\Admin\Support\Contact\YelpMessageStore;

/**
 * Leads > Yelp messages: every Yelp conversation saved from Zapier (lead
 * database, yelp_message), newest first, with the chosen one as a chat.
 * Read-only: replies are written in Yelp ("Reply in Yelp"). Opening a chat
 * marks its messages read.
 */
class YelpMessagesController extends Controller
{
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

        $current = $conversations->firstWhere('source_lead_id', $chosen)
            ?? ($chosen !== '' ? (object) ['source_lead_id' => $chosen, 'lead' => YelpMessageStore::leadsFor([$chosen])->first(), 'name' => 'Yelp lead '.$chosen] : null);

        return view('admin::leads.yelp-messages', [
            'conversations' => $conversations,
            'counts' => $counts ?? ['all' => 0, 'unread' => 0, 'unmatched' => 0],
            'current' => $current,
            'thread' => $thread,
            'replyUrl' => self::replyUrl($current?->source_lead_id),
            'filters' => compact('show', 'q', 'chosen'),
            'receiving' => (bool) config('automation.yelp.enabled'),
            'error' => $error,
        ]);
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
