<style>

        .ws {
            --ws-accent: #2563EB;
            --ws-on-accent: #ffffff;
            --ws-surface: #ffffff;
            --ws-ink: #1f2937;
            --ws-muted: #6b7280;
            --ws-line: #e5e7eb;
            --ws-line-soft: #f3f4f6;
            --ws-hatch-a: #f9fafb;
            --ws-hatch-b: #eef0f3;
            --ws-off: #b45309;
            --ws-off-bg: #fef3c7;
            --ws-now: #dc2626;
            --ws-hour: 68px;
            color: var(--ws-ink);
            font-variant-numeric: tabular-nums;
            min-width: 0;
            max-width: 100%;
        }
        .ws-panel { min-width: 0; }
        .dark .ws {
            --ws-surface: #111827;
            --ws-ink: #f3f4f6;
            --ws-muted: #9ca3af;
            --ws-line: #1f2937;
            --ws-line-soft: #182030;
            --ws-hatch-a: #0d1320;
            --ws-hatch-b: #151d2c;
            --ws-off: #fbbf24;
            --ws-off-bg: #3a2a0b;
            --ws-now: #f87171;
        }

        .ws-panel { background: var(--ws-surface); border: 1px solid var(--ws-line); border-radius: 8px; }

        .ws-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 24px; padding: 16px 20px; }
        .ws-who { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .ws-swatch { width: 14px; height: 40px; border-radius: 3px; background: var(--ws-accent); flex: none; }
        .ws-name { font-size: 22px; font-weight: 700; line-height: 1.2; margin: 0; }
        .ws-badge { margin-left: 8px; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 500; background: var(--ws-line-soft); color: var(--ws-muted); vertical-align: middle; }
        .ws-contact { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 2px; font-size: 13px; color: var(--ws-muted); overflow-wrap: anywhere; }
        .ws-who > div { flex: 1 1 auto; }
        .ws-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

        .ws-select, .ws-date {
            height: 36px; padding: 0 10px; border: 1px solid var(--ws-line); border-radius: 6px;
            background: var(--ws-surface); color: var(--ws-ink); font-size: 14px;
        }

        .ws-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 20px; padding: 12px 20px; border-bottom: 1px solid var(--ws-line); }
        .ws-nav { display: inline-flex; border: 1px solid var(--ws-line); border-radius: 6px; overflow: hidden; }
        .ws-nav a { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 34px; padding: 0 12px; font-size: 14px; font-weight: 500; color: var(--ws-ink); }
        .ws-nav a + a { border-left: 1px solid var(--ws-line); }
        .ws-nav a:hover { background: var(--ws-line-soft); }
        .ws-range { font-size: 18px; font-weight: 600; margin: 0; }
        .ws-stats { margin-left: auto; display: flex; gap: 20px; font-size: 14px; color: var(--ws-muted); }
        .ws-stats strong { color: var(--ws-ink); font-weight: 600; }

        .ws-scroll { overflow-x: auto; }
        .ws-calendar { min-width: 880px; display: grid; grid-template-columns: 56px repeat(7, minmax(0, 1fr)); }

        .ws-dayhead { display: grid; grid-template-columns: auto 1fr; align-items: center; column-gap: 10px; padding: 12px; border-left: 1px solid var(--ws-line); border-bottom: 1px solid var(--ws-line); }
        .ws-daynum { grid-row: span 2; display: grid; place-items: center; width: 38px; height: 38px; border-radius: 50%; font-size: 18px; font-weight: 700; }
        .ws-dayhead.is-today .ws-daynum { background: var(--ws-accent); color: var(--ws-on-accent); }
        .ws-dayname { font-size: 13px; font-weight: 600; }
        .ws-daymeta { font-size: 12px; color: var(--ws-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ws-daymeta.is-off { color: var(--ws-off); }
        .ws-dayroute { grid-column: 2; font-size: 12px; font-weight: 600; color: var(--ws-accent); justify-self: start; }
        .ws-dayroute:hover { text-decoration: underline; }

        .ws-gutter { position: relative; border-bottom: 1px solid var(--ws-line); }
        .ws-gutter span { position: absolute; right: 8px; transform: translateY(-50%); font-size: 11px; color: var(--ws-muted); }
        .ws-gutter span:first-child { transform: none; }

        .ws-col {
            position: relative; height: calc(var(--ws-hour) * var(--ws-grid-hours));
            border-left: 1px solid var(--ws-line); border-bottom: 1px solid var(--ws-line);
            background: repeating-linear-gradient(135deg, var(--ws-hatch-a) 0 6px, var(--ws-hatch-b) 6px 7px);
        }
        .ws-col::after {
            content: ""; position: absolute; inset: 0; pointer-events: none;
            background: repeating-linear-gradient(to bottom, transparent 0 calc(var(--ws-hour) - 1px), var(--ws-line) calc(var(--ws-hour) - 1px) var(--ws-hour));
        }
        .ws-work { position: absolute; left: 0; right: 0; background: var(--ws-surface); }
        .ws-col.is-today .ws-work { background: color-mix(in srgb, var(--ws-accent) 4%, var(--ws-surface)); }

        .ws-dayoff {
            position: absolute; inset: 0; z-index: 3; display: flex; align-items: flex-start; justify-content: center; padding-top: 24px;
            background: repeating-linear-gradient(135deg, color-mix(in srgb, var(--ws-off-bg) 70%, transparent) 0 6px, transparent 6px 12px);
        }
        .ws-dayoff a, .ws-dayoff span, .ws-timeoff span { font-size: 12px; font-weight: 600; color: var(--ws-off); background: var(--ws-surface); padding: 2px 8px; border-radius: 4px; }
        .ws-timeoff {
            position: absolute; left: 0; right: 0; z-index: 1; padding: 4px;
            background: repeating-linear-gradient(135deg, var(--ws-off-bg) 0 6px, transparent 6px 12px);
            border-top: 1px solid var(--ws-off); border-bottom: 1px solid var(--ws-off);
        }
        .ws-dayoff a:hover, a.ws-timeoff:hover span { text-decoration: underline; }
        .ws-dayoff a:focus-visible, a.ws-timeoff:focus-visible { outline: 2px solid var(--ws-ink); outline-offset: 2px; }

        .ws-open {
            position: absolute; left: 4px; right: 4px; z-index: 1; padding: 6px 8px; border-radius: 6px;
            background: color-mix(in srgb, var(--ws-accent) 6%, transparent);
            font-size: 11px; color: transparent;
        }
        .ws-open:hover { color: color-mix(in srgb, var(--ws-accent) 70%, var(--ws-muted)); background: color-mix(in srgb, var(--ws-accent) 11%, transparent); }
        .ws-col.is-past .ws-work { background: var(--ws-hatch-a); }

        .ws-event {
            position: absolute; z-index: 2; overflow: hidden; display: flex; flex-direction: column; gap: 1px; padding: 6px 9px;
            border-radius: 6px; background: var(--ws-accent); color: var(--ws-on-accent);
            font-size: 12px; line-height: 1.3; box-shadow: 0 0 0 2px var(--ws-surface);
        }
        .ws-event:hover { filter: brightness(1.08); }
        .ws-event:focus-visible { outline: 2px solid var(--ws-ink); outline-offset: 2px; z-index: 4; }
        .ws-event-title { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ws-event-time { opacity: .9; }
        .ws-event-sub { opacity: .8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ws-event.is-busy { background: var(--ws-line-soft); color: var(--ws-ink); border: 1px solid var(--ws-line); }
        .ws-event.is-task { background: #0f766e; color: #ffffff; }
        .ws-event.is-done { background: color-mix(in srgb, var(--ws-accent) 22%, var(--ws-surface)); color: var(--ws-ink); }
        .ws-event.is-cancelled { background: var(--ws-surface); color: var(--ws-muted); border: 1px dashed var(--ws-line); }
        .ws-event.is-cancelled .ws-event-title { text-decoration: line-through; }

        .ws-now { position: absolute; left: -1px; right: 0; z-index: 5; height: 2px; background: var(--ws-now); pointer-events: none; }
        .ws-now::before { content: ""; position: absolute; left: -4px; top: -3px; width: 8px; height: 8px; border-radius: 50%; background: var(--ws-now); }

        .ws-legend { display: flex; flex-wrap: wrap; gap: 8px 20px; padding: 12px 20px; font-size: 12px; color: var(--ws-muted); }
        .ws-legend i { display: inline-block; width: 18px; height: 12px; margin-right: 6px; vertical-align: -2px; border-radius: 3px; }
        .ws-key-visit { background: var(--ws-accent); }
        .ws-key-task { background: #0f766e; }
        .ws-key-open { background: color-mix(in srgb, var(--ws-accent) 8%, transparent); border-left: 3px solid color-mix(in srgb, var(--ws-accent) 35%, transparent); border-radius: 0; }
        .ws-jump { position: relative; }
        .ws-jump input { position: absolute; inset: 0; opacity: 0; pointer-events: none; }
        .ws-key-hours { background: repeating-linear-gradient(135deg, var(--ws-hatch-a) 0 3px, var(--ws-hatch-b) 3px 5px); border: 1px solid var(--ws-line); }
        .ws-key-off { background: repeating-linear-gradient(135deg, var(--ws-off-bg) 0 3px, transparent 3px 6px); border: 1px solid var(--ws-off); }

        @media (max-width: 640px) {
            .ws-head, .ws-toolbar, .ws-legend { padding-left: 16px; padding-right: 16px; }
            .ws-stats { margin-left: 0; }
        }

        .ws-panel {
            border-radius: 14px;
            box-shadow: 0 2px 8px rgb(15 23 42 / 4%);
        }

        .ws-head {
            position: relative;
            overflow: hidden;
            padding: 22px 24px;
            background: linear-gradient(120deg, color-mix(in srgb, var(--ws-accent) 7%, var(--ws-surface)), var(--ws-surface) 56%);
        }

        .ws-head::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--ws-accent);
        }

        .ws-who { gap: 16px; }
        .ws-swatch {
            display: grid;
            place-items: center;
            width: 52px;
            height: 52px;
            border-radius: 14px;
            color: var(--ws-on-accent);
            font-size: 21px;
            font-weight: 700;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--ws-accent) 22%, transparent);
        }
        .ws-kicker {
            margin: 0 0 4px;
            color: var(--ws-muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .ws-name { font-size: 24px; letter-spacing: -.025em; }
        .ws-contact { gap: 6px 16px; margin-top: 7px; font-size: 12px; }
        .ws-contact span { display: inline-flex; align-items: center; }

        .ws-actions { gap: 8px; }
        .ws-select {
            min-width: 170px;
            font-weight: 500;
            box-shadow: 0 1px 2px rgb(15 23 42 / 5%);
        }
        .ws-actions .secondary-button { min-height: 36px; }

        .ws-toolbar {
            gap: 12px 16px;
            padding: 16px 20px;
            background: var(--ws-surface);
        }
        .ws-nav { border-radius: 8px; }
        .ws-nav a { height: 36px; font-weight: 600; text-decoration: none; }
        .ws-nav a[aria-current="date"] {
            background: color-mix(in srgb, var(--ws-accent) 9%, var(--ws-surface));
            color: var(--ws-accent);
        }
        .ws-range { font-size: 20px; letter-spacing: -.02em; }
        .ws-jump .secondary-button { min-height: 36px; }
        .ws-stats { gap: 8px; }
        .ws-stats span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 7px 10px;
            border: 1px solid var(--ws-line);
            border-radius: 8px;
            background: var(--ws-line-soft);
            font-size: 12px;
            white-space: nowrap;
        }
        .ws-stats strong { font-size: 14px; }

        .ws-scroll { scrollbar-color: var(--ws-line) transparent; }
        .ws-calendar {
            min-width: 1050px;
            grid-template-columns: 64px repeat(7, minmax(128px, 1fr));
        }
        .ws-dayhead {
            position: sticky;
            top: 0;
            z-index: 6;
            min-height: 78px;
            padding: 12px 10px;
            background: var(--ws-surface);
            transition: background-color .15s ease;
        }
        .ws-dayhead.is-today { background: color-mix(in srgb, var(--ws-accent) 5%, var(--ws-surface)); }
        .ws-daynum { width: 42px; height: 42px; font-size: 17px; }
        .ws-dayname { font-size: 12px; }
        .ws-daymeta { margin-top: 2px; font-size: 11px; }
        .ws-gutter { background: var(--ws-surface); }
        .ws-col { --ws-hour: 72px; }
        .ws-event { border-radius: 8px; box-shadow: 0 1px 3px rgb(15 23 42 / 12%); }
        .ws-legend { gap: 10px 24px; padding-top: 14px; padding-bottom: 14px; }

        @media (max-width: 900px) {
            .ws-toolbar { align-items: flex-start; }
            .ws-stats { width: 100%; margin-left: 0; flex-wrap: wrap; }
        }

        @media (max-width: 640px) {
            .ws-head { align-items: flex-start; padding-top: 20px; padding-bottom: 20px; }
            .ws-who { align-items: flex-start; }
            .ws-swatch { width: 44px; height: 44px; border-radius: 12px; font-size: 18px; }
            .ws-name { font-size: 20px; }
            .ws-actions { width: 100%; }
            .ws-select { flex: 1 1 100%; }
            .ws-actions .secondary-button { flex: 1 1 auto; text-align: center; }
            .ws-toolbar { gap: 12px; }
            .ws-range { flex: 1 1 calc(100% - 150px); font-size: 17px; }
            .ws-jump { margin-left: auto; }
            .ws-stats span { flex: 1 1 auto; justify-content: center; }
            .ws-legend { gap: 10px 16px; }
        }
    
</style>
