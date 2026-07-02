<?php
/**
 * Permission Management System
 * Handles loading and checking user permissions
 *
 * This system provides granular access control with view, edit, and delete
 * permissions for each page/module in the application.
 */

// Auto-load the security wrapper helpers (logSecure / enforcePageOrAdmin /
// assertCanCreate / assertCanEdit / assertCanDelete). They are additive only
// and never override existing functions in this file.
require_once __DIR__ . '/security_helpers.php';

// Auto-load the project-scope helpers (loadUserScope / userCan /
// scopeFilterSql / refreshScopeCache). Second axis of access control:
// the role system answers "what verbs?"; this one answers "which rows?".
// Phase A ships the helpers but does NOT change any SELECT yet.
require_once __DIR__ . '/project_scope.php';

/**
 * Load user permissions into session
 * 
 * @param int $roleId User's role ID
 * @return void
 */
function loadUserPermissions($roleId)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.page_key,
                rp.can_view,
                rp.can_create,
                rp.can_edit,
                rp.can_delete,
                rp.can_review,
                rp.can_approve
            FROM role_permissions rp
            JOIN permissions p ON p.permission_id = rp.permission_id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);

        $permissions = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $permissions[$row['page_key']] = [
                'view'    => (bool)$row['can_view'],
                'create'  => (bool)$row['can_create'],
                'edit'    => (bool)$row['can_edit'],
                'delete'  => (bool)$row['can_delete'],
                'review'  => (bool)($row['can_review']  ?? false),
                'approve' => (bool)($row['can_approve'] ?? false),
            ];
        }

        $_SESSION['permissions'] = $permissions;
        
    } catch (PDOException $e) {
        error_log("Error loading permissions: " . $e->getMessage());
        $_SESSION['permissions'] = [];
    }
}

/**
 * Check if user can view a page
 * 
 * @param string $pageKey Page identifier (e.g., 'customers', 'loans')
 * @return bool True if user has view permission
 */
function canView($pageKey)
{
    // Admin always has access
    if (isAdmin()) {
        return true;
    }
    
    return $_SESSION['permissions'][$pageKey]['view'] ?? false;
}

/**
 * Check if user can create on a page
 * 
 * @param string $pageKey Page identifier
 * @return bool True if user has create permission
 */
function canCreate($pageKey)
{
    // Admin always has access
    if (isAdmin()) {
        return true;
    }
    
    return $_SESSION['permissions'][$pageKey]['create'] ?? false;
}

/**
 * Check if user can edit on a page
 * 
 * @param string $pageKey Page identifier
 * @return bool True if user has edit permission
 */
function canEdit($pageKey)
{
    // Admin always has access
    if (isAdmin()) {
        return true;
    }
    
    return $_SESSION['permissions'][$pageKey]['edit'] ?? false;
}

/**
 * Check if user can delete on a page
 * 
 * @param string $pageKey Page identifier
 * @return bool True if user has delete permission
 */
function canDelete($pageKey)
{
    // Admin always has access
    if (isAdmin()) {
        return true;
    }
    
    return $_SESSION['permissions'][$pageKey]['delete'] ?? false;
}

/**
 * Check if user can review on a page (e.g. submit RFQ for review)
 * 
 * @param string $pageKey Page identifier
 * @return bool True if user has review permission
 */
function canReview($pageKey)
{
    // Admin always has access
    if (isAdmin()) {
        return true;
    }

    return (bool)($_SESSION['permissions'][$pageKey]['review'] ?? false);
}

/**
 * Check if user can approve on a page (e.g. final-approve an RFQ)
 * 
 * @param string $pageKey Page identifier
 * @return bool True if user has approve permission
 */
function canApprove($pageKey)
{
    // Admin always has access
    if (isAdmin()) {
        return true;
    }

    return (bool)($_SESSION['permissions'][$pageKey]['approve'] ?? false);
}

/**
 * Check if user has any permission for a page
 * 
 * @param string $pageKey Page identifier
 * @return bool True if user has any permission (view, edit, or delete)
 */
function hasAnyPermission($pageKey)
{
    // Admin always has access
    if (isAdmin()) {
        return true;
    }
    
    return canView($pageKey) || canEdit($pageKey) || canDelete($pageKey);
}

/**
 * Check if user has permission (alias for hasAnyPermission for backward compatibility/simplicity)
 * 
 * @param string $pageKey Page identifier
 * @return bool
 */
function hasPermission($pageKey)
{
    return hasAnyPermission($pageKey);
}

/**
 * Require view permission or redirect
 * 
 * @param string $pageKey Page identifier
 * @param string $redirectUrl Where to redirect if no permission
 * @return void
 */
function requireViewPermission($pageKey, $redirectUrl = 'unauthorized')
{
    // First, check if user is logged in
    if (!isAuthenticated()) {
        redirectTo('login');
    }

    if (!canView($pageKey)) {
        http_response_code(403);
        redirectTo($redirectUrl);
    }
}

/**
 * Require create permission or redirect
 * 
 * @param string $pageKey Page identifier
 * @param string $redirectUrl Where to redirect if no permission
 * @return void
 */
function requireCreatePermission($pageKey, $redirectUrl = 'unauthorized')
{
    if (!isAuthenticated()) {
        redirectTo('login');
    }

    if (!canCreate($pageKey)) {
        http_response_code(403);
        redirectTo($redirectUrl);
    }
}

/**
 * Require edit permission or redirect
 * 
 * @param string $pageKey Page identifier
 * @param string $redirectUrl Where to redirect if no permission
 * @return void
 */
function requireEditPermission($pageKey, $redirectUrl = 'unauthorized')
{
    if (!isAuthenticated()) {
        redirectTo('login');
    }

    if (!canEdit($pageKey)) {
        http_response_code(403);
        redirectTo($redirectUrl);
    }
}

/**
 * Require delete permission or redirect
 * 
 * @param string $pageKey Page identifier
 * @param string $redirectUrl Where to redirect if no permission
 * @return void
 */
function requireDeletePermission($pageKey, $redirectUrl = 'unauthorized')
{
    if (!isAuthenticated()) {
        redirectTo('login');
    }

    if (!canDelete($pageKey)) {
        http_response_code(403);
        redirectTo($redirectUrl);
    }
}

/**
 * Get all permissions for current user
 * 
 * @return array Associative array of page_key => permissions
 */
function getAllPermissions()
{
    return $_SESSION['permissions'] ?? [];
}

/**
 * Check if user is admin (has full access).
 * Uses the is_admin flag on the roles table so any role marked as admin
 * bypasses permission checks — not hardcoded to role_id 1.
 */
function isAdmin()
{
    // Fast path: session already has the flag
    if (isset($_SESSION['is_admin'])) {
        return (bool)$_SESSION['is_admin'];
    }

    // Fallback: query DB (should not normally be needed — header.php sets it)
    if (!isset($_SESSION['role_id'])) {
        return false;
    }
    global $pdo;
    try {
        $flag = $pdo->prepare("SELECT is_admin FROM roles WHERE role_id = ? LIMIT 1");
        $flag->execute([$_SESSION['role_id']]);
        $result = (bool)$flag->fetchColumn();
        $_SESSION['is_admin'] = $result;
        return $result;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get permission summary for a page
 * Returns a string describing the permissions (e.g., "View, Edit, Delete")
 * 
 * @param string $pageKey Page identifier
 * @return string Permission summary
 */
function getPermissionSummary($pageKey)
{
    $perms = [];
    
    if (canView($pageKey)) {
        $perms[] = 'View';
    }
    if (canEdit($pageKey)) {
        $perms[] = 'Edit';
    }
    if (canDelete($pageKey)) {
        $perms[] = 'Delete';
    }
    
    return empty($perms) ? 'No Access' : implode(', ', $perms);
}

/**
 * Check if permissions are loaded in session
 * 
 * @return bool True if permissions are loaded
 */
function arePermissionsLoaded()
{
    return isset($_SESSION['permissions']) && is_array($_SESSION['permissions']);
}

/**
 * Reload permissions for current user
 * Useful after permission changes
 * 
 * @return void
 */
function reloadPermissions()
{
    if (isset($_SESSION['role_id'])) {
        loadUserPermissions($_SESSION['role_id']);
    }
}
/**
 * Get the permission key mapping for pages
 * Maps filename => permission_key
 * 
 * @return array
 */
function getPagePermissionMapping()
{
    return [
        // Core
        'dashboard' => 'dashboard',
        'dashboard.php' => 'dashboard',
        'index.php' => 'dashboard',
        'customers.php' => 'customers',
        'customer_details.php' => 'customer_details',
        'edit_customer.php' => 'edit_customer',
        'customer_groups.php' => 'customer_groups',
        'customer_group_details.php' => 'customer_groups',
        'customer_group_members.php' => 'customer_groups',
        'customer_import.php' => 'customer_import',
        'customer_documents.php' => 'customer_documents',
        'suppliers.php' => 'suppliers',
        'products.php' => 'products',

        // Sales
        'sales_orders.php' => 'sales_orders',
        'pos.php' => 'pos',
        'quotations.php' => 'quotations',
        'sales_returns.php' => 'sales_returns',
        'lpos.php' => 'lpo',
        'lpo_create.php' => 'lpo',
        'lpo_view.php' => 'lpo',
        'print_lpo.php' => 'lpo',

        // Finance
        'invoices.php' => 'invoices',
        'received_invoices_view.php' => 'received_invoices',
        'bank_accounts.php' => 'bank_accounts',
        'cash_register.php' => 'cash_register',
        'petty_cash.php' => 'petty_cash',
        'bank_reconciliation.php' => 'bank_reconciliation',
        'payment_vouchers.php' => 'payment_vouchers',
        'expenses.php' => 'expenses',
        'budget.php' => 'budget',
        'chart_of_accounts.php' => 'chart_of_accounts',
        'journals.php' => 'journals',
        'transactions.php' => 'transactions',

        // Inventory
        'categories.php' => 'categories',
        'stock_adjustments.php' => 'stock_adjustments',
        'inventory_valuation.php' => 'inventory_valuation',
        'warehouses.php' => 'warehouses',
        'locations.php' => 'locations',

        // Procurement (Purchases)
        'purchase_orders.php' => 'purchase_orders',
        'grn.php' => 'grn',
        'purchase_returns.php' => 'purchase_returns',
        'tenders.php' => 'tenders',
        'tender_view.php' => 'tenders',
        'tender_create.php' => 'tenders',
        'tender_edit.php' => 'tenders',

        // Operations / HR
        'employees.php' => 'employees',
        'payroll.php' => 'payroll',
        'attendance.php' => 'attendance',
        'leaves.php' => 'leaves',
        'assets.php' => 'assets',
        'projects.php' => 'projects',
        'maintenance.php' => 'maintenance',

        // System Settings
        'users.php' => 'users',
        'user_roles.php' => 'user_roles',
        'user_projects.php' => 'user_projects',
        'system_settings.php' => 'system_settings',
        'company_profile.php' => 'company_profile',
        'backup_restore.php' => 'backup_restore',
        'audit_logs.php' => 'audit_logs',
        'activity_log.php' => 'audit_logs',
        'notification_center.php' => 'notification_center',
        'notification_center' => 'notification_center',
        'message_center.php' => 'message_center',
        'campaign_management.php' => 'campaign_management',
        'email_templates.php' => 'email_templates',
        'lead_generation.php' => 'lead_generation',
        'sms_alerts.php' => 'sms_alerts',
        'sms_templates.php' => 'sms_templates',
        'tax_settings.php' => 'tax_settings',
        'payment_settings.php' => 'payment_settings',
        'notification_settings.php' => 'notification_settings',

        // ── Phase 6 — defence-in-depth router fallback ─────────────────────
        // Every page reachable from the URL router gets a deterministic
        // page_key, so even if a developer forgets to call
        // autoEnforcePermission() inside the file, the router-level fallback
        // can still gate it. Strictly additive — no existing mappings touched.

        // Customers / Suppliers (extras)
        'supplier_categories.php'  => 'suppliers',
        'supplier_details.php'     => 'suppliers',
        'supplier_payments.php'    => 'supplier_payments',
        'sub_contractors.php'      => 'projects',
        'sub_contractor_details.php' => 'projects',

        // Sales / Quotations
        'sales_order_create.php'   => 'sales_orders',
        'sales_order_edit.php'     => 'sales_orders',
        'sales_order_view.php'     => 'sales_orders',
        'print_sales_order.php'    => 'sales_orders',
        'quotation_create.php'     => 'sales_orders',
        'quotation_edit.php'       => 'sales_orders',
        'quotation_view.php'       => 'sales_orders',
        'quotation_form.php'       => 'sales_orders',
        'print_quotation.php'      => 'sales_orders',
        'sales_return_create.php'  => 'sales_returns',
        'sales_return_edit.php'    => 'sales_returns',
        'sales_return_view.php'    => 'sales_returns',
        'print_sales_return.php'   => 'sales_returns',
        'sales_customer.php'       => 'reports',
        'sales_forecast.php'       => 'sales_report',

        // Invoices / Payments / Received Invoices
        'invoice_create.php'       => 'invoices',
        'invoice_edit.php'         => 'invoices',
        'invoice_view.php'         => 'invoices',
        'invoice_print.php'        => 'invoices',
        'received_invoices.php'    => 'received_invoices',
        'payment_create.php'       => 'payment_create',
        'po_invoice_report.php'    => 'received_invoices',
        'get_invoices.php'         => 'received_invoices',

        // Procurement (Purchase / RFQ / GRN / DN / DO / NIP / Tenders)
        'purchase_order_create.php'  => 'purchase_orders',
        'purchase_order_details.php' => 'purchase_orders',
        'purchase_return_view.php'   => 'purchase_returns',
        'print_purchase_return.php'  => 'purchase_returns',
        'rfq.php'                    => 'rfq',
        'rfq_create.php'             => 'rfq',
        'rfq_view.php'               => 'rfq',
        'grn_create.php'             => 'grn',
        'grn_edit.php'               => 'grn',
        'grn_view.php'               => 'grn',
        'grn_print.php'              => 'grn',
        'delivery_notes.php'         => 'dn',
        'dn_create.php'              => 'dn',
        'dn_view.php'                => 'dn',
        'dn_outbound.php'            => 'dn',
        'do_create.php'              => 'do',
        'do_view.php'                => 'do',
        'nip_materials.php'          => 'nip_materials',
        'view_nip_materials.php'     => 'nip_materials',
        'view_material_list.php'     => 'nip_materials',
        'edit_nip_materials.php'     => 'nip_materials',
        'purchase_report.php'        => 'purchase_report',

        // Accounts / Finance details
        'account_details.php'           => 'chart_of_accounts',
        'add_journal.php'               => 'journals',
        'edit_journal.php'              => 'journals',
        'journal_details.php'           => 'journals',
        'edit_expense.php'              => 'expenses',
        'expense_details.php'           => 'expenses',
        'budget_details.php'            => 'budget',
        'transaction_details.php'       => 'transactions',
        'cash_register_details.php'     => 'cash_register',
        'reconciliation_details.php'    => 'bank_reconciliation',
        'payment_voucher_print.php'     => 'payment_vouchers',
        'petty_cash_print.php'          => 'petty_cash',

        // Operations / Projects
        'project_view.php'              => 'projects',
        'project_budget_report.php'     => 'projects',
        'project_financial_report.php'  => 'projects',
        'project_progress_report.php'   => 'projects',
        'inspection_view.php'           => 'projects',
        'print_ipc.php'                 => 'projects',
        'warehouse_view.php'            => 'warehouses',
        'warehouse_stock_view.php'     => 'warehouses',

        // Inventory / Stock / Products
        'product_create.php'  => 'products',
        'product_edit.php'    => 'products',
        'product_view.php'    => 'products',
        'product_import.php'  => 'products',
        'print_barcode.php'   => 'products',
        'brands.php'          => 'products',
        'services.php'        => 'products',
        'service_view.php'    => 'products',
        'stock_movements.php'        => 'stock_adjustments',
        'stock_transfers.php'        => 'stock_adjustments',
        'adjustment_print.php'       => 'stock_adjustments',
        'print_transfer.php'         => 'stock_adjustments',
        'ajax_get_transfer_items.php'=> 'stock_adjustments',

        // HR / Payroll / Leaves
        'employee_details.php' => 'employees',
        'hr_actions.php'       => 'employee_lifecycle',
        'payroll_details.php'  => 'payroll',
        'payroll_settings.php' => 'payroll',
        'payslip.php'          => 'payslip',
        'leave_application.php'=> 'leaves',
        'leave_details.php'    => 'leaves',
        'leave_reports.php'    => 'leaves',

        // Loans (Phase 5d + 6)
        'loan_application.php' => 'loans',
        'loan_details.php'     => 'loans',
        'loan_documents.php'   => 'loan_documents',
        'loan_performance.php' => 'financial_reports',
        'loan_portfolio.php'   => 'financial_reports',
        'delinquency_report.php' => 'financial_reports',
        'repayment_report.php'   => 'financial_reports',

        // Documents
        'document_library.php'              => 'document_library',
        'document_workflow.php'             => 'document_workflow',
        'document_templates.php'            => 'document_templates',
        'compliance_documents.php'          => 'compliance_documents',
        'e_signatures.php'                  => 'e_signatures',
        'select_document_add_esignature.php' => 'e_signatures',
        'preview_template.php'              => 'document_templates',

        // Reports
        'reports.php'              => 'reports',
        'sales_report.php'         => 'sales_report',
        'tax_report.php'           => 'tax_report',
        'general_ledger.php'       => 'ledger_report',
        'income_statement.php'     => 'income_statement',
        'balance_sheet.php'        => 'balance_sheet',
        'cash_flow.php'            => 'cash_flow',
        'trial_balance.php'        => 'trial_balance',
        'financial_statements.php' => 'financial_statements',
        'audit_report.php'         => 'audit_report',
        'compliance_report.php'    => 'compliance',
        'customer_analysis.php'    => 'customer_analysis',
        'employee_report.php'      => 'employee_report',
        'product_analysis.php'     => 'product_analysis',
        'trends_analysis.php'      => 'trends_analysis',
        'asset_report.php'         => 'asset_report',
        'inventory_report.php'     => 'inventory_report',
        'ledger_report.php'        => 'ledger_report',
        'performance_dashboard.php'=> 'performance_dashboard',
        'daily_sales.php'          => 'reports',
        'low_stock.php'            => 'reports',
        'stock_value.php'          => 'reports',

        // User / Settings
        'add_user.php'      => 'add_user',
        'edit_user.php'     => 'edit_user',
        'profile.php'       => 'profile',
        'my_settings.php'   => 'my_settings',
        'help.php'          => 'help',
        'download_backup.php' => 'backup_restore',
        'system_status.php' => 'system_settings',
    ];
}

/**
 * Check if user has access to Reports module
 * @return bool
 */
function hasReportsAccess()
{
    if (isAdmin()) return true;
    
    // Check for any report permission
    $reportPermissions = [
        'income_statement', 'balance_sheet', 'cash_flow', 'trial_balance', 'ledger_report',
        'sales_report', 'purchase_report', 'inventory_report', 'profit_loss_report', 'expense_report',
        'performance_dashboard', 'customer_analysis', 'product_analysis', 'sales_forecast', 'trends_analysis',
        'tax_report', 'audit_report', 'compliance_report', 'employee_report', 'asset_report',
        'financial_statements',
    ];
    foreach ($reportPermissions as $perm) {
        if (canView($perm)) return true;
    }
    return false;
}

/**
 * Check if user has access to Accounts module
 * @return bool
 */
function hasAccountsAccess()
{
    if (isAdmin()) return true;
    
    // Check for accounts-related permissions
    $accPermissions = ['expenses', 'journals', 'budget', 'chart_of_accounts', 'transactions'];
    foreach ($accPermissions as $perm) {
        if (canView($perm)) return true;
    }
    return false;
}

/**
 * Check if user has access to Communication module
 * @return bool
 */
function hasCommunicationAccess()
{
    if (isAdmin()) return true;
    
    // Check for communication-related permissions
    $commPermissions = ['payment_reminders', 'sms_alerts', 'collection_letters', 'customers', 'campaign_management', 'lead_generation', 'customer_feedback'];
    foreach ($commPermissions as $perm) {
        if (canView($perm)) return true;
    }
    return false;
}

/**
 * Check if user has access to Documents module
 * @return bool
 */
function hasDocumentsAccess()
{
    if (isAdmin()) return true;
    
    // Check for business document permissions
    $docPermissions = ['invoices', 'customers', 'suppliers', 'purchase_orders'];
    foreach ($docPermissions as $perm) {
        if (canView($perm)) return true;
    }
    return false;
}

/**
 * Check if user has access to Integrations module
 * @return bool
 */
function hasIntegrationsAccess()
{
    if (isAdmin()) return true;
    
    // Integrations typically admin-only, but can be extended
    return false;
}

/**
 * Check if user has access to Support module
 * @return bool
 */
function hasSupportAccess()
{
    // Support is available to all logged-in users
    return true;
}


/**
 * Automatically enforce permission for the current page
 * Should be called in header.php or a global include
 * 
 * @param string|null $pageKey Optional explicit page key to check
 * @return void
 */
function autoEnforcePermission($pageKey = null)
{
    // If explicit key provided, use it
    if ($pageKey) {
        requireViewPermission($pageKey);
        return;
    }

    // Identify the current page. If we are in a routed environment (index.php),
    // we need to look at the backtrace to find the actual file being required.
    $currentPage = basename($_SERVER['PHP_SELF']);
    if ($currentPage === 'index.php') {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        if (isset($backtrace[0]['file'])) {
            $currentPage = basename($backtrace[0]['file']);
        }
    }

    $mapping = getPagePermissionMapping();
    
    // Exclude special system pages that don't need permission checks
    $excludedPages = ['unauthorized.php', 'login.php', 'logout.php', 'my-dashbord.php'];
    
    // If the current page is in the mapping and not excluded, enforce the permission
    if (isset($mapping[$currentPage]) && !in_array($currentPage, $excludedPages)) {
        requireViewPermission($mapping[$currentPage]);
    }
}
/**
 * Get the landing page for the current user based on permissions
 * 
 * @return string URL to redirect to
 */
function getLandingPage()
{
    // All users go to main dashboard by default unless specialized dashboards are ready
    return 'dashboard';
}
?>
