<?php
/**
 * core/pos_nav.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — POS Navigation Reorganization.
 *
 * One small, testable source of truth for the POS hub's card list, so
 * app/bms/pos/pos_dashboard.php can loop over it to render cards instead of
 * header.php looping over it to render dropdown items. See the plan's "The
 * hub's cards, and where each one goes" section for the reasoning behind
 * each entry.
 */

require_once __DIR__ . '/permissions.php';

if (!function_exists('posNavGroups')) {
    /**
     * Returns the gated list of POS hub cards for the current session.
     * A card whose gate the current user doesn't hold is simply absent from
     * the returned array — not present-but-disabled — so a template can
     * render every returned entry unconditionally.
     *
     * @return array<int, array{key:string, label:string, description:string, url:string, primary:bool}>
     */
    function posNavGroups(): array
    {
        $groups = [];

        // Always present, rendered first and visually primary.
        $groups[] = [
            'key'         => 'terminal',
            'label'       => t('Open Terminal'),
            'description' => t('Start selling at the POS terminal.'),
            'url'         => 'pos',
            'primary'     => true,
        ];

        // Always present.
        $groups[] = [
            'key'         => 'shift_history',
            'label'       => t('Shift History'),
            'description' => t('Past and active shifts, with Z-Report drill-through.'),
            'url'         => 'pos/shifts',
            'primary'     => false,
        ];

        // Gated pos_advanced — genuinely absent otherwise, not hidden.
        if (canView('pos_advanced')) {
            $groups[] = [
                'key'         => 'catalog_setup',
                'label'       => t('Catalog Setup'),
                'description' => t('Price Groups and other catalog-level POS configuration.'),
                'url'         => 'pos/price-groups',
                'primary'     => false,
            ];
        }

        // Gated restaurant_pos — genuinely absent otherwise, not hidden.
        if (canView('restaurant_pos')) {
            $groups[] = [
                'key'         => 'restaurant',
                'label'       => t('Restaurant'),
                'description' => t('Floors & Tables, Kitchen Display, Modifier Groups and Reservations.'),
                'url'         => 'restaurant',
                'primary'     => false,
            ];
        }

        // Always present, small/secondary — a shortcut, not a relocation;
        // the page itself stays filed under System > Settings.
        $groups[] = [
            'key'         => 'settings',
            'label'       => t('Settings'),
            'description' => t('POS configuration: registers, receipts, loyalty.'),
            'url'         => 'pos_config_settings',
            'primary'     => false,
        ];

        return $groups;
    }
}
