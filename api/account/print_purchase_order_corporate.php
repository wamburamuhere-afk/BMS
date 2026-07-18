<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
assertScopeForRecordHtml('purchase_orders', 'purchase_order_id', $order_id);

global $pdo;
$stmt = $pdo->prepare("
    SELECT po.*, s.supplier_name, s.company_name, s.address as s_address,
           s.postal_address as s_postal_address,
           s.postal_code as s_postal_code, s.city as s_city, s.state as s_state, s.country as s_country,
           s.phone as s_phone, s.email as s_email,
           s.tax_id as s_tin, s.vat_number as s_vrn,
           u.username, u.first_name AS creator_first, u.last_name AS creator_last,
           COALESCE(u.user_role, u.role) AS creator_role,
           pr.project_name, pr.contract_number as project_contract_no, w.warehouse_name,
           po.prepared_by_name, po.prepared_by_role,
           po.reviewed_by_name, po.reviewed_by_role, po.reviewed_at,
           po.approved_by_name, po.approved_by_role, po.approved_at
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN users u ON po.created_by = u.user_id
    LEFT JOIN projects pr ON po.project_id = pr.project_id
    LEFT JOIN warehouses w ON po.warehouse_id = w.warehouse_id
    WHERE po.purchase_order_id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) die("Order not found");

$stmtItems = $pdo->prepare("
    SELECT poi.*, p.product_name, p.sku, p.unit
    FROM purchase_order_items poi
    LEFT JOIN products p ON poi.product_id = p.product_id
    WHERE poi.purchase_order_id = ?
");
$stmtItems->execute([$order_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$currency = $order['currency'] ?? 'TZS';
$order['expected_delivery_date'] = $order['expected_delivery_date'] ?? $order['expected_date'] ?? null;
$order['subtotal']        = $order['subtotal']        ?? $order['total_amount'] ?? 0;
$order['tax_amount']      = $order['tax_amount']      ?? 0;
$order['shipping_cost']   = $order['shipping_cost']   ?? 0;
$order['grand_total']     = $order['grand_total']     ?? 0;
$order['notes']           = $order['notes']           ?? '';
$order['terms_conditions']= $order['terms_conditions']?? '';

// ── Three-approval workflow data for signature row + DRAFT watermark ──
$wf_status  = $order['status'] ?? 'pending';
$po_id      = $order['purchase_order_id'] ?? 0;
$wf_sigs    = $po_id ? getWorkflowSignatures($pdo, 'purchase_order', $po_id) : [];

$po_creator_name = trim(($order['creator_first'] ?? '') . ' ' . ($order['creator_last'] ?? ''))
                   ?: ($order['username'] ?? '')
                   ?: ($order['prepared_by_name'] ?? '');
$po_creator_role = $order['creator_role'] ?: ($order['prepared_by_role'] ?? '');

$wf = [
    'created_by_name'   => $po_creator_name,
    'created_by_role'   => $po_creator_role,
    'reviewed_by_name'  => $order['reviewed_by_name'] ?? '',
    'reviewed_by_role'  => $order['reviewed_by_role'] ?? '',
    'approved_by_name'  => $order['approved_by_name'] ?? '',
    'approved_by_role'  => $order['approved_by_role'] ?? '',
    'created_sig_path'  => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at' => $wf_sigs['created']['signed_at']  ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']  ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at'] ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']  ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at'] ?? null,
    '__include_css'      => true,
];

$comp = ['name'=>'Business Management System','email'=>'','phone'=>'','address'=>'','postal_address'=>'','website'=>'','tin'=>'','vrn'=>'','logo'=>''];
try {
    $stmtC = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'company_%'");
    $stmtC->execute();
    while ($r = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        $k = str_replace('company_', '', $r['setting_key']);
        if ($k === 'physical_address') $comp['address'] = $r['setting_value'];
        elseif ($k === 'logo')         $comp['logo']    = $r['setting_value'];
        else                           $comp[$k]        = $r['setting_value'];
    }
} catch (Exception $e) {}

// Purchase Order's own Corporate-layout accent color — separate from Purchase
// Return and Debit Note even though they share the same Navy/Corporate/Banded
// designs. Configurable from System Settings > Color Setting; falls back to the
// original design color when unset.
$accent = getSetting('print_template_color_po_corporate', '#000000');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order #<?= htmlspecialchars($order['order_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            line-height: 1.5;
            padding: 20px 20px 0 20px;
            background: #fff;
        }

        /* ── COMPANY STRIP (name, logo, contacts) ── */
        .company-strip { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px solid #d8d8d8; }
        .company-strip img { max-height: 46px; width: auto; object-fit: contain; }
        .company-strip h1 { font-size: 16px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
        .company-strip p { font-size: 10px; color: #444; margin: 0; }

        /* ── BLACK TITLE BAR ── */
        .title-bar { background: var(--accent); color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .title-bar h2 { font-size: 20px; font-weight: 800; letter-spacing: 1.5px; }
        .title-bar .meta { text-align: right; font-size: 11.5px; }
        .title-bar .meta p { margin: 2px 0; }

        /* ── SECTION LABEL BARS ── */
        .section-label { background: var(--accent); color: #fff; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 6px 12px; margin-bottom: 0; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .section-body { border: 1px solid var(--accent); border-top: none; padding: 12px 14px; margin-bottom: 18px; }
        .section-body p { margin: 3px 0; font-size: 11.5px; }
        .section-body strong { font-weight: 700; }

        .two-col { display: flex; gap: 16px; }
        .two-col > div { width: 50%; }

        /* ── ITEMS TABLE ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: var(--accent); color: #fff; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr { border-bottom: 1px solid #ddd; }
        tbody tr:last-child { border-bottom: 2px solid var(--accent); }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        /* ── STATUS + DELIVERY (two columns) ── */
        .status-delivery { display: flex; gap: 16px; margin-bottom: 18px; }
        .status-delivery > div { width: 50%; }
        .status-badge { display: inline-block; padding: 5px 14px; background: var(--accent); color: #fff; font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.6px; border-radius: 3px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }

        /* ── TOTALS ── */
        .totals { float: right; width: 300px; border: 1px solid var(--accent); padding: 12px 16px; margin-bottom: 18px; }
        .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 12px; border-bottom: 1px solid #ddd; }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total { border-top: 2px solid var(--accent); border-bottom: none; margin-top: 8px; padding-top: 9px; font-size: 14px; font-weight: 700; }

        /* ── NOTES ── */
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div { border: 1px solid var(--accent); padding: 12px 14px; margin-bottom: 10px; }
        .notes-section strong { display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; }
        .notes-section p { font-size: 11px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>


    <div class="print-scale-wrapper">
    <!-- COMPANY STRIP -->
    <div class="company-strip">
        <?php if (!empty($comp['logo'])): ?>
        <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <?php
            $line = [];
            if (!empty($comp['address'])) $line[] = htmlspecialchars($comp['address']);
            if (!empty($comp['phone']))   $line[] = 'Tel: ' . htmlspecialchars($comp['phone']);
            if (!empty($comp['email']))   $line[] = htmlspecialchars($comp['email']);
            if ($line): ?><p><?= implode(' &nbsp;|&nbsp; ', $line) ?></p><?php endif; ?>
            <?php
            $tv = [];
            if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
            if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
            if (!empty($comp['website'])) $tv[] = 'Web: ' . htmlspecialchars($comp['website']);
            if ($tv): ?><p><?= implode(' &nbsp;|&nbsp; ', $tv) ?></p><?php endif; ?>
        </div>
    </div>

    <!-- TITLE BAR -->
    <div class="title-bar">
        <h2>PURCHASE ORDER</h2>
        <div class="meta">
            <p><strong>PO #:</strong> <?= htmlspecialchars($order['order_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($order['order_date'])) ?></p>
        </div>
    </div>

    <!-- VENDOR + ORDER INFORMATION -->
    <div class="two-col">
        <div>
            <div class="section-label">Vendor Information</div>
            <div class="section-body">
                <p><strong><?= htmlspecialchars($order['supplier_name']) ?></strong></p>
                <?php if (!empty($order['company_name'])): ?><p><?= htmlspecialchars($order['company_name']) ?></p><?php endif; ?>
                <?php if (!empty($order['s_postal_address'])): ?><p><?= htmlspecialchars($order['s_postal_address']) ?></p><?php endif; ?>
                <?php if (!empty($order['s_address'])): ?><p><?= htmlspecialchars($order['s_address']) ?></p><?php endif; ?>
                <?php if (!empty($order['s_phone'])): ?><p><?= htmlspecialchars($order['s_phone']) ?></p><?php endif; ?>
                <?php if (!empty($order['s_email'])): ?><p><?= htmlspecialchars($order['s_email']) ?></p><?php endif; ?>
                <?php
                $s_tv = [];
                if (!empty($order['s_tin'])) $s_tv[] = 'TIN: ' . htmlspecialchars($order['s_tin']);
                if (!empty($order['s_vrn'])) $s_tv[] = 'VRN: ' . htmlspecialchars($order['s_vrn']);
                if ($s_tv): ?><p><?= implode(' | ', $s_tv) ?></p><?php endif; ?>
            </div>
        </div>
        <div>
            <div class="section-label">Order Information</div>
            <div class="section-body">
                <p><strong>Expected Delivery:</strong> <?= !empty($order['expected_delivery_date']) ? date('d M Y', strtotime($order['expected_delivery_date'])) : 'Not specified' ?></p>
                <?php if (!empty($order['supplier_quote_ref'])): ?><p><strong>Quote Ref:</strong> <?= htmlspecialchars($order['supplier_quote_ref']) ?></p><?php endif; ?>
                <?php if (!empty($order['project_contract_no'])): ?><p><strong>Contract No:</strong> <?= htmlspecialchars($order['project_contract_no']) ?></p><?php endif; ?>
                <?php if (!empty($order['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($order['project_name']) ?></p><?php endif; ?>
                <?php if (!empty($order['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($order['warehouse_name']) ?></p><?php endif; ?>
                <p><strong>Created By:</strong> <?= htmlspecialchars($order['username'] ?? 'N/A') ?></p>
            </div>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center" style="width:100px;">Product Code</th>
                <th class="text-center">Item / Description</th>
                <th class="text-right" style="width:80px;">Qty</th>
                <th class="text-right" style="width:105px;">Unit Price</th>
                <th class="text-right" style="width:115px;">Total (<?= $currency ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item):
                $lineTotal = floatval($item['quantity']) * floatval($item['unit_price']);
                $unit = !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : '';
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= !empty($item['sku']) ? htmlspecialchars($item['sku']) : '—' ?></td>
                <td><?= htmlspecialchars($item['product_name'] ?? $item['item_name'] ?? 'Unknown Product') ?></td>
                <td class="text-right"><?= floatval($item['quantity']) ?><?= $unit ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right fw-bold"><?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ORDER STATUS + DELIVERY DETAILS (real fields only — no fabricated checklist) -->
    <div class="status-delivery">
        <div>
            <div class="section-label">Order Status</div>
            <div class="section-body">
                <span class="status-badge"><?= strtoupper($order['status']) ?></span>
                <?php if (!empty($order['reviewed_at'])): ?><p style="margin-top:8px;"><strong>Reviewed:</strong> <?= date('d M Y', strtotime($order['reviewed_at'])) ?></p><?php endif; ?>
                <?php if (!empty($order['approved_at'])): ?><p><strong>Approved:</strong> <?= date('d M Y', strtotime($order['approved_at'])) ?></p><?php endif; ?>
            </div>
        </div>
        <div>
            <div class="section-label">Delivery Details</div>
            <div class="section-body">
                <p><strong>Expected:</strong> <?= !empty($order['expected_delivery_date']) ? date('d M Y', strtotime($order['expected_delivery_date'])) : 'Not specified' ?></p>
                <?php if (!empty($order['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($order['warehouse_name']) ?></p><?php endif; ?>
                <?php if (!empty($order['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($order['project_name']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TOTALS -->
    <div class="totals">
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($order['subtotal'], 2) ?></span></div>
        <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($order['tax_amount'], 2) ?></span></div>
        <div class="totals-row"><span>Shipping:</span><span><?= $currency ?> <?= number_format($order['shipping_cost'], 2) ?></span></div>
        <div class="totals-row grand-total"><span>GRAND TOTAL:</span><span><?= $currency ?> <?= number_format($order['grand_total'], 2) ?></span></div>
    </div>

    <!-- NOTES -->
    <div class="notes-section">
        <?php if (!empty($order['notes'])): ?>
        <div><strong>Notes</strong><p><?= nl2br(htmlspecialchars($order['notes'])) ?></p></div>
        <?php endif; ?>
        <?php if (!empty($order['terms_conditions'])): ?>
        <div><strong>Terms &amp; Conditions</strong><p><?= nl2br(htmlspecialchars($order['terms_conditions'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>

    <div style="clear: both;">
        <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>
    </div>


    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
