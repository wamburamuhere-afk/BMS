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

        // 'terminal' (-> pos) and 'shift_history' (-> pos/shifts) used to be
        // the first two cards here, but pos_dashboard.php's own header row
        // already links to both ("Open POS" / "Shift History"), so having
        // them again below was a reported duplicate with no distinct
        // purpose. The header row is now the one place for those two;
        // this hub covers only the destinations that aren't already there.

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

if (!function_exists('restaurantSubHubCards')) {
    /**
     * The Restaurant sub-hub's 5 destination cards — the single source shared
     * by app/bms/restaurant/index.php (the full-page sub-hub) and
     * pos_dashboard.php's "Restaurant" popup (2026-09-12: clicking the hub's
     * Restaurant card now opens this list in a modal instead of navigating
     * away to a full page first, matching the product owner's "professional
     * popup, then land on the specific page" request). Not gated here —
     * both callers only ever show this list after their own
     * canView('restaurant_pos') check already passed.
     *
     * @return array<int, array{icon:string, label:string, description:string, url:string}>
     */
    function restaurantSubHubCards(): array
    {
        return [
            ['icon' => 'bi-diagram-3',      'label' => t('Floors & Tables'), 'description' => t('Define dining floors and their tables.'), 'url' => 'restaurant/floors'],
            ['icon' => 'bi-egg-fried',      'label' => t('Kitchen Display'), 'description' => t('Live kitchen queue — advance tickets as they cook.'), 'url' => 'restaurant/kitchen-dashboard'],
            ['icon' => 'bi-list-check',     'label' => t('Modifier Group'),  'description' => t('Add-on/option groups linked to menu items.'), 'url' => 'restaurant/modifier-group'],
            ['icon' => 'bi-calendar-check', 'label' => t('Reservations'),    'description' => t('Book and manage table reservations.'), 'url' => 'restaurant/reservations'],
            ['icon' => 'bi-tags',           'label' => t('Menu Type'),       'description' => t('Categorize menu items (uses the shared product categories).'), 'url' => 'restaurant/menu-type'],
        ];
    }
}
