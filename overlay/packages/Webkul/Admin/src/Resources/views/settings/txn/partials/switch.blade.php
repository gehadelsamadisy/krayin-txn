{{-- One on/off setting as a switch row (hidden "0" + checkbox "1"). Data: $key, $inputNames, $values, $fields, $canEdit, optional $help, $switchLabel, $plain (default true: blue/grey instead of green/red). --}}
@php
    $name = $inputNames[$key];
    $on = old($name, ! empty($values[$key]) ? '1' : '0') === '1';
@endphp
<div class="tc-field" style="flex-direction: row; align-items: flex-start; justify-content: space-between; gap: 14px;">
    <div style="min-width: 0;">
        <span class="tc-label">{{ $switchLabel ?? $fields[$key][2] }}</span>
        @if (! empty($help))
            <p class="tc-help">{{ $help }}</p>
        @endif
        @include('admin::settings.txn.partials.field-meta', ['key' => $key])
    </div>
    <input type="hidden" name="{{ $name }}" value="0">
    <label class="tc-toggle {{ ($plain ?? true) ? 'is-plain' : '' }}" style="flex: 0 0 auto;">
        <input type="checkbox" name="{{ $name }}" value="1" @checked($on) @disabled(! $canEdit) aria-label="{{ $switchLabel ?? $fields[$key][2] }}">
        <span class="tc-toggle-track"></span>
        <span data-switch-text>{{ $on ? 'On' : 'Off' }}</span>
    </label>
</div>
