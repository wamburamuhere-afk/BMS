<?php
/**
 * BMS Daily Development Report — PDF Generator
 * Date   : 26 May 2026  (Session 2)
 * URL    : http://localhost/bms/scratch/report_daily_2026_05_26_v2.php
 * Covers : Updates 136 – 168 (26 May 2026)
 * Label  : LAST REPORT of 26 May 2026
 */
ob_start();
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../TCPDF/tcpdf.php';
ob_end_clean();

define('RPT_DATE',   '26 May 2026');
define('RPT_REF',    'RPT-BMS-2026-0526B');
define('RPT_VER',    'v1.0');
define('RPT_AUTHOR', 'Wambura Muhere');
define('RPT_EMAIL',  'wambura.muhere@bjptechnologies.co.tz');
define('RPT_WEB',    'www.bjptechnologies.co.tz');
define('RPT_FOR',    'Internal — BJP Technologies Co. Ltd');

// ── PDF class ────────────────────────────────────────────────────────────────
class DailyReport extends TCPDF {
    public function Header() {
        if ($this->getPage() === 1) return;
        $this->SetFillColor(15, 23, 42);
        $this->Rect(0, 0, 220, 12, 'F');
        $this->SetFont('helvetica', 'B', 7.5);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(12, 3);
        $this->Cell(110, 6, 'BJP TECHNOLOGIES CO. LTD  |  Daily Development Report  |  BMS', 0, 0, 'L');
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 6, RPT_REF . '  |  ' . RPT_DATE . '  |  ' . RPT_VER, 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
        $this->SetY(16);
    }
    public function Footer() {
        if ($this->getPage() === 1) return;
        $this->SetY(-14);
        $this->SetFillColor(15, 23, 42);
        $this->Rect(0, $this->GetY() - 2, 220, 20, 'F');
        $this->SetTextColor(148, 163, 184);
        $this->SetFont('helvetica', '', 7.5);
        $this->Cell(0, 8, RPT_AUTHOR . '  |  ' . RPT_EMAIL . '  |  ' . RPT_WEB, 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function sectionHead($pdf, $number, $title) {
    $pdf->Ln(3);
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->Cell(0, 7.5, "  {$number}.  " . strtoupper($title), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(200, 212, 220);
    $pdf->Ln(2);
}
function subHead($pdf, $title) {
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor(30, 64, 175);
    $pdf->Cell(0, 6, $title, 'B', 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetDrawColor(200, 212, 220);
    $pdf->Ln(1);
}
function tHead($pdf, $cols, $widths) {
    $pdf->SetFillColor(30, 41, 59);
    $pdf->SetDrawColor(30, 41, 59);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 7.5);
    foreach ($cols as $i => $col)
        $pdf->Cell($widths[$i], 6.5, "  {$col}", 1, 0, 'L', true);
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetDrawColor(200, 212, 220);
}
function tRow($pdf, $cols, $widths, $alt = false) {
    $pdf->SetFillColor($alt ? 241 : 255, $alt ? 245 : 255, $alt ? 249 : 255);
    $last = count($cols) - 1;
    foreach ($cols as $i => $col) {
        $b = 'B';
        if ($i === 0)     $b .= 'L';
        if ($i === $last) $b .= 'R';
        $pdf->Cell($widths[$i], 5.5, "  {$col}", $b, 0, 'L', true);
    }
    $pdf->Ln();
}
function summaryCard($pdf, $x, $y, $w, $h, $number, $label, $r, $g, $b) {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(210, 220, 230);
    $pdf->Rect($x, $y, $w, $h, 'DF');
    $pdf->SetFillColor($r, $g, $b);
    $pdf->Rect($x, $y, $w, 3.5, 'F');
    $pdf->SetFont('helvetica', 'B', 22);
    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetXY($x, $y + 4);
    $pdf->Cell($w, 12, $number, 0, 0, 'C');
    $pdf->SetFont('helvetica', '', 6.2);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY($x, $y + 17);
    $pdf->Cell($w, 5, strtoupper($label), 0, 0, 'C');
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
}

// ── PDF setup ─────────────────────────────────────────────────────────────────
$pdf = new DailyReport('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('BJPTechnologies');
$pdf->SetAuthor(RPT_AUTHOR);
$pdf->SetTitle('BMS Daily Development Report — 2026-05-26 Session 2');
$pdf->SetSubject('BMS Development Report');
$pdf->SetMargins(12, 18, 12);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(14);
$pdf->SetAutoPageBreak(true, 18);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->SetFont('helvetica', '', 9);

// ═══════════════════════════════════════════════════════════════════════════
// PAGE 1 — COVER
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

$pdf->SetFillColor(15, 23, 42);
$pdf->Rect(0, 0, 210, 80, 'F');
$pdf->SetFillColor(30, 64, 175);
$pdf->Rect(0, 0, 4.5, 80, 'F');
$pdf->SetFillColor(20, 184, 166);
$pdf->Rect(0, 80, 210, 2.5, 'F');

$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(20, 184, 166);
$pdf->SetXY(18, 22);
$pdf->Cell(0, 5, 'DEVELOPMENT REPORT', 0, 1, 'L');

$pdf->SetFont('helvetica', 'B', 22);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetX(18);
$pdf->Cell(0, 10, 'Daily Progress Report', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(148, 163, 184);
$pdf->SetX(18);
$pdf->Cell(0, 7, 'BMS — Business Management System', 0, 1, 'L');

$pdf->SetFillColor(30, 41, 59);
$pdf->Rect(18, 55, 85, 0.7, 'F');
$pdf->SetFont('helvetica', '', 7.5);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetXY(18, 59);
$pdf->Cell(0, 5, RPT_REF . '  |  ' . RPT_DATE . '  |  ' . RPT_VER, 0, 1, 'L');

$metrics = [
    ['33',  'Changelog Updates',            59,  130, 246],
    ['4',   'Major Work Streams',           99,  102, 241],
    ['9',   'Doc Types with E-Signatures',  34,  197,  94],
    ['1',   'DB Migration Created',        239,   68,  68],
    ['14',  'CLI Test Assertions',          20,  184, 166],
    ['140+','Files Touched',                6,  182, 212],
    ['5',   'Bug Fixes',                  249,  115,  22],
    ['13',  'Dashboard Alert Types Scoped',139,   92, 246],
];
$cardW = 45; $cardH = 26; $gapX = 2; $sx = 12;
$row1Y = 88; $row2Y = $row1Y + $cardH + 3;
foreach ($metrics as $idx => $m) {
    $col = $idx % 4;
    $y   = $idx < 4 ? $row1Y : $row2Y;
    $x   = $sx + $col * ($cardW + $gapX);
    summaryCard($pdf, $x, $y, $cardW, $cardH, $m[0], $m[1], $m[2], $m[3], $m[4]);
}

$metaStartY = $row2Y + $cardH + 8;
$pdf->SetFillColor(247, 249, 252);
$pdf->SetDrawColor(210, 220, 230);
$pdf->Rect(18, $metaStartY, 174, 58, 'DF');
$metaItems = [
    ['Report Reference', RPT_REF],
    ['Report Date',      RPT_DATE . ' (Session 2 — afternoon)'],
    ['Coverage Window',  '26 May 2026 (current working day)'],
    ['Update Range',     'Updates 136 – 168 (33 entries)'],
    ['Version',          RPT_VER],
    ['Prepared By',      RPT_AUTHOR],
    ['Prepared For',     RPT_FOR],
];
$mY = $metaStartY + 3;
foreach ($metaItems as $i => $item) {
    if ($i % 2 === 1) { $pdf->SetFillColor(237, 242, 248); $pdf->Rect(18, $mY - 1, 174, 7.5, 'F'); }
    $pdf->SetFont('helvetica', '', 6.8); $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(22, $mY); $pdf->Cell(50, 4, strtoupper($item[0]), 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 8.5); $pdf->SetTextColor(15, 23, 42);
    $pdf->SetXY(70, $mY); $pdf->Cell(118, 4, $item[1], 0, 1, 'L');
    $mY += 7.5;
}

$hlY = $metaStartY + 62;
$pdf->SetFillColor(15, 23, 42);
$pdf->Rect(18, $hlY, 174, 6, 'F');
$pdf->SetFont('helvetica', 'B', 7.5); $pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(22, $hlY + 1); $pdf->Cell(0, 4, 'KEY HIGHLIGHTS', 0, 1, 'L');

$highlights = [
    'E-signature pipeline COMPLETE — all 9 document types (Invoice, PO, GRN, Quotation, Expense, Sales Order, DN, IPC, RFQ)',
    'workflow_signature_row.php canonical partial — single source of truth for all print-page signature blocks',
    'Phase 1: "Digitally signed by [name], date, Ref:" protocol text now embedded directly into signed PDFs via pdf-lib',
    'Dashboard fully secured: all 13 alert types + 6 KPI queries + pending approvals role-gated and project-scoped',
    'CI/CD fixed: removed shivammathur/setup-php@v2 dependency (archive SHA inaccessible); now uses system PHP',
    '5 bug fixes: missing workflow_signatures table, missing capture calls (SO/Quotation), PDF preview, nav link keys',
    'Feature branch feat/esignature-phase3-phase4-cicd-fix pushed; 225 pre-push CI checks all passing',
];
$pdf->SetFillColor(252, 253, 254); $pdf->SetDrawColor(210, 220, 230);
$pdf->Rect(18, $hlY + 6, 174, count($highlights) * 7 + 4, 'DF');
$hlItemY = $hlY + 9;
foreach ($highlights as $h) {
    $pdf->SetFillColor(20, 184, 166); $pdf->Rect(22, $hlItemY + 1.5, 1.5, 1.5, 'F');
    $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(30, 41, 59);
    $pdf->SetXY(26, $hlItemY); $pdf->Cell(0, 5, $h, 0, 1, 'L');
    $hlItemY += 7;
}

$pdf->SetAutoPageBreak(false);
$pdf->SetFont('helvetica', '', 7); $pdf->SetTextColor(150, 160, 175);
$pdf->SetXY(12, 272);
$pdf->Cell(0, 5, 'INTERNAL USE ONLY  —  BJP Technologies Co. Ltd  —  Confidential Development Documentation', 0, 0, 'C');
$pdf->SetAutoPageBreak(true, 18);
$pdf->SetDrawColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0);


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 2 — EXECUTIVE SUMMARY + WORK STREAMS
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHead($pdf, 1, 'Executive Summary');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'This report covers updates 136 – 168 (33 changelog entries) delivered on 26 May 2026. ' .
    'The day had two primary themes: (1) securing the dashboard and navigation layer with ' .
    'role gates and project-scope filters across all 13 alert types, 6 KPI queries, pending ' .
    'approvals, the performance chart API, and the Reports / Docs nav links; and (2) completing ' .
    'the full e-signature pipeline across all 9 BMS document types. ' .
    'The e-signature work spanned four phases: Phase 1 embeds a "Digitally signed by" protocol ' .
    'text label directly into PDFs at signing time; Phase 2 introduced the workflow_signatures ' .
    'database table; Phase 3 added workflowCaptureSignature() calls to every review and approve ' .
    'API endpoint; Phase 4 replaced all inline signature HTML/CSS blocks on every print page ' .
    'with a single canonical workflow_signature_row.php partial, eliminating duplication across ' .
    '8 print pages. The extension round then applied Phase 3+4 to the three previously-missed ' .
    'documents (Delivery Note, IPC, RFQ). Five bug fixes were resolved including a missing ' .
    'workflow_signatures table migration, missing capture calls in Sales Order and Quotation ' .
    'review/approve APIs, a PDF preview failure in the signing wizard, and a broken GitHub ' .
    'Actions CI step. All 225 pre-push test assertions pass.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, 'Work Streams Overview');
tHead($pdf, ['#', 'Work Stream', 'Updates', 'Status'], [8, 118, 22, 38]);
tRow($pdf, ['1', 'Dashboard security — role gates + project scope on all widgets/alerts',   '136–148', 'Complete'], [8, 118, 22, 38], false);
tRow($pdf, ['2', 'E-signature Phases 1–4 — full pipeline for Invoice/PO/GRN/Quotation/Expense/SO', '149–157', 'Complete'], [8, 118, 22, 38], true);
tRow($pdf, ['3', 'E-signature extension — Phase 3+4 for Delivery Note, IPC, RFQ',           '158–165', 'Complete'], [8, 118, 22, 38], false);
tRow($pdf, ['4', 'Bug fixes + CI/CD + Sales Order/Quotation capture gap',                   '166–168', 'Complete'], [8, 118, 22, 38], true);
$pdf->Ln(3);

subHead($pdf, 'Update Distribution (26 May 2026)');
tHead($pdf, ['Update Range', 'Theme'], [30, 156]);
tRow($pdf, ['136–143', 'Dashboard alerts, KPI cards, pending approvals, performance chart, widget fixes'],  [30, 156], false);
tRow($pdf, ['144–148', 'Dashboard recent activities, activity log purge, Docs/Reports nav fixes, notification banner'], [30, 156], true);
tRow($pdf, ['149–156', 'E-signature Phases 1–4: PDF protocol text, workflow_signatures table, capture calls, print partials'], [30, 156], false);
tRow($pdf, ['157',     'Q3 fix: "Failed to load PDF preview" in signing wizard Position & Sign step'],      [30, 156], true);
tRow($pdf, ['158–165', 'E-signature Phase 3+4 extension: Delivery Note, IPC, RFQ'],                         [30, 156], false);
tRow($pdf, ['166–168', 'CI/CD fix, workflow_signatures migration, SO/Quotation capture gap'],                [30, 156], true);


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 3 — WORK STREAM 1: DASHBOARD SECURITY
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHead($pdf, 2, 'Work Stream 1 — Dashboard Security: Role Gates + Project Scope (Updates 136–148)');

$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'The dashboard (app/dashboard.php) was comprehensively secured across all query groups. ' .
    'Each of the 13 alert types in get_system_alerts(), the 6 KPI query groups in ' .
    'get_business_stats(), and the pending-approvals query in get_pending_approvals() now has ' .
    '(a) a canView/canReview role gate so the query is skipped for unauthorized roles, and ' .
    '(b) scopeFilterSqlNullable() injected into the WHERE clause so non-admin users only see ' .
    'records from their assigned projects. NULL project_id records pass through for all ' .
    'permitted users (global/unlinked records remain visible). The performance chart API ' .
    '(api/get_performance_data.php) received the same treatment. Navigation fixes corrected ' .
    'wrong page_keys in the Reports and Docs dropdowns, which had silently hidden those menus ' .
    'from all non-admin users.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '2.1  Dashboard Alert Types Secured (Update 136)');
tHead($pdf, ['Alert Type', 'Role Gate', 'Scope Applied'], [40, 64, 82]);
tRow($pdf, ['low_stock / negative_stock / expiring', 'canView(products)',          'scopeFilterSqlNullable(project, p)'],         [40, 64, 82], false);
tRow($pdf, ['overdue invoices',                      'canView(invoices)',           'scopeFilterSqlNullable(project, invoices)'],  [40, 64, 82], true);
tRow($pdf, ['quote_expiring',                        'canView(quotations)',         'scopeFilterSqlNullable(project, q)'],         [40, 64, 82], false);
tRow($pdf, ['grn_pending',                           'canView(grn) || canView(po)', 'scopeFilterSqlNullable(project, po)'],        [40, 64, 82], true);
tRow($pdf, ['leave_pending',                         'canReview/canApprove(leaves)','scopeFilterSqlNullable via employees alias'], [40, 64, 82], false);
tRow($pdf, ['credit_over',                           'canView(invoices/customers)', 'scopeFilterSqlNullable(customer, c)'],        [40, 64, 82], true);
tRow($pdf, ['cash_shift_open / bank_recon / payroll','canView per module',          'No project scope (company-wide)'],            [40, 64, 82], false);
tRow($pdf, ['tender_deadline',                       'canView(tenders)',            'No project scope (tenders have no project_id)'],[40, 64, 82], true);
$pdf->Ln(2);

subHead($pdf, '2.2  KPI Cards + Pending Approvals Secured (Updates 137–138)');
tHead($pdf, ['Query Group', 'Gate', 'Scope'], [44, 60, 82]);
tRow($pdf, ['Sales / Invoices',    'canView(invoices) || hasReportsAccess()', 'scopeFilterSqlNullable(project, invoices)'], [44, 60, 82], false);
tRow($pdf, ['Purchases',           'canView(purchase_orders)',                'scopeFilterSqlNullable(project, po)'],       [44, 60, 82], true);
tRow($pdf, ['Inventory',           'canView(products)',                       'scopeFilterSqlNullable(project, p)'],        [44, 60, 82], false);
tRow($pdf, ['Customers',           'canView(customers)',                      'scopeFilterSqlNullable(customer, c)'],       [44, 60, 82], true);
tRow($pdf, ['Expenses',            'canView(expenses)',                       'scopeFilterSqlNullable(project, e)'],        [44, 60, 82], false);
tRow($pdf, ['POS Today',           'canView(pos)',                            'None — shared terminal'],                   [44, 60, 82], true);
tRow($pdf, ['Pending Approvals',   'canApprove/canReview per module',         'scopeFilterSqlNullable on expenses + POs'], [44, 60, 82], false);
$pdf->Ln(2);

subHead($pdf, '2.3  Navigation + Miscellaneous Fixes (Updates 139–148)');
tHead($pdf, ['Update', 'File', 'Change'], [14, 76, 96]);
tRow($pdf, ['139', 'app/dashboard.php',           'Quick Links: removed dead duplicate branch; empty-state for zero-permission users'],  [14, 76, 96], false);
tRow($pdf, ['140', 'app/dashboard.php',           'KPI card gates aligned; SUM(grand_total - COALESCE(paid_amount,0)) NULL-safe'],       [14, 76, 96], true);
tRow($pdf, ['141', 'api/get_performance_data.php','Role gate + scopeFilterSqlNullable on revenue + expenses; skip comment removed'],      [14, 76, 96], false);
tRow($pdf, ['142', 'app/dashboard.php',           'Customer Overview active% clamped; Inventory widget gate expanded to inventory_report'],[14, 76, 96], true);
tRow($pdf, ['143', 'app/dashboard.php',           'Inventory count: added AND p.is_service = 0 to match products.php'],                  [14, 76, 96], false);
tRow($pdf, ['144', 'app/dashboard.php',           'Recent Activities: canView(audit_logs) gate; can_view_all expanded for Auditor'],      [14, 76, 96], true);
tRow($pdf, ['145', 'app/activity_log.php',        'Period filter presets (Today/Week/Month/Year/Custom) + admin bulk purge with preview'],[14, 76, 96], false);
tRow($pdf, ['146', 'header.php',                  'Docs nav: library→document_library, templates→document_templates; audit_logs added'], [14, 76, 96], true);
tRow($pdf, ['147', 'core/permissions.php',        'hasReportsAccess() expanded to all 20 report keys; 17 missing keys inserted via SQL'],  [14, 76, 96], false);
tRow($pdf, ['148', 'app/dashboard.php',           'Notification banner: removed outer notification_center gate; any user with alerts sees it'],[14, 76, 96], true);


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 4 — WORK STREAM 2: E-SIGNATURE PHASES 1–4
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHead($pdf, 3, 'Work Stream 2 — E-signature Pipeline: Phases 1–4 (Updates 149–157)');

$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'The e-signature feature was extended beyond the external PDF wizard to cover the internal ' .
    'workflow print pages. The result is a four-phase pipeline: signed PDFs carry a visible ' .
    '"Digitally signed by" text label (Phase 1); a workflow_signatures table stores one row per ' .
    'entity + action (Phase 2); every review/approve API now writes to that table at the moment ' .
    'the action is performed (Phase 3); and every print page reads the table and renders the ' .
    'signature image plus timestamp above the reviewer/approver name line (Phase 4). The ' .
    'canonical workflow_signature_row.php partial is the single source of truth for all ' .
    'print pages — no inline signature HTML/CSS duplication remains.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '3.1  Phase 1 — "Digitally Signed By" Protocol Text on PDFs');
$pdf->SetFont('helvetica', '', 8.5);
$pdf->MultiCell(0, 5.2,
    'In select_document_add_esignature.php, after the signature image is embedded into the PDF ' .
    'via pdf-lib, three lines of protocol text are drawn directly on the page: ' .
    '"Digitally signed by: [SIGNER_NAME]", the date and time, and "Ref: [signing reference]". ' .
    'Font: HelveticaBold (name) + Helvetica (date/ref) in ink-blue (#0a6efd) and gray. ' .
    'The signing certificate page was also updated: "Signed by" → "Digitally signed by"; ' .
    'date row appends "(server-recorded, tamper-evident)".',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '3.2  Phase 2 — workflow_signatures Table');
tHead($pdf, ['Column', 'Type', 'Notes'], [44, 50, 92]);
tRow($pdf, ['entity_type',      'VARCHAR(50)',              'e.g. invoice, purchase_order, grn, quotation, sales_order, delivery, ipc, rfq, expense'], [44, 50, 92], false);
tRow($pdf, ['entity_id',        'INT UNSIGNED',             'PK of the parent document'],                                                                [44, 50, 92], true);
tRow($pdf, ['action',           "ENUM('created','reviewed','approved')", 'Workflow stage captured'],                                                     [44, 50, 92], false);
tRow($pdf, ['user_id/name/role','INT + VARCHAR',            'Actor snapshot at time of action'],                                                         [44, 50, 92], true);
tRow($pdf, ['sig_path',         'VARCHAR(500) NULL',        'Path from user_signatures; NULL if user has no signature on file'],                         [44, 50, 92], false);
tRow($pdf, ['signed_at',        'TIMESTAMP auto-update',    'Server-side timestamp; updates on ON DUPLICATE KEY UPDATE'],                               [44, 50, 92], true);
tRow($pdf, ['UNIQUE KEY',       'uq_entity_action',         '(entity_type, entity_id, action) — prevents duplicates; re-runs overwrite cleanly'],       [44, 50, 92], false);
$pdf->Ln(2);

subHead($pdf, '3.3  Phase 3 — Capture Calls Added to Workflow APIs');
tHead($pdf, ['API File', 'Entity Type', 'Action Captured'], [90, 42, 54]);
tRow($pdf, ['api/account/review_invoice.php',          'invoice',        'reviewed'], [90, 42, 54], false);
tRow($pdf, ['api/account/approve_invoice.php',         'invoice',        'approved'], [90, 42, 54], true);
tRow($pdf, ['api/account/review_purchase_order.php',   'purchase_order', 'reviewed'], [90, 42, 54], false);
tRow($pdf, ['api/account/approve_purchase_order.php',  'purchase_order', 'approved'], [90, 42, 54], true);
tRow($pdf, ['api/review_grn.php',                      'grn',            'reviewed'], [90, 42, 54], false);
tRow($pdf, ['api/approve_grn.php',                     'grn',            'approved'], [90, 42, 54], true);
tRow($pdf, ['api/account/update_expense_status.php',   'expense',        'reviewed + approved'], [90, 42, 54], false);
tRow($pdf, ['api/account/approve_quotation.php',       'quotation',      'approved'], [90, 42, 54], true);
$pdf->Ln(2);

subHead($pdf, '3.4  Phase 4 — canonical workflow_signature_row.php + Print Pages Updated');
tHead($pdf, ['Print Page', 'Entity Key', 'Old Approach → New'], [80, 30, 76]);
tRow($pdf, ['app/bms/invoice/invoice_print.php',        'invoice',        'Inline 15-line CSS+HTML → partial include'],    [80, 30, 76], false);
tRow($pdf, ['app/bms/sales/quotations/print_quotation.php', 'quotation',  'Inline CSS+HTML → partial include'],            [80, 30, 76], true);
tRow($pdf, ['api/account/print_purchase_order.php',     'purchase_order', '$wf expanded with sig keys + __include_css'],   [80, 30, 76], false);
tRow($pdf, ['app/bms/grn/grn_print.php',                'grn',            '$wf expanded with sig keys + __include_css'],   [80, 30, 76], true);
tRow($pdf, ['app/bms/sales/print_sales_order.php',      'sales_order',    'Duplicate CSS block removed → partial include'],[80, 30, 76], false);
$pdf->Ln(2);

subHead($pdf, '3.5  Q3 Fix — "Failed to Load PDF Preview" in Signing Wizard (Update 157)');
$pdf->SetFont('helvetica', '', 8.5);
$pdf->MultiCell(0, 5.2,
    'Root cause: the download endpoint (document_library?action=download) sends ' .
    'Content-Disposition: attachment, which some pdf.js XHR implementations reject, causing the ' .
    '"Failed to load PDF preview" error in the Position & Sign step. Fix: replaced the download ' .
    'URL in initPlacement() with a direct file URL built from selectedDocPath (already available ' .
    'as a JS variable from the document radio button data-path attribute). The signing step ' .
    'continues to use the download endpoint for fetch(), which handles the header correctly.',
    0, 'J');


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 5 — WORK STREAM 3+4: EXTENSION + BUG FIXES
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHead($pdf, 4, 'Work Stream 3 — E-signature Extension: Delivery Note, IPC, RFQ (Updates 158–165)');

$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'Three document types were missed in the initial Phase 3+4 implementation: Delivery Note ' .
    '(entity_type "delivery"), Interim Payment Certificate (IPC, entity_type "ipc"), and ' .
    'Request for Quotation (RFQ, entity_type "rfq"). Both phases were applied to all three.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '4.1  Phase 3 Extension — Capture Calls');
tHead($pdf, ['API File', 'Entity Type', 'Action', 'Notes'], [82, 30, 28, 46]);
tRow($pdf, ['api/review_dn.php',                    'delivery', 'reviewed', 'Added before $pdo->commit(); actor already set'], [82, 30, 28, 46], false);
tRow($pdf, ['api/approve_dn.php',                   'delivery', 'approved', 'Added after stock loop, before logActivity'],      [82, 30, 28, 46], true);
tRow($pdf, ['api/review_rfq.php',                   'rfq',      'reviewed', 'Added require workflow.php + workflowActorSnapshot() replacement'], [82, 30, 28, 46], false);
tRow($pdf, ['api/approve_rfq.php',                  'rfq',      'approved', 'Same pattern as review_rfq'],                      [82, 30, 28, 46], true);
tRow($pdf, ['api/operations/update_ipc_status.php', 'ipc',      'reviewed', "IPC status 'Viewed' maps to action 'reviewed'"],   [82, 30, 28, 46], false);
tRow($pdf, ['api/operations/update_ipc_status.php', 'ipc',      'approved', "IPC status 'Approved' maps to action 'approved'"], [82, 30, 28, 46], true);
$pdf->Ln(2);

subHead($pdf, '4.2  Phase 4 Extension — Print Pages');
tHead($pdf, ['Print Page', 'Old Approach', 'New Approach'], [80, 52, 54]);
tRow($pdf, ['api/account/print_delivery_note.php', '.signature-table CSS (27 lines) + <table> HTML (35 lines)', 'workflow_signature_row.php partial'], [80, 52, 54], false);
tRow($pdf, ['app/bms/operations/print_ipc.php',    'Inline .signature-box CSS + <div> block (30 lines)',        'workflow_signature_row.php partial'], [80, 52, 54], true);
tRow($pdf, ['api/account/print_rfq.php',           'Dead .signature-box CSS + <table class="auth-table"> (50 lines)', 'workflow_signature_row.php partial'], [80, 52, 54], false);
$pdf->Ln(3);

sectionHead($pdf, 5, 'Work Stream 4 — Bug Fixes + CI/CD + Sales Order / Quotation Gap (Updates 166–168)');

subHead($pdf, '5.1  Bug Fixes');
tHead($pdf, ['#', 'Bug', 'Root Cause', 'Fix'], [7, 55, 62, 62]);
tRow($pdf, ['1', 'CI/CD pipeline failing on every push',
                 'shivammathur/setup-php@v2 SHA 7c071dfe inaccessible — GitHub could not serve the archive',
                 '.github/workflows/deploy.yml: replaced the action with plain php --version (ubuntu-24.04 ships PHP 8.3)'],
                 [7, 55, 62, 62], false);
tRow($pdf, ['2', '"Table workflow_signatures doesn\'t exist" on every review/approve action',
                 'Migration file for the table was never written, only the SQL file (gitignored)',
                 'Created migrations/2026_05_26_create_workflow_signatures.php (CREATE TABLE IF NOT EXISTS)'],
                 [7, 55, 62, 62], true);
tRow($pdf, ['3', 'Sales Order review: no signature captured in workflow_signatures',
                 'review_sales_order.php had workflow.php + actor snapshot but no capture call',
                 'Added workflowCaptureSignature(pdo, sales_order, so_id, reviewed, ...) before commit()'],
                 [7, 55, 62, 62], false);
tRow($pdf, ['4', 'Sales Order approve: same gap',
                 'approve_sales_order.php same pattern — actor present, capture call absent',
                 'Added workflowCaptureSignature(pdo, sales_order, so_id, approved, ...) before commit()'],
                 [7, 55, 62, 62], true);
tRow($pdf, ['5', 'Quotation review: no signature captured',
                 'review_quotation.php was missing workflow.php, actorSnapshot(), AND capture call entirely',
                 'Added require workflow.php + workflowActorSnapshot() + workflowCaptureSignature(..., reviewed, ...)'],
                 [7, 55, 62, 62], false);
$pdf->Ln(3);

subHead($pdf, '5.2  Pre-push Test Suite Fixes (4 test files)');
tHead($pdf, ['Test File', 'Old Assertion', 'Updated Assertion'], [74, 52, 60]);
tRow($pdf, ['test_dn_three_approval_cli.php',      'preg_match for "prepared/reviewed/approved by" in print file',   'str_contains for workflow_signature_row.php include'],  [74, 52, 60], false);
tRow($pdf, ['test_invoice_three_approval_cli.php', 'preg_match for "Created/Reviewed/Approved By" in print file',    'str_contains for workflow_signature_row.php include'],  [74, 52, 60], true);
tRow($pdf, ['test_quotations_cli.php',             '3x want() for Created/Reviewed/Approved By strings',             '1x want() for workflow_signature_row.php include'],     [74, 52, 60], false);
tRow($pdf, ['test_sales_order_three_approval_cli.php', '.signature-line small checked in print_sales_order.php',     'Checks canonical partial instead of host print page'], [74, 52, 60], true);


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 6 — MIGRATIONS + TEST SUITES + NEXT STEPS
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHead($pdf, 6, 'Database Schema Changes — 1 New Migration');

tHead($pdf, ['Migration File', 'Purpose'], [108, 78]);
tRow($pdf, ['2026_05_26_create_workflow_signatures.php',
             'CREATE TABLE IF NOT EXISTS workflow_signatures — stores one signature capture row per entity+action; UNIQUE KEY prevents duplicates'],
             [108, 78], false);
$pdf->Ln(3);

sectionHead($pdf, 7, 'Test Suites — Added or Extended');

tHead($pdf, ['Test File', 'Change', 'Coverage'], [82, 24, 80]);
tRow($pdf, ['scratch/test_esignature_workflow_cli.php', 'New',      '14 assertions: table schema, UNIQUE KEY upsert, workflowCaptureSignature DB write + return shape, getWorkflowSignatures result, workflow_signature_row.php HTML output. All 14 pass.'], [82, 24, 80], false);
tRow($pdf, ['test_dn_three_approval_cli.php',           'Updated',  'Signature-labels assertion updated to check partial include (not inline content)'],  [82, 24, 80], true);
tRow($pdf, ['test_invoice_three_approval_cli.php',      'Updated',  'Same fix — checks workflow_signature_row.php include'],                               [82, 24, 80], false);
tRow($pdf, ['test_quotations_cli.php',                  'Updated',  '3 label checks → 1 partial-include check; test count: 130 → 128'],                   [82, 24, 80], true);
tRow($pdf, ['test_sales_order_three_approval_cli.php',  'Updated',  '.signature-line small CSS check moved to canonical partial'],                         [82, 24, 80], false);
$pdf->Ln(3);

sectionHead($pdf, 8, 'E-signature Architecture — Final State');

$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'As of 26 May 2026, the BMS e-signature system covers all 9 document types end-to-end. ' .
    'The workflowCaptureSignature() helper writes to workflow_signatures at every review and ' .
    'approve action. getWorkflowSignatures() reads from it on every print page. The ' .
    'workflow_signature_row.php partial renders the three-column Created By / Reviewed By / ' .
    'Approved By block — with signature image + "Digitally signed" protocol text when a ' .
    'sig_path is present, or plain name/role text when it is not. Backward-compatibility is ' .
    'preserved: all $wf keys are optional; pages without sig data render identically to before.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '8.1  Document Coverage Matrix');
tHead($pdf, ['Document Type', 'Entity Key', 'Phase 3 (capture)', 'Phase 4 (print)'], [40, 34, 60, 52]);
tRow($pdf, ['Invoice',         'invoice',        'review_invoice + approve_invoice',             'invoice_print.php'],          [40, 34, 60, 52], false);
tRow($pdf, ['Purchase Order',  'purchase_order', 'review_po + approve_po',                       'print_purchase_order.php'],   [40, 34, 60, 52], true);
tRow($pdf, ['GRN',             'grn',            'review_grn + approve_grn',                     'grn_print.php'],              [40, 34, 60, 52], false);
tRow($pdf, ['Quotation',       'quotation',      'review_quotation + approve_quotation',          'print_quotation.php'],       [40, 34, 60, 52], true);
tRow($pdf, ['Sales Order',     'sales_order',    'review_sales_order + approve_sales_order',      'print_sales_order.php'],    [40, 34, 60, 52], false);
tRow($pdf, ['Expense',         'expense',        'update_expense_status (reviewed + approved)',   'N/A (no print page yet)'],   [40, 34, 60, 52], true);
tRow($pdf, ['Delivery Note',   'delivery',       'review_dn + approve_dn',                       'print_delivery_note.php'],   [40, 34, 60, 52], false);
tRow($pdf, ['IPC',             'ipc',            "update_ipc_status (Viewed→reviewed, Approved→approved)", 'print_ipc.php'],   [40, 34, 60, 52], true);
tRow($pdf, ['RFQ',             'rfq',            'review_rfq + approve_rfq',                     'print_rfq.php'],              [40, 34, 60, 52], false);
$pdf->Ln(3);

sectionHead($pdf, 9, 'Next Steps');
tHead($pdf, ['#', 'Task', 'Priority'], [8, 152, 26]);
tRow($pdf, ['1', 'Merge PR feat/esignature-phase3-phase4-cicd-fix → main (runs migrations on all servers)', 'High'],   [8, 152, 26], false);
tRow($pdf, ['2', 'Verify workflow_signatures table created on all 5 production databases after deploy',     'High'],   [8, 152, 26], true);
tRow($pdf, ['3', 'Test full e-signature flow on production: review DN → approve → print → verify sig image','High'],   [8, 152, 26], false);
tRow($pdf, ['4', 'Q2 (Boss): Capture "created" action signature at document creation time (9 create APIs)', 'Medium'], [8, 152, 26], true);
tRow($pdf, ['5', 'Security: MIME check on quick_upload_document.php (identified, not yet implemented)',     'Medium'], [8, 152, 26], false);
tRow($pdf, ['6', 'Security: soft-delete on delete_signature.php (identified, not yet implemented)',         'Medium'], [8, 152, 26], true);
tRow($pdf, ['7', 'Expense print page — add Phase 4 (print_expense.php if it exists)',                       'Low'],    [8, 152, 26], false);
tRow($pdf, ['8', 'Add rate-limiting to login and write APIs (carry-over from prior reports)',                'Low'],    [8, 152, 26], true);

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetTextColor(0, 0, 0);

// Output — D forces download
$pdf->Output('BMS_Daily_Report_2026-05-26_v2.pdf', 'D');
