{{-- Contacts > Workers > Company schedule and holidays (WorkerController). Styles: settings/txn/partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>
        Company schedule and holidays
    </x-slot>

    @php
        $hours = collect($weekdays)->mapWithKeys(fn ($dayName, $day) => [$day => [
            old("default_work_hours.{$day}.start", $settings->default_work_hours[$day][0] ?? ''),
            old("default_work_hours.{$day}.end", $settings->default_work_hours[$day][1] ?? ''),
        ]])->all();
        $holidays = old('holidays', $settings->holidays ?? []);
    @endphp

    @include('admin::settings.txn.partials.tc-styles')

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'calendar', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Workers</span>
                <h1>Company schedule and holidays</h1>
                <p>The hours used for every worker without hours of their own, and the days nobody works.</p>
            </div>
            <a href="{{ route('admin.contacts.workers.index') }}" class="secondary-button">Back to workers</a>
        </div>

        <form method="POST" action="{{ route('admin.contacts.workers.settings.update') }}" class="tc tc-form">
            @csrf
            @method('PUT')

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'clock', 'title' => 'Default weekly hours', 'text' => 'A day with both times blank is a day off. A time is only offered if the whole visit fits inside these hours.'])
                <div class="tc-body">
                    @include('admin::settings.txn.partials.week-hours', ['field' => 'default_work_hours', 'hours' => $hours, 'allBlankLabel' => 'Day off'])
                </div>
            </section>

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'beach',
                    'title' => 'Company holidays',
                    'text' => 'These dates apply to all workers: no visits are suggested.',
                    'aside' => '<button id="add-holiday" type="button" class="tc-add">+ Add holiday</button>',
                ])
                <div class="tc-body">
                    @error('holidays') <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $message }}</p> @enderror
                    <p class="tc-empty" id="holiday-empty" @if ($holidays) style="display: none;" @endif>No company holidays yet.</p>

                    <div id="holiday-list" class="tc-rows">
                        @foreach ($holidays as $index => $holiday)
                            <div class="holiday-entry tc-entry is-holiday">
                                <div class="tc-field"><label class="tc-label">Date</label><input type="date" name="holidays[{{ $index }}][date]" value="{{ $holiday['date'] ?? '' }}" class="tc-input"></div>
                                <div class="tc-field"><label class="tc-label">Name</label><input type="text" name="holidays[{{ $index }}][name]" maxlength="255" value="{{ $holiday['name'] ?? '' }}" placeholder="Company holiday" class="tc-input"></div>
                                <button type="button" class="remove-holiday tc-btn is-danger">Remove</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            @include('admin::settings.txn.partials.savebar', ['noReset' => true, 'saveLabel' => 'Save company schedule', 'cancelUrl' => route('admin.contacts.workers.index'), 'idleText' => 'No changes yet'])
        </form>
    </div>

    <template id="holiday-template">
        <div class="holiday-entry tc-entry is-holiday">
            <div class="tc-field"><label class="tc-label">Date</label><input type="date" data-field="date" class="tc-input"></div>
            <div class="tc-field"><label class="tc-label">Name</label><input type="text" data-field="name" maxlength="255" placeholder="Company holiday" class="tc-input"></div>
            <button type="button" class="remove-holiday tc-btn is-danger">Remove</button>
        </div>
    </template>

    @pushOnce('scripts')
        <script>
            (() => {
                const list = document.getElementById('holiday-list');
                const template = document.getElementById('holiday-template');
                const empty = document.getElementById('holiday-empty');
                const showEmpty = () => { empty.style.display = list.children.length ? 'none' : ''; };
                let nextIndex = {{ count($holidays) ? max(array_keys($holidays)) + 1 : 0 }};

                document.getElementById('add-holiday').addEventListener('click', () => {
                    const entry = template.content.firstElementChild.cloneNode(true);
                    entry.querySelectorAll('[data-field]').forEach((input) => {
                        input.name = `holidays[${nextIndex}][${input.dataset.field}]`;
                    });
                    nextIndex += 1;
                    list.appendChild(entry);
                    showEmpty();
                    entry.querySelector('input').focus();
                });

                list.addEventListener('click', (event) => {
                    if (event.target.closest('.remove-holiday')) {
                        event.target.closest('.holiday-entry').remove();
                        showEmpty();
                    }
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
