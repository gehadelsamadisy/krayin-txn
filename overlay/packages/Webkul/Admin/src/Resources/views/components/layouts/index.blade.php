<!DOCTYPE html>

@php
    // TXN: sidebar pinned open (icons + text). Set by txnToggleSidebar() below in a
    // plain browser cookie, so it is read raw (Laravel's cookies are encrypted).
    $txnSidebarPinned = ($_COOKIE['txn_sidebar_pinned'] ?? '') === '1';
@endphp

<html
    class="{{ request()->cookie('dark_mode') ? 'dark' : '' }} {{ $txnSidebarPinned ? 'txn-sidebar-pinned' : '' }}"
    lang="{{ app()->getLocale() }}"
    dir="{{ in_array(app()->getLocale(), ['fa', 'ar']) ? 'rtl' : 'ltr' }}"
>

<head>

    {!! view_render_event('admin.layout.head.before') !!}

    <title>{{ $title }}</title>

    <meta charset="UTF-8">

    <meta
        http-equiv="X-UA-Compatible"
        content="IE=edge"
    >
    <meta
        http-equiv="content-language"
        content="{{ app()->getLocale() }}"
    >

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <meta
        name="base-url"
        content="{{ url()->to('/') }}"
    >
    <meta
        name="currency"
        content="{{
            json_encode([
                'code' => config('app.currency'),
                'symbol' => core()->currencySymbol(config('app.currency'))])
            }}
        "
    >

    @stack('meta')

    {{
        vite()->set(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'])
    }}

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap"
        rel="stylesheet"
    />

    @if ($logo = core()->getConfigData('general.general.admin_logo.logo_image'))
        <link
            rel="preload"
            as="image"
            href="{{ Storage::url($logo) }}"
        >
    @endif

    @if ($favicon = core()->getConfigData('general.general.admin_logo.favicon'))
        <link
            type="image/png"
            href="{{ Storage::url($favicon) }}"
            rel="icon"
            sizes="16x16"
        >
    @else
        <link
            type="image/x-icon"
            href="{{ vite()->asset('images/favicon.ico') }}"
            rel="shortcut icon"
            sizes="16x16"
        />
    @endif

    @php
        $brandColor = core()->getConfigData('general.settings.menu_color.brand_color') ?? '#0E90D9';
    @endphp

    @if (request()->routeIs('admin.contacts.workers.schedule'))
        @include('admin::contacts.workers.schedule-styles')
    @endif

    @if (request()->routeIs('admin.contacts.workers.routes'))
        @include('admin::contacts.workers.routes-styles')
    @endif

    <!-- TXN: pin the desktop sidebar open with the ☰ in the header. Krayin's hover code only swaps sidebar-collapsed <->
         sidebar-not-collapsed, so with neither class the sidebar stays open. -->
    <style>
        @media (min-width: 1024px) {
            html.txn-sidebar-pinned[dir="ltr"] .txn-page-content { padding-left: 216px; }
            html.txn-sidebar-pinned[dir="rtl"] .txn-page-content { padding-right: 216px; }
        }
        .txn-sidebar-toggle {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border: 0; border-radius: 6px;
            background: transparent; color: #4b5563; cursor: pointer;
        }
        .txn-sidebar-toggle:hover { background: #f3f4f6; color: #111827; }
        html.txn-sidebar-pinned .txn-sidebar-toggle { color: #0e90d9; }
        .dark .txn-sidebar-toggle { color: #d1d5db; }
        .dark .txn-sidebar-toggle:hover { background: #030712; color: #fff; }
        html.dark.txn-sidebar-pinned .txn-sidebar-toggle { color: #38bdf8; }
        @media (max-width: 1023px) { .txn-sidebar-toggle { display: none; } }

        /* TXN: "customers can get messages" banner (a Safety guard is off). */
        .txn-live-banner {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;
            padding: 10px 14px; border: 1px solid #d1453b; border-radius: 10px;
            background: #fbe9e7; color: #9b2c24; font-size: 13px;
        }
        .txn-live-banner span { flex: 1 1 240px; }
        .txn-live-banner a { font-weight: 600; color: #9b2c24; text-decoration: underline; }
        .dark .txn-live-banner { background: #3a1714; border-color: #f07167; color: #fbd5d1; }
        .dark .txn-live-banner a { color: #fbd5d1; }

        /* TXN: a service isn't working (red) or has no key (amber): ServiceHealth. */
        .txn-service-banner {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;
            padding: 10px 14px; border-radius: 10px; font-size: 13px; line-height: 1.45;
        }
        .txn-service-banner > span:first-of-type { flex: 1 1 260px; overflow-wrap: anywhere; }
        .txn-service-banner a { font-weight: 600; text-decoration: underline; white-space: nowrap; }
        .txn-service-banner svg { flex: 0 0 auto; }
        .txn-service-when { opacity: .75; }
        .txn-service-banner.is-failed { border: 1px solid #d1453b; background: #fbe9e7; color: #9b2c24; }
        .txn-service-banner.is-failed a { color: #9b2c24; }
        .txn-service-banner.is-nokey { border: 1px solid #c9822b; background: #fbf1e3; color: #8a5413; }
        .txn-service-banner.is-nokey a { color: #8a5413; }
        .dark .txn-service-banner.is-failed { background: #3a1714; border-color: #f07167; color: #fbd5d1; }
        .dark .txn-service-banner.is-failed a { color: #fbd5d1; }
        .dark .txn-service-banner.is-nokey { background: #33260f; border-color: #e3a356; color: #f6dfbf; }
        .dark .txn-service-banner.is-nokey a { color: #f6dfbf; }
    </style>

    <script>
        window.txnToggleSidebar = function () {
            var root = document.documentElement;
            var layout = document.getElementById('txn-app-layout');
            var pinned = ! root.classList.contains('txn-sidebar-pinned');

            root.classList.toggle('txn-sidebar-pinned', pinned);

            if (layout) {
                layout.classList.remove('sidebar-collapsed', 'sidebar-not-collapsed');

                if (! pinned) {
                    layout.classList.add('sidebar-collapsed');
                }
            }

            document.cookie = 'txn_sidebar_pinned=' + (pinned ? '1' : '0')
                + '; path=/; max-age=' + (pinned ? 31536000 : 0) + '; SameSite=Lax';
        };
    </script>

    @stack('styles')

    <style>
        :root {
            --brand-color: {{ $brandColor }};
        }

        {!! core()->getConfigData('general.content.custom_scripts.custom_css') !!}
    </style>

    {!! view_render_event('admin.layout.head.after') !!}
</head>

<body class="h-full font-inter dark:bg-gray-950">
    {!! view_render_event('admin.layout.body.before') !!}

    <div
        id="app"
        class="h-full"
    >
        <!-- Flash Message Blade Component -->
        <x-admin::flash-group />

        <!-- Confirm Modal Blade Component -->
        <x-admin::modal.confirm />

        {!! view_render_event('admin.layout.content.before') !!}

        <!-- Page Header Blade Component -->
        <x-admin::layouts.header />

        <div
            class="group/container {{ $txnSidebarPinned ? '' : 'sidebar-collapsed' }} flex gap-4"
            id="txn-app-layout"
            ref="appLayout"
        >
            <!-- Page Sidebar Blade Component -->
            <x-admin::layouts.sidebar.desktop />

            <div class="flex min-h-[calc(100vh-62px)] max-w-full flex-1 flex-col bg-gray-100 pt-3 transition-all duration-300 dark:bg-gray-950">
                <!-- Page Content Blade Component -->
                <div class="txn-page-content px-4 pb-6 ltr:lg:pl-[85px] rtl:lg:pr-[85px]">
                    {{-- TXN: while a guard is off (Settings > TXN Settings > Safety), say so on
                         the Settings pages and the contact page, where messages are sent. --}}
                    @if (
                        request()->routeIs('admin.settings.*', 'admin.contacts.persons.view')
                        && ($txnGuardsOff = \Webkul\Admin\Support\Contact\TxnSettings::guardsOff())
                    )
                        <div class="txn-live-banner" role="status">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z"/><path d="M12 9v4M12 16v.01"/></svg>
                            <span><strong>Customers can get messages.</strong> Off: {{ implode(', ', $txnGuardsOff) }}.</span>
                            @if (bouncer()->hasPermission('settings.txn.safety'))
                                <a href="{{ route('admin.settings.txn.safety') }}">Safety settings</a>
                            @endif
                        </div>
                    @endif

                    {{-- TXN: a service Krayin uses failed (red) or was used without a key
                         (amber), on every page (Support\Contact\ServiceHealth). --}}
                    @foreach (\Webkul\Admin\Support\Contact\ServiceHealth::problems() as $txnProblem)
                        <div class="txn-service-banner {{ $txnProblem['state'] === 'no_key' ? 'is-nokey' : 'is-failed' }}" role="alert" v-pre>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17v.01"/></svg>
                            <span>
                                @if ($txnProblem['state'] === 'no_key')
                                    <strong>{{ $txnProblem['label'] }} has no key assigned.</strong>
                                @else
                                    <strong>{{ $txnProblem['label'] }} isn't working:</strong>
                                    {{ strtok($txnProblem['message'], "\n") }}
                                @endif
                                <span class="txn-service-when">({{ \Carbon\Carbon::parse($txnProblem['failed_at'])->format('M j, g:i A') }})</span>
                            </span>
                            @if (bouncer()->hasPermission('settings.txn.connections'))
                                <a href="{{ route('admin.settings.txn.connections') }}#tc-{{ $txnProblem['card'] }}">{{ $txnProblem['state'] === 'no_key' ? 'Add its key in Connections' : 'Check its keys in Connections' }}</a>
                            @else
                                <span class="txn-service-when">Tell an administrator.</span>
                            @endif
                        </div>
                    @endforeach

                    {{ $slot }}
                </div>

                <!-- Powered By -->
                <div class="mt-auto pt-6">
                    <div class="border-t bg-white py-5 text-center text-sm font-normal dark:border-gray-800 dark:bg-gray-900 dark:text-white max-md:py-3">
                        <p>{!! core()->getConfigData('general.settings.footer.label') !!}</p>
                    </div>
                </div>
            </div>
        </div>

        {!! view_render_event('admin.layout.content.after') !!}
    </div>

    {!! view_render_event('admin.layout.body.after') !!}

    @stack('scripts')

    {!! view_render_event('admin.layout.vue-app-mount.before') !!}

    <script>
        /**
         * Load event, the purpose of using the event is to mount the application
         * after all of our `Vue` components which is present in blade file have
         * been registered in the app. No matter what `app.mount()` should be
         * called in the last.
         */
        window.addEventListener("load", function(event) {
            app.mount("#app");
        });
    </script>

    {!! view_render_event('admin.layout.vue-app-mount.after') !!}
</body>

</html>
