<?php
/**
 * Record pages whose address carries the record id in the PATH, not the query.
 *
 *   /invoice_view?id=231   →   /invoice_view/231
 *
 * Every link in the system keeps building "page?id=N" exactly as before; for a
 * page listed here the router answers a GET for that with a redirect to the
 * path form, and serves the path form by filling in $_GET['id'] / $_REQUEST['id']
 * — so the page itself needs no change. POST to the path form works too (forms
 * posting to "the current address"). Other query values (tab, back, project_id…)
 * stay in the query string.
 *
 * A page may only be listed after it has been checked for relative links:
 * under /invoice_view/231 a link written as "invoices.php" or a call written as
 * fetch('api/x.php') would resolve to /invoice_view/invoices.php and break.
 * tests/test_detail_urls_cli.php scans every listed page's source for that and
 * opens each one over HTTP with a real record.
 *
 * Not listed on purpose: print pages (opened in their own tab; no benefit),
 * create pages whose "?edit=" is a mode rather than a record, and pages that
 * read the id in JavaScript. account_details and budget_details take
 * account_id / category_id, not id, so they are not record-number pages.
 *
 * Not listed yet because no local record exists to verify them against (they keep
 * working with ?id=N exactly as before): sales_order_view, sales_order_edit,
 * quotation_view, payslip, project_view, field_visit_details.
 *
 * Keys here are the SHORT keys from core/route_aliases.php.
 */

return [
    // Accounts
    'reconciliation_details', 'cash_register_details', 'expense_details', 'edit_expense',
    'journal_details', 'edit_journal', 'transaction_details',
    // Customers / suppliers / sub-contractors
    'customer_details', 'edit_customer', 'customer_group_details', 'customer_group_members',
    'supplier_details', 'sub_contractor_details',
    // Products / stock
    'product_view', 'product_edit', 'service_view', 'warehouse_view', 'asset_view',
    // Sales
    'invoice_view', 'invoice_edit', 'lpo_view',
    'sales_return_view', 'sales_return_edit', 'credit_note_view', 'credit_note_edit',
    // Purchasing
    'purchase_order_details', 'rfq_view', 'purchase_return_view', 'debit_note_view', 'debit_note_edit',
    'received_invoices_view', 'view_nip_materials', 'edit_nip_materials', 'view_material_list',
    // Receiving / delivery
    'grn_view', 'grn_edit', 'dn_view', 'do_view',
    // Tenders
    'tender_view', 'tender_edit', 'tender_boq', 'tender_materials', 'tender_checklist', 'tender_form_of_tender',
    // People / projects
    'employee_details', 'leave_details', 'payroll_details', 'inspection_view', 'edit_user',
    // CRM / field
    'lead_view',
];
