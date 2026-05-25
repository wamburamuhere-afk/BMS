<?php
/**
 * BMS — Project-Scope Coverage Audit (Phase F)
 *
 * Walks every PHP file under app/ and api/, finds any that SELECT or JOIN
 * against a project-scoped table, and flags those that carry no scope-filter
 * call at all.
 *
 * A file is considered "GUARDED" if it contains at least one of:
 *   scopeFilterSql(            — list-page SQL filter (strict IN)
 *   scopeFilterSqlNullable(    — nullable-column variant
 *   assertScopeForRecord(      — write-API / detail-page gate
 *   assertScopeForRecordHtml(  — same, for print pages
 *   assertScopeForEmployee(    — HR write-API gate via employee_id
 *   assertScopeForEmployeeRecord( — HR leaf-record gate (leaves/payroll)
 *   userCan(                   — inline project / resource membership check
 *   // scope-audit: skip       — deliberate opt-out with documented justification
 *
 * A file is considered "UNSCOPED" if it queries a scoped table but has none
 * of the above guards. Unscoped read pages are a lower risk than unscoped
 * write endpoints — both show up here so the count can be tracked over time.
 *
 * Scoped tables — any file that SELECTs or JOINs these needs a scope guard:
 *
 *   Direct (project_id column):
 *     projects, project_milestones, project_inspections,
 *     project_planning_reports, project_planning_tasks,
 *     project_progress_reports, project_progress_report_details,
 *     project_progress_report_attachments, project_scope_documents,
 *     project_notes, project_goods_returns, project_goods_return_items,
 *     project_sub_contractors, sub_contractor_projects,
 *     interim_payment_certificates, employees, budgets, invoices,
 *     quotations, sales_orders, purchase_orders, purchase_receipts,
 *     purchase_returns, rfq, deliveries, delivery_orders, expenses,
 *     payment_vouchers, sc_payments, supplier_invoices, payments,
 *     stock_movements, products, nip_material_lists, pos_sales
 *
 *   Derived (transitive via session scope arrays):
 *     warehouses, product_stocks, suppliers, supplier_payments,
 *     sub_contractors, customers, customer_documents, customer_groups,
 *     payroll, leaves, attendance, payslips
 *
 * Output: JSON on stdout.
 *
 * Run:
 *   php scratch/project_scope_audit.php
 *   php scratch/project_scope_audit.php > scratch/project_scope_findings.json
 *
 * Exit 0 always. The consumer (tests/test_project_scope_cli.php) sets the
 * exit code based on ceiling comparison.
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));

// ── Scoped table list ─────────────────────────────────────────────────────
$SCOPED_TABLES = [
    // Direct project_id column
    'projects',
    'project_milestones',
    'project_inspections',
    'project_planning_reports',
    'project_planning_tasks',
    'project_progress_reports',
    'project_progress_report_details',
    'project_progress_report_attachments',
    'project_scope_documents',
    'project_notes',
    'project_goods_returns',
    'project_goods_return_items',
    'project_sub_contractors',
    'sub_contractor_projects',
    'interim_payment_certificates',
    'employees',
    'budgets',
    'invoices',
    'quotations',
    'sales_orders',
    'purchase_orders',
    'purchase_receipts',
    'purchase_returns',
    'rfq',
    'deliveries',
    'delivery_orders',
    'expenses',
    'payment_vouchers',
    'sc_payments',
    'supplier_invoices',
    'payments',
    'stock_movements',
    'products',
    'nip_material_lists',
    'pos_sales',
    // Derived via session scope
    'warehouses',
    'product_stocks',
    'suppliers',
    'supplier_payments',
    'sub_contractors',
    'customers',
    'customer_documents',
    'customer_groups',
    'payroll',
    'leaves',
    'attendance',
    'payslips',
];

// Pre-build patterns once (case-insensitive, word-boundary on table name)
$TABLE_PATTERNS = [];
foreach ($SCOPED_TABLES as $t) {
    // Match "FROM tablename" or "JOIN tablename" followed by whitespace, backtick, or end-of-string
    $TABLE_PATTERNS[$t] = '/(?:FROM|JOIN)\s+[`]?' . preg_quote($t, '/') . '[`]?(?:\s|$|[,\)\/])/i';
}

// ── Scope-guard markers — any one marks the file as protected ─────────────
$GUARD_PATTERNS = [
    'scopeFilterSql(',
    'scopeFilterSqlNullable(',
    'assertScopeForRecord(',
    'assertScopeForRecordHtml(',
    'assertScopeForEmployee(',
    'assertScopeForEmployeeRecord(',
    'userCan(',
    '// scope-audit: skip',
];

// ── Subdirectory skip list — non-runtime files ────────────────────────────
$SKIP_DIRS = ['/scratch/', '/tmp/', '/migrations/', '/tests/', '/core/'];

// ── Scan ──────────────────────────────────────────────────────────────────
$total_files    = 0;
$scoped_files   = 0;
$guarded_files  = 0;
$unscoped       = [];

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iter as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;

    $abs = str_replace('\\', '/', $file->getPathname());
    $rel = ltrim(str_replace($root . '/', '', $abs), '/');

    // Only audit app/ and api/
    if (strpos($rel, 'app/') !== 0 && strpos($rel, 'api/') !== 0) continue;

    // Skip non-runtime sub-paths
    foreach ($SKIP_DIRS as $skip) {
        if (strpos($rel, $skip) !== false) continue 2;
    }

    $total_files++;
    $src = file_get_contents($abs);

    // Detect which scoped tables this file references
    $matched_tables = [];
    foreach ($TABLE_PATTERNS as $table => $pattern) {
        if (preg_match($pattern, $src)) {
            $matched_tables[] = $table;
        }
    }

    if (empty($matched_tables)) continue;   // no scoped-table queries → nothing to gate
    $scoped_files++;

    // Detect whether any scope guard is present
    $has_guard = false;
    foreach ($GUARD_PATTERNS as $guard) {
        if (strpos($src, $guard) !== false) {
            $has_guard = true;
            break;
        }
    }

    if ($has_guard) {
        $guarded_files++;
    } else {
        $unscoped[] = [
            'file'   => $rel,
            'tables' => $matched_tables,
        ];
    }
}

// ── Output JSON ───────────────────────────────────────────────────────────
$result = [
    'total_files'    => $total_files,
    'scoped_files'   => $scoped_files,       // files that query at least one scoped table
    'guarded_files'  => $guarded_files,      // of those, files that carry a scope guard
    'unscoped_count' => count($unscoped),    // files with scoped queries but NO guard
    'unscoped'       => $unscoped,
    'coverage_pct'   => $scoped_files > 0
        ? round($guarded_files / $scoped_files * 100, 1)
        : 100.0,
    'generated_at'   => date('Y-m-d H:i:s'),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
