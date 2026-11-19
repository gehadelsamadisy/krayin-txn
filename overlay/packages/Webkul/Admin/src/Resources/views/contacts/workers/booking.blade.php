{{-- Contacts > Workers > Schedule > Book worker (WorkerController). Styles: settings/txn/partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>
        Book {{ $worker->name }}
    </x-slot>

    @php
        $scheduleUrl = route('admin.contacts.workers.schedule', ['worker' => $worker->id, 'date' => $date->toDateString()]);
        $startValue = old('schedule_from', $date->setTime(9, 0)->format('Y-m-d\TH:i'));
        $endValue = old('schedule_to', $date->setTime(10, 0)->format('Y-m-d\TH:i'));
        $bookingType = old('booking_type', 'work');
        $initials = collect(preg_split('/\s+/', trim((string) $worker->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '?';
        $fieldError = fn (string $key) => $errors->first($key);
    @endphp

    @include('admin::settings.txn.partials.tc-styles')

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-avatar is-lg" style="{{ $worker->calendar_color ? "background: {$worker->calendar_color};" : '' }}">{{ $initials }}</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Book worker</span>
                <h1>{{ $worker->name }}</h1>
                <p>Plan a work visit (it goes into the worker's route) or another booking that only blocks the time.</p>
            </div>
            <a href="{{ $scheduleUrl }}" class="secondary-button">Back to schedule</a>
        </div>

        <form method="POST" action="{{ route('admin.contacts.workers.bookings.store', $worker->id) }}" class="tc tc-form">
            @csrf

            @if ($fieldError('worker'))
                <div class="tc-alert" role="alert">@include('admin::settings.txn.partials.icon', ['name' => 'alert'])<div>{{ $fieldError('worker') }}</div></div>
            @endif

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'wrench', 'title' => 'What', 'text' => 'What the worker needs to do, and whether it is part of their route.'])
                <div class="tc-body">
                    <div class="tc-grid">
                        <div class="tc-field">
                            <label for="title" class="tc-label">What does the worker need to do?</label>
                            <input id="title" name="title" type="text" maxlength="255" value="{{ old('title') }}" required class="tc-input" placeholder="e.g. Pick up materials">
                            @if ($fieldError('title')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $fieldError('title') }}</p> @endif
                        </div>

                        <div class="tc-field">
                            <label for="booking_type" class="tc-label">Booking type</label>
                            <select id="booking_type" name="booking_type" required class="tc-input">
                                <option value="work" @selected($bookingType === 'work')>Work-related visit (include in route)</option>
                                <option value="other" @selected($bookingType === 'other')>Other / personal booking</option>
                            </select>
                            @if ($fieldError('booking_type')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $fieldError('booking_type') }}</p> @endif
                        </div>

                        <div class="tc-field tc-wide" id="work-location-group">
                            <label for="location" class="tc-label">Location <span class="tc-sublabel" id="location-requirement">(required for work route)</span></label>
                            <input id="location" name="location" type="text" maxlength="255" value="{{ old('location') }}" @required($bookingType === 'work') class="tc-input" placeholder="Street address, city">
                            @if ($fieldError('location')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $fieldError('location') }}</p> @endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'calendar', 'title' => 'When', 'text' => 'Refused if the worker already has something booked or time off then.'])
                <div class="tc-body">
                    <div class="tc-grid">
                        <div class="tc-field">
                            <label for="schedule_from" class="tc-label">Start</label>
                            <input id="schedule_from" name="schedule_from" type="datetime-local" value="{{ $startValue }}" required class="tc-input">
                            @if ($fieldError('schedule_from')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $fieldError('schedule_from') }}</p> @endif
                        </div>

                        <div class="tc-field">
                            <label for="schedule_to" class="tc-label">End</label>
                            <input id="schedule_to" name="schedule_to" type="datetime-local" value="{{ $endValue }}" required class="tc-input">
                            @if ($fieldError('schedule_to')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $fieldError('schedule_to') }}</p> @endif
                        </div>

                        <div class="tc-field tc-wide">
                            <label for="comment" class="tc-label">Notes <span class="tc-sublabel">(optional)</span></label>
                            <textarea id="comment" name="comment" rows="4" maxlength="2000" class="tc-input">{{ old('comment') }}</textarea>
                            @if ($fieldError('comment')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $fieldError('comment') }}</p> @endif
                        </div>
                    </div>
                </div>
            </section>

            @include('admin::settings.txn.partials.savebar', ['noReset' => true, 'saveLabel' => 'Book worker', 'cancelUrl' => $scheduleUrl, 'idleText' => 'Nothing is booked until you press Book worker'])
        </form>
    </div>

    @pushOnce('scripts')
        <script>
            (() => {
                const updateLocationRequirement = () => {
                    const bookingType = document.getElementById('booking_type');
                    const location = document.getElementById('location');
                    const requirement = document.getElementById('location-requirement');

                    if (!bookingType || !location || !requirement) {
                        return;
                    }

                    const isWorkVisit = bookingType.value === 'work';
                    location.required = isWorkVisit;
                    requirement.textContent = isWorkVisit
                        ? '(required; included in route)'
                        : '(optional; not included in route)';
                };

                document.addEventListener('change', (event) => {
                    if (event.target instanceof Element && event.target.closest('#booking_type')) {
                        updateLocationRequirement();
                    }
                });

                updateLocationRequirement();
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
