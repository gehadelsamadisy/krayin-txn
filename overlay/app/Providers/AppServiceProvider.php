<?php

namespace App\Providers;

use App\Mail\GraphTransport;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Admin\Http\Controllers\Lead\LeadWebhookController;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // MAIL_MAILER=graph: send through Microsoft 365 (config/graph_mail.php),
        // added here so the stock config/mail.php stays unchanged.
        if (! config()->has('mail.mailers.graph')) {
            config(['mail.mailers.graph' => ['transport' => 'graph']]);
        }

        // Lead database (config/lead_triage.php lead_db), added here so the
        // stock config/database.php stays unchanged. Connects only when used.
        config(['database.connections.lead_db' => config('lead_triage.lead_db')]);

        // Yelp messages (Support\Contact\YelpMessageStore): same database, own login.
        config(['database.connections.msg_db' => \Webkul\Admin\Support\Contact\YelpMessageStore::connectionConfig()]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Krayin is reached two ways: the office address (plain http, e.g.
        // http://192.168.100.114:8080) and the public link through Cloudflare
        // (https://txnhome.epro.co). Cloudflare ends the https and passes the
        // request on as http with "X-Forwarded-Proto: https". Without this,
        // pages opened on the public link would point their data calls at
        // http://, which the browser blocks on an https page (empty leads
        // board). Office requests carry no such header and stay as they were.
        // Uploaded files (logo, favicon) load from the address the page was
        // opened on instead of APP_URL. APP_URL is still used outside web
        // requests (queued jobs, commands).
        if (! $this->app->runningInConsole()) {
            // CF-Visitor ({"scheme":"https"}) too, in case a proxy between
            // Cloudflare and the server rewrites X-Forwarded-Proto.
            if (strtolower((string) request()->header('X-Forwarded-Proto')) === 'https'
                || str_contains((string) request()->header('CF-Visitor'), '"https"')) {
                \Illuminate\Support\Facades\URL::forceScheme('https');
            }

            config(['filesystems.disks.public.url' => url('/storage')]);
        }

        // Values changed on Settings > TXN Settings, over the config defaults
        // (Support\Contact\TxnSettings). Again before each queued job, so a
        // long-running queue worker sees changes made after it started.
        \Webkul\Admin\Support\Contact\TxnSettings::apply();
        Event::listen(\Illuminate\Queue\Events\JobProcessing::class, fn () => \Webkul\Admin\Support\Contact\TxnSettings::apply());

        // TXNHome calls this after saving a lead to the lead database
        // (LeadWebhookController). "api" group: no session, no CSRF; the
        // shared token is checked in the controller.
        Route::post('txn/lead-arrived', LeadWebhookController::class)
            ->middleware('api')
            ->name('txn.lead_arrived');

        Mail::extend('graph', fn () => new GraphTransport(
            config('graph_mail.tenant_id'),
            config('graph_mail.client_id'),
            config('graph_mail.client_secret'),
        ));

        // Lead check buttons, added through Krayin's view hooks so its lead
        // views stay unchanged.
        Event::listen('admin.leads.view.title.after', fn ($view) => $view->addTemplate('admin::leads.triage-button'));
        Event::listen('admin.leads.index.header.right.before', fn ($view) => $view->addTemplate('admin::leads.triage-new-button'));
        Event::listen('admin.leads.index.header.after', fn ($view) => $view->addTemplate('admin::leads.import-result'));

        // Automation engines (Support\Automation; all off until switched on in
        // Settings > TXN Settings > Automation). Fired by the lead check and by
        // booking syncs; a failure is logged there and never breaks them.
        Event::listen('txn.lead.checked', fn ($lead, $result) => app(\Webkul\Admin\Support\Automation\EmailEngine::class)->leadChecked($lead, $result));
        Event::listen('txn.booking.changed', fn ($event, $activity, $person, $lead, $summary, $isNew = false) => app(\Webkul\Admin\Support\Automation\EmailEngine::class)->bookingChanged($event, $activity, $person, $lead, $summary, (bool) $isNew));

        // Follow-up schedule: a lead that leaves Contacted (any stage move,
        // by staff, the board or automation) or is deleted loses its pending
        // follow-ups. Watched on the lead record itself (Eloquent "updated"),
        // so it doesn't depend on Krayin's lead.update.after listeners
        // letting the event through; the event is kept as a second trigger.
        $leftContacted = function ($lead) {
            try {
                if (! \Webkul\Admin\Support\Automation\MessageScheduler::ready() || ! is_object($lead) || ! $lead->id
                    || ! \Illuminate\Support\Facades\DB::table('txn_scheduled_messages')->where('lead_id', $lead->id)->where('status', 'pending')->exists()) {
                    return;
                }

                $fresh = \Webkul\Lead\Models\Lead::query()->with('stage')->find($lead->id);

                if ($fresh && app(\Webkul\Admin\Support\Contact\LeadStageService::class)->roleOf($fresh) !== \Webkul\Admin\Support\Contact\LeadStageService::CONTACTED) {
                    app(\Webkul\Admin\Support\Automation\MessageScheduler::class)->cancelForLead((int) $lead->id, 'The lead moved to '.($fresh->stage?->name ?? 'another stage'));
                }
            } catch (\Throwable $exception) {
                Log::warning('Follow-up schedule: stage-move check failed.', ['lead_id' => $lead->id ?? null, 'error' => $exception->getMessage()]);
            }
        };
        \Webkul\Lead\Models\Lead::updated(function ($lead) use ($leftContacted) {
            if ($lead->wasChanged('lead_pipeline_stage_id')) {
                $leftContacted($lead);
            }
        });
        \Webkul\Lead\Models\Lead::deleting(function ($lead) {
            try {
                if (\Webkul\Admin\Support\Automation\MessageScheduler::ready()) {
                    app(\Webkul\Admin\Support\Automation\MessageScheduler::class)->cancelForLead((int) $lead->id, 'The lead was deleted');
                }
            } catch (\Throwable $exception) {
                Log::warning('Follow-up schedule: lead-delete check failed.', ['error' => $exception->getMessage()]);
            }
        });
        Event::listen('lead.update.after', $leftContacted);
        Event::listen('lead.delete.before', function ($id) {
            try {
                if (\Webkul\Admin\Support\Automation\MessageScheduler::ready() && is_numeric($id)) {
                    app(\Webkul\Admin\Support\Automation\MessageScheduler::class)->cancelForLead((int) $id, 'The lead was deleted');
                }
            } catch (\Throwable $exception) {
                Log::warning('Follow-up schedule: lead-delete check failed.', ['error' => $exception->getMessage()]);
            }
        });

        // Yelp chat messages from Zapier (own switch, X-TXN-Token), saved in
        // the lead database's yelp_message table (YelpMessageController).
        Route::post('txn/yelp-message', \Webkul\Admin\Http\Controllers\Contact\YelpMessageController::class)
            ->middleware('api')
            ->name('txn.yelp_message');

        // Cal.com tells Krayin about bookings at once (own switch, signed with a secret).
        Route::post('txn/calcom-webhook', \Webkul\Admin\Http\Controllers\Contact\CalcomWebhookController::class)
            ->middleware('api')
            ->name('txn.calcom_webhook');

        // Pipeline events of this lead / contact (PipelineEvents, Leads > Event log).
        Event::listen('admin.leads.view.activities.after', fn ($view) => $view->addTemplate('admin::leads.yelp-chat-card'));
        Event::listen('admin.leads.view.activities.after', fn ($view) => $view->addTemplate('admin::leads.scheduled-card'));
        Event::listen('admin.leads.view.activities.after', fn ($view) => $view->addTemplate('admin::leads.events-card'));
        Event::listen('admin.contact.persons.view.right.after', fn ($view) => $view->addTemplate('admin::leads.events-card'));

        // "Yelp chat" button on Yelp leads and their contacts: opens the chat, even before any message.
        Event::listen('admin.leads.view.actions.before', fn ($view) => $view->addTemplate('admin::leads.yelp-chat-button'));
        Event::listen('admin.contact.persons.view.actions.before', fn ($view) => $view->addTemplate('admin::leads.yelp-chat-button'));

        // Re-run the lead check when the contact or lead is edited, so
        // "Missing Info" etc. follow the new phone/address. Lead edits count
        // only from the edit form and the inline fields: a stage change must
        // not re-check (it could undo a stage staff just set).
        Event::listen('contacts.person.update.after', fn ($person) => app(\Webkul\Admin\Support\Contact\LeadTriageService::class)->recheckAfterEdit($person));
        Event::listen('lead.update.after', function ($lead) {
            if (request()->routeIs('admin.leads.update', 'admin.leads.attributes.update')) {
                app(\Webkul\Admin\Support\Contact\LeadTriageService::class)->recheckAfterEdit($lead);
            }
        });

        // While the email guard is on (txn.guard_email: Settings > TXN Settings >
        // Safety, default TXN_TEST_MODE; missing = on) an email leaves only if
        // every recipient (to, cc, bcc) is in MAIL_ALLOWED_RECIPIENTS
        // (config/mail_guard.php). Anything else is not sent and is logged.
        // An empty list blocks every email.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $allowed = array_map('strtolower', config('mail_guard.allowed_recipients', []));
            $message = $event->message;
            $recipients = collect([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()])
                ->map(fn ($address) => strtolower($address->getAddress()));

            if (config('txn.guard_email', true)
                && ($recipients->isEmpty() || $recipients->contains(fn ($address) => ! in_array($address, $allowed, true)))) {
                Log::warning('Email not sent: a recipient is not in MAIL_ALLOWED_RECIPIENTS.', [
                    'to' => $recipients->all(),
                    'subject' => $message->getSubject(),
                ]);

                return false;
            }

            // Brevo turns text-only emails into HTML-only ones, which some
            // inboxes (e.g. Yelp) show as a PDF. Sending both a text and an
            // HTML version keeps them readable everywhere.
            self::addMissingBodyPart($message);
        });

        // An email actually left: the mail service works (clears its alert,
        // Support\Contact\ServiceHealth).
        Event::listen(\Illuminate\Mail\Events\MessageSent::class, fn () => \Webkul\Admin\Support\Contact\ServiceHealth::ok('mail'));
    }

    private static function addMissingBodyPart(\Symfony\Component\Mime\Email $message): void
    {
        $text = $message->getTextBody();
        $html = $message->getHtmlBody();
        $text = is_resource($text) ? stream_get_contents($text) : $text;
        $html = is_resource($html) ? stream_get_contents($html) : $html;

        if ($text !== null && $html === null) {
            $lines = array_map('trim', explode("\n", str_replace("\r\n", "\n", $text)));
            $escaped = e(implode("\n", $lines));
            $linked = preg_replace('~https?://[^\s<]+~', '<a href="$0">$0</a>', $escaped);
            $message->html('<div style="font-family:sans-serif;white-space:pre-line">'.$linked.'</div>');
        } elseif ($html !== null && $text === null) {
            $plain = preg_replace(['~<br\s*/?>~i', '~</(p|div|tr|h[1-6]|li)>~i'], "\n", $html);
            $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $message->text(trim(preg_replace("~\n{3,}~", "\n\n", $plain)));
        }
    }
}
