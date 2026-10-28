<?php
/**
 * core/help_nav.php — where a page sits in the tenant header menu, as THIS user sees it.
 *
 * Mirrors header.php's top navigation (and core/nav_menu.php's bmsNavMenu() collapse
 * rules) as data, so help articles can say "Shop › Shops" to a Smart POS shop and
 * "Inventory › Warehouses" to a Smart BMS company from one article text ({nav:warehouses}).
 * tests/test_support_center_phase2_cli.php fails if header.php gains a menu page this
 * mirror does not know, so the two cannot drift apart silently.
 *
 * Context ($ctx) keys:
 *   simple  bool      posSimpleModeEnabled()
 *   shop    bool      isShopLabel() — Shop/Duka wording
 *   admin   bool      isAdmin()
 *   tenant  bool      a tenant subdomain (Subscription link)
 *   projects bool     get_setting('enable_projects') === '1'
 *   ai      bool      aiConfigured()
 *   can     callable  fn(string $pageKey): bool   — canView()
 *   feature callable  fn(string $featureKey): bool — tenantFeatureEnabled()
 *   t       callable  fn(string $label): string    — t()
 */

if (!function_exists('helpNavMenu')) {
    /**
     * The header's top-level entries for one context, in header order.
     * Item: [helpKey, perm (string page_key | callable), label (string | [warehouseWording, shopWording]), companion]
     */
    function helpNavMenu(array $ctx): array
    {
        $simple = !empty($ctx['simple']);
        $can = $ctx['can'];
        $feat = $ctx['feature'] ?? fn() => false;
        $both = fn(string ...$k) => fn() => array_reduce($k, fn($ok, $x) => $ok && $can($x), true);
        $any  = fn(string ...$k) => array_reduce($k, fn($ok, $x) => $ok || $can($x), false);

        $m = [];
        $m[] = ['type' => 'group', 'label' => $simple ? 'My Business' : 'Core',
            'gate' => $any('dashboard', 'customers', 'suppliers', 'products'),
            'items' => [
                ['dashboard', 'dashboard', 'Dashboard', false],
                ['customers', 'customers', 'Customers', false],
                ['suppliers', 'suppliers', 'Suppliers', false],
                ['sub_contractors', fn() => $can('suppliers') && $feat('projects'), 'Sub-Contractors', true],
                ['products', 'products', ['Inventory Products', 'Shop Products'], false],
                ['services', 'products', 'Service', true],
            ]];

        if (!$simple) {
            $m[] = ['type' => 'group', 'label' => 'Finance', 'gate' => false, 'items' => [
                ['expenses', 'expenses', 'Expenses', false],
                ['revenue', 'revenue', 'Revenue / Other Income', false],
                ['budget', 'budget', 'Budget', false],
                ['chart_of_accounts', 'chart_of_accounts', 'Chart of Accounts', false],
                ['bank_accounts', 'bank_accounts', 'Bank Accounts', false],
                ['cash_register', 'cash_register', 'Cash Register', false],
                ['petty_cash', 'petty_cash', 'Petty Cash', false],
                ['bank_transfers', 'bank_transfers', 'Bank Transfers', false],
                ['bank_reconciliation', 'bank_reconciliation', 'Reconciliation', false],
                ['bank_statement', 'bank_reconciliation', 'Bank Statement', false],
                ['journals', 'journals', 'Journals', false],
                ['invoices', 'invoices', 'Invoices', false],
                ['receive_payment', 'invoices', 'Receive Payment', true],
                ['purchase_orders', 'purchase_orders', 'Purchase Orders', false],
                ['payment_vouchers', 'payment_vouchers', 'Payment Vouchers', false],
            ]];
        } else {
            $m[] = ['type' => 'direct', 'key' => 'expenses', 'perm' => 'expenses', 'label' => 'Expenses'];
        }

        $m[] = ['type' => 'group', 'label' => 'CRM', 'gate' => $any('crm_dashboard', 'crm_leads', 'crm_pipeline'), 'items' => [
            ['crm_dashboard', 'crm_dashboard', 'CRM Dashboard', false],
            ['crm_leads', 'crm_leads', 'Leads', false],
            ['crm_pipeline', 'crm_pipeline', 'Pipeline Board', false],
            ['crm_pipeline_stages', 'crm_pipeline', 'Pipeline Stages', true],
        ]];

        $m[] = ['type' => 'group', 'label' => 'Sales', 'gate' => $can('sales_orders'), 'id' => 'sales', 'items' => [
            ['quotations', 'quotations', 'Quotations', false],
            ['sales_orders', 'sales_orders', 'Sales Orders', false],
            ['lpo', 'lpo', 'LPO', false],
            ['dn', 'dn', 'DN (Outbound)', false],
            ['pos', 'pos', ['POS', 'Sell'], false],
            ['invoices', 'invoices', 'Invoices', true],
            ['sales_returns', 'sales_returns', 'Sales Returns', false],
            ['credit_notes', 'credit_notes', 'Credit Notes', false],
        ]];
        // POS stands alone as a direct link whenever the Sales dropdown is not shown.
        $m[] = ['type' => 'direct', 'key' => 'pos', 'perm' => 'pos', 'label' => ['POS', 'Sell'], 'unless' => 'sales'];

        if ($simple) {
            $restock = $both('pos', 'pos_restock');
            $m[] = ['type' => 'simple_shop', 'label' => ['Inventory', 'Shop'], 'restock' => $restock, 'items' => [
                ['warehouses', 'warehouses', ['Warehouses', 'Shops'], false],
                ['restock', $restock, 'Receive Stock', false],
            ]];
        } else {
            $m[] = ['type' => 'group', 'label' => ['Inventory', 'Shop'], 'gate' => $any('products', 'warehouses'), 'items' => [
                ['products', 'products', ['Inventory Products', 'Shop Products'], false],
                ['services', 'products', 'Services', true],
                ['categories', 'categories', 'Categories', false],
                ['stock_adjustments', 'stock_adjustments', 'Adjustments', false],
                ['inventory_valuation', 'inventory_valuation', 'Valuation', false],
                ['warehouses', 'warehouses', ['Warehouses', 'Shops'], false],
                ['locations', 'locations', 'Locations', false],
            ]];
            $m[] = ['type' => 'group', 'label' => 'Procurement', 'gate' => false, 'items' => [
                ['suppliers', 'suppliers', 'Suppliers', false],
                ['rfq', 'rfq', 'RFQ', false],
                ['purchase_orders', 'purchase_orders', 'Purchase Order', false],
                ['delivery_notes', 'grn', 'DN', true],
                ['grn', 'grn', 'GRN', false],
                ['received_invoices', 'received_invoices', 'Bills', false],
                ['purchase_returns', 'purchase_returns', 'Return Note', false],
                ['debit_notes', 'debit_notes', 'Debit Notes', false],
                ['nip_materials', 'nip_materials', 'Materials', false],
                ['tenders', 'tenders', 'Tenders', false],
            ]];
        }

        $m[] = ['type' => 'group', 'label' => 'Operations', 'gate' => $any('employees', 'assets'), 'items' => [
            ['hr_dashboard', 'hr_dashboard', 'HR Dashboard', false],
            ['employees', 'employees', 'Employees', false],
            ['employee_lifecycle', 'employee_lifecycle', 'HR Actions', false],
            ['org_chart', 'org_chart', 'Org Chart', false],
            ['employee_contracts', 'employee_contracts', 'Contracts', false],
            ['departments', 'departments', 'Departments', false],
            ['designations', 'designations', 'Designations', false],
            ['employment_types', 'employment_types', 'Employment Types', false],
            ['hr_performance', 'hr_performance', 'Performance (HR)', false],
            ['trainings', 'trainings', 'Training', false],
            ['recruitment', 'recruitment', 'Recruitment', false],
            ['hr_checklists', 'hr_checklists', 'Checklists', false],
            ['payroll', 'payroll', 'Payroll', false],
            ['salary_components', 'salary_components', 'Salary Components', false],
            ['attendance', 'attendance', 'Attendance', false],
            ['leaves', 'leaves', 'Leaves', false],
            ['company_calendar', 'company_calendar', 'Working Days & Holidays', false],
            ['meetings', 'meetings', 'Meetings', false],
            ['employee_trips', 'employee_trips', 'Trips', false],
            ['announcements', 'announcements', 'Announcements', false],
            ['assets', 'assets', 'Assets', false],
            ['maintenance', 'maintenance', 'Maintenance', false],
        ]];

        $m[] = ['type' => 'direct', 'key' => 'projects', 'perm' => fn() => !empty($ctx['projects']) && $can('projects'), 'label' => 'Projects'];

        $ai = fn() => !empty($ctx['ai']) && $can('ai_assistant');
        $m[] = ['type' => 'group', 'label' => 'Comms', 'gate' => $any('message_center', 'notification_center', 'ai_assistant'), 'items' => [
            ['ai_assistant', $ai, 'Ask BMS', false],
            ['message_center', 'message_center', 'Messages', false],
            ['email_templates', 'email_templates', 'Email', false],
            ['notification_center', 'notification_center', 'Notifications', false],
            ['campaigns', 'campaigns', 'Campaigns', false],
            ['leads', 'leads', 'Leads', false],
        ]];

        $m[] = ['type' => 'group', 'label' => 'Docs',
            'gate' => $any('document_library', 'document_templates', 'e_signatures', 'compliance_documents'), 'items' => [
            ['document_library', 'document_library', 'Library', false],
            ['document_templates', 'document_templates', 'Templates', false],
            ['e_signatures', 'e_signatures', 'E-Sign', false],
            ['compliance_documents', 'compliance_documents', 'Compliance', false],
        ]];

        $m[] = ['type' => 'direct', 'key' => 'field_visits', 'perm' => 'field_visits', 'label' => 'Customer Visits'];

        if ($simple) {
            $reports = [
                ['sales_report', 'sales_report', 'Sales Report', false],
                ['purchase_report', 'purchase_report', 'Purchase Report', false],
                ['inventory_report', 'inventory_report', 'Inventory Report', false],
                ['expense_report', 'expense_report', 'Expense Report', false],
                ['pos_user_sales_report', $both('pos', 'pos_user_sales_report'), 'Sales by User Report', false],
                ['pos_profit_report', $both('pos', 'pos_profit_report'), 'Profit Report', false],
                ['product_analysis', 'product_analysis', 'Most Profitable Products', false],
            ];
        } else {
            $fr = 'financial_reports';
            $reports = [
                ['income_statement', 'income_statement', 'Income Statement', false],
                ['balance_sheet', 'balance_sheet', 'Balance Sheet', false],
                ['cash_flow', 'cash_flow', 'Cash Flow', false],
                ['consolidated_expenses', $fr, 'Consolidated Expenses', false],
                ['trial_balance', 'trial_balance', 'Trial Balance', false],
                ['ledger_report', 'ledger_report', 'General Ledger', false],
                ['ar_aging', $fr, 'Receivables Aging', false],
                ['ap_aging', 'ap_aging', 'Payables Aging', false],
                ['customer_statement', $fr, 'Customer Statement', false],
                ['vendor_statement', 'vendor_statement', 'Vendor Statement', false],
                ['sales_report', 'sales_report', 'Sales Report', false],
                ['service_sales_report', 'service_sales_report', 'Service Sales Report', false],
                ['purchase_report', 'purchase_report', 'Purchase Report', false],
                ['po_invoice_report', 'received_invoices', 'PO vs Invoice Report', false],
                ['inventory_report', 'inventory_report', 'Inventory Report', false],
                ['expense_report', 'expense_report', 'Expense Report', false],
                ['performance_dashboard', 'performance_dashboard', 'Performance', false],
                ['customer_analysis', 'customer_analysis', 'Customer Analysis', false],
                ['product_analysis', 'product_analysis', 'Product Analysis', false],
                ['sales_forecast', 'sales_forecast', 'Sales Forecast', false],
                ['trends_analysis', 'trends_analysis', 'Trends', false],
                ['tax_report', 'tax_report', 'Tax Report', false],
                ['wht_report', 'wht_report', 'WHT Report', false],
                ['wht_receivable_report', 'tax_report', 'WHT Credit (Received)', false],
                ['audit_logs', 'audit_logs', 'Audit Logs', false],
                ['compliance_report', 'compliance_report', 'Compliance', false],
                ['employee_report', 'employee_report', 'Employee Report', false],
                ['asset_report', 'asset_report', 'Asset Report', false],
            ];
        }
        $m[] = ['type' => 'group', 'label' => 'Reports', 'gate' => null, 'items' => $reports];

        $m[] = ['type' => 'direct', 'key' => 'subscription', 'perm' => fn() => !empty($ctx['admin']) && !empty($ctx['tenant']), 'label' => 'Subscription'];

        $colorOk = fn() => $can('color_settings') && ($feat('sales') || $feat('procurement'));
        $m[] = ['type' => 'settings', 'label' => 'Settings', 'items' => [
            ['system_settings', fn() => !empty($ctx['admin']), 'Admin', false],
            ['pos_config_settings', 'pos_config_settings', ['POS Settings', 'Selling Settings'], false],
            ['color_settings', $colorOk, 'Color Setting', false],
        ]];

        $m[] = ['type' => 'account', 'label' => 'Your name (top right)', 'items' => [
            ['my_hr', fn() => false, 'My HR', false],
            // The link shows for everyone but the page is permission-gated (my_settings).
            ['my_settings', 'my_settings', 'My Profile & Settings', false],
            ['help', fn() => true, 'Help', false],
        ]];
        return $m;
    }
}

if (!function_exists('helpNavPermOk')) {
    function helpNavPermOk($perm, array $ctx): bool
    {
        // Closure only: a page-key string like 'pos' can collide with a global function name.
        return $perm instanceof Closure ? (bool)$perm() : (bool)($ctx['can'])((string)$perm);
    }
}

if (!function_exists('helpNavLabel')) {
    function helpNavLabel($label, array $ctx): string
    {
        $raw = is_array($label) ? (!empty($ctx['shop']) ? $label[1] : $label[0]) : $label;
        $t = $ctx['t'] ?? null;
        return is_callable($t) ? (string)$t($raw) : $raw;
    }
}

if (!function_exists('helpNavLocate')) {
    /**
     * Where $helpKey is reachable from the header for this context.
     * @return ?array ['parts' => string[]] (e.g. ['Shop', 'Shops'] or ['Sell']), null when not in the menu.
     */
    function helpNavLocate(string $helpKey, array $ctx): ?array
    {
        $simple = !empty($ctx['simple']);
        $shown = [];   // group id => shown as dropdown?
        $found = null;
        foreach (helpNavMenu($ctx) as $e) {
            $type = $e['type'];
            if ($type === 'direct') {
                if (isset($e['unless']) && !empty($shown[$e['unless']])) continue;
                if ($found === null && $e['key'] === $helpKey && helpNavPermOk($e['perm'], $ctx)) {
                    $found = [helpNavLabel($e['label'], $ctx)];
                }
                continue;
            }

            $visible = array_values(array_filter($e['items'], fn($it) => helpNavPermOk($it[1], $ctx)));
            $counted = array_values(array_filter($visible, fn($it) => !$it[3]));
            $label = helpNavLabel($e['label'], $ctx);
            $isShown = false;
            $parts = null;

            if ($type === 'group') {
                $gate = $e['gate'] ?? null;
                if ($gate === null) $gate = count($counted) > 0;   // hasReportsAccess()-style: anything to show
                if (!$simple) {
                    if (count($counted) === 1) {
                        $isShown = false;
                        if ($counted[0][0] === $helpKey) $parts = [helpNavLabel($counted[0][2], $ctx)];
                    } elseif (count($counted) > 1) {
                        $isShown = true;
                    }
                } else {
                    $isShown = (bool)$gate && count($visible) > 0;
                }
                if ($isShown) {
                    foreach ($visible as $it) {
                        if ($it[0] === $helpKey) { $parts = [$label, helpNavLabel($it[2], $ctx)]; break; }
                    }
                }
            } elseif ($type === 'simple_shop') {
                $wh = ($ctx['can'])('warehouses');
                $rs = (bool)($e['restock'])();
                if ($wh && $rs) {
                    $isShown = true;
                    foreach ($visible as $it) {
                        if ($it[0] === $helpKey) { $parts = [$label, helpNavLabel($it[2], $ctx)]; break; }
                    }
                } elseif ($wh && $helpKey === 'warehouses') {
                    $parts = [$label];
                } elseif ($rs && $helpKey === 'restock') {
                    $parts = [helpNavLabel('Receive Stock', $ctx)];
                }
            } elseif ($type === 'settings') {
                $isShown = count($visible) > 0;
                foreach ($visible as $it) {
                    if ($it[0] === $helpKey) { $parts = [$label, helpNavLabel($it[2], $ctx)]; break; }
                }
            } elseif ($type === 'account') {
                foreach ($visible as $it) {
                    if ($it[0] === $helpKey) { $parts = [helpNavLabel($e['label'], $ctx), helpNavLabel($it[2], $ctx)]; break; }
                }
            }

            if (isset($e['id'])) $shown[$e['id']] = $isShown;
            if ($found === null && $parts !== null) $found = $parts;
        }
        return $found === null ? null : ['parts' => $found];
    }
}

if (!function_exists('helpNavPath')) {
    /** "Shop › Shops", "Sell", or null when the page is not reachable from the menu. */
    function helpNavPath(string $helpKey, array $ctx): ?string
    {
        $loc = helpNavLocate($helpKey, $ctx);
        return $loc === null ? null : implode(' › ', $loc['parts']);
    }
}

if (!function_exists('helpKeyAllowed')) {
    /** May this user open the page behind $helpKey at all (module + role)? */
    function helpKeyAllowed(string $helpKey, array $ctx): bool
    {
        foreach ([false, true] as $simple) {
            foreach (helpNavMenu(['simple' => $simple] + $ctx) as $e) {
                if ($e['type'] === 'direct' && $e['key'] === $helpKey) return helpNavPermOk($e['perm'], $ctx);
                foreach ($e['items'] ?? [] as $it) {
                    if ($it[0] === $helpKey) return helpNavPermOk($it[1], $ctx);
                }
            }
        }
        return false;
    }
}

if (!function_exists('helpNavCatalog')) {
    /** Every help key with its menu labels (both wordings) — for the superadmin page picker. */
    function helpNavCatalog(): array
    {
        $out = [];
        foreach ([false, true] as $simple) {
            $ctx = ['simple' => $simple, 'shop' => false, 'admin' => true, 'tenant' => true, 'projects' => true, 'ai' => true,
                    'can' => fn() => true, 'feature' => fn() => true];
            foreach (helpNavMenu($ctx) as $e) {
                $g = is_array($e['label']) ? $e['label'][0] : $e['label'];
                $list = $e['type'] === 'direct' ? [[$e['key'], $e['perm'], $e['label'], false]] : $e['items'];
                foreach ($list as $it) {
                    if (isset($out[$it[0]])) continue;
                    $lbl = $it[2];
                    $out[$it[0]] = [
                        'group' => $e['type'] === 'direct' ? 'Direct links' : $g,
                        'label' => is_array($lbl) ? $lbl[0] : $lbl,
                        'shop_label' => is_array($lbl) ? $lbl[1] : null,
                    ];
                }
            }
        }
        return $out;
    }
}

if (!function_exists('helpNavRoute')) {
    /** The route (for getUrl) and optional query that opens a help key's page. */
    function helpNavRoute(string $helpKey): array
    {
        $map = [
            'restock' => ['receive_stock', ''], 'dn' => ['delivery_notes', 'type=outbound'], 'lpo' => ['lpos', ''],
            'crm_dashboard' => ['crm/dashboard', ''], 'crm_leads' => ['crm/leads', ''], 'crm_pipeline' => ['crm/pipeline', ''],
            'crm_pipeline_stages' => ['crm/pipeline_stages', ''], 'employee_lifecycle' => ['hr_actions', ''],
            'document_library' => ['library', ''], 'document_templates' => ['templates', ''], 'field_visits' => ['field_reports', ''],
            'product_analysis' => ['product_analysis', ''],
        ];
        return $map[$helpKey] ?? [$helpKey, ''];
    }
}

if (!function_exists('helpNavUrl')) {
    /** URL that opens the page behind $helpKey, or null when getUrl() is unavailable. */
    function helpNavUrl(string $helpKey): ?string
    {
        if (!function_exists('getUrl')) return null;
        [$route, $qs] = helpNavRoute($helpKey);
        $u = (string)getUrl($route);
        return $qs !== '' ? $u . (str_contains($u, '?') ? '&' : '?') . $qs : $u;
    }
}

if (!function_exists('helpCurrentPageKey')) {
    /** Which help key the current request is on (matches the path the way the phone bottom bar does). */
    function helpCurrentPageKey(?string $requestUri = null): ?string
    {
        if (!function_exists('getUrl')) return null;
        $uri = $requestUri ?? (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = rtrim((string)parse_url($uri, PHP_URL_PATH), '/');
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
        if ($path === '') return null;
        $best = null; $bestLen = 0;
        foreach (array_keys(helpNavCatalog()) as $k) {
            [$route, $qs] = helpNavRoute($k);
            $p = rtrim((string)parse_url((string)getUrl($route), PHP_URL_PATH), '/');
            if ($p === '' || $p === '/') continue;
            if ($path !== $p && !str_starts_with($path, $p . '/')) continue;
            if ($qs !== '') { parse_str($qs, $need); if (array_diff_assoc($need, $q)) continue; }
            $len = strlen($p) + ($qs !== '' ? 1 : 0);
            if ($len > $bestLen) { $best = $k; $bestLen = $len; }
        }
        return $best;
    }
}

if (!function_exists('helpPreviewContext')) {
    /**
     * A simulated tenant context for the superadmin article preview.
     * $product 'pos' (Smart POS) | 'bms' (Smart BMS); $role 'admin' | 'staff'.
     * Module rules come from the real registry (featureForPageKey) plus the same
     * Simple Mode / supplier-access bypasses tenantModuleAllowsPage() applies.
     */
    function helpPreviewContext(string $product, string $role): array
    {
        require_once __DIR__ . '/feature_registry.php';
        require_once __DIR__ . '/plans.php';
        $pos = $product === 'pos';
        $features = $pos ? SMART_POS_MODULES : planDefaultOnFeatures();
        $staffPages = ['dashboard', 'customers', 'products', 'pos', 'warehouses', 'sales_report', 'quotations', 'sales_orders'];
        $moduleOk = function (string $k) use ($pos, $features): bool {
            if ($pos && $k === 'expenses') return true;
            if ($pos && in_array($k, ['suppliers', 'supplier_payments'], true)) return true;
            $owners = featureForPageKey($k);
            if (!$owners) return true;
            return (bool)array_intersect($owners, $features);
        };
        $admin = $role !== 'staff';
        return [
            'simple' => $pos, 'shop' => $pos, 'admin' => $admin, 'tenant' => true, 'projects' => false, 'ai' => false,
            'can' => fn(string $k) => $moduleOk($k) && ($admin || in_array($k, $staffPages, true)),
            'feature' => fn(string $f) => in_array($f, $features, true),
            't' => fn(string $s) => $s,
            'product' => $pos ? 'Smart POS' : 'Smart BMS',
        ];
    }
}

if (!function_exists('helpRuntimeContext')) {
    /** The real context for the signed-in tenant user (header.php's own functions). */
    function helpRuntimeContext(): array
    {
        return [
            'simple'   => function_exists('posSimpleModeEnabled') && posSimpleModeEnabled(),
            'shop'     => function_exists('isShopLabel') && isShopLabel(),
            'admin'    => function_exists('isAdmin') && isAdmin(),
            'tenant'   => function_exists('bmsCurrentTenantId') && bmsCurrentTenantId() !== null,
            'projects' => function_exists('get_setting') && get_setting('enable_projects') == '1',
            'ai'       => function_exists('aiConfigured') && aiConfigured(),
            'can'      => fn(string $k) => function_exists('canView') && canView($k),
            'feature'  => fn(string $f) => function_exists('tenantFeatureEnabled') && tenantFeatureEnabled($f),
            't'        => fn(string $s) => function_exists('t') ? t($s) : $s,
        ];
    }
}
