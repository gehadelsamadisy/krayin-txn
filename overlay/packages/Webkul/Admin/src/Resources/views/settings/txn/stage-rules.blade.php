{{-- Settings > TXN Settings > Stage rules (TxnStageRulesController). Styles: partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>
        Stage rules
    </x-slot>

    @php
        $canEdit = bouncer()->hasPermission('settings.txn.stage_rules.edit');
    @endphp

    @include('admin::settings.txn.partials.tc-styles')

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'layers', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">TXN Settings</span>
                <h1>Stage rules</h1>
                <p>
                    Which stage each automatic move uses. Add, remove, rename or reorder stages in
                    <a href="{{ route('admin.settings.pipelines.index') }}" style="color: var(--tc-brand); font-weight: 600;">Settings &gt; Pipelines</a>,
                    then give each new or replaced stage its role here.
                </p>
            </div>
            <a href="{{ route('admin.settings.index') }}" class="secondary-button">Back to settings</a>
        </div>

        @if ($errors->has('roles'))
            <div class="tc tc-alert" role="alert">
                @include('admin::settings.txn.partials.icon', ['name' => 'alert'])
                <div>
                    <strong>Not saved</strong>
                    @foreach ($errors->get('roles') as $message)
                        <div>{{ $message }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($warnings)
            <div class="tc tc-box is-warn" role="status">
                @include('admin::settings.txn.partials.icon', ['name' => 'alert'])
                <div>
                    <strong>Needs attention</strong>
                    <ul>
                        @foreach ($warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.txn.stage_rules.update') }}" class="tc tc-form">
            @csrf
            @method('PUT')

            @foreach ($pipelines as $pipeline)
                @php
                    $pipelineStages = $stages->get($pipeline->id, collect());
                    $editLink = bouncer()->hasPermission('settings.lead.pipelines.edit')
                        ? '<a href="'.e(route('admin.settings.pipelines.edit', $pipeline->id)).'" class="tc-btn">Edit stages</a>'
                        : '';
                @endphp
                <section class="tc-card is-plain">
                    @include('admin::settings.txn.partials.card-head', [
                        'icon' => $pipeline->is_default ? 'layers' : 'route',
                        'title' => $pipeline->name,
                        'text' => ($pipeline->is_default ? 'Main pipeline: new leads arrive here.' : 'Leads come here only when a rule or staff move them.').' '.$pipelineStages->count().' stages.',
                        'aside' => $editLink,
                    ])
                    <div class="tc-body">
                        <div class="tc-rows">
                            @foreach ($pipelineStages as $stage)
                                @php
                                    $isClosing = in_array($stage->code, $closing, true);
                                    $selected = old("roles.{$stage->id}", $roles[$stage->id] ?? '');
                                    $count = (int) ($leadCounts[$stage->id] ?? 0);
                                @endphp
                                <div class="tc-stage {{ $isClosing ? 'is-closing' : '' }}">
                                    <span class="tc-stage-pos">{{ $stage->sort_order }}</span>
                                    <span class="tc-stage-name">{{ $stage->name }}</span>
                                    <span class="tc-chip {{ $count ? '' : 'is-muted' }}">{{ $count }} {{ $count === 1 ? 'lead' : 'leads' }}</span>
                                    @if ($isClosing)
                                        <span class="tc-status is-off">Krayin closing stage ({{ $stage->code === 'won' ? 'won' : 'lost' }})</span>
                                    @else
                                        <select name="roles[{{ $stage->id }}]" class="tc-input" @disabled(! $canEdit) aria-label="Role of {{ $stage->name }}">
                                            <option value="" @selected($selected === '')>None (staff move leads here by hand)</option>
                                            @foreach ($roleOptions as $role => [$label])
                                                <option value="{{ $role }}" @selected($selected === $role)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach

            @include('admin::settings.txn.partials.savebar', ['canEdit' => $canEdit, 'noReset' => true, 'saveLabel' => 'Save stage rules', 'idleText' => 'No changes yet'])
        </form>

        <section class="tc tc-card is-plain">
            @include('admin::settings.txn.partials.card-head', ['icon' => 'info', 'title' => 'What each role does', 'text' => 'Each role belongs to one stage per pipeline.'])
            <div class="tc-body">
                <div class="tc-roles">
                    @foreach ($roleOptions as $role => [$label, $what])
                        <div class="tc-role"><strong>{{ $label }}</strong>{{ $what }}</div>
                    @endforeach
                    <div class="tc-role"><strong>None</strong>The automation never moves leads into or out of this stage; staff do it on the board (e.g. Visit Done, Estimate Sent).</div>
                </div>
            </div>
        </section>

        @include('admin::settings.txn.partials.log')
    </div>
</x-admin::layouts>
