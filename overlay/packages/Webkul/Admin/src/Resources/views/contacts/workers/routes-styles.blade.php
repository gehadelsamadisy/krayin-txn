<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<style>
        .wr {
            --wr-surface: #ffffff;
            --wr-ink: #1f2937;
            --wr-muted: #6b7280;
            --wr-line: #e5e7eb;
            --wr-soft: #f9fafb;
            --wr-late: #b91c1c;
            --wr-late-bg: #fee2e2;
            --wr-note: #92400e;
            --wr-note-bg: #fef3c7;
            color: var(--wr-ink);
            font-variant-numeric: tabular-nums;
            min-width: 0;
        }
        .dark .wr {
            --wr-surface: #111827;
            --wr-ink: #f3f4f6;
            --wr-muted: #9ca3af;
            --wr-line: #1f2937;
            --wr-soft: #182030;
            --wr-late: #fca5a5;
            --wr-late-bg: #450a0a;
            --wr-note: #fcd34d;
            --wr-note-bg: #422006;
        }
        .wr-panel {
            background: var(--wr-surface);
            border: 1px solid var(--wr-line);
            border-radius: 8px;
            min-width: 0;
        }

        /* Header */
        .wr-head {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 12px 24px;
            justify-content: space-between;
            padding: 14px 16px;
        }
        .wr-kicker {
            color: var(--wr-muted);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .wr-title { font-size: 20px; font-weight: 700; line-height: 1.3; }
        .wr-summary { color: var(--wr-muted); font-size: 13px; margin-top: 2px; }
        .wr-summary strong { color: var(--wr-ink); font-weight: 600; }
        .wr-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }
        .wr-nav { display: inline-flex; border: 1px solid var(--wr-line); border-radius: 6px; overflow: hidden; }
        .wr-nav a {
            color: var(--wr-ink);
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            padding: 9px 12px;
        }
        .wr-nav a + a { border-left: 1px solid var(--wr-line); }
        .wr-nav a:hover { background: var(--wr-soft); }
        .wr-nav a[aria-current] { color: #2563eb; }
        .wr-select {
            background: var(--wr-surface);
            border: 1px solid var(--wr-line);
            border-radius: 6px;
            color: var(--wr-ink);
            font-size: 14px;
            padding: 7px 30px 7px 10px;
        }
        .wr-jump { position: relative; }
        .wr-jump input { height: 1px; left: 0; opacity: 0; position: absolute; bottom: 0; width: 1px; }

        .wr-notice {
            background: var(--wr-note-bg);
            border-radius: 8px;
            color: var(--wr-note);
            font-size: 13px;
            padding: 10px 14px;
        }

        /* List + map */
        .wr-body {
            display: grid;
            gap: 16px;
            grid-template-columns: minmax(300px, 380px) minmax(0, 1fr);
            align-items: start;
        }
        .wr-list { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
        .wr-mapwrap {
            overflow: hidden;
            position: sticky;
            top: 74px;
        }
        /* Own stacking context: Leaflet's panes must not cover Krayin's menus. */
        #wr-map { height: calc(100vh - 200px); min-height: 460px; position: relative; width: 100%; z-index: 0; }

        /* Worker card */
        .wr-card { padding: 0; }
        .wr-card-head {
            align-items: center;
            border-bottom: 1px solid var(--wr-line);
            display: flex;
            gap: 10px;
            padding: 12px 14px;
        }
        .wr-dot { border-radius: 50%; flex: none; height: 12px; width: 12px; }
        .wr-card-name {
            background: none;
            border: 0;
            color: var(--wr-ink);
            cursor: pointer;
            flex: 1;
            font-size: 15px;
            font-weight: 600;
            min-width: 0;
            overflow: hidden;
            padding: 0;
            text-align: left;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .wr-card-name:hover { text-decoration: underline; }
        .wr-card-link { color: #2563eb; flex: none; font-size: 13px; }
        .wr-card-link:hover { text-decoration: underline; }
        .wr-stats {
            color: var(--wr-muted);
            display: flex;
            flex-wrap: wrap;
            font-size: 12px;
            gap: 4px 14px;
            padding: 10px 14px 0;
        }
        .wr-stats strong { color: var(--wr-ink); font-weight: 600; }
        .wr-empty { color: var(--wr-muted); font-size: 13px; padding: 12px 14px 14px; }

        /* Timeline */
        .wr-steps { list-style: none; margin: 0; padding: 10px 14px 14px; }
        .wr-step { display: grid; gap: 0 10px; grid-template-columns: 26px minmax(0, 1fr); }
        .wr-pin {
            align-items: center;
            background: var(--wr-color);
            border: 2px solid var(--wr-surface);
            border-radius: 50%;
            box-shadow: 0 0 0 1px var(--wr-color);
            color: #fff;
            display: flex;
            font-size: 11px;
            font-weight: 700;
            height: 24px;
            justify-content: center;
            width: 24px;
        }
        .wr-pin.is-start { background: #374151; box-shadow: 0 0 0 1px #374151; }
        .wr-step.is-done .wr-pin { opacity: .45; }
        .wr-stop {
            background: none;
            border: 0;
            border-radius: 6px;
            color: var(--wr-ink);
            cursor: pointer;
            display: block;
            margin: -2px -6px 0;
            min-width: 0;
            padding: 2px 6px 4px;
            text-align: left;
            width: calc(100% + 12px);
        }
        .wr-stop:hover { background: var(--wr-soft); }
        .wr-stop-time { font-size: 13px; font-weight: 600; }
        .wr-stop-name { font-size: 13px; overflow-wrap: anywhere; }
        .wr-stop-addr { color: var(--wr-muted); font-size: 12px; overflow-wrap: anywhere; }
        .wr-step.is-done .wr-stop-time,
        .wr-step.is-done .wr-stop-name { color: var(--wr-muted); }
        .wr-badge {
            border-radius: 999px;
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            margin-left: 4px;
            padding: 0 7px;
            vertical-align: 1px;
        }
        .wr-badge.is-late { background: var(--wr-late-bg); color: var(--wr-late); }
        .wr-badge.is-done { background: var(--wr-soft); color: var(--wr-muted); }

        .wr-drive { display: grid; gap: 0 10px; grid-template-columns: 26px minmax(0, 1fr); min-height: 30px; }
        .wr-drive::before {
            border-left: 2px dashed var(--wr-color);
            content: "";
            justify-self: center;
            opacity: .55;
        }
        .wr-drive span { align-self: center; color: var(--wr-muted); font-size: 12px; }

        .wr-offmap {
            border-top: 1px dashed var(--wr-line);
            color: var(--wr-muted);
            font-size: 12px;
            margin: 0 14px;
            padding: 8px 0 12px;
        }

        /* Map markers and popups */
        .wr-marker {
            align-items: center;
            border: 2px solid #fff;
            border-radius: 50%;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .45);
            color: #fff;
            cursor: pointer;
            display: flex;
            font: 700 12px/1 Inter, Arial, sans-serif;
            height: 26px;
            justify-content: center;
            width: 26px;
        }
        .wr-marker.is-start { background: #374151; height: 30px; width: 30px; }
        .wr-marker.is-done { opacity: .5; }
        .wr-popup { color: #1f2937; font: 13px/1.5 Inter, Arial, sans-serif; }
        .wr-popup strong { display: block; }
        .wr-popup .wr-popup-muted { color: #6b7280; }
        .wr-marker-icon { background: none; border: 0; }
        #wr-map .leaflet-popup-content { margin: 10px 14px; min-width: 180px; }
        .wr-legend {
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .3);
            color: #1f2937;
            font: 12px/1.7 Inter, Arial, sans-serif;
            padding: 6px 10px;
        }
        .wr-legend i { border-radius: 50%; display: inline-block; height: 10px; margin-right: 6px; width: 10px; }

        @media (max-width: 1023px) {
            .wr-body { grid-template-columns: minmax(0, 1fr); }
            .wr-mapwrap { order: -1; position: static; }
            #wr-map { height: 380px; min-height: 0; }
        }
</style>
