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

    <!-- TXN: pin the desktop sidebar open (☰ in the header, pin at the bottom of
         the sidebar). Krayin's hover code only swaps sidebar-collapsed <->
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

        .txn-sidebar-pin { position: absolute; left: 0; right: 0; bottom: 76px; padding: 0 16px; }
        .txn-sidebar-pin button {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 6px;
            border: 0; border-radius: 8px; background: transparent; cursor: pointer;
            color: #4b5563; font-weight: 500; white-space: nowrap;
        }
        .txn-sidebar-pin button:hover { background: #f3f4f6; }
        .txn-sidebar-pin svg { flex: 0 0 24px; }
        .dark .txn-sidebar-pin button { color: #d1d5db; }
        .dark .txn-sidebar-pin button:hover { background: #030712; }
        html.txn-sidebar-pinned .txn-sidebar-pin button { color: #0e90d9; }
        html.dark.txn-sidebar-pinned .txn-sidebar-pin button { color: #38bdf8; }
        .txn-pin-on, html.txn-sidebar-pinned .txn-pin-off { display: none; }
        html.txn-sidebar-pinned .txn-pin-on { display: inline; }
    </style>

    <script>
        // fromSidebar: the click came from inside the sidebar (mouse is over it),
        // so unpinning leaves it open until the mouse leaves, as with hover.
        window.txnToggleSidebar = function (fromSidebar) {
            var root = document.documentElement;
            var layout = document.getElementById('txn-app-layout');
            var pinned = ! root.classList.contains('txn-sidebar-pinned');

            root.classList.toggle('txn-sidebar-pinned', pinned);

            if (layout) {
                layout.classList.remove('sidebar-collapsed', 'sidebar-not-collapsed');

                if (! pinned) {
                    layout.classList.add(fromSidebar ? 'sidebar-not-collapsed' : 'sidebar-collapsed');
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
