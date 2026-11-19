{{-- Weekly hours, one row per day, with a live label per day. Used by the worker form and the company schedule.
     Data: $field (input name, e.g. "work_hours"), $weekdays (day => name), $hours (day => [start, end]),
     $allBlankLabel (label when every day is blank: "Company hours" for a worker, "Day off" for the company). --}}
<div class="tc-days" data-week data-all-blank="{{ $allBlankLabel }}">
    @foreach ($weekdays as $day => $dayName)
        @php
            [$start, $end] = $hours[$day] ?? ['', ''];
        @endphp
        <div class="tc-dayrow" data-day>
            <strong>{{ $dayName }}</strong>
            <input type="time" name="{{ $field }}[{{ $day }}][start]" value="{{ $start }}" class="tc-input" aria-label="{{ $dayName }} start">
            <span class="tc-arrow">to</span>
            <input type="time" name="{{ $field }}[{{ $day }}][end]" value="{{ $end }}" class="tc-input" aria-label="{{ $dayName }} end">
            <span class="tc-status" data-day-label></span>
        </div>
        @error("{$field}.{$day}.start") <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $message }}</p> @enderror
    @endforeach
</div>

@pushOnce('scripts')
    <script>
        (() => {
            const clock = (value) => {
                const [h, m] = value.split(':').map(Number);
                return `${(h % 12) || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
            };

            const update = (week) => {
                const rows = [...week.querySelectorAll('[data-day]')];
                const anyFilled = rows.some((row) => [...row.querySelectorAll('input')].some((input) => input.value));

                rows.forEach((row) => {
                    const [start, end] = [...row.querySelectorAll('input')].map((input) => input.value);
                    const label = row.querySelector('[data-day-label]');
                    let text = '';
                    let kind = 'is-off';

                    if (start && end) {
                        text = `${clock(start)} – ${clock(end)}`;
                        kind = start < end ? 'is-on' : 'is-warn';
                        text = start < end ? text : 'End is before start';
                    } else if (start || end) {
                        text = 'Needs a start and an end';
                        kind = 'is-warn';
                    } else {
                        text = anyFilled ? 'Day off' : week.dataset.allBlank;
                    }

                    label.className = `tc-status ${kind}`;
                    label.textContent = text;
                    row.classList.toggle('is-off', kind === 'is-off');
                });
            };

            document.querySelectorAll('[data-week]').forEach((week) => {
                week.addEventListener('input', () => update(week));
                update(week);
            });
        })();
    </script>
@endPushOnce
