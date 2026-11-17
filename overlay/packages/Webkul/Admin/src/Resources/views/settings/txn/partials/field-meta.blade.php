{{-- Under a field: "Changed" + default + Reset button (submits the form with reset=<key>). Data: $key, $saved, $defaults, $canEdit, optional $defaultText. --}}
@php
    $default = $defaultText ?? \Webkul\Admin\Support\Contact\TxnSettings::describe($defaults[$key] ?? null);
@endphp
<p class="mt-1 text-xs text-gray-500">
    @if (isset($saved[$key]))
        <span class="font-semibold text-blue-600">Changed.</span>
        Default: {{ \Illuminate\Support\Str::limit($default, 160) }}
        @if ($canEdit)
            <button type="submit" name="reset" value="{{ $key }}" formnovalidate class="text-blue-600 hover:underline">Reset to default</button>
        @endif
    @else
        Default.
    @endif
</p>
