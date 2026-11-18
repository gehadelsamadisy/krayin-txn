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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
