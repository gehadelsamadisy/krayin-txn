{{-- Under a field, only when it was changed: "Changed" + default + Reset (submits the form with reset=<key>). Data: $key, $saved, $defaults, $canEdit, optional $defaultText. --}}
@if (isset($saved[$key]))
    @php
        $default = $defaultText ?? \Webkul\Admin\Support\Contact\TxnSettings::describe($defaults[$key] ?? null);
    @endphp
    <p class="tc-meta">
        <span class="tc-badge tc-badge-saved">Changed</span>
        <span class="tc-meta-default">Default: {{ \Illuminate\Support\Str::limit($default, 160) }}</span>
        @if ($canEdit)
            <button type="submit" name="reset" value="{{ $key }}" formnovalidate class="tc-link">Reset to default</button>
        @endif
    </p>
@endif
