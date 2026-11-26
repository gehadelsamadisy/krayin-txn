{{-- Fields of one mailbox (Mailboxes screen). Data: $m (row or null for a new one), $ends (secret ending), $open (this form was just submitted: show old input). --}}
@php
    $isMain = $m && $m->is_main;
    $key = $m ? $m->id : 'new';
    $val = fn (string $field, $default = '') => $open ? old($field, $default) : $default;
    $reads = $open ? old('read_replies', '1') === '1' : ($m ? (bool) $m->read_replies : true);
@endphp
<div class="tc-grid">
    <div class="tc-field">
        <label for="mb-address-{{ $key }}" class="tc-label">Email address</label>
        <input id="mb-address-{{ $key }}" type="email" name="address" class="tc-input" value="{{ $val('address', $m->address ?? '') }}"
            @readonly($isMain) @required(! $isMain) placeholder="estimates@yourdomain.com">
        @if ($isMain)
            <p class="tc-help">Change the sender on Connections; this row follows it.</p>
        @endif
    </div>
    <div class="tc-field">
        <label for="mb-name-{{ $key }}" class="tc-label">Name (optional)</label>
        <input id="mb-name-{{ $key }}" type="text" name="name" maxlength="100" class="tc-input" value="{{ $val('name', $m->name ?? '') }}" placeholder="e.g. Estimates team">
    </div>

    @if ($isMain)
        <div class="tc-field tc-wide">
            <p class="tc-help">Login: the Microsoft 365 tenant ID, client ID and secret on
                <a href="{{ route('admin.settings.txn.connections') }}" style="color: var(--tc-brand); font-weight: 600;">Connections</a>
                (the same app that sends). It needs Mail.ReadWrite as well as Mail.Send.</p>
        </div>
    @else
        <div class="tc-field">
            <label for="mb-tenant-{{ $key }}" class="tc-label">Tenant ID</label>
            <input id="mb-tenant-{{ $key }}" type="text" name="tenant_id" maxlength="64" class="tc-input" value="{{ $val('tenant_id', $m->tenant_id ?? '') }}" placeholder="1b2c3d4e-...">
        </div>
        <div class="tc-field">
            <label for="mb-client-{{ $key }}" class="tc-label">Client ID (application ID)</label>
            <input id="mb-client-{{ $key }}" type="text" name="client_id" maxlength="64" class="tc-input" value="{{ $val('client_id', $m->client_id ?? '') }}" placeholder="1b2c3d4e-...">
        </div>
        <div class="tc-field tc-wide">
            <label for="mb-secret-{{ $key }}" class="tc-label">Client secret</label>
            <input id="mb-secret-{{ $key }}" type="password" name="client_secret" class="tc-input" autocomplete="new-password"
                placeholder="{{ $ends ? 'Set ('.$ends.'): leave empty to keep it' : 'Paste the secret value (Certificates & secrets)' }}">
            <p class="tc-help">Stored encrypted. It is never shown again; only its last 4 characters.</p>
        </div>
    @endif

    <div class="tc-field tc-wide" style="flex-direction: row; align-items: center; justify-content: space-between; gap: 14px;">
        <div>
            <span class="tc-label">Read replies from this mailbox</span>
            <p class="tc-help">Off: Krayin keeps the login but does not check this mailbox.</p>
        </div>
        <input type="hidden" name="read_replies" value="0">
        <label class="tc-toggle is-plain" style="flex: 0 0 auto;">
            <input type="checkbox" name="read_replies" value="1" @checked($reads) aria-label="Read replies from this mailbox">
            <span class="tc-toggle-track"></span>
            <span>{{ $reads ? 'On' : 'Off' }}</span>
        </label>
    </div>
</div>
