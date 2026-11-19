{{-- Shared look of the locked TXN Settings screens (Connections, Safety). Scoped to .tc: the precompiled Tailwind build ignores new classes. --}}
@pushOnce('styles')
<style>
    .tc {
        --tc-ink: #1c2b39; --tc-muted: #5f6f7f; --tc-line: #e3e8ee; --tc-panel: #fff; --tc-band: #f6f8fa;
        --tc-field: #fff; --tc-field-off: #f6f8fa; --tc-brand: #0e90d9; --tc-brand-soft: #e3f2fb;
        --tc-ok: #1f9d6b; --tc-ok-soft: #e4f5ee; --tc-warn: #c9822b; --tc-warn-soft: #fbf1e3;
        --tc-bad: #d1453b; --tc-bad-soft: #fbe9e7; --tc-idle: #c3ccd5;
        color: var(--tc-ink); font-size: 14px;
    }
    .dark .tc {
        --tc-ink: #e6ebf0; --tc-muted: #9aa8b5; --tc-line: #243041; --tc-panel: #111827; --tc-band: #161f2e;
        --tc-field: #0b1220; --tc-field-off: #131c2b; --tc-brand: #38a9e8; --tc-brand-soft: #0f2c40;
        --tc-ok: #3cc28f; --tc-ok-soft: #10302a; --tc-warn: #e3a356; --tc-warn-soft: #33260f;
        --tc-bad: #f07167; --tc-bad-soft: #3a1714; --tc-idle: #3a4759;
    }

    /* Lock bar: always reachable while scrolling, carries Save. */
    .tc-lockbar {
        position: sticky; top: 72px; z-index: 5;
        display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        padding: 12px 16px; border: 1px solid var(--tc-line); border-radius: 10px; background: var(--tc-panel);
    }
    .tc-lockbar.is-open { border-color: var(--tc-warn); background: var(--tc-warn-soft); }
    .tc-lock-icon { flex: 0 0 auto; width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; background: var(--tc-band); color: var(--tc-muted); }
    .tc-lockbar.is-open .tc-lock-icon { background: var(--tc-panel); color: var(--tc-warn); }
    .tc-lock-text { flex: 1 1 260px; line-height: 1.4; }
    .tc-lock-text strong { display: block; font-size: 15px; font-weight: 600; }
    .tc-lock-text span { color: var(--tc-muted); font-size: 13px; }
    .tc-lock-actions { display: flex; gap: 8px; }

    /* Service card: the rail on the left is the state (ready, key missing, can't read, test result). */
    .tc-card {
        position: relative; overflow: hidden;
        border: 1px solid var(--tc-line); border-radius: 12px; background: var(--tc-panel);
    }
    .tc-card::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 4px; background: var(--tc-rail, var(--tc-idle)); transition: background .25s; }
    .tc-state-ready { --tc-rail: var(--tc-ok); }
    .tc-state-missing { --tc-rail: var(--tc-warn); }
    .tc-state-bad, .tc-state-failed { --tc-rail: var(--tc-bad); }
    .tc-state-passed { --tc-rail: var(--tc-ok); }

    .tc-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 16px 20px 16px 24px; background: var(--tc-band); border-bottom: 1px solid var(--tc-line); }
    .tc-mark { flex: 0 0 auto; width: 40px; height: 40px; border-radius: 10px; display: grid; place-items: center; background: var(--tc-brand-soft); color: var(--tc-brand); font-weight: 700; font-size: 14px; letter-spacing: -.01em; }
    .tc-title { flex: 1 1 220px; min-width: 0; }
    .tc-title h2 { margin: 0; font-size: 17px; font-weight: 600; line-height: 1.3; }
    .tc-title p { margin: 2px 0 0; color: var(--tc-muted); font-size: 13px; line-height: 1.4; }

    .tc-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; background: var(--tc-panel); border: 1px solid var(--tc-line); color: var(--tc-muted); }
    .tc-pill::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--tc-rail, var(--tc-idle)); }

    .tc-test {
        display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; border-radius: 8px;
        border: 1px solid var(--tc-brand); background: transparent; color: var(--tc-brand);
        font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;
    }
    .tc-test:hover:not(:disabled) { background: var(--tc-brand); color: #fff; }
    .tc-test:disabled { border-color: var(--tc-line); color: var(--tc-muted); cursor: not-allowed; }
    .tc-spin { width: 13px; height: 13px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: tc-spin .7s linear infinite; }
    @keyframes tc-spin { to { transform: rotate(360deg); } }

    .tc-result { display: flex; gap: 10px; align-items: flex-start; margin: 0; padding: 11px 20px 11px 24px; font-size: 13px; line-height: 1.45; border-bottom: 1px solid var(--tc-line); }
    .tc-result.is-ok { background: var(--tc-ok-soft); color: var(--tc-ok); }
    .tc-result.is-fail { background: var(--tc-bad-soft); color: var(--tc-bad); }
    .tc-result strong { font-weight: 600; }

    .tc-body { padding: 20px 20px 20px 24px; display: flex; flex-direction: column; gap: 18px; }
    .tc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 18px; }
    .tc-wide { grid-column: 1 / -1; }
    @media (max-width: 720px) { .tc-grid { grid-template-columns: 1fr; } }

    .tc-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .tc-label-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .tc-label { font-size: 13px; font-weight: 600; }
    .tc-input {
        width: 100%; padding: 8px 11px; border: 1px solid var(--tc-line); border-radius: 8px;
        background: var(--tc-field); color: var(--tc-ink); font: inherit; font-size: 14px; line-height: 1.4;
    }
    .tc-input::placeholder { color: var(--tc-muted); opacity: .8; }
    .tc-input:focus { outline: none; border-color: var(--tc-brand); box-shadow: 0 0 0 3px var(--tc-brand-soft); }
    .tc-input:disabled { background: var(--tc-field-off); color: var(--tc-muted); cursor: not-allowed; }
    .tc-help { margin: 0; color: var(--tc-muted); font-size: 12px; line-height: 1.45; min-height: 0; }
    .tc-help:empty { display: none; }
    .tc-changed { color: var(--tc-brand); font-weight: 600; }

    .tc-source { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px; }
    .tc-badge { padding: 1px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
    .tc-badge-saved { background: var(--tc-brand-soft); color: var(--tc-brand); }
    .tc-badge-env { background: var(--tc-band); color: var(--tc-muted); border: 1px solid var(--tc-line); }
    .tc-badge-none { background: var(--tc-warn-soft); color: var(--tc-warn); }
    .tc-badge-bad { background: var(--tc-bad-soft); color: var(--tc-bad); }
    .tc-ends { color: var(--tc-muted); font-variant-numeric: tabular-nums; }

    .tc-link { align-self: flex-start; padding: 0; border: 0; background: none; color: var(--tc-brand); font-size: 12px; font-weight: 600; cursor: pointer; }
    .tc-link:hover:not(:disabled) { text-decoration: underline; }
    .tc-link:disabled { display: none; }

    .tc-note { display: flex; gap: 8px; margin: 0; padding: 10px 12px; border-radius: 8px; background: var(--tc-band); color: var(--tc-muted); font-size: 12.5px; line-height: 1.45; }
    .tc-note svg { flex: 0 0 auto; margin-top: 1px; color: var(--tc-warn); }

    /* Email: which service sends, as a two-way switch. */
    .tc-switch-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding-top: 4px; border-top: 1px dashed var(--tc-line); padding-top: 18px; }
    .tc-switch { display: inline-flex; padding: 3px; border-radius: 10px; background: var(--tc-band); border: 1px solid var(--tc-line); }
    .tc-switch label { position: relative; cursor: pointer; }
    .tc-switch input { position: absolute; opacity: 0; pointer-events: none; }
    .tc-switch span { display: block; padding: 6px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--tc-muted); transition: background .15s, color .15s; }
    .tc-switch input:checked + span { background: var(--tc-panel); color: var(--tc-ink); box-shadow: 0 1px 2px rgba(16, 24, 40, .12); }
    .tc-switch input:focus-visible + span { outline: 2px solid var(--tc-brand); outline-offset: 1px; }
    .tc-switch input:disabled + span { cursor: not-allowed; }
    .tc-mailer { display: flex; flex-direction: column; gap: 14px; }
    .tc-mailer-hint { margin: 0; color: var(--tc-muted); font-size: 12.5px; }

    .tc-foot { color: var(--tc-muted); font-size: 12px; line-height: 1.5; padding: 0 4px; }
    .tc button:focus-visible, .tc a:focus-visible { outline: 2px solid var(--tc-brand); outline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) { .tc-spin { animation: none; } .tc-card::before, .tc-switch span { transition: none; } }

    /* Safety: on/off switch for a guard, list boxes, the "type to confirm" box. */
    .tc-toggle { position: relative; display: inline-flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .tc-toggle input { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .tc-toggle-track { position: relative; width: 44px; height: 24px; border-radius: 999px; background: var(--tc-bad); transition: background .2s; flex: 0 0 auto; }
    .tc-toggle-track::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(16, 24, 40, .25); transition: transform .2s; }
    .tc-toggle input:checked + .tc-toggle-track { background: var(--tc-ok); }
    .tc-toggle input:checked + .tc-toggle-track::after { transform: translateX(20px); }
    .tc-toggle input:focus-visible + .tc-toggle-track { outline: 2px solid var(--tc-brand); outline-offset: 2px; }
    .tc-toggle input:disabled + .tc-toggle-track { opacity: .55; cursor: not-allowed; }
    .tc-toggle.is-plain .tc-toggle-track { background: var(--tc-idle); }
    .tc-toggle.is-plain input:checked + .tc-toggle-track { background: var(--tc-brand); }
    .tc-guard-on { --tc-rail: var(--tc-ok); }
    .tc-guard-off { --tc-rail: var(--tc-bad); }
    .tc-guard-off .tc-head { background: var(--tc-bad-soft); }
    .tc-textarea { min-height: 84px; resize: vertical; font-variant-numeric: tabular-nums; }
    .tc-people { display: flex; flex-wrap: wrap; gap: 6px; }
    .tc-person { padding: 2px 9px; border-radius: 999px; background: var(--tc-band); border: 1px solid var(--tc-line); font-size: 12px; color: var(--tc-ink); }
    .tc-person.is-missing { background: var(--tc-warn-soft); border-color: transparent; color: var(--tc-warn); }
    .tc-options { display: flex; flex-direction: column; gap: 12px; padding-top: 16px; border-top: 1px dashed var(--tc-line); }
    .tc-confirm { display: flex; flex-direction: column; gap: 10px; padding: 16px 20px; border: 1px solid var(--tc-bad); border-radius: 12px; background: var(--tc-bad-soft); }
    .tc-confirm strong { color: var(--tc-bad); font-size: 15px; }
    .tc-confirm p { margin: 0; font-size: 13px; line-height: 1.45; }
    .tc-confirm .tc-input { max-width: 320px; letter-spacing: .02em; }
    @media (prefers-reduced-motion: reduce) { .tc-toggle-track, .tc-toggle-track::after { transition: none; } }

    /* A service Krayin uses failed (red) or was used without a key (amber): ServiceHealth. */
    .tc-state-failing { --tc-rail: var(--tc-bad); }
    .tc-state-failing .tc-head { background: var(--tc-bad-soft); }
    .tc-state-nokey { --tc-rail: var(--tc-warn); }
    .tc-state-nokey .tc-head { background: var(--tc-warn-soft); }
    .tc-problem { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; margin: 0; padding: 12px 20px 12px 24px; font-size: 13px; line-height: 1.45; border-bottom: 1px solid var(--tc-line); }
    .tc-problem.is-failed { background: var(--tc-bad-soft); color: var(--tc-bad); }
    .tc-problem.is-nokey { background: var(--tc-warn-soft); color: var(--tc-warn); }
    .tc-problem strong { font-weight: 700; }
    .tc-problem-when { opacity: .8; font-size: 12px; }
    .tc-problem p { flex: 1 0 100%; margin: 0; color: var(--tc-ink); white-space: pre-wrap; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
    .tc-field-problem .tc-input { border-color: var(--tc-bad); box-shadow: 0 0 0 3px var(--tc-bad-soft); }
    .tc-state-nokey .tc-field-problem .tc-input { border-color: var(--tc-warn); box-shadow: 0 0 0 3px var(--tc-warn-soft); }
    .tc-flag { margin-left: 8px; padding: 1px 8px; border-radius: 999px; background: var(--tc-bad-soft); color: var(--tc-bad); font-size: 11.5px; font-weight: 600; }
    .tc-state-nokey .tc-flag { background: var(--tc-warn-soft); color: var(--tc-warn); }

    /* ---- Settings screens (Company, Services, Area, Follow-ups, Emails), header and log ---- */
    .tc-top { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 18px 20px; border: 1px solid var(--tc-line); border-radius: 12px; background: linear-gradient(135deg, var(--tc-brand-soft), var(--tc-panel) 60%); }
    .tc-top-icon { flex: 0 0 auto; width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; background: var(--tc-brand); color: #fff; box-shadow: 0 4px 12px rgba(14, 144, 217, .25); }
    .tc-top-text { flex: 1 1 280px; min-width: 0; }
    .tc-top-text h1 { margin: 0; font-size: 20px; font-weight: 700; line-height: 1.25; letter-spacing: -.01em; }
    .tc-top-text p { margin: 4px 0 0; color: var(--tc-muted); font-size: 13px; line-height: 1.45; }
    .tc-crumb { display: block; margin-bottom: 2px; color: var(--tc-brand); font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .tc-alert { display: flex; gap: 10px; padding: 12px 16px; border: 1px solid var(--tc-bad); border-radius: 10px; background: var(--tc-bad-soft); color: var(--tc-bad); font-size: 13px; line-height: 1.5; }
    .tc-alert strong { display: block; margin-bottom: 2px; }

    .tc-form { display: flex; flex-direction: column; gap: 16px; }
    .tc-card.is-plain::before { display: none; }
    .tc-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px 18px; }
    @media (max-width: 720px) { .tc-grid-3 { grid-template-columns: 1fr; } }
    .tc-input.is-short { max-width: 220px; }
    select.tc-input { appearance: auto; cursor: pointer; }
    textarea.tc-input { resize: vertical; min-height: 72px; }
    .tc-sublabel { color: var(--tc-muted); font-size: 12px; font-weight: 500; }

    /* Number with its unit inside the box: [ 90 | min ] */
    .tc-unit { display: flex; align-items: stretch; max-width: 220px; border: 1px solid var(--tc-line); border-radius: 8px; background: var(--tc-field); overflow: hidden; }
    .tc-unit:focus-within { border-color: var(--tc-brand); box-shadow: 0 0 0 3px var(--tc-brand-soft); }
    .tc-unit .tc-input { border: 0; border-radius: 0; box-shadow: none !important; min-width: 0; }
    .tc-unit span { display: flex; align-items: center; padding: 0 11px; background: var(--tc-band); border-left: 1px solid var(--tc-line); color: var(--tc-muted); font-size: 12.5px; font-weight: 600; white-space: nowrap; }

    /* "Changed" badge + default + reset under a field */
    .tc-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0; font-size: 12px; color: var(--tc-muted); }
    .tc-meta-default { overflow-wrap: anywhere; }

    /* Chips: slot times, keywords, placeholders */
    .tc-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .tc-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; background: var(--tc-brand-soft); color: var(--tc-brand); font-size: 12.5px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .tc-chip.is-muted { background: var(--tc-band); color: var(--tc-muted); border: 1px solid var(--tc-line); }
    .tc-chip.is-bad { background: var(--tc-bad-soft); color: var(--tc-bad); }
    code.tc-chip { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; cursor: help; }

    /* Folded advanced card */
    details.tc-card > summary { list-style: none; cursor: pointer; }
    details.tc-card > summary::-webkit-details-marker { display: none; }
    details.tc-card:not([open]) > summary.tc-head { border-bottom: 0; }
    .tc-chevron { flex: 0 0 auto; color: var(--tc-muted); transition: transform .2s; }
    details.tc-card[open] .tc-chevron { transform: rotate(180deg); }

    /* Amber note (time zone change) */
    .tc-warnbox { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border: 1px solid var(--tc-warn); border-radius: 10px; background: var(--tc-warn-soft); color: var(--tc-ink); font-size: 13px; line-height: 1.45; }
    .tc-warnbox input { margin-top: 3px; accent-color: var(--tc-warn); }

    /* Small row editors: rush hours, services */
    .tc-rows { display: flex; flex-direction: column; gap: 8px; }
    .tc-rowline { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 8px 10px; border: 1px solid var(--tc-line); border-radius: 10px; background: var(--tc-band); }
    .tc-rowline .tc-input { width: auto; }
    .tc-arrow { color: var(--tc-muted); font-size: 13px; }
    .tc-remove { display: inline-flex; align-items: center; gap: 6px; margin-left: auto; color: var(--tc-muted); font-size: 12px; cursor: pointer; }
    .tc-remove input { accent-color: var(--tc-bad); }
    .tc-add { align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border: 1px dashed var(--tc-brand); border-radius: 8px; background: transparent; color: var(--tc-brand); font-size: 13px; font-weight: 600; cursor: pointer; }
    .tc-add:hover { background: var(--tc-brand-soft); }

    .tc-svc { position: relative; display: grid; grid-template-columns: 56px minmax(0, 1fr); gap: 14px; padding: 14px 16px; border: 1px solid var(--tc-line); border-radius: 12px; background: var(--tc-panel); transition: border-color .15s, box-shadow .15s; }
    .tc-svc:hover { border-color: var(--tc-brand); box-shadow: 0 2px 10px rgba(16, 24, 40, .06); }
    .tc-svc-order { display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .tc-svc-order .tc-input { width: 56px; text-align: center; font-weight: 700; padding: 8px 4px; }
    .tc-svc-order small { color: var(--tc-muted); font-size: 11px; }
    .tc-svc-fields { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
    .tc-svc-fields .tc-wide { grid-column: 1 / -1; }
    @media (max-width: 860px) { .tc-svc-fields { grid-template-columns: 1fr; } }
    .tc-svc.is-removed { opacity: .5; }
    .tc-svc.is-removed .tc-input { text-decoration: line-through; }

    /* Follow-up timeline */
    .tc-steps { display: flex; align-items: flex-start; gap: 0; overflow-x: auto; padding: 4px 2px 2px; }
    .tc-step { flex: 1 1 0; min-width: 110px; position: relative; text-align: center; }
    .tc-step::before { content: ""; position: absolute; top: 15px; left: -50%; right: 50%; height: 2px; background: var(--tc-line); }
    .tc-step:first-child::before { display: none; }
    .tc-step-dot { position: relative; z-index: 1; width: 32px; height: 32px; margin: 0 auto 6px; border-radius: 50%; display: grid; place-items: center; background: var(--tc-brand-soft); color: var(--tc-brand); font-size: 12px; font-weight: 700; border: 2px solid var(--tc-panel); }
    .tc-step.is-end .tc-step-dot { background: var(--tc-bad-soft); color: var(--tc-bad); }
    .tc-step strong { display: block; font-size: 13px; }
    .tc-step span { color: var(--tc-muted); font-size: 12px; font-variant-numeric: tabular-nums; }

    /* Signature / SMS previews */
    .tc-preview { padding: 14px 16px; border: 1px dashed var(--tc-line); border-radius: 10px; background: var(--tc-band); font-size: 13px; line-height: 1.5; }
    .tc-preview-label { display: block; margin-bottom: 6px; color: var(--tc-muted); font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .tc-swatch { display: inline-flex; align-items: center; gap: 10px; }
    .tc-swatch input[type=color] { width: 44px; height: 36px; padding: 2px; border: 1px solid var(--tc-line); border-radius: 8px; background: var(--tc-field); cursor: pointer; }
    .tc-phone { max-width: 340px; padding: 14px; border-radius: 18px; background: var(--tc-band); border: 1px solid var(--tc-line); }
    .tc-bubble { display: inline-block; max-width: 100%; padding: 10px 13px; border-radius: 16px 16px 16px 4px; background: var(--tc-panel); border: 1px solid var(--tc-line); font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; box-shadow: 0 1px 2px rgba(16, 24, 40, .06); }
    .tc-count { display: flex; align-items: center; gap: 8px; margin-top: 8px; color: var(--tc-muted); font-size: 12px; }
    .tc-meter { flex: 1 1 auto; height: 4px; border-radius: 999px; background: var(--tc-line); overflow: hidden; }
    .tc-meter i { display: block; height: 100%; background: var(--tc-ok); transition: width .2s; }
    .tc-meter.is-long i { background: var(--tc-warn); }

    /* Emails: editor + sticky preview */
    .tc-split { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
    .tc-sticky { position: sticky; top: 72px; }
    @media (max-width: 1024px) { .tc-split { grid-template-columns: 1fr; } .tc-sticky { position: static; } }
    .tc-frame { width: 100%; height: 720px; border: 1px solid var(--tc-line); border-radius: 10px; background: #fff; }

    /* Save bar, stuck to the bottom while editing */
    .tc-savebar { position: sticky; bottom: 12px; z-index: 5; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border: 1px solid var(--tc-line); border-radius: 12px; background: var(--tc-panel); box-shadow: 0 8px 24px rgba(16, 24, 40, .12); }
    .tc-savebar-text { flex: 1 1 200px; display: flex; align-items: center; gap: 8px; color: var(--tc-muted); font-size: 13px; }
    .tc-savebar-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--tc-ok); }
    .tc-savebar.is-dirty { border-color: var(--tc-brand); }
    .tc-savebar.is-dirty .tc-savebar-dot { background: var(--tc-warn); }
    .tc-savebar.is-dirty .tc-savebar-text { color: var(--tc-ink); font-weight: 600; }

    /* Change log as a timeline */
    .tc-timeline { margin: 0; padding: 0; list-style: none; }
    .tc-timeline li { position: relative; display: grid; grid-template-columns: 14px minmax(0, 1fr); gap: 12px; padding: 0 0 16px; }
    .tc-timeline li::before { content: ""; position: absolute; left: 6px; top: 14px; bottom: 0; width: 2px; background: var(--tc-line); }
    .tc-timeline li:last-child { padding-bottom: 0; }
    .tc-timeline li:last-child::before { display: none; }
    .tc-tl-dot { width: 14px; height: 14px; margin-top: 3px; border-radius: 50%; background: var(--tc-brand-soft); border: 3px solid var(--tc-brand); }
    .tc-tl-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
    .tc-tl-head strong { font-size: 13.5px; }
    .tc-tl-when { color: var(--tc-muted); font-size: 12px; }
    .tc-diff { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 4px; font-size: 12.5px; }
    .tc-diff-old { padding: 1px 8px; border-radius: 6px; background: var(--tc-bad-soft); color: var(--tc-bad); text-decoration: line-through; overflow-wrap: anywhere; }
    .tc-diff-new { padding: 1px 8px; border-radius: 6px; background: var(--tc-ok-soft); color: var(--tc-ok); overflow-wrap: anywhere; }
    .tc-empty { margin: 0; color: var(--tc-muted); font-size: 13px; }
    @media (prefers-reduced-motion: reduce) { .tc-chevron, .tc-svc, .tc-meter i { transition: none; } }

    /* ---- Workers, booking link, stage rules, import result ---- */
    .tc-top-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .tc-stats { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .tc-stat { display: inline-flex; align-items: baseline; gap: 6px; padding: 4px 11px; border-radius: 999px; background: var(--tc-panel); border: 1px solid var(--tc-line); font-size: 12px; color: var(--tc-muted); }
    .tc-stat b { color: var(--tc-ink); font-size: 14px; }

    .tc-table-wrap { overflow-x: auto; }
    .tc-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .tc-table th { text-align: left; padding: 10px 16px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--tc-muted); background: var(--tc-band); border-bottom: 1px solid var(--tc-line); white-space: nowrap; }
    .tc-table td { padding: 12px 16px; border-bottom: 1px solid var(--tc-line); vertical-align: middle; }
    .tc-table tbody tr:last-child td { border-bottom: 0; }
    .tc-table tbody tr:hover td { background: var(--tc-band); }
    .tc-table tr.is-inactive td { opacity: .6; }
    .tc-right { text-align: right; }

    .tc-avatar { flex: 0 0 auto; width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; background: var(--tc-brand); color: #fff; font-size: 13px; font-weight: 700; letter-spacing: .02em; box-shadow: 0 0 0 3px var(--tc-panel), 0 0 0 4px var(--tc-line); }
    .tc-avatar.is-lg { width: 46px; height: 46px; font-size: 15px; }
    .tc-who { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .tc-who strong { display: block; font-size: 14px; }
    .tc-sub { display: block; color: var(--tc-muted); font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }

    .tc-week { display: inline-flex; gap: 3px; }
    .tc-day { width: 27px; padding: 3px 0; border-radius: 6px; text-align: center; font-size: 10.5px; font-weight: 700; background: var(--tc-ok-soft); color: var(--tc-ok); cursor: default; }
    .tc-day.is-off { background: var(--tc-band); color: var(--tc-muted); text-decoration: line-through; }

    .tc-status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .tc-status::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
    .tc-status.is-on { background: var(--tc-ok-soft); color: var(--tc-ok); }
    .tc-status.is-off { background: var(--tc-band); color: var(--tc-muted); border: 1px solid var(--tc-line); }
    .tc-status.is-warn { background: var(--tc-warn-soft); color: var(--tc-warn); }
    .tc-prio { display: inline-grid; place-items: center; min-width: 26px; height: 26px; padding: 0 6px; border-radius: 8px; background: var(--tc-band); border: 1px solid var(--tc-line); font-size: 12.5px; font-weight: 700; }
    .tc-prio.is-top { background: var(--tc-brand-soft); border-color: transparent; color: var(--tc-brand); }

    .tc-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid var(--tc-line); border-radius: 8px; background: var(--tc-panel); color: var(--tc-ink); font-size: 12.5px; font-weight: 600; white-space: nowrap; cursor: pointer; text-decoration: none; transition: border-color .15s, color .15s; }
    .tc-btn:hover { border-color: var(--tc-brand); color: var(--tc-brand); }
    .tc-btn.is-danger { color: var(--tc-bad); }
    .tc-btn.is-danger:hover { border-color: var(--tc-bad); background: var(--tc-bad-soft); }
    .tc-empty-state { padding: 44px 20px; text-align: center; color: var(--tc-muted); }
    .tc-empty-state strong { display: block; margin: 10px 0 4px; color: var(--tc-ink); font-size: 15px; }

    /* Weekly hours: one row per day */
    .tc-days { display: flex; flex-direction: column; gap: 6px; }
    .tc-dayrow { display: grid; grid-template-columns: 120px auto auto auto 1fr; align-items: center; gap: 10px; padding: 8px 12px; border: 1px solid var(--tc-line); border-radius: 10px; background: var(--tc-panel); }
    .tc-dayrow strong { font-size: 13.5px; }
    .tc-dayrow .tc-input { width: auto; padding: 6px 9px; }
    .tc-dayrow .tc-status { justify-self: end; }
    .tc-dayrow.is-off { background: var(--tc-band); }
    @media (max-width: 640px) { .tc-dayrow { grid-template-columns: 1fr 1fr; } .tc-dayrow strong { grid-column: 1 / -1; } .tc-dayrow .tc-arrow { display: none; } }

    /* Entries that can be added/removed (time off, holidays) */
    .tc-entry { position: relative; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding: 14px 16px; border: 1px solid var(--tc-line); border-left: 4px solid var(--tc-warn); border-radius: 10px; background: var(--tc-panel); }
    .tc-entry.is-holiday { grid-template-columns: minmax(0, 1fr) minmax(0, 2fr) auto; border-left-color: var(--tc-brand); align-items: end; }
    .tc-entry .tc-entry-foot { grid-column: 1 / -1; display: flex; justify-content: flex-end; }
    @media (max-width: 720px) { .tc-entry, .tc-entry.is-holiday { grid-template-columns: 1fr; } }

    .tc-colorpick { display: flex; align-items: center; gap: 10px; }
    .tc-colorpick input[type=color] { width: 44px; height: 38px; padding: 2px; border: 1px solid var(--tc-line); border-radius: 8px; background: var(--tc-field); cursor: pointer; flex: 0 0 auto; }
    .tc-colorpick .tc-input { max-width: 140px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; text-transform: uppercase; }

    /* Coloured notes */
    .tc-box { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.5; border: 1px solid; }
    .tc-box svg { flex: 0 0 auto; margin-top: 2px; }
    .tc-box.is-info { background: var(--tc-brand-soft); border-color: transparent; color: var(--tc-ink); }
    .tc-box.is-info svg { color: var(--tc-brand); }
    .tc-box.is-warn { background: var(--tc-warn-soft); border-color: var(--tc-warn); color: var(--tc-ink); }
    .tc-box.is-warn svg { color: var(--tc-warn); }
    .tc-box.is-ok { background: var(--tc-ok-soft); border-color: var(--tc-ok); color: var(--tc-ink); }
    .tc-box.is-ok svg { color: var(--tc-ok); }
    .tc-box.is-bad { background: var(--tc-bad-soft); border-color: var(--tc-bad); color: var(--tc-ink); }
    .tc-box.is-bad svg { color: var(--tc-bad); }
    .tc-box ul { margin: 4px 0 0 18px; list-style: disc; }

    /* Booking link: ask list, suggestion result, draft link */
    .tc-ask { border: 1px solid var(--tc-line); border-radius: 10px; overflow: hidden; }
    .tc-ask label { display: flex; align-items: center; gap: 12px; padding: 10px 14px; cursor: pointer; font-size: 13.5px; }
    .tc-ask label + label { border-top: 1px solid var(--tc-line); }
    .tc-ask label:hover { background: var(--tc-band); }
    .tc-ask input { accent-color: var(--tc-brand); width: 16px; height: 16px; }
    .tc-ask-name { min-width: 130px; font-weight: 600; }
    .tc-ask-current { margin-left: auto; color: var(--tc-muted); font-size: 12.5px; text-align: right; overflow-wrap: anywhere; }
    .tc-suggest { display: flex; flex-direction: column; gap: 10px; font-size: 13px; }
    .tc-suggest:empty { display: none; }
    .tc-option { padding: 12px 14px; border: 1px solid var(--tc-line); border-left: 4px solid var(--tc-opt, var(--tc-brand)); border-radius: 10px; background: var(--tc-panel); }
    .tc-option-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 10px; }
    .tc-option-head b { font-size: 14px; }
    .tc-option ul { margin: 8px 0 0; padding: 0; list-style: none; font-size: 12px; color: var(--tc-muted); }
    .tc-option li { padding: 2px 0; font-variant-numeric: tabular-nums; }
    .tc-option li.is-new { color: var(--tc-ok); font-weight: 700; }
    .tc-late { color: var(--tc-bad); font-weight: 700; }
    .tc-linkbox { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .tc-linkbox .tc-input { flex: 1 1 260px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
    .tc-slots { display: flex; flex-direction: column; gap: 8px; }
    .tc-slot { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid var(--tc-line); border-radius: 10px; background: var(--tc-band); }
    .tc-slot .tc-input { width: auto; }
    .tc-slot input[type=datetime-local] { flex: 1 1 220px; }
    .tc-slot-num { flex: 0 0 auto; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: var(--tc-brand-soft); color: var(--tc-brand); font-size: 12px; font-weight: 700; counter-increment: tc-slot; }
    .tc-slot-num::before { content: counter(tc-slot); }
    .tc-slots { counter-reset: tc-slot; }

    /* Stage rules */
    .tc-stage { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto minmax(0, 1.2fr); align-items: center; gap: 12px; padding: 10px 14px; border: 1px solid var(--tc-line); border-radius: 10px; background: var(--tc-panel); }
    .tc-stage-pos { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; background: var(--tc-band); color: var(--tc-muted); font-size: 12px; font-weight: 700; }
    .tc-stage-name { font-weight: 600; }
    .tc-stage.is-closing { background: var(--tc-band); }
    @media (max-width: 720px) { .tc-stage { grid-template-columns: 34px 1fr; } .tc-stage > :nth-child(n+3) { grid-column: 1 / -1; } }
    .tc-roles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .tc-role { padding: 12px 14px; border: 1px solid var(--tc-line); border-radius: 10px; background: var(--tc-band); font-size: 13px; line-height: 1.45; }
    .tc-role strong { display: block; margin-bottom: 2px; }
    @media (max-width: 720px) { .tc-roles { grid-template-columns: 1fr; } }

    /* Leads > Waiting for booking: customer | times | follow-ups | actions */
    .tc-svc.tc-wait { grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 1fr) 150px; align-items: start; }
    @media (max-width: 1100px) { .tc-svc.tc-wait { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 640px) { .tc-svc.tc-wait { grid-template-columns: 1fr; } }

    /* Pipeline event log (Leads > Event log, Events card) */
    .tc-filters { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) auto; gap: 10px; align-items: end; }
    @media (max-width: 900px) { .tc-filters { grid-template-columns: 1fr 1fr; } }
    .tc-statgrid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
    @media (max-width: 900px) { .tc-statgrid { grid-template-columns: 1fr 1fr; } }
    .tc-statcard { padding: 14px 16px; border: 1px solid var(--tc-line); border-radius: 12px; background: var(--tc-panel); }
    .tc-statcard b { display: block; font-size: 24px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .tc-statcard span { color: var(--tc-muted); font-size: 12px; font-weight: 600; }
    .tc-events { margin: 0; padding: 0; list-style: none; }
    .tc-event { position: relative; display: grid; grid-template-columns: 30px minmax(0, 1fr); gap: 12px; padding: 0 0 14px; }
    .tc-event::before { content: ""; position: absolute; left: 14px; top: 30px; bottom: 0; width: 2px; background: var(--tc-line); }
    .tc-event:last-child { padding-bottom: 0; }
    .tc-event:last-child::before { display: none; }
    .tc-event-icon { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; background: var(--tc-brand-soft); color: var(--tc-brand); }
    .tc-event.is-ok .tc-event-icon { background: var(--tc-ok-soft); color: var(--tc-ok); }
    .tc-event.is-warn .tc-event-icon { background: var(--tc-warn-soft); color: var(--tc-warn); }
    .tc-event.is-bad .tc-event-icon { background: var(--tc-bad-soft); color: var(--tc-bad); }
    .tc-event-body { min-width: 0; padding-top: 4px; }
    .tc-event-head { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; }
    .tc-event-type { font-size: 12px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--tc-muted); }
    .tc-event-when { font-size: 12px; color: var(--tc-muted); font-variant-numeric: tabular-nums; }
    .tc-event-summary { margin: 3px 0 0; font-size: 13.5px; line-height: 1.45; overflow-wrap: anywhere; }
    .tc-event-links { display: flex; flex-wrap: wrap; gap: 10px; margin: 3px 0 0; font-size: 12px; }
    .tc-event-links a { color: var(--tc-brand); font-weight: 600; text-decoration: none; }
    .tc-event-links a:hover { text-decoration: underline; }
</style>
@endPushOnce
