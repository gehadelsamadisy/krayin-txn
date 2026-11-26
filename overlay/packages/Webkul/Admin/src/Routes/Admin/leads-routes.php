<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Lead\ActivityController;
use Webkul\Admin\Http\Controllers\Lead\EmailController;
use Webkul\Admin\Http\Controllers\Lead\LeadController;
use Webkul\Admin\Http\Controllers\Lead\LeadTriageController;
use Webkul\Admin\Http\Controllers\Lead\QuoteController;
use Webkul\Admin\Http\Controllers\Lead\TagController;
use Webkul\Admin\Http\Controllers\Settings\TxnSettingsController;
use Webkul\Admin\Http\Controllers\Settings\TxnStageRulesController;

// Lead check (LeadTriageService), CSV import (LeadCsvImportService) and
// import from the lead database (LeadDbImportService).
Route::controller(LeadTriageController::class)->prefix('leads')->group(function () {
    Route::post('check-new', 'checkNew')->name('admin.leads.triage_new');

    Route::post('import-csv', 'import')->name('admin.leads.import_csv');

    Route::post('import-db', 'importDb')->name('admin.leads.import_db');

    Route::post('{id}/check', 'check')->name('admin.leads.triage');
});

// Leads > Event log: every pipeline step, by staff or automation (PipelineEvents).
Route::controller(\Webkul\Admin\Http\Controllers\Lead\EventLogController::class)->prefix('leads/event-log')->group(function () {
    Route::get('', 'index')->name('admin.leads.event_log');

    Route::get('export', 'export')->name('admin.leads.event_log.export');
});

// Leads > Scheduled messages: the follow-up schedule (MessageScheduler).
Route::controller(\Webkul\Admin\Http\Controllers\Lead\ScheduledMessagesController::class)->prefix('leads/scheduled')->group(function () {
    Route::get('', 'index')->name('admin.leads.scheduled');

    Route::post('{id}/cancel', 'cancel')->name('admin.leads.scheduled.cancel');

    Route::post('{id}/send-now', 'sendNow')->name('admin.leads.scheduled.send_now');

    Route::post('{id}/retime', 'retime')->name('admin.leads.scheduled.retime');
});

// Leads > Waiting for booking: leads in Contacted, link sent, not booked yet.
Route::controller(\Webkul\Admin\Http\Controllers\Lead\WaitingController::class)->prefix('leads/waiting')->group(function () {
    Route::get('', 'index')->name('admin.leads.waiting');

    Route::post('{followUpId}/stop', 'stop')->name('admin.leads.waiting.stop');
});

// Leads > Yelp messages: Yelp chats saved from Zapier (lead database, yelp_message).
Route::get('leads/yelp-messages', [\Webkul\Admin\Http\Controllers\Lead\YelpMessagesController::class, 'index'])->name('admin.leads.yelp_messages');
// A reply typed under a Yelp chat (YelpReplySender -> the reply Zap).
Route::post('leads/yelp-messages/{chat}/reply', [\Webkul\Admin\Http\Controllers\Lead\YelpMessagesController::class, 'reply'])->name('admin.leads.yelp_messages.reply');

// Leads > SMS messages: texts with customers (txn_sms_messages, Support\Replies\SmsInbox).
Route::get('leads/sms-messages', [\Webkul\Admin\Http\Controllers\Lead\SmsMessagesController::class, 'index'])->name('admin.leads.sms_messages');
// A reply typed under a conversation (SmsSender; the SMS guard applies).
Route::post('leads/sms-messages/reply', [\Webkul\Admin\Http\Controllers\Lead\SmsMessagesController::class, 'reply'])->name('admin.leads.sms_messages.reply');

// Settings > TXN Settings > Stage rules: which stage each automatic move uses.
Route::controller(TxnStageRulesController::class)->prefix('settings/txn/stage-rules')->group(function () {
    Route::get('', 'index')->name('admin.settings.txn.stage_rules');

    Route::put('', 'update')->name('admin.settings.txn.stage_rules.update');
});

// Settings > TXN Settings: Company & sender, Services, Service area &
// scheduling, Follow-ups, Emails, Connections, Safety. One route pair per
// screen, so each has its own permission (acl.php); the screen is passed to
// the controller.
foreach (['company', 'services', 'area', 'follow-ups', 'emails', 'connections', 'safety', 'automation'] as $txnScreen) {
    $txnName = 'admin.settings.txn.'.str_replace('-', '_', $txnScreen);

    Route::get("settings/txn/{$txnScreen}", [TxnSettingsController::class, 'show'])->defaults('screen', $txnScreen)->name($txnName);

    Route::put("settings/txn/{$txnScreen}", [TxnSettingsController::class, 'update'])->defaults('screen', $txnScreen)->name("{$txnName}.update");
}

// Connections: Test buttons (log in only; no SMS or email). Permission
// settings.txn.connections.edit is checked in the controller.
Route::post('settings/txn/connections/test', [TxnSettingsController::class, 'testConnection'])->name('admin.settings.txn.connections.test');

// Settings > TXN Settings > Mailboxes: mailboxes Krayin reads customer
// replies from, each with its own Microsoft 365 login (Support\Replies).
Route::controller(\Webkul\Admin\Http\Controllers\Settings\TxnMailboxesController::class)->prefix('settings/txn/mailboxes')->group(function () {
    Route::get('', 'index')->name('admin.settings.txn.mailboxes');

    Route::post('', 'store')->name('admin.settings.txn.mailboxes.store');

    Route::put('{id}', 'update')->whereNumber('id')->name('admin.settings.txn.mailboxes.update');

    Route::delete('{id}', 'destroy')->whereNumber('id')->name('admin.settings.txn.mailboxes.delete');

    Route::post('{id}/test', 'test')->whereNumber('id')->name('admin.settings.txn.mailboxes.test');

    Route::post('{id}/check', 'check')->whereNumber('id')->name('admin.settings.txn.mailboxes.check');
});

Route::controller(LeadController::class)->prefix('leads')->group(function () {
    Route::get('', 'index')->name('admin.leads.index');

    Route::get('create', 'create')->name('admin.leads.create');

    Route::post('create', 'store')->name('admin.leads.store');

    Route::post('create-by-ai', 'createByAI')->name('admin.leads.create_by_ai');

    Route::get('view/{id}', 'view')->name('admin.leads.view');

    Route::get('edit/{id}', 'edit')->name('admin.leads.edit');

    Route::put('edit/{id}', 'update')->name('admin.leads.update');

    Route::put('attributes/edit/{id}', 'updateAttributes')->name('admin.leads.attributes.update');

    Route::put('stage/edit/{id}', 'updateStage')->name('admin.leads.stage.update');

    Route::get('search', 'search')->name('admin.leads.search');

    Route::delete('{id}', 'destroy')->name('admin.leads.delete');

    Route::post('mass-update', 'massUpdate')->name('admin.leads.mass_update');

    Route::post('mass-destroy', 'massDestroy')->name('admin.leads.mass_delete');

    Route::get('get/{pipeline_id?}', 'get')->name('admin.leads.get');

    Route::delete('product/{lead_id}', 'removeProduct')->name('admin.leads.product.remove');

    Route::put('product/{lead_id}', 'addProduct')->name('admin.leads.product.add');

    Route::get('kanban/look-up', [LeadController::class, 'kanbanLookup'])->name('admin.leads.kanban.look_up');

    Route::controller(ActivityController::class)->prefix('{id}/activities')->group(function () {
        Route::get('', 'index')->name('admin.leads.activities.index');
    });

    Route::controller(TagController::class)->prefix('{id}/tags')->group(function () {
        Route::post('', 'attach')->name('admin.leads.tags.attach');

        Route::delete('', 'detach')->name('admin.leads.tags.detach');
    });

    Route::controller(EmailController::class)->prefix('{id}/emails')->group(function () {
        Route::post('', 'store')->name('admin.leads.emails.store');

        Route::delete('', 'detach')->name('admin.leads.emails.detach');
    });

    Route::controller(QuoteController::class)->prefix('{id}/quotes')->group(function () {
        Route::delete('{quote_id?}', 'delete')->name('admin.leads.quotes.delete');
    });
});
