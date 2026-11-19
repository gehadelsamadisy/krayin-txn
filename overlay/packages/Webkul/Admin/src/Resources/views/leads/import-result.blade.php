{{-- Result of "Import leads (CSV)", above the leads list (hook admin.leads.index.header.after,
     AppServiceProvider). Shown once, on the page the import returns to. v-pre: file names and CSV
     text are never read as Vue template code. Styles: settings/txn/partials/tc-styles (.tc, scoped). --}}
@if ($import = session('lead_import'))
    @php
        $failed = ! empty($import['errors']);
        $plural = fn (int $count, string $word) => $count.' '.\Illuminate\Support\Str::plural($word, $count);
        $checkProblem = $import['check_failed'] || ! empty($import['check_left']);
    @endphp

    @include('admin::settings.txn.partials.tc-styles')

    <div v-pre class="tc tc-box {{ $failed ? 'is-bad' : ($checkProblem ? 'is-warn' : 'is-ok') }}" style="margin-top: 12px;" role="status">
        @include('admin::settings.txn.partials.icon', ['name' => $failed ? 'alert' : 'upload'])
        <div style="flex: 1 1 auto; min-width: 0;">
            <strong>{{ $import['file'] }}</strong>

            @if ($failed)
                <div>{{ ! empty($import['stopped']) ? 'The import stopped and nothing was saved. Details are in laravel.log.' : 'Nothing was imported. Fix these rows and choose the file again.' }}</div>
                <ul>
                    @foreach (array_slice($import['errors'], 0, 20) as $error)
                        <li>{{ $error }}</li>
                    @endforeach

                    @if (count($import['errors']) > 20)
                        <li>… and {{ count($import['errors']) - 20 }} more.</li>
                    @endif
                </ul>
            @else
                <div class="tc-chips" style="margin-top: 6px;">
                    <span class="tc-chip is-muted">{{ $plural($import['rows'], 'row') }}</span>
                    <span class="tc-chip">{{ $plural($import['created'], 'new lead') }}</span>
                    <span class="tc-chip is-muted">{{ $import['updated'] }} updated</span>
                    <span class="tc-chip is-muted">{{ $import['unchanged'] }} unchanged</span>
                    @if ($import['duplicates'])
                        <span class="tc-chip is-muted">{{ $plural($import['duplicates'], 'repeated row') }} skipped</span>
                    @endif
                </div>

                @if ($import['checked'] || $checkProblem)
                    <div style="margin-top: 6px;">
                        Lead check ran on {{ $plural($import['checked'], 'new or changed lead') }}: <strong style="display: inline;">{{ $import['review'] }} need review</strong>.

                        @if ($import['check_failed'])
                            {{ $import['check_failed'] }} could not be checked (see laravel.log); use "Check new leads" to retry.
                        @endif

                        @if (! empty($import['check_left']))
                            <br>
                            <strong style="display: inline;">{{ $plural($import['check_left'], 'lead') }} not checked yet</strong> (time limit): they are in Raw Lead. Press "Check new leads" until none are left.
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </div>
@endif
