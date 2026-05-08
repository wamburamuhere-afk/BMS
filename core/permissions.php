<?php
/**
 * Permission Management System
 * Handles loading and checking user permissions
 * 
 * This system provides granular access control with view, edit, and delete
 * permissions for each page/module in the application.
 */

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
                rp.can_delete
            FROM role_permissions rp
            JOIN permissions p ON p.permission_id = rp.permission_id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);

        $permissions = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $permissions[$row['page_key']] = [
                'view'   => (bool)$row['can_view'],
                'create' => (bool)$row['can_create'],
                'edit'   => (bool)$row['can_edit'],
                'delete' => (bool)$row['can_delete'],
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
 * Check if user is admin (has full access)
 * 
 * @return bool True if user is admin
 */
function isAdmin()
{
    // ONLY role_id 1 is the Super Admin who bypasses all permission checks
    if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) {
        return true;
    }
    
    return false;
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

        // Finance
        'invoices.php' => 'invoices',
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
    ];
}

/**
 * Check if user has access to Reports module
 * @return bool
 */
function hasReportsAccess()
{
    if (isAdmin()) return true;
    
    // Check for business report permissions
    $reportPermissions = ['financial_statements', 'sales_reports', 'inventory_reports', 'income_statement', 'balance_sheet', 'trial_balance'];
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
