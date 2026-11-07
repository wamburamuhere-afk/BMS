<?php
/**
 * One page, one short address (clean URLs, 2026-11-02).
 *
 * Every key on the left is an OLD address that still works; the value is the
 * single short address the page now lives at. Two places use this list:
 *
 *   - getUrl()      — any getUrl('restaurant/menu') call in the code already
 *                      prints the short form (/menu), so links, menus, buttons
 *                      and redirects all show the new address without each call
 *                      site being edited.
 *   - handleRoute() — a GET for an old address (a bookmark, a typed URL, a
 *                      hard-coded JS link) is sent to the short one with a 301,
 *                      query string kept. POST/PUT/DELETE are never redirected:
 *                      the old key is still in $routes and is served as before,
 *                      so no form or AJAX call can break.
 *
 * The short key is added to $routes automatically (same file as the old key)
 * when it is not there already — see roots.php right after $routes.
 *
 * Rules for adding a line:
 *   - the short name must not already be a route for a DIFFERENT file;
 *   - the short name must never be LESS guarded than the old one by
 *     featureForPageKey() / simpleModeHiddenPageKeys(). It may be more
 *     guarded: many old side-addresses (accounts/journals, bms/locations…)
 *     skipped the router's module check that the menu's own address already
 *     had, and sending them to that address closes the gap;
 *   - api/, ajax/ and actions/ never go here.
 * tests/test_clean_urls_cli.php checks all three.
 */

return [
    // ── Restaurant ─────────────────────────────────────────────────────────
    'restaurant/floors'            => 'floors',
    'restaurant/tables'            => 'tables',
    'restaurant/kitchen'           => 'kitchen',
    'restaurant/kitchen-dashboard' => 'kitchen-dashboard',
    'restaurant/modifier-group'    => 'modifier-group',
    'restaurant/reservations'      => 'reservations',
    'restaurant/menu-type'         => 'menu-type',
    'restaurant/menu'              => 'menu',
    'restaurant/tables-board'      => 'tables-board',
    'restaurant/sales-report'      => 'restaurant-sales',
    'restaurant/waste-report'      => 'waste-report',
    'restaurant/purchases'         => 'purchases',

    // ── POS ────────────────────────────────────────────────────────────────
    'pos/dashboard'                => 'pos_dashboard',
    'pos/workspace'                => 'pos_dashboard',
    'pos/sales-history'            => 'pos_dashboard',
    'pos_sales_history'            => 'pos_dashboard',
    'pos/shifts'                   => 'shifts',
    'pos/shift-history'            => 'shifts',
    'pos/zreport'                  => 'zreport',
    'pos/price-groups'             => 'price-groups',
    'pos_price_groups'             => 'price-groups',
    'pos/credit-customers'         => 'credit-customers',

    // ── Accounts ───────────────────────────────────────────────────────────
    'accounts/bank_reconciliation'    => 'bank_reconciliation',
    'bank-reconciliation'             => 'bank_reconciliation',
    'reconciliation'                  => 'bank_reconciliation',
    'account/details'                 => 'account_details',
    'account/view'                    => 'account_details',
    'accounts/account_details'        => 'account_details',
    'accounts/budget'                 => 'budget',
    'budget/view'                     => 'budget_details',
    'budget/details'                  => 'budget_details',
    'accounts/budget_details'         => 'budget_details',
    'chart-of-accounts'               => 'chart_of_accounts',
    'accounts/chart_of_accounts'      => 'chart_of_accounts',
    'accounts/expenses'               => 'expenses',
    'expenses/view'                   => 'expense_details',
    'expenses/details'                => 'expense_details',
    'accounts/expense_details'        => 'expense_details',
    'expenses/edit'                   => 'edit_expense',
    'accounts/edit_expense'           => 'edit_expense',
    'accounts/journals'               => 'journals',
    'journal/view'                    => 'journal_details',
    'journal/details'                 => 'journal_details',
    'accounts/journal_details'        => 'journal_details',
    'journal/add'                     => 'add_journal',
    'accounts/add_journal'            => 'add_journal',
    'journal/edit'                    => 'edit_journal',
    'accounts/edit_journal'           => 'edit_journal',
    'reconciliation/view'             => 'reconciliation_details',
    'bank-reconciliation/view'        => 'reconciliation_details',
    'accounts/reconciliation_details' => 'reconciliation_details',
    'accounts/transactions'           => 'transactions',
    'transaction/view'                => 'transaction_details',
    'transaction/details'             => 'transaction_details',
    'accounts/transaction_details'    => 'transaction_details',
    'accounts/trial_balance'          => 'trial-balance',
    'reports/trial_balance'           => 'trial-balance',
    'bank-accounts'                   => 'bank_accounts',
    'cash-register'                   => 'cash_register',
    'expense-types'                   => 'expense_types',
    'revenue-categories'              => 'revenue_categories',
    'petty-cash'                      => 'petty_cash',
    'bank-statement'                  => 'bank_statement',
    'bank-transfers'                  => 'bank_transfers',
    'receive-payment'                 => 'receive_payment',
    'customer-deposits'               => 'customer_deposits',
    'salary-components'               => 'salary_components',
    'sales_invoices'                  => 'invoices',
    'billing'                         => 'subscription',

    // ── Reports ────────────────────────────────────────────────────────────
    'reports/ledger_report'           => 'ledger_report',
    'reports/ar_aging'                => 'ar_aging',
    'reports/delinquency_report'      => 'ar_aging',
    'reports/ap_aging'                => 'ap_aging',
    'reports/customer_statement'      => 'customer_statement',
    'reports/vendor_statement'        => 'vendor_statement',
    'reports/audit_logs'              => 'audit_logs',
    'reports/financial_statements'    => 'financial_statements',
    'reports/income_statement'        => 'income_statement',
    'profit_loss_report'              => 'income_statement',
    'reports/balance_sheet'           => 'balance_sheet',
    'reports/cash_flow'               => 'cash_flow',
    'reports/consolidated_expenses'   => 'consolidated_expenses',

    // ── Customers / suppliers / sub-contractors ────────────────────────────
    'customers/details'               => 'customer_details',
    'customers/view'                  => 'customer_details',
    'customers/edit'                  => 'edit_customer',
    'customers/group_details'         => 'customer_group_details',
    'customers/group_members'         => 'customer_group_members',
    'customers/groups'                => 'customer_groups',
    'customers/import'                => 'import_customers',
    'suppliers/details'               => 'supplier_details',
    'suppliers/view'                  => 'supplier_details',
    'suppliers/payments'              => 'supplier_payments',
    'suppliers/categories'            => 'supplier_categories',
    'sub_contractors/details'         => 'sub_contractor_details',
    'sub_contractors/view'            => 'sub_contractor_details',

    // ── Products / stock / sales ───────────────────────────────────────────
    'services/view'                   => 'service_view',
    'products/create'                 => 'product_create',
    'products/edit'                   => 'product_edit',
    'products/view'                   => 'product_view',
    'bms/locations'                   => 'locations',
    'bms/stock_transfers'             => 'stock_transfers',
    'bms/warehouse_view'              => 'warehouse_view',
    'bms/assets'                      => 'assets',
    'sales_orders/create'             => 'sales_order_create',

    // ── CRM / field reports ────────────────────────────────────────────────
    'crm/dashboard'                   => 'crm',
    'crm/leads'                       => 'crm_leads',
    'crm/lead_view'                   => 'lead_view',
    'crm/pipeline'                    => 'pipeline',
    'crm/pipeline_stages'             => 'pipeline_stages',
    'crm/import_leads'                => 'import_leads',
    'field_reports/print'             => 'field_report_print',
    'field_reports/details'           => 'field_visit_details',

    // ── Documents / communication / integrations / settings ────────────────
    'library'                         => 'document_library',
    'documents/library'               => 'document_library',
    'templates'                       => 'document_templates',
    'documents/templates'             => 'document_templates',
    'documents/workflow'              => 'document_workflow',
    'documents/e_signatures'          => 'e_signatures',
    'communication/campaign_management'   => 'campaigns',
    'communication/email_templates'       => 'email_templates',
    'communication/lead_generation'       => 'leads',
    'communication/message_center'        => 'message_center',
    'communication/notification_center'   => 'notification_center',
    'integrations/api_dashboard'          => 'api_dashboard',
    'integrations/api_documentation'      => 'api_documentation',
    'integrations/banking_integration'    => 'banking_integration',
    'integrations/credit_bureau'          => 'credit_bureau',
    'integrations/crm_integration'        => 'crm_integration',
    'integrations/payment_gateways'       => 'payment_gateways',
    'integrations/webhooks'               => 'webhooks',
    'settings/notifications'          => 'notification_settings',
    'settings/notification-rules'     => 'notification_rules',
    'settings/system'                 => 'system_settings',
    'settings/ai'                     => 'ai_settings',
    'settings/zoom'                   => 'zoom_settings',
];
