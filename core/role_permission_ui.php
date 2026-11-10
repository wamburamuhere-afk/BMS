<?php
/**
 * core/role_permission_ui.php — what the Roles & Permissions matrix shows,
 * and how saving it treats rows it did not show.
 *
 * Display-only: nothing here changes who can open a page. Access stays with
 * canView()/tenantModuleAllowsPage().
 */

require_once __DIR__ . '/feature_registry.php';

if (!function_exists('rolePermissionRelevantModules')) {
    /**
     * Always-on page_keys that only mean something when a module is active
     * (any-of). They stay ungated for access (see the documented always-on list
     * in tests/test_feature_registry_cli.php); this only hides the checkbox.
     */
    function rolePermissionRelevantModules(): array
    {
        return [
            'color_settings'      => ['sales', 'procurement'], // styles only sales/purchase documents
            'payment_create'      => ['sales'],                // records payment against an invoice
            'attendance_settings' => ['hr'],
            'policy_management'   => ['hr'],
            'zoom_settings'       => ['hr'],                   // Zoom backs HR meetings
            'sms_templates'       => ['communication'],
        ];
    }
}

if (!function_exists('rolePermissionRetiredKeys')) {
    /**
     * Pages with no effect anywhere; hidden from the matrix, existing grants kept.
     *
     * audit_report / activity_log: activity history is controlled by ONE box,
     * "Audit Logs" (audit_logs) — the dashboard's Recent Activities, the Audit
     * Logs page, the Activity Log page and the old Audit Report link (which only
     * redirects to Audit Logs) all check it. Showing three boxes for the same
     * data let an admin untick one and still leave the data visible.
     */
    function rolePermissionRetiredKeys(): array
    {
        return [
            'tax_settings', 'audit_report', 'activity_log',
            // Mobile Money lives only in LMS now: no BMS page reads these rights any more.
            'mm_agents', 'mm_commission_rates', 'mm_commissions', 'mm_compliance', 'mm_dashboard', 'mm_float',
            'mm_networks', 'mm_reconciliation', 'mm_reports', 'mm_shifts', 'mm_transactions',
        ];
    }
}

if (!function_exists('rolePermissionRestaurantKeys')) {
    /** The rows that exist only for a restaurant: whatever the Restaurant POS module owns. */
    function rolePermissionRestaurantKeys(): array
    {
        return bmsFeatureRegistry()['restaurant_pos']['page_keys'] ?? [];
    }
}

if (!function_exists('rolePermissionShopMode')) {
    /**
     * What kind of shops this company runs, for the Roles & Permissions matrix only:
     *
     *  'retail'     — Restaurant POS is off, or it is on but no shop is set to Restaurant/Hybrid.
     *                 Restaurant rows are left out (same rule as the ready-made restaurant roles).
     *  'mixed'      — at least one Restaurant/Hybrid shop alongside Retail/Hybrid ones (or anything we
     *                 cannot tell for sure). Everything shows, restaurant rows in their own tab.
     *  'restaurant' — Smart POS with EVERY active shop set to Restaurant (restaurantOnlyCompany's rule).
     *                 Shared rows are described in restaurant words.
     *
     * Any doubt (schema missing, query error) answers 'mixed', which is how the page behaved before.
     */
    function rolePermissionShopMode(PDO $pdo, bool $fresh = false): string
    {
        if (!tenantFeatureEnabled('restaurant_pos')) return 'retail';

        static $shops = [];
        $k = spl_object_id($pdo);
        if ($fresh || !array_key_exists($k, $shops)) {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM warehouses LIKE 'pos_mode'")->fetch()) {
                    $shops[$k] = null;
                } else {
                    $shops[$k] = $pdo->query("SELECT
                            SUM(pos_mode IN ('restaurant','hybrid'))                   AS any_rest,
                            SUM(status = 'active')                                     AS active_total,
                            SUM(status = 'active' AND pos_mode = 'restaurant')         AS active_rest
                        FROM warehouses")->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            } catch (Throwable $e) {
                $shops[$k] = null;
            }
        }
        $smartPos = function_exists('get_setting') && get_setting('pos_simple_mode', '0') === '1';
        return rolePermissionShopModeFrom($shops[$k], $smartPos);
    }
}

if (!function_exists('rolePermissionShopModeFrom')) {
    /**
     * The decision itself, from the shop counts (Restaurant POS already known to be on).
     * @param array{any_rest:mixed, active_total:mixed, active_rest:mixed}|null $s  null = could not read
     */
    function rolePermissionShopModeFrom(?array $s, bool $smartPos): string
    {
        if ($s === null) return 'mixed';

        // Same test as rofsHasRestaurantShop() — the roles list and the rows below it always agree.
        if ((int)$s['any_rest'] === 0) return 'retail';

        if ($smartPos && (int)$s['active_total'] > 0 && (int)$s['active_rest'] === (int)$s['active_total']) {
            return 'restaurant';
        }
        return 'mixed';
    }
}

if (!function_exists('rolePermissionShopText')) {
    /**
     * Restaurant wording for a row, or null to show the row exactly as stored.
     *
     * @return array{name: ?string, text: string, replace: bool}|null
     *   name    — a restaurant name for the row (null = keep the stored name)
     *   text    — what the row does in a restaurant (English source; the page passes it through t())
     *   replace — true: show text INSTEAD of the stored description; false: show it as an extra note
     *
     * 'retail' never gets anything: a retail company sees every row as before.
     */
    function rolePermissionShopText(string $pageKey, string $mode): ?array
    {
        if ($mode !== 'mixed' && $mode !== 'restaurant') return null;

        // Restaurant-only rows: their stored text is old (the kitchen has its own row now) — always replaced.
        $restaurantRows = [
            'restaurant_pos' => ['Restaurant Menu & Set-up',
                'View: open the Menu and the restaurant pages. Create: add reservations. Edit: change dishes, recipes, add-on options, tables, kitchen stations, the online menu and the Cooking plan.'],
        ];
        if (isset($restaurantRows[$pageKey])) {
            return ['name' => $restaurantRows[$pageKey][0], 'text' => $restaurantRows[$pageKey][1], 'replace' => true];
        }

        // Rows retail and restaurant share: same right, restaurant meaning.
        $shared = [
            'pos' => ['Orders & Till',
                'View: take orders and see tables and reservations (waiter). Create: take payment, use the cash drawer and see money (cashier). Edit: see and close every cashier\'s shift. Delete: void a paid sale.'],
            'pos_restock' => ['Purchases & Stock',
                'Buy food and drinks (Purchases) and give out the stock a cook asks for on the Cooking plan. Needs Orders & Till (View) too.'],
            'pos_config_settings' => [null,
                'Selling Settings, including how orders reach the kitchen.'],
            'pos_advanced' => [null,
                'Extra tills, network (kitchen) printers and sales targets.'],
            'categories' => ['Menu groups',
                'The groups dishes and drinks sit in on the Menu.'],
            'warehouses' => ['Shops',
                'Your restaurant shops (Settings → Shops). Edit also shows the set-up buttons on restaurant pages.'],
            'products' => [null,
                'Dishes, drinks and ingredients. Edit is needed to link add-on options to a dish.'],
        ];
        if (!isset($shared[$pageKey])) return null;

        [$name, $text] = $shared[$pageKey];
        return $mode === 'restaurant'
            ? ['name' => $name, 'text' => $text, 'replace' => true]
            : ['name' => null, 'text' => $text, 'replace' => false];   // mixed: retail text stays, restaurant meaning added
    }
}

if (!function_exists('rolePermissionVisible')) {
    function rolePermissionVisible(string $pageKey): bool
    {
        if (in_array($pageKey, rolePermissionRetiredKeys(), true)) {
            return false;
        }
        if (function_exists('tenantModuleAllowsPage') && !tenantModuleAllowsPage($pageKey)) {
            return false;
        }
        $needs = rolePermissionRelevantModules()[$pageKey] ?? null;
        if ($needs === null) return true;
        foreach ($needs as $featureKey) {
            if (tenantFeatureEnabled($featureKey)) return true;
        }
        return false;
    }
}

if (!function_exists('rolePermissionWorkflowPageKeys')) {
    /**
     * Every page_key that canReview()/canApprove() is actually checked against,
     * or that a notification event resolves by review/approve. Kept in sync by
     * tests/test_role_permission_ui_cli.php, which scans the codebase.
     */
    function rolePermissionWorkflowPageKeys(): array
    {
        return [
            'bank_reconciliation', 'bank_transfers', 'credit_notes', 'debit_notes', 'dn',
            'employee_contracts', 'employee_lifecycle', 'employee_trips', 'expenses', 'grn',
            'hr_performance', 'invoices', 'leaves', 'lpo', 'payment_vouchers',
            'payroll', 'projects', 'purchase_orders', 'purchase_returns', 'received_invoices',
            'restaurant_orders', 'revenue', 'rfq', 'sales_orders', 'sales_returns',
        ];
    }
}

if (!function_exists('rolePermissionHasWorkflow')) {
    function rolePermissionHasWorkflow(string $pageKey): bool
    {
        return in_array($pageKey, rolePermissionWorkflowPageKeys(), true);
    }
}

if (!function_exists('rolePermissionNote')) {
    /** A hint for rows whose workflow is controlled by another row. */
    function rolePermissionNote(string $pageKey): ?string
    {
        return [
            'quotations' => 'Review/Approve of quotations is set on the Sales Orders row.',
        ][$pageKey] ?? null;
    }
}

if (!function_exists('rolePermissionTabName')) {
    /** All Point-of-Sale permissions share one tab instead of being split across Sales/Settings. */
    function rolePermissionTabName(string $pageKey, ?string $moduleName): string
    {
        // Restaurant rows get their own tab, so a shop that also sells retail sees them apart.
        if (in_array($pageKey, rolePermissionRestaurantKeys(), true)) {
            return 'Restaurant';
        }
        if (array_intersect(featureForPageKey($pageKey), ['pos', 'pos_advanced'])) {
            return 'Point of Sale';
        }
        // The one activity-history box sits with the reports, where admins look
        // for it (it replaces the retired "Audit Report" row there).
        if ($pageKey === 'audit_logs') {
            return 'Reports';
        }
        return ($moduleName === null || $moduleName === '') ? 'Other' : $moduleName;
    }
}

if (!function_exists('loadRolePermissionMatrix')) {
    /**
     * @return array{visible: array<int,array>, preserved_ids: int[]}
     *   visible       — rows the matrix shows (permission_id, page_key, page_name, description, module_name)
     *   preserved_ids — non-hidden rows the matrix does NOT show (module off / not relevant);
     *                   saving a role must leave the role's grants on these untouched
     */
    function loadRolePermissionMatrix(PDO $pdo): array
    {
        $rows = $pdo->query("
            SELECT permission_id, page_key, page_name, description, module_name
            FROM permissions
            WHERE COALESCE(is_hidden, 0) = 0
            ORDER BY COALESCE(module_name, 'Other'), page_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        // A company with no Restaurant/Hybrid shop never sees restaurant rows; their grants are kept (preserved).
        $hideRestaurant = rolePermissionShopMode($pdo) === 'retail' ? rolePermissionRestaurantKeys() : [];

        $visible = [];
        $preserved = [];
        foreach ($rows as $r) {
            if (rolePermissionVisible((string)$r['page_key']) && !in_array((string)$r['page_key'], $hideRestaurant, true)) {
                $visible[] = $r;
            } else {
                $preserved[] = (int)$r['permission_id'];
            }
        }
        return ['visible' => $visible, 'preserved_ids' => $preserved];
    }
}

if (!function_exists('saveRolePermissionGrants')) {
    /**
     * Replace a role's grants for the rows the matrix showed; keep the rest.
     * Caller owns the transaction.
     *
     * @param array $submitted perms[permission_id][view|create|edit|delete|review|approve]
     * @param array $matrix    result of loadRolePermissionMatrix()
     * @return int number of grant rows written
     */
    function saveRolePermissionGrants(PDO $pdo, int $roleId, array $submitted, array $matrix): int
    {
        $keyById = [];
        foreach ($matrix['visible'] as $r) {
            $keyById[(int)$r['permission_id']] = (string)$r['page_key'];
        }
        $preserved = array_map('intval', $matrix['preserved_ids']);

        if ($preserved) {
            $in = implode(',', array_fill(0, count($preserved), '?'));
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id NOT IN ($in)")
                ->execute(array_merge([$roleId], $preserved));
        } else {
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);
        }

        $ins = $pdo->prepare("INSERT INTO role_permissions
            (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        $written = 0;
        foreach ($submitted as $permId => $actions) {
            $permId = (int)$permId;
            // Rows not on screen can't be granted through this form.
            if (!isset($keyById[$permId]) || !is_array($actions)) continue;

            $workflow = rolePermissionHasWorkflow($keyById[$permId]);
            $v = isset($actions['view'])    ? 1 : 0;
            $c = isset($actions['create'])  ? 1 : 0;
            $e = isset($actions['edit'])    ? 1 : 0;
            $d = isset($actions['delete'])  ? 1 : 0;
            $rv = ($workflow && isset($actions['review']))  ? 1 : 0;
            $ap = ($workflow && isset($actions['approve'])) ? 1 : 0;

            if ($v || $c || $e || $d || $rv || $ap) {
                $ins->execute([$roleId, $permId, $v, $c, $e, $d, $rv, $ap]);
                $written++;
            }
        }
        return $written;
    }
}
