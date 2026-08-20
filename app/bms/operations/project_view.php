<?php
// File: app/bms/operations/project_view.php
define('BMS_SUPPRESS_PRINT_HEADER', true);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';
require_once __DIR__ . '/../../../core/payment_source.php';

// Phase 5b — enforce view permission on project detail
autoEnforcePermission('projects');

// Phase B (scope) — block detail view of projects not in user scope
$project_id_param = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($project_id_param > 0 && !userCan('project', $project_id_param)) {
    http_response_code(403);
    die('Access denied: this project is not in your scope.');
}

includeHeader();

// Ensure user info is in session for print footer
if (isset($_SESSION['user_id']) && (!isset($_SESSION['first_name']) || empty($_SESSION['first_name']) || !isset($_SESSION['username']))) {
    global $pdo;
    $stmtU = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE user_id = ?");
    $stmtU->execute([$_SESSION['user_id']]);
    $uData = $stmtU->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $_SESSION['first_name'] = $uData['first_name'] ?? '';
        $_SESSION['last_name'] = $uData['last_name'] ?? '';
        $_SESSION['username'] = $uData['username'] ?? '';
    }
}

$project_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// SC mode: triggered when coming from Sub-Contractor > View Project
$sc_id   = isset($_GET['sc_id']) ? intval($_GET['sc_id']) : 0;
$sc_mode = ($sc_id > 0);
$sc_name = '';
if ($sc_mode) {
    $sc_stmt = $pdo->prepare("SELECT supplier_name FROM sub_contractors WHERE supplier_id = ?");
    $sc_stmt->execute([$sc_id]);
    $sc_name = $sc_stmt->fetchColumn() ?: 'Unknown Sub-Contractor';
}

// Supplier mode: triggered when coming from Supplier > View Project
$view_supplier_id  = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;
$supplier_mode     = ($view_supplier_id > 0);
$supplier_view_name = '';
if ($supplier_mode) {
    $sup_stmt = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
    $sup_stmt->execute([$view_supplier_id]);
    $supplier_view_name = $sup_stmt->fetchColumn() ?: 'Unknown Supplier';
}

// Restricted mode — same limited tab set for both SC and Supplier views
$restricted_mode = $sc_mode || $supplier_mode;

// Bills (received_invoices) permissions — the project Bills tab mirrors the
// standalone Bills page and delegates create / edit / payment to it (with the
// current project pre-selected and locked), so it reuses the same permissions.
$ri_can_create  = canCreate('received_invoices');
$ri_can_edit    = canEdit('received_invoices');
$ri_can_delete  = canDelete('received_invoices');
$ri_can_review  = canReview('received_invoices');
$ri_can_approve = canApprove('received_invoices');

// Departments feed the Payroll "Filter Department" dropdown; leave types feed the Apply Leave modal
$hr_departments      = $pdo->query("SELECT department_id, department_name FROM departments WHERE status='active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
$hr_leave_types      = $pdo->query("SELECT type_name, max_days_per_year, requires_document FROM leave_types WHERE status='active' ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Expense Categories for the Add Budget Modal
$category_items = $pdo->query("SELECT id AS category_id, name AS category_name FROM expense_categories WHERE status = 'active' ORDER BY (CASE WHEN name = 'Other' THEN 1 ELSE 0 END), name")->fetchAll(PDO::FETCH_ASSOC);

// Year/Month options for the Add Budget Modal — same range/labels as the external
// budget.php form (app/constant/accounts/budget.php).
$budget_months = [
    1 => 'January', 2 => 'February', 3 => 'March',    4 => 'April',
    5 => 'May',     6 => 'June',     7 => 'July',      8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
$budget_current_year = (int)date('Y');
$budget_years = [];
for ($y = $budget_current_year - 2; $y <= $budget_current_year + 3; $y++) { $budget_years[$y] = $y; }
$budget_selected_year  = $budget_current_year;
$budget_selected_month = (int)date('n');

// Fetch Expense Accounts for the Edit Expense Modal
$expense_accounts = $pdo->query("SELECT account_id, account_name, account_code FROM accounts WHERE status = 'active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%expense%') ORDER BY account_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Bank/Cash Accounts
$bank_accounts = $pdo->query("SELECT account_id, account_name, account_code FROM accounts WHERE status = 'active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%Asset%' OR type_name LIKE '%Bank%' OR type_name LIKE '%Cash%') ORDER BY account_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch payees scoped to this project only
$sc_stmt = $pdo->prepare("
    SELECT sc.supplier_id, sc.supplier_name
    FROM sub_contractors sc
    JOIN sub_contractor_projects psc ON psc.supplier_id = sc.supplier_id AND psc.project_id = ?
    WHERE sc.status = 'active'
    ORDER BY sc.supplier_name ASC
");
$sc_stmt->execute([$project_id]);
$sub_contractors = $sc_stmt->fetchAll(PDO::FETCH_ASSOC);

$sup_stmt = $pdo->prepare("
    SELECT s.supplier_id, s.supplier_name
    FROM suppliers s
    JOIN supplier_projects sp ON sp.supplier_id = s.supplier_id AND sp.project_id = ?
    WHERE s.status = 'active'
    ORDER BY s.supplier_name ASC
");
$sup_stmt->execute([$project_id]);
$all_suppliers = $sup_stmt->fetchAll(PDO::FETCH_ASSOC);

$emp_stmt = $pdo->prepare("
    SELECT employee_id, first_name, last_name
    FROM employees
    WHERE status = 'active' AND project_id = ?
    ORDER BY first_name ASC
");
$emp_stmt->execute([$project_id]);
$all_employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Customers for edit form
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Document Categories
$doc_categories = $pdo->query("SELECT * FROM document_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Supplier Categories
$supplier_categories = $pdo->query("SELECT category_id, category_name FROM supplier_categories WHERE status = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Projects for supplier linking
$projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status != 'deleted' ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Warehouses for returns — shared helper, also respects the user's
// direct warehouse grant (Phase 6, pos_upgrade_plan.md).
$warehouses = warehousesForSelect($pdo);

// Fetch project details for print display
$stmt = $pdo->prepare("SELECT project_name, contract_number FROM projects WHERE project_id = ?");
$stmt->execute([$project_id]);
$project_data_row = $stmt->fetch(PDO::FETCH_ASSOC);
$project_name = $project_data_row['project_name'] ?? '';
$contract_no = $project_data_row['contract_number'] ?? 'N/A';

// Fetch company settings for print header
$company_name = getSetting('company_name', 'BMS');
$company_logo = getSetting('company_logo', '');

// Fetch tax rates for NIP edit modal
$tax_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status='active' ORDER BY rate_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch warehouses scoped to this project (for Non-inventory Products tab) —
// also filtered by the user's own warehouse grant (Phase 6, pos_upgrade_plan.md).
$nipWh = $pdo->prepare("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status='active' AND project_id = ?" . scopeFilterSqlNullable('warehouse') . " ORDER BY warehouse_name ASC");
$nipWh->execute([$project_id]);
$proj_warehouses = $nipWh->fetchAll(PDO::FETCH_ASSOC);

// NIP products for this project (for Add Materials form)
$proj_nip_stmt = $pdo->prepare("
    SELECT p.product_id, p.product_name
    FROM products p
    LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
    WHERE p.is_service = 1 AND p.status = 'active'
      AND (p.project_id = ? OR (p.project_id IS NULL AND w.project_id = ?))
    ORDER BY p.product_name ASC
");
$proj_nip_stmt->execute([$project_id, $project_id]);
$proj_nip_products = $proj_nip_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch top-level project milestones for Inspections modal (parent_id IS NULL = main milestones only)
$proj_ms_stmt = $pdo->prepare("SELECT id, description, scope FROM project_milestones WHERE project_id = ? AND scope_type = 'milestone' AND (parent_id IS NULL OR parent_id = 0) ORDER BY id ASC");
$proj_ms_stmt->execute([$project_id]);
$proj_milestones = $proj_ms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch APPROVED sales orders for IPC modal (with customer_id for JS filtering)
$proj_so_stmt = $pdo->prepare("SELECT so.sales_order_id, so.order_number, so.customer_id FROM sales_orders so WHERE so.project_id = ? AND so.status = 'approved' ORDER BY so.created_at DESC");
$proj_so_stmt->execute([$project_id]);
$proj_sales_orders = $proj_so_stmt->fetchAll(PDO::FETCH_ASSOC);

// Customers who have approved sales orders on this project
$ipc_cust_stmt = $pdo->prepare("SELECT DISTINCT c.customer_id, c.customer_name FROM customers c JOIN sales_orders so ON c.customer_id = so.customer_id WHERE so.project_id = ? AND so.status = 'approved' ORDER BY c.customer_name");
$ipc_cust_stmt->execute([$project_id]);
$ipc_customers = $ipc_cust_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4 pt-0">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0" style="font-size: 0.75rem;">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none text-muted">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('projects') ?>" class="text-decoration-none text-muted">Projects</a></li>
            <li class="breadcrumb-item active text-primary fw-bold">Details</li>
        </ol>
    </nav>

    <div id="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="mt-2 text-muted">Loading project details...</p>
    </div>

    <div id="content" style="display: none;">
        <!-- Print Header (Overview Only) -->
        <div class="print-header-overview text-center mb-2 d-none">
            <?php if(!empty($company_logo)): ?>
                <div class="mb-2">
                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                </div>
            <?php endif; ?>
            <h1 class="fw-bold text-primary mb-1" style="text-transform: uppercase; font-size: 1.5rem;"><?= htmlspecialchars($company_name) ?></h1>
            <h4 class="fw-bold mb-2 text-dark" style="text-transform: uppercase; font-size: 1.1rem;">PROJECT DETAILS REPORT</h4>
            <div class="mx-auto bg-primary mb-2" style="width: 60px; height: 2px; border-radius: 2px;"></div>
            <h5 class="fw-bold mb-3" id="projectTitlePrint"></h5>
        </div>

        <style>
            @page { margin: 10mm 8mm 16mm 8mm; }
            @media print {
                tfoot { display: table-row-group !important; }
                /* Force tfoot to only appear at the end for modern browsers */
                table tfoot { display: table-row-group !important; }
                table thead { display: table-header-group; }
            }

            /* ── Delivery Notes (#dtDNs) print column widths ──
               Auto-layout squeezed the long-value columns (DN Number, DO Ref,
               Supplier, Date) into narrow, badly-wrapped cells while S/NO and Items
               got too wide. table-layout:fixed + explicit %s gives content-friendly
               widths that adapt to BOTH portrait and landscape. */
            @media print {
                #dtDNs { table-layout: fixed !important; width: 100% !important; border-collapse: collapse !important; font-size: 9pt !important; }
                #dtDNs th, #dtDNs td {
                    white-space: normal !important; word-break: break-word !important; overflow: visible !important;
                    padding: 6px 8px !important; border: 1px solid #dee2e6 !important; vertical-align: middle !important;
                }
                #dtDNs thead th { font-size: 8pt !important; font-weight: 700 !important; background-color: #f8f9fa !important; text-transform: uppercase !important; }
                /* thin columns (small values) */
                #dtDNs th:nth-child(1), #dtDNs td:nth-child(1) { width: 6% !important; }   /* S/NO   */
                #dtDNs th:nth-child(6), #dtDNs td:nth-child(6) { width: 7% !important; }   /* Items  */
                /* wide columns (long values) */
                #dtDNs th:nth-child(2), #dtDNs td:nth-child(2) { width: 22% !important; }  /* DN Number */
                #dtDNs th:nth-child(3), #dtDNs td:nth-child(3) { width: 20% !important; }  /* DO Ref    */
                #dtDNs th:nth-child(4), #dtDNs td:nth-child(4) { width: 16% !important; }  /* Supplier  */
                #dtDNs th:nth-child(5), #dtDNs td:nth-child(5) { width: 13% !important; }  /* Date      */
                #dtDNs th:nth-child(7), #dtDNs td:nth-child(7) { width: 16% !important; }  /* Status    */
                #dtDNs tr { page-break-inside: avoid !important; break-inside: avoid !important; }

                /* Sub-Contractors stat cards: keep all 4 on ONE row when printing.
                   They are col-6 col-md-3, so on a portrait page (width < the md
                   768px breakpoint) they fall back to col-6 = 2 per row. Force 25%
                   so the row holds all four in both portrait and landscape. */
                #proj-sc-stats > [class*="col-"] { flex: 0 0 25% !important; max-width: 25% !important; }
                #proj-sc-stats .card-body { padding: 0.4rem 0.6rem !important; }

                /* Inspections stat cards: same fix — keep all 4 (Total/Passed/Failed/
                   Re-inspect) on ONE row when printing, in both portrait and landscape. */
                #proj-insp-stats > [class*="col-"] { flex: 0 0 25% !important; max-width: 25% !important; }
                #proj-insp-stats .card-body { padding: 0.4rem 0.6rem !important; }

                /* Payroll stat cards: same fix — keep all 4 (Active Staff/Paid/Pending/
                   Total Payout) on ONE row when printing, in both portrait and landscape. */
                #payrollStatCards > [class*="col-"] { flex: 0 0 25% !important; max-width: 25% !important; }
                #payrollStatCards .card { padding: 0.35rem !important; }

                /* Doc Library table (#projectDocsTable): Source/Category, Type, and Date
                   Added use Bootstrap's d-none d-md-table-cell / d-none d-lg-table-cell —
                   screen-responsive classes meant to hide columns on a NARROW MOBILE
                   viewport. Print inherits the same breakpoints, so on a print render
                   under those widths these columns vanish, leaving only S/NO and
                   Document Title. Force all 5 required columns to always show on print;
                   Actions (a dropdown menu, not useful on paper) is hidden instead. */
                #projectDocsTable th.d-none, #projectDocsTable td.d-none {
                    display: table-cell !important;
                }
                #projectDocsTable th:last-child, #projectDocsTable td:last-child {
                    display: none !important;
                }

                /* Scope tables (Original / Revised / Variation / Additional) must
                   start on the FIRST printed page. Their table sits in a .card, and
                   responsive.css forces `.card{page-break-inside:avoid !important}` —
                   on a landscape page (shorter than portrait) the card doesn't fit
                   after the print header, so it gets pushed whole to page 2. Allow it
                   to break so it flows from page 1; overflow:visible stops the
                   `overflow-hidden` card from clipping rows that cross a page. */
                #scope-original .card, #scope-revised .card,
                #scope-variation .card, #scope-additional .card {
                    page-break-inside: auto !important;
                    break-inside: auto !important;
                    overflow: visible !important;
                }

                /* A digit value must NEVER wrap to a new line inside a scope cell.
                   Keep the table's ORIGINAL fixed column widths (so the whole table
                   still fits a portrait page) and just stop the wrapping:
                     - nowrap every cell (numbers stay on one line),
                     - keep the UNIT textarea (e.g. "kg") on one line,
                     - shrink the big tfoot totals a notch so a long number
                       (e.g. "117,247,800.00") fits inside its column on one line.
                   Only DESCRIPTION (free text) may wrap — targeted by data-label, NOT
                   nth-child, because the tfoot total is also the 2nd <td> (its label
                   spans colspan=6). Applies to all four scope tables via .scope-table. */
                .scope-table th, .scope-table td { white-space: nowrap !important; }
                .scope-table td[data-label="DESCRIPTION"] {
                    white-space: normal !important; word-break: break-word !important;
                }
                .scope-table .s-unit { white-space: nowrap !important; overflow: hidden !important; }
                .scope-table tfoot td {
                    font-size: 10.5pt !important;
                    padding-top: 6px !important; padding-bottom: 6px !important;
                    padding-right: 8px !important;
                }
            }
            @media (max-width: 767px) {
                /* Hide all table/card toggle buttons on mobile — card view is always automatic */
                [id$="-btn-tbl"], [id$="-btn-crd"] { display: none !important; }
                .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
            }
            .text-indigo { color: #6610f2 !important; }
            .btn-milestone-filter:hover, .btn-milestone-filter.active {

            /* ===== DELIVERY ORDERS (DO) TABLE — WEB & PRINT ===== */

            /* Web view: full-width, no horizontal scroll hang */
            .do-section-wrapper { width: 100%; }
            .do-table-wrapper {
                width: 100% !important;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .do-data-table {
                width: 100% !important;
                min-width: 600px;
                table-layout: auto !important;
            }
            .do-data-table td,
            .do-data-table th {
                white-space: nowrap;
                vertical-align: middle !important;
                padding: 8px 10px !important;
            }

            /* Print: DO table fits both portrait and landscape cleanly */
            @media print {
                /* Show DO section on print */
                .do-section-wrapper { display: block !important; }

                /* Hide DO screen-header on print (replaced by do-print-header) */
                .do-section-wrapper > .d-print-none { display: none !important; }

                /* Separator between DN and DO sections on print */
                .do-print-header {
                    margin-top: 18mm !important;
                    page-break-before: always !important;
                }

                /* DO table print rules */
                #dtDOs,
                .do-data-table {
                    width: 100% !important;
                    table-layout: auto !important;
                    border-collapse: collapse !important;
                    font-size: 9pt !important;
                }

                /* All DO cells: no cutting, normal wrapping, solid margins */
                #dtDOs th,
                #dtDOs td,
                .do-data-table th,
                .do-data-table td {
                    white-space: normal !important;
                    word-break: break-word !important;
                    word-wrap: break-word !important;
                    overflow: visible !important;
                    padding: 6px 8px !important;
                    border: 1px solid #dee2e6 !important;
                    vertical-align: middle !important;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                }

                #dtDOs thead th,
                .do-data-table thead th {
                    font-size: 8pt !important;
                    font-weight: 700 !important;
                    background-color: #f8f9fa !important;
                    text-transform: uppercase !important;
                }

                /* No row should be cut mid-page */
                #dtDOs tr,
                .do-data-table tr {
                    page-break-inside: avoid !important;
                    break-inside: avoid !important;
                }

                /* Wrapper must not clip */
                .do-table-wrapper {
                    overflow: visible !important;
                    width: 100% !important;
                }

                /* Portrait & Landscape: scale columns proportionally */
            }
                color: #fff !important;
                background-color: #0d6efd !important;
                border-color: #0d6efd !important;
            }
            /* Mobile Tab Optimization */
            @media (max-width: 768px) {
                body { overflow-x: hidden !important; width: 100%; position: relative; }
                .scrollbar-hidden::-webkit-scrollbar { display: none; }
                .scrollbar-hidden { -ms-overflow-style: none; scrollbar-width: none; }
                #projectWorkspaceTabs .nav-link { 
                    padding: 12px 15px !important;
                    font-size: 0.85rem;
                }
                .container-fluid { overflow-x: hidden !important; }
                /* Ensure financial cards icons are smaller on mobile */
                .overview-print-section .rounded-3 {
                    width: 35px !important;
                    height: 35px !important;
                    padding: 0.4rem !important;
                    margin-right: 0 !important;
                    margin-bottom: 0.5rem !important;
                }
                .overview-print-section .bi { font-size: 0.9rem !important; }
                .overview-print-section .d-flex { flex-direction: column !important; align-items: flex-start !important; }
                .overview-print-section h5 { font-size: 13px !important; width: 100%; white-space: normal; word-break: break-all; }
                .overview-print-section p { font-size: 10px !important; margin-bottom: 2px !important; }

                /* Fix for dropdowns being hidden inside scrollable tabs header */
                #projectWorkspaceTabs .dropdown-menu {
                    position: fixed !important;
                    top: 15% !important;
                    left: 5% !important;
                    right: 5% !important;
                    width: 90% !important;
                    margin: 0 auto !important;
                    z-index: 1080 !important;
                    transform: none !important;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.15) !important;
                    border: 1px solid rgba(0,0,0,0.05) !important;
                }
                #projectWorkspaceTabs .dropdown-menu.show {
                    display: block !important;
                }

                /* --- Project Table wrapping (Planning, Review, Schedules, Scopes) --- */
                .scope-table td, 
                #milestonesTable td, 
                #performanceTable td, 
                .schedule-table-side td {
                    white-space: normal !important;
                    word-wrap: break-word !important;
                    min-width: 0;
                    vertical-align: top !important;
                }
                .scope-table textarea.s-desc, 
                .scope-table textarea.s-unit,
                #milestonesTable textarea.m-desc {
                    min-width: 100%;
                    line-height: 1.5;
                    border: 1px solid #dee2e6;
                    padding: 4px 8px;
                    border-radius: 4px;
                }
                /* Styling for read-only areas to look clean */
                .scope-table textarea[readonly],
                #milestonesTable textarea[readonly],
                #performanceTable span {
                    background-color: transparent !important;
                    border: none !important;
                    padding-left: 0 !important;
                    padding-right: 0 !important;
                    color: inherit;
                    font-weight: inherit;
                    box-shadow: none !important;
                    display: inline-block;
                    width: 100%;
                }
                .scope-table input[readonly],
                #milestonesTable input[readonly] {
                    background-color: transparent !important;
                    border: none !important;
                    box-shadow: none !important;
                    font-weight: 500;
                }

                /* --- Scope Table Mobile Optimization --- */
                .scope-table thead {
                    display: table-header-group;
                }

                @media screen and (max-width: 768px) {
                    .scope-table thead {
                        display: none;
                    }
                    .scope-table, .scope-table tbody, .scope-table tr, .scope-table td {
                        display: block;
                        width: 100%;
                    }
                    .scope-table tr.scope-row {
                        margin-bottom: 1rem;
                        background: #fff;
                        border: 1px solid #eef2f7 !important;
                        border-radius: 12px;
                        padding: 1rem;
                        box-shadow: 0 4px 6px rgba(0,0,0,0.03);
                        position: relative;
                    }
                    .scope-table td {
                        padding: 10px 0 !important;
                        border: none !important;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        text-align: right;
                        border-bottom: 1px dashed #f1f1f1 !important;
                    }
                    .scope-table td:last-child {
                        border-bottom: none !important;
                        justify-content: center;
                        padding-top: 12px !important;
                    }
                    .scope-table td::before {
                        content: attr(data-label);
                        font-weight: 700;
                        text-transform: uppercase;
                        font-size: 0.7rem;
                        color: #94a3b8;
                        text-align: left;
                        flex: 1;
                    }
                    .scope-table td[data-label="DESCRIPTION"] {
                        flex-direction: column;
                        align-items: flex-start;
                        text-align: left;
                    }
                    .scope-table td[data-label="DESCRIPTION"]::before {
                        margin-bottom: 5px;
                    }
                    .scope-table td .form-control {
                        width: 65% !important;
                        font-size: 0.85rem;
                        background-color: #f8fafc;
                        border: 1px solid #e2e8f0 !important;
                        border-radius: 6px;
                    }
                    .scope-table td .s-desc {
                        width: 100% !important;
                        font-weight: 700;
                        color: #1e293b;
                    }
                    .scope-table td .s-total {
                        background-color: #f1f5f9;
                        color: #0d6efd;
                    }
                    /* Footer Sum for Mobile - Fixed Row Layout */
                    @media screen {
                        .scope-table tfoot tr {
                            display: flex !important;
                            justify-content: space-between !important;
                            align-items: center !important;
                            background-color: #f8fafc !important;
                            border-radius: 10px;
                            padding: 12px 15px !important;
                            margin-top: 10px;
                            border-top: 2px solid #eef2f7 !important;
                            width: 100% !important;
                            box-sizing: border-box !important;
                        }
                    }
                    .scope-table tfoot td {
                        display: block !important;
                        width: auto !important;
                        padding: 0 !important;
                        border: none !important;
                    }
                    /* Ensure only 2 cells are visible in the flex row */
                    .scope-table tfoot td:not(:first-child):not(:nth-child(2)) {
                        display: none !important;
                    }
                    .scope-table tfoot td:first-child {
                        text-align: left !important;
                        font-size: 0.82rem;
                        color: #64748b;
                        font-weight: 700;
                        flex: 1 !important;
                    }
                    .scope-table tfoot td:nth-child(2) {
                        text-align: right !important;
                        font-weight: 800;
                        color: #0d6efd;
                        margin-left: auto !important;
                        white-space: nowrap !important;
                    }

                    /* Stack headers and buttons on mobile */
                    #scope-original .d-flex.justify-content-between,
                    #scope-revised .d-flex.justify-content-between,
                    #scope-variation .d-flex.justify-content-between,
                    #scope-additional .d-flex.justify-content-between {
                        flex-direction: column !important;
                        align-items: flex-start !important;
                        gap: 1rem;
                        margin-bottom: 1.5rem !important;
                    }
                    #scope-original .d-flex.gap-2,
                    #scope-revised .d-flex.gap-2,
                    #scope-variation .d-flex.gap-2,
                    #scope-additional .d-flex.gap-2 {
                        width: 100%;
                        justify-content: flex-start;
                        flex-wrap: wrap;
                    }
                    #scope-original .btn, #scope-revised .btn, 
                    #scope-variation .btn, #scope-additional .btn {
                        font-size: 0.8rem;
                        padding: 8px 12px;
                    }
                }
                #performance .btn-group {
                    width: 100% !important;
                    display: flex !important;
                    flex-wrap: nowrap !important;
                    box-shadow: none !important;
                    background-color: transparent !important;
                }
                #performance .btn-group .btn {
                    flex: 1 1 0 !important;
                    padding-left: 2px !important;
                    padding-right: 2px !important;
                    font-size: 0.65rem !important;
                    white-space: nowrap !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    min-width: 0 !important;
                    letter-spacing: -0.2px;
                }
                #performance .btn-group .btn-outline-success {
                    border-width: 1px !important;
                }

                /* Additional Report Header Optimization */
                #performance .card-header {
                    flex-direction: column !important;
                    align-items: flex-start !important;
                    padding: 12px !important;
                    display: flex !important;
                }
                #performance .card-header .d-flex.gap-2 {
                    width: 100% !important;
                    flex-wrap: wrap !important;
                    margin-top: 10px;
                    display: flex !important;
                    gap: 5px !important;
                }
                #performance .card-header h6 {
                    font-size: 0.9rem !important;
                    border-bottom: 1px solid #f0f0f0 !important;
                    width: 100% !important;
                    padding-bottom: 8px !important;
                    margin-bottom: 5px !important;
                }
                #performance .card-header .btn-group { 
                    margin-right: 0 !important; 
                    margin-bottom: 5px !important; 
                    width: 100% !important;
                    display: flex !important;
                }
                #performance .card-header .btn-group .btn-milestone-filter {
                    flex: 1 1 0 !important;
                    font-size: 0.72rem !important;
                    padding: 8px 5px !important;
                }
                #performance .card-header .dropdown {
                    flex: 1 1 calc(50% - 5px) !important;
                }
                #performance .card-header .dropdown .btn {
                    width: 100% !important;
                    font-size: 0.75rem !important;
                    padding: 8px 5px !important;
                    margin: 0 !important;
                }

            }

            /* --- Desktop (Web View) Sub-menu Optimization --- */
            @media (min-width: 769px) {
                .workspace-card-main > .card-header {
                    overflow: visible !important;
                }
                
                #projectWorkspaceTabs .dropdown-menu {
                    position: absolute !important;
                    top: 100% !important;
                    left: 0 !important;
                    z-index: 1070 !important;
                    border-radius: 0 0 10px 10px !important;
                    padding: 8px !important;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.10) !important;
                    border: 1px solid #eef2f7 !important;
                    border-top: 2px solid #0d6efd !important;
                    display: none;
                    flex-direction: column !important;
                    gap: 3px !important;
                    width: 220px !important;
                    background: #ffffff !important;
                    transform: none !important;
                    margin-top: 0 !important;
                }

                #projectWorkspaceTabs .dropdown-menu.show {
                    display: flex !important;
                }

                #projectWorkspaceTabs .dropdown-item {
                    display: flex !important;
                    align-items: center !important;
                    width: 100% !important;
                    padding: 6px 10px !important;
                    border-radius: 7px !important;
                    background: #f8fafc !important;
                    border: 1px solid #f1f5f9 !important;
                    color: #334155 !important;
                    font-weight: 600 !important;
                    font-size: 0.78rem !important;
                    transition: all 0.2s ease !important;
                    white-space: normal !important;
                }

                #projectWorkspaceTabs .dropdown-item:hover {
                    background: #0d6efd !important;
                    color: #fff !important;
                    transform: translateX(3px);
                    box-shadow: 0 3px 10px rgba(13, 110, 253, 0.15) !important;
                    border-color: #0d6efd !important;
                }

                #projectWorkspaceTabs .dropdown-item i {
                    font-size: 0.85rem !important;
                    margin-right: 7px !important;
                    width: 16px;
                    text-align: center;
                }

                #projectWorkspaceTabs .dropdown-header {
                    width: 100% !important;
                    font-size: 0.62rem !important;
                    color: #64748b !important;
                    margin: 6px 0 3px 0 !important;
                    padding: 3px 8px !important;
                    text-transform: uppercase;
                    letter-spacing: 0.8px;
                    border-left: 3px solid #0d6efd;
                    background: rgba(13, 110, 253, 0.05);
                    border-radius: 4px;
                    font-weight: 800 !important;
                }

                #projectWorkspaceTabs .dropdown-header:first-child {
                    margin-top: 0 !important;
                }
                
                #projectWorkspaceTabs .dropdown-divider {
                    margin: 3px 0 !important;
                    border-top: 1px dashed #e2e8f0 !important;
                    display: block !important;
                    opacity: 0.5;
                }
            }

            /* --- Mobile Scroll Fix --- */
            @media (max-width: 768px) {
                html, body {
                    width: 100vw !important;
                    overflow-x: hidden !important;
                    position: relative;
                }
                .workspace-card-main {
                    margin-left: -5px !important;
                    margin-right: -5px !important;
                    width: calc(100% + 10px) !important;
                    max-width: none !important;
                    border-radius: 0 !important;
                }
                .container, .container-fluid {
                    padding-left: 10px !important;
                    padding-right: 10px !important;
                    overflow-x: hidden !important;
                }
                #projectWorkspaceTabs {
                    flex-wrap: nowrap !important;
                }
                /* Ensure all tables are wrapped to prevent stretching */
                .table-responsive {
                    border: 0 !important;
                    margin-bottom: 0 !important;
                    overflow-x: auto !important;
                    -webkit-overflow-scrolling: touch;
                }
            }

            /* --- Schedule Web View Optimization --- */
            #fullScheduleExportArea {
                width: 100% !important;
                min-width: auto !important;
                margin: 0 !important;
            }
            .schedule-container {
                max-width: 100% !important;
                overflow-x: hidden !important; /* The individual sides handle overflow */
            }
            .schedule-table-side {
                flex: 0 0 auto;
                min-width: 650px;
                max-width: 100%;
            }
            .schedule-gantt-side {
                flex: 1 1 auto;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
                max-width: 100%;
            }
        </style>

        <!-- Header -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-3">
            <div class="text-center text-md-start">
                <h2 class="fw-bold mb-0 text-primary fs-4 fs-md-2"><i class="bi bi-briefcase me-2"></i> <span id="projectNameDisplay"></span></h2>
                <div class="mt-1 mt-md-2 d-flex flex-wrap justify-content-center justify-content-md-start align-items-center gap-2">
                    <span id="projectStatusBadge" class="badge rounded-pill status-badge" style="font-size: 0.7rem;"></span>
                    <span class="text-muted d-flex align-items-center" style="font-size: 0.8rem;"><i class="bi bi-person-badge me-1"></i> Manager: <span id="projectManagerDisplay" class="fw-bold text-dark ms-1"></span></span>
                </div>
            </div>
            <div class="d-none d-md-flex gap-2 d-print-none flex-wrap justify-content-end">
                <?php if ($sc_mode): ?>
                <a href="<?= getUrl('sub_contractors/view') ?>?id=<?= $sc_id ?>" class="btn btn-outline-primary px-3 px-lg-4 shadow-sm">
                    <i class="bi bi-arrow-left"></i> Back to Sub-Contractor
                </a>
                <?php elseif ($supplier_mode): ?>
                <a href="<?= getUrl('suppliers/view') ?>?id=<?= $view_supplier_id ?>" class="btn btn-outline-primary px-3 px-lg-4 shadow-sm">
                    <i class="bi bi-arrow-left"></i> Back to Supplier
                </a>
                <?php else: ?>
                <a href="<?= getUrl('projects') ?>" class="btn btn-outline-primary px-3 px-lg-4 shadow-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <a href="projects?edit_id=<?= $project_id ?>" class="btn btn-primary px-3 px-lg-4 shadow-sm">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <?php endif; ?>
                <button id="globalPrintBtn" onclick="smartPrint()" class="btn btn-outline-primary px-3 px-lg-4 shadow-sm">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
            <div class="d-flex d-md-none d-print-none w-100">
                <div class="dropdown w-100">
                    <button class="btn btn-primary dropdown-toggle rounded-pill w-100 shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear-fill me-1"></i> Actions
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 w-100 text-center text-md-start">
                        <?php if (!$restricted_mode): ?>
                        <li><button class="dropdown-item py-2" onclick="$('#editProjectBtn').click()"><i class="bi bi-pencil me-2 text-primary"></i> Edit Project</button></li>
                        <?php endif; ?>
                        <li><button class="dropdown-item py-2" onclick="smartPrint()"><i class="bi bi-printer me-2 text-info"></i> Print Report</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php if ($sc_mode): ?>
                        <li><a class="dropdown-item py-2" href="<?= getUrl('sub_contractors/view') ?>?id=<?= $sc_id ?>"><i class="bi bi-arrow-left me-2"></i> Back to Sub-Contractor</a></li>
                        <?php elseif ($supplier_mode): ?>
                        <li><a class="dropdown-item py-2" href="<?= getUrl('suppliers/view') ?>?id=<?= $view_supplier_id ?>"><i class="bi bi-arrow-left me-2"></i> Back to Supplier</a></li>
                        <?php else: ?>
                        <li><a class="dropdown-item py-2" href="<?= getUrl('projects') ?>"><i class="bi bi-list me-2"></i> All Projects</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <?php if ($sc_mode): ?>
        <!-- SC Mode Context Banner -->
        <div class="alert border-start border-4 border-info d-flex flex-wrap align-items-center gap-2 mb-3 d-print-none px-3 py-2" style="background:#e8f4fd; border-radius:8px;">
            <i class="bi bi-person-workspace fs-5 text-info flex-shrink-0"></i>
            <div class="flex-grow-1 small">
                <span class="badge bg-info text-dark me-1">SC View</span>
                Showing sections relevant to <strong><?= htmlspecialchars($sc_name) ?></strong>
            </div>
            <a href="<?= getUrl('sub_contractors/view') ?>?id=<?= $sc_id ?>" class="btn btn-sm btn-outline-info text-nowrap">
                <i class="bi bi-arrow-left me-1"></i>Back to Sub-Contractor
            </a>
        </div>
        <?php elseif ($supplier_mode): ?>
        <!-- Supplier Mode Context Banner -->
        <div class="alert border-start border-4 border-warning d-flex flex-wrap align-items-center gap-2 mb-3 d-print-none px-3 py-2" style="background:#fffbea; border-radius:8px;">
            <i class="bi bi-shop fs-5 text-warning flex-shrink-0"></i>
            <div class="flex-grow-1 small">
                <span class="badge bg-warning text-dark me-1">Supplier View</span>
                Showing sections relevant to <strong><?= htmlspecialchars($supplier_view_name) ?></strong>
            </div>
            <a href="<?= getUrl('suppliers/view') ?>?id=<?= $view_supplier_id ?>" class="btn btn-sm btn-outline-warning text-nowrap">
                <i class="bi bi-arrow-left me-1"></i>Back to Supplier
            </a>
        </div>
        <?php endif; ?>

        <!-- Project Workspace Tabs -->
        <div class="card shadow-sm border-0 mb-4 workspace-card-main" style="border-radius: 12px;">
            <div class="card-header bg-white border-bottom p-0 d-print-none overflow-auto scrollbar-hidden">
                    <ul class="nav nav-tabs border-0 flex-nowrap d-print-none text-nowrap" id="projectWorkspaceTabs" role="tablist">

<?php if ($restricted_mode): ?>
                        <!-- Restricted Mode — SC or Supplier view -->
<?php if ($supplier_mode): ?>
                        <!-- Supplier view: Purchase Orders first (no Scope tab) -->
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active px-4 py-3 text-nowrap" id="supplier-purchases-tab" data-bs-toggle="tab" data-bs-target="#purchases" type="button">
                                <i class="bi bi-bag"></i> Purchase Orders
                            </button>
                        </li>
<?php else: ?>
                        <!-- SC view: keep Scope tab -->
                        <li class="nav-item dropdown">
                            <button class="nav-link active dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-compass"></i> Scope
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" onclick="openScopeTab('original')"><i class="bi bi-file-earmark-text me-2"></i> Original Scope</button></li>
                                <li><button class="dropdown-item py-2" onclick="openScopeTab('revised')"><i class="bi bi-pencil-square me-2"></i> Revised Scopes</button></li>
                                <li><button class="dropdown-item py-2" onclick="openScopeTab('variation')"><i class="bi bi-layers me-2 text-warning"></i> Variation Scope</button></li>
                                <li><button class="dropdown-item py-2" onclick="openScopeTab('additional')"><i class="bi bi-plus-square me-2 text-success"></i> Additional Scopes</button></li>
                            </ul>
                        </li>
                        <li class="d-none"><button id="trigger-scope-original" data-bs-toggle="tab" data-bs-target="#scope-original" type="button"></button></li>
                        <li class="d-none"><button id="trigger-scope-revised" data-bs-toggle="tab" data-bs-target="#scope-revised" type="button"></button></li>
                        <li class="d-none"><button id="trigger-scope-variation" data-bs-toggle="tab" data-bs-target="#scope-variation" type="button"></button></li>
                        <li class="d-none"><button id="trigger-scope-variation-history" data-bs-toggle="tab" data-bs-target="#scope-variation-history" type="button"></button></li>
                        <li class="d-none"><button id="trigger-scope-additional" data-bs-toggle="tab" data-bs-target="#scope-additional" type="button"></button></li>
<?php endif; ?>

                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-cart"></i> Sales
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" id="proj-ipc-tab" data-bs-toggle="tab" data-bs-target="#proj-ipc" type="button"><i class="bi bi-file-earmark-check me-2 text-warning"></i> IPC</button></li>
                                <li><button class="dropdown-item py-2" id="proj-ri-tab-sc" data-bs-toggle="tab" data-bs-target="#proj-received-invoices" type="button"><i class="bi bi-file-invoice-dollar me-2 text-info"></i> Bills</button></li>
                            </ul>
                        </li>

                        <li class="nav-item" role="presentation">
                            <button class="nav-link px-4 py-3" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory" type="button">
                                <i class="bi bi-box-seam"></i> Inventory
                            </button>
                        </li>

                        <li class="nav-item" role="presentation">
                            <button class="nav-link px-4 py-3 text-nowrap" id="proj-inspections-tab" data-bs-toggle="tab" data-bs-target="#proj-inspections" type="button">
                                <i class="bi bi-clipboard-check"></i> Inspections
                            </button>
                        </li>

                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3 text-nowrap" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-graph-up"></i> Reports
                            </button>
                            <ul class="dropdown-menu shadow border-0" style="min-width:200px;">
                                <li><button class="dropdown-item py-2" onclick="openMilestonesTab()"><i class="bi bi-flag me-2 text-primary"></i> Project Milestones</button></li>
                                <li><button class="dropdown-item py-2" onclick="openReportingTab()"><i class="bi bi-pencil-square me-2 text-info"></i> Reporting</button></li>
                            </ul>
                        </li>
                        <li class="d-none"><button id="trigger-milestones" data-bs-toggle="tab" data-bs-target="#milestones" type="button"></button></li>
                        <li class="d-none"><button id="trigger-reporting" data-bs-toggle="tab" data-bs-target="#reporting" type="button"></button></li>
                        <li class="d-none"><button id="trigger-performance" data-bs-toggle="tab" data-bs-target="#performance" type="button"></button></li>

                        <li class="nav-item" role="presentation">
                            <button class="nav-link px-4 py-3 text-nowrap" id="sc-payments-tab" data-bs-toggle="tab" data-bs-target="#sc-payments" type="button">
                                <i class="bi bi-cash-stack"></i> Payments
                            </button>
                        </li>

<?php else: ?>
                        <!-- Full Mode — all tabs -->
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active px-4 py-3" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button">
                                <i class="bi bi-speedometer2"></i> Overview
                            </button>
                        </li>

                        <!-- Scope -->
                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-compass"></i> Scope
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" onclick="openScopeTab('original')"><i class="bi bi-file-earmark-text me-2"></i> Original Scope</button></li>
                                <li><button class="dropdown-item py-2" onclick="openScopeTab('revised')"><i class="bi bi-pencil-square me-2"></i> Revised Scopes</button></li>
                                <li><button class="dropdown-item py-2" onclick="openScopeTab('variation')"><i class="bi bi-layers me-2 text-warning"></i> Variation Scope</button></li>
                                <li><button class="dropdown-item py-2" onclick="openScopeTab('additional')"><i class="bi bi-plus-square me-2 text-success"></i> Additional Scopes</button></li>
                            </ul>
                        </li>
                        <li class="d-none"><button id="trigger-scope-original" data-bs-toggle="tab" data-bs-target="#scope-original" type="button"></button></li>
                        <li class="d-none"><button id="trigger-scope-revised" data-bs-toggle="tab" data-bs-target="#scope-revised" type="button"></button></li>
                        <li class="d-none"><button id="trigger-scope-variation" data-bs-toggle="tab" data-bs-target="#scope-variation" type="button"></button></li>
                        <li class="d-none"><button id="trigger-scope-variation-history" data-bs-toggle="tab" data-bs-target="#scope-variation-history" type="button"></button></li>
                        <li class="d-none"><button id="trigger-scope-additional" data-bs-toggle="tab" data-bs-target="#scope-additional" type="button"></button></li>

                        <!-- Planning Group -->
                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-calendar3"></i> Planning
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" id="planning-tab" data-bs-toggle="tab" data-bs-target="#planning" type="button"><i class="bi bi-gear me-2"></i> Planning</button></li>
                                <li><button class="dropdown-item py-2" id="review-tab-trigger" data-bs-toggle="tab" data-bs-target="#review" type="button"><i class="bi bi-search me-2"></i> Review</button></li>
                                <li><button class="dropdown-item py-2" id="schedules-tab-trigger" data-bs-toggle="tab" data-bs-target="#schedules" type="button"><i class="bi bi-calendar-week me-2"></i> Schedules</button></li>
                            </ul>
                        </li>

                        <!-- Sales Group -->
                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-cart"></i> Sales
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales" type="button"><i class="bi bi-cart me-2"></i> Sales Orders</button></li>
                                <li><button class="dropdown-item py-2" id="proj-ipc-tab" data-bs-toggle="tab" data-bs-target="#proj-ipc" type="button"><i class="bi bi-file-earmark-check me-2 text-warning"></i> IPC</button></li>
                                <li><button class="dropdown-item py-2" id="invoices-tab" data-bs-toggle="tab" data-bs-target="#invoices" type="button"><i class="bi bi-receipt me-2"></i> Invoices</button></li>
                            </ul>
                        </li>

                        <!-- Procurements Group -->
                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-boxes"></i> Procurements
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" id="suppliers-tab" data-bs-toggle="tab" data-bs-target="#suppliers-project" type="button"><i class="bi bi-truck me-2"></i> Suppliers</button></li>
                                <li><button class="dropdown-item py-2" id="proc-rfq-tab" data-bs-toggle="tab" data-bs-target="#proc-rfq" type="button"><i class="bi bi-file-earmark-ruled me-2"></i> RFQ</button></li>
                                <li><button class="dropdown-item py-2" id="purchases-tab" data-bs-toggle="tab" data-bs-target="#proc-orders" type="button"><i class="bi bi-bag me-2"></i> Purchase Orders</button></li>
                                <li><button class="dropdown-item py-2" id="proc-grn-tab" data-bs-toggle="tab" data-bs-target="#proc-grn" type="button"><i class="bi bi-check2-square me-2"></i> GRN</button></li>
                                <li><button class="dropdown-item py-2" id="proj-ri-tab" data-bs-toggle="tab" data-bs-target="#proj-received-invoices" type="button"><i class="bi bi-file-invoice-dollar me-2 text-info"></i> Bills</button></li>
                                <li><button class="dropdown-item py-2" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory" type="button"><i class="bi bi-box-seam me-2"></i> Inventory</button></li>
                                <li><button class="dropdown-item py-2" id="proc-do-tab" data-bs-toggle="tab" data-bs-target="#proc-do" type="button"><i class="bi bi-file-earmark-check me-2"></i> Delivery Order</button></li>
                                <li><button class="dropdown-item py-2" id="proc-dn-tab" data-bs-toggle="tab" data-bs-target="#proc-dn" type="button"><i class="bi bi-truck-flatbed me-2"></i> Delivery Note</button></li>
                                <li><button class="dropdown-item py-2" id="proc-returns-tab" data-bs-toggle="tab" data-bs-target="#proc-returns" type="button"><i class="bi bi-arrow-return-left me-2"></i> Return Note</button></li>
                                <li><button class="dropdown-item py-2" id="proc-debit-notes-tab" data-bs-toggle="tab" data-bs-target="#proc-debit-notes" type="button"><i class="bi bi-receipt-cutoff me-2"></i> Debit Notes</button></li>
                                <li><button class="dropdown-item py-2" id="proc-materials-tab" data-bs-toggle="tab" data-bs-target="#proc-materials" type="button"><i class="bi bi-boxes me-2"></i> Materials</button></li>
                                <li><button class="dropdown-item py-2" id="proc-nip-products-tab" data-bs-toggle="tab" data-bs-target="#proc-nip-products" type="button"><i class="bi bi-gear me-2"></i> Non-inventory Products</button></li>
                                <li><button class="dropdown-item py-2" id="proj-sc-tab" data-bs-toggle="tab" data-bs-target="#proj-sub-contractors" type="button"><i class="bi bi-person-workspace me-2 text-info"></i> Sub-Contractors</button></li>
                                <li><hr class="dropdown-divider"></li>
                            </ul>
                        </li>

                        <!-- Finance Group -->
                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-cash-coin"></i> Finance
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" id="budget-tab" data-bs-toggle="tab" data-bs-target="#budget" type="button"><i class="bi bi-piggy-bank me-2"></i> Budget</button></li>
                                <li><button class="dropdown-item py-2" id="vouchers-tab" data-bs-toggle="tab" data-bs-target="#vouchers" type="button"><i class="bi bi-wallet me-2"></i> Vouchers</button></li>
                                <li><button class="dropdown-item py-2" id="expenses-tab" data-bs-toggle="tab" data-bs-target="#expenses" type="button"><i class="bi bi-credit-card me-2"></i> Expenses</button></li>
                            </ul>
                        </li>

                        <li class="nav-item" role="presentation">
                            <button class="nav-link px-4 py-3 text-nowrap" id="proj-inspections-tab" data-bs-toggle="tab" data-bs-target="#proj-inspections" type="button">
                                <i class="bi bi-clipboard-check"></i> Inspections
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link px-4 py-3 text-nowrap" id="communications-tab" data-bs-toggle="tab" data-bs-target="#communications" type="button">
                                <i class="bi bi-chat-dots"></i> Notes
                            </button>
                        </li>
                        <!-- HR Group -->
                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-people-fill"></i> HR
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" id="staff-tab" data-bs-toggle="tab" data-bs-target="#staff-project" type="button"><i class="bi bi-people me-2"></i> Project Staff</button></li>
                                <li><button class="dropdown-item py-2" id="hr-attendance-tab" data-bs-toggle="tab" data-bs-target="#hr-attendance" type="button"><i class="bi bi-calendar-check me-2"></i> Attendance</button></li>
                                <li><button class="dropdown-item py-2" id="hr-leaves-tab" data-bs-toggle="tab" data-bs-target="#hr-leaves" type="button"><i class="bi bi-calendar-x me-2"></i> Leaves</button></li>
                                <li><button class="dropdown-item py-2" id="hr-payroll-tab" data-bs-toggle="tab" data-bs-target="#hr-payroll" type="button"><i class="bi bi-cash-coin me-2"></i> Payroll</button></li>
                            </ul>
                        </li>
                        <!-- Docs Group -->
                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3" data-bs-toggle="dropdown" type="button" aria-expanded="false" id="docs-main-tab">
                                <i class="bi bi-file-earmark-text"></i> Docs
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li><button class="dropdown-item py-2" id="docs-view-tab" data-bs-toggle="tab" data-bs-target="#docs" type="button"><i class="bi bi-eye me-2"></i> Docs Library</button></li>
                                <li><button class="dropdown-item py-2" id="docs-add-tab" data-bs-toggle="tab" data-bs-target="#docs-add" type="button"><i class="bi bi-plus-circle me-2"></i> Add Doc</button></li>
                                <li><a class="dropdown-item py-2" href="<?= getUrl('new_document') ?>?project_id=<?= (int)$project_id ?>"><i class="bi bi-file-earmark-plus me-2"></i> Create Doc</a></li>
                            </ul>
                        </li>
                        <!-- Reports Group -->
                        <li class="nav-item dropdown">
                            <button class="nav-link dropdown-toggle px-4 py-3 text-nowrap" id="reports-main-tab" data-bs-toggle="dropdown" type="button" aria-expanded="false">
                                <i class="bi bi-graph-up"></i> Reports
                            </button>
                            <ul class="dropdown-menu shadow border-0" style="min-width: 220px;">
                                <li class="dropdown-header text-uppercase small fw-bold text-primary opacity-75">Project Progress Report</li>
                                <li><button class="dropdown-item py-2 ps-4" onclick="openMilestonesTab()"><i class="bi bi-flag me-2 text-primary"></i> Project Milestones</button></li>
                                <li><button class="dropdown-item py-2 ps-4" onclick="openReportingTab()"><i class="bi bi-pencil-square me-2 text-info"></i> Reporting</button></li>
                                <li><button class="dropdown-item py-2 ps-4" onclick="openPerformanceTab()"><i class="bi bi-speedometer2 me-2 text-success"></i> Reports</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li class="dropdown-header text-uppercase small fw-bold text-info opacity-75">Financial & Budget</li>
                                <li><button class="dropdown-item py-2" onclick="generateFinancialReport()"><i class="bi bi-file-earmark-bar-graph me-2 text-info"></i> Financial Summary</button></li>
                                <li><button class="dropdown-item py-2" onclick="generateBudgetReport()"><i class="bi bi-graph-up-arrow me-2 text-warning"></i> Budget Analysis</button></li>
                            </ul>
                        </li>
                        <!-- Hidden tab triggers inside the nav so Bootstrap can resolve tab-content -->
                        <li class="d-none"><button id="trigger-milestones" data-bs-toggle="tab" data-bs-target="#milestones" type="button"></button></li>
                        <li class="d-none"><button id="trigger-reporting" data-bs-toggle="tab" data-bs-target="#reporting" type="button"></button></li>
                        <li class="d-none"><button id="trigger-performance" data-bs-toggle="tab" data-bs-target="#performance" type="button"></button></li>
<?php endif; ?>
                    </ul>
            </div>
            <div class="card-body p-0">
                <div class="tab-content border-top" id="projectWorkspaceContent">
                    <!-- Overview Tab -->
                    <div class="tab-pane fade <?= !$restricted_mode ? 'show active' : '' ?> p-4" id="overview" role="tabpanel">
                        <h5 class="fw-bold mb-4 d-print-none"><i class="bi bi-speedometer2 me-2 text-primary"></i>Project Activities Summary</h5>
                        <div class="row g-2 g-md-4 d-print-none row-cols-2 row-cols-md-3 row-cols-lg-5">
                            <div class="col">
                                <div class="card border-0 shadow-sm h-100 bg-white" style="border-radius: 12px; border: 1px solid rgba(13, 110, 253, 0.1) !important;">
                                    <div class="card-body p-2 p-md-4 text-center">
                                        <div class="stats-icon bg-primary bg-opacity-10 text-primary mx-auto mb-2 mb-md-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 1.1rem; border-radius: 10px;">
                                            <i class="bi bi-cart"></i>
                                        </div>
                                        <h6 class="text-muted small fw-bold mb-1" style="font-size: 0.75rem;">Sales</h6>
                                        <h4 class="fw-bold mb-0 text-primary" id="countSalesOrders" style="font-size: 1.1rem;">0</h4>
                                        <button class="btn btn-sm btn-link text-primary mt-1 p-0 text-decoration-none small" onclick="$('#sales-tab').tab('show')">View</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm h-100 bg-white" style="border-radius: 12px; border: 1px solid rgba(25, 135, 84, 0.1) !important;">
                                    <div class="card-body p-2 p-md-4 text-center">
                                        <div class="stats-icon bg-success bg-opacity-10 text-success mx-auto mb-2 mb-md-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 1.1rem; border-radius: 10px;">
                                            <i class="bi bi-receipt"></i>
                                        </div>
                                        <h6 class="text-muted small fw-bold mb-1" style="font-size: 0.75rem;">Invoices</h6>
                                        <h4 class="fw-bold mb-0 text-success" id="countInvoices" style="font-size: 1.1rem;">0</h4>
                                        <button class="btn btn-sm btn-link text-success mt-1 p-0 text-decoration-none small" onclick="$('#invoices-tab').tab('show')">View</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm h-100 bg-white" style="border-radius: 12px; border: 1px solid rgba(255, 193, 7, 0.1) !important;">
                                    <div class="card-body p-2 p-md-4 text-center">
                                        <div class="stats-icon bg-warning bg-opacity-10 text-warning mx-auto mb-2 mb-md-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 1.1rem; border-radius: 10px;">
                                            <i class="bi bi-bag"></i>
                                        </div>
                                        <h6 class="text-muted small fw-bold mb-1" style="font-size: 0.75rem;">Purchases</h6>
                                        <h4 class="fw-bold mb-0 text-warning" id="countPurchases" style="font-size: 1.1rem;">0</h4>
                                        <button class="btn btn-sm btn-link text-warning mt-1 p-0 text-decoration-none small" onclick="$('#purchases-tab').tab('show')">View</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm h-100 bg-white" style="border-radius: 12px; border: 1px solid rgba(220, 53, 69, 0.1) !important;">
                                    <div class="card-body p-2 p-md-4 text-center">
                                        <div class="stats-icon bg-danger bg-opacity-10 text-danger mx-auto mb-2 mb-md-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 1.1rem; border-radius: 10px;">
                                            <i class="bi bi-wallet"></i>
                                        </div>
                                        <h6 class="text-muted small fw-bold mb-1" style="font-size: 0.75rem;">Vouchers</h6>
                                        <h4 class="fw-bold mb-0 text-danger" id="countVouchers" style="font-size: 1.1rem;">0</h4>
                                        <button class="btn btn-sm btn-link text-danger mt-1 p-0 text-decoration-none small" onclick="$('#vouchers-tab').tab('show')">View</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm h-100 bg-white" style="border-radius: 12px; border: 1px solid rgba(13, 202, 240, 0.1) !important;">
                                    <div class="card-body p-2 p-md-4 text-center">
                                        <div class="stats-icon bg-info bg-opacity-10 text-info mx-auto mb-2 mb-md-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 1.1rem; border-radius: 10px;">
                                            <i class="bi bi-files"></i>
                                        </div>
                                        <h6 class="text-muted small fw-bold mb-1" style="font-size: 0.75rem;">Docs</h6>
                                        <h4 class="fw-bold mb-0 text-info" id="countDocuments" style="font-size: 1.1rem;">0</h4>
                                        <button class="btn btn-sm btn-link text-info mt-1 p-0 text-decoration-none small" onclick="$('#docs-view-tab').tab('show')">Files</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Planning Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="planning" role="tabpanel">
                        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                            <div class="card-body p-3 p-md-4">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                                    <h5 class="fw-bold mb-0 text-primary text-center text-md-start"><i class="bi bi-card-checklist me-2"></i>New Project Plan</h5>
                                    <div class="text-center text-md-end">
                                        <button class="btn btn-primary px-4 shadow-sm w-100 w-md-auto" id="savePlanBtn" onclick="saveProjectPlan()">
                                            <i class="bi bi-save me-1"></i> Save Plan
                                        </button>
                                    </div>
                                </div>
                                <hr class="opacity-10 mb-4">
                                
                                <input type="hidden" id="plan_report_id" value="">
                                
                                <!-- Project Timeline Summary -->
                                <div class="row g-2 g-md-3 mb-4 row-cols-2 row-cols-md-4">
                                    <div class="col">
                                        <div class="card planning-summary-card shadow-sm border-0">
                                            <div class="card-body p-2 p-md-3">
                                                <small class="text-uppercase fw-bold d-block mb-1">Project Start</small>
                                                <span class="fw-bold" id="summaryProjectStart">-</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="card planning-summary-card shadow-sm border-0">
                                            <div class="card-body p-2 p-md-3">
                                                <small class="text-uppercase fw-bold d-block mb-1">Project Deadline</small>
                                                <span class="fw-bold" id="summaryProjectDeadline">-</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="card planning-summary-card shadow-sm border-0">
                                            <div class="card-body p-2 p-md-3">
                                                <small class="text-uppercase fw-bold d-block mb-1">Total Project Days</small>
                                                <span class="fw-bold text-truncate d-block" id="summaryProjectDuration">0 Days</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="card planning-summary-card shadow-sm border-0">
                                            <div class="card-body p-2 p-md-3">
                                                <small class="text-uppercase fw-bold d-block mb-1 text-truncate" id="summaryPlanLabel">Remaining Days</small>
                                                <span class="fw-bold text-truncate d-block" id="summaryPlanAllocated">0 Days</span>
                                                <small class="d-block mt-0" id="summaryPlanStatus" style="font-size: 0.6rem;"></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="plan_title" class="form-label fw-bold text-muted small text-uppercase">Project Plan Title</label>
                                    <input type="text" class="form-control form-control-lg border-2" id="plan_title" placeholder="Enter Plan Title (e.g., Construction Phase 1)" style="border-radius: 8px;">
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover align-middle border" id="planningTable" style="border-radius: 8px; overflow: hidden;">
                                        <thead class="bg-light">
                                            <tr>
                                                <th class="text-center" style="width: 60px;">S/NO</th>
                                                <th class="text-center">Task Description / Phase</th>
                                                <th class="text-center" style="width: 120px;">Duration</th>
                                                <th class="text-center" style="width: 170px;">Start Date</th>
                                                <th class="text-center" style="width: 170px;">Finish Date</th>
                                                <th class="text-center" style="width: 100px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Tasks will be added here -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                 <div class="mt-3 d-print-none">
                                    <!-- Desktop View: Side by Side -->
                                    <div class="d-none d-md-flex gap-2">
                                        <button class="btn btn-outline-primary px-4 shadow-sm" id="btnAddNewMainPhase" onclick="addPlanningTaskRow()">
                                            <i class="bi bi-plus-circle me-1"></i> Add New Phase
                                        </button>
                                        <button class="btn btn-outline-secondary px-3 shadow-sm" onclick="expandCollapseAllPlanning(false)" title="Main Phases">
                                            <i class="bi bi-dash-square me-1"></i> MAIN PHASES
                                        </button>
                                        <button class="btn btn-outline-secondary px-3 shadow-sm" onclick="expandCollapseAllPlanning(true)" title="Full Details">
                                            <i class="bi bi-plus-square me-1"></i> FULL DETAILS
                                        </button>

                                    </div>
                                    
                                    <!-- Mobile View: Consolidated Actions -->
                                    <div class="d-flex d-md-none gap-2">
                                        <button class="btn btn-primary flex-fill shadow-sm" onclick="addPlanningTaskRow()">
                                            <i class="bi bi-plus-circle me-1"></i> Add Phase
                                        </button>
                                        <div class="dropdown">
                                            <button class="btn btn-blue-kabambe dropdown-toggle px-4 shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="background-color: #0d6efd; color: white;">
                                                <i class="bi bi-sliders me-1"></i> Actions
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="border-radius: 12px;">
                                                <li><button class="dropdown-item py-2" onclick="expandCollapseAllPlanning(false)"><i class="bi bi-dash-square me-2 text-secondary"></i>Main Phases</button></li>
                                                <li><button class="dropdown-item py-2" onclick="expandCollapseAllPlanning(true)"><i class="bi bi-plus-square me-2 text-secondary"></i>Full Details</button></li>
                                                <li><hr class="dropdown-divider"></li>

                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Review Tab -->
                    <div class="tab-pane fade p-4" id="review" role="tabpanel">
                        <div id="reviewContainer">
                            <div class="card border-0 shadow-sm" style="border-radius: 12px;">
                                <div class="card-header bg-white p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-3">
                                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-search me-2"></i>Review Project Plan</h5>
                                    <div class="d-flex gap-2 align-items-center">
                                        <button class="btn btn-outline-secondary btn-sm" onclick="expandCollapseAllReview(false)" title="Main Phases">
                                            <i class="bi bi-dash-square me-1"></i> MAIN PHASES
                                        </button>
                                        <button class="btn btn-outline-secondary btn-sm" onclick="expandCollapseAllReview(true)" title="Full Details">
                                            <i class="bi bi-plus-square me-1"></i> FULL DETAILS
                                        </button>
                                        <div class="vr mx-2"></div>
                                        <button class="btn btn-outline-primary btn-sm" onclick="editCurrentPlan()">
                                            <i class="bi bi-pencil me-1"></i> Edit Plan
                                        </button>
                                        <button class="btn btn-outline-danger btn-sm" onclick="deletePlan()">
                                            <i class="bi bi-trash me-1"></i> Delete Plan
                                        </button>
                                        <button class="btn btn-success btn-sm" onclick="approvePlan()">
                                            <i class="bi bi-check-circle me-1"></i> Approve & Activate
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div id="reviewPlanContent">
                                        <!-- Plan details for review loaded here -->
                                        <div class="text-center py-5 text-muted">Loading plan for review...</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Schedules Tab -->
                    <div class="tab-pane fade p-4" id="schedules" role="tabpanel">
                        <div id="activeScheduleContainer">
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-check display-1 opacity-25"></i>
                                <p class="mt-3">No approved schedule yet. Please approve the plan in the Review tab first.</p>
                            </div>
                        </div>
                    </div>

                    
                    <!-- Budget Tab -->
                       <div class="tab-pane fade p-3 p-md-4" id="budget" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0 text-center text-md-start"><i class="bi bi-piggy-bank me-2 text-primary"></i>Budget Management</h5>
                            <div class="d-flex justify-content-center justify-content-md-end w-100 w-md-auto">
                                <button class="btn btn-primary btn-sm flex-md-grow-0 px-4 shadow-sm" onclick="createBudgetItem()">
                                    <i class="bi bi-plus-circle me-1"></i> Add Budget Item
                                </button>
                            </div>
                        </div>
                        
                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT BUDGET MANAGEMENT</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <!-- Budget Filters -->
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 d-print-none" id="budgetFilterBar">
                            <select id="budgetFilterYear" class="form-select form-select-sm" style="width:100px;" onchange="loadProjectBudgetsAjax(1)">
                                <option value="all">All Years</option>
                                <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                            <select id="budgetFilterMonth" class="form-select form-select-sm" style="width:120px;" onchange="loadProjectBudgetsAjax(1)">
                                <option value="all">All Months</option>
                                <?php foreach ([1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'] as $mn => $ml): ?>
                                <option value="<?= $mn ?>"><?= $ml ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="budgetFilterType" class="form-select form-select-sm" style="width:130px;" onchange="loadProjectBudgetsAjax(1)">
                                <option value="all">All Types</option>
                                <option value="inventory">Inventory</option>
                                <option value="non_inventory">Non-Inventory</option>
                            </select>
                            <div class="d-flex align-items-center gap-1 ms-auto">
                                <span class="small text-muted">Show:</span>
                                <select id="budgetFilterPerPage" class="form-select form-select-sm" style="width:75px;" onchange="loadProjectBudgetsAjax(1)">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="all">All</option>
                                </select>
                            </div>
                        </div>
                        <div id="budgetContent">
                            <p class="text-muted">Budget management interface will be loaded here...</p>
                        </div>


                    </div>
                    
                    <!-- Expenses Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="expenses" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0 text-center text-md-start"><i class="bi bi-credit-card me-2 text-primary"></i>Expenses</h5>
                            <div class="d-flex gap-2 justify-content-center justify-content-md-end w-100 w-md-auto">
                                <button class="btn btn-outline-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="createExpense()">
                                    <i class="bi bi-plus-circle me-1"></i> Add Expense
                                </button>
                            </div>
                        </div>

                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT EXPENSES REPORT</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="expensesContent">
                            <div id="expensesTable"></div>
                        </div>
                    </div>
                    
                    <!-- Invoices Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="invoices" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0 text-center text-md-start"><i class="bi bi-receipt me-2 text-primary"></i>Linked Invoices</h5>
                            <div class="d-flex gap-2 justify-content-center justify-content-md-end w-100 w-md-auto">
                                <button class="btn btn-outline-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="createInvoice()">
                                    <i class="bi bi-plus-circle me-1"></i> Create Invoice
                                </button>
                            </div>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT LINKED INVOICES</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>

                        <div id="invoicesContent">
                            <div id="invoicesTableFull"></div>
                        </div>
                    </div>

                    <!-- Received Invoices Tab (supplier_invoices linked to this project, optionally filtered by supplier) -->
                    <div class="tab-pane fade p-3 p-md-4" id="proj-received-invoices" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0 text-center text-md-start">
                                <i class="bi bi-file-invoice-dollar me-2 text-info"></i>Bills
                                <?php if ($supplier_mode): ?>
                                    <small class="text-muted fw-normal fs-6 ms-1">— <?= htmlspecialchars($supplier_view_name) ?></small>
                                <?php endif; ?>
                            </h5>
                            <div class="d-flex gap-2 justify-content-center justify-content-md-end w-100 w-md-auto">
                                <?php if ($ri_can_create): ?>
                                <a class="btn btn-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" href="<?= getUrl('received_invoices') ?>?add=1&lock_project=<?= $project_id ?>">
                                    <i class="bi bi-plus-circle me-1"></i> Record Bill
                                </a>
                                <?php endif; ?>
                                <button class="btn btn-outline-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="loadProjectReceivedInvoices()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                            </div>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT BILLS</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>

                        <div id="proj-ri-content">
                            <div class="py-5 text-center text-muted">
                                <span class="spinner-border spinner-border-sm me-2"></span> Loading...
                            </div>
                        </div>
                    </div>

                    <!-- Sales Orders Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="sales" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0 text-center text-md-start"><i class="bi bi-cart me-2 text-primary"></i>Linked Sales Orders</h5>
                            <div class="d-flex gap-2 justify-content-center justify-content-md-end w-100 w-md-auto">
                                <button class="btn btn-outline-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="createSalesOrder()">
                                    <i class="bi bi-plus-circle me-1"></i> Create Sales Order
                                </button>
                            </div>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT SALES ORDERS</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>

                        <div id="salesContent">
                            <div id="salesOrdersTableFull"></div>
                        </div>
                    </div>
                    
                    <!-- Purchase Orders Tab -->
                    <div class="tab-pane fade p-3 p-md-4<?= $supplier_mode ? ' show active' : '' ?>" id="purchases" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0 text-center text-md-start"><i class="bi bi-bag me-2 text-primary"></i>Linked Purchase Orders</h5>
                            <div class="d-flex gap-2 justify-content-center justify-content-md-end w-100 w-md-auto">
                                <button class="btn btn-outline-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="createPurchaseOrder()">
                                    <i class="bi bi-plus-circle me-1"></i> Create Purchase Order
                                </button>
                            </div>
                        </div>
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT PURCHASE ORDERS</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="purchasesContent">
                            <div id="purchasesTableFull"></div>
                        </div>
                    </div>

                    <!-- RFQ Tab -->
                    <div class="tab-pane fade p-4" id="proc-rfq" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-file-earmark-ruled me-2 text-primary"></i>Request for Quotation (RFQ)</h5>
                                <p class="text-muted small mb-0">Manage RFQs linked to this project.</p>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <button class="btn btn-outline-primary btn-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <a href="<?= getUrl('rfq_create') ?>?project=<?= $project_id ?>&back=rfq" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle me-1"></i> Create RFQ
                                </a>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="dtRFQs-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('dtRFQs','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="dtRFQs-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('dtRFQs','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT RFQs</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="procRFQContent">
                            <div id="procRFQTable"></div>
                        </div>
                    </div>

                    <!-- Goods Supply Orders Tab -->
                    <div class="tab-pane fade p-4" id="proc-orders" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Goods Supply Orders</h5>
                                <p class="text-muted small mb-0">Manage all goods supply orders linked to this project.</p>
                            </div>
                            <div>
                                <button class="btn btn-outline-primary btn-sm me-2" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm" onclick="window.location.href='<?= getUrl('purchase_order_create') ?>?project=<?= $project_id ?>&type=supply_order&back=procurement'">
                                    <i class="bi bi-plus-circle me-1"></i> Create Order
                                </button>
                            </div>
                        </div>
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT DELIVERY ORDERS</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="procOrdersContent">
                            <div id="procOrdersTable"></div>
                        </div>
                    </div>

                    <!-- Delivery Notes Tab -->
                    <div class="tab-pane fade p-4" id="proc-dn" role="tabpanel">
                        <!-- DN Section -->
                        <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-truck-flatbed me-2 text-primary"></i>Delivery Notes (DN)</h5>
                                <p class="text-muted small mb-0">Issue materials from warehouse to project suppliers.</p>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <button class="btn btn-outline-primary btn-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <a href="<?= getUrl('dn_create') ?>?project_id=<?= $project_id ?>" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle me-1"></i> New DN
                                </a>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="dtDNs-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('dtDNs','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="dtDNs-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('dtDNs','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT DELIVERY NOTES (DN)</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="procDNContent">
                            <div id="procDNTable"></div>
                        </div>
                    </div>

                    <!-- Delivery Orders Tab -->
                    <div class="tab-pane fade p-4" id="proc-do" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-2">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Delivery Orders (DO)</h5>
                                <p class="text-muted small mb-0">Create a Delivery Order first, then issue a Delivery Note against it.</p>
                            </div>
                            <div class="d-flex gap-2 justify-content-center justify-content-md-end flex-wrap align-items-center">
                                <button class="btn btn-outline-primary btn-sm shadow-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm shadow-sm" onclick="openCreateDOModal()">
                                    <i class="bi bi-plus-circle me-1"></i> Create DO
                                </button>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="dtDOs-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('dtDOs','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="dtDOs-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('dtDOs','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT DELIVERY ORDERS (DO)</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-success" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="procDOTable"></div>
                    </div>

                    <!-- Goods Received Notes Tab -->
                    <div class="tab-pane fade p-4" id="proc-grn" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-check2-square me-2 text-primary"></i>Goods Received Notes (GRN)</h5>
                                <p class="text-muted small mb-0">Record and verify goods received at the project site.</p>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <button class="btn btn-outline-primary btn-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm" onclick="window.location.href='<?= getUrl('grn_create') ?>?project_id=<?= $project_id ?>'">
                                    <i class="bi bi-plus-circle me-1"></i> Create GRN
                                </button>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="procGRNInnerTable-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('procGRNInnerTable','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="procGRNInnerTable-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('procGRNInnerTable','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT GOODS RECEIVED NOTES (GRN)</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="procGRNContent">
                            <div id="procGRNTable"></div>
                        </div>
                    </div>

                    <!-- Goods Returns Tab -->
                    <div class="tab-pane fade p-4" id="proc-returns" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-arrow-return-left me-2 text-primary"></i>Goods Return Notes (Returns)</h5>
                                <p class="text-muted small mb-0">Manage goods returned to suppliers from this project.</p>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <button class="btn btn-outline-primary btn-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createReturnModal">
                                    <i class="bi bi-plus-circle me-1"></i> Create Return
                                </button>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="procReturnsInnerTable-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('procReturnsInnerTable','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="procReturnsInnerTable-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('procReturnsInnerTable','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT GOODS RETURN NOTES</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="procReturnsContent">
                            <div id="procReturnsTable">
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-arrow-return-left" style="font-size: 3rem; opacity: 0.3;"></i>
                                    <p class="mt-3">No returns recorded yet. Click <strong>Create Return</strong> to add one.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Debit Notes Tab (project-scoped; same files as the standalone module) -->
                    <div class="tab-pane fade p-4" id="proc-debit-notes" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Debit Notes</h5>
                                <p class="text-muted small mb-0">Supplier debit notes raised for this project.</p>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <button class="btn btn-outline-primary btn-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm" onclick="createDebitNote()">
                                    <i class="bi bi-plus-circle me-1"></i> Create Debit Note
                                </button>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="procDebitNotesInnerTable-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('procDebitNotesInnerTable','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="procDebitNotesInnerTable-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('procDebitNotesInnerTable','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT DEBIT NOTES</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="procDebitNotesContent">
                            <div id="procDebitNotesTable">
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-receipt-cutoff" style="font-size: 3rem; opacity: 0.3;"></i>
                                    <p class="mt-3">No debit notes recorded yet. Click <strong>Create Debit Note</strong> to add one.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Materials Tab -->
                    <div class="tab-pane fade p-4" id="proc-materials" role="tabpanel">
                        <!-- Header -->
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-boxes me-2 text-primary"></i>Materials</h5>
                                <p class="text-muted small mb-0">Manage material components for Non-Inventory Products in this project.</p>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <button class="btn btn-primary btn-sm px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#procAddNipMaterialsModal">
                                    <i class="bi bi-plus-circle me-1"></i> Add Materials
                                </button>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="procMatTable-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('procMatTable','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="procMatTable-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('procMatTable','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>

                        <!-- Export / Print -->
                        <div class="d-flex gap-2 mb-3 d-print-none">
                            <button onclick="procExportMatPDF()" style="background:#fff;border:1px solid #dee2e6;border-radius:3px;font-size:.78rem;padding:.22rem .55rem;cursor:pointer;">
                                <i class="bi bi-file-earmark-pdf text-danger me-1"></i>Export PDF
                            </button>
                            <button onclick="window.print()" style="background:#fff;border:1px solid #dee2e6;border-radius:3px;font-size:.78rem;padding:.22rem .55rem;cursor:pointer;">
                                <i class="bi bi-printer me-1"></i>Print
                            </button>
                        </div>

                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color:#0d6efd;font-weight:800;text-transform:uppercase;margin:0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color:#000;text-transform:uppercase;">NIP MATERIALS</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width:60px;height:3px;border-radius:2px;"></div>
                        </div>

                        <!-- Materials Table -->
                        <div id="procMaterialsCard" style="display:none;">
                            <div class="table-responsive border rounded">
                                <table class="table table-hover align-middle mb-0 w-100" id="procMatTable">
                                    <thead>
                                        <tr>
                                            <th class="text-center" style="width:5%">S/NO</th>
                                            <th style="width:35%">Materials List Name</th>
                                            <th class="text-center" style="width:20%">Materials List No</th>
                                            <th class="text-center" style="width:20%">Warehouse</th>
                                            <th class="text-center pe-3 d-print-none" style="width:20%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="procMatTableBody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div id="procMaterialsEmpty" class="text-center py-5 text-muted">
                            <i class="bi bi-boxes" style="font-size:3rem;opacity:.3;"></i>
                            <p class="mt-3">No materials found for this project. Click <strong>Add Materials</strong> to get started.</p>
                        </div>
                    </div>

                    <!-- Non-inventory Products Tab -->
                    <div class="tab-pane fade p-4" id="proc-nip-products" role="tabpanel">
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height:80px;width:auto;"></div>
                            <?php endif; ?>
                            <h2 style="color:#0d6efd;font-weight:800;text-transform:uppercase;margin:0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color:#000!important;text-transform:uppercase;">NON-INVENTORY PRODUCTS</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color:#666!important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width:60px;height:3px;border-radius:2px;"></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-gear me-2 text-primary"></i>Non-inventory Products</h5>
                                <p class="text-muted small mb-0">Non-inventory products assigned to this project.</p>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <button class="btn btn-outline-primary btn-sm" onclick="projNipLoadTable()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm" onclick="projNipOpenAdd()">
                                    <i class="bi bi-plus-circle me-1"></i> Add Non-inventory Product
                                </button>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="projNipInnerTable-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('projNipInnerTable','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="projNipInnerTable-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('projNipInnerTable','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>
                        <!-- Stats -->
                        <div class="row g-3 mb-3 d-print-none" id="projNipStats"></div>
                        <!-- Filter -->
                        <div class="card bg-light border-0 mb-3 d-print-none">
                            <div class="card-body py-2 px-3">
                                <div class="row g-2 align-items-center">
                                    <div class="col-md-5">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                                            <input type="text" class="form-control border-0 bg-white" id="projNipSearch" placeholder="Search products..." oninput="projNipFilter()">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select form-select-sm" id="projNipStatusFilter" onchange="projNipFilter()">
                                            <option value="">All Status</option>
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <span class="text-muted small" id="projNipCountLabel"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Content -->
                        <div id="projNipContent">
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-gear" style="font-size:3rem;opacity:.3;"></i>
                                <p class="mt-3">Click <strong>Add Non-inventory Product</strong> or <strong>Refresh</strong> to load products.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Vouchers Tab -->
                    <div class="tab-pane fade p-4" id="vouchers" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <h5 class="fw-bold mb-0"><i class="bi bi-wallet me-2 text-primary"></i>Linked Payment Vouchers</h5>
                            <div>
                                <button class="btn btn-outline-primary btn-sm me-2" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm" onclick="createVoucher()">
                                    <i class="bi bi-plus-circle me-1"></i> Create Voucher
                                </button>
                            </div>
                        </div>

                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT PAYMENT VOUCHERS</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="vouchersContent">
                            <div id="vouchersTableFull"></div>
                        </div>
                    </div>

                    <!-- Inventory Tab -->
                    <div class="tab-pane fade p-4" id="inventory" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <h5 class="fw-bold mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>Project Materials & Stock</h5>
                            <div>
                                <button class="btn btn-outline-primary btn-sm me-2" onclick="loadProjectInventory(true)">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh Data
                                </button>
                                <a href="<?= getUrl('stock_adjustments') ?>?project_id=<?= $project_id ?>" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle me-1"></i> New Adjustment
                                </a>
                            </div>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT MATERIALS &amp; STOCK</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>

                        <!-- Warehouses Table — click View Details to see stock per warehouse -->
                        <div id="projectWarehousesSummaryTable"></div>
                    </div>

                    <!-- Suppliers Tab -->
                    <div class="tab-pane fade p-4" id="suppliers-project" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0 text-center text-md-start"><i class="bi bi-truck me-2 text-primary"></i>Project Suppliers</h5>
                            <div class="d-flex gap-2 justify-content-center justify-content-md-end w-100 w-md-auto flex-wrap align-items-center">
                                <button class="btn btn-outline-primary btn-sm flex-fill flex-md-grow-0 shadow-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <a href="<?= getUrl('suppliers') ?>?action=add&project=<?= $project_id ?>&back=suppliers" class="btn btn-primary btn-sm flex-fill flex-md-grow-0 shadow-sm">
                                    <i class="bi bi-plus-circle me-1"></i> Register
                                </a>
                                <div class="btn-group shadow-sm" role="group">
                                    <button type="button" class="btn btn-primary btn-sm text-white" id="projSuppliersTable-btn-tbl" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('projSuppliersTable','table')" title="Table View"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-light btn-sm border" id="projSuppliersTable-btn-crd" onclick="window.bmsMobileCards&&window.bmsMobileCards.toggleAuto('projSuppliersTable','card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                                </div>
                            </div>
                        </div>
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT SUPPLIERS</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="suppliersProjectContent">
                            <div id="projectSuppliersTable"></div>
                        </div>
                    </div>

                    <!-- Sub-Contractors Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="proj-sub-contractors" role="tabpanel">

                        <!-- Print Header -->
                        <div class="text-center mb-4 d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height:80px;width:auto;"></div>
                            <?php endif; ?>
                            <h2 style="color:#0d6efd;font-weight:800;text-transform:uppercase;margin:0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="text-transform:uppercase;">PROJECT SUB-CONTRACTORS</h3>
                            <h6 class="text-muted fw-bold mb-0">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <p class="text-muted small">Generated: <?= date('d M Y, H:i') ?></p>
                            <div class="mx-auto bg-primary" style="width:60px;height:3px;border-radius:2px;"></div>
                        </div>

                        <!-- Header -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0"><i class="bi bi-person-workspace text-info me-2"></i>Project Sub-Contractors</h5>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-outline-primary btn-sm shadow-sm" onclick="projScLoadTable()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                                <button class="btn btn-outline-warning btn-sm shadow-sm" onclick="openAssignExistingScModal()"><i class="bi bi-link-45deg me-1"></i> Assign Existing</button>
                                <a href="<?= getUrl('sub_contractors') ?>?action=add&project=<?= $project_id ?>&back=sub-contractors" class="btn btn-primary btn-sm shadow-sm"><i class="bi bi-plus-circle me-1"></i> Add New</a>
                            </div>
                        </div>

                        <!-- Stat Cards -->
                        <div class="row mb-4 g-3" id="proj-sc-stats">
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-people"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Total</p><h4 class="mb-0 fw-bold" id="proj-sc-total">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(25,135,84,0.1);border-radius:10px;color:#198754;"><i class="bi bi-check-circle"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Active</p><h4 class="mb-0 fw-bold" id="proj-sc-active">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(255,193,7,0.1);border-radius:10px;color:#ffc107;"><i class="bi bi-exclamation-triangle"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Suspended</p><h4 class="mb-0 fw-bold" id="proj-sc-suspended">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(220,53,69,0.1);border-radius:10px;color:#dc3545;"><i class="bi bi-x-circle"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Blacklisted</p><h4 class="mb-0 fw-bold" id="proj-sc-blacklisted">0</h4></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="card shadow-sm border-0 mb-4 d-print-none">
                            <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-funnel"></i> Filters</h6></div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-bold">Status</label>
                                        <select class="form-select" id="projScStatusFilter">
                                            <option value="">All Status</option>
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                            <option value="suspended">Suspended</option>
                                            <option value="blacklisted">Blacklisted</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-bold">Category</label>
                                        <select class="form-select" id="projScCategoryFilter">
                                            <option value="">All Categories</option>
                                            <?php foreach ($supplier_categories as $cat): ?>
                                            <option value="<?= safe_output($cat['category_name']) ?>"><?= safe_output($cat['category_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-bold">Country</label>
                                        <input type="text" class="form-control" id="projScCountryFilter" placeholder="Filter by country">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-bold">District / City</label>
                                        <input type="text" class="form-control" id="projScCityFilter" placeholder="Filter by city">
                                    </div>
                                    <div class="col-12 d-flex justify-content-end gap-2">
                                        <button class="btn btn-outline-secondary btn-sm" onclick="projScClearFilters()">Clear</button>
                                        <button class="btn btn-primary btn-sm" onclick="projScApplyFilters()">Apply</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Table -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table id="proj-sc-table" class="table table-striped table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-center">S/NO</th>
                                                <th>Code</th>
                                                <th>Name</th>
                                                <th>Contact Info</th>
                                                <th>Address</th>
                                                <th>Category</th>
                                                <th>Status</th>
                                                <th class="d-none">Location</th>
                                                <th class="d-print-none text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Print Footer -->
                        <div class="d-none d-print-block mt-4 pt-3 border-top">
                            <div class="row">
                                <div class="col-6"><p class="small text-muted mb-0">Printed by: <?= htmlspecialchars($_SESSION['username'] ?? '') ?></p></div>
                                <div class="col-6 text-end"><p class="small text-muted mb-0">Date: <?= date('d M Y, H:i') ?></p></div>
                            </div>
                        </div>
                    </div>

                    <!-- Inspections Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="proj-inspections" role="tabpanel">

                        <!-- Print Header -->
                        <div class="text-center mb-4 d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height:80px;width:auto;"></div>
                            <?php endif; ?>
                            <h2 style="color:#0d6efd;font-weight:800;text-transform:uppercase;margin:0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="text-transform:uppercase;">PROJECT INSPECTIONS</h3>
                            <h6 class="text-muted fw-bold mb-0">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <p class="text-muted small">Generated: <?= date('d M Y, H:i') ?></p>
                            <div class="mx-auto bg-primary" style="width:60px;height:3px;border-radius:2px;"></div>
                        </div>

                        <!-- Header -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0"><i class="bi bi-clipboard-check text-primary me-2"></i>Project Inspections</h5>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                                <button class="btn btn-outline-primary btn-sm shadow-sm" onclick="inspLoadTable()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                                <?php if(canCreate('projects')): ?>
                                <button class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#inspAddModal"><i class="bi bi-plus-circle me-1"></i> Add Inspection</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Stat Cards -->
                        <div class="row mb-4 g-3" id="proj-insp-stats">
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-clipboard-check"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Total</p><h4 class="mb-0 fw-bold" id="insp-total">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-check-circle"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Passed</p><h4 class="mb-0 fw-bold" id="insp-passed">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-x-circle"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Failed</p><h4 class="mb-0 fw-bold" id="insp-failed">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-arrow-repeat"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Re-inspect</p><h4 class="mb-0 fw-bold" id="insp-reinspect">0</h4></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="card shadow-sm border-0 mb-4 d-print-none">
                            <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-funnel"></i> Filters</h6></div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-bold">Result</label>
                                        <select class="form-select form-select-sm" id="inspResultFilter">
                                            <option value="">All Results</option>
                                            <option value="Pass">Pass</option>
                                            <option value="Fail">Fail</option>
                                            <option value="Conditional Pass">Conditional Pass</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-bold">Status</label>
                                        <select class="form-select form-select-sm" id="inspStatusFilter">
                                            <option value="">All Status</option>
                                            <option value="Pending">Pending</option>
                                            <option value="Completed">Completed</option>
                                            <option value="Cancelled">Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3 d-flex align-items-end gap-2">
                                        <button class="btn btn-primary btn-sm" onclick="inspApplyFilters()"><i class="bi bi-search"></i> Filter</button>
                                        <button class="btn btn-outline-secondary btn-sm" onclick="inspClearFilters()"><i class="bi bi-x"></i> Clear</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Table -->
                        <div class="card shadow-sm border-0">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" id="proj-insp-table">
                                        <thead class="table-light border-bottom border-2">
                                            <tr>
                                                <th>S/NO</th>
                                                <th>Insp. No</th>
                                                <th>Date</th>
                                                <th>Type</th>
                                                <th>Milestone</th>
                                                <th>Inspector</th>
                                                <th>Location</th>
                                                <th>Result</th>
                                                <th>Status</th>
                                                <th class="d-print-none">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody><tr><td colspan="10" class="text-center text-muted py-4">Loading...</td></tr></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- IPC Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="proj-ipc" role="tabpanel">

                        <!-- Print Header -->
                        <div class="text-center mb-4 d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height:80px;width:auto;"></div>
                            <?php endif; ?>
                            <h2 style="color:#0d6efd;font-weight:800;text-transform:uppercase;margin:0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="text-transform:uppercase;">INTERIM PAYMENT CERTIFICATES</h3>
                            <h6 class="text-muted fw-bold mb-0">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <p class="text-muted small">Generated: <?= date('d M Y, H:i') ?></p>
                            <div class="mx-auto bg-primary" style="width:60px;height:3px;border-radius:2px;"></div>
                        </div>

                        <!-- Header -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 d-print-none gap-3">
                            <h5 class="fw-bold mb-0"><i class="bi bi-file-earmark-check text-primary me-2"></i>Interim Payment Certificates</h5>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-outline-primary btn-sm shadow-sm" onclick="ipcLoadTable()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                                <?php if(canCreate('projects')): ?>
                                <button class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#ipcAddModal"><i class="bi bi-plus-circle me-1"></i> Add IPC</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Stat Cards -->
                        <style>@media print { #ipc-stat-cards .ipc-stat-col { flex: 0 0 25% !important; max-width: 25% !important; } }</style>
                        <div class="row mb-4 g-3" id="ipc-stat-cards">
                            <div class="col-6 col-md-3 ipc-stat-col">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-file-earmark-check"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Total</p><h4 class="mb-0 fw-bold" id="ipc-total">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 ipc-stat-col">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-hourglass-split"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Draft</p><h4 class="mb-0 fw-bold" id="ipc-draft">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 ipc-stat-col">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-check-circle"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Approved</p><h4 class="mb-0 fw-bold" id="ipc-approved">0</h4></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 ipc-stat-col">
                                <div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;border-radius:12px;">
                                    <div class="card-body py-2 px-3 d-flex align-items-center">
                                        <div class="me-3 d-none d-sm-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(13,110,253,0.1);border-radius:10px;color:#0d6efd;"><i class="bi bi-receipt"></i></div>
                                        <div><p class="small mb-0 opacity-75 text-uppercase" style="font-size:0.65rem;">Invoiced</p><h4 class="mb-0 fw-bold" id="ipc-paid">0</h4></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Table (desktop) -->
                        <div class="card shadow-sm border-0">
                            <div class="card-body p-0">
                                <div class="table-responsive d-none d-lg-block p-2">
                                    <table class="table table-hover align-middle mb-0 w-100" id="proj-ipc-table">
                                        <thead class="table-light border-bottom border-2">
                                            <tr>
                                                <th>S/NO</th>
                                                <th>IPC No</th>
                                                <th>IPC Date</th>
                                                <th>Period</th>
                                                <th>Customer</th>
                                                <th>Sales Order</th>
                                                <th class="text-end">Net Payable (TZS)</th>
                                                <th>Status</th>
                                                <th class="d-print-none">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody><tr><td colspan="9" class="text-center text-muted py-4">Loading...</td></tr></tbody>
                                    </table>
                                </div>
                                <!-- Cards (mobile) -->
                                <div id="proj-ipc-cards" class="d-lg-none row g-2 p-2"></div>
                            </div>
                        </div>

                    </div>

                    <!-- Staff Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="staff-project" role="tabpanel">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 d-print-none gap-3">
                            <div class="text-center text-lg-start">
                                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-people me-2"></i>Project Staff & HR</h5>
                                <p class="text-muted small mb-0 mt-1">Manage employees and team members assigned to this project.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-lg-end w-100 w-lg-auto">
                                <button class="btn btn-outline-primary btn-sm px-3 shadow-sm" onclick="loadProjectDetails()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-primary btn-sm px-3 shadow-sm" onclick="openAssignStaffModal()">
                                    <i class="bi bi-person-plus me-1"></i> Assign
                                </button>
                                <a href="<?= getUrl('employees') ?>?action=new&project=<?= $project_id ?>&back=staff" class="btn btn-success btn-sm px-3 shadow-sm">
                                    <i class="bi bi-plus-lg me-1"></i> New Staff
                                </a>
                            </div>
                        </div>
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT STAFF LIST</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="staffProjectContent">
                            <div id="projectStaffTable"></div>
                        </div>
                    </div>
                    
                    <!-- HR: Attendance Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="hr-attendance" role="tabpanel">
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT ATTENDANCE REPORT</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <p class="text-muted small mb-1" id="attPrintPeriod"></p>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <!-- Screen Header -->
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-3 d-print-none gap-3">
                            <div class="text-center text-lg-start">
                                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-calendar-check me-2"></i>Project Attendance</h5>
                                <p class="text-muted small mb-0 mt-1">Mark today's attendance directly below, or pick a date range to review history.</p>
                            </div>
                        </div>
                        <!-- Stat Cards -->
                        <style>@media print { #attStatCards > div { flex: 0 0 16.666% !important; max-width: 16.666% !important; width: 16.666% !important; } }</style>
                        <div class="row g-2 mb-3" id="attStatCards">
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Present</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="attStatPresent" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Absent</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="attStatAbsent" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Late</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="attStatLate" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Half Day</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="attStatHalfDay" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">On Leave</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="attStatOnLeave" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Total Hrs</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="attStatHours" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                        </div>
                        <!-- Filters -->
                        <div class="row g-2 mb-3 d-print-none">
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-bold mb-1">Date From</label>
                                <input type="date" id="attDateFrom" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-bold mb-1">Date To</label>
                                <input type="date" id="attDateTo" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-bold mb-1">Status</label>
                                <select id="attStatusFilter" class="form-select form-select-sm">
                                    <option value="">All Status</option>
                                    <option value="present">Present</option>
                                    <option value="absent">Absent</option>
                                    <option value="late">Late</option>
                                    <option value="half_day">Half Day</option>
                                    <option value="leave">On Leave</option>
                                    <option value="holiday">Holiday</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3 d-flex align-items-end">
                                <button class="btn btn-primary btn-sm w-100" onclick="loadProjectAttendance()"><i class="bi bi-search me-1"></i> Filter</button>
                            </div>
                        </div>
                        <!-- Table -->
                        <div id="hrAttendanceContent">
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-check display-4 opacity-25"></i>
                                <p class="mt-2 small">Select a date range and click Filter to load attendance records.</p>
                            </div>
                        </div>
                    </div>

                    <!-- HR: Leaves Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="hr-leaves" role="tabpanel">
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT LEAVE REPORT</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <p class="text-muted small mb-1" id="leavePrintPeriod"></p>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <!-- Screen Header -->
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-3 d-print-none gap-3">
                            <div class="text-center text-lg-start">
                                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-calendar-x me-2"></i>Project Leaves</h5>
                                <p class="text-muted small mb-0 mt-1">Manage leave applications for staff assigned to this project.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-lg-end w-100 w-lg-auto">
                                <button class="btn btn-outline-primary btn-sm px-3 shadow-sm" onclick="loadProjectLeaves()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                                <button class="btn btn-outline-secondary btn-sm px-3 shadow-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
                                <button class="btn btn-primary btn-sm px-3 shadow-sm" onclick="openApplyLeaveModal()"><i class="bi bi-plus-lg me-1"></i> Apply Leave</button>
                            </div>
                        </div>
                        <!-- Stat Cards -->
                        <style>@media print { #leaveStatCards > div { flex: 0 0 16.666% !important; max-width: 16.666% !important; width: 16.666% !important; } }</style>
                        <div class="row g-2 mb-3" id="leaveStatCards">
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Total</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="lvStatTotal" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Pending</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="lvStatPending" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Approved</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="lvStatApproved" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Rejected</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="lvStatRejected" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Cancelled</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="lvStatCancelled" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Total Days</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="lvStatDays" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                        </div>
                        <!-- Filters -->
                        <div class="row g-2 mb-3 d-print-none">
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-bold mb-1">Date From</label>
                                <input type="date" id="lvDateFrom" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-bold mb-1">Date To</label>
                                <input type="date" id="lvDateTo" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-bold mb-1">Status</label>
                                <select id="lvStatusFilter" class="form-select form-select-sm">
                                    <option value="">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="taken">Taken</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-bold mb-1">Leave Type</label>
                                <select id="lvTypeFilter" class="form-select form-select-sm">
                                    <option value="">All Types</option>
                                    <option value="annual">Annual Leave</option>
                                    <option value="sick">Sick Leave</option>
                                    <option value="maternity">Maternity Leave</option>
                                    <option value="paternity">Paternity Leave</option>
                                    <option value="study">Study Leave</option>
                                    <option value="unpaid">Unpaid Leave</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3 d-flex align-items-end">
                                <button class="btn btn-primary btn-sm w-100" onclick="loadProjectLeaves()"><i class="bi bi-search me-1"></i> Filter</button>
                            </div>
                        </div>
                        <!-- Table -->
                        <div id="hrLeavesContent">
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-x display-4 opacity-25"></i>
                                <p class="mt-2 small">Click Refresh or Filter to load leave records.</p>
                            </div>
                        </div>
                    </div>

                    <!-- HR: Payroll Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="hr-payroll" role="tabpanel">
                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT PAYROLL REGISTRY</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <p class="text-muted small mb-1" id="payrollPrintPeriod"></p>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <!-- Screen Header -->
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-3 d-print-none gap-3">
                            <div class="text-center text-lg-start">
                                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-cash-coin me-2"></i>Project Payroll</h5>
                                <p class="text-muted small mb-0 mt-1">Process and manage payroll for all staff assigned to this project.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-lg-end w-100 w-lg-auto">
                                <button class="btn btn-outline-primary btn-sm px-3 shadow-sm" onclick="loadProjectPayroll()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                                <button class="btn btn-outline-secondary btn-sm px-3 shadow-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
                                <button class="btn btn-primary btn-sm px-3 shadow-sm" onclick="openProcessPayrollModal()"><i class="bi bi-gear me-1"></i> Process Payroll</button>
                            </div>
                        </div>
                        <!-- Stat Cards -->
                        <div class="row g-2 mb-3" id="payrollStatCards">
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Active Staff</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="prStatActive" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Paid</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="prStatPaid" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Pending</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="prStatPending" style="color:#0f5132;">0</h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm text-center p-2 h-100" style="border-radius:10px; background:#d1e7dd;">
                                    <small class="text-uppercase fw-bold" style="font-size:0.6rem; color:#0f5132;">Total Payout</small>
                                    <h5 class="fw-bold mb-0 mt-1" id="prStatTotal" style="color:#0f5132; font-size:0.85rem !important;">0 TZS</h5>
                                </div>
                            </div>
                        </div>
                        <!-- Filters -->
                        <div class="row g-2 mb-3 d-print-none">
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-bold mb-1">Payroll Period</label>
                                <input type="month" id="prPeriodFilter" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-bold mb-1">Status</label>
                                <select id="prStatusFilter" class="form-select form-select-sm">
                                    <option value="">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="paid">Paid</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3 d-flex align-items-end">
                                <button class="btn btn-primary btn-sm w-100" onclick="loadProjectPayroll()"><i class="bi bi-search me-1"></i> Filter</button>
                            </div>
                        </div>
                        <!-- Table -->
                        <div id="hrPayrollContent">
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-cash-coin display-4 opacity-25"></i>
                                <p class="mt-2 small">Select a period and click Filter to load payroll records.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Communications Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="communications" role="tabpanel">
                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT NOTES</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3 d-print-none">
                            <h5 class="fw-bold mb-0 text-center text-md-start"><i class="bi bi-chat-dots me-2 text-primary"></i>Project Notes</h5>
                            <button class="btn btn-primary btn-sm w-100 w-md-auto shadow-sm" onclick="addNote()">
                                <i class="bi bi-plus-circle me-1"></i> Add Note
                            </button>
                        </div>
                        <div id="communicationsContent">
                            <div id="projectNotesList"></div>
                        </div>
                    </div>
                    
                    
                    <!-- Docs Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="docs" role="tabpanel">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 d-print-none gap-2">
                            <h5 class="fw-bold mb-0 text-center text-md-start"><i class="bi bi-files me-2 text-primary"></i>Project Documents</h5>
                            <small class="text-muted text-center text-md-end">Project attachments and contracts</small>
                        </div>
                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT DOCUMENTS LIBRARY</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div id="projectDocsList" class="row g-3">
                            <div class="col-12 text-center py-5">
                                <div class="spinner-border text-primary" role="status"></div>
                                <p class="mt-2 text-muted">Loading documents...</p>
                            </div>
                        </div>
                    </div>

                    <!-- Add Document Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="docs-add" role="tabpanel">
                        <div class="mb-4">
                            <h5 class="fw-bold mb-0 text-center text-md-start text-primary"><i class="bi bi-cloud-upload me-2"></i>Upload New Project Document</h5>
                        </div>
                        <div class="card border-0 shadow-sm bg-light-soft" style="border-radius: 15px;">
                            <div class="card-body p-4">
                                <form id="projectDocUploadForm" enctype="multipart/form-data">
                                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="doc_upload_name" class="form-label fw-bold small">Document Title</label>
                                            <input type="text" class="form-control" name="document_name" id="doc_upload_name" required placeholder="e.g. Project Scope Document">
                                            <div class="form-text small text-muted">Title will auto-fill from filename if left blank.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="source_select" class="form-label fw-bold small">Source / Category</label>
                                            <select class="form-select" name="source_select" id="source_select" required>
                                                <option value="" selected disabled>Select Source/Category</option>
                                                <option value="Project Asset">Project Asset</option>
                                                <option value="Payment Voucher">Payment Voucher</option>
                                                <option value="Budget Allocation">Budget Allocation</option>
                                                <option value="Invoice / Sales">Invoice / Sales</option>
                                                <option value="Purchase Order">Purchase Order</option>
                                                <option value="Other">Other (Write Manually)</option>
                                            </select>
                                            <input type="text" class="form-control mt-2" name="source_manual" id="source_manual" style="display: none;" placeholder="Enter custom source...">
                                            <input type="hidden" name="source" id="final_source">
                                        </div>
                                        <div class="col-12">
                                            <label for="doc_upload_file" class="form-label fw-bold small">File Selection</label>
                                            <div class="input-group">
                                                <input type="file" class="form-control" name="document_file" id="doc_upload_file" required>
                                                <label class="input-group-text bg-white"><i class="bi bi-file-earmark-arrow-up"></i></label>
                                            </div>
                                            <div class="form-text small">Automatically records Type & Date Added.</div>
                                        </div>
                                        <div class="col-12 text-end mt-4">
                                            <button type="reset" class="btn btn-light px-4 me-2">Clear Form</button>
                                            <button type="submit" class="btn btn-primary px-5 shadow-sm">
                                                <i class="bi bi-cloud-arrow-up me-1"></i> Upload Document
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Reports Tab (Legacy/Settings Overview) -->
                    <div class="tab-pane fade p-4" id="reports" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0"><i class="bi bi-graph-up me-2 text-primary"></i>Project Reporting Center</h5>
                        </div>
                        <div class="row g-4">
                            <!-- Existing report cards... -->
                            <!-- Reference: Financial, Progress (now split), Budget -->
                        </div>
                    </div>

                    <!-- Milestones Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="milestones" role="tabpanel">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 d-print-none gap-3">
                            <div class="text-center text-lg-start">
                                <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-flag me-2"></i> Project Milestones</h5>
                                <p class="text-muted small mb-0">Define the scope and weighted importance of each milestone.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-lg-end w-100 w-lg-auto">
                                <div class="btn-group shadow-sm flex-fill flex-lg-grow-0">
                                    <button class="btn btn-sm btn-outline-secondary px-3 btn-milestone-filter" data-type="milestones" data-limit="0" onclick="filterMilestoneLevels('milestones', 0, this)">
                                        <i class="bi bi-list-task"></i> <span class="d-none d-sm-inline ms-1">Main</span>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary px-3 btn-milestone-filter active" data-type="milestones" data-limit="all" onclick="filterMilestoneLevels('milestones', 'all', this)">
                                        <i class="bi bi-list-nested"></i> <span class="d-none d-sm-inline ms-1">All</span>
                                    </button>
                                </div>
                                <button class="btn btn-primary btn-sm px-3 shadow-sm flex-fill flex-lg-grow-0" onclick="addNewMilestoneRow()">
                                    <i class="bi bi-plus-lg me-1"></i> Add Milestone
                                </button>
                            </div>
                        </div>

                        <!-- Print Header -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;"></div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT MILESTONES REPORT</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>

                        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px;">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="milestonesTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4 text-center" style="width: 80px;">S/NO</th>
                                            <th class="text-center">Description</th>
                                            <th class="text-center" style="width: 120px;">Unit</th>
                                            <th class="text-center" style="width: 150px;">Scope (Qty)</th>
                                            <th class="text-center" style="width: 150px;">Weight (%)</th>
                                            <th class="text-end pe-4 d-print-none" style="width: 100px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <tfoot class="bg-light fw-bold">
                                        <tr>
                                            <td colspan="4" class="text-end ps-4 text-dark py-3">PROJECT TOTAL WEIGHT (MAIN MILESTONES):</td>
                                            <td id="totalMilestoneWeight" class="text-center text-dark fs-5 py-3" style="font-weight: 800 !important;">0.00%</td>
                                            <td class="d-print-none"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <div class="mt-4 text-end d-print-none">
                            <button class="btn btn-success px-5" id="btnSaveMilestones" onclick="saveMilestones()">
                                <i class="bi bi-check-circle me-1"></i> Save All Milestones
                            </button>
                        </div>
                    </div>

                    <!-- Scope Tab (Original Scope) -->
                    <div class="tab-pane fade <?= ($restricted_mode && !$supplier_mode) ? 'show active' : '' ?> p-3 p-md-4" id="scope-original" role="tabpanel">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 d-print-none gap-3">
                            <div class="text-center text-lg-start">
                                <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-file-earmark-text me-2"></i> Original Scope</h5>
                                <p class="text-muted small mb-0">Original items and quantities defined at the start of the project.</p>
                            </div>
                            <div class="d-flex flex-wrap justify-content-center justify-content-lg-end gap-2">
                                <div id="signedDocContainer-original" class="d-flex align-items-center gap-2 p-1 px-3 bg-light rounded-pill border border-info shadow-sm d-none">
                                    <small class="text-primary fw-bold"><i class="bi bi-file-earmark-check me-1"></i> Doc:</small>
                                    <a href="#" id="signedDocLink-original" target="_blank" class="text-decoration-none small text-truncate" style="max-width: 100px;">...</a>
                                    <button class="btn btn-sm btn-link text-danger p-0 ms-1" onclick="deleteScopeDoc('original')"><i class="bi bi-x-circle"></i></button>
                                </div>
                                <button class="btn btn-outline-info btn-sm px-3" id="attachDocBtn-original" onclick="triggerScopeDocUpload('original')">
                                    <i class="bi bi-paperclip me-1"></i> Attach Signed
                                </button>
                                <button class="btn btn-primary btn-sm px-3" id="btnAddOriginalScopeItem" onclick="addNewScopeRow('original')">
                                    <i class="bi bi-plus-lg me-1"></i> Add Item
                                </button>
                            </div>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">ORIGINAL PROJECT SCOPE</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>

                        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px;">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 scope-table" id="originalScopeTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4" style="width: 80px;">S/NO</th>
                                            <th class="text-center">DESCRIPTION</th>
                                            <th style="width: 100px;">UNIT</th>
                                            <th style="width: 120px;">QUANTITY</th>
                                            <th style="width: 130px;">PRICE</th>
                                            <th style="width: 100px;">TAX (%)</th>

                                            <th style="width: 160px;">TOTAL AMOUNT</th>
                                            <th class="text-end pe-4 d-print-none" style="width: 80px;">ACTION</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Rows added dynamically -->
                                    </tbody>
                                    <tfoot class="bg-light fw-bold">
                                        <tr>
                                            <td colspan="6" class="text-end ps-4">TOTAL PROJECT SUM:</td>
                                            <td id="originalScopeTotal" class="text-primary fs-5 pe-4">0.00</td>
                                            <td class="d-print-none"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>


                        <div class="mt-4 text-end d-print-none">
                            <button class="btn btn-primary px-5" id="btnSaveOriginalScope" onclick="saveScope('original')">
                                <i class="bi bi-save me-1"></i> Save Original Scope
                            </button>
                        </div>
                    </div>

                    <!-- Revised Scopes Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="scope-revised" role="tabpanel">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 d-print-none gap-3">
                            <div class="text-center text-lg-start">
                                <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-pencil-square me-2"></i> Revised Scopes</h5>
                                <p class="text-muted small mb-0">Project scope after various revisions and updates.</p>
                            </div>
                            <div class="d-flex flex-wrap justify-content-center justify-content-lg-end gap-2">
                                <div id="signedDocContainer-revised" class="d-flex align-items-center gap-2 p-1 px-3 bg-light rounded-pill border border-info shadow-sm d-none">
                                    <small class="text-primary fw-bold"><i class="bi bi-file-earmark-check me-1"></i> Doc:</small>
                                    <a href="#" id="signedDocLink-revised" target="_blank" class="text-decoration-none small text-truncate" style="max-width: 100px;">...</a>
                                    <button class="btn btn-sm btn-link text-danger p-0 ms-1" onclick="deleteScopeDoc('revised')"><i class="bi bi-x-circle"></i></button>
                                </div>
                                <button class="btn btn-outline-info btn-sm px-3" id="attachDocBtn-revised" onclick="triggerScopeDocUpload('revised')">
                                    <i class="bi bi-paperclip me-1"></i> Attach Signed
                                </button>
                                <button class="btn btn-primary btn-sm px-3" onclick="addNewScopeRow('revised')">
                                    <i class="bi bi-plus-lg me-1"></i> Add Revised
                                </button>
                            </div>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">REVISED PROJECT SCOPE</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px;">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 scope-table" id="revisedScopeTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4" style="width: 80px;">S/NO</th>
                                            <th class="text-center">DESCRIPTION</th>
                                            <th style="width: 100px;">UNIT</th>
                                            <th style="width: 120px;">QUANTITY</th>
                                            <th style="width: 130px;">PRICE</th>
                                            <th style="width: 100px;">TAX (%)</th>

                                            <th style="width: 160px;">TOTAL AMOUNT</th>
                                            <th class="text-end pe-4 d-print-none" style="width: 80px;">ACTION</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                    <tfoot class="bg-light fw-bold">
                                        <tr>
                                            <td colspan="6" class="text-end ps-4">TOTAL REVISED SUM:</td>
                                            <td id="revisedScopeTotal" class="text-primary fs-5 pe-4">0.00</td>
                                            <td class="d-print-none"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>


                        <div class="mt-4 text-end d-print-none">
                            <button class="btn btn-info px-5 text-white" onclick="saveScope('revised')">
                                <i class="bi bi-save me-1"></i> Save Revised Scope
                            </button>
                        </div>
                    </div>

                    <!-- Variation Scope Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="scope-variation" role="tabpanel">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 d-print-none gap-3">
                            <div class="text-center text-lg-start">
                                <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-layers me-2"></i> Variation Scopes</h5>
                                <p class="text-muted small mb-0">Track variation orders and addendums against the original contract.</p>
                            </div>
                            <div class="d-flex flex-wrap justify-content-center justify-content-lg-end gap-2 align-items-center">
                                <div class="bg-primary text-white px-3 py-1 rounded fw-bold small border-0" id="variationAddendumDisplay">
                                    <i class="bi bi-hash me-1"></i> NO: 1
                                </div>
                                <button class="btn btn-sm btn-outline-info px-3" onclick="openScopeTab('variation-history')">
                                    <i class="bi bi-list-ul me-1"></i> Archive
                                </button>
                                <input type="hidden" id="variationAddendumSel" value="1">
                                <button class="btn btn-sm btn-primary px-3" onclick="addNewScopeRow('variation')">
                                    <i class="bi bi-plus-lg me-1"></i> Add Variation
                                </button>
                            </div>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">VARIATION PROJECT SCOPE - ADDENDUM NO: <span id="print-variation-no">1</span></h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px;">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 scope-table" id="variationScopeTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4" style="width: 80px;">S/NO</th>
                                            <th class="text-center">DESCRIPTION</th>
                                            <th style="width: 100px;">UNIT</th>
                                            <th style="width: 120px;">QUANTITY</th>
                                            <th style="width: 130px;">PRICE</th>
                                            <th style="width: 100px;">TAX (%)</th>

                                            <th style="width: 160px;">TOTAL AMOUNT</th>
                                            <th class="text-end pe-4 d-print-none" style="width: 80px;">ACTION</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                    <tfoot class="bg-light fw-bold">
                                        <tr>
                                            <td colspan="6" class="text-end ps-4">VARIATION TOTAL:</td>
                                            <td id="variationScopeTotal" class="text-primary fs-5 pe-4">0.00</td>
                                            <td class="d-print-none"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <div class="mt-4 d-flex justify-content-between align-items-center d-print-none">
                            <div class="d-flex align-items-center gap-3">
                                <div id="signedDocContainer-variation" class="d-flex align-items-center gap-2 p-2 px-3 bg-light rounded shadow-sm border d-none" style="border-left: 4px solid #6c757d !important;">
                                    <small class="text-secondary fw-bold"><i class="bi bi-file-earmark-check me-1"></i> SIGNED DOCUMENT:</small>
                                    <a href="#" id="signedDocLink-variation" target="_blank" class="text-decoration-none small fw-bold text-dark text-truncate" style="max-width: 250px;">...</a>
                                    <button class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="deleteScopeDoc('variation')" title="Remove document"><i class="bi bi-trash"></i></button>
                                </div>
                                <button class="btn btn-outline-secondary btn-sm px-3" id="attachDocBtn-variation" onclick="triggerScopeDocUpload('variation')">
                                    <i class="bi bi-paperclip me-1"></i> Attach Signed Addendum
                                </button>
                            </div>
                            <button class="btn btn-primary px-5" onclick="saveScope('variation')">
                                <i class="bi bi-save me-1"></i> Save Variation
                            </button>
                        </div>
                    </div>
                    <!-- Variation Archive (History) Tab -->
                    <div class="tab-pane fade p-4" id="scope-variation-history" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1 text-info"><i class="bi bi-clock-history me-2"></i> Variation Archive</h5>
                                <p class="text-muted small mb-0">Browse and edit previously saved variation orders and addendums.</p>
                            </div>
                            <button class="btn btn-sm btn-primary" onclick="openScopeTab('variation')">
                                <i class="bi bi-plus-lg me-1"></i> Create New Addendum
                            </button>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">VARIATION PROJECT SCOPE - ADDENDUM NO: <span id="print-variation-history-no">1</span></h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>

                        <!-- Addendum Selector (Horizontal Scroll) -->
                        <div class="d-flex overflow-auto pb-2 mb-3 gap-2 d-print-none" id="addendumHistorySelector" style="scrollbar-width: thin;">
                            <!-- Numbers 1, 2, 3... added dynamically -->
                        </div>

                        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px;">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 scope-table" id="variationHistoryTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4" style="width: 80px;">S/NO</th>
                                            <th class="text-center">DESCRIPTION</th>
                                            <th style="width: 100px;">UNIT</th>
                                            <th style="width: 120px;">QUANTITY</th>
                                            <th style="width: 130px;">PRICE</th>
                                            <th style="width: 100px;">TAX (%)</th>

                                            <th style="width: 160px;">TOTAL AMOUNT</th>
                                            <th class="text-end pe-4 d-print-none" style="width: 80px;">ACTION</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                    <tfoot class="bg-light fw-bold">
                                        <tr>
                                            <td colspan="6" class="text-end ps-4">VARIATION TOTAL:</td>
                                            <td id="variationHistoryTotal" class="text-primary fs-5 pe-4">0.00</td>
                                            <td class="d-print-none"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>


                        <input type="hidden" id="variationHistoryAddendumSel" value="1">
                        <div class="mt-4 d-flex justify-content-between align-items-center d-print-none">
                            <div class="d-flex align-items-center gap-3">
                                <div id="signedDocContainer-variation-history" class="d-flex align-items-center gap-2 p-2 px-3 bg-light rounded shadow-sm border d-none">
                                    <small class="text-secondary fw-bold">SIGNED DOCUMENT:</small>
                                    <a href="#" id="signedDocLink-variation-history" target="_blank" class="text-decoration-none small fw-bold text-primary">...</a>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-danger px-4" onclick="deleteAddendum()">
                                    <i class="bi bi-trash me-1"></i> Delete This Addendum
                                </button>
                                <button class="btn btn-primary text-white px-5" onclick="saveScope('variation-history')">
                                    <i class="bi bi-save me-1"></i> Update This Addendum
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Scopes Tab -->
                    <div class="tab-pane fade p-4" id="scope-additional" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                            <div>
                                <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-plus-square me-2"></i> Additional Scopes <span id="additionalScopeIncreasePercent"></span></h5>
                                <p class="text-muted small mb-0">New works or services added that were not in the original scope.</p>
                            </div>
                            <div class="d-flex gap-2">
                                <div id="signedDocContainer-additional" class="d-flex align-items-center gap-2 p-1 px-3 bg-light rounded-pill border border-primary shadow-sm d-none">
                                    <small class="text-primary fw-bold"><i class="bi bi-file-earmark-check me-1"></i> Signed Doc:</small>
                                    <a href="#" id="signedDocLink-additional" target="_blank" class="text-decoration-none small text-truncate" style="max-width: 150px;">...</a>
                                    <button class="btn btn-sm btn-link text-danger p-0 ms-1" onclick="deleteScopeDoc('additional')"><i class="bi bi-x-circle"></i></button>
                                </div>
                                <button class="btn btn-outline-primary btn-sm" id="attachDocBtn-additional" onclick="triggerScopeDocUpload('additional')">
                                    <i class="bi bi-paperclip me-1"></i> Attach Signed Copy
                                </button>
                                <button class="btn btn-primary" onclick="addNewScopeRow('additional')">
                                    <i class="bi bi-plus-lg me-1"></i> Add Additional Item
                                </button>
                            </div>
                        </div>

                        <!-- Print Header (Visible only on print) -->
                        <div class="text-center mb-4 report-header d-none d-print-block">
                            <?php if(!empty($company_logo)): ?>
                                <div class="mb-2">
                                    <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                </div>
                            <?php endif; ?>
                            <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                            <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">ADDITIONAL PROJECT SCOPE</h3>
                            <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                            <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($project_name) ?></h5>
                            <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                        </div>
                        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px;">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 scope-table" id="additionalScopeTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4" style="width: 80px;">S/NO</th>
                                            <th class="text-center">DESCRIPTION</th>
                                            <th style="width: 100px;">UNIT</th>
                                            <th style="width: 120px;">QUANTITY</th>
                                            <th style="width: 130px;">PRICE</th>
                                            <th style="width: 100px;">TAX (%)</th>

                                            <th style="width: 160px;">TOTAL AMOUNT</th>
                                            <th class="text-end pe-4 d-print-none" style="width: 80px;">ACTION</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                    <tfoot class="bg-light fw-bold">
                                        <tr class="border-top-0">
                                            <td colspan="6" class="text-end ps-4 border-0 text-primary">ADDITIONAL SCOPE TOTAL:</td>
                                            <td id="additionalScopeTotal" class="text-primary pe-4 py-3 fs-4 border-0">0.00</td>
                                            <td class="border-0 d-print-none"></td>
                                        </tr>
                                        <tr class="bg-white border-top border-2 border-dark">
                                            <td colspan="6" class="text-end ps-4 text-dark fw-bolder fs-5">PROJECT GRAND TOTAL:</td>
                                            <td id="additionalScopeGrandTotal" class="text-dark fs-4 pe-4 fw-bolder">0.00</td>
                                            <td></td>
                                        </tr>
                                        <!-- Hidden elements for script synchronization -->
                                        <tr style="display:none;">
                                            <td id="grandTotalBaseline">0.00</td>
                                            <td id="grandTotalVariations">0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>


                        <div class="mt-4 text-end d-print-none">
                            <button class="btn btn-primary px-5" onclick="saveScope('additional')">
                                <i class="bi bi-save me-1"></i> Save Additional Scope
                            </button>
                        </div>
                    </div>

                    <!-- NEW: Reporting Tab (Daily Update) -->
                    <div class="tab-pane fade p-4" id="reporting" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0"><i class="bi bi-pencil-square me-2 text-primary"></i>Project Reporting & Updates</h5>
                            <div class="d-flex align-items-center">
                                <label for="reportingReportDate" class="me-2 text-muted small fw-bold">Report Date:</label>
                                <input type="date" id="reportingReportDate" class="form-control form-control-sm border-primary shadow-sm" value="<?= date('Y-m-d') ?>" onchange="loadReportingData()" style="width: 160px;">
                            </div>
                        </div>
                        <div id="reportingContent">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle border mb-0" id="reportingTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4 text-center" style="width: 80px;">S/NO</th>
                                            <th class="text-center">Description</th>
                                            <th class="text-center" style="width: 120px;">Unit</th>
                                            <th class="text-center" style="width: 150px;">Total Scope</th>
                                            <th class="text-center" style="width: 180px;">Actual (Qty)</th>
                                            <th class="text-center" style="width: 120px;">Weight (%)</th>
                                            <th class="text-center" style="width: 120px;">Progress (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Populated via JS -->
                                    </tbody>
                                </table>
                            </div>
                            <div id="reportingComments" class="mt-4">
                                <label for="reportingComment" class="form-label fw-bold text-muted small"><i class="bi bi-chat-left-text me-1"></i> Reporting Comments / Observations:</label>
                                <textarea id="reportingComment" class="form-control border-info-subtle shadow-sm" rows="3" placeholder="Enter any site observations or comments for today's report..."></textarea>
                            </div>
                            <div class="mt-3">
                                <label class="form-label fw-bold text-muted small mb-2"><i class="bi bi-paperclip me-1"></i> Attachments (PDF / Image):</label>
                                <!-- Saved attachments (loaded from DB) -->
                                <div id="savedAttachmentsList" class="mb-1"></div>
                                <!-- New attachment rows added this session -->
                                <div id="newAttachmentsList" class="mb-2"></div>
                                <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm" onclick="addReportingAttachmentRow()">
                                    <i class="bi bi-plus-circle me-1"></i> Add Attachment
                                </button>
                            </div>
                        </div>
                        <div class="mt-4 d-flex justify-content-between align-items-center">
                            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i> Reporting data updates project completion indicators.</p>
                            <button class="btn btn-info text-white px-5 shadow-sm" id="btnSaveReporting" onclick="saveDailyReporting()">
                                <i class="bi bi-cloud-upload me-1"></i> Submit Daily Report
                            </button>
                        </div>
                    </div>

                    <!-- Reports Tab -->
                    <div class="tab-pane fade p-4" id="performance" role="tabpanel">
                        <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 d-print-none gap-3">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-speedometer2 me-2 text-success"></i> Project Progress Reports</h5>
                                <p class="text-muted small mb-0">Analyze project performance and milestone progress across different periods.</p>
                            </div>
                            <div id="performanceFilterContainer" class="d-flex gap-2 align-items-center flex-wrap">
                            </div>
                        </div>

                        <div class="mb-3 d-print-none" style="overflow-x: auto;">
                            <!-- Period filter buttons — scrollable on narrow screens -->
                            <div class="btn-group shadow-sm flex-nowrap" role="group">
                                <input type="radio" class="btn-check" name="report_filter" id="filter_daily" value="daily" checked onclick="setPerformanceFilter('daily')">
                                <label class="btn btn-outline-success px-3" for="filter_daily">Daily</label>

                                <input type="radio" class="btn-check" name="report_filter" id="filter_weekly" value="weekly" onclick="setPerformanceFilter('weekly')">
                                <label class="btn btn-outline-success px-3" for="filter_weekly">Weekly</label>

                                <input type="radio" class="btn-check" name="report_filter" id="filter_monthly" value="monthly" onclick="setPerformanceFilter('monthly')">
                                <label class="btn btn-outline-success px-3" for="filter_monthly">Monthly</label>

                                <input type="radio" class="btn-check" name="report_filter" id="filter_quarterly" value="quarterly" onclick="setPerformanceFilter('quarterly')">
                                <label class="btn btn-outline-success px-3" for="filter_quarterly">Quarterly</label>

                                <input type="radio" class="btn-check" name="report_filter" id="filter_annual" value="annual" onclick="setPerformanceFilter('annual')">
                                <label class="btn btn-outline-success px-3" for="filter_annual">Yearly</label>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm" style="border-radius: 12px;" id="performanceReportArea">
                            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center d-print-none">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text me-2"></i> Report View</h6>
                                <div class="d-flex gap-2 align-items-center">
                                    <!-- Desktop View: Split Dropdowns -->
                                    <div class="d-none d-md-flex gap-2">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-dark dropdown-toggle px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-eye me-1"></i> View
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                                <li><button class="dropdown-item py-2 btn-milestone-filter" data-type="performance" data-limit="0" onclick="filterMilestoneLevels('performance', 0, this)"><i class="bi bi-list-task me-2 text-muted"></i> Main Only</button></li>
                                                <li><button class="dropdown-item py-2 btn-milestone-filter active" data-type="performance" data-limit="all" onclick="filterMilestoneLevels('performance', 'all', this)"><i class="bi bi-list-nested me-2 text-muted"></i> View All</button></li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-sm btn-outline-primary px-3 shadow-sm" onclick="exportPerformancePDF()">
                                            <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                                        </button>
                                    </div>

                                    <!-- Mobile View: Combined Blue Button -->
                                    <div class="dropdown d-md-none">
                                        <button class="btn btn-primary btn-sm dropdown-toggle shadow-sm px-4" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill me-1"></i> Actions
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="min-width: 200px; border-radius: 12px;">
                                            <li class="dropdown-header text-uppercase small fw-bold">Report Display</li>
                                            <li><button class="dropdown-item py-2" onclick="filterMilestoneLevels('performance', 0, this)"><i class="bi bi-list-task me-2 text-secondary"></i>Main Only</button></li>
                                            <li><button class="dropdown-item py-2" onclick="filterMilestoneLevels('performance', 'all', this)"><i class="bi bi-list-nested me-2 text-secondary"></i>Full View</button></li>
                                            <li class="dropdown-header text-uppercase small fw-bold">Export PDF</li>
                                            <li><button class="dropdown-item py-2" onclick="exportPerformancePDF()"><i class="bi bi-file-pdf me-2 text-danger"></i>Export PDF</button></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-2 p-md-3">
                                <div class="text-center mb-4 report-header d-none d-print-block">
                                    <?php if(!empty($company_logo)): ?>
                                        <div class="mb-2">
                                            <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                        </div>
                                    <?php endif; ?>
                                    <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                                    <h3 class="fw-bold mb-1" id="performanceReportTitle" style="color: #000 !important; text-transform: uppercase;">PROJECT PROGRESS REPORT</h3>
                                    <h6 class="text-muted fw-bold mb-0 mt-1" style="color: #666 !important;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                                    <h5 class="text-dark fw-bold mb-1" id="projectNameReport"><?= htmlspecialchars($project_name) ?></h5>
                                    <p class="text-muted small text-uppercase fw-bold letter-spacing-1 mb-2" id="performanceReportSubtitle">DAILY UPDATE</p>
                                    <div class="mx-auto bg-primary" style="width: 60px; height: 3px; border-radius: 2px;"></div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0" id="performanceTable" style="min-width: 560px;">
                                        <thead class="bg-light" id="performanceTableHead">
                                            <tr>
                                                <th class="ps-4 text-center" style="width: 60px;">S/NO</th>
                                                <th class="text-center">Milestone Description / Phase</th>
                                                <th class="text-center" style="width: 100px;">Unit</th>
                                                <th class="text-center" style="width: 120px;">Scope</th>
                                                <th class="text-center" style="width: 120px;">Actual</th>
                                                <th class="text-center" style="width: 120px;">Weight (%)</th>
                                                <th class="text-center" style="width: 120px;">Progress (%)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Read-only rows generated based on milestones -->
                                        </tbody>
                                        <tfoot class="bg-light fw-bold text-dark">
                                            <!-- Row 1: Period Specific Totals (Label set via JS: Total Daily Report, etc.) -->
                                            <tr class="border-bottom">
                                                <td colspan="5" id="periodReportLabel" class="text-end ps-4 py-2 text-muted uppercase small">Total Report:</td>
                                                <td id="periodWeightTotal" class="text-center py-2" style="font-weight: 700 !important; font-size: 0.9rem;">0.00%</td>
                                                <td id="periodProgressTotal" class="text-center py-2" style="font-weight: 700 !important; font-size: 0.9rem;">0.00%</td>
                                            </tr>
                                            <!-- Row 2: Global Aggregated Totals (Constant across all views) -->
                                            <tr class="bg-white">
                                                <td colspan="5" class="text-end ps-4 py-3" style="font-size: 1.1rem; letter-spacing: 0.5px; font-weight: bold;">AGGREGATED PROGRESS:</td>
                                                <td colspan="2" id="globalAggregatedProgress" class="text-center py-3 text-primary" style="font-weight: 900 !important; font-size: 1.4rem; background: rgba(13, 110, 253, 0.05);">0.00%</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div> <!-- end table-responsive -->
                                
                                <div id="perfReportCommentsContainer" class="mt-4 rounded border border-info-subtle overflow-hidden" style="display: none;">
                                    <div class="d-flex" style="min-height: 80px;">
                                        <!-- LEFT: Attachments — always visible -->
                                        <div id="perfAttachmentContainer" class="p-3 bg-white flex-shrink-0" style="width: 35%; border-right: 1px solid #dee2e6;">
                                            <h6 class="fw-bold text-muted mb-3" style="font-size: 0.82rem; letter-spacing: 0.3px;">
                                                <i class="bi bi-paperclip me-1 text-info"></i> Attachments
                                            </h6>
                                            <div id="perfAttachmentList" class="d-flex flex-column gap-2"></div>
                                        </div>
                                        <!-- RIGHT: Comments — always on the right -->
                                        <div class="p-3 bg-light flex-grow-1">
                                            <h6 class="fw-bold text-muted mb-2" style="font-size: 0.82rem; letter-spacing: 0.3px;">
                                                <i class="bi bi-chat-left-text me-1"></i> Comments / Observations
                                            </h6>
                                            <p id="perfReportComments" class="mb-0 text-dark" style="white-space: pre-wrap; font-style: italic;"></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="reportMetaFooter" class="d-none">
                                    <div class="row g-4">
                                        <!-- Only visible during print/export -->
                                        <div class="col-12 text-center mt-5 d-none d-print-block">
                                            <div class="border-bottom pb-2 mb-2 mx-auto" style="width: 40%;"></div>
                                            <small class="text-uppercase fw-bold text-muted d-block" style="font-size: 0.6rem; letter-spacing: 1px;"></small>
                                            <div class="text-muted small">
                                                This report was <strong>Printed</strong> by <span id="perfPrintUser" class="text-dark fw-bold"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= ucwords($_SESSION['user_role'] ?? 'Staff') ?></span> 
                                                on <span id="perfPrintTimestamp" class="text-dark fw-bold"><?= date('d M, Y \a\t H:i:s') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SC / Supplier Payments Tab -->
                    <div class="tab-pane fade p-3 p-md-4" id="sc-payments" role="tabpanel">

                        <?php if ($supplier_mode): ?>
                        <!-- ── Supplier Payments (via purchase_orders) ── -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                            <h5 class="fw-bold mb-0">
                                <i class="bi bi-cash-stack me-2 text-success"></i>Payments
                                <small class="text-muted fw-normal fs-6 ms-1">— <?= htmlspecialchars($supplier_view_name) ?></small>
                            </h5>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-primary btn-sm shadow-sm" onclick="loadSupplierProjectPayments()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                                <button class="btn btn-success btn-sm shadow-sm" onclick="openSuppPaymentModal()">
                                    <i class="bi bi-plus-circle me-1"></i> Record Payment
                                </button>
                            </div>
                        </div>
                        <div id="suppPaymentsTotalBar" class="alert alert-success py-2 small mb-3 d-none">
                            <i class="bi bi-cash-coin me-1"></i>Total Paid: <strong id="suppPaymentsTotalAmt"></strong>
                        </div>
                        <div id="suppPaymentsContent">
                            <div class="py-5 text-center text-muted">
                                <span class="spinner-border spinner-border-sm me-2"></span> Loading...
                            </div>
                        </div>

                        <?php else: ?>
                        <!-- ── Sub-Contractor Payments ── -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                            <h5 class="fw-bold mb-0"><i class="bi bi-cash-stack me-2 text-success"></i>Sub-Contractor Payments</h5>
                            <button class="btn btn-success btn-sm shadow-sm" onclick="openScPaymentModal()">
                                <i class="bi bi-plus-circle me-1"></i>Record Payment
                            </button>
                        </div>
                        <div id="scPaymentsTotalBar" class="alert alert-success py-2 small mb-3" style="display:none;">
                            <i class="bi bi-cash-coin me-1"></i>Total Paid: <strong id="scPaymentsTotalAmt"></strong>
                        </div>
                        <div class="table-responsive" style="overflow:visible;">
                            <table class="table table-hover align-middle border mb-0" id="scPaymentsTable">
                                <thead class="table-light small fw-bold text-muted">
                                    <tr>
                                        <th width="50" class="text-center">S/No</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Currency</th>
                                        <th>Method</th>
                                        <th>Reference No</th>
                                        <th>Receipt No</th>
                                        <th>Status</th>
                                        <th width="80" class="text-center d-print-none">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="scPaymentsBody">
                                    <tr><td colspan="9" class="text-center py-4 text-muted small">Click the Payments tab to load data.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                    </div>

                </div>
            </div>
        </div>

        <!-- Financial Summary Cards -->
        <div id="overviewFinancialCards" class="overview-print-section px-2 px-md-0 mb-3">
            <!-- Row 1: Expected, Executed, Revenue (Billed), Revenue (Un-Billed) -->
            <div class="row g-1 g-md-2 mb-1 mb-md-2 row-cols-2 row-cols-md-4">
                <!-- 1. Expected -->
                <div class="col">
                    <div class="card shadow-sm h-100" style="background-color: #d1e7dd !important; border: 1px solid #badbcc !important; border-radius: 10px;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-1 me-md-2 d-none d-md-flex" style="background: rgba(15,81,50,0.1); width:32px; height:32px; align-items:center; justify-content:center; flex-shrink:0;">
                                    <i class="bi bi-hourglass-split" style="color:#0f5132 !important; font-size:0.9rem;"></i>
                                </div>
                                <div class="w-100">
                                    <p class="mb-0 text-uppercase fw-bold" style="font-size:clamp(0.45rem,1vw,0.55rem); letter-spacing:0.2px; color:#0f5132 !important; white-space:nowrap;">REVENUE (EXPECTED)</p>
                                    <h6 class="fw-bold mb-0" id="expectedDisplay" style="color:#0f5132 !important; font-size:clamp(0.55rem,1.5vw,0.85rem); word-break:break-word; white-space:normal; line-height:1.2;">0 TZS</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 2. Executed -->
                <div class="col">
                    <div class="card shadow-sm h-100" style="background-color: #d1e7dd !important; border: 1px solid #badbcc !important; border-radius: 10px;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-1 me-md-2 d-none d-md-flex" style="background: rgba(15,81,50,0.1); width:32px; height:32px; align-items:center; justify-content:center; flex-shrink:0;">
                                    <i class="bi bi-clipboard2-check" style="color:#0f5132 !important; font-size:0.9rem;"></i>
                                </div>
                                <div class="w-100">
                                    <p class="mb-0 text-uppercase fw-bold" style="font-size:clamp(0.45rem,1vw,0.55rem); letter-spacing:0.2px; color:#0f5132 !important; white-space:nowrap;">REVENUE (EXECUTED)</p>
                                    <h6 class="fw-bold mb-0" id="executedDisplay" style="color:#0f5132 !important; font-size:clamp(0.55rem,1.5vw,0.85rem); word-break:break-word; white-space:normal; line-height:1.2;">0 TZS</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 3. Revenue (Billed) -->
                <div class="col">
                    <div class="card shadow-sm h-100" style="background-color: #d1e7dd !important; border: 1px solid #badbcc !important; border-radius: 10px;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-1 me-md-2 d-none d-md-flex" style="background: rgba(15,81,50,0.1); width:32px; height:32px; align-items:center; justify-content:center; flex-shrink:0;">
                                    <i class="bi bi-cash-stack" style="color:#0f5132 !important; font-size:0.9rem;"></i>
                                </div>
                                <div class="w-100">
                                    <p class="mb-0 text-uppercase fw-bold" style="font-size:clamp(0.45rem,1vw,0.55rem); letter-spacing:0.2px; color:#0f5132 !important; white-space:nowrap;">Revenue (Billed)</p>
                                    <h6 class="fw-bold mb-0" id="revenueDisplay" style="color:#0f5132 !important; font-size:clamp(0.55rem,1.5vw,0.85rem); word-break:break-word; white-space:normal; line-height:1.2;">0 TZS</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 4. Revenue (Un-Billed) = Executed - Revenue (Billed) -->
                <div class="col">
                    <div class="card shadow-sm h-100" style="background-color: #d1e7dd !important; border: 1px solid #badbcc !important; border-radius: 10px;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-1 me-md-2 d-none d-md-flex" style="background: rgba(15,81,50,0.1); width:32px; height:32px; align-items:center; justify-content:center; flex-shrink:0;">
                                    <i class="bi bi-cash" style="color:#0f5132 !important; font-size:0.9rem;"></i>
                                </div>
                                <div class="w-100">
                                    <p class="mb-0 text-uppercase fw-bold" style="font-size:clamp(0.45rem,1vw,0.55rem); letter-spacing:0.2px; color:#0f5132 !important; white-space:nowrap;">Revenue (Un-Billed)</p>
                                    <h6 class="fw-bold mb-0" id="revenueUnbilledDisplay" style="color:#0f5132 !important; font-size:clamp(0.55rem,1.5vw,0.85rem); word-break:break-word; white-space:normal; line-height:1.2;">0 TZS</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Row 2: Paid, Budget, Expenses, Profit -->
            <div class="row g-1 g-md-2 row-cols-2 row-cols-md-4">
                <!-- 5. Paid -->
                <div class="col">
                    <div class="card shadow-sm h-100" style="background-color: #d1e7dd !important; border: 1px solid #badbcc !important; border-radius: 10px;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-1 me-md-2 d-none d-md-flex" style="background: rgba(15,81,50,0.1); width:32px; height:32px; align-items:center; justify-content:center; flex-shrink:0;">
                                    <i class="bi bi-check-circle" style="color:#0f5132 !important; font-size:0.9rem;"></i>
                                </div>
                                <div class="w-100">
                                    <p class="mb-0 text-uppercase fw-bold" style="font-size:clamp(0.45rem,1vw,0.55rem); letter-spacing:0.2px; color:#0f5132 !important; white-space:nowrap;">Paid</p>
                                    <h6 class="fw-bold mb-0" id="paidDisplay" style="color:#0f5132 !important; font-size:clamp(0.55rem,1.5vw,0.85rem); word-break:break-word; white-space:normal; line-height:1.2;">0 TZS</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 6. Budget -->
                <div class="col">
                    <div class="card shadow-sm h-100" style="background-color: #d1e7dd !important; border: 1px solid #badbcc !important; border-radius: 10px;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-1 me-md-2 d-none d-md-flex" style="background: rgba(15,81,50,0.1); width:32px; height:32px; align-items:center; justify-content:center; flex-shrink:0;">
                                    <i class="bi bi-piggy-bank" style="color:#0f5132 !important; font-size:0.9rem;"></i>
                                </div>
                                <div class="w-100">
                                    <p class="mb-0 text-uppercase fw-bold" style="font-size:clamp(0.45rem,1vw,0.55rem); letter-spacing:0.2px; color:#0f5132 !important; white-space:nowrap;">Budget</p>
                                    <h6 class="fw-bold mb-0" id="budgetDisplay" style="color:#0f5132 !important; font-size:clamp(0.55rem,1.5vw,0.85rem); word-break:break-word; white-space:normal; line-height:1.2;">0 TZS</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 7. Expenses -->
                <div class="col">
                    <div class="card shadow-sm h-100" style="background-color: #d1e7dd !important; border: 1px solid #badbcc !important; border-radius: 10px;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-1 me-md-2 d-none d-md-flex" style="background: rgba(15,81,50,0.1); width:32px; height:32px; align-items:center; justify-content:center; flex-shrink:0;">
                                    <i class="bi bi-wallet2" style="color:#0f5132 !important; font-size:0.9rem;"></i>
                                </div>
                                <div class="w-100">
                                    <p class="mb-0 text-uppercase fw-bold" style="font-size:clamp(0.45rem,1vw,0.55rem); letter-spacing:0.2px; color:#0f5132 !important; white-space:nowrap;">Expenses</p>
                                    <h6 class="fw-bold mb-0" id="expenseDisplay" style="color:#0f5132 !important; font-size:clamp(0.55rem,1.5vw,0.85rem); word-break:break-word; white-space:normal; line-height:1.2;">0 TZS</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 8. Profit -->
                <div class="col">
                    <div class="card shadow-sm h-100" style="background-color: #d1e7dd !important; border: 1px solid #badbcc !important; border-radius: 10px;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-1 me-md-2 d-none d-md-flex" style="background: rgba(15,81,50,0.1); width:32px; height:32px; align-items:center; justify-content:center; flex-shrink:0;">
                                    <i class="bi bi-graph-up-arrow" id="profitIcon" style="color:#0f5132 !important; font-size:0.9rem;"></i>
                                </div>
                                <div class="w-100">
                                    <p class="mb-0 text-uppercase fw-bold" style="font-size:clamp(0.45rem,1vw,0.55rem); letter-spacing:0.2px; color:#0f5132 !important; white-space:nowrap;">Profit</p>
                                    <h6 class="fw-bold mb-0" id="profitDisplay" style="color:#0f5132 !important; font-size:clamp(0.55rem,1.5vw,0.85rem); word-break:break-word; white-space:normal; line-height:1.2;">0 TZS</h6>
                                    <small class="d-block" id="profitMarginDisplay" style="font-size:clamp(0.4rem,0.8vw,0.5rem); color:#0f5132 !important; opacity:0.8; word-break:break-word;">0% margin</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>



        <!-- Main Content -->
        <div id="overviewMainContent" class="row g-4 overview-print-section">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Project Overview -->
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2 text-primary"></i> Project Overview</h6>
                        <span id="activeProjectBadge" class="badge">Active</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="row g-0">
                            <!-- Progress Info -->
                            <div class="col-md-5 bg-light p-4 text-center border-end">
                                <h6 class="text-muted text-uppercase small fw-bold mb-3">Overall Progress</h6>
                                <div class="position-relative d-inline-block mb-3">
                                    <h1 class="fw-bold text-primary mb-0" id="progressTextDisplay" style="font-size: clamp(1.8rem, 6vw, 3.5rem); word-break: break-word; overflow-wrap: break-word; max-width: 100%;">0%</h1>
                                </div>
                                <div class="px-3">
                                    <div class="progress mb-3" style="height: 8px; border-radius: 10px;">
                                        <div id="progressBarDisplay" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div id="progressStatusMessage" class="small fw-semibold"></div>
                                </div>
                            </div>
                            <!-- Breakthrough / Details -->
                            <div class="col-md-7 p-4">
                                <div class="row g-3 mb-4" id="projectDetailsGrid">
                                    <!-- Populated by JS -->
                                </div>
                                
                                <h6 class="fw-bold text-dark mb-2 border-top pt-3 print-page-break"><i class="bi bi-text-left me-2 text-primary"></i> Description</h6>
                                <div id="descriptionDisplay" class="text-muted small lh-base mb-4" style="white-space: pre-wrap; max-height: 150px; overflow-y: auto;">
                                    No description provided.
                                </div>

                                <h6 class="text-muted text-uppercase small fw-bold mb-3 border-top pt-3">Metrics Breakdown</h6>
                                <div id="progressBreakdown"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Budget Performance -->
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2 text-primary"></i> Budget Performance</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <small class="text-muted d-block mb-1">Budget Allocated</small>
                                <h5 class="fw-bold mb-0 text-primary" id="budgetAllocated">0 TZS</h5>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted d-block mb-1">Amount Spent</small>
                                <h5 class="fw-bold mb-0 text-danger" id="budgetSpent">0 TZS</h5>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted d-block mb-1">Remaining</small>
                                <h5 class="fw-bold mb-0" id="budgetRemaining">0 TZS</h5>
                            </div>
                        </div>
                        
                        <!-- Budget Utilization Progress -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-muted fw-bold">Budget Utilization</small>
                                <span class="badge" id="utilizationBadge">0%</span>
                            </div>
                            <div class="progress" style="height: 20px; border-radius: 10px;">
                                <div id="budgetProgressBar" class="progress-bar" role="progressbar" style="width: 0%">
                                    <span class="fw-bold" id="budgetProgressText">0%</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Status Message -->
                        <div id="budgetStatusMessage" class="alert mb-0" role="alert">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="budgetStatusText">Calculating budget status...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Timeline -->
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i> PROJECT DURATION</h6>
                    </div>
                    <div class="card-body p-0 p-md-4">
                        <div class="row g-1 mb-4">
                            <div class="col-6">
                                <div class="p-2 py-4 rounded-3 bg-light border-start border-4 border-success shadow-sm timeline-summary-card d-flex flex-column justify-content-center align-items-center text-center" style="min-height: 85px;">
                                    <small class="text-secondary text-uppercase fw-bold mb-1 text-nowrap" style="font-size: 12px; letter-spacing: 0.5px;">START DATE</small>
                                    <div class="fw-bold text-dark date-value text-nowrap" id="startDateDisplay" style="font-size: 11px;">N/A</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 py-4 rounded-3 bg-light border-start border-4 border-danger shadow-sm timeline-summary-card d-flex flex-column justify-content-center align-items-center text-center" style="min-height: 85px;">
                                    <small class="text-secondary text-uppercase fw-bold mb-1 text-nowrap" style="font-size: 12px; letter-spacing: 0.5px;">END DATE</small>
                                    <div class="fw-bold text-dark date-value text-nowrap" id="deadlineDisplay" style="font-size: 11px;">N/A</div>
                                </div>
                            </div>
                        </div>

                        <!-- Pulse Countdown Card -->
                        <div class="p-4 rounded-4 shadow-sm border-0 position-relative overflow-hidden" style="background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%); color: white;">
                            <i class="bi bi-hourglass-split position-absolute" style="font-size: 5rem; bottom: -1rem; right: -1rem; opacity: 0.15;"></i>
                            
                            <div class="d-flex justify-content-between align-items-start mb-3 position-relative">
                                <div>
                                    <small class="text-uppercase fw-bold" style="font-size: 0.6rem; letter-spacing: 1.5px; opacity: 0.9;">Time status</small>
                                    <h4 class="fw-bold mb-0 mt-1" id="daysRemainingFocus">Calculating...</h4>
                                </div>
                                <div class="badge bg-white text-primary rounded-pill px-3 py-2 fw-bold shadow-sm ms-auto" style="font-size: 0.75rem;" id="totalDurationBadge">
                                    -- Days Total
                                </div>
                            </div>
                            
                            <div class="progress mb-2 bg-white bg-opacity-25" style="height: 10px; border-radius: 10px;">
                                <div id="timeProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-white" role="progressbar" style="width: 0%"></div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2 small fw-semibold position-relative">
                                <span id="timeDescriptor"><i class="bi bi-activity me-1"></i>Calculating status...</span>
                                
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Priority & Status -->
                <div class="card shadow-sm border-0 mb-4 print-page-break" style="border-radius: 12px;">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-flag me-2"></i> Priority & Status</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <small class="text-muted d-block mb-2">Priority Level</small>
                            <span id="priorityBadge" class="priority-badge">NOT SET</span>
                        </div>
                        <div>
                            <small class="text-muted d-block mb-2">Current Status</small>
                            <h5 class="fw-bold mb-0 text-capitalize" id="statusTextDisplay">Planning</h5>
                        </div>
                    </div>
                </div>

                <!-- System Meta -->
                <div class="card shadow-sm border-0 d-print-none" style="border-radius: 12px;">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2"></i> System Info</h6>
                    </div>
                    <div class="card-body p-4 small text-muted">
                        <div class="mb-2"><strong>ID:</strong> #<span id="projectIdMeta"></span></div>
                        <div class="mb-2"><strong>Created:</strong> <span id="createdAtMeta"></span></div>
                        <div class="mb-0"><strong>Updated:</strong> <span id="updatedAtMeta"></span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Milestone Edit Modal — mobile only (inline edit works on desktop) -->
<div class="modal fade" id="milestoneEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning py-3 px-4">
                <h6 class="modal-title fw-bold mb-0"><i class="bi bi-pencil me-2"></i>Edit Milestone</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="msEditRowId">
                <div class="mb-3">
                    <label class="form-label fw-bold small">Description <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="msEditDesc" rows="3" placeholder="Milestone description..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">Unit</label>
                    <input type="text" class="form-control" id="msEditUnit" placeholder="e.g. days, pcs, %">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">Scope (%)</label>
                    <input type="number" class="form-control" id="msEditScope" min="0" max="100" step="0.01" placeholder="0.00">
                </div>
                <div class="mb-3" id="msEditWeightGroup">
                    <label class="form-label fw-bold small">Weight (%)</label>
                    <input type="number" class="form-control" id="msEditWeight" min="0" max="100" step="0.01" placeholder="0.00">
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-3">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning text-dark fw-bold px-4" onclick="saveMilestoneEditModal()">
                    <i class="bi bi-check-lg me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Document Meta Modal (For manual project uploads) -->
<div class="modal fade" id="editDocMetaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-info text-white p-4">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Document Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editDocMetaForm">
                <input type="hidden" name="document_id" id="edit_doc_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Document Title</label>
                        <input type="text" class="form-control" name="document_name" id="edit_doc_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Source / Category</label>
                        <select class="form-select" name="source_select" id="edit_source_select" required>
                            <option value="Project Asset">Project Asset</option>
                            <option value="Payment Voucher">Payment Voucher</option>
                            <option value="Budget Allocation">Budget Allocation</option>
                            <option value="Invoice / Sales">Invoice / Sales</option>
                            <option value="Purchase Order">Purchase Order</option>
                            <option value="Other">Other (Write Manually)</option>
                        </select>
                        <input type="text" class="form-control mt-2" name="source_manual" id="edit_source_manual" style="display: none;" placeholder="Enter custom source...">
                    </div>
                </div>
                <div class="modal-footer bg-light p-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Project Modal -->
<div class="modal fade" id="editProjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Project</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editProjectForm">
                <input type="hidden" name="project_id" id="edit_project_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">Project Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="project_name" id="edit_project_name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Client/Employer <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="customer_id" id="edit_customerSelect" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>" data-name="<?= htmlspecialchars($c['customer_name'] . ($c['company_name'] ? ' (' . $c['company_name'] . ')' : '')) ?>">
                                        <?= htmlspecialchars($c['customer_name'] . ($c['company_name'] ? ' (' . $c['company_name'] . ')' : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="client_name" id="edit_client_name_hidden">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Discipline <span class="text-danger">*</span></label>
                            <div class="modern-other-container" id="edit_disciplineContainer">
                                <select class="form-select" name="discipline" id="edit_discipline" onchange="handleModernOther(this)" required>
                                    <option value="">Select Discipline</option>
                                    <option value="Electrical works">Electrical works</option>
                                    <option value="Civil Works">Civil Works</option>
                                    <option value="Building Work">Building Work</option>
                                    <option value="mechanical works">mechanical works</option>
                                    <option value="Telecommunication">Telecommunication</option>
                                    <option value="Renewable Energy works">Renewable Energy works</option>
                                    <option value="Other">Other (Specify...)</option>
                                </select>
                                <div class="modern-input-wrapper mt-2" style="display:none;">
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="discipline_other" id="edit_discipline_other" placeholder="Type discipline and press Enter">
                                        <button class="btn btn-outline-secondary" type="button" onclick="cancelModernOther(this)"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Position <span class="text-danger">*</span></label>
                            <div class="modern-other-container" id="edit_positionContainer">
                                <select class="form-select" name="role_position" id="edit_role_position" onchange="handleModernOther(this)" required>
                                    <option value="">Select Position</option>
                                    <option value="Main Contractor">Main Contractor</option>
                                    <option value="Sub Contractor">Sub Contractor</option>
                                    <option value="Supplier">Supplier</option>
                                    <option value="Other">Other (Specify...)</option>
                                </select>
                                <div class="modern-input-wrapper mt-2" style="display:none;">
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="role_position_other" id="edit_role_position_other" placeholder="Type position and press Enter">
                                        <button class="btn btn-outline-secondary" type="button" onclick="cancelModernOther(this)"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Contract Attachment</label>
                            <input type="file" class="form-control" name="contract_file">
                            <div id="edit_current_attachment" class="small mt-1"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Project Manager</label>
                            <input type="text" class="form-control" name="project_manager" id="edit_project_manager">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" id="edit_start_date" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">END DATE</label>
                            <input type="date" class="form-control" name="deadline" id="edit_deadline">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Priority</label>
                            <select class="form-select" name="priority" id="edit_priority">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="planning">Planning</option>
                                <option value="active">Active</option>
                                <option value="on_hold">On Hold</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



<!-- Edit Expense Modal -->
<div class="modal fade" id="expenseActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Expense Detail</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="expenseActionForm">
                <input type="hidden" name="expense_id">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Expense Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="expense_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Expense Type <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select class="form-select expense-type-sel" name="expense_type" id="edit_expense_type" required>
                                    <option value="">Select Type</option>
                                </select>
                                <button type="button" class="btn btn-outline-primary" onclick="openExpenseConfigModal()" title="Manage Types & Categories">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12 edit-expense-category-block" style="display: none;">
                            <label class="form-label fw-bold text-primary"><i class="bi bi-tags-fill me-1"></i>Expense Category</label>
                            <div id="proj_edit_cascade_container"></div>
                            <input type="hidden" name="category_id" id="proj_edit_selected_cat">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount (TZS) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-primary">TZS</span>
                                <input type="number" class="form-control fw-bold border-primary" name="amount" id="edit_ex_amount" step="0.01" required placeholder="0.00">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="edit_ex_bank_account_id" class="form-label fw-bold">Paid From <span class="text-danger">*</span></label>
                            <select class="form-select select2" name="bank_account_id" id="edit_ex_bank_account_id" required>
                                <option value="">Select account…</option>
                                <?php foreach (cashBankAccounts($pdo) as $acc): ?>
                                    <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars((!empty($acc['account_code']) ? $acc['account_code'] . ' — ' : '') . $acc['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">The cash/bank account the money is paid from.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Paid To Type</label>
                            <select class="form-select" name="paid_to_type" id="edit_ex_paid_to_type">
                                <option value="">General / Other</option>
                                <option value="supplier">Supplier</option>
                                <option value="staff">Staff (Employee)</option>
                                <option value="sub_contractor">Sub Contractor</option>
                            </select>
                        </div>

                        <div class="col-md-6 d-none" id="edit_paid_to_id_block">
                            <label class="form-label fw-bold" id="edit_paid_to_id_label">Payee</label>
                            <select class="form-select" name="paid_to_id" id="edit_paid_to_id_select">
                                <option value="">Select...</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" rows="3" required placeholder="Explain why this expense happened..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Additional details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm"><i class="bi bi-save me-1"></i> Update Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white p-4" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
                <div>
                    <h5 class="modal-title fw-bold mb-0"><i class="bi bi-wallet2 me-2"></i>Record Project Expense</h5>
                  
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addExpenseForm">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="ex_expense_date" class="form-label fw-bold">Expense Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="expense_date" id="ex_expense_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Expense Type <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select class="form-select expense-type-sel" name="expense_type" id="ex_expense_type" required>
                                    <option value="">Select Type</option>
                                </select>
                                <button type="button" class="btn btn-outline-primary" onclick="openExpenseConfigModal()" title="Manage Types & Categories">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12 add-expense-category-block" style="display: none;">
                            <label class="form-label fw-bold text-primary"><i class="bi bi-tags-fill me-1"></i>Expense Category</label>
                            <div id="proj_add_cascade_container"></div>
                            <input type="hidden" name="category_id" id="proj_add_selected_cat">
                        </div>

                        <div class="col-md-6">
                            <label for="ex_amount" class="form-label fw-bold">Amount (TZS) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-primary">TZS</span>
                                <input type="number" class="form-control fw-bold border-primary" name="amount" id="ex_amount" step="0.01" required placeholder="0.00">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="ex_bank_account_id" class="form-label fw-bold">Paid From <span class="text-danger">*</span></label>
                            <select class="form-select select2" name="bank_account_id" id="ex_bank_account_id" required>
                                <option value="">Select account…</option>
                                <?php foreach (cashBankAccounts($pdo) as $acc): ?>
                                    <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars((!empty($acc['account_code']) ? $acc['account_code'] . ' — ' : '') . $acc['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">The cash/bank account the money is paid from.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="ex_paid_to_type" class="form-label fw-bold">Paid To Type</label>
                            <select class="form-select" name="paid_to_type" id="ex_paid_to_type">
                                <option value="">General / Other</option>
                                <option value="supplier">Supplier</option>
                                <option value="staff">Staff (Employee)</option>
                                <option value="sub_contractor">Sub Contractor</option>
                            </select>
                        </div>

                        <div class="col-md-6 d-none" id="proj_paid_to_id_block">
                            <label class="form-label fw-bold" id="proj_paid_to_id_label">Payee</label>
                            <select class="form-select" name="paid_to_id" id="proj_paid_to_id_select">
                                <option value="">Select...</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="ex_description" class="form-label fw-bold">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" id="ex_description" rows="3" required placeholder="Explain why this expense happened "></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Additional details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm fw-bold" id="btnSaveExpense">
                        <i class="bi bi-save me-1"></i> Record Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== Create Payment Voucher Modal (Project-Aware) ===== -->
<div class="modal fade" id="createVoucherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white p-4" style="background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);">
                <div>
                    <h5 class="modal-title fw-bold mb-0"><i class="bi bi-receipt-cutoff me-2"></i>Create Payment Voucher</h5>
                    <small class="opacity-75">Sequential Financial Workflow: Link Expenses to Payments</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="createVoucherForm" enctype="multipart/form-data">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <div class="modal-body p-4">
                    <div class="row g-3">

                        <!-- Expense/Category Selection -->
                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="bi bi-link-45deg me-1 text-primary"></i>Link to Specific Project Expense / Category *</label>
                            <select class="form-select select2" name="expense_id" id="vc_expense_id" required onchange="vcOnExpenseChange(this.value)">
                                <option value="">⏳ Loading project data...</option>
                            </select>
                            <input type="hidden" name="category_id" id="vc_category_id_hidden">
                        </div>

                        <!-- Balance Info Alert (Hidden by default) -->
                        <div class="col-12" id="vc_balance_info_cont" style="display:none;">
                            <div class="alert alert-info border-0 shadow-sm d-flex justify-content-between align-items-center mb-0 py-2">
                                <div>
                                    <i class="bi bi-calculator me-2"></i>
                                    <span class="small fw-bold">Total Expense:</span> <span id="vc_total_exp" class="fw-bold">0.00</span> |
                                    <span class="small fw-bold">Paid So Far:</span> <span id="vc_already_paid" class="fw-bold">0.00</span>
                                </div>
                                <div class="text-end">
                                    <span class="small fw-bold">REMAINING:</span> 
                                    <span id="vc_remaining" class="badge bg-primary fs-6">0.00 TZS</span>
                                </div>
                            </div>
                        </div>

                        <!-- Payee Name -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payee Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" name="payee_name" id="vc_payee_name" placeholder="Who are we paying?" required>
                            </div>
                        </div>

                        <!-- Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Voucher Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" id="vc_date" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- Amount -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Amount (TZS) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold text-primary">TZS</span>
                                <input type="number" class="form-control fw-bold border-primary" name="amount" id="vc_amount" step="0.01" min="0.01" required placeholder="0.00" oninput="vcUpdateAmountWords(this.value)">
                            </div>
                            <small id="vc_amount_validation" class="text-danger fw-bold small mt-1 d-block" style="display:none;"></small>
                        </div>

                        <!-- Payment Method -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Method</label>
                            <select class="form-select" name="payment_method" id="vc_payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>

                        <!-- Amount in Words -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Amount in Words</label>
                            <div class="input-group">
                                <span class="input-group-text small fw-bold text-muted">WORDS</span>
                                <input type="text" class="form-control bg-light opacity-75" name="amount_in_words" id="vc_amount_words" placeholder="Auto-calculated..." readonly>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Description / Purpose <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" id="vc_description" rows="2" placeholder="Reference invoice, receipt, or reason for payment..." required></textarea>
                        </div>

                        <!-- Upload Proof -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><i class="bi bi-paperclip me-1"></i>Upload Receipt / Proof</label>
                            <input type="file" class="form-control" name="attachment" id="vc_attachment">
                            <small class="text-muted">Will appear in Project Docs.</small>
                        </div>

                        <!-- Reference Number -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Reference #</label>
                            <input type="text" class="form-control" name="reference" id="vc_reference" placeholder="Check/Trans ID/Receipt #">
                        </div>

                        <!-- Expense Account — same field as the external Payment Vouchers form.
                             Required for GL posting on approval (postVoucherAccrual needs it);
                             pre-filled from the linked expense's own account when one is picked,
                             but freely editable, matching the external form's behaviour. -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Expense Account</label>
                            <select class="form-select select2" name="expense_account_id" id="vc_expense_account_id">
                                <option value="">Select expense account</option>
                                <?php foreach ($expense_accounts as $ea): ?>
                                <option value="<?= (int)$ea['account_id'] ?>"><?= htmlspecialchars($ea['account_code'] . ' — ' . $ea['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">The cost is booked here (P&amp;L) when the voucher is approved.</small>
                        </div>

                    </div>
                </div>
                <div class="modal-footer bg-light p-3 border-top">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm fw-bold" id="btnSaveVoucher">
                        <i class="bi bi-check-all me-1"></i> Confirm & Generate Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Voucher Details Modal — same content/behaviour as payment_vouchers.php's #detailsModal -->
<div class="modal fade" id="pvDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <div class="modal-header border-0 pb-0 pe-4 pt-4">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle me-3">
                        <i class="bi bi-file-earmark-text text-primary fs-4"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Payment Voucher Details</h5>
                        <p class="text-muted small mb-0" id="pv_detail_voucher_no">#PV-00000</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-2">
                <div class="row g-4 mb-4">
                    <div class="col-md-7">
                        <div class="bg-light p-4 rounded-4 h-100">
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Status &amp; Method</label>
                            <div class="d-flex gap-2 mb-3">
                                <div id="pv_detail_status_badge"></div>
                                <div id="pv_detail_method_badge"></div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <label class="small text-muted text-uppercase fw-bold d-block mb-1">Voucher Date</label>
                                    <p class="fw-bold fs-6 mb-3" id="pv_detail_date"></p>
                                </div>
                                <div class="col-6">
                                    <label class="small text-muted text-uppercase fw-bold d-block mb-1">Reference No.</label>
                                    <p class="fw-bold mb-3" id="pv_detail_reference"></p>
                                </div>
                            </div>
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Payee (Pay To)</label>
                            <p class="fw-bold mb-3 fs-5 text-dark" id="pv_detail_payee"></p>
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Expense Category</label>
                            <p class="fw-bold mb-0" id="pv_detail_category"></p>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="p-4 rounded-4 text-center h-100 d-flex flex-column justify-content-center" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                            <label class="small text-primary text-uppercase fw-bold d-block mb-2">Total Amount</label>
                            <h2 class="fw-bold text-primary mb-2" id="pv_detail_amount" style="white-space:nowrap;line-height:1.2;">...</h2>
                            <p id="pv_detail_words" class="text-muted small mb-0 border-top pt-2 mt-2" style="word-break:break-word;"></p>
                            <div class="mt-3 text-start">
                                <label class="small text-muted text-uppercase fw-bold d-block mb-1">Project</label>
                                <p class="badge bg-white text-dark border w-100 py-2 mb-0" id="pv_detail_project"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-light rounded-4">
                    <label class="small text-muted text-uppercase fw-bold d-block mb-2">Description / Narration</label>
                    <p class="mb-0 fs-6 text-dark lh-base" id="pv_detail_description" style="white-space:pre-wrap;font-style:italic;"></p>
                </div>

                <!-- Payment History -->
                <div class="mt-3 p-4 bg-light rounded-4" id="pv_detail_payments_section" style="display:none;">
                    <label class="small text-muted text-uppercase fw-bold d-block mb-2">
                        <i class="bi bi-clock-history me-1"></i>Payment History
                    </label>
                    <div id="pv_detail_payments_list"></div>
                </div>

                <div class="mt-4 px-2">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="py-2 border-bottom mb-2"><small class="text-muted">Prepared By</small></div>
                            <p class="fw-bold small mb-0" id="pv_detail_user">System</p>
                        </div>
                        <div class="col-4">
                            <div class="py-2 border-bottom mb-2"><small class="text-muted">Checked By</small></div>
                            <p class="small text-muted mb-0">________________</p>
                        </div>
                        <div class="col-4">
                            <div class="py-2 border-bottom mb-2"><small class="text-muted">Authorized By</small></div>
                            <p class="small text-muted mb-0">________________</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light py-3" style="border-bottom-left-radius:20px;border-bottom-right-radius:20px;">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary px-4 fw-bold" id="pv_detail_print_btn">
                    <i class="bi bi-printer me-2"></i>Print Voucher
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Pay Voucher Modal — same fields/endpoint as payment_vouchers.php's #payVoucherModal -->
<div class="modal fade" id="payVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="payVoucherForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="id" id="pay_voucher_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                    <div class="rounded p-3 mb-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Voucher</span><strong id="pay_voucher_no">—</strong>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Payee</span><strong id="pay_payee">—</strong>
                        </div>
                        <div class="d-flex justify-content-between small mt-1">
                            <span class="text-muted">Total Amount</span><strong id="pay_amount">—</strong>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Already Paid</span><strong class="text-success" id="pay_already_paid">—</strong>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <span class="text-muted fw-bold">Outstanding Balance</span>
                            <strong class="text-danger fs-5" id="pay_balance_due">—</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Amount to Pay Now <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">TZS</span>
                            <input type="number" class="form-control border-start-0 fw-bold" name="payment_amount"
                                   id="pay_payment_amount" step="0.01" min="0.01" required>
                        </div>
                        <small class="text-muted">Enter less than the balance to record a partial payment.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Paid From (Bank/Cash) <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="paid_from_account_id" id="pay_paid_from" required>
                            <option value="">Select cash/bank account…</option>
                            <?php foreach (cashBankAccounts($pdo) as $cb): ?>
                                <option value="<?= (int)$cb['account_id'] ?>"><?= htmlspecialchars(($cb['account_code'] ? $cb['account_code'] . ' — ' : '') . $cb['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">The cash/bank account the money leaves from (Cr on the GL).</small>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" id="pay_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">Method</label>
                            <select class="form-select" name="payment_method" id="pay_method">
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Reference (Cheque/Txn No.)</label>
                            <input type="text" class="form-control" name="payment_reference" id="pay_reference" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Payment Proof (optional)</label>
                            <input type="file" class="form-control" name="attachment_file" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small text-muted">
                        <i class="bi bi-info-circle me-1"></i> Posts <strong>Dr Accrued Expenses / Cr Bank</strong> to the GL for this payment amount.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     PROC: ADD MATERIALS MODAL (Project-scoped, matches procurement>materials)
═══════════════════════════════════════════════════ -->
<div class="modal fade" id="procAddNipMaterialsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-3" style="background:#0d6efd;">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>CREATE MATERIALS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="procAddNipMaterialsForm" style="display:flex;flex-direction:column;overflow:hidden;flex:1 1 auto;min-height:0;">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <div class="modal-body p-4" style="overflow-y:auto;flex:1 1 auto;">
                    <div id="procMlAddMsg" class="mb-3"></div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Material List Name <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="name" id="procMlAddName" rows="2" required
                            placeholder="e.g. Foundation Materials for Block A"
                            style="resize:vertical;white-space:pre-wrap;word-wrap:break-word;"></textarea>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Project</label>
                            <input type="text" class="form-control form-control-sm bg-light" readonly
                                value="<?= htmlspecialchars($project_name ?? '') ?>">
                            <div class="form-text">Linked to this project automatically.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Warehouse</label>
                            <select name="warehouse_id" id="procMlAddWarehouse" class="form-select form-select-sm" onchange="procMlAddWarehouseChanged()">
                                <option value="">— Loading… —</option>
                            </select>
                        </div>
                    </div>
                    <h6 class="fw-bold small text-uppercase text-muted mb-2"><i class="bi bi-list-ul me-1"></i>Non-Inventory Products</h6>
                    <div class="table-responsive rounded-3 border" style="overflow-x:auto;overflow-y:visible;">
                        <table class="table table-hover align-middle mb-0" id="procMlAddTable">
                            <thead class="text-white text-center" style="background:#0d6efd;">
                                <tr class="small">
                                    <th style="width:55px;">S/NO</th>
                                    <th class="text-start ps-3">Non-Inventory Product</th>
                                    <th style="width:20%;">Quantity</th>
                                    <th style="width:55px;"></th>
                                </tr>
                            </thead>
                            <tbody id="procMlAddTbody"></tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="4" class="ps-3 py-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="procMlAddRow()">
                                            <i class="bi bi-plus-circle me-1"></i> Add NIP
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="procMlAddSaveBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Materials
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     PROC: ML VIEW DETAILS MODAL
═══════════════════════════════════════════════════ -->
<div class="modal fade" id="procMlViewDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-3" style="background:#0d6efd;">
                <h5 class="modal-title fw-bold" id="procMlViewDetailsTitle">
                    <i class="bi bi-layout-text-window me-2"></i>Material Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="procMlViewDetailsBody">
                <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer bg-white border-top d-flex justify-content-between">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary shadow-sm fw-bold" onclick="procMlPrintDetails()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     EDIT NIP PRODUCT MODAL (Project-scoped)
═══════════════════════════════════════════════════ -->
<div class="modal fade" id="editProcNipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Non-Inventory Product</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editProcNipForm">
                <input type="hidden" name="product_id" id="editProcNipId">
                <input type="hidden" name="is_service" value="1">
                <input type="hidden" name="track_inventory" value="0">
                <div class="modal-body p-4">
                    <div id="editProcNipMsg" class="mb-3"></div>

                    <!-- Product Name header -->
                    <div class="p-3 bg-light rounded border mb-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="nip-product-avatar" style="width:52px;height:52px;font-size:1.4rem;">
                                <i class="bi bi-gear text-primary"></i>
                            </div>
                            <div class="flex-grow-1">
                                <label class="form-label fw-bold small mb-1">Non-Inventory Product Name <span class="text-danger">*</span></label>
                                <textarea class="form-control form-control-lg bg-white border shadow-sm fw-bold" name="product_name" id="editProcNipName" required rows="2"></textarea>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">SKU / Item Code</label>
                                <input type="text" class="form-control form-control-sm bg-light" id="editProcNipSku" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Contract Item No</label>
                                <input type="text" class="form-control form-control-sm" name="contract_item_no" id="editProcNipContractNo">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Status</label>
                                <select class="form-select form-select-sm" name="status" id="editProcNipStatus">
                                    <option value="active">Active</option>
                                    <option value="approved">Approved</option>
                                    <option value="pending">Pending</option>
                                    <option value="draft">Draft</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 1: Selling Price | Cost Price | Project (read-only) -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Selling Price <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">TZS</span>
                                <input type="number" class="form-control fw-bold" name="selling_price" id="editProcNipSell" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Cost Price (Auto-Sum)</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">TZS</span>
                                <input type="number" class="form-control bg-light" name="cost_price" id="editProcNipCost" step="0.01" readonly>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Project</label>
                            <input type="text" class="form-control form-control-sm bg-light" id="editProcNipProjectDisplay" readonly>
                            <input type="hidden" name="project_id" id="editProcNipProjectId">
                        </div>
                    </div>

                    <!-- Row 2: Tax Rate | Warehouse (same row) -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Tax Rate</label>
                            <select class="form-select form-select-sm" name="tax_id" id="editProcNipTax" onchange="editProcNipRecalcCost()">
                                <option value="" data-rate="0">No Tax</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Warehouse</label>
                            <select class="form-select form-select-sm" name="warehouse_id" id="editProcNipWarehouse">
                                <option value="">— Select Warehouse —</option>
                            </select>
                        </div>
                    </div>

                    <!-- Components -->
                    <h6 class="fw-bold border-bottom pb-2 mb-3">Material Components</h6>
                    <div class="table-responsive mb-2">
                        <table class="table table-bordered table-sm align-middle" id="editProcNipCompTable">
                            <thead class="bg-white small">
                                <tr>
                                    <th style="width:5%">S/No</th>
                                    <th style="width:55%">Materials Description</th>
                                    <th style="width:14%">Unit</th>
                                    <th style="width:16%">Qty / Unit</th>
                                    <th style="width:10%"></th>
                                </tr>
                            </thead>
                            <tbody id="editProcNipCompBody"></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-start mb-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="editProcNipAddRow()">
                            <i class="bi bi-plus-circle me-1"></i> Add Row
                        </button>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="editProcNipSaveBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     PROC: ML EDIT MODAL
═══════════════════════════════════════════════════ -->
<div class="modal fade" id="procMlEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-3" style="background:#0d6efd;">
                <h5 class="modal-title fw-bold" id="procMlEditTitle">
                    <i class="bi bi-pencil me-2"></i>Edit Materials
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="procMlEditForm" style="display:flex;flex-direction:column;overflow:hidden;flex:1 1 auto;min-height:0;">
                <div class="modal-body p-4" style="overflow-y:auto;flex:1 1 auto;" id="procMlEditBody">
                    <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
                </div>
                <div class="modal-footer bg-white border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="procMlEditSaveBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ADD NON-INVENTORY PRODUCT MODAL (Project-scoped)
════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="projNipAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add Non-Inventory Product
                    <span class="badge bg-white bg-opacity-25 text-white ms-2 small">Non-Inventory</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="projNipAddForm" enctype="multipart/form-data" autocomplete="off" style="display:flex;flex-direction:column;overflow:hidden;flex:1 1 auto;min-height:0;">
                <input type="hidden" name="is_service" value="1">
                <input type="hidden" name="track_inventory" value="0">
                <input type="hidden" name="status" value="active">
                <div class="modal-body p-4" style="overflow-y:auto;flex:1 1 auto;">
                    <div id="projNipAddMsg" class="mb-3"></div>
                    <!-- Step nav -->
                    <div class="d-flex gap-4 mb-4 border-bottom pb-2 px-1">
                        <h6 class="fw-bold mb-0 pb-2" id="projNipTab1" onclick="projNipToggleStep(1)" style="color:#0d6efd;border-bottom:2px solid #0d6efd;cursor:pointer;">
                            <i class="bi bi-info-circle me-2"></i>Product Identity
                        </h6>
                        <h6 class="fw-bold mb-0 pb-2" id="projNipTab2" onclick="projNipToggleStep(2)" style="color:#000;cursor:pointer;">
                            <i class="bi bi-cash-stack me-2"></i>Pricing &amp; Planning
                        </h6>
                    </div>
                    <!-- Step 1: Identity -->
                    <div id="projNipStep1">
                        <div class="row g-4 mb-4">
                            <div class="col-md-7 border-end pe-md-4">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold small">Non-Inventory Product Name <span class="text-danger">*</span></label>
                                        <textarea class="form-control form-control-lg bg-light border-0 shadow-sm" name="product_name" required rows="2" placeholder="e.g. Consulting, Delivery Charge"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold small">Description</label>
                                        <textarea class="form-control bg-light border-0" name="description" rows="2" placeholder="Describe this product..."></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Item Code</label>
                                        <input type="text" class="form-control form-control-sm border-0 bg-light fw-bold" name="contract_item_no" placeholder="e.g. ITEM-001">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Unit</label>
                                        <div id="projNipAddUnitContainer">
                                            <select class="form-select form-select-sm fw-bold border border-secondary border-opacity-25" name="unit" id="projNipAddUnitSelect" onchange="projNipCheckOtherUnit(this,'projNipAddUnitContainer')">
                                                <option value="job">Job</option>
                                                <option value="pcs">Pieces</option>
                                                <option value="set">Set</option>
                                                <option value="box">Box</option>
                                                <option value="ltr">Litre</option>
                                                <option value="kg">Kg</option>
                                                <option value="other">Other (specify)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Qty</label>
                                        <input type="number" class="form-control form-control-sm bg-secondary bg-opacity-10 fw-bold" name="assembly_quantity" id="projNipAddAsmQty" value="1" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-5 ps-md-4">
                                <div class="p-4 bg-primary bg-opacity-10 rounded-4 h-100 border border-primary border-opacity-10">
                                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Non-Inventory Product</h5>
                                    <p class="small text-muted mb-3">This product will be available in:</p>
                                    <ul class="list-unstyled small">
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Sales Orders</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Invoices</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>POS</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Budget</li>
                                        <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i>Warehouse / GRN</li>
                                        <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i>Stock Tracking</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Step 2: Pricing & Planning -->
                    <div id="projNipStep2" style="display:none;">
                        <div class="row g-3 mb-4 p-3 bg-white rounded-3 shadow-sm border border-primary border-opacity-10">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-success">Selling Price <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-success text-white">TZS</span>
                                    <input type="number" class="form-control border-0 bg-light fw-bold text-success" name="selling_price" id="projNipAddSell" value="0" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Cost Price (Auto-Sum)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-light">TZS</span>
                                    <input type="number" class="form-control border-0 bg-secondary bg-opacity-10 fw-bold" name="cost_price" id="projNipAddCostSum" value="0" step="0.01" readonly>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Tax Rate</label>
                                <select class="form-select form-select-sm border-0 bg-light" name="tax_id">
                                    <option value="">No Tax</option>
                                    <?php foreach ($tax_rates as $tx): ?>
                                    <option value="<?= $tx['rate_id'] ?>"><?= htmlspecialchars($tx['rate_name']) ?> (<?= $tx['rate_percentage'] ?>%)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-2 pt-3 border-top">
                                <label class="form-label fw-bold small">Select Warehouse <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm fw-bold text-primary shadow-sm border border-primary border-opacity-25" id="projNipAddWarehouseId" onchange="projNipAddRefreshCosts()">
                                    <option value="">— Select Warehouse —</option>
                                </select>
                                <input type="hidden" name="project_id" id="projNipAddProjectId" value="<?= $project_id ?>">
                                <div class="form-text text-muted small">Select a warehouse to filter component products. The NIP product itself is not stored in any warehouse.</div>
                            </div>
                        </div>
                        <!-- Components table -->
                        <h6 class="fw-bold small text-uppercase text-muted mb-2"><i class="bi bi-list-ul me-1"></i>Material Components</h6>
                        <div class="table-responsive rounded-3 bg-white shadow-sm border" style="overflow-x:auto;overflow-y:visible;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-dark text-white text-center">
                                    <tr class="small">
                                        <th style="width:50px;">S/NO</th>
                                        <th class="text-start ps-3">Materials Description</th>
                                        <th style="width:12%;">Unit</th>
                                        <th style="width:14%;">Qty / Unit</th>
                                        <th style="width:7%;"></th>
                                    </tr>
                                </thead>
                                <tbody id="projNipAddCompBody"></tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="5" class="ps-3 py-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="projNipAddCompRow()">
                                                <i class="bi bi-plus-circle me-1"></i> Add Row
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold" id="projNipSaveBtn">
                        <i class="bi bi-check-circle me-1"></i> Create Product
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EDIT NON-INVENTORY PRODUCT MODAL (Project-scoped)
════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="projNipEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Non-Inventory Product
                    <span class="badge bg-white bg-opacity-25 text-white ms-2 small">Non-Inventory</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="projNipEditForm" enctype="multipart/form-data" autocomplete="off" style="display:flex;flex-direction:column;overflow:hidden;flex:1 1 auto;min-height:0;">
                <input type="hidden" name="product_id" id="projNipEditId">
                <input type="hidden" name="is_service" value="1">
                <input type="hidden" name="track_inventory" value="0">
                <div class="modal-body p-4" style="overflow-y:auto;flex:1 1 auto;">
                    <div id="projNipEditMsg" class="mb-3"></div>
                    <!-- Step nav -->
                    <div class="d-flex gap-4 mb-4 border-bottom pb-2 px-1">
                        <h6 class="fw-bold mb-0 pb-2" id="projNipEditTab1" onclick="projNipEditToggleStep(1)" style="color:#0d6efd;border-bottom:2px solid #0d6efd;cursor:pointer;">
                            <i class="bi bi-info-circle me-2"></i>Product Identity
                        </h6>
                        <h6 class="fw-bold mb-0 pb-2" id="projNipEditTab2" onclick="projNipEditToggleStep(2)" style="color:#000;cursor:pointer;">
                            <i class="bi bi-cash-stack me-2"></i>Pricing &amp; Planning
                        </h6>
                    </div>
                    <!-- Step 1: Identity -->
                    <div id="projNipEditStep1">
                        <div class="row g-4 mb-4">
                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold small">Non-Inventory Product Name <span class="text-danger">*</span></label>
                                        <textarea class="form-control form-control-lg bg-light border-0 shadow-sm" name="product_name" id="projNipEditName" required rows="2"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold small">Description</label>
                                        <textarea class="form-control bg-light border-0" name="description" id="projNipEditDesc" rows="2"></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Item Code</label>
                                        <input type="text" class="form-control form-control-sm border-0 bg-light fw-bold" name="contract_item_no" id="projNipEditContractNo">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Unit</label>
                                        <div id="projNipEditUnitContainer">
                                            <select class="form-select form-select-sm fw-bold border border-secondary border-opacity-25" name="unit" id="projNipEditUnitSelect" onchange="projNipCheckOtherUnit(this,'projNipEditUnitContainer')">
                                                <option value="job">Job</option>
                                                <option value="pcs">Pieces</option>
                                                <option value="set">Set</option>
                                                <option value="box">Box</option>
                                                <option value="ltr">Litre</option>
                                                <option value="kg">Kg</option>
                                                <option value="other">Other (specify)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Status</label>
                                        <select class="form-select form-select-sm" name="status" id="projNipEditStatus">
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                            <option value="approved">Approved</option>
                                            <option value="pending">Pending</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Qty</label>
                                        <input type="number" class="form-control form-control-sm bg-secondary bg-opacity-10 fw-bold" name="assembly_quantity" id="projNipEditAsmQty" value="1" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-4 bg-primary bg-opacity-10 rounded-4 h-100 border border-primary border-opacity-10">
                                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Non-Inventory Product</h5>
                                    <p class="small text-muted mb-3">This product is available in:</p>
                                    <ul class="list-unstyled small">
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Sales Orders</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Invoices</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>POS</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Budget</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Step 2: Pricing & Planning -->
                    <div id="projNipEditStep2" style="display:none;">
                        <div class="row g-3 mb-4 p-3 bg-white rounded-3 shadow-sm border border-primary border-opacity-10">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-success">Selling Price <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-success text-white">TZS</span>
                                    <input type="number" class="form-control border-0 bg-light fw-bold text-success" name="selling_price" id="projNipEditSell" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Cost Price (Auto-Sum)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-light">TZS</span>
                                    <input type="number" class="form-control border-0 bg-secondary bg-opacity-10 fw-bold" name="cost_price" id="projNipEditCost" step="0.01" readonly>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Tax Rate</label>
                                <select class="form-select form-select-sm border-0 bg-light" name="tax_id" id="projNipEditTax">
                                    <option value="">No Tax</option>
                                    <?php foreach ($tax_rates as $tx): ?>
                                    <option value="<?= $tx['rate_id'] ?>"><?= htmlspecialchars($tx['rate_name']) ?> (<?= $tx['rate_percentage'] ?>%)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-2 pt-3 border-top">
                                <label class="form-label fw-bold small">Select Warehouse <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm fw-bold text-primary shadow-sm border border-primary border-opacity-25" id="projNipEditWarehouseId" onchange="projNipEditRefreshCosts()">
                                    <option value="">— Select Warehouse —</option>
                                </select>
                                <input type="hidden" name="project_id" id="projNipEditProjectId" value="<?= $project_id ?>">
                                <div class="form-text text-muted small">Select a warehouse to filter component products. The NIP product itself is not stored in any warehouse.</div>
                            </div>
                        </div>
                        <!-- Components table -->
                        <h6 class="fw-bold small text-uppercase text-muted mb-2"><i class="bi bi-list-ul me-1"></i>Material Components</h6>
                        <div class="table-responsive rounded-3 bg-white shadow-sm border" style="overflow-x:auto;overflow-y:visible;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-dark text-white text-center">
                                    <tr class="small">
                                        <th style="width:50px;">S/NO</th>
                                        <th class="text-start ps-3">Materials Description</th>
                                        <th style="width:12%;">Unit</th>
                                        <th style="width:14%;">Qty / Unit</th>
                                        <th style="width:7%;"></th>
                                    </tr>
                                </thead>
                                <tbody id="projNipEditCompBody"></tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="5" class="ps-3 py-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="projNipEditAddCompRow()">
                                                <i class="bi bi-plus-circle me-1"></i> Add Row
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold" id="projNipEditSaveBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Goods Return Modal -->
<div class="modal fade" id="createReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-arrow-return-left me-2"></i>Create Goods Return Note</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="createReturnForm">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Return Note No. <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="return_number" id="returnNumber" placeholder="e.g. GRN-RET-001" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Return Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="return_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Warehouse <span class="text-danger">*</span></label>
                            <select class="form-select" name="warehouse_id" id="returnWarehouseId" onchange="loadReturnSuppliers(this.value)" required>
                                <option value="">Select Warehouse</option>
                                <?php foreach ($warehouses as $w): ?>
                                    <option value="<?= $w['warehouse_id'] ?>"><?= htmlspecialchars($w['warehouse_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select" name="supplier_id" id="returnSupplierId" onchange="loadReturnGRNs(this.value)" required disabled>
                                <option value="">Select Warehouse First</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">GRN <span class="text-danger">*</span></label>
                            <select class="form-select" name="receipt_id" id="returnReceiptId" onchange="loadGRNItems(this.value)" required disabled>
                                <option value="">Select Supplier First</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Reason for Return <span class="text-danger">*</span></label>
                            <select class="form-select" name="return_reason" id="return_reason_select" onchange="toggleReturnReasonOther(this)" required>
                                <option value="">Select Reason</option>
                                <option value="damaged">Damaged Goods</option>
                                <option value="wrong_item">Wrong Item Delivered</option>
                                <option value="excess_quantity">Excess Quantity</option>
                                <option value="poor_quality">Poor Quality</option>
                                <option value="expired">Expired Goods</option>
                                <option value="other">Other</option>
                            </select>
                            <div id="other_return_reason_div" class="mt-2" style="display: none;">
                                <input type="text" class="form-control" id="other_return_reason" placeholder="Specify other reason...">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Items Being Returned</label>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm" id="returnItemsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width: 50px;">S/NO</th>
                                            <th>Product/Item <span class="text-danger">*</span></th>
                                            <th style="width:120px;">SKU/Barcode</th>
                                            <th style="width:100px;">Quantity <span class="text-danger">*</span></th>
                                            <th style="width:80px;">Unit</th>
                                            <th style="width:120px;">Unit Price</th>
                                            <th style="width:120px;">Total</th>
                                            <th style="width:40px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="returnItemsBody">
                                        <tr class="empty-row">
                                            <td colspan="8" class="text-center text-muted py-3">Select a GRN to populate items</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addReturnItemRow()">
                                <i class="bi bi-plus-circle me-1"></i> Add Item
                            </button>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Notes / Remarks</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes about this return..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-circle me-1"></i> Save Return Note
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Staff Modal -->
<div class="modal fade" id="assignStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Assign Staff to Project</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="assignStaffForm">
                <div class="modal-body p-4">
                    <p class="text-muted small">Select an available employee to assign to this project team.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Employee</label>
                        <select class="form-select select2" id="assign_employee_id" required>
                            <!-- Populated dynamically -->
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="btnConfirmAssignStaff">Confirm Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ===== HR: Apply / Edit Leave Modal ===== -->
<div class="modal fade" id="applyLeaveModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold" id="applyLeaveModalTitle"><i class="bi bi-calendar-x me-2"></i>Apply for Leave</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="applyLeaveForm" enctype="multipart/form-data">
                <input type="hidden" id="lv_leave_id" name="leave_id" value="">
                <div class="modal-body p-4">
                    <div id="lv-leave-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Staff Member <span class="text-danger">*</span></label>
                            <select class="form-select" id="lv_employee_id" name="employee_id" required onchange="lvUpdateLeaveBalance()">
                                <option value="">Select Staff</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Leave Type <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" id="lv_type" name="leave_type" required onchange="lvUpdateLeaveTypeInfo()">
                                <option value="">Select Type</option>
                                <?php
                                $lv_enum_map = ['Annual Leave'=>'annual','Sick Leave'=>'sick','Maternity Leave'=>'maternity','Paternity Leave'=>'paternity','Study Leave'=>'study','Unpaid Leave'=>'unpaid','Compassionate Leave'=>'other'];
                                foreach ($hr_leave_types as $lt):
                                    $lv_enum = $lv_enum_map[$lt['type_name']] ?? 'other';
                                ?>
                                <option value="<?= $lv_enum ?>"
                                        data-type-name="<?= htmlspecialchars($lt['type_name']) ?>"
                                        data-max-days="<?= intval($lt['max_days_per_year']) ?>"
                                        data-requires-doc="<?= intval($lt['requires_document']) ?>">
                                    <?= htmlspecialchars($lt['type_name']) ?> (Max: <?= intval($lt['max_days_per_year']) ?> days/year)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="lv_start_date" name="start_date" required onchange="lvCalculateDays()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="lv_end_date" name="end_date" required onchange="lvCalculateDays()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Total Days</label>
                            <input type="number" class="form-control" id="lv_total_days" name="total_days" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Half Day</label>
                            <select class="form-select" id="lv_half_day" name="half_day" onchange="lvCalculateDays()">
                                <option value="">No</option>
                                <option value="first_half">First Half</option>
                                <option value="second_half">Second Half</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Leave Pay</label>
                            <select class="form-select" id="lv_is_paid" name="is_paid">
                                <option value="1">Paid Leave</option>
                                <option value="0">Unpaid Leave</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Reason for Leave <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="lv_reason" name="reason" rows="3" required placeholder="Please provide a reason for your leave"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Additional Notes</label>
                            <textarea class="form-control" id="lv_notes" name="notes" rows="2" placeholder="Any additional information or notes"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Contact During Leave</label>
                            <input type="text" class="form-control" id="lv_contact" name="contact_during_leave" placeholder="Phone number or email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Handover To</label>
                            <select class="form-select" id="lv_handover_to" name="handover_to">
                                <option value="">Select Colleague</option>
                            </select>
                        </div>
                        <div class="col-12" id="lv_documentSection" style="display:none;">
                            <label class="form-label fw-bold">Supporting Document</label>
                            <input type="file" class="form-control" id="lv_document" name="document" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="text-muted">Upload supporting document (e.g., medical certificate)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" id="lv_status" name="status">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="card border">
                                <div class="card-header bg-light py-2">
                                    <h6 class="mb-0 fw-bold small"><i class="bi bi-info-circle me-1"></i>Leave Balance Information</h6>
                                </div>
                                <div class="card-body py-3" id="lv_balanceInfo">
                                    <p class="text-muted mb-0 small">Select a staff member and leave type to view balance.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="btnSaveLeave"><i class="bi bi-check-circle me-1"></i> Submit Leave Application</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== HR: View Leave Modal ===== -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary"><i class="bi bi-calendar-x me-2"></i>Leave Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewLeaveBody">
                <div class="text-center py-3"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer bg-light p-3">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== HR: Process Payroll Modal ===== -->
<div class="modal fade" id="processPayrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-gear-fill me-2"></i>Process Project Payroll</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="processPayrollForm">
                <div class="modal-body p-4 bg-light">
                    <div id="process-payroll-message"></div>
                    <div class="alert alert-info border-0 small py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i> Payroll will be processed <strong>only for staff assigned to this project</strong>.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Payroll Period <span class="text-danger">*</span></label>
                            <input type="month" class="form-control form-control-lg rounded-3 border-0 shadow-sm" id="pr_period" name="payroll_period" required onchange="prPreviewPayroll()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Reference Date</label>
                            <input type="date" class="form-control form-control-lg rounded-3 border-0 shadow-sm" id="pr_ref_date" name="payroll_date">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Filter Department</label>
                            <select class="form-select form-control-lg rounded-3 border-0 shadow-sm" id="pr_department" name="department_id" onchange="prPreviewPayroll()">
                                <option value="">All Departments</option>
                                <?php foreach ($hr_departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Employment Status</label>
                            <select class="form-select form-control-lg rounded-3 border-0 shadow-sm" id="pr_emp_status" name="employment_status" onchange="prPreviewPayroll()">
                                <option value="">All Active</option>
                                <option value="active">Active</option>
                                <option value="probation">Probation</option>
                                <option value="contract">Contract</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mt-3 g-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="pr_allowances" name="include_allowances" checked onchange="prPreviewPayroll()">
                                <label class="form-check-label fw-bold text-muted small" for="pr_allowances">INCLUDE ALLOWANCES</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="pr_deductions" name="include_deductions" checked onchange="prPreviewPayroll()">
                                <label class="form-check-label fw-bold text-muted small" for="pr_deductions">INCLUDE DEDUCTIONS & TAX</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="pr_attendance" name="consider_attendance" onchange="prPreviewPayroll()">
                                <label class="form-check-label fw-bold text-muted small" for="pr_attendance">CONSIDER ATTENDANCE</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="pr_auto_approve" name="auto_approve">
                                <label class="form-check-label fw-bold text-muted small" for="pr_auto_approve">AUTO-APPROVE RESULTS</label>
                            </div>
                        </div>
                    </div>
                    <div id="prPayrollPreview" class="mt-4 p-3 rounded-4 bg-white shadow-sm border" style="display:none;">
                        <h6 class="fw-bold mb-3 d-flex justify-content-between text-success">
                            <span>Calculation Preview</span>
                            <span class="badge px-3 rounded-pill" id="prPreviewCount" style="background-color:#d1e7dd; color:#0f5132;">0 Employees</span>
                        </h6>
                        <div class="table-responsive" style="max-height:280px;">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr class="small text-muted text-uppercase">
                                        <th>Employee</th>
                                        <th class="text-end">Basic</th>
                                        <th class="text-end">Allowances</th>
                                        <th class="text-end">Deductions</th>
                                        <th class="text-end">Net</th>
                                    </tr>
                                </thead>
                                <tbody id="prPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light">
                    <button type="button" class="btn btn-outline-success rounded-pill px-4" onclick="prPreviewPayroll()">
                        <i class="bi bi-eye me-1"></i> Refresh Preview
                    </button>
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 shadow" id="btnProcessPayroll">
                        <i class="bi bi-check2-circle me-2"></i>Execute Final Processing
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== HR: Edit Payroll Modal ===== -->
<div class="modal fade" id="editPayrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Payroll Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPayrollForm">
                <input type="hidden" id="ep_payroll_id" name="payroll_id">
                <div class="modal-body p-4 bg-light">
                    <div id="edit-payroll-message"></div>
                    <!-- Staff / Period info -->
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Staff Member</label>
                            <input type="text" class="form-control rounded-3 border-0 shadow-sm" id="ep_staff_name" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Period</label>
                            <input type="text" class="form-control rounded-3 border-0 shadow-sm" id="ep_period_display" readonly>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Basic Salary</label>
                            <input type="number" step="0.01" class="form-control rounded-3 border-0 shadow-sm ep-calc" id="ep_basic_salary" name="basic_salary" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Allowances</label>
                            <input type="number" step="0.01" class="form-control rounded-3 border-0 shadow-sm ep-calc" id="ep_allowances" name="allowances" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Deductions</label>
                            <input type="number" step="0.01" class="form-control rounded-3 border-0 shadow-sm ep-calc" id="ep_deductions" name="deductions" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Tax Amount</label>
                            <input type="number" step="0.01" class="form-control rounded-3 border-0 shadow-sm ep-calc" id="ep_tax_amount" name="tax_amount" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Payment Method</label>
                            <select class="form-select rounded-3 border-0 shadow-sm" id="ep_payment_method" name="payment_method">
                                <option value="bank">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="check">Check</option>
                                <option value="mobile">Mobile Money</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Payment Status</label>
                            <select class="form-select rounded-3 border-0 shadow-sm" id="ep_status" name="payment_status">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="paid">Paid</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-uppercase text-muted">Notes</label>
                            <textarea class="form-control rounded-3 border-0 shadow-sm" id="ep_notes" name="notes" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                        <!-- Live Net Preview -->
                        <div class="col-12">
                            <div class="p-3 rounded-3 bg-white shadow-sm border d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-muted small text-uppercase">Computed Net Salary</span>
                                <span class="fw-bold fs-5 text-success" id="ep_net_preview">0.00 TZS</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 shadow"><i class="bi bi-check2-circle me-2"></i>Update Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== HR: View Payroll / Payslip Modal ===== -->
<div class="modal fade" id="viewPayrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary"><i class="bi bi-receipt me-2"></i>Payslip</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewPayrollBody">
                <div class="text-center py-3"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer bg-light p-3">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                <a href="#" target="_blank" id="printPayslipBtn" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i> Print Payslip</a>
            </div>
        </div>
    </div>
</div>

<!-- Add Budget Item Modal -->
<div class="modal fade" id="addBudgetItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title fw-bold" id="addBudgetItemModalTitle"><i class="bi bi-plus-circle me-2"></i>Add Project Budget Item</h5>
                <div class="form-check form-switch ms-3 mb-0" id="proj_budget_svc_toggle_wrap">
                    <input class="form-check-input" type="checkbox" id="proj_budget_is_service" onchange="toggleProjectBudgetMode()">
                    <label class="form-check-label fw-bold text-white small" for="proj_budget_is_service">
                        <i class="bi bi-box-seam me-1"></i> Non-Inventory
                    </label>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <form id="addBudgetForm">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="budget_id" id="budget_id_field">
                <input type="hidden" name="budget_is_service_value" id="proj_budget_is_service_value" value="0">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Year <span class="text-danger">*</span></label>
                            <select class="form-select" name="budget_year" id="proj_budget_year" required>
                                <?php foreach ($budget_years as $y => $yLabel): ?>
                                <option value="<?= $y ?>" <?= $y == $budget_selected_year ? 'selected' : '' ?>><?= $yLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Month <span class="text-danger">*</span></label>
                            <select class="form-select" name="budget_month" id="proj_budget_month" required>
                                <?php foreach ($budget_months as $m => $mLabel): ?>
                                <option value="<?= $m ?>" <?= $m == $budget_selected_month ? 'selected' : '' ?>><?= $mLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Budget Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="category_other" id="budget_category_name" placeholder="Enter budget name" required>
                        </div>

                        <!-- Budget Items Breakdown -->
                        <div class="col-12 mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold mb-0">Budget Breakdown (Items)</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addBudgetLineItem()">
                                    <i class="bi bi-plus"></i> Add Item
                                </button>
                            </div>
                            <div class="table-responsive border rounded">
                                <table class="table table-sm table-borderless align-middle mb-0" id="budgetBreakdownTable">
                                    <thead class="bg-light small">
                                        <tr>
                                            <th style="width: 5%;" class="text-center">S/No</th>
                                            <th style="width: 32%;">Description</th>
                                            <th style="width: 12%;">Units</th>
                                            <th style="width: 10%;">Qty</th>
                                            <th style="width: 16%;">Price/Each</th>
                                            <th style="width: 10%;">Tax %</th>
                                            <th style="width: 12%;">Total</th>
                                            <th style="width: 3%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Rows added by JS -->
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Grand Total Allocated (TZS)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-success">TZS</span>
                                <input type="number" class="form-control fw-bold fs-5 text-success" name="allocated_amount" id="budget_allocated_amount" readonly value="0.00">
                            </div>
                            <small class="text-muted">Automatically calculated from the items above.</small>
                        </div>

                        <div class="col-md-6" id="budget_status_container">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status" id="budget_status_field">
                                <option value="draft" selected>Draft</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>

                        <div class="col-md-6 payment-info-fields" style="display: none;">
                             <label class="form-label fw-bold">Payment Reference No</label>
                             <input type="text" class="form-control" name="payment_reference" placeholder="Ref No (e.g. Receipt #)">
                        </div>

                        <div class="col-12 payment-info-fields" style="display: none;">
                             <label class="form-label fw-bold">Upload Proof (Voucher/Receipt)</label>
                             <input type="file" class="form-control" name="attachment_file">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">General Notes (Optional)</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Overall notes for this budget..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-4">
                    <button type="button" class="btn btn-secondary border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm" id="btnSaveBudget">
                        <i class="bi bi-check-circle me-1"></i> Save Budget
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- PROJECT SUB-CONTRACTORS MODALS                              -->
<!-- ============================================================ -->

<!-- Assign Existing Sub-Contractor Modal -->
<div class="modal fade" id="assignExistingScModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:12px;">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Assign Existing Sub-Contractor</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Search Sub-Contractor</label>
                    <select id="assignScSelect2" style="width:100%"></select>
                    <div class="form-text text-muted mt-1">Type to search by name or code. Already assigned sub-contractors are excluded.</div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmAssignExistingSc()"><i class="bi bi-check-circle me-1"></i> Assign</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Sub-Contractor Modal -->
<div class="modal fade" id="projScAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Sub-Contractor</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="projScAddForm" enctype="multipart/form-data">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="status" value="active">
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pasc-basic" type="button"><i class="bi bi-info-circle me-1"></i>Basic</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pasc-contact" type="button"><i class="bi bi-person-lines-fill me-1"></i>Contact</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pasc-address" type="button"><i class="bi bi-geo-alt me-1"></i>Address</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pasc-financial" type="button"><i class="bi bi-wallet2 me-1"></i>Financial</button></li>
                    </ul>
                    <div class="tab-content">
                        <!-- Basic -->
                        <div class="tab-pane fade show active" id="pasc-basic">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" class="form-control" name="supplier_name" required></div>
                                <div class="col-6 mb-3"><label class="form-label">Company Name</label><input type="text" class="form-control" name="company_name"></div>
                                <div class="col-6 mb-3"><label class="form-label">Acronym</label><input type="text" class="form-control" name="acronym"></div>
                                <div class="col-6 mb-3"><label class="form-label">Logo</label><input type="file" class="form-control" name="logo" accept="image/*"></div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Type</label>
                                    <select class="form-select" name="supplier_type">
                                        <option value="">Select Type</option>
                                        <option value="Manufacturer">Manufacturer</option>
                                        <option value="Distributor">Distributor</option>
                                        <option value="Wholesaler">Wholesaler</option>
                                        <option value="Retailer">Retailer</option>
                                        <option value="Service Provider">Service Provider</option>
                                        <option value="Contractor">Contractor</option>
                                        <option value="Consultant">Consultant</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php $cy = date('Y'); for ($y = $cy; $y >= $cy - 10; $y--) echo "<option value='$y'" . ($y == $cy ? ' selected' : '') . ">$y</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($supplier_categories as $cat): ?><option value="<?= $cat['category_id'] ?>"><?= safe_output($cat['category_name']) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 mb-3"><label class="form-label">Credit Limit</label><input type="number" class="form-control" name="credit_limit" step="0.01" value="0"></div>
                                <div class="col-12 mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
                            </div>
                        </div>
                        <!-- Contact -->
                        <div class="tab-pane fade" id="pasc-contact">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Contact Person</label><input type="text" class="form-control" name="contact_person"></div>
                                <div class="col-6 mb-3"><label class="form-label">Title</label><input type="text" class="form-control" name="contact_title"></div>
                                <div class="col-6 mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div>
                                <div class="col-6 mb-3"><label class="form-label">Company Email</label><input type="email" class="form-control" name="company_email"></div>
                                <div class="col-6 mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" name="phone"></div>
                                <div class="col-6 mb-3"><label class="form-label">Mobile</label><input type="text" class="form-control" name="mobile"></div>
                                <div class="col-6 mb-3"><label class="form-label">Fax</label><input type="text" class="form-control" name="fax"></div>
                                <div class="col-6 mb-3"><label class="form-label">Website</label><input type="url" class="form-control" name="website"></div>
                            </div>
                        </div>
                        <!-- Address -->
                        <div class="tab-pane fade" id="pasc-address">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Country</label><input type="text" class="form-control" name="country" value="Tanzania"></div>
                                <div class="col-6 mb-3"><label class="form-label">Region</label><input type="text" class="form-control" name="state"></div>
                                <div class="col-6 mb-3"><label class="form-label">District</label><input type="text" class="form-control" name="city"></div>
                                <div class="col-6 mb-3"><label class="form-label">Council</label><input type="text" class="form-control" name="council"></div>
                                <div class="col-6 mb-3"><label class="form-label">Ward</label><input type="text" class="form-control" name="ward"></div>
                                <div class="col-6 mb-3"><label class="form-label">Zip Code</label><input type="text" class="form-control" name="postal_code"></div>
                                <div class="col-12 mb-3"><label class="form-label">Physical Address</label><textarea class="form-control" name="address" rows="2"></textarea></div>
                                <div class="col-12 mb-3"><label class="form-label">Postal Address</label><input type="text" class="form-control" name="postal_address"></div>
                            </div>
                        </div>
                        <!-- Financial -->
                        <div class="tab-pane fade" id="pasc-financial">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">TIN</label><input type="text" class="form-control" name="tax_id"></div>
                                <div class="col-6 mb-3"><label class="form-label">VAT Number</label><input type="text" class="form-control" name="vat_number"></div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <select class="form-select" name="payment_terms">
                                        <option value="">Select...</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Net 15">Net 15</option>
                                        <option value="Net 30">Net 30</option>
                                        <option value="Net 60">Net 60</option>
                                        <option value="Due on Receipt">Due on Receipt</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select" name="currency">
                                        <option value="TZS" selected>TZS</option>
                                        <option value="USD">USD</option>
                                        <option value="KES">KES</option>
                                        <option value="EUR">EUR</option>
                                        <option value="GBP">GBP</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3"><label class="form-label">Bank Name</label><input type="text" class="form-control" name="bank_name"></div>
                                <div class="col-6 mb-3"><label class="form-label">Bank Account</label><input type="text" class="form-control" name="bank_account"></div>
                                <div class="col-12 mb-3"><label class="form-label">Bank Address</label><textarea class="form-control" name="bank_address" rows="2"></textarea></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Sub-Contractor Modal -->
<div class="modal fade" id="projScEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Sub-Contractor</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="projScEditForm" enctype="multipart/form-data">
                <input type="hidden" name="supplier_id" id="pesc_id">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pesc-basic" type="button"><i class="bi bi-info-circle me-1"></i>Basic</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pesc-contact" type="button"><i class="bi bi-person-lines-fill me-1"></i>Contact</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pesc-address" type="button"><i class="bi bi-geo-alt me-1"></i>Address</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pesc-financial" type="button"><i class="bi bi-wallet2 me-1"></i>Financial</button></li>
                    </ul>
                    <div class="tab-content">
                        <!-- Basic -->
                        <div class="tab-pane fade show active" id="pesc-basic">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="pesc_name" name="supplier_name" required></div>
                                <div class="col-6 mb-3"><label class="form-label">Company Name</label><input type="text" class="form-control" id="pesc_company_name" name="company_name"></div>
                                <div class="col-6 mb-3"><label class="form-label">Acronym</label><input type="text" class="form-control" id="pesc_acronym" name="acronym"></div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Logo</label>
                                    <input type="file" class="form-control" name="logo" accept="image/*">
                                    <div id="pesc_logo_display" class="mt-1"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Type</label>
                                    <select class="form-select" id="pesc_type" name="supplier_type">
                                        <option value="">Select Type</option>
                                        <option value="Manufacturer">Manufacturer</option>
                                        <option value="Distributor">Distributor</option>
                                        <option value="Wholesaler">Wholesaler</option>
                                        <option value="Retailer">Retailer</option>
                                        <option value="Service Provider">Service Provider</option>
                                        <option value="Contractor">Contractor</option>
                                        <option value="Consultant">Consultant</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" id="pesc_year" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php $cy = date('Y'); for ($y = $cy; $y >= $cy - 10; $y--) echo "<option value='$y'>$y</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" id="pesc_category" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($supplier_categories as $cat): ?><option value="<?= $cat['category_id'] ?>"><?= safe_output($cat['category_name']) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="pesc_status" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3"><label class="form-label">Credit Limit</label><input type="number" class="form-control" id="pesc_credit_limit" name="credit_limit" step="0.01"></div>
                                <div class="col-12 mb-3"><label class="form-label">Description</label><textarea class="form-control" id="pesc_description" name="description" rows="2"></textarea></div>
                            </div>
                        </div>
                        <!-- Contact -->
                        <div class="tab-pane fade" id="pesc-contact">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Contact Person</label><input type="text" class="form-control" id="pesc_contact_person" name="contact_person"></div>
                                <div class="col-6 mb-3"><label class="form-label">Title</label><input type="text" class="form-control" id="pesc_contact_title" name="contact_title"></div>
                                <div class="col-6 mb-3"><label class="form-label">Email</label><input type="email" class="form-control" id="pesc_email" name="email"></div>
                                <div class="col-6 mb-3"><label class="form-label">Company Email</label><input type="email" class="form-control" id="pesc_company_email" name="company_email"></div>
                                <div class="col-6 mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" id="pesc_phone" name="phone"></div>
                                <div class="col-6 mb-3"><label class="form-label">Mobile</label><input type="text" class="form-control" id="pesc_mobile" name="mobile"></div>
                                <div class="col-6 mb-3"><label class="form-label">Fax</label><input type="text" class="form-control" id="pesc_fax" name="fax"></div>
                                <div class="col-6 mb-3"><label class="form-label">Website</label><input type="url" class="form-control" id="pesc_website" name="website"></div>
                            </div>
                        </div>
                        <!-- Address -->
                        <div class="tab-pane fade" id="pesc-address">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Country</label><input type="text" class="form-control" id="pesc_country" name="country"></div>
                                <div class="col-6 mb-3"><label class="form-label">Region</label><input type="text" class="form-control" id="pesc_state" name="state"></div>
                                <div class="col-6 mb-3"><label class="form-label">District</label><input type="text" class="form-control" id="pesc_city" name="city"></div>
                                <div class="col-6 mb-3"><label class="form-label">Council</label><input type="text" class="form-control" id="pesc_council" name="council"></div>
                                <div class="col-6 mb-3"><label class="form-label">Ward</label><input type="text" class="form-control" id="pesc_ward" name="ward"></div>
                                <div class="col-6 mb-3"><label class="form-label">Zip Code</label><input type="text" class="form-control" id="pesc_postal_code" name="postal_code"></div>
                                <div class="col-12 mb-3"><label class="form-label">Physical Address</label><textarea class="form-control" id="pesc_address" name="address" rows="2"></textarea></div>
                                <div class="col-12 mb-3"><label class="form-label">Postal Address</label><input type="text" class="form-control" id="pesc_postal_address" name="postal_address"></div>
                            </div>
                        </div>
                        <!-- Financial -->
                        <div class="tab-pane fade" id="pesc-financial">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">TIN</label><input type="text" class="form-control" id="pesc_tax_id" name="tax_id"></div>
                                <div class="col-6 mb-3"><label class="form-label">VAT Number</label><input type="text" class="form-control" id="pesc_vat_number" name="vat_number"></div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <select class="form-select" id="pesc_payment_terms" name="payment_terms">
                                        <option value="">Select...</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Net 15">Net 15</option>
                                        <option value="Net 30">Net 30</option>
                                        <option value="Net 60">Net 60</option>
                                        <option value="Due on Receipt">Due on Receipt</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select" id="pesc_currency" name="currency">
                                        <option value="TZS">TZS</option>
                                        <option value="USD">USD</option>
                                        <option value="KES">KES</option>
                                        <option value="EUR">EUR</option>
                                        <option value="GBP">GBP</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3"><label class="form-label">Bank Name</label><input type="text" class="form-control" id="pesc_bank_name" name="bank_name"></div>
                                <div class="col-6 mb-3"><label class="form-label">Bank Account</label><input type="text" class="form-control" id="pesc_bank_account" name="bank_account"></div>
                                <div class="col-12 mb-3"><label class="form-label">Bank Address</label><textarea class="form-control" id="pesc_bank_address" name="bank_address" rows="2"></textarea></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Sub-Contractor Modal -->
<div class="modal fade" id="projScViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-person-workspace me-2"></i><span id="pvsc_title">Sub-Contractor Details</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Print Header -->
                <div class="text-center mb-4 d-none d-print-block">
                    <?php if(!empty($company_logo)): ?>
                        <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height:80px;width:auto;"></div>
                    <?php endif; ?>
                    <h2 style="color:#0d6efd;font-weight:800;text-transform:uppercase;"><?= htmlspecialchars($company_name) ?></h2>
                    <h3 class="fw-bold" style="text-transform:uppercase;">SUB-CONTRACTOR PROFILE</h3>
                    <h5 class="text-dark fw-bold"><?= htmlspecialchars($project_name) ?></h5>
                    <div class="mx-auto bg-primary" style="width:60px;height:3px;border-radius:2px;"></div>
                </div>

                <div class="row">
                    <!-- Basic & Bank -->
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-info-circle me-1"></i>Basic Information</h6></div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0">
                                    <tr><td class="text-muted border-0 ps-3">Status</td><td class="border-0"><span id="pvsc_status_badge"></span></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Code</td><td class="border-0"><code id="pvsc_code"></code></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Type</td><td class="border-0" id="pvsc_type"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Category</td><td class="border-0" id="pvsc_category"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Year</td><td class="border-0" id="pvsc_year"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">TIN</td><td class="border-0" id="pvsc_tin"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">VAT</td><td class="border-0" id="pvsc_vat"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Credit Limit</td><td class="border-0" id="pvsc_credit_limit"></td></tr>
                                </table>
                            </div>
                        </div>
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-bank me-1"></i>Bank Information</h6></div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0">
                                    <tr><td class="text-muted border-0 ps-3">Bank</td><td class="border-0" id="pvsc_bank_name"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Account</td><td class="border-0"><code id="pvsc_bank_account"></code></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Currency</td><td class="border-0" id="pvsc_currency"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Terms</td><td class="border-0" id="pvsc_payment_terms"></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <!-- Contact & Notes -->
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-person-lines-fill me-1"></i>Contact Details</h6></div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0">
                                    <tr><td class="text-muted border-0 ps-3">Person</td><td class="border-0" id="pvsc_contact_person"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Title</td><td class="border-0" id="pvsc_contact_title"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Email</td><td class="border-0" id="pvsc_email"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Phone</td><td class="border-0" id="pvsc_phone"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Mobile</td><td class="border-0" id="pvsc_mobile"></td></tr>
                                    <tr><td class="text-muted border-0 ps-3">Website</td><td class="border-0" id="pvsc_website"></td></tr>
                                </table>
                            </div>
                        </div>
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-justify-left me-1"></i>Notes</h6></div>
                            <div class="card-body"><p class="mb-0 text-muted small" id="pvsc_description"></p></div>
                        </div>
                    </div>
                    <!-- Address -->
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-geo-alt me-1"></i>Address</h6></div>
                            <div class="card-body">
                                <p class="mb-1 text-muted small fw-bold">Physical Address</p>
                                <p id="pvsc_address" class="mb-3"></p>
                                <p class="mb-1 text-muted small fw-bold">Postal Address</p>
                                <p id="pvsc_postal_address" class="mb-3"></p>
                                <hr class="opacity-10">
                                <div class="row g-2 small">
                                    <div class="col-6"><strong>District:</strong> <span id="pvsc_city"></span></div>
                                    <div class="col-6"><strong>Region:</strong> <span id="pvsc_state"></span></div>
                                    <div class="col-6"><strong>Ward:</strong> <span id="pvsc_ward"></span></div>
                                    <div class="col-6"><strong>Country:</strong> <span id="pvsc_country"></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer d-print-none">
                <button type="button" class="btn btn-outline-info btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
                <button type="button" class="btn btn-primary btn-sm" id="pvsc_edit_btn"><i class="bi bi-pencil me-1"></i> Edit</button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== INSPECTIONS MODALS ===== -->

<!-- Add Inspection Modal -->
<div class="modal fade" id="inspAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-clipboard-plus me-2"></i>Add Inspection</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="inspAddForm" enctype="multipart/form-data">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    <input type="hidden" id="inspSubMilestoneId" name="sub_milestone_id" value="">
                    <div class="row g-3">

                        <!-- Milestone (top-level only) -->
                        <div class="col-12">
                            <label class="form-label fw-bold small">Milestone <span class="text-muted fw-normal">(optional)</span></label>
                            <select class="form-select form-select-sm" id="inspMilestoneSelect" name="milestone_id" onchange="inspOnMilestoneChange(this, 0)">
                                <option value="">-- No Milestone --</option>
                                <?php foreach($proj_milestones as $ms): ?>
                                <option value="<?= $ms['id'] ?>" data-scope="<?= $ms['scope'] ?>"><?= htmlspecialchars($ms['description']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Sub-milestone cascade container -->
                        <div id="inspSubMilestonesContainer" class="col-12" style="display:none;">
                            <div id="inspSubMilestonesBlocks" class="d-flex flex-column gap-2"></div>
                        </div>

                        <!-- Scope block (shown at deepest milestone level) -->
                        <div id="inspScopeBlock" class="col-12" style="display:none;">
                            <div class="p-3 bg-light rounded border">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small mb-1">Milestone Scope</label>
                                        <input type="text" class="form-control form-control-sm bg-white" id="inspScopeDisplay" readonly placeholder="0.00">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small mb-1">Inspected Scope <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control form-control-sm" name="inspected_scope" id="inspInspectedScope" step="0.01" min="0" placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inspection Type & Date/Time -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Inspection Type</label>
                            <select class="form-select form-select-sm" name="inspection_type">
                                <option value="Site">Site</option>
                                <option value="Quality">Quality</option>
                                <option value="Safety">Safety</option>
                                <option value="Structural">Structural</option>
                                <option value="Final">Final</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Inspection Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="inspection_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Inspection Time</label>
                            <input type="time" class="form-control form-control-sm" name="inspection_time">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Location / Area</label>
                            <input type="text" class="form-control form-control-sm" name="location_area">
                        </div>

                        <!-- Multiple Inspectors -->
                        <div class="col-12">
                            <label class="form-label fw-bold small mb-2">Inspectors <span class="text-danger">*</span></label>
                            <div id="inspectorsList">
                                <div class="inspector-row row g-2 mb-2" data-idx="0">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control form-control-sm" name="insp_name[]" placeholder="Inspector Name *" required>
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" class="form-control form-control-sm" name="insp_org[]" placeholder="Organisation">
                                    </div>
                                    <div class="col-md-1 d-flex align-items-center justify-content-center"></div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm mt-1" onclick="inspAddInspectorRow()">
                                <i class="bi bi-plus-circle me-1"></i>Add Inspector
                            </button>
                        </div>

                        <!-- Result & Re-inspection -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Result</label>
                            <select class="form-select form-select-sm" name="result">
                                <option value="">-- Pending --</option>
                                <option value="Pass">Pass</option>
                                <option value="Fail">Fail</option>
                                <option value="Conditional Pass">Conditional Pass</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Re-inspection Required</label>
                            <select class="form-select form-select-sm" name="reinspection_required">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Re-inspection Date</label>
                            <input type="date" class="form-control form-control-sm" name="reinspection_date">
                        </div>

                        <!-- Status & Signed Off -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Status</label>
                            <select class="form-select form-select-sm" name="status">
                                <option value="Pending">Pending</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Signed Off By</label>
                            <input type="text" class="form-control form-control-sm" name="signed_off_by">
                        </div>

                        <!-- Defects & Corrective Action -->
                        <div class="col-12">
                            <label class="form-label fw-bold small">Defects Found</label>
                            <textarea class="form-control form-control-sm" name="defects_found" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Corrective Action</label>
                            <textarea class="form-control form-control-sm" name="corrective_action" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Notes</label>
                            <textarea class="form-control form-control-sm" name="notes" rows="2"></textarea>
                        </div>

                        <!-- Attachments -->
                        <div class="col-12">
                            <label class="form-label fw-bold small mb-2">Attachments <span class="text-muted fw-normal">(Excel, Word, PDF, images)</span></label>
                            <div id="inspAttachList">
                                <div class="attach-row row g-2 mb-2">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control form-control-sm" name="attach_name[]" placeholder="Attachment name / description">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="file" class="form-control form-control-sm" name="attachments[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
                                    </div>
                                    <div class="col-md-1 d-flex align-items-center justify-content-center"></div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm mt-1" onclick="inspAddAttachRow('inspAttachList')">
                                <i class="bi bi-plus-circle me-1"></i>Add Attachment
                            </button>
                            <div class="form-text text-muted mt-1" style="font-size:.75rem;">Allowed: PDF, Word, Excel, images. Max 10 MB per file.</div>
                        </div>

                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="inspSave()"><i class="bi bi-save me-1"></i> Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Inspection Modal -->
<div class="modal fade" id="inspEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Inspection</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="inspEditForm" enctype="multipart/form-data">
                    <input type="hidden" name="inspection_id" id="edit_insp_id">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Milestone</label>
                            <select class="form-select form-select-sm" name="milestone_id" id="edit_insp_milestone">
                                <option value="">-- No Milestone --</option>
                                <?php foreach($proj_milestones as $ms): ?>
                                <option value="<?= $ms['id'] ?>"><?= htmlspecialchars($ms['description']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Inspection Type</label>
                            <select class="form-select form-select-sm" name="inspection_type" id="edit_insp_type">
                                <option value="Site">Site</option>
                                <option value="Quality">Quality</option>
                                <option value="Safety">Safety</option>
                                <option value="Structural">Structural</option>
                                <option value="Final">Final</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Inspection Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="inspection_date" id="edit_insp_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Inspection Time</label>
                            <input type="time" class="form-control form-control-sm" name="inspection_time" id="edit_insp_time">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Location / Area</label>
                            <input type="text" class="form-control form-control-sm" name="location_area" id="edit_insp_location">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Result</label>
                            <select class="form-select form-select-sm" name="result" id="edit_insp_result">
                                <option value="">-- Pending --</option>
                                <option value="Pass">Pass</option>
                                <option value="Fail">Fail</option>
                                <option value="Conditional Pass">Conditional Pass</option>
                            </select>
                        </div>

                        <!-- Multiple Inspectors -->
                        <div class="col-12">
                            <label class="form-label fw-bold small mb-2">Inspectors <span class="text-danger">*</span></label>
                            <div id="editInspectorsList"></div>
                            <button type="button" class="btn btn-primary btn-sm mt-1" onclick="editInspAddRow()">
                                <i class="bi bi-plus-circle me-1"></i>Add Inspector
                            </button>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold small">Defects Found</label>
                            <textarea class="form-control form-control-sm" name="defects_found" id="edit_insp_defects" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Corrective Action</label>
                            <textarea class="form-control form-control-sm" name="corrective_action" id="edit_insp_corrective" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Re-inspection Required</label>
                            <select class="form-select form-select-sm" name="reinspection_required" id="edit_insp_reinsp_req">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Re-inspection Date</label>
                            <input type="date" class="form-control form-control-sm" name="reinspection_date" id="edit_insp_reinsp_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Status</label>
                            <select class="form-select form-select-sm" name="status" id="edit_insp_status">
                                <option value="Pending">Pending</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Signed Off By</label>
                            <input type="text" class="form-control form-control-sm" name="signed_off_by" id="edit_insp_signedby">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Notes</label>
                            <textarea class="form-control form-control-sm" name="notes" id="edit_insp_notes" rows="2"></textarea>
                        </div>

                        <!-- Attachments -->
                        <div class="col-12">
                            <label class="form-label fw-bold small mb-2">New Attachments <span class="text-muted fw-normal">(Excel, Word, PDF, images)</span></label>
                            <div id="editInspAttachList">
                                <div class="attach-row row g-2 mb-2">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control form-control-sm" name="attach_name[]" placeholder="Attachment name / description">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="file" class="form-control form-control-sm" name="attachments[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
                                    </div>
                                    <div class="col-md-1 d-flex align-items-center justify-content-center"></div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm mt-1" onclick="inspAddAttachRow('editInspAttachList')">
                                <i class="bi bi-plus-circle me-1"></i>Add Attachment
                            </button>
                            <div class="form-text text-muted mt-1" style="font-size:.75rem;">Allowed: PDF, Word, Excel, images. Max 10 MB per file.</div>
                        </div>

                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="inspUpdate()"><i class="bi bi-save me-1"></i> Update</button>
            </div>
        </div>
    </div>
</div>

<!-- View Inspection: handled by inspection_view page (inspView() navigates there) -->

<!-- ===== IPC MODALS ===== -->

<!-- Add IPC Modal -->
<div class="modal fade" id="ipcAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-plus me-2"></i>Add IPC</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="ipcAddForm">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    <!-- Row 1: IPC No, Date, Periods -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">IPC No</label>
                            <input type="text" class="form-control form-control-sm bg-light" id="ipc_add_no" readonly placeholder="Auto-generated">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">IPC Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="ipc_date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Period From</label>
                            <input type="date" class="form-control form-control-sm" name="period_from">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Period To</label>
                            <input type="date" class="form-control form-control-sm" name="period_to">
                        </div>
                    </div>
                    <!-- Row 2: Customer, Sales Order, Project -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Customer</label>
                            <select class="form-select form-select-sm" id="ipc_add_customer" onchange="ipcFilterSO(this.value,'add')">
                                <option value="">-- Select Customer --</option>
                                <?php foreach($ipc_customers as $c): ?>
                                <option value="<?= $c['customer_id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Sales Order <span class="text-muted fw-normal">(optional)</span></label>
                            <select class="form-select form-select-sm" name="sales_order_id" id="ipc_add_so" onchange="ipcLoadOrderItems(this.value,'add')">
                                <option value="">-- Select Sales Order --</option>
                                <?php foreach($proj_sales_orders as $so): ?>
                                <option value="<?= $so['sales_order_id'] ?>" data-customer="<?= $so['customer_id'] ?>"><?= htmlspecialchars($so['order_number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Project</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($project_name) ?>" readonly>
                        </div>
                    </div>
                    <!-- IPC Items Table -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 fw-bold small">IPC Items</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle mb-0" id="ipcAddItemsTable">
                                    <thead class="table-light small fw-bold text-muted">
                                        <tr>
                                            <th width="35" class="text-center">#</th>
                                            <th>Product / Item</th>
                                            <th width="80" class="text-center">Quantity</th>
                                            <th width="70" class="text-center">Unit</th>
                                            <th width="130" class="text-end">Unit Price</th>
                                            <th width="65" class="text-center">Tax %</th>
                                            <th width="130" class="text-end">Total</th>
                                            <th width="40" class="d-print-none"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="ipcAddItemsBody">
                                        <tr>
                                            <td class="ipc-row-no text-center">1</td>
                                            <td><input type="text" class="form-control form-control-sm border-0" data-field="product_name" placeholder="Product or description"></td>
                                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm border-0 text-center" data-field="quantity" value="1" oninput="ipcCalc('add')"></td>
                                            <td><input type="text" class="form-control form-control-sm border-0 text-center" data-field="unit" placeholder="pcs"></td>
                                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm border-0 text-end" data-field="unit_price" placeholder="0.00" oninput="ipcCalc('add')"></td>
                                            <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm border-0 text-center" data-field="tax_percent" value="0" oninput="ipcCalc('add')"></td>
                                            <td class="text-end fw-bold small" data-field-display="total">0.00</td>
                                            <td class="d-print-none text-center"><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="ipcRemoveItem(this,'add')"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm mb-4" onclick="ipcAddItem('add')"><i class="bi bi-plus-circle me-1"></i> Add Item</button>
                    <!-- Notes, Summary -->
                    <input type="hidden" name="status" value="Draft">
                    <input type="hidden" name="retention_percent" value="0">
                    <input type="hidden" name="previous_payments" value="0">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold small">Notes</label>
                            <textarea class="form-control form-control-sm" name="notes" rows="3"></textarea>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 bg-light p-3">
                                <div class="d-flex justify-content-between mb-1 small">
                                    <span class="text-muted">Subtotal</span>
                                    <span id="ipc_add_subtotal">0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2 small">
                                    <span class="text-muted">Tax</span>
                                    <span id="ipc_add_tax">0.00</span>
                                </div>
                                <hr class="my-1">
                                <div class="d-flex justify-content-between fw-bold">
                                    <span>Net Payable (TZS)</span>
                                    <span class="text-primary" id="ipc_add_net">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="ipcSave()"><i class="bi bi-save me-1"></i> Save IPC</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit IPC Modal -->
<div class="modal fade" id="ipcEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit IPC</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="ipcEditForm">
                    <input type="hidden" name="ipc_id" id="edit_ipc_id">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    <!-- Row 1: IPC No, Date, Periods -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">IPC No</label>
                            <input type="text" class="form-control form-control-sm bg-light" id="ipc_edit_no" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">IPC Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="ipc_date" id="edit_ipc_date">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Period From</label>
                            <input type="date" class="form-control form-control-sm" name="period_from" id="edit_ipc_period_from">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Period To</label>
                            <input type="date" class="form-control form-control-sm" name="period_to" id="edit_ipc_period_to">
                        </div>
                    </div>
                    <!-- Row 2: Customer, Sales Order, Project -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Customer</label>
                            <select class="form-select form-select-sm" id="ipc_edit_customer" onchange="ipcFilterSO(this.value,'edit')">
                                <option value="">-- Select Customer --</option>
                                <?php foreach($ipc_customers as $c): ?>
                                <option value="<?= $c['customer_id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Sales Order <span class="text-muted fw-normal">(optional)</span></label>
                            <select class="form-select form-select-sm" name="sales_order_id" id="ipc_edit_so" onchange="ipcLoadOrderItems(this.value,'edit')">
                                <option value="">-- Select Sales Order --</option>
                                <?php foreach($proj_sales_orders as $so): ?>
                                <option value="<?= $so['sales_order_id'] ?>" data-customer="<?= $so['customer_id'] ?>"><?= htmlspecialchars($so['order_number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Project</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($project_name) ?>" readonly>
                        </div>
                    </div>
                    <!-- IPC Items Table -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 fw-bold small">IPC Items</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle mb-0" id="ipcEditItemsTable">
                                    <thead class="table-light small fw-bold text-muted">
                                        <tr>
                                            <th width="35" class="text-center">#</th>
                                            <th>Product / Item</th>
                                            <th width="80" class="text-center">Quantity</th>
                                            <th width="70" class="text-center">Unit</th>
                                            <th width="130" class="text-end">Unit Price</th>
                                            <th width="65" class="text-center">Tax %</th>
                                            <th width="130" class="text-end">Total</th>
                                            <th width="40" class="d-print-none"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="ipcEditItemsBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm mb-4" onclick="ipcAddItem('edit')"><i class="bi bi-plus-circle me-1"></i> Add Item</button>
                    <!-- Notes, Summary -->
                    <input type="hidden" name="status" id="edit_ipc_status">
                    <input type="hidden" name="retention_percent" id="ipc_edit_retention_pct" value="0">
                    <input type="hidden" name="previous_payments" id="ipc_edit_previous" value="0">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold small">Notes</label>
                            <textarea class="form-control form-control-sm" name="notes" id="edit_ipc_notes" rows="3"></textarea>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 bg-light p-3">
                                <div class="d-flex justify-content-between mb-1 small">
                                    <span class="text-muted">Subtotal</span>
                                    <span id="ipc_edit_subtotal">0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2 small">
                                    <span class="text-muted">Tax</span>
                                    <span id="ipc_edit_tax">0.00</span>
                                </div>
                                <hr class="my-1">
                                <div class="d-flex justify-content-between fw-bold">
                                    <span>Net Payable (TZS)</span>
                                    <span class="text-primary" id="ipc_edit_net">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="ipcUpdate()"><i class="bi bi-save me-1"></i> Update IPC</button>
            </div>
        </div>
    </div>
</div>

<!-- View IPC Modal -->
<div class="modal fade" id="ipcViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-check me-2"></i>IPC Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="ipcViewBody">Loading...</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning btn-sm" id="ipcReviewBtn" style="display:none;"><i class="bi bi-eye-fill me-1"></i> Review</button>
                <button type="button" class="btn btn-success btn-sm" id="ipcApproveBtn" style="display:none;"><i class="bi bi-check-circle me-1"></i> Approve</button>
                <button type="button" class="btn btn-outline-primary btn-sm" id="ipcCreateInvoiceBtn" style="display:none;"><i class="bi bi-receipt me-1"></i> Create Invoice</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.open(APP_URL + '/print_ipc?id=' + ipcCurrentId, '_blank')"><i class="bi bi-printer me-1"></i> Print</button>
                <button type="button" class="btn btn-primary btn-sm" id="ipcViewEditBtn" style="display:none;"><i class="bi bi-pencil me-1"></i> Edit</button>
                <?php if (isAdmin()): ?>
                <button type="button" class="btn btn-danger btn-sm" id="ipcViewDeleteBtn" onclick="ipcDeleteFromModal()"><i class="bi bi-trash me-1"></i> Delete</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><i class="bi bi-arrow-left me-1"></i> Back</button>
            </div>
        </div>
    </div>
</div>

<!-- Register Project Supplier Modal -->
<div class="modal fade" id="addProjectSupplierModal" tabindex="-1" aria-labelledby="addProjectSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addProjectSupplierModalLabel">
                    <i class="bi bi-truck"></i> Register Project Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addProjectSupplierForm">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <div class="modal-body">                    <!-- Tabs Navigation -->
                    <ul class="nav nav-tabs mb-3" id="addProjectSupplierTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" id="aps-basic-tab" data-bs-toggle="tab" data-bs-target="#aps-basic" type="button" role="tab" aria-controls="aps-basic" aria-selected="true">
                                <i class="bi bi-info-circle me-1"></i>Basic Info
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="aps-contact-tab" data-bs-toggle="tab" data-bs-target="#aps-contact" type="button" role="tab" aria-controls="aps-contact" aria-selected="false">
                                <i class="bi bi-person-lines-fill me-1"></i>Contact Details
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="aps-address-tab" data-bs-toggle="tab" data-bs-target="#aps-address" type="button" role="tab" aria-controls="aps-address" aria-selected="false">
                                <i class="bi bi-geo-alt me-1"></i>Address
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="aps-financial-tab" data-bs-toggle="tab" data-bs-target="#aps-financial" type="button" role="tab" aria-controls="aps-financial" aria-selected="false">
                                <i class="bi bi-wallet2 me-1"></i>Financial
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="addProjectSupplierTabsContent">
                        <!-- Tab 1: Basic Info -->
                        <div class="tab-pane fade show active" id="aps-basic" role="tabpanel" aria-labelledby="aps-basic-tab">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="supplier_name" required placeholder="Enter supplier name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Company Name</label>
                                    <input type="text" class="form-control" name="company_name" placeholder="Company name (if different)">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Acronym</label>
                                    <input type="text" class="form-control" name="acronym" placeholder="e.g. TANESCO, TRA">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Company Logo</label>
                                    <input type="file" class="form-control" name="logo" accept="image/*">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Supplier Type</label>
                                    <select class="form-select" name="supplier_type">
                                        <option value="">Select Type</option>
                                        <option value="Manufacturer">Manufacturer</option>
                                        <option value="Distributor">Distributor</option>
                                        <option value="Wholesaler">Wholesaler</option>
                                        <option value="Retailer">Retailer</option>
                                        <option value="Service Provider">Service Provider</option>
                                        <option value="Contractor">Contractor</option>
                                        <option value="Consultant">Consultant</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php 
                                        $current_year = date('Y');
                                        for ($y = $current_year; $y >= $current_year - 10; $y--) {
                                            echo "<option value=\"$y\">$y</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($supplier_categories as $cat): ?>
                                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Credit Limit</label>
                                    <input type="number" class="form-control" name="credit_limit" placeholder="0.00" step="0.01">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="description" rows="2" placeholder="Supplier description or notes"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Contact Details -->
                        <div class="tab-pane fade" id="aps-contact" role="tabpanel" aria-labelledby="aps-contact-tab">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" name="contact_person" placeholder="Primary contact person">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Title</label>
                                    <input type="text" class="form-control" name="contact_title" placeholder="e.g., Manager, Director">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Email</label>
                                    <input type="email" class="form-control" name="email" placeholder="contact@example.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Company Email</label>
                                    <input type="email" class="form-control" name="company_email" placeholder="company@example.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Mobile Number</label>
                                    <input type="text" class="form-control" name="mobile" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fax Number</label>
                                    <input type="text" class="form-control" name="fax" placeholder="Fax number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Website</label>
                                    <input type="url" class="form-control" name="website" placeholder="https://www.example.com">
                                </div>
                            </div>
                        </div>

                        <!-- Tab 3: Address -->
                        <div class="tab-pane fade" id="aps-address" role="tabpanel" aria-labelledby="aps-address-tab">
                            <div class="row">
                                <!-- Location cascade: Country → Region → District → Ward → Street/Village -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Country</label>
                                    <input type="text" class="form-control" id="aps_country" name="country" placeholder="e.g. Tanzania">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Region</label>
                                    <input type="text" class="form-control" id="aps_state" name="state" placeholder="e.g. Dar es Salaam">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">District (City)</label>
                                    <input type="text" class="form-control" id="aps_city" name="city" placeholder="e.g. Ilala">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="aps_ward" name="ward" placeholder="e.g. Kariakoo">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Street/Village</label>
                                    <input type="text" class="form-control" id="aps_village" name="village" placeholder="e.g. Mtaa wa Kariakoo">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Council</label>
                                    <input type="text" class="form-control" name="council" placeholder="e.g. Ilala Municipal">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Postal Code (Zip)</label>
                                    <input type="text" class="form-control" name="postal_code" placeholder="Zip code">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Physical Address</label>
                                    <textarea class="form-control" name="address" rows="2" placeholder="e.g. Ilala - Dar-es-salaam"></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Postal Address</label>
                                    <input type="text" class="form-control" name="postal_address" placeholder="e.g. p.o. box 120, mbezi">
                                </div>
                            </div>
                        </div>

                        <!-- Financial -->
                        <div class="tab-pane fade" id="aps-financial" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" name="tax_id" placeholder="TIN">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">VAT Number</label>
                                    <input type="text" class="form-control" name="vat_number" placeholder="VAT registration number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <select class="form-select" name="payment_terms">
                                        <option value="">Select Terms</option>
                                        <option value="cod">Cash on Delivery</option>
                                        <option value="7_days">7 Days</option>
                                        <option value="15_days">15 Days</option>
                                        <option value="30_days">30 Days</option>
                                        <option value="60_days">60 Days</option>
                                        <option value="90_days">90 Days</option>
                                        <option value="other">Other...</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select" name="currency">
                                        <option value="TZS" selected>Tanzanian Shilling (TZS)</option>
                                        <option value="USD">US Dollar (USD)</option>
                                        <option value="EUR">Euro (EUR)</option>
                                        <option value="GBP">British Pound (GBP)</option>
                                        <option value="KES">Kenyan Shilling (KES)</option>
                                        <option value="other">Other...</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Bank Address</label>
                                    <textarea class="form-control" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSaveProjectSupplier">
                        <i class="bi bi-check-circle"></i> Register Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Project Supplier Modal -->
<div class="modal fade" id="editProjectSupplierModal" tabindex="-1" aria-labelledby="editProjectSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editProjectSupplierModalLabel">
                    <i class="bi bi-pencil"></i> Edit Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editProjectSupplierForm">
                <input type="hidden" id="eps_supplier_id" name="supplier_id">
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" id="editSupplierTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" id="eps-basic-tab" data-bs-toggle="tab" data-bs-target="#eps-basic" type="button" role="tab">Basic Info</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="eps-contact-tab" data-bs-toggle="tab" data-bs-target="#eps-contact" type="button" role="tab">Contact Details</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="eps-address-tab" data-bs-toggle="tab" data-bs-target="#eps-address" type="button" role="tab">Address</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="eps-financial-tab" data-bs-toggle="tab" data-bs-target="#eps-financial" type="button" role="tab">Financial</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content">
                        <!-- Basic Info Tab -->
                        <div class="tab-pane fade show active" id="eps-basic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="eps_supplier_name" name="supplier_name" required placeholder="Enter supplier name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Company Name</label>
                                    <input type="text" class="form-control" id="eps_company_name" name="company_name" placeholder="Legal company name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" id="eps_category_id" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($supplier_categories as $cat): ?>
                                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="eps_status" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Linked Project</label>
                                    <select class="form-select" id="eps_project_id" name="project_id">
                                        <option value="">-- General Supplier (No Project) --</option>
                                        <?php foreach ($projects as $project): ?>
                                            <option value="<?= $project['project_id'] ?>"><?= htmlspecialchars($project['project_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" id="eps_description" name="description" rows="2" placeholder="Additional details..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Details Tab -->
                        <div class="tab-pane fade" id="eps-contact" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" id="eps_contact_person" name="contact_person" placeholder="Full name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Title</label>
                                    <input type="text" class="form-control" id="eps_contact_title" name="contact_title" placeholder="e.g. Sales Manager">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="eps_email" name="email" placeholder="example@supplier.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="eps_phone" name="phone" placeholder="Landline">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Mobile Number</label>
                                    <input type="text" class="form-control" id="eps_mobile" name="mobile" placeholder="e.g. 07XXXXXXXX">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fax Number</label>
                                    <input type="text" class="form-control" id="eps_fax" name="fax" placeholder="Fax number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Website</label>
                                    <input type="url" class="form-control" id="eps_website" name="website" placeholder="https://">
                                </div>
                            </div>
                        </div>

                        <!-- Address Tab -->
                        <div class="tab-pane fade" id="eps-address" role="tabpanel">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <label class="form-label">Physical Address</label>
                                    <textarea class="form-control" id="eps_address" name="address" rows="2" placeholder="Street, Building, etc."></textarea>
                                </div>
                                <!-- Location cascade: Country → Region → District → Ward → Street/Village -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Country</label>
                                    <input type="text" class="form-control" id="eps_country" name="country" placeholder="Country">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Region</label>
                                    <input type="text" class="form-control" id="eps_state" name="state" placeholder="Region">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">District (City)</label>
                                    <input type="text" class="form-control" id="eps_city" name="city" placeholder="District">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="eps_ward" name="ward" placeholder="Ward">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Street/Village</label>
                                    <input type="text" class="form-control" id="eps_village" name="village" placeholder="Street/Village">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Postal Code</label>
                                    <input type="text" class="form-control" id="eps_postal_code" name="postal_code" placeholder="Postal Code">
                                </div>
                            </div>
                        </div>

                        <!-- Financial Tab -->
                        <div class="tab-pane fade" id="eps-financial" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" id="eps_tax_id" name="tax_id" placeholder="TIN">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">VAT Number</label>
                                    <input type="text" class="form-control" id="eps_vat_number" name="vat_number" placeholder="VAT registration number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <select class="form-select" id="eps_payment_terms" name="payment_terms">
                                        <option value="">Select Terms</option>
                                        <option value="cod">Cash on Delivery</option>
                                        <option value="7_days">7 Days</option>
                                        <option value="15_days">15 Days</option>
                                        <option value="30_days">30 Days</option>
                                        <option value="60_days">60 Days</option>
                                        <option value="90_days">90 Days</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select" id="eps_currency" name="currency">
                                        <option value="TZS">Tanzanian Shilling (TZS)</option>
                                        <option value="USD">US Dollar (USD)</option>
                                        <option value="EUR">Euro (EUR)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="eps_bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" id="eps_bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Bank Address</label>
                                    <textarea class="form-control" id="eps_bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnUpdateProjectSupplier">
                        <i class="bi bi-check-circle"></i> Update Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Manage Expense Types & Categories Modal -->
<div class="modal fade" id="expenseConfigModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header bg-primary text-white py-3" style="border-radius: 15px 15px 0 0;">
                <h5 class="modal-title fw-bold"><i class="bi bi-gear-fill me-2"></i>Manage Categories</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <!-- Step 1: Expense Type Selection -->
                <div class="mb-4">
                    <label class="form-label fw-bold text-muted small text-uppercase">1. Select or Create Expense Type</label>
                    <div class="input-group">
                        <select class="form-select border-primary fw-bold" id="cfg_type_id">
                            <option value="">-- Choose Type --</option>
                        </select>
                        <button class="btn btn-primary" type="button" onclick="toggleNewTypeInput()" title="Add New Type"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <div id="new_type_input_cont" class="mt-2" style="display: none;">
                        <div class="input-group">
                            <input type="text" class="form-control border-success" id="cfg_new_type_name" placeholder="Enter New Type Name...">
                            <button class="btn btn-success" type="button" onclick="saveNewExpenseType()">Save</button>
                        </div>
                    </div>
                </div>

                <hr class="opacity-10">

                <!-- Step 2: Categories Management -->
                <div id="cfg_categories_section" style="display: none;">
                    <label class="form-label fw-bold text-muted small text-uppercase">2. Manage Categories</label>
                    
                    <!-- Add Category Form -->
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" id="cfg_new_cat_name" placeholder="Type new category name...">
                        <button class="btn btn-outline-primary fw-bold" type="button" onclick="saveNewCategory()">Add</button>
                    </div>

                    <!-- Categories List -->
                    <div class="list-group list-group-flush border rounded overflow-auto" id="cfg_categories_list" style="max-height: 250px;">
                        <!-- Dynamically populated -->
                    </div>
                </div>

                <div id="cfg_no_type_selected" class="text-center py-4 text-muted small">
                    <i class="bi bi-arrow-up-circle d-block fs-2 mb-2 opacity-25"></i>
                    Select an Expense Type above to manage its categories.
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light" style="border-radius: 0 0 15px 15px;">
                <button type="button" class="btn btn-light w-100 fw-bold" data-bs-dismiss="modal">Done & Close</button>
            </div>
        </div>
    </div>
</div>

<!-- SC Add Payment Modal -->
<div class="modal fade" id="scAddPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:12px;">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-cash-stack me-2"></i>Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="scPaymentMsg" class="mb-2"></div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold small">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="scPayDate">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="scPayAmount" step="0.01" min="0.01" placeholder="0.00">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">Currency</label>
                        <select class="form-select" id="scPayCurrency">
                            <option value="TZS">TZS</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="KES">KES</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="scPayMethod">
                            <option value="">Select...</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Paid From <span class="text-danger">*</span></label>
                        <select class="form-select" id="scPayAccount">
                            <option value="">Select account…</option>
                            <?php foreach (cashBankAccounts($pdo) as $acc): ?>
                            <option value="<?= (int)$acc['account_id'] ?>"><?= safe_output($acc['account_name'] . ($acc['account_code'] ? ' (' . $acc['account_code'] . ')' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Cash/bank account the money is paid from.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Reference Number</label>
                        <input type="text" class="form-control" id="scPayRef" placeholder="e.g. bank ref, cheque no...">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Receipt Number <span class="text-muted small fw-normal">(from sub-contractor)</span></label>
                        <input type="text" class="form-control" id="scPayReceipt" placeholder="Receipt no. provided by SC after payment">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Notes</label>
                        <textarea class="form-control" id="scPayNotes" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-3" style="border-radius:0 0 12px 12px;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary fw-bold px-4" onclick="saveScPayment()">
                    <i class="bi bi-check-circle me-1"></i>Save Payment
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($supplier_mode): ?>
<!-- Supplier Project Payment Modal -->
<div class="modal fade" id="suppAddPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:12px;">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-cash-stack me-2"></i>Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div id="suppPaymentMsg" class="mb-2"></div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold small">Purchase Order <span class="text-danger">*</span></label>
                        <select class="form-select" id="suppPayPO">
                            <option value="">Loading POs...</option>
                        </select>
                        <div class="form-text" id="suppPayPOBalance"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="suppPayDate">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="suppPayAmount" step="0.01" min="0.01" placeholder="0.00">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">Currency</label>
                        <select class="form-select" id="suppPayCurrency">
                            <option value="TZS">TZS</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="KES">KES</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="suppPayMethod">
                            <option value="">Select...</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Reference Number</label>
                        <input type="text" class="form-control" id="suppPayRef" placeholder="e.g. bank ref, cheque no...">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Notes</label>
                        <textarea class="form-control" id="suppPayNotes" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-3" style="border-radius:0 0 12px 12px;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success fw-bold px-4" id="suppPaySaveBtn" onclick="saveSuppPayment()">
                    <i class="bi bi-check-circle me-1"></i>Save Payment
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Supplier Payment Edit Modal -->
<div class="modal fade" id="suppEditPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:12px;">
            <div class="modal-header bg-warning text-dark py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="editSuppPayId">
                <div id="editSuppPaymentMsg" class="mb-2"></div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold small">Purchase Order <span class="text-danger">*</span></label>
                        <select class="form-select" id="editSuppPayPO">
                            <option value="">Select PO...</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="editSuppPayDate">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="editSuppPayAmount" step="0.01" min="0.01">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">Currency</label>
                        <select class="form-select" id="editSuppPayCurrency">
                            <option value="TZS">TZS</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="KES">KES</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="editSuppPayMethod">
                            <option value="">Select...</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Reference Number</label>
                        <input type="text" class="form-control" id="editSuppPayRef">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Notes</label>
                        <textarea class="form-control" id="editSuppPayNotes" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-3" style="border-radius:0 0 12px 12px;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning fw-bold px-4" id="editSuppPaySaveBtn" onclick="saveSuppPaymentEdit()">
                    <i class="bi bi-check-circle me-1"></i>Update Payment
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .priority-badge { font-size: 0.8rem; font-weight: 700; padding: 0.4rem 0.8rem; border-radius: 50rem; display: inline-block; }
    .status-badge { font-size: 0.8rem; font-weight: 600; padding: 0.4rem 0.8rem; border-radius: 50rem; }
    .status-badge-approved { background:#e7f0ff!important; color:#0d6efd!important; border:1px solid #b8d0ff; }
    .status-badge-pending  { background:#fff!important; color:#0d6efd!important; border:1px solid #0d6efd; }
    .nip-product-avatar { width:36px; height:36px; border-radius:50%; background:#e7f0ff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    #procMatTable thead th { background:#fff; color:#333; border-bottom:2px solid #dee2e6; font-size:.78rem; text-transform:uppercase; }
    @media print {
        #procMatTable-bms-cards { display:none!important; }
        #procMaterialsCard      { display:block!important; }
        #procMaterialsEmpty     { display:none!important; }
        #procMatPageControls    { display:none!important; }
        #procMatPaginationInfo  { display:none!important; }
        .d-print-none           { display:none!important; }
        #procMatTable { font-size:9px; }
        #procMatTable th, #procMatTable td { font-size:9px!important; white-space:nowrap; }
    }
    .bg-success-light { background-color: #d1e7dd !important; }
    .bg-danger-light { background-color: #f8d7da !important; }
    .bg-primary-light { background-color: #cfe2ff !important; }
    @media (min-width: 768px) {
        #overviewFinancialCards.row-cols-md-7 > * { flex: 0 0 auto; width: 14.285714%; }
    }
    .bg-secondary-light { background-color: #e2e3e5 !important; }
    .bg-info-light { background-color: #cff4fc !important; }
    
    .bg-success-soft { background-color: rgba(25, 135, 84, 0.1) !important; }
    .bg-primary-soft { background-color: rgba(13, 110, 253, 0.1) !important; }
    .bg-info-soft { background-color: rgba(13, 202, 240, 0.1) !important; }
    .bg-warning-soft { background-color: rgba(255, 193, 7, 0.1) !important; }
    .bg-danger-soft { background-color: rgba(220, 53, 69, 0.1) !important; }

    .stats-icon {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        font-size: 1.25rem;
    }

    .tabs-scroll-container {
        overflow-x: auto;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none; /* Firefox */
    }
    .tabs-scroll-container::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
    }
    
    .nav-tabs .nav-link { 
        color: #6c757d; 
        border: none; 
        border-bottom: 3px solid transparent;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    .nav-tabs .nav-link:hover { 
        border-bottom-color: #dee2e6;
        background-color: #f8f9fa;
    }
    .nav-tabs .nav-link.active { 
        color: #0d6efd; 
        border-bottom-color: #0d6efd !important;
        background-color: transparent;
        font-weight: 600;
    }
    .nav-tabs .nav-link i {
        margin-right: 0.5rem;
    }
    
    /* Ensure dropdown toggle shows active state when sub-tab is active */
    .nav-tabs .dropdown-toggle.active {
        color: #0d6efd !important;
        border-bottom: 3px solid #0d6efd !important;
        background-color: transparent !important;
    }
    .dropdown-item.active {
        background-color: #0d6efd;
        color: white;
    }
    
    .table-warning-soft { background-color: #fff3cd !important; }
    .phase-balance-chip .badge { font-weight: 600; font-size: 0.7rem; border-radius: 4px; padding: 0.35rem 0.6rem; }
    .is-invalid { border-color: #dc3545 !important; background-image: none !important; }
    .table-light.fw-bold { background-color: #f8f9ff !important; border-top: 2px solid #dee2e6; }
    .task-id-cell { width: 50px; }
    .task-is-phase { transform: scale(1.2); cursor: pointer; }
    .planning-summary-card { background-color: #d1e7dd !important; border: 1px solid #c3e6cb; border-radius: 12px; min-height: 80px; height: 100%; transition: transform 0.2s; }
    .planning-summary-card:hover { transform: translateY(-2px); }
    .planning-summary-card small { color: #0f5132 !important; font-size: 0.65rem; letter-spacing: 0.5px; }
    .planning-summary-card span { color: #0f5132 !important; font-size: 1rem; }
    @media (min-width: 768px) {
        .planning-summary-card small { font-size: 0.75rem; }
        .planning-summary-card span { font-size: 1.1rem; }
    }

    
    @media print {
        /* General visibility */
        .d-print-none, .breadcrumb, header, footer, nav, .nav-tabs, .btn-group, #performanceFilterContainer, .card-header.d-print-none, .d-print-none * { 
            display: none !important; 
            visibility: hidden !important;
            height: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        
        body { background: white !important; margin: 0 !important; padding: 0 !important; }
        .container-fluid { padding: 0 !important; margin: 0 !important; max-width: 100% !important; width: 100% !important; }
        
        /* Custom overview sections — hidden by default in print */
        .overview-print-section { display: none !important; }
        .print-header-overview { display: none !important; }
        
        /* When overview-print class is on body — show overview sections */
        body.overview-print .overview-print-section { display: flex !important; flex-wrap: wrap !important; }
        body.overview-print #overviewFinancialCards { flex-wrap: nowrap !important; }
        body.overview-print .print-header-overview { display: block !important; }
        body.overview-print .workspace-card-main { display: none !important; }
        
        /* Standard page margins for all prints from this page */
        @page { margin: 10mm 8mm 16mm 8mm; }

        /* Overview Financial Cards — Force single row with 6 equal columns in print */
        body.overview-print #overviewFinancialCards {
            display: flex !important;
            flex-wrap: nowrap !important;
            width: 100% !important;
            margin: 0 -4px !important;
        }
        body.overview-print #overviewFinancialCards > .col {
            flex: 0 0 14.2857% !important;
            max-width: 14.2857% !important;
            padding: 0 4px !important;
        }
        body.overview-print #overviewFinancialCards .card {
            background-color: #ffffff !important;
            border: 1px solid #000 !important;
            color: #000 !important;
        }
        body.overview-print #overviewFinancialCards .rounded-circle { display: none !important; }
        body.overview-print #overviewFinancialCards p {
            color: #000 !important;
            font-size: 0.5rem !important;
        }
        #revenueDisplay, #revenueUnbilledDisplay, #expectedDisplay, #paidDisplay, #expenseDisplay, #budgetDisplay, #profitDisplay, #executedDisplay {
            color: #000 !important;
            font-weight: 900 !important;
            font-size: 0.72rem !important;
            white-space: nowrap !important;
            word-break: normal !important;
            overflow: visible !important;
        }
        #profitMarginDisplay {
            font-size: 0.5rem !important;
            white-space: nowrap !important;
        }
        .card[style*="background-color: #d1e7dd"] {
            background-color: #ffffff !important;
            border-color: #000 !important;
        }
        .card[style*="background-color: #d1e7dd"] i, .card[style*="background-color: #d1e7dd"] p, .card[style*="background-color: #d1e7dd"] small {
            color: #000 !important;
        }
        #profitIcon { display: none !important; }
        
        /* Pulse Countdown Card & Layout Fixes */
        .rounded-4.shadow-sm.position-relative.overflow-hidden {
            break-inside: avoid !important;
            page-break-inside: avoid !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            padding: 1rem !important;
        }
        .rounded-4 i { display: block !important; opacity: 0.15 !important; }

        /* --- Overview-specific print: only when smartPrint detects Overview tab --- */
        body.overview-print #overviewFinancialCards,
        body.overview-print #overviewMainContent {
            display: flex !important;
        }
        body.overview-print #overviewMainContent {
            flex-wrap: wrap !important;
        }
        body.overview-print .workspace-card-main {
            display: none !important;
        }
        body.overview-print .print-header-overview {
            display: block !important;
        }
        /* Non-overview print: ensure overview sections stay hidden */
        body:not(.overview-print) .print-header-overview {
            display: none !important;
        }
        body:not(.overview-print) #overviewFinancialCards,
        body:not(.overview-print) #overviewMainContent {
            display: none !important;
        }

        /* Grid Layout Fix for Print */
        .col-lg-8 { width: 65% !important; flex: 0 0 65% !important; }
        .col-lg-4 { width: 33% !important; flex: 0 0 33% !important; }
        .row { display: flex !important; flex-wrap: wrap !important; width: 100% !important; }
        .col-6 { width: 50% !important; flex: 0 0 50% !important; }
        .mt-4 { margin-top: 0 !important; }

        /* --- Sales Orders list: always print the wide TABLE (never the mobile cards),
               regardless of phone/desktop. Actions column is hidden via .d-print-none. --- */
        #salesOrdersTableFull .d-lg-none { display: none !important; }
        #salesOrdersTableFull .d-none.d-lg-block { display: block !important; }
        #salesOrdersTableFull .dataTables_length,
        #salesOrdersTableFull .dataTables_filter,
        #salesOrdersTableFull .dataTables_info,
        #salesOrdersTableFull .dataTables_paginate { display: none !important; }
        /* Kill the DataTables inline pixel width so the table fits the page
           (a too-wide table overflows and spawns a blank trailing page). */
        #salesOrdersTableFull .dataTables_wrapper { width: 100% !important; overflow: visible !important; }
        #salesOrdersTableFull table {
            width: 100% !important;
            table-layout: auto !important;
            font-size: 9pt !important;
        }
        #salesOrdersTableFull table th,
        #salesOrdersTableFull table td {
            white-space: normal !important;
            word-break: break-word !important;
        }

        /* --- Invoices & IPC lists: identical print behaviour to Sales Orders above
               (always print the wide table, hide cards + DataTables chrome, fit to page). --- */
        #invoicesTableFull .d-lg-none,
        #proj-ipc .d-lg-none { display: none !important; }
        #invoicesTableFull .d-none.d-lg-block,
        #proj-ipc .d-none.d-lg-block { display: block !important; }
        #invoicesTableFull .dataTables_length,
        #invoicesTableFull .dataTables_filter,
        #invoicesTableFull .dataTables_info,
        #invoicesTableFull .dataTables_paginate,
        #proj-ipc .dataTables_filter,
        #proj-ipc .dataTables_info,
        #proj-ipc .dataTables_paginate { display: none !important; }
        #invoicesTableFull .dataTables_wrapper,
        #proj-ipc .dataTables_wrapper,
        #proj-ipc .table-responsive { width: 100% !important; overflow: visible !important; }
        #invoicesTableFull table,
        #proj-ipc table {
            width: 100% !important;
            table-layout: auto !important;
            font-size: 9pt !important;
        }
        #invoicesTableFull table th,
        #invoicesTableFull table td,
        #proj-ipc table th,
        #proj-ipc table td {
            white-space: normal !important;
            word-break: break-word !important;
        }

        /* --- Finance lists (Budget / Vouchers / Expenses): identical print behaviour
               (always print the wide table, hide cards + DataTables chrome, fit to page). --- */
        #budgetContent .d-lg-none,
        #vouchersTableFull .d-lg-none,
        #expensesTable .d-lg-none { display: none !important; }
        #budgetContent .d-none.d-lg-block,
        #vouchersTableFull .d-none.d-lg-block,
        #expensesTable .d-none.d-lg-block { display: block !important; }
        #budgetContent .dataTables_length,
        #budgetContent .dataTables_filter,
        #budgetContent .dataTables_info,
        #budgetContent .dataTables_paginate,
        #vouchersTableFull .dataTables_length,
        #vouchersTableFull .dataTables_filter,
        #vouchersTableFull .dataTables_info,
        #vouchersTableFull .dataTables_paginate,
        #expensesTable .dataTables_length,
        #expensesTable .dataTables_filter,
        #expensesTable .dataTables_info,
        #expensesTable .dataTables_paginate { display: none !important; }
        #budgetContent .dataTables_wrapper,
        #vouchersTableFull .dataTables_wrapper,
        #expensesTable .dataTables_wrapper { width: 100% !important; overflow: visible !important; }
        #budgetContent table,
        #vouchersTableFull table,
        #expensesTable table {
            width: 100% !important;
            table-layout: auto !important;
            font-size: 9pt !important;
        }
        #budgetContent table th,
        #budgetContent table td,
        #vouchersTableFull table th,
        #vouchersTableFull table td,
        #expensesTable table th,
        #expensesTable table td {
            white-space: normal !important;
            word-break: break-word !important;
        }
        /* Hide the Budget expand/collapse control column on print (empty on paper). */
        #budgetListTable thead th:first-child,
        #budgetListTable tbody td.dt-control { display: none !important; }

        /* Ensure schedule fits on one page width */
        #fullScheduleExportArea { 
            width: 100% !important; 
            min-width: 0 !important; 
            box-shadow: none !important; 
            border: none !important; 
            margin: 0 !important;
            padding: 0 !important;
        }
        
        .schedule-container { 
            display: flex !important; 
            flex-wrap: nowrap !important; 
            width: 100% !important;
            overflow: visible !important;
        }
        
        .schedule-table-side { 
            width: 650px !important; 
            min-width: 650px !important; 
            flex: 0 0 auto !important; 
        }
        
        .schedule-gantt-side { 
            flex: 1 1 auto !important; 
            overflow: visible !important; 
            min-width: 0 !important;
        }

        .gantt-scroll-box { 
            overflow: visible !important; 
            width: 100% !important; 
        }

        /* Force background colors */
        * { 
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

<script>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_01.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_02.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_03.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_04.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_05.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_06.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_07.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_08.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_09.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_10.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_11.php'; ?>
</script>

<form id="scopeDocUploadForm" style="display: none;">
    <input type="file" id="scopeDocFileInput" name="scope_file" onchange="handleScopeDocFileSelect()">
    <input type="hidden" name="scope_type" id="scopeDocUploadType">
    <input type="hidden" name="addendum_no" id="scopeDocUploadAddendum">
    <input type="hidden" name="project_id" value="<?= $project_id ?? 0 ?>">
</form>

<script>
function smartPrint() {
    const activePaneId = $('#projectWorkspaceContent > .tab-pane.active').attr('id') || 'overview';
    
    injectPrintSpacers();

    const restore = function () {
        $('.print-buffer-foot').remove();
        document.body.classList.remove('overview-print');
        window.removeEventListener('afterprint', restore);
    };
    window.addEventListener('afterprint', restore);

    if (activePaneId === 'overview') {
        document.body.classList.add('overview-print');
    }
    
    window.print();
}
</script>

<!-- Edit Warehouse Modal -->
<div class="modal fade" id="editProjectWarehouseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg shadow-lg">
        <div class="modal-content border-0" style="border-radius: 15px;">
            <form id="editProjectWarehouseForm">
                <input type="hidden" id="edit_proj_warehouse_id" name="warehouse_id">
                <div class="modal-header bg-primary text-white border-0 py-3" style="border-radius: 15px 15px 0 0;">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Linked Warehouse</h5>
                    <button type="button" class="btn btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="editWarehouseFormContent">
                        <!-- Loaded via AJAX -->
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Loading warehouse details...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3" style="border-radius: 0 0 15px 15px;">
                    <button type="button" class="btn btn-secondary border px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm" id="btnUpdateWarehouseFromProject">
                        <i class="bi bi-check-circle me-1"></i> Update Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create DO Modal -->
<div class="modal fade" id="createDOModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-check me-2"></i>Create Delivery Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="createDOForm" enctype="multipart/form-data" autocomplete="off">

                    <!-- Section 1: DO Information -->
                    <div class="p-3 border rounded mb-4" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-3 small text-uppercase"><i class="bi bi-info-circle me-1"></i> Delivery Order Information</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" id="cdo_supplier_id" required>
                                    <option value="">-- Select Supplier --</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Project</label>
                                <input type="text" class="form-control bg-light" id="cdo_project_display" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Warehouse <span class="text-danger">*</span></label>
                                <select class="form-select" id="cdo_warehouse_id" required>
                                    <option value="">-- Select Warehouse --</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">DO Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="cdo_do_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Expected Delivery Date</label>
                                <input type="date" class="form-control" id="cdo_expected_date">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Contact Person</label>
                                <input type="text" class="form-control" id="cdo_contact_person" placeholder="Person at delivery site">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Contact Phone</label>
                                <input type="text" class="form-control" id="cdo_contact_phone" placeholder="+255...">
                            </div>
                        </div>
                    </div>

                    <!-- Section 1.5: Delivered Items -->
                    <div class="p-3 border rounded mb-4" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-3 small text-uppercase"><i class="bi bi-list-check me-1"></i> Delivered Items</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0" id="cdoItemsTable">
                                <thead class="table-light text-uppercase small fw-bold">
                                    <tr>
                                        <th style="width:45px;" class="text-center">S/NO</th>
                                        <th>Product</th>
                                        <th style="width:130px;" class="text-center">Qty to Issue</th>
                                        <th style="width:80px;" class="text-center">Unit</th>
                                        <th style="width:48px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="cdoItemsBody"></tbody>
                            </table>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-primary" onclick="addCDOItemRow()">
                                <i class="bi bi-plus-circle me-1"></i> Add Item
                            </button>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="p-3 border rounded mb-4" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-3 small text-uppercase"><i class="bi bi-chat-text me-1"></i> Notes / Instructions</h6>
                        <textarea class="form-control" id="cdo_notes" rows="2" placeholder="Delivery instructions or special notes..."></textarea>
                    </div>

                    <!-- Section 2: Attachments -->
                    <div class="p-3 border rounded mb-2" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-3 small text-uppercase"><i class="bi bi-paperclip me-1"></i> Attachments <span class="text-muted fw-normal">(Optional)</span></h6>
                        <div id="cdoAttachmentRows"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addCDOAttachmentRow()">
                            <i class="bi bi-plus-circle me-1"></i> Add Attachment
                        </button>
                    </div>

                </form>
            </div>
            <div class="modal-footer border-0 bg-light py-3">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-5 shadow-sm" id="btnSubmitCreateDO" onclick="submitCreateDO()">
                    <i class="bi bi-send me-1"></i> Create Delivery Order
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit DO Modal -->
<div class="modal fade" id="editDOModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Delivery Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="editDOForm" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" id="edit_do_id">

                    <!-- Section 1: DO Information -->
                    <div class="p-3 border rounded mb-4" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-3 small text-uppercase"><i class="bi bi-info-circle me-1"></i> Delivery Order Information</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_supplier_id" required>
                                    <option value="">-- Select Supplier --</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Project</label>
                                <input type="text" class="form-control bg-light" id="edit_project_display" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Warehouse <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_warehouse_id" required>
                                    <option value="">-- Select Warehouse --</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">DO Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="edit_do_date" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Expected Delivery Date</label>
                                <input type="date" class="form-control" id="edit_expected_date">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Contact Person</label>
                                <input type="text" class="form-control" id="edit_contact_person" placeholder="Person at delivery site">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Contact Phone</label>
                                <input type="text" class="form-control" id="edit_contact_phone" placeholder="+255...">
                            </div>
                        </div>
                    </div>

                    <!-- Section 1.5: Delivered Items -->
                    <div class="p-3 border rounded mb-4" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-3 small text-uppercase"><i class="bi bi-list-check me-1"></i> Delivered Items</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0" id="editDOItemsTable">
                                <thead class="table-light text-uppercase small fw-bold">
                                    <tr>
                                        <th style="width:45px;" class="text-center">S/NO</th>
                                        <th>Product</th>
                                        <th style="width:130px;" class="text-center">Qty to Issue</th>
                                        <th style="width:80px;" class="text-center">Unit</th>
                                        <th style="width:48px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="editDOItemsBody"></tbody>
                            </table>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-primary" onclick="addEditDOItemRow()">
                                <i class="bi bi-plus-circle me-1"></i> Add Item
                            </button>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="p-3 border rounded mb-4" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-3 small text-uppercase"><i class="bi bi-chat-text me-1"></i> Notes / Instructions</h6>
                        <textarea class="form-control" id="edit_do_notes" rows="2" placeholder="Delivery instructions or special notes..."></textarea>
                    </div>

                    <!-- Section 2: Current Attachments (editable) -->
                    <div class="p-3 border rounded mb-3" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-2 small text-uppercase"><i class="bi bi-paperclip me-1"></i> Current Attachments</h6>
                        <div id="editDOExistingAttachments"><p class="text-muted small mb-0">None</p></div>
                    </div>

                    <!-- Section 3: Add New Attachments -->
                    <div class="p-3 border rounded mb-2" style="background:#f8f9fa;">
                        <h6 class="fw-bold text-muted mb-2 small text-uppercase"><i class="bi bi-plus-circle me-1"></i> Add New Attachments</h6>
                        <div id="editDONewAttachmentRows"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addEditDOAttachmentRow()">
                            <i class="bi bi-plus-circle me-1"></i> Add Attachment
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 bg-light py-3">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-5 shadow-sm" id="btnSubmitEditDO" onclick="submitEditDO()">
                    <i class="bi bi-check-circle me-1"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

    <!-- Print Footer Styles -->
    <style>
        @media print {

            /* --- THE FIX: RESERVATION ZONE --- */
            /* We force all tables to have a "dummy" footer group that is visible ONLY during print */
            /* This footer will push the data 2cm UP from the page bottom */
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
            
            .print-buffer-foot { display: table-footer-group !important; }
            .print-buffer-foot tr td {
                height: 0.8cm !important; /* Further minimized to allow more rows to fit */
                border: none !important;
                background: transparent !important;
            }

            .tab-pane.active, .card, .container-fluid {
                overflow: visible !important;
                display: block !important;
                position: relative !important;
                width: 100% !important;
            }

            /* --- THE FIX FOR OVERVIEW OVERLAP --- */
            /* Only apply this deep buffer for the Overview tab as per user request */
            #overview.tab-pane.active {
                padding-bottom: 4cm !important;
                display: block !important;
                clear: both !important;
                position: relative !important;
            }

            #reports.tab-pane.active table, 
            #reporting.tab-pane.active table,
            #performance.tab-pane.active table {
                width: 100% !important;
                table-layout: auto !important;
                font-size: 11pt !important; /* Significantly increased for high visibility */
                border-collapse: collapse !important;
                margin: 0 !important;
            }

            #reports .table td, #reports .table th,
            #reporting .table td, #reporting .table th,
            #performance .table td, #performance .table th {
                padding: 1px 2px !important; /* Tight padding */
                word-wrap: break-word !important;
                white-space: normal !important;
                word-break: break-all !important;
            }

            /* --- Global Performance Table Header Specifics --- */
            #performanceTable thead th {
                font-size: 8.5pt !important;
                padding: 4px 6px !important;
                text-align: center !important;
                vertical-align: middle !important;
            }

            /* --- Equalizing Column Widths for Portrait Print (Weekly/Monthly/Yearly) --- */
            /* Target: Unit(3), Scope(4), Weight(7), Progress(8) in Row 1 */
            #performanceTable thead tr:nth-child(1) th:nth-child(3),
            #performanceTable thead tr:nth-child(1) th:nth-child(4),
            #performanceTable thead tr:nth-child(1) th:nth-child(7),
            #performanceTable thead tr:nth-child(1) th:nth-child(8),
            /* Target: Prev(1), This(2), Cum(3) in Row 2 */
            #performanceTable thead tr:nth-child(2) th:nth-child(1),
            #performanceTable thead tr:nth-child(2) th:nth-child(2),
            #performanceTable thead tr:nth-child(2) th:nth-child(3) {
                width: 60px !important;
                min-width: 60px !important;
                max-width: 60px !important;
                white-space: normal !important; /* Allow long headers to wrap into rows */
                word-break: normal !important;
                line-height: 1.1 !important;
                font-size: 7.5pt !important;
            }

            /* Maintain background for This Period in Row 2 Header */
            #performanceTable thead tr:nth-child(2) th:nth-child(2) {
                background-color: rgba(13, 110, 253, 0.05) !important;
            }

            /* Portrait Specific Force: No wrapping for any data column from Unit onwards */
            #performanceTable tbody tr td:nth-child(n+3) {
                white-space: nowrap !important;
                word-break: normal !important;
                word-wrap: normal !important;
                font-size: 10pt !important; /* Large visibility as requested */
                padding: 3px 5px !important;
                text-align: center !important;
            }

            /* Main Phase (Level 0) font size override for high-visibility print */
            #performanceTable tr.perf-row[data-level="0"] span,
            #performanceTable tr.perf-row[data-level="0"] strong {
                font-size: 11pt !important;
                font-weight: 700 !important;
            }
            
            #performanceTable tr.perf-row[data-level="1"] span {
                font-size: 10pt !important;
            }

            /* Tighten up S/NO to give room for Description */
            #performanceTable thead th:nth-child(1) { width: 45px !important; min-width: 45px !important; } /* S/NO */

            /* Ensure Previous and Cumulative values have enough breathing room */
            #performanceTable tbody tr td:nth-child(5), 
            #performanceTable tbody tr td:nth-child(7) {
                min-width: 65px !important;
            }

            /* Ensure footer Totals stay on one row and follow large font policy */
            #performanceTable tfoot td {
                white-space: nowrap !important;
                font-size: 11pt !important;
                vertical-align: middle !important;
            }

            table { 
                width: 100% !important; 
                max-width: 100% !important;
                table-layout: auto !important;
                border-collapse: collapse !important;
                page-break-inside: auto !important;
                margin-bottom: 0 !important;
                font-size: 8.5pt !important; 
            }

            th, td {
                word-wrap: break-word !important;
                word-break: break-word !important;
                white-space: normal !important; 
                padding: 3px 2px !important;
            }

            .table-responsive {
                display: block !important;
                width: 100% !important;
                overflow: visible !important;
            }

            tr { 
                page-break-inside: avoid !important; 
                page-break-after: auto !important;
            }

            body { margin: 0 !important; padding: 0 !important; }

            /* Force page break for Description & Priority items as per user request */
            .print-page-break {
                page-break-before: always !important;
                break-before: page !important;
                margin-top: 2cm !important;
            }

            /* Scope tables (Original/Revised/Variation/Additional/History) —
               the screen-sized fixed px widths on S/NO..TOTAL AMOUNT (80-160px
               each) sum to more than a portrait page's printable width, so
               DESCRIPTION (the one column with no explicit width) was squeezed
               to only a few px — narrow enough that even its own header
               ("DESCRIPTION") had to wrap one letter per line. That abnormally
               tall header didn't fit in whatever space was left on page 1
               below the report title, so the whole table (its header must stay
               attached, thead is a repeating group) deferred entirely to page
               2, leaving page 1 blank underneath the title. Percentage widths,
               sized per column exactly like the same fix already applied to
               suppliers.php, fix both symptoms in one shot: Description gets
               the largest share so it never collapses, and everything fits
               page 1 immediately below the title. */
            .scope-table thead th {
                font-size: 6.5pt !important;
                line-height: 1.15 !important;
                letter-spacing: 0 !important;
                padding: 3px 2px !important;
                white-space: normal !important;
                word-break: normal !important;
            }
            .scope-table th:nth-child(1), .scope-table td:nth-child(1) { width: 5%  !important; white-space: nowrap !important; }
            .scope-table th:nth-child(2), .scope-table td:nth-child(2) { width: 30% !important; }
            .scope-table th:nth-child(3), .scope-table td:nth-child(3) { width: 8%  !important; }
            .scope-table th:nth-child(4), .scope-table td:nth-child(4) { width: 12% !important; }
            .scope-table th:nth-child(5), .scope-table td:nth-child(5) { width: 14% !important; }
            .scope-table th:nth-child(6), .scope-table td:nth-child(6) { width: 10% !important; }
            .scope-table th:nth-child(7), .scope-table td:nth-child(7) { width: 21% !important; }

            /* TAX (%) is a live <select> on screen — show its current value as
               plain text in print instead of a boxed dropdown with an arrow. */
            .scope-table select.s-tax-rate {
                -webkit-appearance: none !important;
                appearance: none !important;
                border: none !important;
                background: transparent !important;
                box-shadow: none !important;
                pointer-events: none !important;
                padding: 0 !important;
                text-align: center !important;
            }

            /* Every tab's print header (.report-header, incl. the 5 Scope tabs)
               trimmed down — a landscape page is ~30% shorter than portrait, so
               the same header that leaves comfortable room in portrait can eat
               enough of a landscape page that the table right after it doesn't
               fit what's left and gets deferred whole to page 2. Smaller logo
               + tighter line spacing gives every orientation more headroom. */
            .report-header { margin-bottom: 0.6rem !important; }
            .report-header img { max-height: 50px !important; }
            .report-header h2 { font-size: 1.05rem !important; margin-bottom: 2px !important; }
            .report-header h3 { font-size: 0.95rem !important; margin: 2px 0 !important; }
            .report-header h5 { font-size: 0.9rem  !important; margin-bottom: 2px !important; }
            .report-header h6 { font-size: 0.78rem !important; margin: 2px 0 !important; }
        }
    </style>

    <!-- Warehouse Stock & History — dedicated print container -->
    <div id="whStockPrintContainer" style="display:none;"></div>
    <style>
        @media print {
            body.warehouse-stock-print .container-fluid { display: none !important; }
            body.warehouse-stock-print #whStockPrintContainer {
                display: block !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            body.warehouse-stock-print #whStockPrintContainer table {
                page-break-inside: auto;
            }
            body.warehouse-stock-print #whStockPrintContainer tr {
                page-break-inside: avoid;
            }
            @page { size: auto; margin: 10mm; }
        }
    </style>
<script>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_12.php'; ?>
</script>

<script>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_13.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_14.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_15.php'; ?>
<?php include __DIR__ . '/../../../includes/project_view/scripts/pv_js_16.php'; ?>
</script>

<!-- Location cascade engine (Country → Region → District → Ward → Street/Village).
     Same component the external Suppliers / Employees forms use. Tanzania gets
     locked cascading dropdowns; other countries fall back to free text. -->
<script src="<?= getUrl('assets/js/location_cascade.js') ?>"></script>
<script>
$(function () {
    if (typeof initLocationCascade !== 'function') return;
    var LOC_URL = '<?= buildUrl('api/location/options.php') ?>';

    // Procurements → Add Supplier (only when the modal is on the page)
    if ($('#aps_country').length) {
        window.addSupplierCascade = initLocationCascade({
            endpoint: LOC_URL,
            fields: { country: '#aps_country', region: '#aps_state', district: '#aps_city', ward: '#aps_ward', village: '#aps_village' },
            dropdownParent: '#addProjectSupplierModal'
        });
        $('#addProjectSupplierModal').on('shown.bs.modal', function () {
            if (window.addSupplierCascade) window.addSupplierCascade.setValues({ country: 'Tanzania' });
        });
    }
    // Procurements → Edit Supplier (prefilled via setValues() in editProjectSupplier())
    if ($('#eps_country').length) {
        window.editSupplierCascade = initLocationCascade({
            endpoint: LOC_URL,
            fields: { country: '#eps_country', region: '#eps_state', district: '#eps_city', ward: '#eps_ward', village: '#eps_village' },
            dropdownParent: '#editProjectSupplierModal'
        });
    }
});
</script>

<!-- Comments / Notes / Access modal — shared with document_library.php -->
<?php require_once __DIR__ . '/../../../includes/document_activity_modal.php'; ?>

<?php includeFooter(); ?>