{{-- Copies Cal.com bookings into Krayin now (CalcomSyncController). --}}
@if (bouncer()->hasPermission('contacts.persons.view'))
    <form method="POST" action="{{ route('admin.contacts.calcom_sync') }}">
        @csrf

        <button type="submit" class="secondary-button" title="Copy new, moved and cancelled Cal.com bookings into Krayin">
            Sync Cal.com bookings
        </button>
    </form>
@endif
