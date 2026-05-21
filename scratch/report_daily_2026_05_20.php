<?php
/**
 * BMS Daily Development Report — PDF Generator
 * Date   : 2026-05-20
 * URL    : http://localhost/bms/scratch/report_daily_2026_05_20.php
 */
ob_start();
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../TCPDF/tcpdf.php';
ob_end_clean();

define('RPT_DATE',   '20 May 2026');
define('RPT_REF',    'RPT-BMS-2026-0520');
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
$pdf->SetTitle('BMS Daily Development Report — 2026-05-20');
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
    ['12', 'Changelog Updates',            59,  130, 246],
    ['6',  'Work Streams',                 99,  102, 241],
    ['37', 'Files Created / Modified',     34,  197,  94],
    ['3',  'Bug Fixes Deployed',          239,   68,  68],
    ['7',  'DB Migrations',                20,  184, 166],
    ['8',  'Test Suites Added',             6,  182, 212],
    ['14', 'New API Files',               139,   92, 246],
    ['1',  'Boss Request Delivered',      249,  115,  22],
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
    ['Report Date',      RPT_DATE],
    ['Version',          RPT_VER],
    ['Prepared By',      RPT_AUTHOR],
    ['Prepared For',     RPT_FOR],
    ['Project',          'BMS — Business Management System'],
    ['Branch',           'feature/received-invoices-status-workflow'],
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
    'Customer LPO built end-to-end in one day: recording, workflow, line items, multi-file attachments',
    'Boss request delivered: cumulative PO cap rejects over-billing with return-to-supplier message',
    'New PO vs Invoice Report: every PO with Invoiced, Remaining, % Billed progress bar and status',
    'RFQ gains multi-file attachments; update endpoint fully rewritten as proper UPDATE',
    'Project View: supplier-scoped Received Invoices + Supplier Payments workflow (pending/reviewed/approved)',
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

// Disable auto page break to keep footer text on cover page
$pdf->SetAutoPageBreak(false);
$pdf->SetFont('helvetica', '', 7); $pdf->SetTextColor(150, 160, 175);
$pdf->SetXY(12, 272);
$pdf->Cell(0, 5, 'INTERNAL USE ONLY  —  BJP Technologies Co. Ltd  —  Confidential Development Documentation', 0, 0, 'C');
$pdf->SetAutoPageBreak(true, 18);
$pdf->SetDrawColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0);


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 2 — EXECUTIVE SUMMARY + WORK STREAMS + ALL 12 UPDATES
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHead($pdf, 1, 'Executive Summary');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'This report covers updates 28–39 (12 changelog entries, 13 non-merge commits) delivered on 20 May ' .
    '2026. The primary achievement was a full Customer LPO module built in five phases in one day: ' .
    'database, CRUD API, status workflow (pending→reviewed→approved), line items with live totals, and ' .
    'multi-file attachments. A management request was fulfilled: invoices can no longer exceed their ' .
    'linked Purchase Order total — cumulative tracking rejects any invoice that pushes the running ' .
    'total past the PO value. A new PO vs Invoice Report page shows all POs with invoiced amount, ' .
    'remaining capacity and progress. RFQ gained multi-file attachments with a rewritten update ' .
    'endpoint. Customer Details was reorganised into section tabs. Project View gained a ' .
    'supplier-scoped Received Invoices tab and a full Supplier Payments workflow.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, 'Work Streams Overview');
tHead($pdf, ['#', 'Work Stream', 'Updates', 'Status'], [8, 114, 16, 48]);
tRow($pdf, ['1', 'Customer LPO — recording, workflow, UI polish, items, attachments', '5',  'Complete'], [8, 114, 16, 48], false);
tRow($pdf, ['2', 'Customer Details — Bootstrap pill tabs (4 sections)',               '1',  'Complete'], [8, 114, 16, 48], true);
tRow($pdf, ['3', 'RFQ — multi-file attachment support (create, edit, view)',          '1',  'Complete'], [8, 114, 16, 48], false);
tRow($pdf, ['4', 'Received Invoices — PO cumulative cap + PO vs Invoice Report',      '1',  'Complete'], [8, 114, 16, 48], true);
tRow($pdf, ['5', 'Project View + Supplier — RI tab + Payments workflow',              '4',  'Complete'], [8, 114, 16, 48], false);
tRow($pdf, ['6', 'Procurement — DN warehouse filter + CI test gate',                  '1',  'Complete'], [8, 114, 16, 48], true);
$pdf->Ln(3);

subHead($pdf, 'All 12 Changelog Updates at a Glance');
tHead($pdf, ['#', 'Update Title', 'Type', 'Stream'], [10, 112, 20, 44]);
tRow($pdf, ['28', 'Customer LPO — full initial implementation (DB, API, UI)',    'Feature', 'Stream 1'], [10, 112, 20, 44], false);
tRow($pdf, ['29', 'Customer LPO — View Details, status workflow, auto number',  'Feature', 'Stream 1'], [10, 112, 20, 44], true);
tRow($pdf, ['30', 'Customer LPO — UI polish: badge colors, modal fixes',        'Improve', 'Stream 1'], [10, 112, 20, 44], false);
tRow($pdf, ['31', 'Customer Details — Bootstrap pill tabs for 4 sections',      'Feature', 'Stream 2'], [10, 112, 20, 44], true);
tRow($pdf, ['32', 'Customer LPO — line items + multi-file attachments',         'Feature', 'Stream 1'], [10, 112, 20, 44], false);
tRow($pdf, ['33', 'RFQ — multi-file attachment support (create, edit, view)',   'Feature', 'Stream 3'], [10, 112, 20, 44], true);
tRow($pdf, ['34', 'Customer LPO — CSRF token fix on delete / status change',   'Fix',     'Stream 1'], [10, 112, 20, 44], false);
tRow($pdf, ['35', 'Received Invoices — PO cumulative cap + PO vs Invoice report','Feature','Stream 4'], [10, 112, 20, 44], true);
tRow($pdf, ['36', 'Supplier Details — RI table blank (safeOutput undefined fix)', 'Fix',  'Stream 5'], [10, 112, 20, 44], false);
tRow($pdf, ['37', 'Project View — Received Invoices tab, supplier-scoped',      'Feature', 'Stream 5'], [10, 112, 20, 44], true);
tRow($pdf, ['38', 'Project View — Supplier Payments tab + Record Payment modal', 'Feature','Stream 5'], [10, 112, 20, 44], false);
tRow($pdf, ['39', 'Project View — Supplier Payments gear dropdown + workflow',  'Feature', 'Stream 5'], [10, 112, 20, 44], true);


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 3 — WORK STREAM 1: CUSTOMER LPO
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHead($pdf, 2, 'Work Stream 1 — Customer LPO: 5-Phase Feature Build (Updates 28–30, 32, 34)');

subHead($pdf, '2.1  Phase 1 — Update 28: Initial Customer LPO Feature');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'A complete Purchase Order (LPO) module was added to Customer Details from scratch. A ' .
    'customer_lpos table stores all LPO data. Five API endpoints handle CRUD + list, gated by the ' .
    'customers permission set. Document upload (PDF/DOC/Image, 10 MB, magic-byte validated) saves ' .
    'to uploads/finance/customer_lpos/. UI adds stat cards, desktop DataTable, mobile card view, ' .
    'Add and Edit modals, and SweetAlert2 delete confirm.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '2.2  Phase 2 — Update 29: Status Workflow + View Details Modal');
tHead($pdf, ['Component', 'Detail'], [52, 134]);
tRow($pdf, ['Migration',          'ALTER status ENUM: pending/reviewed/approved; DEFAULT pending'],             [52, 134], false);
tRow($pdf, ['change_lpo_status',  'New API — pending to reviewed to approved; CSRF + permission check'],       [52, 134], true);
tRow($pdf, ['add_lpo.php',        'Auto-generates LPO number (LPO-YYYY-NNNNN); status always pending'],        [52, 134], false);
tRow($pdf, ['View Details modal', 'Full details; Print; Edit; Review/Approve buttons shown per status'],        [52, 134], true);
$pdf->Ln(2);

subHead($pdf, '2.3  Phase 3 — Update 30: UI Polish');
tHead($pdf, ['Element', 'Before', 'After'], [48, 60, 78]);
tRow($pdf, ['Status badge',     'bg-warning text-dark',     'bg-primary (blue)'],                          [48, 60, 78], false);
tRow($pdf, ['Document column',  'Always visible in table',  'Removed — accessible via View Details only'], [48, 60, 78], true);
tRow($pdf, ['Mobile card',      'No fixed footer',          'View Details eye-icon always shown first'],   [48, 60, 78], false);
tRow($pdf, ['Buttons',          'btn-warning / btn-info',   'btn-primary throughout'],                     [48, 60, 78], true);
$pdf->Ln(2);

subHead($pdf, '2.4  Phase 4 — Update 32: Line Items + Multi-File Attachments');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'Two new tables: customer_lpo_items (sort_order, product_name, qty, unit_price, tax_rate, total) ' .
    'and customer_lpo_attachments. Modals upgraded to modal-xl. Items table has add/remove rows ' .
    'with a live grand total that auto-syncs to the Amount field. Row-based attachment section with ' .
    'Add Attachment button. View Details shows both tables. Colors locked to white/blue only. ' .
    'JS helpers: lpoAddRow, lpoCalcRow, lpoRemoveRow, lpoUpdateGrandTotal, lpoEsc() XSS guard.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '2.5  Phase 5 — Update 34: CSRF Fix');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'deleteLpo() and changeLpoStatus() $.post calls were missing _csrf. Both APIs call csrf_check() ' .
    'which returned HTTP 419, causing jQuery .fail() to show "Server error." Fix: added const ' .
    'CSRF_TOKEN and passed _csrf: CSRF_TOKEN in both calls.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '2.6  Files — Stream 1');
tHead($pdf, ['File', 'Action'], [130, 56]);
tRow($pdf, ['migrations/2026_05_20_create_customer_lpos.php',    'Created'], [130, 56], false);
tRow($pdf, ['migrations/2026_05_20_lpo_status_workflow.php',     'Created'], [130, 56], true);
tRow($pdf, ['migrations/2026_05_20_create_lpo_items.php',        'Created'], [130, 56], false);
tRow($pdf, ['migrations/2026_05_20_create_lpo_attachments.php',  'Created'], [130, 56], true);
tRow($pdf, ['api/customer/add_lpo.php',                          'Created'], [130, 56], false);
tRow($pdf, ['api/customer/update_lpo.php',                       'Created'], [130, 56], true);
tRow($pdf, ['api/customer/get_lpo.php',                          'Created'], [130, 56], false);
tRow($pdf, ['api/customer/get_lpos_list.php',                    'Created'], [130, 56], true);
tRow($pdf, ['api/customer/delete_lpo.php',                       'Created'], [130, 56], false);
tRow($pdf, ['api/customer/change_lpo_status.php',                'Created'], [130, 56], true);
tRow($pdf, ['api/customer/delete_lpo_attachment.php',            'Created'], [130, 56], false);
tRow($pdf, ['app/bms/customer/customer_details.php',             'Modified'], [130, 56], true);


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 4 — WORK STREAMS 2, 3, 4
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHead($pdf, 3, 'Work Stream 2 — Customer Details: Section Tabs (Update 31)');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'The four major sections of customer_details.php — Sales Order History, Invoice & Payment ' .
    'History, Purchase Orders (LPO), and System Information — were wrapped in Bootstrap pill tabs ' .
    'in a single scrollable row. The active tab is highlighted blue. The LPO tab is PHP-conditional: ' .
    'hidden when the customer has no LPOs and the user lacks create permission. ' .
    'DataTable columns.adjust() is called on each tab show to fix hidden-pane rendering artefacts.',
    0, 'J');
$pdf->Ln(3);

sectionHead($pdf, 4, 'Work Stream 3 — RFQ Multi-File Attachments (Update 33)');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'Two migrations replace the single rfq.attachment column with an rfq_attachments table. ' .
    'api/create_rfq.php was rewritten with CSRF and multi-file handling (5-check security per file). ' .
    'api/update_rfq.php was rewritten from a near-copy of create into a proper UPDATE with a ' .
    'draft-only guard. api/delete_rfq_attachment.php removes one attachment entry. rfq_create.php ' .
    'gained an Attachments card below RFQ Items; rfq_view.php shows all attachments in a list-group.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '4.1  Files — Stream 3');
tHead($pdf, ['File', 'Action'], [130, 56]);
tRow($pdf, ['migrations/2026_05_20_add_rfq_attachment.php',     'Created'],           [130, 56], false);
tRow($pdf, ['migrations/2026_05_20_rfq_multi_attachments.php',  'Created'],           [130, 56], true);
tRow($pdf, ['api/create_rfq.php',                               'Modified'],          [130, 56], false);
tRow($pdf, ['api/update_rfq.php',                               'Modified/rewritten'], [130, 56], true);
tRow($pdf, ['api/delete_rfq_attachment.php',                    'Created'],           [130, 56], false);
tRow($pdf, ['app/bms/purchase/rfq_create.php',                  'Modified'],          [130, 56], true);
tRow($pdf, ['app/bms/purchase/rfq_view.php',                    'Modified'],          [130, 56], false);
$pdf->Ln(3);

sectionHead($pdf, 5, 'Work Stream 4 — Received Invoices: PO Cap + Report (Update 35)');

subHead($pdf, '5.1  PO Cumulative Cap (Management Request)');
tHead($pdf, ['Requirement', 'What Was Delivered'], [54, 132]);
tRow($pdf, ['PO Reference position',  'Moved above Amount and Attachment fields on the form'],                     [54, 132], false);
tRow($pdf, ['Single-invoice cap',     'Server rejects any invoice amount that exceeds the PO total alone'],        [54, 132], true);
tRow($pdf, ['Cumulative cap',         'ri_check_po_cap() sums prior invoices; rejects the one that pushes over'],  [54, 132], false);
tRow($pdf, ['Live PO Summary panel',  'Shows PO Total / Invoiced / Remaining / After This Invoice live'],          [54, 132], true);
tRow($pdf, ['Rejection message',      'Tells user to return the invoice to the supplier for a corrected amount'],  [54, 132], false);
tRow($pdf, ['Project auto-fill',      'Selecting a PO auto-fills Project — no manual selection needed'],           [54, 132], true);
$pdf->Ln(2);

subHead($pdf, '5.2  PO vs Invoice Report (New Page)');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'New app/bms/invoice/po_invoice_report.php and api/po_invoice_report.php. The report lists ' .
    'every PO with Supplier, PO Date, Total, Invoiced, Remaining, % Billed (progress bar), and ' .
    'Status (Open / Partially Billed / Fully Billed / Over-billed). Filters: Supplier, Status, ' .
    'Date Range. Stat cards. CSV Export. Mobile cards. Menu link added under Sales & Purchases.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '5.3  Files — Stream 4');
tHead($pdf, ['File', 'Action'], [130, 56]);
tRow($pdf, ['helpers.php  (ri_check_po_cap helper added)',   'Modified'], [130, 56], false);
tRow($pdf, ['api/received_invoices.php',                     'Modified'], [130, 56], true);
tRow($pdf, ['app/bms/invoice/received_invoices.php',         'Modified'], [130, 56], false);
tRow($pdf, ['api/po_invoice_report.php',                     'Created'],  [130, 56], true);
tRow($pdf, ['app/bms/invoice/po_invoice_report.php',         'Created'],  [130, 56], false);
tRow($pdf, ['roots.php  (route registered)',                 'Modified'], [130, 56], true);
tRow($pdf, ['header.php  (menu link added)',                 'Modified'], [130, 56], false);


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 5 — WORK STREAMS 5 & 6
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHead($pdf, 6, 'Work Stream 5 — Project View + Supplier: 4 Updates (36–39)');

subHead($pdf, '6.1  Update 36 — Supplier Details: safeOutput Undefined Fix');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'The Received Invoices DataTable in supplier_details.php drew a blank tbody even though the ' .
    'API returned data and the badge showed the correct count. Root cause: safeOutput() was called ' .
    'in three places (invoice_ref, po_number, riActions dropdown) but was never defined in this ' .
    'file. JavaScript threw ReferenceError on every draw(), clearing the tbody. Fix: added the ' .
    'safeOutput() definition alongside the RI_* JS constants.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '6.2  Update 37 — Project View: Supplier-Scoped Received Invoices Tab');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'A Received Invoices tab pane was added to the Sales section of project_view.php. When the ' .
    'project is opened via Supplier Details (?supplier_id=X), the tab heading shows the supplier ' .
    'name and the API filters by both project_id AND supplier_id — only that supplier\'s invoices ' .
    'for this project appear. In SC/supplier mode this tab replaces the customer-facing Invoices ' .
    'tab. safeOutput() was also added to project_view.php where it was previously missing.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '6.3  Updates 38–39 — Supplier Project Payments: Record + Workflow');
tHead($pdf, ['New API File', 'Purpose'], [88, 98]);
tRow($pdf, ['api/suppliers/get_project_payments.php', 'action=list: payments list; action=get_pos: PO dropdown'],    [88, 98], false);
tRow($pdf, ['api/suppliers/add_project_payment.php',  'POST: insert payment (status=pending); updates PO amount'],   [88, 98], true);
tRow($pdf, ['api/suppliers/get_project_payment.php',  'GET single payment with PO info for edit modal'],             [88, 98], false);
tRow($pdf, ['api/suppliers/update_project_payment.php','POST: edit pending payment; reverses and reapplies amount'], [88, 98], true);
tRow($pdf, ['api/suppliers/delete_project_payment.php','POST: soft-delete; reverses PO amount; blocks approved'],    [88, 98], false);
tRow($pdf, ['api/suppliers/change_payment_status.php', 'POST: pending to reviewed (canReview); to approved'],        [88, 98], true);
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'project_view.php additions: Record Payment button, #suppAddPaymentModal with PO dropdown ' .
    '(shows outstanding balance), Actions gear column (View Details, Edit [pending only], Mark ' .
    'Reviewed, Approve, Delete). Edit modal pre-fills from get_project_payment.php. Migration ' .
    'adds reviewed/approved values to the supplier_payments.status ENUM.',
    0, 'J');
$pdf->Ln(2);

subHead($pdf, '6.4  Files — Stream 5');
tHead($pdf, ['File', 'Action'], [130, 56]);
tRow($pdf, ['migrations/2026_05_20_supplier_payment_workflow_status.php', 'Created'], [130, 56], false);
tRow($pdf, ['api/suppliers/add_project_payment.php',                      'Created'], [130, 56], true);
tRow($pdf, ['api/suppliers/update_project_payment.php',                   'Created'], [130, 56], false);
tRow($pdf, ['api/suppliers/get_project_payment.php',                      'Created'], [130, 56], true);
tRow($pdf, ['api/suppliers/get_project_payments.php',                     'Created'], [130, 56], false);
tRow($pdf, ['api/suppliers/change_payment_status.php',                    'Created'], [130, 56], true);
tRow($pdf, ['api/suppliers/delete_project_payment.php',                   'Created'], [130, 56], false);
tRow($pdf, ['app/bms/operations/project_view.php',                        'Modified'], [130, 56], true);
tRow($pdf, ['app/bms/Suppliers/supplier_details.php',                     'Modified'], [130, 56], false);
$pdf->Ln(3);

sectionHead($pdf, 7, 'Work Stream 6 — Procurement: DN Warehouse Fix + CI Test Gate');
$pdf->SetFont('helvetica', '', 8.8);
$pdf->MultiCell(0, 5.5,
    'DN create: when no project was selected the PHP filter used AND project_id = 0, blocking every ' .
    'project-assigned warehouse from the dropdown. Fixed to show all active warehouses when no ' .
    'project is provided (AND the matching JS filterWarehousesManual() updated). A CI test gate ' .
    'was added to deploy.yml: a "test" job runs before the deploy job and blocks it on failure — ' .
    'PHP syntax lint on all app/api files, critical-file existence check, and migration filename ' .
    'validation.',
    0, 'J');


// ═══════════════════════════════════════════════════════════════════════════
// PAGE 6 — DB MIGRATIONS + BUG FIXES + TEST SUITES + NEXT STEPS
// ═══════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHead($pdf, 8, 'Database Schema Changes — 7 Migrations');
tHead($pdf, ['Migration File', 'Purpose'], [100, 86]);
tRow($pdf, ['2026_05_20_create_customer_lpos.php',          'CREATE customer_lpos table (LPO header)'],             [100, 86], false);
tRow($pdf, ['2026_05_20_lpo_status_workflow.php',           'ALTER customer_lpos: add pending/reviewed/approved'],  [100, 86], true);
tRow($pdf, ['2026_05_20_create_lpo_items.php',              'CREATE customer_lpo_items (line items)'],              [100, 86], false);
tRow($pdf, ['2026_05_20_create_lpo_attachments.php',        'CREATE customer_lpo_attachments'],                     [100, 86], true);
tRow($pdf, ['2026_05_20_add_rfq_attachment.php',            'Create uploads/procurement/rfq/ + .htaccess guard'],   [100, 86], false);
tRow($pdf, ['2026_05_20_rfq_multi_attachments.php',         'CREATE rfq_attachments; DROP rfq.attachment column'],  [100, 86], true);
tRow($pdf, ['2026_05_20_supplier_payment_workflow_status.php','ALTER supplier_payments: add reviewed/approved'],     [100, 86], false);
$pdf->Ln(3);

sectionHead($pdf, 9, 'Bug Fixes (3)');
tHead($pdf, ['#', 'Bug', 'Root Cause', 'Fix'], [7, 58, 64, 57]);
tRow($pdf, ['1', 'LPO delete/status shows "Server error."', 'CSRF token missing in $.post; API returned 419', 'Added CSRF_TOKEN const; passed _csrf in both calls'], [7, 58, 64, 57], false);
tRow($pdf, ['2', 'Supplier RI table blank (badge correct)',  'safeOutput() used but never defined — ReferenceError', 'Added safeOutput() alongside RI_* constants block'], [7, 58, 64, 57], true);
tRow($pdf, ['3', 'DN Create: empty warehouse dropdown',      'AND project_id=0 excluded project-assigned warehouses', 'Show all active warehouses when no project given'], [7, 58, 64, 57], false);
$pdf->Ln(3);

sectionHead($pdf, 10, 'Test Suites Added (8 Files)');
tHead($pdf, ['Test File', 'Pass', 'Coverage'], [88, 14, 84]);
tRow($pdf, ['scratch/test_customer_lpo.php',         '—',    'DB schema, CRUD roundtrip, file MIME/size validation'],  [88, 14, 84], false);
tRow($pdf, ['scratch/test_lpo_workflow.php',          '—',    'Status transitions, permission guards, edge cases'],     [88, 14, 84], true);
tRow($pdf, ['scratch/test_lpo_ui_fixes.php',          '—',    'CSRF token, badge colors, mobile card, no Doc column'],  [88, 14, 84], false);
tRow($pdf, ['scratch/test_lpo_items_attachments.php', '69/69','Items grand total, multi-file upload, remove attach'],   [88, 14, 84], true);
tRow($pdf, ['scratch/test_customer_tabs.php',         '50/50','Tab elements, LPO conditional, DataTable adjust'],        [88, 14, 84], false);
tRow($pdf, ['scratch/test_rfq_attachment.php',        '78/78','rfq_attachments table, API endpoints, UI flow'],          [88, 14, 84], true);
tRow($pdf, ['scratch/test_po_invoice_cap.php',        '83/83','PO cap formula, boss scenario, project auto-fill'],       [88, 14, 84], false);
tRow($pdf, ['scratch/test_dn_create.php',             '—',    'Warehouse filter, stock API, form fields, live submit'],  [88, 14, 84], true);
$pdf->Ln(3);

sectionHead($pdf, 11, 'Next Steps — Continuing Development');
tHead($pdf, ['#', 'Task', 'Priority', 'Owner'], [8, 122, 27, 29]);
tRow($pdf, ['1', 'Run all 7 migrations on live server after branch merge',                   'High',   RPT_AUTHOR], [8, 122, 27, 29], false);
tRow($pdf, ['2', 'Test Customer LPO on live: workflow, items, attachments',                  'High',   RPT_AUTHOR], [8, 122, 27, 29], true);
tRow($pdf, ['3', 'Verify PO cap + PO vs Invoice Report on live with real data',              'High',   RPT_AUTHOR], [8, 122, 27, 29], false);
tRow($pdf, ['4', 'Test Supplier Payments workflow on live (record, review, approve)',         'High',   RPT_AUTHOR], [8, 122, 27, 29], true);
tRow($pdf, ['5', 'Verify supplier-scoped Received Invoices tab on live',                     'High',   RPT_AUTHOR], [8, 122, 27, 29], false);
tRow($pdf, ['6', 'Merge feature branch into develop then main for live deployment',           'High',   RPT_AUTHOR], [8, 122, 27, 29], true);
tRow($pdf, ['7', 'Apply CLAUDE.md standards to HR module (employees, leaves, loans)',        'Medium', RPT_AUTHOR], [8, 122, 27, 29], false);
tRow($pdf, ['8', 'Add rate limiting to login and write API endpoints (security gap)',         'Medium', RPT_AUTHOR], [8, 122, 27, 29], true);

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetTextColor(0, 0, 0);

// Output
$pdf->Output('BMS_Daily_Report_2026-05-20.pdf', 'D');
