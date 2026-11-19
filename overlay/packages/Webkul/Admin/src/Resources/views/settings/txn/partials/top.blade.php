{{-- TXN Settings screens: header (icon, title, description) and save errors. Data: $title, $info, $screen. Brings the shared styles (.tc). --}}
@include('admin::settings.txn.partials.tc-styles')

@php
    $screenIcons = ['company' => 'building', 'services' => 'list', 'area' => 'map', 'follow-ups' => 'bell', 'emails' => 'mail', 'connections' => 'key', 'safety' => 'shield'];
@endphp

<div class="tc tc-top">
    <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => $screenIcons[$screen ?? ''] ?? 'sliders', 'size' => 22])</span>
    <div class="tc-top-text">
        <span class="tc-crumb">TXN Settings</span>
        <h1>{{ $title }}</h1>
        <p>{{ $info }} Empty or invalid values are never saved; a setting you reset goes back to its default.</p>
    </div>
    <a href="{{ route('admin.settings.index') }}" class="secondary-button">Back to settings</a>
</div>

@if ($errors->has('settings'))
    <div class="tc tc-alert" role="alert">
        @include('admin::settings.txn.partials.icon', ['name' => 'alert'])
        <div>
            <strong>Not saved</strong>
            @foreach ($errors->get('settings') as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    </div>
@endif
