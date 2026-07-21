<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");

$do_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
assertScopeForRecordHtml('delivery_orders', 'do_id', $do_id);

global $pdo;
$stmt = $pdo->prepare("
    SELECT do.*,
           s.supplier_name, s.company_name, s.phone AS supplier_phone,
           s.contact_person AS supplier_contact,
           w.warehouse_name, w.location AS warehouse_location,
           p.project_name, p.contract_number AS contract_no,
           u.username, u.first_name AS creator_first, u.last_name AS creator_last,
           COALESCE(u.user_role, u.role) AS creator_role
    FROM delivery_orders do
    LEFT JOIN suppliers s  ON do.supplier_id  = s.supplier_id
    LEFT JOIN warehouses w ON do.warehouse_id = w.warehouse_id
    LEFT JOIN projects p   ON do.project_id   = p.project_id
    LEFT JOIN users u      ON do.created_by   = u.user_id
    WHERE do.do_id = ?
");
$stmt->execute([$do_id]);
$do = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$do) die("Delivery Order not found");

$items = [];
try {
    $itemsStmt = $pdo->prepare("SELECT product_name, available_qty, qty_to_issue, unit FROM delivery_order_items WHERE do_id = ? ORDER BY item_id");
    $itemsStmt->execute([$do_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$linkedDns = [];
try {
    $dnStmt = $pdo->prepare("SELECT delivery_number, delivery_date, status FROM deliveries WHERE do_id = ? ORDER BY delivery_id");
    $dnStmt->execute([$do_id]);
    $linkedDns = $dnStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$wf_status = $do['status'] ?? 'pending';
$do_creator_name = trim(($do['creator_first'] ?? '') . ' ' . ($do['creator_last'] ?? '')) ?: ($do['username'] ?? '');
$do_creator_role = $do['creator_role'] ?? '';
$wf_sigs = $do_id ? getWorkflowSignatures($pdo, 'delivery_order', $do_id) : [];

$wf = [
    'created_by_name'    => $do_creator_name,
    'created_by_role'    => $do_creator_role,
    'reviewed_by_name'   => '',
    'reviewed_by_role'   => '',
    'approved_by_name'   => '',
    'approved_by_role'   => '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']  ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at'] ?? null,
    'reviewed_sig_path'  => null,
    'reviewed_signed_at' => null,
    'approved_sig_path'  => null,
    'approved_signed_at' => null,
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

// Delivery Order's own Waybill-layout accent color. Configurable from
// System Settings > Color Setting; falls back to the original design color when unset.
$accent = getSetting('print_template_color_do_waybill', '#0f766e');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Order #<?= htmlspecialchars($do['do_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #1a252f;
            line-height: 1.5;
            padding: 0 20px;
            background: #fff;
        }

        /* ── HEADER BAND ── */
        .waybill-header { background: var(--accent); color: #fff; padding: 16px 22px; border-radius: 8px; margin: 20px 0 18px 0; display: flex; justify-content: space-between; align-items: center; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .waybill-header .company-side { display: flex; align-items: center; gap: 12px; }
        .waybill-header img { max-height: 42px; width: auto; object-fit: contain; background: #fff; border-radius: 4px; padding: 3px; }
        .waybill-header h1 { font-size: 20px; font-weight: 800; letter-spacing: 0.5px; }
        .waybill-header .meta { text-align: right; font-size: 11.5px; }
        .waybill-header .meta p { margin: 2px 0; }
        .waybill-header .meta strong { font-weight: 700; }

        .company-contacts { font-size: 10.5px; color: #445; margin-bottom: 16px; }
        .company-contacts p { margin: 2px 0; }

        /* ── TEAL BANDED BLOCKS ── */
        .band { background: #e3f2f0; padding: 10px 14px; margin-bottom: 2px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .band-title { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #0b5a54; margin-bottom: 3px; }
        .band p { margin: 1px 0; font-size: 11px; }
        .two-col { display: flex; gap: 2px; margin-bottom: 14px; }
        .two-col > .band { width: 50%; }

        .meta-strip { display: flex; gap: 2px; margin-bottom: 18px; }
        .meta-strip > .band { width: 25%; }
        .meta-strip .band-title { margin-bottom: 4px; }
        .meta-strip .band-value { font-size: 12.5px; font-weight: 700; color: #1a252f; }

        /* ── ITEMS TABLE ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: var(--accent); color: #fff; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr:nth-child(even) { background: #f0f9f8; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr:last-child { border-bottom: 2px solid var(--accent); }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; color: #1a252f; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        .no-items { padding: 14px; text-align: center; color: #6c757d; font-style: italic; font-size: 11.5px; }

        /* ── LINKED DNs ── */
        .dn-badge { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }

        /* ── NOTES ── */
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div { background: #e3f2f0; padding: 12px 14px; border-radius: 6px; margin-bottom: 10px; }
        .notes-section strong { display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 700; color: #0b5a54; }
        .notes-section p { color: #1a252f; font-size: 11px; }

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
    <!-- HEADER -->
    <div class="waybill-header">
        <div class="company-side">
            <?php if (!empty($comp['logo'])): ?>
            <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
        </div>
        <div class="meta">
            <p><strong>WAYBILL / DO #:</strong> <?= htmlspecialchars($do['do_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($do['do_date'])) ?></p>
            <p><strong>Status:</strong> <?= htmlspecialchars(str_replace('_', ' ', strtoupper($do['status']))) ?></p>
        </div>
    </div>

    <div class="company-contacts">
        <?php
        $cc = [];
        if (!empty($comp['address'])) $cc[] = htmlspecialchars($comp['address']);
        if (!empty($comp['phone']))   $cc[] = 'Tel: ' . htmlspecialchars($comp['phone']);
        if (!empty($comp['email']))   $cc[] = htmlspecialchars($comp['email']);
        if ($cc): ?><p><?= implode(' &nbsp;|&nbsp; ', $cc) ?></p><?php endif; ?>
    </div>

    <!-- SUPPLIER + CONSIGNMENT -->
    <div class="two-col">
        <div class="band">
            <div class="band-title">Supplier</div>
            <p><strong><?= htmlspecialchars($do['supplier_name'] ?? 'N/A') ?></strong></p>
            <?php if (!empty($do['company_name'])): ?><p><?= htmlspecialchars($do['company_name']) ?></p><?php endif; ?>
            <?php if (!empty($do['supplier_contact'])): ?><p>Contact: <?= htmlspecialchars($do['supplier_contact']) ?></p><?php endif; ?>
            <?php if (!empty($do['supplier_phone'])): ?><p><?= htmlspecialchars($do['supplier_phone']) ?></p><?php endif; ?>
        </div>
        <div class="band">
            <div class="band-title">Consignment</div>
            <?php if (!empty($do['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($do['project_name']) ?></p><?php endif; ?>
            <?php if (!empty($do['contract_no'])): ?><p><strong>Contract No:</strong> <?= htmlspecialchars($do['contract_no']) ?></p><?php endif; ?>
            <?php if (!empty($do['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($do['warehouse_name']) ?></p><?php endif; ?>
        </div>
    </div>

    <!-- META STRIP: driver / vehicle / contact / expected date -->
    <div class="meta-strip">
        <div class="band">
            <div class="band-title">Driver</div>
            <div class="band-value"><?= !empty($do['driver_name']) ? htmlspecialchars($do['driver_name']) : '—' ?></div>
        </div>
        <div class="band">
            <div class="band-title">Vehicle Reg</div>
            <div class="band-value"><?= !empty($do['vehicle_number']) ? htmlspecialchars($do['vehicle_number']) : '—' ?></div>
        </div>
        <div class="band">
            <div class="band-title">Site Contact</div>
            <div class="band-value" style="font-size:11px;"><?= !empty($do['contact_person']) ? htmlspecialchars($do['contact_person']) : '—' ?></div>
            <?php if (!empty($do['contact_phone'])): ?><p style="font-size:10px;"><?= htmlspecialchars($do['contact_phone']) ?></p><?php endif; ?>
        </div>
        <div class="band">
            <div class="band-title">Expected Date</div>
            <div class="band-value" style="font-size:11px;"><?= !empty($do['expected_date']) ? date('d M Y', strtotime($do['expected_date'])) : 'Not specified' ?></div>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:44px;">S/NO</th>
                <th>Product / Description</th>
                <th class="text-right" style="width:110px;">Available Qty</th>
                <th class="text-right" style="width:110px;">Qty to Issue</th>
                <th class="text-center" style="width:80px;">Unit</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($items)): foreach ($items as $i => $item): ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($item['product_name']) ?></td>
                <td class="text-right"><?= number_format((float)$item['available_qty'], 3) ?></td>
                <td class="text-right fw-bold"><?= number_format((float)$item['qty_to_issue'], 3) ?></td>
                <td class="text-center"><?= htmlspecialchars($item['unit'] ?: '—') ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" class="no-items">No items recorded on this Delivery Order.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- LINKED DELIVERY NOTES -->
    <?php if (!empty($linkedDns)): ?>
    <table>
        <thead>
            <tr>
                <th style="width:38%;">Delivery Note Issued</th>
                <th style="width:32%;">Date</th>
                <th style="width:30%;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($linkedDns as $dn):
                $dnColors = ['draft'=>'#6c757d','review'=>'#e0a800','approved'=>'#198754'];
                $dnColor  = $dnColors[$dn['status']] ?? '#6c757d';
            ?>
            <tr>
                <td class="fw-bold"><?= htmlspecialchars($dn['delivery_number']) ?></td>
                <td><?= date('d M Y', strtotime($dn['delivery_date'])) ?></td>
                <td><span class="dn-badge" style="background:<?= $dnColor ?>;"><?= htmlspecialchars(strtoupper($dn['status'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- NOTES -->
    <div class="notes-section">
        <?php if (!empty($do['notes'])): ?>
        <div><strong>Notes / Delivery Instructions</strong><p><?= nl2br(htmlspecialchars($do['notes'])) ?></p></div>
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
