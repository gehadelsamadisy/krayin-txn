{{-- Settings > TXN Settings > Follow-ups (TxnSettingsController). Styles: partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $hours = [
            'reminder_after_hours' => 'After the booking link was emailed, if the customer hasn\'t booked: a reminder with the times still open.',
            'final_after_hours' => 'After the booking link: a last "if you still need help, call or text us" email.',
            'lost_after_hours' => 'After the booking link: the lead is marked Lost (only leads still in Contacted).',
            'min_hours_before_slot' => 'The reminder leaves out times sooner than this, so the customer has time to book them.',
        ];
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <form method="POST" action="{{ route('admin.settings.txn.follow_ups.update') }}" class="tc tc-form" id="follow-ups-form">
            @csrf
            @method('PUT')

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'clock', 'title' => 'What happens after a booking link', 'text' => 'Counted from the moment the link was emailed, while the customer hasn\'t booked.'])
                <div class="tc-body">
                    <div class="tc-steps" aria-hidden="true">
                        <div class="tc-step"><span class="tc-step-dot">0h</span><strong>Link sent</strong><span>booking link email</span></div>
                        <div class="tc-step"><span class="tc-step-dot" data-step="reminder_after_hours"></span><strong>Reminder</strong><span>times still open</span></div>
                        <div class="tc-step"><span class="tc-step-dot" data-step="final_after_hours"></span><strong>Final email</strong><span>"call or text us"</span></div>
                        <div class="tc-step is-end"><span class="tc-step-dot" data-step="lost_after_hours"></span><strong>Lost</strong><span>lead closed</span></div>
                    </div>

                    <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15]) Follow-ups are sent when someone runs them (the follow-ups command); nothing is sent automatically yet.</p>

                    <div class="tc-grid">
                        @foreach ($hours as $name => $help)
                            @php $key = "follow_ups.{$name}"; @endphp
                            <div class="tc-field">
                                <label for="f-{{ $name }}" class="tc-label">{{ $fields[$key][2] }}</label>
                                <div class="tc-unit">
                                    <input id="f-{{ $name }}" type="number" name="{{ $name }}" min="{{ $fields[$key][3]['min'] }}" max="{{ $fields[$key][3]['max'] }}" value="{{ old($name, $values[$key]) }}" class="tc-input" data-hours="{{ $name }}" @disabled(! $canEdit)>
                                    <span>hours</span>
                                </div>
                                <p class="tc-help">{{ $help }}</p>
                                @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'tag', 'title' => 'When a lead is marked Lost', 'text' => 'Written on the lead when the follow-ups run out.'])
                <div class="tc-body">
                    <div class="tc-field">
                        <label for="f-lost_reason" class="tc-label">{{ $fields['follow_ups.lost_reason'][2] }}</label>
                        <input id="f-lost_reason" type="text" name="lost_reason" maxlength="200" value="{{ old('lost_reason', $values['follow_ups.lost_reason']) }}" class="tc-input" @disabled(! $canEdit)>
                        @include('admin::settings.txn.partials.field-meta', ['key' => 'follow_ups.lost_reason'])
                    </div>
                </div>
            </section>

            @include('admin::settings.txn.partials.savebar')
        </form>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        <script>
            (() => {
                const form = document.getElementById('follow-ups-form');
                const update = () => form.querySelectorAll('[data-step]').forEach((dot) => {
                    const hours = form.querySelector(`[data-hours="${dot.dataset.step}"]`)?.value || '?';
                    dot.textContent = hours >= 48 && hours % 24 === 0 ? `${hours / 24}d` : `${hours}h`;
                });

                form.addEventListener('input', update);
                update();
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
