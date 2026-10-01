<?php
/**
 * core/mm_nav.php
 *
 * Mobile Money display-mode helpers.
 *
 * Simple Mode (default ON) — no GL posting required. Transactions are
 * saved as status='posted' with journal_entry_id=NULL. All operational
 * metrics (dashboard KPIs, shift summaries, reports) work normally; the
 * canonical double-entry ledger is simply not touched.
 *
 * Advanced/GL Mode (Simple Mode OFF, set by superadmin) — full double-
 * entry posting via core/mm_posting.php. Requires:
 *   - mm_networks.float_account_id set per network
 *   - mm_gl_cash_float in system_settings (set by the GL accounts migration)
 */

if (!function_exists('mmSimpleModeEnabled')) {
    /**
     * Returns true when this tenant is running MM Simple Mode (no GL).
     * Default is true so any tenant that has not been explicitly configured
     * starts in simple mode and never hits a GL error.
     */
    function mmSimpleModeEnabled(): bool
    {
        return get_setting('mm_simple_mode', '1') === '1';
    }
}
