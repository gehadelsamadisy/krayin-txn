{{-- One field on the Connections screen. Data: $key, $fields, $values, $defaults, $saved, $secrets, $inputNames, $canEdit.
     A secret never has its value on the page: only where it comes from and its last 4 characters. --}}
@php
    [, $type, $label, $options] = $fields[$key];
    $name = $inputNames[$key];
    $sourceText = ['saved' => 'Saved in Krayin', 'env' => 'From .env', 'none' => 'Not set'];
@endphp

@if ($type === 'secret')
    @php $status = $secrets[$key]; @endphp

    <div class="tc-field tc-wide">
        <div class="tc-label-row">
            <label for="f-{{ $name }}" class="tc-label">{{ $label }}</label>

            <span class="tc-source">
                @if ($status['unreadable'])
                    <span class="tc-badge tc-badge-bad">Can't be read, enter it again</span>
                @endif
                <span class="tc-badge tc-badge-{{ $status['source'] }}">{{ $sourceText[$status['source']] }}</span>
                @if ($status['source'] !== 'none')
                    <span class="tc-ends">{{ $status['ends'] }}</span>
                @endif
            </span>
        </div>

        <input
            id="f-{{ $name }}"
            type="password"
            name="{{ $name }}"
            value=""
            autocomplete="new-password"
            spellcheck="false"
            placeholder="{{ $status['source'] === 'none' ? 'Paste the key' : 'Leave empty to keep the current key' }}"
            class="tc-input"
        >

        @if ($canEdit && $status['source'] === 'saved')
            <button
                type="submit"
                name="reset"
                value="{{ $key }}"
                formnovalidate
                class="tc-link"
                onclick="return confirm('Delete the key saved in Krayin and use the one in .env?')"
            >Use the .env key instead</button>
        @endif
    </div>
@else
    <div class="tc-field">
        <label for="f-{{ $name }}" class="tc-label">{{ $label }}</label>

        <input
            id="f-{{ $name }}"
            type="{{ $type === 'port' ? 'number' : ($type === 'email' ? 'email' : 'text') }}"
            name="{{ $name }}"
            value="{{ old($name, $values[$key]) }}"
            spellcheck="false"
            class="tc-input"
        >

        <p class="tc-help">
            @if (! empty($options['hint']))
                {{ ucfirst($options['hint']) }}.
            @endif

            @if (isset($saved[$key]))
                <span class="tc-changed">Changed in Krayin.</span>
                @if ($canEdit)
                    <button type="submit" name="reset" value="{{ $key }}" formnovalidate class="tc-link">Use the .env value</button>
                @endif
            @endif
        </p>
    </div>
@endif
