<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Contact\CalcomSyncController;
use Webkul\Admin\Http\Controllers\Contact\OrganizationController;
use Webkul\Admin\Http\Controllers\Contact\Persons\ActivityController;
use Webkul\Admin\Http\Controllers\Contact\Persons\PersonController;
use Webkul\Admin\Http\Controllers\Contact\Persons\TagController;
use Webkul\Admin\Http\Controllers\Contact\WorkerController;

Route::prefix('contacts')->group(function () {
    Route::post('calcom-sync', [CalcomSyncController::class, 'sync'])->name('admin.contacts.calcom_sync');

    /**
     * Persons routes.
     */
    Route::controller(PersonController::class)->prefix('persons')->group(function () {
        Route::get('', 'index')->name('admin.contacts.persons.index');

        Route::put('columns', 'updateColumns')->name('admin.contacts.persons.columns.update');

        Route::get('create', 'create')->name('admin.contacts.persons.create');

        Route::post('create', 'store')->name('admin.contacts.persons.store');

        Route::get('view/{id}', 'show')->name('admin.contacts.persons.view');

        Route::get('{id}/booking-link', 'createBookingLink')->name('admin.contacts.persons.booking_link.create');

        Route::post('{id}/booking-link/create-link', 'createBookingLinkOnly')->name('admin.contacts.persons.booking_link.create_link');

        Route::post('{id}/booking-link', 'sendBookingLink')->name('admin.contacts.persons.booking_link.send');

        Route::get('{id}/booking-link/suggest', 'suggestBookingSlots')->name('admin.contacts.persons.booking_link.suggest');

        Route::get('edit/{id}', 'edit')->name('admin.contacts.persons.edit');

        Route::put('edit/{id}', 'update')->name('admin.contacts.persons.update');

        Route::get('search', 'search')->name('admin.contacts.persons.search');

        Route::middleware(['throttle:100,60'])->delete('{id}', 'destroy')->name('admin.contacts.persons.delete');

        Route::post('mass-destroy', 'massDestroy')->name('admin.contacts.persons.mass_delete');

        /**
         * Tag routes.
         */
        Route::controller(TagController::class)->prefix('{id}/tags')->group(function () {
            Route::post('', 'attach')->name('admin.contacts.persons.tags.attach');

            Route::delete('', 'detach')->name('admin.contacts.persons.tags.detach');
        });

        /**
         * Activity routes.
         */
        Route::controller(ActivityController::class)->prefix('{id}/activities')->group(function () {
            Route::get('', 'index')->name('admin.contacts.persons.activities.index');
        });
    });

    /**
     * Organization routes.
     */
    Route::controller(OrganizationController::class)->prefix('organizations')->group(function () {
        Route::get('', 'index')->name('admin.contacts.organizations.index');

        Route::get('create', 'create')->name('admin.contacts.organizations.create');

        Route::post('create', 'store')->name('admin.contacts.organizations.store');

        Route::get('edit/{id?}', 'edit')->name('admin.contacts.organizations.edit');

        Route::put('edit/{id}', 'update')->name('admin.contacts.organizations.update');

        Route::delete('{id}', 'destroy')->name('admin.contacts.organizations.delete');

        Route::put('mass-destroy', 'massDestroy')->name('admin.contacts.organizations.mass_delete');
    });

    Route::controller(WorkerController::class)->prefix('workers')->group(function () {
        Route::get('', 'index')->name('admin.contacts.workers.index');

        Route::get('create', 'create')->name('admin.contacts.workers.create');

        Route::get('routes', 'routes')->name('admin.contacts.workers.routes');

        Route::post('', 'store')->name('admin.contacts.workers.store');

        Route::get('{worker}/bookings/create', 'createBooking')->name('admin.contacts.workers.bookings.create');

        Route::post('{worker}/bookings', 'storeBooking')->name('admin.contacts.workers.bookings.store');

        Route::get('{worker}/edit', 'edit')->name('admin.contacts.workers.edit');

        Route::get('{worker}/schedule', 'schedule')->name('admin.contacts.workers.schedule');

        Route::get('{worker}/time-off/{timeOff}', 'editTimeOff')->scopeBindings()->name('admin.contacts.workers.time_off.edit');

        Route::put('{worker}/time-off/{timeOff}', 'updateTimeOff')->scopeBindings()->name('admin.contacts.workers.time_off.update');

        Route::delete('{worker}/time-off/{timeOff}', 'destroyTimeOff')->scopeBindings()->name('admin.contacts.workers.time_off.delete');

        Route::get('settings', 'settings')->name('admin.contacts.workers.settings');

        Route::put('settings', 'updateSettings')->name('admin.contacts.workers.settings.update');

        Route::put('{worker}', 'update')->name('admin.contacts.workers.update');
    });
});
