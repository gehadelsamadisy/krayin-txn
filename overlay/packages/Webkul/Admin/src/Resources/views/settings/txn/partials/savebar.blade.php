{{-- Save bar at the bottom of a settings form, stuck to the screen while editing. Shows "Unsaved changes" once something is edited. Data: $canEdit, optional $resetLabel, $resetConfirm. --}}
@if ($canEdit)
    <div class="tc-savebar" data-savebar>
        <span class="tc-savebar-text"><span class="tc-savebar-dot"></span><span data-savebar-text>All changes saved</span></span>
        <button type="submit" name="reset" value="all" formnovalidate class="secondary-button" onclick="return confirm(@js($resetConfirm ?? 'Put every setting on this page back to its default?'))">{{ $resetLabel ?? 'Reset this page to defaults' }}</button>
        <button type="submit" class="primary-button">Save</button>
    </div>

    @pushOnce('scripts')
        <script>
            document.querySelectorAll('[data-savebar]').forEach((bar) => {
                const form = bar.closest('form');
                const mark = () => {
                    bar.classList.add('is-dirty');
                    bar.querySelector('[data-savebar-text]').textContent = 'Unsaved changes';
                };

                form?.addEventListener('input', mark);
                form?.addEventListener('change', mark);
            });
        </script>
    @endPushOnce
@endif
