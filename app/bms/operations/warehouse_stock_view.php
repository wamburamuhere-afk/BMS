<?php
// File: app/bms/operations/warehouse_stock_view.php
require_once __DIR__ . '/../../../roots.php';

// This page prints its own project-branded header (#whPrintHeader: company, section,
// contract no, project, warehouse). Suppress the generic global company-only print
// header so the two don't compete/overlap on print (mirrors project_view.php).
define('BMS_SUPPRESS_PRINT_HEADER', true);

autoEnforcePermission('warehouses');
includeHeader();

$warehouse_id = intval($_GET['warehouse_id'] ?? 0);
$project_id   = intval($_GET['project_id']   ?? 0);

if ($warehouse_id <= 0 || $project_id <= 0) {
    echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Invalid warehouse or project ID.</div></div>';
    includeFooter(); exit;
}

assertScopeForRecordHtml('warehouses', 'warehouse_id', $warehouse_id);

// Validate warehouse belongs to project
$wh_stmt = $pdo->prepare("
    SELECT warehouse_id, warehouse_name, warehouse_code, location, status
    FROM warehouses
    WHERE warehouse_id = ? AND project_id = ? AND status != 'deleted'
");
$wh_stmt->execute([$warehouse_id, $project_id]);
$warehouse = $wh_stmt->fetch(PDO::FETCH_ASSOC);
if (!$warehouse) {
    echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Warehouse not found in this project.</div></div>';
    includeFooter(); exit;
}

$proj_stmt = $pdo->prepare("SELECT project_name, contract_number FROM projects WHERE project_id = ?");
$proj_stmt->execute([$project_id]);
$proj_row     = $proj_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$project_name = $proj_row['project_name'] ?: 'Project';
$contract_no  = $proj_row['contract_number'] ?? '';

$company_name = getSetting('company_name', 'BMS');
$company_logo = getSetting('company_logo', '');

// 1. Stock Summary — current balances from product_stocks
$stmt = $pdo->prepare("
    SELECT ps.product_id, p.product_name, p.sku, p.unit, c.category_name,
           ps.stock_quantity, ps.reserved_quantity, ps.available_quantity
    FROM product_stocks ps
    JOIN products p        ON ps.product_id  = p.product_id
    LEFT JOIN categories c ON p.category_id  = c.category_id
    WHERE ps.warehouse_id = ?
    ORDER BY p.product_name ASC
");
$stmt->execute([$warehouse_id]);
$stock_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Materials Received — from GRN receipt_items
$stmt = $pdo->prepare("
    SELECT ri.receipt_item_id, p.product_name, p.sku, ri.quantity_received, ri.unit,
           pr.receipt_number, pr.receipt_date, pr.status, s.supplier_name
    FROM receipt_items ri
    JOIN purchase_receipts pr ON ri.receipt_id   = pr.receipt_id
    JOIN products p           ON ri.product_id   = p.product_id
    LEFT JOIN suppliers s     ON pr.supplier_id  = s.supplier_id
    WHERE pr.warehouse_id = ?
      AND (pr.project_id = ? OR EXISTS (
          SELECT 1 FROM purchase_orders po
          WHERE po.purchase_order_id = pr.purchase_order_id AND po.project_id = ?
      ))
    ORDER BY pr.receipt_date DESC, ri.receipt_item_id ASC
");
$stmt->execute([$warehouse_id, $project_id, $project_id]);
$received = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Materials Issued — from delivery_items (DN/DO)
$stmt = $pdo->prepare("
    SELECT di.delivery_item_id, di.product_name, di.sku, di.quantity_delivered, di.unit,
           d.delivery_number, d.delivery_date, d.status as dn_status, s.supplier_name
    FROM delivery_items di
    JOIN deliveries d     ON di.delivery_id  = d.delivery_id
    LEFT JOIN suppliers s ON d.supplier_id   = s.supplier_id
    WHERE d.warehouse_id = ? AND d.project_id = ?
    ORDER BY d.delivery_date DESC, di.delivery_item_id ASC
");
$stmt->execute([$warehouse_id, $project_id]);
$issued = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Adjustments — from stock_movements (adjustment types only)
$stmt = $pdo->prepare("
    SELECT sm.movement_id, sm.movement_type, sm.quantity, sm.unit,
           sm.movement_date, sm.created_at, sm.notes, sm.reference_number,
           p.product_name, p.sku, u.username as adjusted_by
    FROM stock_movements sm
    JOIN products p      ON sm.product_id = p.product_id
    LEFT JOIN users u    ON sm.created_by = u.user_id
    WHERE sm.warehouse_id = ? AND sm.project_id = ?
      AND sm.movement_type IN (
          'adjustment_in','adjustment_out','correction',
          'damaged','expired','found','theft','adjustment','stock_adjustment'
      )
    ORDER BY sm.created_at DESC
");
$stmt->execute([$warehouse_id, $project_id]);
$adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Movement History — all movements
$stmt = $pdo->prepare("
    SELECT sm.movement_id, sm.movement_type, sm.quantity, sm.unit,
           sm.movement_date, sm.created_at, sm.reference_number, sm.notes,
           p.product_name, p.sku
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.product_id
    WHERE sm.warehouse_id = ? AND sm.project_id = ?
    ORDER BY sm.created_at DESC
");
$stmt->execute([$warehouse_id, $project_id]);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

logActivity($pdo, $_SESSION['user_id'], 'View Warehouse Stock & History',
    "Viewed stock & history for warehouse #{$warehouse_id} ({$warehouse['warehouse_name']})");

// Movement type badge helper
function moveBadge($type) {
    static $map = [
        'purchase_in'    => ['success',   'Purchase In'],
        'sale_out'       => ['primary',   'Sale Out'],
        'adjustment_in'  => ['success',   'Adj In'],
        'adjustment_out' => ['warning',   'Adj Out'],
        'stock_transfer' => ['secondary', 'Transfer'],
        'transfer_in'    => ['info',      'Transfer In'],
        'transfer_out'   => ['secondary', 'Transfer Out'],
        'return_in'      => ['info',      'Return In'],
        'return_out'     => ['danger',    'Return Out'],
        'correction'     => ['secondary', 'Correction'],
        'damaged'        => ['danger',    'Damaged'],
        'expired'        => ['danger',    'Expired'],
        'found'          => ['success',   'Found'],
        'theft'          => ['danger',    'Theft'],
        'issue_out'      => ['danger',    'Issue Out'],
        'production_out' => ['warning',   'Production Out'],
    ];
    $cfg = $map[$type] ?? ['dark', ucwords(str_replace('_', ' ', $type))];
    return '<span class="badge bg-' . $cfg[0] . '">' . htmlspecialchars($cfg[1]) . '</span>';
}

$out_types = ['sale_out','adjustment_out','transfer_out','return_out',
              'damaged','expired','theft','production_out','issue_out'];
?>

<style>
@page { margin: 10mm 8mm 16mm 8mm; }

.wh-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    background: #fff;
    color: #495057;
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
}
.wh-nav-btn:hover { border-color: #0d6efd; color: #0d6efd; background: #f0f5ff; }
.wh-nav-btn.active { border-color: #0d6efd; color: #0d6efd; background: #e8f0fe; }
.wh-nav-btn .badge {
    background: #dee2e6;
    color: #495057;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 0.75rem;
}
.wh-nav-btn.active .badge { background: #0d6efd; color: #fff; }

.section-panel { display: none; }
.section-panel.active { display: block; }

@media print {
    .wh-nav-bar, .wh-toolbar, .no-print { display: none !important; }
    .section-panel { display: none !important; }
    body.wh-printing .section-panel.active { display: block !important; }

    /* Static (non-fixed) header: prints ONCE at the top of the first page,
       and the content flows right after it (no repeating header, no blank gap). */
    /* Print header — same fonts/layout as the general project print header
       (project_view.php's .print-header-overview): 80px logo, company at
       1.5rem, report title at 1.1rem, divider bar, then project + contract. */
    #whPrintHeader {
        display: none;
        position: static;
        background: #fff;
        text-align: center;
        padding: 0 0 6px;
        margin-bottom: 10px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body.wh-printing #whPrintHeader { display: block !important; }
    #whPrintHeader img { max-height: 80px; width: auto; display: block; margin: 0 auto 8px; }
    #whPrintHeader h1 { font-size: 1.5rem; font-weight: 700; color: #0d6efd; margin: 0 0 4px; text-transform: uppercase; line-height: 1.1; }
    #whPrintHeader h2 { font-size: 1.1rem; font-weight: 700; color: #212529; margin: 0 0 8px; text-transform: uppercase; }
    #whPrintHeader .wh-divider { width: 60px; height: 2px; background: #0d6efd; border-radius: 2px; margin: 0 auto 8px; }
    #whPrintHeader .wh-project { font-size: 1rem; font-weight: 700; color: #212529; margin: 0; }
    #whPrintHeader .wh-contract { font-size: 0.85rem; font-weight: 700; color: #6c757d; margin: 2px 0 0; }
    #whPrintHeader small { font-size: 0.8rem; color: #6c757d; display: block; margin-top: 2px; }
    body.wh-printing .container-fluid { margin-top: 0 !important; padding-top: 0 !important; }

    .section-heading {
        background: #0d6efd !important;
        color: #fff !important;
        padding: 6px 10px !important;
        font-weight: 700 !important;
        font-size: 10pt !important;
        text-transform: uppercase !important;
        margin-bottom: 8px !important;
        border-radius: 3px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body.wh-printing .section-panel table { width: 100% !important; border-collapse: collapse !important; font-size: 9pt !important; }
    body.wh-printing .section-panel th,
    body.wh-printing .section-panel td { border: 1px solid #dee2e6 !important; padding: 5px 7px !important; vertical-align: middle !important; }
    body.wh-printing .section-panel thead th {
        background: #f8f9fa !important;
        font-size: 8pt !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body.wh-printing .section-panel tr { page-break-inside: avoid; break-inside: avoid; }
    body.wh-printing .table-responsive { overflow: visible !important; }
    body.wh-printing .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
    body.wh-printing .card-header { display: none !important; }
}
</style>

<!-- Print header/footer (hidden on screen, printed via JS) -->
<div id="whPrintHeader" style="display:none;">
    <?php if (!empty($company_logo)): ?>
        <img src="<?= getUrl($company_logo) ?>" alt="Logo">
    <?php endif; ?>
    <h1><?= htmlspecialchars($company_name) ?></h1>
    <h2 id="printSectionHeading">Stock Summary</h2>
    <div class="wh-divider"></div>
    <p class="wh-project"><?= htmlspecialchars($project_name) ?></p>
    <?php if (!empty($contract_no)): ?>
    <p class="wh-contract">Contract No: <?= htmlspecialchars($contract_no) ?></p>
    <?php endif; ?>
    <small><?= htmlspecialchars($warehouse['warehouse_name']) ?></small>
</div>
<!-- Print footer: shared .bms-print-footer from footer.php (no per-page footer). -->

<div class="container-fluid py-4">

    <!-- Breadcrumb + toolbar -->
    <div class="row mb-3 align-items-center no-print wh-toolbar">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size:0.75rem;">
                    <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= getUrl('projects') ?>">Projects</a></li>
                    <li class="breadcrumb-item">
                        <a href="<?= getUrl('project_view') ?>?id=<?= $project_id ?>&tab=inventory">
                            <?= htmlspecialchars($project_name) ?>
                        </a>
                    </li>
                    <li class="breadcrumb-item active">Warehouse Stock &amp; History</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0 mt-2">
                <i class="bi bi-building text-primary me-2"></i><?= htmlspecialchars($warehouse['warehouse_name']) ?>
            </h4>
            <small class="text-muted"><?= htmlspecialchars($project_name) ?> &mdash; Warehouse Stock &amp; History</small>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="<?= getUrl('project_view') ?>?id=<?= $project_id ?>&tab=inventory" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Project
            </a>
            <button onclick="doPrint()" class="btn btn-primary btn-sm">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Section navigation buttons -->
    <div class="d-flex flex-wrap gap-2 mb-4 no-print wh-nav-bar">
        <button class="wh-nav-btn active" data-panel="sec-stock-summary">
            <i class="bi bi-boxes"></i> Stock Summary
            <span class="badge"><?= count($stock_summary) ?></span>
        </button>
        <button class="wh-nav-btn" data-panel="sec-received">
            <i class="bi bi-truck"></i> Materials Received
            <span class="badge"><?= count($received) ?></span>
        </button>
        <button class="wh-nav-btn" data-panel="sec-issued">
            <i class="bi bi-truck-flatbed"></i> Materials Issued
            <span class="badge"><?= count($issued) ?></span>
        </button>
        <button class="wh-nav-btn" data-panel="sec-adjustments">
            <i class="bi bi-arrow-left-right"></i> Adjustments
            <span class="badge"><?= count($adjustments) ?></span>
        </button>
        <button class="wh-nav-btn" data-panel="sec-movements">
            <i class="bi bi-clock-history"></i> Movement History
            <span class="badge"><?= count($movements) ?></span>
        </button>
    </div>

    <!-- ─── SECTION 1: Stock Summary ───────────────────────────────── -->
    <div class="section-panel active" id="sec-stock-summary">
        <div class="section-heading d-none d-print-block">1. Stock Summary</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 no-print">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-boxes text-primary me-2"></i>Stock Summary
                    <span class="badge bg-success ms-2"><?= count($stock_summary) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($stock_summary)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-box-seam d-block mb-2 fs-2 opacity-25"></i>
                        <p>No stock found in this warehouse.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" id="tblStockSummary">
                            <thead class="table-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3" style="width:50px;">#</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Category</th>
                                    <th class="text-center">Stock Qty</th>
                                    <th class="text-center">Reserved</th>
                                    <th class="text-center">Available</th>
                                    <th>Unit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stock_summary as $idx => $item): ?>
                                    <?php $avClass = $item['available_quantity'] > 0 ? 'text-success' : 'text-danger'; ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td><div class="fw-bold small"><?= safe_output($item['product_name']) ?></div></td>
                                        <td><code class="small"><?= safe_output($item['sku'], '—') ?></code></td>
                                        <td><small class="text-muted"><?= safe_output($item['category_name'], '—') ?></small></td>
                                        <td class="text-center fw-bold"><?= number_format((float)$item['stock_quantity'], 3) ?></td>
                                        <td class="text-center text-warning"><?= number_format((float)($item['reserved_quantity'] ?? 0), 3) ?></td>
                                        <td class="text-center fw-bold <?= $avClass ?>"><?= number_format((float)$item['available_quantity'], 3) ?></td>
                                        <td><span class="badge bg-light text-dark border small"><?= safe_output($item['unit'], 'pcs') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ─── SECTION 2: Materials Received ──────────────────────────── -->
    <div class="section-panel" id="sec-received">
        <div class="section-heading d-none d-print-block">2. Materials Received</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 no-print">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-truck text-success me-2"></i>Materials Received
                    <span class="badge bg-secondary ms-2"><?= count($received) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($received)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-truck d-block mb-2 fs-2 opacity-25"></i>
                        <p>No materials received in this warehouse.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" id="tblReceived">
                            <thead class="table-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3" style="width:50px;">#</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>GRN #</th>
                                    <th>Date</th>
                                    <th class="text-center">Qty Received</th>
                                    <th>Unit</th>
                                    <th>Supplier</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($received as $idx => $item): ?>
                                    <?php
                                    $st  = $item['status'] ?? '';
                                    $stc = $st === 'completed' ? 'success' : 'secondary';
                                    ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td><div class="fw-bold small"><?= safe_output($item['product_name']) ?></div></td>
                                        <td><code class="small"><?= safe_output($item['sku'], '—') ?></code></td>
                                        <td><span class="badge bg-light text-dark border small"><?= safe_output($item['receipt_number']) ?></span></td>
                                        <td><small><?= $item['receipt_date'] ? date('d M Y', strtotime($item['receipt_date'])) : '—' ?></small></td>
                                        <td class="text-center fw-bold text-success">+<?= number_format((float)$item['quantity_received'], 3) ?></td>
                                        <td><small><?= safe_output($item['unit'], '—') ?></small></td>
                                        <td><small><?= safe_output($item['supplier_name'], 'N/A') ?></small></td>
                                        <td><span class="badge bg-<?= $stc ?> small"><?= safe_output($st) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ─── SECTION 3: Materials Issued ────────────────────────────── -->
    <div class="section-panel" id="sec-issued">
        <div class="section-heading d-none d-print-block">3. Materials Issued</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 no-print">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-truck-flatbed text-danger me-2"></i>Materials Issued
                    <span class="badge bg-secondary ms-2"><?= count($issued) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($issued)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-truck-flatbed d-block mb-2 fs-2 opacity-25"></i>
                        <p>No materials issued from this warehouse.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" id="tblIssued">
                            <thead class="table-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3" style="width:50px;">#</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>DN #</th>
                                    <th>Date</th>
                                    <th class="text-center">Qty Issued</th>
                                    <th>Unit</th>
                                    <th>Supplier</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($issued as $idx => $item): ?>
                                    <?php
                                    $dn_st = $item['dn_status'] ?? '';
                                    $stc   = $dn_st === 'delivered' ? 'success' : ($dn_st === 'approved' ? 'primary' : 'secondary');
                                    ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td><div class="fw-bold small"><?= safe_output($item['product_name']) ?></div></td>
                                        <td><code class="small"><?= safe_output($item['sku'], '—') ?></code></td>
                                        <td><span class="badge bg-light text-primary border small"><?= safe_output($item['delivery_number']) ?></span></td>
                                        <td><small><?= $item['delivery_date'] ? date('d M Y', strtotime($item['delivery_date'])) : '—' ?></small></td>
                                        <td class="text-center fw-bold text-danger">-<?= number_format((float)$item['quantity_delivered'], 3) ?></td>
                                        <td><small><?= safe_output($item['unit'], '—') ?></small></td>
                                        <td><small><?= safe_output($item['supplier_name'], 'N/A') ?></small></td>
                                        <td><span class="badge bg-<?= $stc ?> small"><?= safe_output($dn_st) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ─── SECTION 4: Adjustments ─────────────────────────────────── -->
    <div class="section-panel" id="sec-adjustments">
        <div class="section-heading d-none d-print-block">4. Adjustments</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 no-print">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-arrow-left-right text-warning me-2"></i>Adjustments
                    <span class="badge bg-secondary ms-2"><?= count($adjustments) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($adjustments)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-arrow-left-right d-block mb-2 fs-2 opacity-25"></i>
                        <p>No adjustments in this warehouse.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" id="tblAdjustments">
                            <thead class="table-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3" style="width:50px;">#</th>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Type</th>
                                    <th class="text-center">Quantity</th>
                                    <th>Unit</th>
                                    <th>Adjusted By</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adjustments as $idx => $item): ?>
                                    <?php
                                    $isIn   = in_array($item['movement_type'], ['adjustment_in','found','correction']);
                                    $qSign  = $isIn ? '+' : '-';
                                    $qClass = $isIn ? 'text-success' : 'text-danger';
                                    $dt     = $item['movement_date'] ?: $item['created_at'];
                                    ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td><small><?= $dt ? date('d M Y', strtotime($dt)) : '—' ?></small></td>
                                        <td><div class="fw-bold small"><?= safe_output($item['product_name']) ?></div></td>
                                        <td><code class="small"><?= safe_output($item['sku'], '—') ?></code></td>
                                        <td><?= moveBadge($item['movement_type']) ?></td>
                                        <td class="text-center fw-bold <?= $qClass ?>"><?= $qSign ?><?= number_format((float)$item['quantity'], 3) ?></td>
                                        <td><small><?= safe_output($item['unit'], '—') ?></small></td>
                                        <td><small class="text-muted"><?= safe_output($item['adjusted_by'], 'System') ?></small></td>
                                        <td><small class="text-muted"><?= safe_output($item['notes'], '—') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ─── SECTION 5: Movement History ────────────────────────────── -->
    <div class="section-panel" id="sec-movements">
        <div class="section-heading d-none d-print-block">5. Movement History</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 no-print">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-clock-history text-info me-2"></i>Movement History
                    <span class="badge bg-secondary ms-2"><?= count($movements) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($movements)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-clock-history d-block mb-2 fs-2 opacity-25"></i>
                        <p>No movement history in this warehouse.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" id="tblMovements">
                            <thead class="table-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3" style="width:50px;">#</th>
                                    <th>Date / Time</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Type</th>
                                    <th class="text-center">Quantity</th>
                                    <th>Unit</th>
                                    <th>Ref #</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movements as $idx => $item): ?>
                                    <?php
                                    $isOut  = in_array($item['movement_type'], $out_types);
                                    $qSign  = $isOut ? '-' : '+';
                                    $qClass = $isOut ? 'text-danger' : 'text-success';
                                    ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td><small><?= $item['created_at'] ? date('d M Y H:i', strtotime($item['created_at'])) : '—' ?></small></td>
                                        <td><div class="small fw-bold"><?= safe_output($item['product_name']) ?></div></td>
                                        <td><code class="small"><?= safe_output($item['sku'], '—') ?></code></td>
                                        <td><?= moveBadge($item['movement_type']) ?></td>
                                        <td class="text-center fw-bold <?= $qClass ?>"><?= $qSign ?><?= number_format((float)$item['quantity'], 3) ?></td>
                                        <td><small><?= safe_output($item['unit'], '—') ?></small></td>
                                        <td><small class="text-primary"><?= safe_output($item['reference_number'], 'N/A') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /.container-fluid -->

<script>
var sectionNames = {
    'sec-stock-summary': 'Stock Summary',
    'sec-received':      'Materials Received',
    'sec-issued':        'Materials Issued',
    'sec-adjustments':   'Adjustments',
    'sec-movements':     'Movement History'
};

var tableMap = {
    'sec-stock-summary': '#tblStockSummary',
    'sec-received':      '#tblReceived',
    'sec-issued':        '#tblIssued',
    'sec-adjustments':   '#tblAdjustments',
    'sec-movements':     '#tblMovements'
};

var lenValues = [10, 25, 50, 100, -1];
var lenLabels = ['10', '25', '50', '100', 'All'];

function attachLengthButtons(tableId) {
    var $hdr = $(tableId).closest('.section-panel').find('.card-header');
    var opts = '';
    lenValues.forEach(function (n, i) {
        opts += '<option value="' + n + '"' + (n === 25 ? ' selected' : '') + '>' + lenLabels[i] + '</option>';
    });
    $hdr.prepend(
        '<div class="d-flex align-items-center gap-2 no-print me-auto">'
      + '<small class="text-muted">Show:</small>'
      + '<select class="form-select form-select-sm dt-len-select" style="width:75px;" data-table="' + tableId + '">' + opts + '</select>'
      + '</div>'
    );
}

$(document).ready(function () {
    var dtOpts = {
        responsive: true,
        autoWidth: false,
        pageLength: 25,
        dom: '<"top no-print"f>rt<"bottom no-print"ip><"clear">'
    };
    ['#tblStockSummary','#tblReceived','#tblIssued','#tblAdjustments','#tblMovements'].forEach(function (id) {
        if ($(id).length) {
            $(id).DataTable(dtOpts);
            attachLengthButtons(id);
        }
    });

    $(document).on('change', '.dt-len-select', function () {
        var len = parseInt($(this).val());
        $($(this).data('table')).DataTable().page.len(len).draw();
    });

    $('.wh-nav-btn').on('click', function () {
        var panelId = $(this).data('panel');
        $('.wh-nav-btn').removeClass('active');
        $(this).addClass('active');
        $('.section-panel').removeClass('active');
        $('#' + panelId).addClass('active');
        document.getElementById('printSectionHeading').textContent = sectionNames[panelId] || panelId;
        var tblId = tableMap[panelId];
        if (tblId && $.fn.DataTable.isDataTable(tblId)) {
            $(tblId).DataTable().columns.adjust().responsive.recalc();
        }
    });
});

function doPrint() {
    var activeBtn = document.querySelector('.wh-nav-btn.active');
    var panelId   = activeBtn ? activeBtn.dataset.panel : 'sec-stock-summary';
    document.getElementById('printSectionHeading').textContent = sectionNames[panelId] || 'Stock Summary';

    var hdr = document.getElementById('whPrintHeader');
    hdr.style.display = 'block';
    // Header is a static block: place it at the very top of the printed content
    // (body's first child) so it prints ONCE on page 1 and content follows.
    // Not the first .container-fluid — that one lives inside the top navbar,
    // which is display:none on print, so the header would vanish.
    // The print FOOTER is the shared one from footer.php (.bms-print-footer);
    // responsive.css fixes it to the bottom of every printed page.
    document.body.insertBefore(hdr, document.body.firstChild);
    document.body.classList.add('wh-printing');

    window.addEventListener('afterprint', function restore() {
        document.body.classList.remove('wh-printing');
        hdr.style.display = 'none';
        window.removeEventListener('afterprint', restore);
    });

    window.print();
}
</script>

<?php
includeFooter();
ob_end_flush();
?>
