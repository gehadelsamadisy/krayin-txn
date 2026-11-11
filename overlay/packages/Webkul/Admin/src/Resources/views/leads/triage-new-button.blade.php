{{-- Leads list header (hook admin.leads.index.header.right.before, AppServiceProvider):
     "Import leads (CSV)" and "Check new leads". --}}
@if (bouncer()->hasPermission('leads.edit'))
    {{-- The header spreads its children apart; the auto margin keeps these next to "Create Lead". --}}
    <div style="margin-left: auto; margin-right: 10px; display: flex; gap: 10px; align-items: center;">
        {{-- Clicking the label opens the browser's file window; choosing a file shows the
             "Importing leads" panel and submits the form (script below). --}}
        <form id="txn-lead-import-form" method="POST" action="{{ route('admin.leads.import_csv') }}" enctype="multipart/form-data">
            @csrf

            <label class="secondary-button" for="txn-lead-csv" style="cursor: pointer;" title="Choose a Yelp/Angi lead CSV: new leads are added, existing ones (same [TXN-…] tag) are updated, never duplicated">
                Import leads (CSV)
            </label>

            <input type="file" id="txn-lead-csv" name="file" accept=".csv,text/csv" style="display: none;">
        </form>

        <form method="POST" action="{{ route('admin.leads.triage_new') }}">
            @csrf

            <button type="submit" class="secondary-button" title="Check every lead in Raw Lead: clean the data, classify the service, check the location">
                Check new leads
            </button>
        </form>
    </div>

    {{-- Plain script outside Vue (same pattern as the worker pages). The panel is added to <body>,
         outside the Vue app, and stays until the import's response replaces the page. The server
         sends no progress while it works, so the bar is an estimate from the elapsed time: it slows
         down near the end and never claims to be done. --}}
    @pushOnce('scripts')
        <script>
            (() => {
                const panelHtml = `
                    <div style="position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center;
                                background: rgba(17, 24, 39, 0.45);">
                        <div role="status" aria-live="polite" style="width: min(440px, calc(100vw - 32px)); padding: 20px 22px; border-radius: 10px;
                                    background: #fff; color: #1f2937; box-shadow: 0 20px 45px rgba(0,0,0,.25); font-size: 14px; line-height: 1.5;">
                            <div style="font-size: 16px; font-weight: 600;">Importing leads…</div>
                            <div data-file style="margin-top: 2px; color: #4b5563; overflow-wrap: anywhere;"></div>
                            <div style="margin-top: 14px; height: 8px; border-radius: 999px; background: #e5e7eb; overflow: hidden;">
                                <div data-bar style="height: 100%; width: 3%; border-radius: 999px; background: #0E90D9; transition: width .4s ease;"></div>
                            </div>
                            <div style="margin-top: 10px; display: flex; justify-content: space-between; gap: 12px; color: #4b5563;">
                                <span data-step>Uploading the file</span>
                                <span data-time style="font-variant-numeric: tabular-nums;">0:00</span>
                            </div>
                            <div style="margin-top: 10px; font-size: 13px; color: #6b7280;">
                                New and changed leads also get the lead check, which can take a few minutes for a big file.
                                Keep this page open; the result appears when it's done.
                            </div>
                        </div>
                    </div>`;

                const showPanel = (fileName) => {
                    const panel = document.createElement('div');
                    panel.innerHTML = panelHtml;
                    panel.querySelector('[data-file]').textContent = fileName;
                    document.body.appendChild(panel);

                    const bar = panel.querySelector('[data-bar]');
                    const step = panel.querySelector('[data-step]');
                    const time = panel.querySelector('[data-time]');
                    const started = Date.now();

                    setInterval(() => {
                        const seconds = (Date.now() - started) / 1000;
                        // Fast at first, then slower: about 60% after 30 s, never past 95%.
                        bar.style.width = (3 + 92 * (1 - Math.exp(-seconds / 33))).toFixed(1) + '%';
                        step.textContent = seconds < 2 ? 'Uploading the file' : 'Saving leads and running the lead check';
                        time.textContent = Math.floor(seconds / 60) + ':' + String(Math.floor(seconds % 60)).padStart(2, '0');
                    }, 250);
                };

                document.addEventListener('change', (event) => {
                    const input = event.target;

                    if (! (input instanceof HTMLInputElement) || input.id !== 'txn-lead-csv' || ! input.files.length) {
                        return;
                    }

                    showPanel(input.files[0].name);
                    input.form.submit();
                });
            })();
        </script>
    @endPushOnce
@endif
