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
    /** Pages with no effect anywhere; hidden from the matrix, existing grants kept. */
    function rolePermissionRetiredKeys(): array
    {
        return ['tax_settings'];
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
            'revenue', 'rfq', 'sales_orders', 'sales_returns',
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
        if (array_intersect(featureForPageKey($pageKey), ['pos', 'pos_advanced', 'restaurant_pos'])) {
            return 'Point of Sale';
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

        $visible = [];
        $preserved = [];
        foreach ($rows as $r) {
            if (rolePermissionVisible((string)$r['page_key'])) {
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
