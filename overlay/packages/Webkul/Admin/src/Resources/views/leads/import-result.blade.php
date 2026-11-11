{{-- Result of "Import leads (CSV)", above the leads list (hook admin.leads.index.header.after,
     AppServiceProvider). Shown once, on the page the import returns to. v-pre: file names and CSV
     text are never read as Vue template code. Inline styles: Krayin's compiled CSS has no new classes. --}}
@if ($import = session('lead_import'))
    @php
        $failed = ! empty($import['errors']);
        $plural = fn (int $count, string $word) => $count.' '.\Illuminate\Support\Str::plural($word, $count);

        if ($failed && ! empty($import['stopped'])) {
            $summary = 'the import stopped and nothing was saved. Details are in laravel.log.';
        } elseif ($failed) {
            $summary = 'nothing was imported. Fix these rows and choose the file again.';
        } else {
            $summary = $plural($import['rows'], 'row').': '.$plural($import['created'], 'new lead').', '
                .$import['updated'].' updated, '.$import['unchanged'].' unchanged'
                .($import['duplicates'] ? ', '.$plural($import['duplicates'], 'repeated row').' skipped' : '').'.';
        }
    @endphp

    <div v-pre style="margin-top: 12px; padding: 12px 16px; border-radius: 6px; font-size: 14px; line-height: 1.5;
        border: 1px solid {{ $failed ? '#FCA5A5' : '#86EFAC' }}; background: {{ $failed ? '#FEF2F2' : '#F0FDF4' }}; color: #1F2937;">
        <strong>{{ $import['file'] }}</strong>: {{ $summary }}

        @if ($failed)
            <ul style="margin: 6px 0 0 18px; list-style: disc;">
                @foreach (array_slice($import['errors'], 0, 20) as $error)
                    <li>{{ $error }}</li>
                @endforeach

                @if (count($import['errors']) > 20)
                    <li>… and {{ count($import['errors']) - 20 }} more.</li>
                @endif
            </ul>
        @elseif ($import['checked'] || $import['check_failed'] || ! empty($import['check_left']))
            <br>
            Lead check ran on {{ $plural($import['checked'], 'new or changed lead') }}: {{ $import['review'] }} need review.

            @if ($import['check_failed'])
                {{ $import['check_failed'] }} could not be checked (see laravel.log); use "Check new leads" to retry.
            @endif

            @if (! empty($import['check_left']))
                <br>
                <strong>{{ $plural($import['check_left'], 'lead') }} not checked yet</strong> (time limit): they are in Raw Lead. Press "Check new leads" until none are left.
            @endif
        @endif
    </div>
@endif
