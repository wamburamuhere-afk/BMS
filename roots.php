<?php
// Enable Error Reporting for Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Ensure session cookie is accessible across the whole site
// This must be set BEFORE session_start()
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_path', '/');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
    session_start();
}

// Start the buffer to capture content
if (ob_get_level() === 0) ob_start();

// Ensure database connection is available in global scope
global $pdo, $pdo_accounts;

// Define root directory
define('ROOT_DIR', __DIR__);

// ============================================================================
// MODULE DIRECTORY DEFINITIONS
// ============================================================================
// Shared & Constant Modules
define('ACCOUNTS_DIR', ROOT_DIR . '/app/constant/accounts');
define('COMMUNICATION_DIR', ROOT_DIR . '/app/constant/communication');
define('DOCUMENT_DIR', ROOT_DIR . '/app/constant/document');
define('INTEGRATIONS_DIR', ROOT_DIR . '/app/constant/integrations');
define('PROFILE_DIR', ROOT_DIR . '/app/constant/profile');
define('RESOURCES_DIR', ROOT_DIR . '/app/constant/resources');
define('REPORTS_DIR', ROOT_DIR . '/app/constant/reports');
define('SETTINGS_DIR', ROOT_DIR . '/app/constant/settings');

// BMS Modules (Core Business)
define('BMS_DIR', ROOT_DIR . '/app/bms');
define('CUSTOMERS_DIR', BMS_DIR . '/customer');
define('SUPPLIERS_DIR', BMS_DIR . '/Suppliers');
define('INVOICE_DIR', BMS_DIR . '/invoice');
define('POS_DIR', BMS_DIR . '/pos');
define('PRODUCT_DIR', BMS_DIR . '/product');
define('PURCHASE_DIR', BMS_DIR . '/purchase');
define('SALES_DIR', BMS_DIR . '/sales');
define('STOCK_DIR', BMS_DIR . '/stock');
define('BANKING_DIR', BMS_DIR . '/banking');
define('GRN_DIR', BMS_DIR . '/grn');
define('OPERATIONS_DIR', BMS_DIR . '/operations');
define('TENDERS_DIR', BMS_DIR . '/tenders');
define('CRM_DIR',     BMS_DIR . '/crm');

// Special Directories
define('API_DIR', ROOT_DIR . '/api');
define('AJAX_DIR', ROOT_DIR . '/ajax');
define('COMING_SOON_FILE', ROOT_DIR . '/app/coming_soon.php');


// ============================================================================
// CORE FILE DEFINITIONS
// ============================================================================
define('HEADER_FILE', ROOT_DIR . '/header.php');
define('FOOTER_FILE', ROOT_DIR . '/footer.php');
define('HELPERS_FILE', ROOT_DIR . '/helpers.php');
define('CONFIG_FILE', ROOT_DIR . '/includes/config.php');
define('INDEX_FILE', ROOT_DIR . '/index.php');
define('LOGIN_FILE', ROOT_DIR . '/login.php');
define('LOGOUT_FILE', ROOT_DIR . '/logout.php');

// Automatically include core utilities
require_once CONFIG_FILE;
require_once HELPERS_FILE; // Load Helper Functions

// Apply the admin-configured timezone now that get_setting() + $pdo are available.
// config.php sets a hardcoded default earlier as a pre-DB fallback; this makes the
// General > Timezone setting actually take effect for both PHP and MySQL.
$bms_timezone = get_setting('timezone', 'Africa/Dar_es_Salaam');
if (in_array($bms_timezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($bms_timezone);
    try {
        $bms_tz_offset = (new DateTime('now', new DateTimeZone($bms_timezone)))->format('P');
        $pdo->exec("SET time_zone = '" . $bms_tz_offset . "'");
    } catch (Throwable $e) {
        // Keep the config.php default offset if this can't be applied.
    }
}

require_once ROOT_DIR . '/core/permissions.php'; // Load permissions
require_once ROOT_DIR . '/actions/check_auth.php';


// ============================================================================
// URL ROUTING MAP
// ============================================================================
// This array maps clean URLs to actual PHP files
// 
// HOW TO ADD NEW ROUTES:
// 1. Find the appropriate category section below
// 2. Add your route in the format: 'clean/url' => DIRECTORY_CONSTANT . '/file.php'
// 3. For API/AJAX routes, add both clean and .php versions for compatibility
// 4. Keep routes alphabetically sorted within each section for easy maintenance
//
// EXAMPLE:
//    'customers/new_feature' => CUSTOMERS_DIR . '/new_feature.php',
//
// ============================================================================

$routes = [
    // ========================================================================
    // CORE PAGES
    // ========================================================================
    'login'          => ROOT_DIR . '/login.php',
    'logout'         => ROOT_DIR . '/logout.php',
    'dashboard'      => ROOT_DIR . '/app/dashboard.php',
    'activity_log'   => ROOT_DIR . '/app/activity_log.php',
    'profile'        => PROFILE_DIR . '/profile.php',
    'unauthorized'   => ROOT_DIR . '/unauthorized.php',

    // ========================================================================
    // ACCOUNTS MODULE (App Directory)
    // ========================================================================
    'bank-reconciliation' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'bank_reconciliation' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'bank_reconciliation.php' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'bank-accounts' => ACCOUNTS_DIR . '/bank_accounts.php',
    'bank_accounts' => ACCOUNTS_DIR . '/bank_accounts.php',
    'bank_accounts.php' => ACCOUNTS_DIR . '/bank_accounts.php',
    'account/details' => ACCOUNTS_DIR . '/account_details.php',
    'account/view' => ACCOUNTS_DIR . '/account_details.php',
    'budget' => ACCOUNTS_DIR . '/budget.php',
    'budget/view' => ACCOUNTS_DIR . '/budget_details.php',
    'budget/details' => ACCOUNTS_DIR . '/budget_details.php',
    'cash-register' => ACCOUNTS_DIR . '/cash_register.php',
    'cash_register' => ACCOUNTS_DIR . '/cash_register.php',
    'cash_register.php' => ACCOUNTS_DIR . '/cash_register.php',
    'chart-of-accounts' => ACCOUNTS_DIR . '/chart_of_accounts.php',
    'chart_of_accounts' => ACCOUNTS_DIR . '/chart_of_accounts.php',
    'chart_of_accounts.php' => ACCOUNTS_DIR . '/chart_of_accounts.php',
    'expenses' => ACCOUNTS_DIR . '/expenses.php',
    'expenses/view' => ACCOUNTS_DIR . '/expense_details.php',
    'expenses/details' => ACCOUNTS_DIR . '/expense_details.php',
    'expenses/edit' => ACCOUNTS_DIR . '/edit_expense.php',
    'expenses/export' => API_DIR . '/export_expenses.php',
    'expense_types' => ACCOUNTS_DIR . '/expense_types.php',
    'expense_types.php' => ACCOUNTS_DIR . '/expense_types.php',
    'expense-types' => ACCOUNTS_DIR . '/expense_types.php',
    'recurring' => ACCOUNTS_DIR . '/recurring.php',
    'recurring.php' => ACCOUNTS_DIR . '/recurring.php',
    'recurring_documents' => ACCOUNTS_DIR . '/recurring.php',
    'revenue' => ACCOUNTS_DIR . '/revenue.php',
    'revenue.php' => ACCOUNTS_DIR . '/revenue.php',
    'revenue_categories' => ACCOUNTS_DIR . '/revenue_categories.php',
    'revenue_categories.php' => ACCOUNTS_DIR . '/revenue_categories.php',
    'revenue-categories' => ACCOUNTS_DIR . '/revenue_categories.php',
    'journals' => ACCOUNTS_DIR . '/journals.php',
    'journal/view' => ACCOUNTS_DIR . '/journal_details.php',
    'journal/details' => ACCOUNTS_DIR . '/journal_details.php',
    'journal/add' => ACCOUNTS_DIR . '/add_journal.php',
    'journal/edit' => ACCOUNTS_DIR . '/edit_journal.php',
    'petty-cash' => ACCOUNTS_DIR . '/petty_cash.php',
    'petty_cash' => ACCOUNTS_DIR . '/petty_cash.php',
    'petty_cash.php' => ACCOUNTS_DIR . '/petty_cash.php',
    'reconciliation' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'reconciliation/view' => ACCOUNTS_DIR . '/reconciliation_details.php',
    'bank-reconciliation/view' => ACCOUNTS_DIR . '/reconciliation_details.php',
    'bank_statement' => ACCOUNTS_DIR . '/bank_statement.php',
    'bank_statement.php' => ACCOUNTS_DIR . '/bank_statement.php',
    'bank-statement' => ACCOUNTS_DIR . '/bank_statement.php',
    'bank_transfers' => ACCOUNTS_DIR . '/bank_transfers.php',
    'bank_transfers.php' => ACCOUNTS_DIR . '/bank_transfers.php',
    'bank-transfers' => ACCOUNTS_DIR . '/bank_transfers.php',
    'transactions' => ACCOUNTS_DIR . '/transactions.php',
    'transaction/view' => ACCOUNTS_DIR . '/transaction_details.php',
    'transaction/details' => ACCOUNTS_DIR . '/transaction_details.php',
    'trial-balance' => ACCOUNTS_DIR . '/trial_balance.php',

    // Original routes kept for backward compatibility if needed by any scripts
    'accounts/bank_reconciliation' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'accounts/bank_reconciliation.php' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'accounts/budget' => ACCOUNTS_DIR . '/budget.php',
    'accounts/budget.php' => ACCOUNTS_DIR . '/budget.php',
    'accounts/budget_details' => ACCOUNTS_DIR . '/budget_details.php',
    'accounts/budget_details.php' => ACCOUNTS_DIR . '/budget_details.php',
    'accounts/chart_of_accounts' => ACCOUNTS_DIR . '/chart_of_accounts.php',
    'accounts/chart_of_accounts.php' => ACCOUNTS_DIR . '/chart_of_accounts.php',
    'accounts/edit_expense' => ACCOUNTS_DIR . '/edit_expense.php',
    'accounts/edit_expense.php' => ACCOUNTS_DIR . '/edit_expense.php',
    'accounts/edit_journal' => ACCOUNTS_DIR . '/edit_journal.php',
    'accounts/edit_journal.php' => ACCOUNTS_DIR . '/edit_journal.php',
    'accounts/expense_details' => ACCOUNTS_DIR . '/expense_details.php',
    'accounts/expense_details.php' => ACCOUNTS_DIR . '/expense_details.php',
    'accounts/expenses' => ACCOUNTS_DIR . '/expenses.php',
    'accounts/expenses.php' => ACCOUNTS_DIR . '/expenses.php',
    'accounts/add_journal' => ACCOUNTS_DIR . '/add_journal.php',
    'accounts/add_journal.php' => ACCOUNTS_DIR . '/add_journal.php',
    'accounts/journal_details' => ACCOUNTS_DIR . '/journal_details.php',
    'accounts/journal_details.php' => ACCOUNTS_DIR . '/journal_details.php',
    'accounts/journals' => ACCOUNTS_DIR . '/journals.php',
    'accounts/journals.php' => ACCOUNTS_DIR . '/journals.php',
    'accounts/transaction_details' => ACCOUNTS_DIR . '/transaction_details.php',
    'accounts/transaction_details.php' => ACCOUNTS_DIR . '/transaction_details.php',
    'accounts/transactions' => ACCOUNTS_DIR . '/transactions.php',
    'accounts/transactions.php' => ACCOUNTS_DIR . '/transactions.php',
    'accounts/trial_balance' => ACCOUNTS_DIR . '/trial_balance.php',
    'accounts/trial_balance.php' => ACCOUNTS_DIR . '/trial_balance.php',
    // Ledger report
    'ledger_report' => REPORTS_DIR . '/ledger_report.php',
    'ledger_report.php' => REPORTS_DIR . '/ledger_report.php',
    // New financial reports
    'sales_report' => REPORTS_DIR . '/sales_report.php',
    'sales_report.php' => REPORTS_DIR . '/sales_report.php',
    // Receivables / Payables aging + party statements
    'ar_aging' => REPORTS_DIR . '/ar_aging.php',
    'ar_aging.php' => REPORTS_DIR . '/ar_aging.php',
    'ap_aging' => REPORTS_DIR . '/ap_aging.php',
    'ap_aging.php' => REPORTS_DIR . '/ap_aging.php',
    'customer_statement' => REPORTS_DIR . '/customer_statement.php',
    'customer_statement.php' => REPORTS_DIR . '/customer_statement.php',
    'vendor_statement' => REPORTS_DIR . '/vendor_statement.php',
    'vendor_statement.php' => REPORTS_DIR . '/vendor_statement.php',
    'employee_statement' => REPORTS_DIR . '/employee_statement.php',
    'employee_statement.php' => REPORTS_DIR . '/employee_statement.php',
    'purchase_report' => REPORTS_DIR . '/purchase_report.php',
    'purchase_report.php' => REPORTS_DIR . '/purchase_report.php',
    'inventory_report' => REPORTS_DIR . '/inventory_report.php',
    'inventory_report.php' => REPORTS_DIR . '/inventory_report.php',
    'asset_report' => REPORTS_DIR . '/asset_report.php',
    'asset_report.php' => REPORTS_DIR . '/asset_report.php',
    'audit_report' => REPORTS_DIR . '/audit_report.php',
    'audit_report.php' => REPORTS_DIR . '/audit_report.php',
    'audit_logs' => REPORTS_DIR . '/audit_logs.php',
    'audit_logs.php' => REPORTS_DIR . '/audit_logs.php',
    'customer_analysis' => REPORTS_DIR . '/customer_analysis.php',
    'customer_analysis.php' => REPORTS_DIR . '/customer_analysis.php',
    'product_analysis' => REPORTS_DIR . '/product_analysis.php',
    'product_analysis.php' => REPORTS_DIR . '/product_analysis.php',
    'trends_analysis' => REPORTS_DIR . '/trends_analysis.php',
    'trends_analysis.php' => REPORTS_DIR . '/trends_analysis.php',
    'tax_report' => REPORTS_DIR . '/tax_report.php',
    'tax_report.php' => REPORTS_DIR . '/tax_report.php',
    'wht_report' => REPORTS_DIR . '/wht_report.php',
    'wht_report.php' => REPORTS_DIR . '/wht_report.php',
    'wht_receivable_report' => REPORTS_DIR . '/wht_receivable_report.php',
    'wht_receivable_report.php' => REPORTS_DIR . '/wht_receivable_report.php',
    'performance_dashboard' => REPORTS_DIR . '/performance_dashboard.php',
    'performance_dashboard.php' => REPORTS_DIR . '/performance_dashboard.php',
    'financial_statements' => REPORTS_DIR . '/financial_statements.php',
    'financial_statements.php' => REPORTS_DIR . '/financial_statements.php',
    'accounts/account_details' => ACCOUNTS_DIR . '/account_details.php',
    'accounts/account_details.php' => ACCOUNTS_DIR . '/account_details.php',
    'accounts/export_expenses' => API_DIR . '/account/export_expenses.php',
    'accounts/export_expenses.php' => API_DIR . '/account/export_expenses.php',


    'accounts/reconciliation_details' => ACCOUNTS_DIR . '/reconciliation_details.php',
    'accounts/reconciliation_details.php' => ACCOUNTS_DIR . '/reconciliation_details.php',
    'reconciliation_details' => ACCOUNTS_DIR . '/reconciliation_details.php',
    'reconciliation_details.php' => ACCOUNTS_DIR . '/reconciliation_details.php',

    'cash_register_details' => ACCOUNTS_DIR . '/cash_register_details.php',
    'cash_register_details.php' => ACCOUNTS_DIR . '/cash_register_details.php',

    'petty_cash_print' => ACCOUNTS_DIR . '/petty_cash_print.php',
    'petty_cash_print.php' => ACCOUNTS_DIR . '/petty_cash_print.php',
    'payment_vouchers' => ACCOUNTS_DIR . '/payment_vouchers.php',
    'payment_vouchers.php' => ACCOUNTS_DIR . '/payment_vouchers.php',
    'payment_voucher_view' => ACCOUNTS_DIR . '/payment_voucher_details.php',
    'payment_voucher_view.php' => ACCOUNTS_DIR . '/payment_voucher_details.php',
    'payment_voucher_print' => ACCOUNTS_DIR . '/payment_voucher_print.php',
    'payment_voucher_print.php' => ACCOUNTS_DIR . '/payment_voucher_print.php',

    // ========================================================================
    // CUSTOMERS MODULE (BMS Directory)
    // ========================================================================
    'customers' => CUSTOMERS_DIR . '/customers.php',
    'customers.php' => CUSTOMERS_DIR . '/customers.php',
    'customers/details' => CUSTOMERS_DIR . '/customer_details.php',
    'customers/view' => CUSTOMERS_DIR . '/customer_details.php',
    'customers/edit' => CUSTOMERS_DIR . '/edit_customer.php',
    'customers/group_details' => CUSTOMERS_DIR . '/customer_group_details.php',
    'customers/group_members' => CUSTOMERS_DIR . '/customer_group_members.php',
    'customers/groups' => CUSTOMERS_DIR . '/customer_groups.php',
    'customers/import' => CUSTOMERS_DIR . '/customer_import.php',

    // ========================================================================
    // SUPPLIERS MODULE (BMS Directory)
    // ========================================================================
    'suppliers' => SUPPLIERS_DIR . '/suppliers.php',
    'suppliers.php' => SUPPLIERS_DIR . '/suppliers.php',
    'suppliers/details' => SUPPLIERS_DIR . '/supplier_details.php',
    'suppliers/view' => SUPPLIERS_DIR . '/supplier_details.php',
    'suppliers/payments' => SUPPLIERS_DIR . '/supplier_payments.php',
    'suppliers/categories' => SUPPLIERS_DIR . '/supplier_categories.php',

    // ========================================================================
    // SUB-CONTRACTORS MODULE (BMS Directory)
    // ========================================================================
    'sub_contractors' => OPERATIONS_DIR . '/sub_contractors.php',
    'sub_contractors.php' => OPERATIONS_DIR . '/sub_contractors.php',
    'sub_contractors/details' => OPERATIONS_DIR . '/sub_contractor_details.php',
    'sub_contractors/view' => OPERATIONS_DIR . '/sub_contractor_details.php',

    // ========================================================================
    // PRODUCTS & INVENTORY (BMS Directory)
    // ========================================================================
    'products' => PRODUCT_DIR . '/products.php',
    'products.php' => PRODUCT_DIR . '/products.php',
    'services' => PRODUCT_DIR . '/services.php',
    'services.php' => PRODUCT_DIR . '/services.php',
    'service_view' => PRODUCT_DIR . '/service_view.php',
    'service_view.php' => PRODUCT_DIR . '/service_view.php',
    'services/view' => PRODUCT_DIR . '/service_view.php',
    'product_create' => PRODUCT_DIR . '/product_create.php',
    'product_create.php' => PRODUCT_DIR . '/product_create.php',
    'products/create' => PRODUCT_DIR . '/product_create.php',
    'product_edit' => PRODUCT_DIR . '/product_edit.php',
    'product_edit.php' => PRODUCT_DIR . '/product_edit.php',
    'products/edit' => PRODUCT_DIR . '/product_edit.php',
    'product_view' => PRODUCT_DIR . '/product_view.php',
    'product_view.php' => PRODUCT_DIR . '/product_view.php',
    'products/view' => PRODUCT_DIR . '/product_view.php',
    'warehouses' => STOCK_DIR . '/warehouses.php',
    'warehouses.php' => STOCK_DIR . '/warehouses.php',
    'locations' => STOCK_DIR . '/locations.php',
    'locations.php' => STOCK_DIR . '/locations.php',
    'categories' => PRODUCT_DIR . '/categories.php',
    'categories.php' => PRODUCT_DIR . '/categories.php',
    'brands' => PRODUCT_DIR . '/brands.php',
    'brands.php' => PRODUCT_DIR . '/brands.php',
    'product_import.php' => PRODUCT_DIR . '/product_import.php',
    'stock_adjustments' => STOCK_DIR . '/stock_adjustments.php',
    'stock_adjustments.php' => STOCK_DIR . '/stock_adjustments.php',
    'stock_movements' => STOCK_DIR . '/stock_movements.php',
    'stock_movements.php' => STOCK_DIR . '/stock_movements.php',
    'adjustment_print' => STOCK_DIR . '/adjustment_print.php',
    'adjustment_print.php' => STOCK_DIR . '/adjustment_print.php',
    'inventory_valuation' => STOCK_DIR . '/inventory_valuation.php',
    'inventory_valuation.php' => STOCK_DIR . '/inventory_valuation.php',
    'stock_transfers' => STOCK_DIR . '/stock_transfers.php',
    'stock_transfers.php' => STOCK_DIR . '/stock_transfers.php',
    'warehouse_view' => STOCK_DIR . '/warehouse_view.php',
    'warehouse_view.php' => STOCK_DIR . '/warehouse_view.php',
    'bms/warehouse_view' => STOCK_DIR . '/warehouse_view.php',
    'bms/locations' => STOCK_DIR . '/locations.php',
    'bms/stock_transfers' => STOCK_DIR . '/stock_transfers.php',
    'ajax_get_warehouse' => ROOT_DIR . '/ajax_get_warehouse.php',
    'ajax_get_warehouse.php' => ROOT_DIR . '/ajax_get_warehouse.php',
    'ajax_delete_warehouse.php' => ROOT_DIR . '/ajax_delete_warehouse.php',
    'ajax_toggle_warehouse_status.php' => ROOT_DIR . '/ajax_toggle_warehouse_status.php',
    'bms/ajax_get_warehouse' => ROOT_DIR . '/ajax_get_warehouse.php',
    'bms/ajax_get_warehouse.php' => ROOT_DIR . '/ajax_get_warehouse.php',
    'bms/ajax_delete_warehouse.php' => ROOT_DIR . '/ajax_delete_warehouse.php',
    'bms/ajax_toggle_warehouse_status.php' => ROOT_DIR . '/ajax_toggle_warehouse_status.php',
    'ajax_get_transfer_items.php' => ROOT_DIR . '/ajax_get_transfer_items.php',
    'bms/ajax_get_transfer_items.php' => ROOT_DIR . '/ajax_get_transfer_items.php',
    'print_transfer.php' => ROOT_DIR . '/print_transfer.php',
    'bms/print_transfer.php' => ROOT_DIR . '/print_transfer.php',
    'bms/assets' => ROOT_DIR . '/app/bms/operations/assets.php',
    'assets' => ROOT_DIR . '/app/bms/operations/assets.php',
    'asset_categories' => ROOT_DIR . '/app/constant/settings/asset_categories.php',
    'asset_settings' => ROOT_DIR . '/app/constant/settings/asset_settings.php',
    'asset_view' => ROOT_DIR . '/app/bms/operations/asset_view.php',
    'asset_schedule' => ROOT_DIR . '/app/constant/reports/asset_schedule.php',
    'asset_dashboard' => ROOT_DIR . '/app/bms/operations/asset_dashboard.php',
    'asset_verify' => ROOT_DIR . '/app/bms/operations/asset_verify.php',

    // ========================================================================
    // SALES & INVOICES (BMS Directory)
    // ========================================================================
    'invoices' => INVOICE_DIR . '/invoices.php',
    'invoices.php' => INVOICE_DIR . '/invoices.php',
    'receive_payment' => ACCOUNTS_DIR . '/receive_payment.php',
    'receive_payment.php' => ACCOUNTS_DIR . '/receive_payment.php',
    'receive-payment' => ACCOUNTS_DIR . '/receive_payment.php',
    'customer_deposits' => ACCOUNTS_DIR . '/customer_deposits.php',
    'customer_deposits.php' => ACCOUNTS_DIR . '/customer_deposits.php',
    'customer-deposits' => ACCOUNTS_DIR . '/customer_deposits.php',
    'received_invoices' => INVOICE_DIR . '/received_invoices.php',
    'received_invoices.php' => INVOICE_DIR . '/received_invoices.php',
    'received_invoices_view' => INVOICE_DIR . '/received_invoices_view.php',
    'received_invoices_view.php' => INVOICE_DIR . '/received_invoices_view.php',
    'po_invoice_report' => INVOICE_DIR . '/po_invoice_report.php',
    'po_invoice_report.php' => INVOICE_DIR . '/po_invoice_report.php',
    'invoice_create' => INVOICE_DIR . '/invoice_create.php',
    'invoice_create.php' => INVOICE_DIR . '/invoice_create.php',
    'invoice_edit' => INVOICE_DIR . '/invoice_edit.php',
    'invoice_edit.php' => INVOICE_DIR . '/invoice_edit.php',
    'invoice_view' => INVOICE_DIR . '/invoice_view.php',
    'invoice_view.php' => INVOICE_DIR . '/invoice_view.php',
    'invoice_print' => INVOICE_DIR . '/invoice_print.php',
    'invoice_print.php' => INVOICE_DIR . '/invoice_print.php',
    'payment_create' => INVOICE_DIR . '/payment_create.php',
    'payment_create.php' => INVOICE_DIR . '/payment_create.php',
    'income_statement' => INVOICE_DIR . '/income_statement.php',
    'income_statement.php' => INVOICE_DIR . '/income_statement.php',
    'balance_sheet' => REPORTS_DIR . '/balance_sheet.php',
    'cash_flow' => REPORTS_DIR . '/cash_flow_gl.php',   // GL-derived, reconciling (old non-reconciling cash_flow.php retired from the route)
    'consolidated_expenses' => REPORTS_DIR . '/consolidated_expenses.php',
    'trial_balance' => REPORTS_DIR . '/trial_balance.php',
    'books_health' => REPORTS_DIR . '/books_health.php',
    'books_health.php' => REPORTS_DIR . '/books_health.php',
    'reports' => INVOICE_DIR . '/reports.php',
    'reports.php' => INVOICE_DIR . '/reports.php',
    // ========================================================================
    // CRM MODULE
    // ========================================================================
    'crm/dashboard'        => CRM_DIR . '/crm_dashboard.php',
    'crm/leads'            => CRM_DIR . '/crm_leads.php',
    'crm/lead_view'        => CRM_DIR . '/crm_lead_view.php',
    'crm/pipeline'         => CRM_DIR . '/crm_pipeline.php',
    'crm/pipeline_stages'  => CRM_DIR . '/crm_pipeline_stages.php',
    'crm/import_leads'     => CRM_DIR . '/crm_import_leads.php',

    // CRM APIs
    'api/crm/add_lead'           => API_DIR . '/crm/add_lead.php',
    'api/crm/edit_lead'          => API_DIR . '/crm/edit_lead.php',
    'api/crm/delete_lead'        => API_DIR . '/crm/delete_lead.php',
    'api/crm/get_lead'           => API_DIR . '/crm/get_lead.php',
    'api/crm/move_lead_stage'    => API_DIR . '/crm/move_lead_stage.php',
    'api/crm/convert_lead'       => API_DIR . '/crm/convert_lead.php',
    'api/crm/add_activity'       => API_DIR . '/crm/add_activity.php',
    'api/crm/edit_activity'      => API_DIR . '/crm/edit_activity.php',
    'api/crm/delete_activity'    => API_DIR . '/crm/delete_activity.php',
    'api/crm/get_activities'     => API_DIR . '/crm/get_activities.php',
    'api/crm/get_pipeline_data'  => API_DIR . '/crm/get_pipeline_data.php',
    'api/crm/get_dashboard_data' => API_DIR . '/crm/get_dashboard_data.php',
    'api/crm/manage_stage'       => API_DIR . '/crm/manage_stage.php',
    'api/crm/export_leads'       => API_DIR . '/crm/export_leads.php',
    'api/crm/import_leads'       => API_DIR . '/crm/import_leads.php',

    // ========================================================================
    // SALES MODULE
    // ========================================================================
    'sales_orders' => SALES_DIR . '/sales_orders.php',
    'sales_orders.php' => SALES_DIR . '/sales_orders.php',
    'sales_order_create' => SALES_DIR . '/sales_order_create.php',
    'sales_order_create.php' => SALES_DIR . '/sales_order_create.php',
    'sales_order_edit' => SALES_DIR . '/sales_order_edit.php',
    'sales_order_edit.php' => SALES_DIR . '/sales_order_edit.php',
    'sales_orders/create' => SALES_DIR . '/sales_order_create.php',
    'sales_order_view' => SALES_DIR . '/sales_order_view.php',
    'sales_order_view.php' => SALES_DIR . '/sales_order_view.php',
    'quotations' => SALES_DIR . '/quotations/quotations.php',
    'quotations.php' => SALES_DIR . '/quotations/quotations.php',
    'quotation_view' => SALES_DIR . '/quotations/quotation_view.php',
    'quotation_view.php' => SALES_DIR . '/quotations/quotation_view.php',
    'quotation_create' => SALES_DIR . '/quotations/quotation_create.php',
    'quotation_create.php' => SALES_DIR . '/quotations/quotation_create.php',
    'quotation_edit' => SALES_DIR . '/quotations/quotation_edit.php',
    'quotation_edit.php' => SALES_DIR . '/quotations/quotation_edit.php',
    'lpos' => SALES_DIR . '/lpo/lpos.php',
    'lpos.php' => SALES_DIR . '/lpo/lpos.php',
    'lpo_create' => SALES_DIR . '/lpo/lpo_create.php',
    'lpo_create.php' => SALES_DIR . '/lpo/lpo_create.php',
    'lpo_view' => SALES_DIR . '/lpo/lpo_view.php',
    'lpo_view.php' => SALES_DIR . '/lpo/lpo_view.php',
    'print_ipc' => OPERATIONS_DIR . '/print_ipc.php',
    'print_ipc.php' => OPERATIONS_DIR . '/print_ipc.php',
    'print_sales_order' => SALES_DIR . '/print_sales_order.php',
    'print_sales_order.php' => SALES_DIR . '/print_sales_order.php',
    'print_quotation' => SALES_DIR . '/quotations/print_quotation.php',
    'print_quotation.php' => SALES_DIR . '/quotations/print_quotation.php',
    'print_purchase_order' => API_DIR . '/account/print_purchase_order.php',
    'print-purchase-order' => API_DIR . '/account/print_purchase_order.php',
    'print_purchase_order.php' => API_DIR . '/account/print_purchase_order.php',
    'print_purchase_order_navy' => API_DIR . '/account/print_purchase_order_navy.php',
    'print_purchase_order_navy.php' => API_DIR . '/account/print_purchase_order_navy.php',
    'print_purchase_order_corporate' => API_DIR . '/account/print_purchase_order_corporate.php',
    'print_purchase_order_corporate.php' => API_DIR . '/account/print_purchase_order_corporate.php',
    'print_purchase_order_banded' => API_DIR . '/account/print_purchase_order_banded.php',
    'print_purchase_order_banded.php' => API_DIR . '/account/print_purchase_order_banded.php',
    'print_lpo' => SALES_DIR . '/lpo/print_lpo.php',
    'print_lpo.php' => SALES_DIR . '/lpo/print_lpo.php',
    'api/review_lpo' => API_DIR . '/customer/review_lpo.php',
    'api/review_lpo.php' => API_DIR . '/customer/review_lpo.php',
    'api/approve_lpo' => API_DIR . '/customer/approve_lpo.php',
    'api/approve_lpo.php' => API_DIR . '/customer/approve_lpo.php',
    'print_delivery_note' => API_DIR . '/account/print_delivery_note.php',
    'print-delivery-note' => API_DIR . '/account/print_delivery_note.php',
    'print_delivery_note.php' => API_DIR . '/account/print_delivery_note.php',
    'print_rfq' => API_DIR . '/account/print_rfq.php',
    'print-rfq' => API_DIR . '/account/print_rfq.php',
    'print_rfq.php' => API_DIR . '/account/print_rfq.php',
    'api/get_purchase_orders' => API_DIR . '/account/get_purchase_orders.php',
    'api/delete_purchase_order' => API_DIR . '/account/delete_purchase_order.php',
    'api/get_lpos_list' => API_DIR . '/customer/get_lpos_list.php',
    'api/get_lpos_list.php' => API_DIR . '/customer/get_lpos_list.php',
    'sales_returns' => SALES_DIR . '/sales_returns/sales_returns.php',
    'sales_returns.php' => SALES_DIR . '/sales_returns/sales_returns.php',
    'sales_return_create' => SALES_DIR . '/sales_returns/sales_return_create.php',
    'print_sales_return' => SALES_DIR . '/sales_returns/print_sales_return.php',
    'sales_return_view' => SALES_DIR . '/sales_returns/sales_return_view.php',
    'sales_return_edit' => SALES_DIR . '/sales_returns/sales_return_edit.php',
    'credit_notes' => SALES_DIR . '/credit_notes/credit_notes.php',
    'credit_notes.php' => SALES_DIR . '/credit_notes/credit_notes.php',
    'credit_note_create' => SALES_DIR . '/credit_notes/credit_note_create.php',
    'credit_note_edit' => SALES_DIR . '/credit_notes/credit_note_edit.php',
    'credit_note_view' => SALES_DIR . '/credit_notes/credit_note_view.php',
    'print_credit_note' => SALES_DIR . '/credit_notes/print_credit_note.php',
    'pos' => POS_DIR . '/pos.php',

    // ========================================================================
    // PURCHASES (BMS Directory)
    // ========================================================================
    // RFQ (Request for Quotation)
    'rfq'                 => PURCHASE_DIR . '/rfq.php',
    'rfq.php'             => PURCHASE_DIR . '/rfq.php',
    'rfq_create'          => PURCHASE_DIR . '/rfq_create.php',
    'rfq_create.php'      => PURCHASE_DIR . '/rfq_create.php',
    'rfq_view'            => PURCHASE_DIR . '/rfq_view.php',
    'rfq_view.php'        => PURCHASE_DIR . '/rfq_view.php',
    'api/get_rfqs'        => API_DIR . '/get_rfqs.php',
    'api/get_rfqs.php'    => API_DIR . '/get_rfqs.php',
    'api/create_rfq'      => API_DIR . '/create_rfq.php',
    'api/create_rfq.php'  => API_DIR . '/create_rfq.php',
    'api/update_rfq'      => API_DIR . '/update_rfq.php',
    'api/update_rfq.php'  => API_DIR . '/update_rfq.php',
    'api/delete_rfq'        => API_DIR . '/delete_rfq.php',
    'api/delete_rfq.php'    => API_DIR . '/delete_rfq.php',
    'api/get_rfq_items'     => API_DIR . '/get_rfq_items.php',
    'api/get_rfq_items.php' => API_DIR . '/get_rfq_items.php',
    'api/get_po_items'      => API_DIR . '/get_po_items.php',
    'api/get_po_items.php'  => API_DIR . '/get_po_items.php',
    'api/approve_rfq'       => API_DIR . '/approve_rfq.php',
    'api/approve_rfq.php'   => API_DIR . '/approve_rfq.php',
    'api/review_rfq'        => API_DIR . '/review_rfq.php',
    'api/review_rfq.php'    => API_DIR . '/review_rfq.php',
    'api/review_purchase_order'     => API_DIR . '/account/review_purchase_order.php',
    'api/review_purchase_order.php' => API_DIR . '/account/review_purchase_order.php',
    'api/approve_purchase_order'     => API_DIR . '/account/approve_purchase_order.php',
    'api/approve_purchase_order.php' => API_DIR . '/account/approve_purchase_order.php',
    'api/review_sales_order'        => API_DIR . '/account/review_sales_order.php',
    'api/review_sales_order.php'    => API_DIR . '/account/review_sales_order.php',
    'api/approve_sales_order'       => API_DIR . '/account/approve_sales_order.php',
    'api/approve_sales_order.php'   => API_DIR . '/account/approve_sales_order.php',
    'api/account/review_sales_order.php'  => API_DIR . '/account/review_sales_order.php',
    'api/account/approve_sales_order.php' => API_DIR . '/account/approve_sales_order.php',
    'api/review_invoice'              => API_DIR . '/account/review_invoice.php',
    'api/review_invoice.php'          => API_DIR . '/account/review_invoice.php',
    'api/approve_invoice'             => API_DIR . '/account/approve_invoice.php',
    'api/approve_invoice.php'         => API_DIR . '/account/approve_invoice.php',
    'api/account/review_invoice.php'  => API_DIR . '/account/review_invoice.php',
    'api/account/approve_invoice.php' => API_DIR . '/account/approve_invoice.php',
    'api/review_dn'      => API_DIR . '/review_dn.php',
    'api/review_dn.php'  => API_DIR . '/review_dn.php',
    'api/approve_dn'     => API_DIR . '/approve_dn.php',
    'api/approve_dn.php' => API_DIR . '/approve_dn.php',
    'api/review_grn'      => API_DIR . '/review_grn.php',
    'api/review_grn.php'  => API_DIR . '/review_grn.php',
    'api/approve_grn'     => API_DIR . '/approve_grn.php',
    'api/approve_grn.php' => API_DIR . '/approve_grn.php',
    'purchase_orders' => PURCHASE_DIR . '/purchase_orders.php',
    'purchase_orders.php' => PURCHASE_DIR . '/purchase_orders.php',
    'purchase_order_create' => PURCHASE_DIR . '/purchase_order_create.php',
    'purchase_order_create.php' => PURCHASE_DIR . '/purchase_order_create.php',
    'purchase_order_details' => PURCHASE_DIR . '/purchase_order_details.php',
    'purchase_order_details.php' => PURCHASE_DIR . '/purchase_order_details.php',
    'purchase_returns' => PURCHASE_DIR . '/purchase_returns.php',
    'purchase_returns.php' => PURCHASE_DIR . '/purchase_returns.php',
    'nip_materials' => PURCHASE_DIR . '/nip_materials.php',
    'nip_materials.php' => PURCHASE_DIR . '/nip_materials.php',
    'view_nip_materials' => PURCHASE_DIR . '/view_nip_materials.php',
    'view_nip_materials.php' => PURCHASE_DIR . '/view_nip_materials.php',
    'edit_nip_materials' => PURCHASE_DIR . '/edit_nip_materials.php',
    'edit_nip_materials.php' => PURCHASE_DIR . '/edit_nip_materials.php',
    'view_material_list' => PURCHASE_DIR . '/view_material_list.php',
    'view_material_list.php' => PURCHASE_DIR . '/view_material_list.php',
    'purchase_return_view' => PURCHASE_DIR . '/purchase_return_view.php',
    'purchase_return_view.php' => PURCHASE_DIR . '/purchase_return_view.php',
    'print_purchase_return' => PURCHASE_DIR . '/print_purchase_return.php',
    'print_purchase_return.php' => PURCHASE_DIR . '/print_purchase_return.php',
    'print_purchase_return_navy' => PURCHASE_DIR . '/print_purchase_return_navy.php',
    'print_purchase_return_navy.php' => PURCHASE_DIR . '/print_purchase_return_navy.php',
    'print_purchase_return_corporate' => PURCHASE_DIR . '/print_purchase_return_corporate.php',
    'print_purchase_return_corporate.php' => PURCHASE_DIR . '/print_purchase_return_corporate.php',
    'print_purchase_return_banded' => PURCHASE_DIR . '/print_purchase_return_banded.php',
    'print_purchase_return_banded.php' => PURCHASE_DIR . '/print_purchase_return_banded.php',
    'debit_notes' => PURCHASE_DIR . '/debit_notes/debit_notes.php',
    'debit_notes.php' => PURCHASE_DIR . '/debit_notes/debit_notes.php',
    'debit_note_create' => PURCHASE_DIR . '/debit_notes/debit_note_create.php',
    'debit_note_edit' => PURCHASE_DIR . '/debit_notes/debit_note_edit.php',
    'debit_note_view' => PURCHASE_DIR . '/debit_notes/debit_note_view.php',
    'print_debit_note' => PURCHASE_DIR . '/debit_notes/print_debit_note.php',
    'grn' => GRN_DIR . '/grn.php',
    'grn.php' => GRN_DIR . '/grn.php',
    'delivery_notes' => GRN_DIR . '/delivery_notes.php',
    'delivery_notes.php' => GRN_DIR . '/delivery_notes.php',
    'grn_create' => GRN_DIR . '/grn_create.php',
    'grn_create.php' => GRN_DIR . '/grn_create.php',
    'grn_view' => GRN_DIR . '/grn_view.php',
    'grn_view.php' => GRN_DIR . '/grn_view.php',
    'grn_edit' => GRN_DIR . '/grn_edit.php',
    'grn_edit.php' => GRN_DIR . '/grn_edit.php',
    'grn_print' => GRN_DIR . '/grn_print.php',
    'grn_print.php' => GRN_DIR . '/grn_print.php',
    'dn_view' => GRN_DIR . '/dn_view.php',
    'dn_view.php' => GRN_DIR . '/dn_view.php',
    'dn_create' => GRN_DIR . '/dn_create.php',
    'dn_create.php' => GRN_DIR . '/dn_create.php',
    'dn_outbound' => GRN_DIR . '/dn_outbound.php',
    'dn_outbound.php' => GRN_DIR . '/dn_outbound.php',
    'do_view' => GRN_DIR . '/do_view.php',
    'do_view.php' => GRN_DIR . '/do_view.php',
    'do_create' => GRN_DIR . '/do_create.php',
    'do_create.php' => GRN_DIR . '/do_create.php',

    // ========================================================================
    // TENDERS (BMS Directory)
    // ========================================================================
    'tenders' => TENDERS_DIR . '/tenders.php',
    'tenders.php' => TENDERS_DIR . '/tenders.php',
    'tender_create' => TENDERS_DIR . '/tender_create.php',
    'tender_create.php' => TENDERS_DIR . '/tender_create.php',
    'tender_view' => TENDERS_DIR . '/tender_view.php',
    'tender_view.php' => TENDERS_DIR . '/tender_view.php',
    'tender_edit' => TENDERS_DIR . '/tender_edit.php',
    'tender_edit.php' => TENDERS_DIR . '/tender_edit.php',
    'api/get_districts' => API_DIR . '/get_districts.php',
    'api/get_councils' => API_DIR . '/get_councils.php',
    'api/get_wards' => API_DIR . '/get_wards.php',

    // ========================================================================
    // HR & OPERATIONS (BMS Directory)
    // ========================================================================
    'attendance' => POS_DIR . '/attendance.php',
    'attendance.php' => POS_DIR . '/attendance.php',
    'employees' => POS_DIR . '/employees.php',
    'employees.php' => POS_DIR . '/employees.php',
    // Reached only from the "Inactive Employees" link on the Employees page —
    // intentionally absent from the header nav (mirrors leave_types.php below).
    'inactive_employees' => POS_DIR . '/inactive_employees.php',
    'inactive_employees.php' => POS_DIR . '/inactive_employees.php',
    'leaves' => POS_DIR . '/leaves.php',
    'leaves.php' => POS_DIR . '/leaves.php',
    // Reached only from the link under the Leave Type field — intentionally absent
    // from the header nav.
    'leave_types' => POS_DIR . '/leave_types.php',
    'leave_types.php' => POS_DIR . '/leave_types.php',
    'payroll' => POS_DIR . '/payroll.php',
    'payroll.php' => POS_DIR . '/payroll.php',
    'salary_components' => POS_DIR . '/salary_components.php',
    'salary_components.php' => POS_DIR . '/salary_components.php',
    'salary-components' => POS_DIR . '/salary_components.php',
    'employee_details' => POS_DIR . '/employee_details.php',
    'employee_details.php' => POS_DIR . '/employee_details.php',
    'leave_details' => POS_DIR . '/leave_details.php',
    'leave_details.php' => POS_DIR . '/leave_details.php',
    'leave_application' => POS_DIR . '/leave_application.php',
    'leave_application.php' => POS_DIR . '/leave_application.php',
    'leave_reports' => POS_DIR . '/leave_reports.php',
    'leave_reports.php' => POS_DIR . '/leave_reports.php',
    'payroll_details' => POS_DIR . '/payroll_details.php',
    'payroll_details.php' => POS_DIR . '/payroll_details.php',
    'payslip' => POS_DIR . '/payslip.php',
    'payslip.php' => POS_DIR . '/payslip.php',
    'statutory_remittances' => POS_DIR . '/statutory_remittances.php',
    'statutory_remittances.php' => POS_DIR . '/statutory_remittances.php',
    'paye_register' => POS_DIR . '/paye_register.php',
    'paye_register.php' => POS_DIR . '/paye_register.php',
    
    // Employee APIs
    'api/get_employee' => API_DIR . '/get_employee.php',
    'api/get_employee.php' => API_DIR . '/get_employee.php',
    'api/get_employees' => API_DIR . '/get_employees.php',
    'api/get_employees.php' => API_DIR . '/get_employees.php',
    'api/add_employee' => API_DIR . '/add_employee.php',
    'api/add_employee.php' => API_DIR . '/add_employee.php',
    'api/import_employees' => API_DIR . '/import_employees.php',
    'api/import_employees.php' => API_DIR . '/import_employees.php',
    'api/update_employee_status' => API_DIR . '/update_employee_status.php',
    'api/update_employee_status.php' => API_DIR . '/update_employee_status.php',
    'api/log_audit' => API_DIR . '/log_audit.php',
    'api/log_audit.php' => API_DIR . '/log_audit.php',
    'api/delete_employee' => API_DIR . '/delete_employee.php',
    'api/delete_employee.php' => API_DIR . '/delete_employee.php',
    'api/inactivate_employee' => API_DIR . '/inactivate_employee.php',
    'api/inactivate_employee.php' => API_DIR . '/inactivate_employee.php',
    'api/reactivate_employee' => API_DIR . '/reactivate_employee.php',
    'api/reactivate_employee.php' => API_DIR . '/reactivate_employee.php',
    'api/update_employee' => API_DIR . '/update_employee.php',
    'api/update_employee.php' => API_DIR . '/update_employee.php',

    // HR Actions — employee lifecycle events (Tier 1)
    'hr_actions' => POS_DIR . '/hr_actions.php',
    'hr_actions.php' => POS_DIR . '/hr_actions.php',
    'api/add_lifecycle_event' => API_DIR . '/add_lifecycle_event.php',
    'api/add_lifecycle_event.php' => API_DIR . '/add_lifecycle_event.php',
    'api/get_lifecycle_event' => API_DIR . '/get_lifecycle_event.php',
    'api/get_lifecycle_event.php' => API_DIR . '/get_lifecycle_event.php',
    'api/get_lifecycle_events' => API_DIR . '/get_lifecycle_events.php',
    'api/get_lifecycle_events.php' => API_DIR . '/get_lifecycle_events.php',
    'api/change_lifecycle_status' => API_DIR . '/change_lifecycle_status.php',
    'api/change_lifecycle_status.php' => API_DIR . '/change_lifecycle_status.php',
    'api/delete_lifecycle_event' => API_DIR . '/delete_lifecycle_event.php',
    'api/delete_lifecycle_event.php' => API_DIR . '/delete_lifecycle_event.php',
    'api/download_lifecycle_attachment' => API_DIR . '/download_lifecycle_attachment.php',
    'api/download_lifecycle_attachment.php' => API_DIR . '/download_lifecycle_attachment.php',

    // HR Compliance — employee documents, contracts, org chart (Tier 2)
    'employee_contracts' => POS_DIR . '/employee_contracts.php',
    'employee_contracts.php' => POS_DIR . '/employee_contracts.php',
    'org_chart' => POS_DIR . '/org_chart.php',
    'org_chart.php' => POS_DIR . '/org_chart.php',
    'api/add_employee_document' => API_DIR . '/add_employee_document.php',
    'api/add_employee_document.php' => API_DIR . '/add_employee_document.php',
    'api/get_employee_documents' => API_DIR . '/get_employee_documents.php',
    'api/get_employee_documents.php' => API_DIR . '/get_employee_documents.php',
    'api/delete_employee_document' => API_DIR . '/delete_employee_document.php',
    'api/delete_employee_document.php' => API_DIR . '/delete_employee_document.php',
    'api/download_employee_document' => API_DIR . '/download_employee_document.php',
    'api/download_employee_document.php' => API_DIR . '/download_employee_document.php',
    'api/manage_document_types' => API_DIR . '/manage_document_types.php',
    'api/manage_document_types.php' => API_DIR . '/manage_document_types.php',
    'api/add_contract' => API_DIR . '/add_contract.php',
    'api/add_contract.php' => API_DIR . '/add_contract.php',
    'api/get_contract' => API_DIR . '/get_contract.php',
    'api/get_contract.php' => API_DIR . '/get_contract.php',
    'api/get_contracts' => API_DIR . '/get_contracts.php',
    'api/get_contracts.php' => API_DIR . '/get_contracts.php',
    'api/change_contract_status' => API_DIR . '/change_contract_status.php',
    'api/change_contract_status.php' => API_DIR . '/change_contract_status.php',
    'api/get_org_chart' => API_DIR . '/get_org_chart.php',
    'api/get_org_chart.php' => API_DIR . '/get_org_chart.php',
    'api/update_reporting_line' => API_DIR . '/update_reporting_line.php',
    'api/update_reporting_line.php' => API_DIR . '/update_reporting_line.php',

    // HR Performance & Development — appraisals, goals, indicators, training (Tier 3)
    'hr_performance' => POS_DIR . '/hr_performance.php',
    'hr_performance.php' => POS_DIR . '/hr_performance.php',
    'trainings' => POS_DIR . '/trainings.php',
    'trainings.php' => POS_DIR . '/trainings.php',
    'api/manage_indicators' => API_DIR . '/manage_indicators.php',
    'api/manage_indicators.php' => API_DIR . '/manage_indicators.php',
    'api/get_indicators' => API_DIR . '/get_indicators.php',
    'api/get_indicators.php' => API_DIR . '/get_indicators.php',
    'api/save_designation_targets' => API_DIR . '/save_designation_targets.php',
    'api/save_designation_targets.php' => API_DIR . '/save_designation_targets.php',
    'api/manage_appraisal_cycles' => API_DIR . '/manage_appraisal_cycles.php',
    'api/manage_appraisal_cycles.php' => API_DIR . '/manage_appraisal_cycles.php',
    'api/add_appraisal' => API_DIR . '/add_appraisal.php',
    'api/add_appraisal.php' => API_DIR . '/add_appraisal.php',
    'api/get_appraisal' => API_DIR . '/get_appraisal.php',
    'api/get_appraisal.php' => API_DIR . '/get_appraisal.php',
    'api/get_appraisals' => API_DIR . '/get_appraisals.php',
    'api/get_appraisals.php' => API_DIR . '/get_appraisals.php',
    'api/change_appraisal_status' => API_DIR . '/change_appraisal_status.php',
    'api/change_appraisal_status.php' => API_DIR . '/change_appraisal_status.php',
    'api/add_goal' => API_DIR . '/add_goal.php',
    'api/add_goal.php' => API_DIR . '/add_goal.php',
    'api/get_goals' => API_DIR . '/get_goals.php',
    'api/get_goals.php' => API_DIR . '/get_goals.php',
    'api/update_goal_progress' => API_DIR . '/update_goal_progress.php',
    'api/update_goal_progress.php' => API_DIR . '/update_goal_progress.php',
    'api/manage_trainings' => API_DIR . '/manage_trainings.php',
    'api/manage_trainings.php' => API_DIR . '/manage_trainings.php',
    'api/get_trainings' => API_DIR . '/get_trainings.php',
    'api/get_trainings.php' => API_DIR . '/get_trainings.php',
    'api/manage_training_participants' => API_DIR . '/manage_training_participants.php',
    'api/manage_training_participants.php' => API_DIR . '/manage_training_participants.php',
    'api/upload_training_certificate' => API_DIR . '/upload_training_certificate.php',
    'api/upload_training_certificate.php' => API_DIR . '/upload_training_certificate.php',
    'api/download_training_certificate' => API_DIR . '/download_training_certificate.php',
    'api/download_training_certificate.php' => API_DIR . '/download_training_certificate.php',

    // HR Talent & Engagement — announcements, meetings, trips, checklists, recruitment, ESS (Tier 4)
    'announcements' => POS_DIR . '/announcements.php',
    'announcements.php' => POS_DIR . '/announcements.php',
    'meetings' => POS_DIR . '/meetings.php',
    'meetings.php' => POS_DIR . '/meetings.php',
    'employee_trips' => POS_DIR . '/employee_trips.php',
    'employee_trips.php' => POS_DIR . '/employee_trips.php',
    'hr_checklists' => POS_DIR . '/hr_checklists.php',
    'hr_checklists.php' => POS_DIR . '/hr_checklists.php',
    'recruitment' => POS_DIR . '/recruitment.php',
    'recruitment.php' => POS_DIR . '/recruitment.php',
    'my_hr' => POS_DIR . '/my_hr.php',
    'my_hr.php' => POS_DIR . '/my_hr.php',
    // Announcements APIs (Phase 4.2)
    'api/manage_announcement' => API_DIR . '/manage_announcement.php',
    'api/manage_announcement.php' => API_DIR . '/manage_announcement.php',
    'api/get_announcements' => API_DIR . '/get_announcements.php',
    'api/get_announcements.php' => API_DIR . '/get_announcements.php',
    'api/mark_announcement_read' => API_DIR . '/mark_announcement_read.php',
    'api/mark_announcement_read.php' => API_DIR . '/mark_announcement_read.php',
    // Meetings + Trips APIs (Phase 4.3)
    'api/manage_meeting' => API_DIR . '/manage_meeting.php',
    'api/manage_meeting.php' => API_DIR . '/manage_meeting.php',
    'api/get_meetings' => API_DIR . '/get_meetings.php',
    'api/get_meetings.php' => API_DIR . '/get_meetings.php',
    'api/manage_trip' => API_DIR . '/manage_trip.php',
    'api/manage_trip.php' => API_DIR . '/manage_trip.php',
    'api/get_trips' => API_DIR . '/get_trips.php',
    'api/get_trips.php' => API_DIR . '/get_trips.php',
    'api/download_trip_attachment' => API_DIR . '/download_trip_attachment.php',
    'api/download_trip_attachment.php' => API_DIR . '/download_trip_attachment.php',
    // Checklists APIs (Phase 4.4)
    'api/manage_checklist_template' => API_DIR . '/manage_checklist_template.php',
    'api/manage_checklist_template.php' => API_DIR . '/manage_checklist_template.php',
    'api/get_checklists' => API_DIR . '/get_checklists.php',
    'api/get_checklists.php' => API_DIR . '/get_checklists.php',
    'api/spawn_checklist' => API_DIR . '/spawn_checklist.php',
    'api/spawn_checklist.php' => API_DIR . '/spawn_checklist.php',
    'api/tick_checklist_item' => API_DIR . '/tick_checklist_item.php',
    'api/tick_checklist_item.php' => API_DIR . '/tick_checklist_item.php',
    'api/change_checklist_status' => API_DIR . '/change_checklist_status.php',
    'api/change_checklist_status.php' => API_DIR . '/change_checklist_status.php',
    // Recruitment APIs (Phase 4.5)
    'api/manage_opening' => API_DIR . '/manage_opening.php',
    'api/manage_opening.php' => API_DIR . '/manage_opening.php',
    'api/get_openings' => API_DIR . '/get_openings.php',
    'api/get_openings.php' => API_DIR . '/get_openings.php',
    'api/manage_candidate' => API_DIR . '/manage_candidate.php',
    'api/manage_candidate.php' => API_DIR . '/manage_candidate.php',
    'api/get_candidates' => API_DIR . '/get_candidates.php',
    'api/get_candidates.php' => API_DIR . '/get_candidates.php',
    'api/change_candidate_stage' => API_DIR . '/change_candidate_stage.php',
    'api/change_candidate_stage.php' => API_DIR . '/change_candidate_stage.php',
    'api/manage_interview' => API_DIR . '/manage_interview.php',
    'api/manage_interview.php' => API_DIR . '/manage_interview.php',
    'api/download_candidate_cv' => API_DIR . '/download_candidate_cv.php',
    'api/download_candidate_cv.php' => API_DIR . '/download_candidate_cv.php',
    // ESS "My HR" APIs (Phase 4.6)
    'api/my_hr_data' => API_DIR . '/my_hr_data.php',
    'api/my_hr_data.php' => API_DIR . '/my_hr_data.php',
    'api/my_leave_apply' => API_DIR . '/my_leave_apply.php',
    'api/my_leave_apply.php' => API_DIR . '/my_leave_apply.php',

    // Payroll APIs
    'api/get_payrolls' => API_DIR . '/get_payrolls.php',
    'api/get_payrolls.php' => API_DIR . '/get_payrolls.php',
    'api/preview_payroll' => API_DIR . '/preview_payroll.php',
    'api/preview_payroll.php' => API_DIR . '/preview_payroll.php',
    'api/process_payroll' => API_DIR . '/process_payroll.php',
    'api/process_payroll.php' => API_DIR . '/process_payroll.php',
    'api/export_payroll' => API_DIR . '/export_payroll.php',
    'api/export_payroll.php' => API_DIR . '/export_payroll.php',
    'api/bulk_update_payroll_status' => API_DIR . '/bulk_update_payroll_status.php',
    'api/bulk_update_payroll_status.php' => API_DIR . '/bulk_update_payroll_status.php',
    'api/get_payroll_details' => API_DIR . '/get_payroll_details.php',
    'api/get_payroll_details.php' => API_DIR . '/get_payroll_details.php',
    'api/update_payroll' => API_DIR . '/update_payroll.php',
    'api/update_payroll.php' => API_DIR . '/update_payroll.php',
    'api/delete_payroll' => API_DIR . '/delete_payroll.php',
    'api/delete_payroll.php' => API_DIR . '/delete_payroll.php',
    'api/remit_statutory' => API_DIR . '/remit_statutory.php',
    'api/remit_statutory.php' => API_DIR . '/remit_statutory.php',
    
    // Leave APIs
    'api/get_leave' => API_DIR . '/get_leave.php',
    'api/get_leave.php' => API_DIR . '/get_leave.php',
    'api/apply_leave' => API_DIR . '/apply_leave.php',
    'api/apply_leave.php' => API_DIR . '/apply_leave.php',
    'api/import_leaves' => API_DIR . '/import_leaves.php',
    'api/import_leaves.php' => API_DIR . '/import_leaves.php',
    'api/update_leave' => API_DIR . '/update_leave.php',
    'api/update_leave.php' => API_DIR . '/update_leave.php',
    'api/export_leaves' => API_DIR . '/export_leaves.php',
    'api/export_leaves.php' => API_DIR . '/export_leaves.php',
    'api/bulk_update_leave_status' => API_DIR . '/bulk_update_leave_status.php',
    'api/bulk_update_leave_status.php' => API_DIR . '/bulk_update_leave_status.php',
    'api/get_leave_balance' => API_DIR . '/get_leave_balance.php',
    'api/get_leave_balance.php' => API_DIR . '/get_leave_balance.php',
    'api/approve_leave' => API_DIR . '/approve_leave.php',
    'api/approve_leave.php' => API_DIR . '/approve_leave.php',
    'api/reject_leave' => API_DIR . '/reject_leave.php',
    'api/reject_leave.php' => API_DIR . '/reject_leave.php',
    'api/cancel_leave' => API_DIR . '/cancel_leave.php',
    'api/cancel_leave.php' => API_DIR . '/cancel_leave.php',
    'api/duplicate_leave' => API_DIR . '/duplicate_leave.php',
    'api/duplicate_leave.php' => API_DIR . '/duplicate_leave.php',
    'api/delete_leave' => API_DIR . '/delete_leave.php',
    'api/delete_leave.php' => API_DIR . '/delete_leave.php',
    'api/export_leave_applications' => API_DIR . '/export_leave_applications.php',
    'api/export_leave_applications.php' => API_DIR . '/export_leave_applications.php',
    
    // Attendance APIs
    'api/mark_attendance' => API_DIR . '/mark_attendance.php',
    'api/mark_attendance.php' => API_DIR . '/mark_attendance.php',
    'api/import_attendance' => API_DIR . '/import_attendance.php',
    'api/import_attendance.php' => API_DIR . '/import_attendance.php',
    'api/export_attendance' => API_DIR . '/export_attendance.php',
    'api/export_attendance.php' => API_DIR . '/export_attendance.php',
    'api/bulk_mark_attendance' => API_DIR . '/bulk_mark_attendance.php',
    'api/bulk_mark_attendance.php' => API_DIR . '/bulk_mark_attendance.php',
    'api/update_attendance_time' => API_DIR . '/update_attendance_time.php',
    'api/update_attendance_time.php' => API_DIR . '/update_attendance_time.php',
    'api/update_attendance_status' => API_DIR . '/update_attendance_status.php',
    'api/update_attendance_status.php' => API_DIR . '/update_attendance_status.php',
    'api/update_attendance_notes' => API_DIR . '/update_attendance_notes.php',
    'api/update_attendance_notes.php' => API_DIR . '/update_attendance_notes.php',
    'api/quick_mark_attendance' => API_DIR . '/quick_mark_attendance.php',
    'api/quick_mark_attendance.php' => API_DIR . '/quick_mark_attendance.php',
    'api/delete_attendance' => API_DIR . '/delete_attendance.php',
    'api/delete_attendance.php' => API_DIR . '/delete_attendance.php',
    
    'maintenance' => OPERATIONS_DIR . '/maintenance.php',
    'maintenance.php' => OPERATIONS_DIR . '/maintenance.php',
    'projects' => OPERATIONS_DIR . '/projects.php',
    'projects.php' => OPERATIONS_DIR . '/projects.php',
    'project_view'          => OPERATIONS_DIR . '/project_view.php',
    'project_view.php'      => OPERATIONS_DIR . '/project_view.php',
    'inspection_view'       => OPERATIONS_DIR . '/inspection_view.php',
    'inspection_view.php'   => OPERATIONS_DIR . '/inspection_view.php',
    'warehouse_stock_view'  => OPERATIONS_DIR . '/warehouse_stock_view.php',
    'project-financial-report' => OPERATIONS_DIR . '/project_financial_report.php',
    'project-budget-report'    => OPERATIONS_DIR . '/project_budget_report.php',

    // Operations APIs
    'api/operations/get_po_items'           => API_DIR . '/operations/get_po_items.php',
    'api/operations/get_po_items.php'       => API_DIR . '/operations/get_po_items.php',
    'api/operations/save_goods_return'      => API_DIR . '/operations/save_goods_return.php',
    'api/operations/save_goods_return.php'  => API_DIR . '/operations/save_goods_return.php',
    'api/operations/create_do_full'         => API_DIR . '/operations/create_do_full.php',
    'api/operations/create_do_full.php'     => API_DIR . '/operations/create_do_full.php',
    'api/operations/edit_do'                => API_DIR . '/operations/edit_do.php',
    'api/operations/edit_do.php'            => API_DIR . '/operations/edit_do.php',
    'api/operations/delete_do'              => API_DIR . '/operations/delete_do.php',
    'api/operations/delete_do.php'          => API_DIR . '/operations/delete_do.php',
    'api/operations/delete_project_doc'     => API_DIR . '/operations/delete_project_doc.php',
    'api/operations/delete_project_doc.php' => API_DIR . '/operations/delete_project_doc.php',
    'api/operations/change_do_status'       => API_DIR . '/operations/change_do_status.php',
    'api/operations/change_do_status.php'   => API_DIR . '/operations/change_do_status.php',
    'api/operations/change_dn_status'       => API_DIR . '/operations/change_dn_status.php',
    'api/operations/change_dn_status.php'   => API_DIR . '/operations/change_dn_status.php',
    'api/operations/get_do_attachments'     => API_DIR . '/operations/get_do_attachments.php',
    'api/operations/get_do_attachments.php' => API_DIR . '/operations/get_do_attachments.php',
    'api/operations/get_do_items'              => API_DIR . '/operations/get_do_items.php',
    'api/operations/get_do_items.php'         => API_DIR . '/operations/get_do_items.php',
    'api/operations/get_project_budgets'      => API_DIR . '/operations/get_project_budgets.php',
    'api/operations/get_project_budgets.php'  => API_DIR . '/operations/get_project_budgets.php',
    'api/operations/get_project'            => API_DIR . '/operations/get_project.php',
    'api/operations/get_project.php'        => API_DIR . '/operations/get_project.php',
    'print-maintenance'                    => API_DIR . '/operations/print_maintenance.php',
    'print-assets'                         => API_DIR . '/operations/print_assets.php',
    'print-customers'                      => API_DIR . '/operations/print_customers.php',
    'print-projects'                       => API_DIR . '/operations/print_projects.php',

    // POS APIs
    'api/pos/get_products'     => API_DIR . '/pos/get_products.php',
    'api/pos/get_products.php' => API_DIR . '/pos/get_products.php',
    'pos/print-receipt'        => API_DIR . '/pos/print_receipt.php',

    // POS Sales History + Returns/Void (Phase 1)
    // POS Workspace — Dashboard + Sales History combined in one toggled page.
    // Legacy sales-history routes redirect here so old links/bookmarks still work.
    'pos/sales-history'         => POS_DIR . '/pos_dashboard.php',
    'pos_sales_history'         => POS_DIR . '/pos_dashboard.php',
    'pos_sales_history.php'     => POS_DIR . '/pos_dashboard.php',
    'pos/dashboard'             => POS_DIR . '/pos_dashboard.php',
    'pos/workspace'             => POS_DIR . '/pos_dashboard.php',
    'pos_dashboard'             => POS_DIR . '/pos_dashboard.php',
    'pos_dashboard.php'         => POS_DIR . '/pos_dashboard.php',
    'api/pos/get_dashboard'     => API_DIR . '/pos/get_dashboard.php',
    'api/pos/get_dashboard.php' => API_DIR . '/pos/get_dashboard.php',
    'api/pos/get_sales'         => API_DIR . '/pos/get_sales.php',
    'api/pos/get_sales.php'     => API_DIR . '/pos/get_sales.php',
    'api/pos/get_sale_items'    => API_DIR . '/pos/get_sale_items.php',
    'api/pos/get_sale_items.php'=> API_DIR . '/pos/get_sale_items.php',
    'api/pos/void_sale'         => API_DIR . '/pos/void_sale.php',
    'api/pos/void_sale.php'     => API_DIR . '/pos/void_sale.php',
    'api/pos/create_return'     => API_DIR . '/pos/create_return.php',
    'api/pos/create_return.php' => API_DIR . '/pos/create_return.php',
    'api/pos/receive_payment'     => API_DIR . '/pos/receive_payment.php',
    'api/pos/receive_payment.php' => API_DIR . '/pos/receive_payment.php',


    // ========================================================================
    // ========================================================================
    'payment_processing' => COMING_SOON_FILE,
    'payment_processing.php' => COMING_SOON_FILE,
    
    // Collections
    'collections_dashboard' => COMING_SOON_FILE,
    'collections_dashboard.php' => COMING_SOON_FILE,


    // ========================================================================
    // DOCUMENT MANAGEMENT MODULE (Constant Directory)
    // ========================================================================
    'customer_documents' => DOCUMENT_DIR . '/customer_documents.php',
    'customer_documents.php' => DOCUMENT_DIR . '/customer_documents.php',
    'document_library' => DOCUMENT_DIR . '/document_library.php',
    'document_library.php' => DOCUMENT_DIR . '/document_library.php',
    'document_templates' => DOCUMENT_DIR . '/document_templates.php',
    'document_templates.php' => DOCUMENT_DIR . '/document_templates.php',
    'document_workflow' => DOCUMENT_DIR . '/document_workflow.php',
    'document_workflow.php' => DOCUMENT_DIR . '/document_workflow.php',
    'e_signatures' => DOCUMENT_DIR . '/e_signatures.php',
    'e_signatures.php' => DOCUMENT_DIR . '/e_signatures.php',
    'preview_template' => DOCUMENT_DIR . '/preview_template.php',
    'preview_template.php' => DOCUMENT_DIR . '/preview_template.php',
    'select_document_add_esignature' => DOCUMENT_DIR . '/select_document_add_esignature.php',
    'select_document_add_esignature.php' => DOCUMENT_DIR . '/select_document_add_esignature.php',
    'create_document' => DOCUMENT_DIR . '/create_document.php',
    'create_document.php' => DOCUMENT_DIR . '/create_document.php',
    'new_document' => DOCUMENT_DIR . '/new_document.php',
    'new_document.php' => DOCUMENT_DIR . '/new_document.php',

    // Document aliases for common navigation patterns
    'library' => DOCUMENT_DIR . '/document_library.php',
    'library.php' => DOCUMENT_DIR . '/document_library.php',
    'templates' => DOCUMENT_DIR . '/document_templates.php',
    'templates.php' => DOCUMENT_DIR . '/document_templates.php',
    'documents/library' => DOCUMENT_DIR . '/document_library.php',
    'documents/templates' => DOCUMENT_DIR . '/document_templates.php',
    'documents/e_signatures' => DOCUMENT_DIR . '/e_signatures.php',
    'documents/workflow' => DOCUMENT_DIR . '/document_workflow.php',
    'compliance_documents' => DOCUMENT_DIR . '/compliance_documents.php',
    'compliance_documents.php' => DOCUMENT_DIR . '/compliance_documents.php',
    'api/get_compliance' => API_DIR . '/get_compliance.php',
    'api/get_compliance.php' => API_DIR . '/get_compliance.php',
    'api/save_compliance' => API_DIR . '/save_compliance.php',
    'api/save_compliance.php' => API_DIR . '/save_compliance.php',
    'api/get_compliance_record' => API_DIR . '/get_compliance_record.php',
    'api/get_compliance_record.php' => API_DIR . '/get_compliance_record.php',
    'api/delete_compliance' => API_DIR . '/delete_compliance.php',
    'api/delete_compliance.php' => API_DIR . '/delete_compliance.php',
    'api/get_adjustment' => API_DIR . '/get_adjustment.php',
    'api/get_adjustment.php' => API_DIR . '/get_adjustment.php',
    'api/export_compliance' => API_DIR . '/export_compliance.php',
    'api/export_compliance.php' => API_DIR . '/export_compliance.php',
    'api/print_compliance' => API_DIR . '/print_compliance.php',
    'api/print_compliance.php' => API_DIR . '/print_compliance.php',
    'api/get_audit_logs' => API_DIR . '/get_audit_logs.php',
    'api/get_audit_logs.php' => API_DIR . '/get_audit_logs.php',
    'api/export_audit_logs' => API_DIR . '/export_audit_logs.php',
    'api/export_audit_logs.php' => API_DIR . '/export_audit_logs.php',
    'api/print_audit_logs' => API_DIR . '/print_audit_logs.php',
    'api/print_audit_logs.php' => API_DIR . '/print_audit_logs.php',
    'api/save_feedback.php' => API_DIR . '/save_feedback.php',


    // ========================================================================
    // REPORTS MODULE (App Directory)
    // ========================================================================
    'reports/audit_logs' => REPORTS_DIR . '/audit_logs.php',
    'reports/balance_sheet' => REPORTS_DIR . '/balance_sheet.php',
    'reports/cash_flow' => REPORTS_DIR . '/cash_flow_gl.php',   // GL-derived, reconciling
    'reports/consolidated_expenses' => REPORTS_DIR . '/consolidated_expenses.php',
    'reports/compliance_checklist' => COMING_SOON_FILE,
    'reports/compliance_dashboard' => COMING_SOON_FILE,
    'reports/customer_activity' => COMING_SOON_FILE,
    'reports/customer_behavior' => COMING_SOON_FILE,
    'reports/customer_statement' => REPORTS_DIR . '/customer_statement.php',
    'reports/vendor_statement' => REPORTS_DIR . '/vendor_statement.php',
    'reports/ar_aging' => REPORTS_DIR . '/ar_aging.php',
    'reports/ap_aging' => REPORTS_DIR . '/ap_aging.php',
    'reports/delinquency_report' => REPORTS_DIR . '/ar_aging.php',
    'reports/financial_statements' => REPORTS_DIR . '/financial_statements.php',
    'reports/income_statement' => INVOICE_DIR . '/income_statement.php',
    'reports/market_analysis' => COMING_SOON_FILE,
    'reports/performance_dashboard' => COMING_SOON_FILE,
    'reports/portfolio_analysis' => COMING_SOON_FILE,
    'reports/profitability_analysis' => COMING_SOON_FILE,
    'reports/regulatory_reports' => COMING_SOON_FILE,
    'reports/risk_analysis' => COMING_SOON_FILE,
    'reports/system_audit' => COMING_SOON_FILE,
    'reports/trial_balance' => ACCOUNTS_DIR . '/trial_balance.php',
    'reports/ledger_report' => REPORTS_DIR . '/ledger_report.php',
    'reports/user_activity' => COMING_SOON_FILE,

    'repayment_report' => REPORTS_DIR . '/repayment_report.php',
    'profit_loss_report' => INVOICE_DIR . '/income_statement.php',
    'expense_report' => REPORTS_DIR . '/expense_report.php',
    'sales_forecast' => REPORTS_DIR . '/sales_forecast.php',
    'compliance_report' => REPORTS_DIR . '/compliance_report.php',
    'employee_report' => REPORTS_DIR . '/employee_report.php',


    // ========================================================================
    // COMMUNICATION MODULE (App Directory)
    // ========================================================================
    'communication/campaign_management' => COMMUNICATION_DIR . '/campaign_management.php',
    'communication/email_templates' => COMMUNICATION_DIR . '/email_templates.php',
    'communication/lead_generation' => COMMUNICATION_DIR . '/lead_generation.php',
    'communication/message_center' => COMMUNICATION_DIR . '/message_center.php',
    'communication/notification_center' => COMMUNICATION_DIR . '/notification_center.php',

    // Comms Aliases
    'campaigns' => COMMUNICATION_DIR . '/campaign_management.php',
    'campaigns.php' => COMMUNICATION_DIR . '/campaign_management.php',
    'leads' => COMMUNICATION_DIR . '/lead_generation.php',
    'leads.php' => COMMUNICATION_DIR . '/lead_generation.php',
    'message_center' => COMMUNICATION_DIR . '/message_center.php',
    'message_center.php' => COMMUNICATION_DIR . '/message_center.php',
    'email_templates' => COMMUNICATION_DIR . '/email_templates.php',
    'email_templates.php' => COMMUNICATION_DIR . '/email_templates.php',
    'notification_center' => COMMUNICATION_DIR . '/notification_center.php',
    'notification_center.php' => COMMUNICATION_DIR . '/notification_center.php',

    // ========================================================================
    // DOCUMENTS MODULE (App Directory)
    // ========================================================================


    // ========================================================================
    // INTEGRATIONS MODULE (App Directory)
    // ========================================================================
    'integrations/api_dashboard' => INTEGRATIONS_DIR . '/api_dashboard.php',
    'integrations/api_documentation' => INTEGRATIONS_DIR . '/api_documentation.php',
    'integrations/banking_integration' => INTEGRATIONS_DIR . '/banking_integration.php',
    'integrations/credit_bureau' => INTEGRATIONS_DIR . '/credit_bureau.php',
    'integrations/crm_integration' => INTEGRATIONS_DIR . '/crm_integration.php',
    'integrations/payment_gateways' => INTEGRATIONS_DIR . '/payment_gateways.php',
    'integrations/webhooks' => INTEGRATIONS_DIR . '/webhooks.php',

    // ========================================================================
    // SETTINGS & USER MANAGEMENT (App Directory)
    // ========================================================================
    'profile.php' => PROFILE_DIR . '/profile.php',
    'settings/notifications' => SETTINGS_DIR . '/notification_settings.php',
    'notification_settings' => SETTINGS_DIR . '/notification_settings.php',
    'notification_settings.php' => SETTINGS_DIR . '/notification_settings.php',
    'settings/notification-rules' => SETTINGS_DIR . '/notification_rules.php',
    'notification_rules' => SETTINGS_DIR . '/notification_rules.php',
    'notification_rules.php' => SETTINGS_DIR . '/notification_rules.php',
    'settings/system' => SETTINGS_DIR . '/system_settings.php',
    'system_settings' => SETTINGS_DIR . '/system_settings.php',
    'system_settings.php' => SETTINGS_DIR . '/system_settings.php',
    'settings/ai' => SETTINGS_DIR . '/ai_settings.php',
    'ai_settings' => SETTINGS_DIR . '/ai_settings.php',
    'ai_settings.php' => SETTINGS_DIR . '/ai_settings.php',
    'ai_assistant' => ROOT_DIR . '/app/constant/communication/ai_assistant.php',
    'ai_assistant.php' => ROOT_DIR . '/app/constant/communication/ai_assistant.php',
    'users' => SETTINGS_DIR . '/users.php',
    'users.php' => SETTINGS_DIR . '/users.php',
    'user_roles' => SETTINGS_DIR . '/user_roles.php',
    'user_roles.php' => SETTINGS_DIR . '/user_roles.php',
    'user_projects' => SETTINGS_DIR . '/user_projects.php',
    'user_projects.php' => SETTINGS_DIR . '/user_projects.php',
    'login_history' => SETTINGS_DIR . '/login_history.php',
    'login_history.php' => SETTINGS_DIR . '/login_history.php',
    'add_user' => SETTINGS_DIR . '/add_user.php',
    'add_user.php' => SETTINGS_DIR . '/add_user.php',
    'edit_user' => SETTINGS_DIR . '/edit_user.php',
    'edit_user.php' => SETTINGS_DIR . '/edit_user.php',
    'permissions' => SETTINGS_DIR . '/manage_permissions.php',
    'permissions.php' => SETTINGS_DIR . '/manage_permissions.php',
    'company_profile' => SETTINGS_DIR . '/company_profile.php',
    'company_profile.php' => SETTINGS_DIR . '/company_profile.php',
    'backup_restore' => SETTINGS_DIR . '/backup_restore.php',
    'backup_restore.php' => SETTINGS_DIR . '/backup_restore.php',
    'download_backup' => SETTINGS_DIR . '/download_backup.php',
    'download_backup.php' => SETTINGS_DIR . '/download_backup.php',
    'tax_settings' => SETTINGS_DIR . '/tax_settings.php',
    'tax_settings.php' => SETTINGS_DIR . '/tax_settings.php',
    'payment_settings' => SETTINGS_DIR . '/payment_settings.php',
    'payment_settings.php' => SETTINGS_DIR . '/payment_settings.php',
    'my_settings' => SETTINGS_DIR . '/my_settings.php',
    'my_settings.php' => SETTINGS_DIR . '/my_settings.php',
    'help' => SETTINGS_DIR . '/help.php',
    'help.php' => SETTINGS_DIR . '/help.php',

    // ========================================================================
    // AJAX ENDPOINTS
    // ========================================================================
    // Accounts Related - Moved to API/Accounts section below
    // Old AJAX routes removed as requested


    // Customers Related
    'ajax/save_customer_document' => AJAX_DIR . '/save_customer_document.php',
    'ajax/search_customers' => AJAX_DIR . '/search_customers.php',
    'ajax/update_customer_document' => AJAX_DIR . '/update_customer_document.php',

    'ajax/add_collection_strategy' => AJAX_DIR . '/add_collection_strategy.php',
    'ajax/add_guarantor' => AJAX_DIR . '/add_guarantor.php',
    'ajax/add_strategy_template' => AJAX_DIR . '/add_strategy_template.php',
    'ajax/calculate_penalties' => AJAX_DIR . '/calculate_penalties.php',
    'ajax/delete_collateral_document' => AJAX_DIR . '/delete_collateral_document.php',
    'ajax/delete_payment' => AJAX_DIR . '/delete_payment.php',
    'ajax/export_strategies' => AJAX_DIR . '/export_strategies.php',
    'ajax/get_collateral_attachments' => AJAX_DIR . '/get_collateral_attachments.php',
    'ajax/get_collateral_details' => AJAX_DIR . '/get_collateral_details.php',
    'ajax/get_collateral_documents' => AJAX_DIR . '/get_collateral_documents.php',
    'ajax/get_collaterals' => AJAX_DIR . '/get_collaterals.php',
    'ajax/get_payment_details' => AJAX_DIR . '/get_payment_details.php',
    'ajax/get_receipt' => AJAX_DIR . '/get_receipt.php',
    'ajax/get_schedule_details' => AJAX_DIR . '/get_schedule_details.php',
    'ajax/get_schedule_details_bulk' => AJAX_DIR . '/get_schedule_details_bulk.php',
    'ajax/get_strategy_details' => AJAX_DIR . '/get_strategy_details.php',
    'ajax/record_overdue_payment' => AJAX_DIR . '/record_overdue_payment.php',
    'ajax/record_payment' => AJAX_DIR . '/record_payment.php',
    'ajax/save_collateral' => AJAX_DIR . '/save_collateral.php',
    'ajax/search_guarantors' => AJAX_DIR . '/search_guarantors.php',
    'ajax/update_collateral_status' => AJAX_DIR . '/update_collateral_status.php',
    'ajax/update_penalty' => AJAX_DIR . '/update_penalty.php',
    'ajax/update_risk_level' => AJAX_DIR . '/update_risk_level.php',
    'ajax/update_strategy_status' => AJAX_DIR . '/update_strategy_status.php',
    'ajax/upload_additional' => AJAX_DIR . '/upload_additional.php',
    'ajax/upload_collateral_doc' => AJAX_DIR . '/upload_collateral_doc.php',
    'ajax/upload_disbursement_doc' => AJAX_DIR . '/upload_disbursement_doc.php',
    'ajax/upload_kyc' => AJAX_DIR . '/upload_kyc.php',
    'ajax/upload_profile_doc' => AJAX_DIR . '/upload_profile_doc.php',

    // Communication Related
    'ajax/delete_email_template' => API_DIR . '/delete_email_template.php',
    'ajax/delete_feedback' => API_DIR . '/delete_feedback.php',
    'ajax/delete_notification' => API_DIR . '/delete_notification.php',
    'ajax/mark_notification_read' => API_DIR . '/mark_notification_read.php',
    'ajax/notification_bulk_actions' => API_DIR . '/notification_bulk_actions.php',
    'ajax/save_campaign' => API_DIR . '/save_campaign.php',
    'ajax/save_email_template' => API_DIR . '/save_email_template.php',
    'ajax/save_feedback' => API_DIR . '/save_feedback.php',
    'ajax/save_lead' => API_DIR . '/save_lead.php',
    'ajax/save_notification_preferences' => API_DIR . '/save_notification_preferences.php',
    'ajax/save_sms_template' => API_DIR . '/save_sms_template.php',
    'ajax/send_template_email' => AJAX_DIR . '/send_template_email.php',
    'ajax/setup_email_templates' => API_DIR . '/setup_email_templates.php',
    'ajax/update_feedback_status' => API_DIR . '/update_feedback_status.php',

    // Documents Related
    'ajax/apply_signature' => AJAX_DIR . '/apply_signature.php',
    'ajax/assign_workflow_document' => AJAX_DIR . '/assign_workflow_document.php',
    'ajax/delete_document_template' => AJAX_DIR . '/delete_document_template.php',
    'ajax/delete_signature' => AJAX_DIR . '/delete_signature.php',
    'ajax/delete_workflow' => AJAX_DIR . '/delete_workflow.php',
    'ajax/get_active_workflows_list' => AJAX_DIR . '/get_active_workflows_list.php',
    'ajax/get_all_documents' => AJAX_DIR . '/get_all_documents.php',
    'ajax/get_document_template' => AJAX_DIR . '/get_document_template.php',
    'ajax/get_my_tasks' => AJAX_DIR . '/get_my_tasks.php',
    'ajax/get_task_details' => AJAX_DIR . '/get_task_details.php',
    'ajax/get_template_details' => AJAX_DIR . '/get_template_details.php',
    'ajax/get_user_signatures_list' => AJAX_DIR . '/get_user_signatures_list.php',
    'ajax/get_workflow_details' => AJAX_DIR . '/get_workflow_details.php',
    'ajax/quick_upload_document' => AJAX_DIR . '/quick_upload_document.php',
    'ajax/save_document_template' => AJAX_DIR . '/save_document_template.php',
    'ajax/save_drawn_signature' => AJAX_DIR . '/save_drawn_signature.php',
    'ajax/save_journal' => AJAX_DIR . '/save_journal.php',
    'ajax/save_workflow' => AJAX_DIR . '/save_workflow.php',
    'ajax/update_task_status' => AJAX_DIR . '/update_task_status.php',
    'ajax/update_workflow' => AJAX_DIR . '/update_workflow.php',
    'ajax/upload_signature' => AJAX_DIR . '/upload_signature.php',
    'ajax/upload_signature.php' => AJAX_DIR . '/upload_signature.php',
    'upload_signature.php' => AJAX_DIR . '/upload_signature.php',

    // Users & Settings Related
    'ajax/assign_role' => AJAX_DIR . '/assign_role.php',
    'ajax/delete_user' => AJAX_DIR . '/delete_user.php',
    'ajax/get_all_users' => AJAX_DIR . '/get_all_users.php',
    'ajax/get_role' => AJAX_DIR . '/get_role.php',
    'ajax/get_users' => AJAX_DIR . '/get_users.php',
    'ajax/toggle_user' => AJAX_DIR . '/toggle_user.php',

    // ========================================================================
    // API ENDPOINTS - ACCOUNTS
    // ========================================================================
    'api/add_budget' => API_DIR . '/account/add_budget.php',
    'api/add_budget.php' => API_DIR . '/account/add_budget.php',
    'api/add_compound_journal' => API_DIR . '/account/add_compound_journal.php',
    'api/add_compound_journal.php' => API_DIR . '/account/add_compound_journal.php',
    'api/add_expense' => API_DIR . '/account/add_expense.php',
    'api/add_expense.php' => API_DIR . '/account/add_expense.php',
    'api/add_transaction' => API_DIR . '/account/add_transaction.php',
    'api/add_transaction.php' => API_DIR . '/account/add_transaction.php',
    'api/delete_account' => API_DIR . '/account/delete_account.php',
    'api/delete_account.php' => API_DIR . '/account/delete_account.php',
    'api/delete_account_category' => API_DIR . '/account/delete_account_category.php',
    'api/delete_account_category.php' => API_DIR . '/account/delete_account_category.php',
    'api/delete_budget' => API_DIR . '/account/delete_budget.php',
    'api/delete_budget.php' => API_DIR . '/account/delete_budget.php',
    'api/delete_expense' => API_DIR . '/account/delete_expense.php',
    'api/delete_expense.php' => API_DIR . '/account/delete_expense.php',
    'api/export_expenses' => API_DIR . '/account/export_expenses.php',
    'api/export_expenses.php' => API_DIR . '/account/export_expenses.php',
    'api/export_journals' => API_DIR . '/account/export_journals.php',
    'api/export_journals.php' => API_DIR . '/account/export_journals.php',
    'api/get_account' => API_DIR . '/account/get_account.php',
    'api/get_account.php' => API_DIR . '/account/get_account.php',
    'api/get_account_categories' => API_DIR . '/account/get_account_categories.php',
    'api/get_account_categories.php' => API_DIR . '/account/get_account_categories.php',
    'api/get_account_category' => API_DIR . '/account/get_account_category.php',
    'api/get_account_category.php' => API_DIR . '/account/get_account_category.php',
    'api/get_account_types' => API_DIR . '/account/get_account_types.php',
    'api/get_account_types.php' => API_DIR . '/account/get_account_types.php',
    'api/get_accounts' => API_DIR . '/account/get_accounts.php',
    'api/get_accounts.php' => API_DIR . '/account/get_accounts.php',
    'api/get_bank_accounts' => API_DIR . '/account/get_bank_accounts.php',
    'api/get_bank_accounts.php' => API_DIR . '/account/get_bank_accounts.php',
    'api/get_budget' => API_DIR . '/account/get_budget.php',
    'api/get_budget.php' => API_DIR . '/account/get_budget.php',
    'api/get_categories_by_type' => API_DIR . '/account/get_categories_by_type.php',
    'api/get_categories_by_type.php' => API_DIR . '/account/get_categories_by_type.php',
    'api/get_category_details' => API_DIR . '/account/get_category_details.php',
    'api/get_category_details.php' => API_DIR . '/account/get_category_details.php',
    'api/get_chart_of_accounts' => API_DIR . '/account/get_chart_of_accounts.php',
    'api/get_chart_of_accounts.php' => API_DIR . '/account/get_chart_of_accounts.php',
    'api/get_expense' => API_DIR . '/account/get_expense.php',
    'api/get_expense.php' => API_DIR . '/account/get_expense.php',
    'api/get_expenses' => API_DIR . '/account/get_expenses.php',
    'api/get_expenses.php' => API_DIR . '/account/get_expenses.php',
    'api/save_journal' => API_DIR . '/account/save_journal.php',
    'api/save_journal.php' => API_DIR . '/account/save_journal.php',
    'api/search_accounts' => API_DIR . '/account/search_accounts.php',
    'api/search_accounts.php' => API_DIR . '/account/search_accounts.php',
    'api/update_budget' => API_DIR . '/account/update_budget.php',
    'api/update_budget.php' => API_DIR . '/account/update_budget.php',
    'api/update_budget_status' => API_DIR . '/account/update_budget_status.php',
    'api/update_budget_status.php' => API_DIR . '/account/update_budget_status.php',
    'api/update_expense' => API_DIR . '/account/update_expense.php',
    'api/update_expense.php' => API_DIR . '/account/update_expense.php',
    'api/update_expense_status' => API_DIR . '/account/update_expense_status.php',
    'api/update_expense_status.php' => API_DIR . '/account/update_expense_status.php',
    'api/update_journal' => API_DIR . '/account/update_journal.php',
    'api/update_journal.php' => API_DIR . '/account/update_journal.php',
    'api/update_journal_status' => API_DIR . '/account/update_journal_status.php',
    'api/update_journal_status.php' => API_DIR . '/account/update_journal_status.php',
    'api/update_transaction' => API_DIR . '/account/update_transaction.php',
    'api/update_transaction.php' => API_DIR . '/account/update_transaction.php',
    'api/update_transaction_status' => API_DIR . '/account/update_transaction_status.php',
    'api/update_transaction_status.php' => API_DIR . '/account/update_transaction_status.php',
    'api/void_journal' => API_DIR . '/account/void_journal.php',
    'api/void_journal.php' => API_DIR . '/account/void_journal.php',
    'api/get_journals' => API_DIR . '/account/get_journals.php',
    'api/get_journals.php' => API_DIR . '/account/get_journals.php',
    'api/reverse_journal' => API_DIR . '/account/reverse_journal.php',
    'api/reverse_journal.php' => API_DIR . '/account/reverse_journal.php',
    'api/delete_journal' => API_DIR . '/account/delete_journal.php',
    'api/delete_journal.php' => API_DIR . '/account/delete_journal.php',

    // ========================================================================
    // API ENDPOINTS - ACCOUNTS
    // ========================================================================
    'api/accounts/add_budget' => API_DIR . '/account/add_budget.php',
    'api/accounts/add_budget.php' => API_DIR . '/account/add_budget.php',
    'api/accounts/add_compound_journal' => API_DIR . '/account/add_compound_journal.php',
    'api/accounts/add_compound_journal.php' => API_DIR . '/account/add_compound_journal.php',
    'api/accounts/add_expense' => API_DIR . '/account/add_expense.php',
    'api/accounts/add_expense.php' => API_DIR . '/account/add_expense.php',
    'api/accounts/add_transaction' => API_DIR . '/account/add_transaction.php',
    'api/accounts/add_transaction.php' => API_DIR . '/account/add_transaction.php',
    'api/accounts/delete_account' => API_DIR . '/account/delete_account.php',
    'api/accounts/delete_account.php' => API_DIR . '/account/delete_account.php',
    'api/accounts/delete_account_category' => API_DIR . '/account/delete_account_category.php',
    'api/accounts/delete_account_category.php' => API_DIR . '/account/delete_account_category.php',
    'api/accounts/delete_budget' => API_DIR . '/account/delete_budget.php',
    'api/accounts/delete_budget.php' => API_DIR . '/account/delete_budget.php',
    'api/accounts/delete_expense' => API_DIR . '/account/delete_expense.php',
    'api/accounts/delete_expense.php' => API_DIR . '/account/delete_expense.php',
    'api/accounts/export_expenses' => API_DIR . '/account/export_expenses.php',
    'api/accounts/export_expenses.php' => API_DIR . '/account/export_expenses.php',
    'api/accounts/export_journals' => API_DIR . '/account/export_journals.php',
    'api/accounts/export_journals.php' => API_DIR . '/account/export_journals.php',
    'api/accounts/get_account' => API_DIR . '/account/get_account.php',
    'api/accounts/get_account.php' => API_DIR . '/account/get_account.php',
    'api/accounts/get_account_categories' => API_DIR . '/account/get_account_categories.php',
    'api/accounts/get_account_categories.php' => API_DIR . '/account/get_account_categories.php',
    'api/accounts/get_account_category' => API_DIR . '/account/get_account_category.php',
    'api/accounts/get_account_category.php' => API_DIR . '/account/get_account_category.php',
    'api/accounts/get_account_types' => API_DIR . '/account/get_account_types.php',
    'api/accounts/get_account_types.php' => API_DIR . '/account/get_account_types.php',
    'api/accounts/get_accounts' => API_DIR . '/account/get_accounts.php',
    'api/accounts/get_accounts.php' => API_DIR . '/account/get_accounts.php',
    'api/accounts/get_bank_accounts' => API_DIR . '/account/get_bank_accounts.php',
    'api/accounts/get_bank_accounts.php' => API_DIR . '/account/get_bank_accounts.php',
    'api/accounts/get_budget' => API_DIR . '/account/get_budget.php',
    'api/accounts/get_budget.php' => API_DIR . '/account/get_budget.php',
    'api/accounts/get_categories_by_type' => API_DIR . '/account/get_categories_by_type.php',
    'api/accounts/get_categories_by_type.php' => API_DIR . '/account/get_categories_by_type.php',
    'api/accounts/get_category_details' => API_DIR . '/account/get_category_details.php',
    'api/accounts/get_category_details.php' => API_DIR . '/account/get_category_details.php',
    'api/accounts/get_chart_of_accounts' => API_DIR . '/account/get_chart_of_accounts.php',
    'api/accounts/get_chart_of_accounts.php' => API_DIR . '/account/get_chart_of_accounts.php',
    'api/accounts/get_expense' => API_DIR . '/account/get_expense.php',
    'api/accounts/get_expense.php' => API_DIR . '/account/get_expense.php',
    'api/accounts/get_expenses' => API_DIR . '/account/get_expenses.php',
    'api/accounts/get_expenses.php' => API_DIR . '/account/get_expenses.php',
    'api/accounts/save_journal' => API_DIR . '/account/save_journal.php',
    'api/accounts/save_journal.php' => API_DIR . '/account/save_journal.php',
    'api/accounts/save_account' => API_DIR . '/account/save_account.php',
    'api/accounts/save_account.php' => API_DIR . '/account/save_account.php',
    'api/accounts/save_category' => API_DIR . '/account/save_category.php',
    'api/accounts/save_category.php' => API_DIR . '/account/save_category.php',
    'api/accounts/search_accounts' => API_DIR . '/account/search_accounts.php',
    'api/accounts/search_accounts.php' => API_DIR . '/account/search_accounts.php',
    'api/accounts/update_budget' => API_DIR . '/account/update_budget.php',
    'api/accounts/update_budget.php' => API_DIR . '/account/update_budget.php',
    'api/accounts/update_budget_status' => API_DIR . '/account/update_budget_status.php',
    'api/accounts/update_budget_status.php' => API_DIR . '/account/update_budget_status.php',
    'api/accounts/update_expense' => API_DIR . '/account/update_expense.php',
    'api/accounts/update_expense.php' => API_DIR . '/account/update_expense.php',
    'api/accounts/update_expense_status' => API_DIR . '/account/update_expense_status.php',
    'api/accounts/update_expense_status.php' => API_DIR . '/account/update_expense_status.php',
    'api/accounts/update_journal' => API_DIR . '/account/update_journal.php',
    'api/accounts/update_journal.php' => API_DIR . '/account/update_journal.php',
    'api/accounts/update_journal_status' => API_DIR . '/account/update_journal_status.php',
    'api/accounts/update_journal_status.php' => API_DIR . '/account/update_journal_status.php',
    'api/accounts/update_transaction' => API_DIR . '/account/update_transaction.php',
    'api/accounts/update_transaction.php' => API_DIR . '/account/update_transaction.php',
    'api/accounts/update_transaction_status' => API_DIR . '/account/update_transaction_status.php',
    'api/accounts/update_transaction_status.php' => API_DIR . '/account/update_transaction_status.php',
    'api/accounts/void_journal' => API_DIR . '/account/void_journal.php',
    'api/accounts/void_journal.php' => API_DIR . '/account/void_journal.php',

    // Bank Reconciliation (Direct Access)
    'api/get_bank_balance' => API_DIR . '/account/get_bank_balance.php',
    'api/get_bank_balance.php' => API_DIR . '/account/get_bank_balance.php',
    'api/create_reconciliation' => API_DIR . '/account/create_reconciliation.php',
    'api/create_reconciliation.php' => API_DIR . '/account/create_reconciliation.php',
    'api/get_bank_reconciliations' => API_DIR . '/account/get_bank_reconciliations.php',
    'api/get_bank_reconciliations.php' => API_DIR . '/account/get_bank_reconciliations.php',
    'api/account/get_bank_statement' => API_DIR . '/account/get_bank_statement.php',
    'api/account/get_bank_statement.php' => API_DIR . '/account/get_bank_statement.php',
    'api/get_reconciliation' => API_DIR . '/account/get_reconciliation.php',
    'api/get_reconciliation.php' => API_DIR . '/account/get_reconciliation.php',
    'api/update_reconciliation' => API_DIR . '/account/update_reconciliation.php',
    'api/update_reconciliation.php' => API_DIR . '/account/update_reconciliation.php',
    'api/delete_reconciliation' => API_DIR . '/account/delete_reconciliation.php',
    'api/delete_reconciliation.php' => API_DIR . '/account/delete_reconciliation.php',
    'api/update_reconciliation_status' => API_DIR . '/account/update_reconciliation_status.php',
    'api/update_reconciliation_status.php' => API_DIR . '/account/update_reconciliation_status.php',
    
    // Invoice APIs
    'api/account/get_invoices' => API_DIR . '/account/get_invoices.php',
    'api/account/get_invoices.php' => API_DIR . '/account/get_invoices.php',
    'api/account/save_invoice' => API_DIR . '/account/save_invoice.php',
    'api/account/save_invoice.php' => API_DIR . '/account/save_invoice.php',
    'api/account/delete_invoice' => API_DIR . '/account/delete_invoice.php',
    'api/account/delete_invoice.php' => API_DIR . '/account/delete_invoice.php',
    'api/account/update_invoice_status' => API_DIR . '/account/update_invoice_status.php',
    'api/account/update_invoice_status.php' => API_DIR . '/account/update_invoice_status.php',
    'api/account/export_invoices' => API_DIR . '/account/export_invoices.php',
    'api/account/export_invoices.php' => API_DIR . '/account/export_invoices.php',
    'api/account/get_income_statement' => API_DIR . '/account/get_income_statement.php',
    'api/account/get_income_statement.php' => API_DIR . '/account/get_income_statement.php',
    'api/account/get_income_statement_detail' => API_DIR . '/account/get_income_statement_detail.php',
    'api/account/get_income_statement_detail.php' => API_DIR . '/account/get_income_statement_detail.php',
    'api/account/export_income_statement' => API_DIR . '/account/export_income_statement.php',
    'api/account/export_income_statement.php' => API_DIR . '/account/export_income_statement.php',
    'api/account/get_products' => API_DIR . '/account/get_products.php',
    'api/account/get_products.php' => API_DIR . '/account/get_products.php',

    // Purchase Order APIs
    'api/account/get_purchase_orders' => API_DIR . '/account/get_purchase_orders.php',
    'api/account/get_purchase_orders.php' => API_DIR . '/account/get_purchase_orders.php',
    'api/account/update_purchase_order_status' => API_DIR . '/account/update_purchase_order_status.php',
    'api/account/update_purchase_order_status.php' => API_DIR . '/account/update_purchase_order_status.php',
    'api/account/export_purchase_orders' => API_DIR . '/account/export_purchase_orders.php',
    'api/account/export_purchase_orders.php' => API_DIR . '/account/export_purchase_orders.php',
    'api/account/save_purchase_order' => API_DIR . '/account/save_purchase_order.php',
    'api/account/save_purchase_order.php' => API_DIR . '/account/save_purchase_order.php',
    'api/account/get_purchase_order' => API_DIR . '/account/get_purchase_order.php',
    'api/account/get_purchase_order.php' => API_DIR . '/account/get_purchase_order.php',
    'api/account/delete_purchase_order' => API_DIR . '/account/delete_purchase_order.php',
    'api/account/delete_purchase_order.php' => API_DIR . '/account/delete_purchase_order.php',

    // Sales Order APIs
    'api/account/get_sales_orders' => API_DIR . '/account/get_sales_orders.php',
    'api/account/get_sales_orders.php' => API_DIR . '/account/get_sales_orders.php',
    'api/account/save_sales_order' => API_DIR . '/account/save_sales_order.php',
    'api/account/save_sales_order.php' => API_DIR . '/account/save_sales_order.php',
    'api/account/update_sales_order_status' => API_DIR . '/account/update_sales_order_status.php',
    'api/account/update_sales_order_status.php' => API_DIR . '/account/update_sales_order_status.php',
    'api/account/delete_sales_order' => API_DIR . '/account/delete_sales_order.php',
    'api/account/delete_sales_order.php' => API_DIR . '/account/delete_sales_order.php',
    'api/account/get_customer' => API_DIR . '/account/get_customer.php',
    'api/account/get_customer.php' => API_DIR . '/account/get_customer.php',
    'api/account/get_tax_rates' => API_DIR . '/account/get_tax_rates.php',
    'api/account/get_tax_rates.php' => API_DIR . '/account/get_tax_rates.php',
    'api/account/get_sales_order_items' => API_DIR . '/account/get_sales_order_items.php',
    'api/account/get_sales_order_items.php' => API_DIR . '/account/get_sales_order_items.php',

    // Purchase Return APIs
    'api/account/get_purchase_returns' => API_DIR . '/account/get_purchase_returns.php',
    'api/account/get_purchase_returns.php' => API_DIR . '/account/get_purchase_returns.php',
    'api/account/save_purchase_return' => API_DIR . '/account/save_purchase_return.php',
    'api/account/save_purchase_return.php' => API_DIR . '/account/save_purchase_return.php',
    'api/account/update_purchase_return_status' => API_DIR . '/account/update_purchase_return_status.php',
    'api/account/update_purchase_return_status.php' => API_DIR . '/account/update_purchase_return_status.php',
    'api/account/delete_purchase_return' => API_DIR . '/account/delete_purchase_return.php',
    'api/account/delete_purchase_return.php' => API_DIR . '/account/delete_purchase_return.php',


    // ========================================================================
    // API ENDPOINTS - CUSTOMERS

    // ========================================================================
    'api/add_group_members' => API_DIR . '/add_group_members.php',
    'api/create_customer_group' => API_DIR . '/create_customer_group.php',
    'api/delete_customer' => API_DIR . '/delete_customer.php',
    'api/delete_customer_group' => API_DIR . '/delete_customer_group.php',
    'api/export_group_members' => API_DIR . '/export_group_members.php',
    'api/get_customer_documents' => API_DIR . '/get_customer_documents.php',
    'api/get_customer_group' => API_DIR . '/get_customer_group.php',
    'api/get_customers' => API_DIR . '/get_customers.php',
    'api/get_customers_paged' => API_DIR . '/get_customers_paged.php',
    'api/get_customers_paged.php' => API_DIR . '/get_customers_paged.php',
    'api/get_feedback' => API_DIR . '/get_feedback.php',
    'api/get_group_members' => API_DIR . '/get_group_members.php',
    'api/import_customers' => API_DIR . '/import_customers.php',
    'api/import_customers.php' => API_DIR . '/import_customers.php',
    'api/process_edit_customer' => API_DIR . '/process_edit_customer.php',
    'api/process_register' => API_DIR . '/process_register.php',
    'api/refresh_dynamic_group' => API_DIR . '/refresh_dynamic_group.php',
    'api/remove_group_member' => API_DIR . '/remove_group_member.php',
    'api/update_customer_group' => API_DIR . '/update_customer_group.php',
    'api/get_customer' => API_DIR . '/account/get_customer.php',
    'api/get_customer.php' => API_DIR . '/account/get_customer.php',
    'sales_invoices' => BMS_DIR . '/invoice/invoices.php',
    'customer_payments' => BMS_DIR . '/customer/customer_payments.php',

    // ========================================================================
    // ========================================================================
    'api/add_guarantor' => AJAX_DIR . '/add_guarantor.php',
    'api/add_guarantor.php' => AJAX_DIR . '/add_guarantor.php',
    'api/calculate_penalties' => AJAX_DIR . '/calculate_penalties.php',
    'api/collateral_verification' => API_DIR . '/collateral_verification.php',
    'api/credit_check' => API_DIR . '/credit_check.php',
    'api/delete_collateral_document' => AJAX_DIR . '/delete_collateral_document.php',
    'api/delete_payment' => AJAX_DIR . '/delete_payment.php',
    'api/delete_product' => API_DIR . '/delete_product.php',
    'api/delete_product.php' => API_DIR . '/delete_product.php',
    'api/update_product_alerts' => API_DIR . '/update_product_alerts.php',
    'api/update_product_alerts.php' => API_DIR . '/update_product_alerts.php',
    'api/escalate_cases' => API_DIR . '/escalate_cases.php',
    'api/export_payments' => API_DIR . '/export_payments.php',
    'api/fetch_districts' => API_DIR . '/fetch_districts.php',
    'api/fetch_regions' => API_DIR . '/fetch_regions.php',
    'api/fix_schema' => API_DIR . '/fix_schema.php',
    'api/fix_schema_v2' => API_DIR . '/fix_schema_v2.php',
    'api/generate_repayment_schedule' => API_DIR . '/generate_repayment_schedule.php',
    'api/generate_schedules' => API_DIR . '/generate_schedules.php',
    'api/get_collateral_attachments' => API_DIR . '/get_collateral_attachments.php',
    'api/get_collateral_details' => API_DIR . '/get_collateral_details.php',
    'api/get_collateral_documents' => API_DIR . '/get_collateral_documents.php',
    'api/get_collaterals' => API_DIR . '/get_collaterals.php',
    'api/get_contact_history' => API_DIR . '/get_contact_history.php',
    'api/get_transactions' => API_DIR . '/get_transactions.php',
    'api/save_transaction' => API_DIR . '/save_transaction.php',
    'api/search_customers' => API_DIR . '/search_customers.php',
    'api/search_guarantors' => API_DIR . '/search_guarantors.php',
    'api/get_payment_details' => API_DIR . '/get_payment_details.php',
    'api/get_processes' => API_DIR . '/get_processes.php',
    'api/get_product_details' => API_DIR . '/get_product_details.php',
    'api/get_products' => API_DIR . '/get_products.php',
    'api/get_receipt' => AJAX_DIR . '/get_receipt.php',
    'api/get_schedules' => API_DIR . '/get_schedules.php',
    'api/log_contact' => API_DIR . '/log_contact.php',
    'api/mark_defaulted' => API_DIR . '/mark_defaulted.php',
    'api/mark_repaid' => API_DIR . '/mark_repaid.php',
    'api/process_bulk_payment' => API_DIR . '/process_bulk_payment.php',
    'api/record_payment' => AJAX_DIR . '/record_payment.php',
    'api/reschedule_payment' => API_DIR . '/reschedule_payment.php',
    'api/reverse_payment' => API_DIR . '/reverse_payment.php',
    'api/risk_assessment' => API_DIR . '/risk_assessment.php',
    'api/save_collateral' => API_DIR . '/save_collateral.php',
    'api/save_product' => API_DIR . '/save_product.php',
    'api/update_collateral_status' => API_DIR . '/update_collateral_status.php',
    'api/update_guarantor' => API_DIR . '/update_guarantor.php',
    'api/update_penalty' => AJAX_DIR . '/update_penalty.php',
    'api/upload_collateral_doc' => AJAX_DIR . '/upload_collateral_doc.php',
    'api/upload_disbursement_doc' => AJAX_DIR . '/upload_disbursement_doc.php',

    // ========================================================================
    // API ENDPOINTS - REPORTS
    // ========================================================================
    'api/generate_financial_report' => API_DIR . '/generate_financial_report.php',
    'api/get_access_log' => API_DIR . '/get_access_log.php',

    // ========================================================================
    // API ENDPOINTS - COMMUNICATION
    // ========================================================================
    'api/delete_email_template' => API_DIR . '/delete_email_template.php',
    'api/delete_email_template.php' => API_DIR . '/delete_email_template.php',
    'api/delete_feedback' => API_DIR . '/delete_feedback.php',
    'api/delete_feedback.php' => API_DIR . '/delete_feedback.php',
    'api/delete_notification' => API_DIR . '/delete_notification.php',
    'api/delete_notification.php' => API_DIR . '/delete_notification.php',
    'api/get_campaigns' => API_DIR . '/get_campaigns.php',
    'api/get_campaigns.php' => API_DIR . '/get_campaigns.php',
    'api/get_email_templates' => API_DIR . '/get_email_templates.php',
    'api/get_email_templates.php' => API_DIR . '/get_email_templates.php',
    'api/get_leads' => API_DIR . '/get_leads.php',
    'api/get_leads.php' => API_DIR . '/get_leads.php',
    'api/get_notifications' => API_DIR . '/get_notifications.php',
    'api/get_notifications.php' => API_DIR . '/get_notifications.php',
    'api/mark_notification_read' => API_DIR . '/mark_notification_read.php',
    'api/mark_notification_read.php' => API_DIR . '/mark_notification_read.php',
    'api/notification_bulk_actions' => API_DIR . '/notification_bulk_actions.php',
    'api/notification_bulk_actions.php' => API_DIR . '/notification_bulk_actions.php',
    'api/save_campaign' => API_DIR . '/save_campaign.php',
    'api/save_campaign.php' => API_DIR . '/save_campaign.php',
    'api/save_email_template' => API_DIR . '/save_email_template.php',
    'api/save_email_template.php' => API_DIR . '/save_email_template.php',

    'api/save_lead' => API_DIR . '/save_lead.php',
    'api/save_lead.php' => API_DIR . '/save_lead.php',
    'api/save_notification_preferences' => API_DIR . '/save_notification_preferences.php',
    'api/save_notification_preferences.php' => API_DIR . '/save_notification_preferences.php',
    'api/save_sms_template' => API_DIR . '/save_sms_template.php',
    'api/save_sms_template.php' => API_DIR . '/save_sms_template.php',
    'api/send_reminders' => API_DIR . '/send_reminders.php',
    'api/setup_email_templates' => API_DIR . '/setup_email_templates.php',
    'api/test_email_config' => API_DIR . '/test_email_config.php',
    'api/test_sms_config' => API_DIR . '/test_sms_config.php',
    'api/update_feedback_status' => API_DIR . '/update_feedback_status.php',
    'api/update_feedback_status.php' => API_DIR . '/update_feedback_status.php',
    'api/verify_esignature' => API_DIR . '/verify_esignature.php',

    // ========================================================================
    // API ENDPOINTS - DOCUMENTS
    // ========================================================================
    // Legacy signature/workflow endpoints (keeping for backwards compatibility)
    'api/get_pending_signatures' => API_DIR . '/get_pending_signatures.php',
    'api/get_pending_signatures.php' => API_DIR . '/get_pending_signatures.php',
    'api/get_signature_history' => API_DIR . '/get_signature_history.php',
    'api/get_signature_history.php' => API_DIR . '/get_signature_history.php',
    'api/get_templates' => API_DIR . '/get_templates.php',
    'api/get_templates.php' => API_DIR . '/get_templates.php',
    'api/get_user_signatures' => API_DIR . '/get_user_signatures.php',
    'api/get_user_signatures.php' => API_DIR . '/get_user_signatures.php',
    'api/get_workflows' => API_DIR . '/get_workflows.php',
    'api/get_workflows.php' => API_DIR . '/get_workflows.php',
    'api/save_document_template' => AJAX_DIR . '/save_document_template.php',
    'api/save_document_template.php' => AJAX_DIR . '/save_document_template.php',

    // Payroll Settings APIs
    'api/payroll/add_tax_bracket' => API_DIR . '/payroll/add_tax_bracket.php',
    'api/payroll/add_tax_bracket.php' => API_DIR . '/payroll/add_tax_bracket.php',
    'api/payroll/update_settings' => API_DIR . '/payroll/update_settings.php',
    'api/payroll/update_settings.php' => API_DIR . '/payroll/update_settings.php',
    'api/payroll/delete_tax_bracket' => API_DIR . '/payroll/delete_tax_bracket.php',
    'api/payroll/delete_tax_bracket.php' => API_DIR . '/payroll/delete_tax_bracket.php',
    'api/pos_session' => API_DIR . '/pos_session.php',
    'api/pos_session.php' => API_DIR . '/pos_session.php',
    
    // ========================================================================
    // API ENDPOINTS - USERS & SETTINGS
    // ========================================================================
    'api/assign_role' => API_DIR . '/assign_role.php',
    'api/delete_user' => API_DIR . '/delete_user.php',
    'api/get_role' => API_DIR . '/get_role.php',
    'api/get_users' => API_DIR . '/get_users.php',
    'api/toggle_user' => API_DIR . '/toggle_user.php',

    // ========================================================================
    // API ENDPOINTS - PRODUCTS
    // ========================================================================
    'api/get_categories' => API_DIR . '/get_categories.php',
    'api/get_categories.php' => API_DIR . '/get_categories.php',
    'api/create_category' => API_DIR . '/create_category.php',
    'api/create_category.php' => API_DIR . '/create_category.php',
    'api/open_cash_drawer' => API_DIR . '/open_cash_drawer.php',
    'api/open_cash_drawer.php' => API_DIR . '/open_cash_drawer.php',
    'api/get_stock_counts' => API_DIR . '/get_stock_counts.php',
    'api/get_stock_counts.php' => API_DIR . '/get_stock_counts.php',
    'api/export_products' => API_DIR . '/export_products.php',
    'api/export_products.php' => API_DIR . '/export_products.php',
    'api/save_brand' => API_DIR . '/save_brand.php',
    'api/save_brand.php' => API_DIR . '/save_brand.php',
    'api/delete_brand' => API_DIR . '/delete_brand.php',
    'api/delete_brand.php' => API_DIR . '/delete_brand.php',
    'api/update_category' => API_DIR . '/update_category.php',
    'api/update_category.php' => API_DIR . '/update_category.php',
    'api/delete_category' => API_DIR . '/delete_category.php',
    'api/delete_category.php' => API_DIR . '/delete_category.php',
    'api/create_product' => API_DIR . '/create_product.php',
    'api/create_product.php' => API_DIR . '/create_product.php',
    'api/update_product' => API_DIR . '/update_product.php',
    'api/update_product.php' => API_DIR . '/update_product.php',
    'api/import_products' => API_DIR . '/import_products.php',
    'api/import_products.php' => API_DIR . '/import_products.php',
    'api/adjust_stock' => API_DIR . '/create_stock_adjustment.php',
    'api/adjust_stock.php' => API_DIR . '/create_stock_adjustment.php',
    'api/update_product_status' => API_DIR . '/update_product_status.php',
    'api/update_product_status.php' => API_DIR . '/update_product_status.php',
    'print_barcode' => PRODUCT_DIR . '/print_barcode.php',
    'print_barcode.php' => PRODUCT_DIR . '/print_barcode.php',
    'api/export_adjustments' => API_DIR . '/export_adjustments.php',
    'api/export_adjustments.php' => API_DIR . '/export_adjustments.php',
    'api/process_bulk_adjustment' => API_DIR . '/process_bulk_adjustment.php',
    'api/process_bulk_adjustment.php' => API_DIR . '/process_bulk_adjustment.php',

    // Purchase Returns
    'api/get_purchase_returns' => API_DIR . '/get_purchase_returns.php',
    'api/get_purchase_returns.php' => API_DIR . '/get_purchase_returns.php',
    'api/get_purchase_return_stats' => API_DIR . '/get_purchase_return_stats.php',
    'api/get_purchase_return_stats.php' => API_DIR . '/get_purchase_return_stats.php',
    'api/create_purchase_return' => API_DIR . '/create_purchase_return.php',
    'api/create_purchase_return.php' => API_DIR . '/create_purchase_return.php',
    'api/update_purchase_return' => API_DIR . '/update_purchase_return.php',
    'api/update_purchase_return.php' => API_DIR . '/update_purchase_return.php',
    'api/update_purchase_return_status' => API_DIR . '/update_purchase_return_status.php',
    'api/update_purchase_return_status.php' => API_DIR . '/update_purchase_return_status.php',
    'api/get_purchase_return' => API_DIR . '/get_purchase_return.php',
    'api/get_purchase_return.php' => API_DIR . '/get_purchase_return.php',
    'api/delete_purchase_return' => API_DIR . '/delete_purchase_return.php',
    'api/delete_purchase_return.php' => API_DIR . '/delete_purchase_return.php',

    // NIP Materials
    'api/get_material_lists' => API_DIR . '/get_material_lists.php',
    'api/get_material_lists.php' => API_DIR . '/get_material_lists.php',
    'api/get_material_list_for_edit' => API_DIR . '/get_material_list_for_edit.php',
    'api/get_material_list_for_edit.php' => API_DIR . '/get_material_list_for_edit.php',
    'api/update_material_list' => API_DIR . '/update_material_list.php',
    'api/update_material_list.php' => API_DIR . '/update_material_list.php',
    'api/delete_material_list' => API_DIR . '/delete_material_list.php',
    'api/delete_material_list.php' => API_DIR . '/delete_material_list.php',
    'api/get_material_list_view' => API_DIR . '/get_material_list_view.php',
    'api/get_material_list_view.php' => API_DIR . '/get_material_list_view.php',
    'api/get_service_components' => API_DIR . '/get_service_components.php',
    'api/get_service_components.php' => API_DIR . '/get_service_components.php',
    'api/add_nip_materials' => API_DIR . '/add_nip_materials.php',
    'api/add_nip_materials.php' => API_DIR . '/add_nip_materials.php',
    'api/get_nip_materials_form_data' => API_DIR . '/get_nip_materials_form_data.php',
    'api/get_nip_materials_form_data.php' => API_DIR . '/get_nip_materials_form_data.php',
    'api/get_nip_components' => API_DIR . '/get_nip_components.php',
    'api/get_nip_components.php' => API_DIR . '/get_nip_components.php',
    'api/delete_nip_component' => API_DIR . '/delete_nip_component.php',
    'api/delete_nip_component.php' => API_DIR . '/delete_nip_component.php',
    'api/update_nip_status' => API_DIR . '/update_nip_status.php',
    'api/update_nip_status.php' => API_DIR . '/update_nip_status.php',
    'api/delete_nip_product' => API_DIR . '/delete_nip_product.php',
    'api/delete_nip_product.php' => API_DIR . '/delete_nip_product.php',
    'api/update_nip_product' => API_DIR . '/update_nip_product.php',
    'api/update_nip_product.php' => API_DIR . '/update_nip_product.php',
    'api/get_grns' => API_DIR . '/get_grns.php',
    'api/get_grns.php' => API_DIR . '/get_grns.php',
    'api/create_grn' => API_DIR . '/create_grn.php',
    'api/create_grn.php' => API_DIR . '/create_grn.php',
    'api/update_grn_status' => API_DIR . '/update_grn_status.php',
    'api/update_grn_status.php' => API_DIR . '/update_grn_status.php',
    'api/delete_grn' => API_DIR . '/delete_grn.php',
    'api/delete_grn.php' => API_DIR . '/delete_grn.php',
    'api/export_grns' => API_DIR . '/export_grns.php',
    'api/export_grns.php' => API_DIR . '/export_grns.php',

    'api/export_purchase_returns' => API_DIR . '/export_purchase_returns.php',
    'api/export_purchase_returns.php' => API_DIR . '/export_purchase_returns.php',


];

/**
 * Get the clean URL for a page
 * @param string $page The page identifier (e.g., 'accounts/journals')
 * @return string The clean URL
 */
/**
 * Detect the base URL prefix for the application
 * Takes into account if the app is in a subdirectory
 */
function getBasePath() {
    static $basePath = null;
    if ($basePath !== null) return $basePath;

    $rootDir = str_replace('\\', '/', realpath(ROOT_DIR));
    
    // 1. Try DOCUMENT_ROOT (Most reliable on most web servers)
    if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
        if (strpos($rootDir, $docRoot) === 0) {
            $base = substr($rootDir, strlen($docRoot));
            $basePath = '/' . trim($base, '/');
            $basePath = ($basePath === '/') ? '' : $basePath;
            return $basePath;
        }
    }

    // 2. Fallback: Use SCRIPT_NAME and SCRIPT_FILENAME to deduce relationship
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptFilename = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    
    if (!empty($scriptName) && !empty($scriptFilename) && strpos($scriptFilename, $rootDir) !== false) {
        $relativePart = str_replace($rootDir, '', $scriptFilename);
        $base = substr($scriptName, 0, strlen($scriptName) - strlen($relativePart));
        $basePath = '/' . trim($base, '/');
        
        // Safety check: if it still looks like an absolute disk path (has drive letter or start with root dir path)
        if (preg_match('/^[A-Z]:/i', $basePath) || (defined('ROOT_DIR') && strpos($basePath, str_replace('\\', '/', ROOT_DIR)) === 0)) {
            $basePath = ''; 
        } else {
            $basePath = ($basePath === '/') ? '' : $basePath;
        }
        return $basePath;
    }
    
    $basePath = '';
    return $basePath;
}

function getUrl($page) {
    global $routes;
    
    $base_url = getBasePath();
    
    // Strip leading slash from page to search for match accurately
    $cleanPage = ltrim($page, '/');
    
    // Skip cleaning for API and AJAX calls (they need their .php extension usually)
    if (!str_starts_with($cleanPage, 'api/') && !str_starts_with($cleanPage, 'ajax/')) {
        // If the page passed has .php, try to find a clean version in the routes
        if (str_ends_with($cleanPage, '.php')) {
            $noExt = substr($cleanPage, 0, -4);
            if (isset($routes[$noExt])) {
                $cleanPage = $noExt;
            }
        }
    }
    
    // Always prepend base_url to the result
    return $base_url . '/' . $cleanPage;
}


/**
 * Route handler - processes clean URLs
 * This should be called from .htaccess or index.php
 */
function handleRoute() {
    global $routes, $pdo, $pdo_accounts;
    
    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri_no_query = strtok($request_uri, '?');
    $clean_uri = trim($uri_no_query, '/');

    // Detect and strip base path (for subdirectory installations)
    $base_path = trim(getBasePath(), '/');
    
    if (!empty($base_path) && (strpos($clean_uri, $base_path) === 0)) {
        $clean_uri = trim(substr($clean_uri, strlen($base_path)), '/');
    }

    // NEW: Automatic redirect for .php extensions to clean URLs
    // Only for GET requests to avoid breaking form submissions/API posts
    // Skip for API and AJAX paths
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_ends_with($clean_uri, '.php') && 
        !str_starts_with($clean_uri, 'api/') && !str_starts_with($clean_uri, 'ajax/')) {
        
        $clean_version = substr($clean_uri, 0, -4);
        // If there's a mapped route for the clean version, redirect to it
        if (isset($routes[$clean_version])) {
            header("Location: " . getUrl($clean_version), true, 301);
            exit();
        }
    }

    // 1. Map lookup
    if (isset($routes[$clean_uri])) {
        $file = $routes[$clean_uri];
        if (file_exists($file)) {
            require_once $file;
            return true;
        } else {
            // File is mapped but missing - Show Coming Soon
            if (file_exists(COMING_SOON_FILE)) {
                // DEBUG: Show what path we were looking for
                if (str_starts_with($clean_uri, 'api/')) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'API file not found at path: ' . $file]);
                    exit;
                }
                require_once COMING_SOON_FILE;
                return true;
            }
        }
    }

    // 2. Fallback: Literal files (relative to clean root)
    $possible_files = [
        ROOT_DIR . '/' . ltrim($clean_uri, '/'),
        ROOT_DIR . '/' . ltrim($clean_uri, '/') . '.php'
    ];
    foreach ($possible_files as $f) {
        if (file_exists($f) && is_file($f)) {
            require_once $f;
            return true;
        }
    }

    return false;
}

/**
 * Get relative path from module to root
 * @param string $module The module name (e.g., 'accounts', 'customers')
 * @return string The relative path to root
 */
function getRelativeRoot($module = '') {
    switch ($module) {
        case 'accounts':
        case 'customers':
        case 'communication':
        case 'document':
        case 'integrations':
        case 'profile':
        case 'resources':
        case 'users':
        case 'payroll':
            return '../../../';
        default:
            return '';
    }
}

/**
 * Build a clean URL with domain
 * @param string $page The page identifier
 * @return string Full URL with domain
 */
function buildUrl($page) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $domain = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $domain . getUrl($page);
}



/**
 * Redirect to a clean URL
 * @param string $page The page identifier
 */
function redirectTo($page) {
    if (empty($page)) return;
    
    $target = getUrl($page);
    $current = strtok($_SERVER['REQUEST_URI'], '?');
    
    // Avoid infinite redirect loop
    if ($current === $target) {
        return;
    }
    
    header('Location: ' . $target);
    exit();
}

/**
 * Includes the main application header
 */
function includeHeader() {
    global $pdo, $pdo_accounts, $username, $user_role, $role_id, $company_type, $company_name, $company_logo;
    if (file_exists(HEADER_FILE)) {
        require HEADER_FILE;
    }
}

/**
 * Includes the main application footer
 */
function includeFooter() {
    global $pdo, $pdo_accounts, $username, $user_role, $role_id, $company_type;
    if (file_exists(FOOTER_FILE)) {
        require FOOTER_FILE;
    }
}

/**
 * Renders a standardized print header with company logo and blue name.
 * Uses settings from Admin > Company Profile automatically.
 */
function renderPrintHeader() {
    $name = get_setting('company_name', 'BUSINESS MANAGEMENT SYSTEM');
    $logo = get_setting('company_logo');
    
    echo '<div class="bms-print-header d-none d-print-block">';
    if ($logo) {
        echo '<img src="' . getUrl($logo) . '" alt="Logo">';
    }
    echo '<h1 class="bph-company">' . htmlspecialchars($name) . '</h1>';
    echo '</div>';
}

?>