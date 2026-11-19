{{-- TXN Settings screens: change log (txn_settings_log) as a timeline. Data: $log. --}}
<section class="tc tc-card is-plain">
    @include('admin::settings.txn.partials.card-head', [
        'icon' => 'history',
        'title' => 'Change log',
        'text' => $log->isEmpty() ? 'No changes yet: everything here is the default.' : 'Latest '.$log->count().' changes on this page, newest first.',
    ])

    @if ($log->isNotEmpty())
        <div class="tc-body">
            <ol class="tc-timeline">
                @foreach ($log as $entry)
                    <li>
                        <span class="tc-tl-dot"></span>
                        <div>
                            <div class="tc-tl-head">
                                <strong>{{ $entry->item }}</strong>
                                <span class="tc-tl-when">{{ \Carbon\Carbon::parse($entry->created_at)->format('M j, Y · g:i A') }} · {{ $entry->user ?? 'System' }}</span>
                            </div>
                            <div class="tc-diff">
                                <span class="tc-diff-old">{{ $entry->old_value }}</span>
                                <span aria-hidden="true">→</span>
                                <span class="tc-diff-new">{{ $entry->new_value }}</span>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</section>
