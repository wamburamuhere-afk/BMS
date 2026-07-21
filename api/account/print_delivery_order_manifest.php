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

// Delivery Order's own Manifest-layout accent color. Configurable from
// System Settings > Color Setting; falls back to the original design color when unset.
$accent = getSetting('print_template_color_do_manifest', '#b45309');
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

        /* ── MANIFEST HEADER BAND ── */
        .manifest-header {
            background: var(--accent); color: #fff; padding: 22px 26px;
            display: flex; justify-content: space-between; align-items: flex-start;
            border-radius: 0 0 10px 10px; margin: 0 -20px 22px -20px;
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
        }
        .manifest-header .company-side { display: flex; gap: 14px; align-items: flex-start; }
        .manifest-header .company-side img { max-height: 54px; width: auto; object-fit: contain; background: #fff; border-radius: 4px; padding: 3px; }
        .manifest-header h1 { font-size: 19px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; color: #fff; }
        .manifest-header .company-side p { font-size: 10.5px; color: rgba(255,255,255,0.75); margin: 1px 0; }
        .manifest-header .do-side { text-align: right; }
        .manifest-header .do-side h2 { font-size: 22px; font-weight: 800; letter-spacing: 1.5px; margin-bottom: 8px; color: #fff; }
        .manifest-header .do-side p { font-size: 11.5px; color: #fff; margin: 3px 0; }
        .status-pill {
            display: inline-block; margin-top: 6px; padding: 3px 12px; border: 1.5px solid #fff; border-radius: 3px;
            color: #fff; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
        }

        /* ── INFO BOXES ── */
        .details-grid { display: flex; justify-content: space-between; margin-bottom: 22px; gap: 16px; flex-wrap: wrap; }
        .box { width: 48%; background: #fdf5ec; padding: 14px 16px; border-radius: 4px; border: 1px solid #eddcc4; }
        .box h3 { font-size: 10.5px; color: var(--accent); padding-bottom: 7px; margin-bottom: 10px; border-bottom: 1.5px solid var(--accent); font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
        .box p { margin: 3px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 700; }

        /* ── ITEMS TABLE (manifest = bold ruled ledger look) ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th {
            background: var(--accent); color: #fff; font-weight: 700; font-size: 11px; text-transform: uppercase;
            letter-spacing: 0.6px; padding: 9px 10px; text-align: left; border: 1px solid var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
        }
        tbody tr td { height: 0.75cm; padding: 3px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; color: #1a252f; border: 1px solid #e2c9a3; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        .no-items { padding: 14px; text-align: center; color: #6c757d; font-style: italic; font-size: 11.5px; border: 1px solid #e2c9a3; }

        /* ── LINKED DNs ── */
        .dn-table th { background: #7a4a13; }
        .dn-badge { display: inline-block; padding: 2px 9px; border-radius: 3px; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }

        /* ── NOTES ── */
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div { background: #fdf5ec; padding: 12px 14px; border-radius: 4px; margin-bottom: 10px; border: 1px solid #eddcc4; }
        .notes-section strong { color: var(--accent); display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 800; text-transform: uppercase; }
        .notes-section p { color: #1a252f; font-size: 11px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">

    <div class="no-print" style="margin:20px 0; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <div class="print-scale-wrapper">
    <!-- HEADER -->
    <div class="manifest-header">
        <div class="company-side">
            <?php if (!empty($comp['logo'])): ?>
            <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <div>
                <h1><?= htmlspecialchars($comp['name']) ?></h1>
                <?php if (!empty($comp['address'])): ?><p><?= htmlspecialchars($comp['address']) ?></p><?php endif; ?>
                <?php if (!empty($comp['phone'])): ?><p>Phone: <?= htmlspecialchars($comp['phone']) ?></p><?php endif; ?>
                <?php if (!empty($comp['email'])): ?><p>Email: <?= htmlspecialchars($comp['email']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="do-side">
            <h2>DISPATCH MANIFEST</h2>
            <p><strong>DO #:</strong> <?= htmlspecialchars($do['do_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($do['do_date'])) ?></p>
            <div class="status-pill"><?= htmlspecialchars(str_replace('_', ' ', strtoupper($do['status']))) ?></div>
        </div>
    </div>

    <!-- SUPPLIER + DELIVERY INFO -->
    <div class="details-grid">
        <div class="box">
            <h3>Supplier</h3>
            <p><strong><?= htmlspecialchars($do['supplier_name'] ?? 'N/A') ?></strong></p>
            <?php if (!empty($do['company_name'])): ?><p><?= htmlspecialchars($do['company_name']) ?></p><?php endif; ?>
            <?php if (!empty($do['supplier_contact'])): ?><p>Contact: <?= htmlspecialchars($do['supplier_contact']) ?></p><?php endif; ?>
            <?php if (!empty($do['supplier_phone'])): ?><p><?= htmlspecialchars($do['supplier_phone']) ?></p><?php endif; ?>
        </div>
        <div class="box">
            <h3>Consignment Details</h3>
            <?php if (!empty($do['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($do['project_name']) ?></p><?php endif; ?>
            <?php if (!empty($do['contract_no'])): ?><p><strong>Contract No:</strong> <?= htmlspecialchars($do['contract_no']) ?></p><?php endif; ?>
            <?php if (!empty($do['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($do['warehouse_name']) ?></p><?php endif; ?>
            <p><strong>Expected Date:</strong> <?= !empty($do['expected_date']) ? date('d M Y', strtotime($do['expected_date'])) : 'Not specified' ?></p>
            <p><strong>Created By:</strong> <?= htmlspecialchars($do_creator_name ?: 'N/A') ?></p>
        </div>
        <?php if (!empty($do['driver_name']) || !empty($do['vehicle_number']) || !empty($do['contact_person'])): ?>
        <div class="box" style="width:100%;">
            <h3>Haulage &amp; Site Contact</h3>
            <div style="display:flex; gap:24px; flex-wrap:wrap;">
                <?php if (!empty($do['driver_name'])): ?><p><strong>Driver:</strong> <?= htmlspecialchars($do['driver_name']) ?></p><?php endif; ?>
                <?php if (!empty($do['vehicle_number'])): ?><p><strong>Vehicle Reg:</strong> <?= htmlspecialchars($do['vehicle_number']) ?></p><?php endif; ?>
                <?php if (!empty($do['contact_person'])): ?><p><strong>Site Contact:</strong> <?= htmlspecialchars($do['contact_person']) ?><?= !empty($do['contact_phone']) ? ' — ' . htmlspecialchars($do['contact_phone']) : '' ?></p><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
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
    <table class="dn-table">
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
        <div><strong>Notes / Delivery Instructions:</strong><p><?= nl2br(htmlspecialchars($do['notes'])) ?></p></div>
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
