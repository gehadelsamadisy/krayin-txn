{{-- Heading of a settings card. Data: $icon, $title, optional $text, optional $aside (HTML on the right). --}}
<div class="tc-head">
    <span class="tc-mark">@include('admin::settings.txn.partials.icon', ['name' => $icon])</span>
    <div class="tc-title">
        <h2>{{ $title }}</h2>
        @if (! empty($text))
            <p>{{ $text }}</p>
        @endif
    </div>
    @if (! empty($aside))
        {!! $aside !!}
    @endif
</div>
