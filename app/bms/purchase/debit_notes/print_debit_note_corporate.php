<?php
// File: app/bms/purchase/debit_notes/print_debit_note_corporate.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/permissions.php';
require_once __DIR__ . '/../../../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('debit_notes')) die("Access Denied");

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) die("Invalid Debit Note ID");

global $pdo;

$_p = $pdo->prepare("
    SELECT po.project_id
      FROM debit_notes dn
      LEFT JOIN purchase_returns pr ON dn.purchase_return_id = pr.purchase_return_id
      LEFT JOIN purchase_orders po  ON pr.purchase_order_id  = po.purchase_order_id
     WHERE dn.debit_note_id = ? LIMIT 1
");
$_p->execute([$id]);
$_pid = $_p->fetchColumn();
if ($_pid && !userCan('project', (int)$_pid)) {
    http_response_code(403);
    die('Access denied: this debit note belongs to a project not in your scope.');
}

try {
    $stmt = $pdo->prepare("
        SELECT dn.*,
               s.supplier_name, s.company_name, s.email AS s_email, s.phone AS s_phone,
               s.address AS s_address, s.postal_address AS s_postal_address,
               s.tax_id AS s_tin, s.vat_number AS s_vrn,
               pr.return_number, w.warehouse_name,
               u.first_name AS creator_first, u.last_name AS creator_last, u.username AS creator_username,
               COALESCE(u.user_role,  u.role)                                          AS creator_role,
               TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
               COALESCE(ur.user_role, ur.role)                                        AS reviewer_role,
               TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
               COALESCE(ua.user_role, ua.role)                                        AS approver_role
          FROM debit_notes dn
          LEFT JOIN suppliers s          ON dn.supplier_id        = s.supplier_id
          LEFT JOIN purchase_returns pr  ON dn.purchase_return_id = pr.purchase_return_id
          LEFT JOIN warehouses w         ON pr.warehouse_id       = w.warehouse_id
          LEFT JOIN users u  ON dn.created_by  = u.user_id
          LEFT JOIN users ur ON dn.reviewed_by = ur.user_id
          LEFT JOIN users ua ON dn.approved_by = ua.user_id
         WHERE dn.debit_note_id = ?
    ");
    $stmt->execute([$id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn) die("Debit note not found");

    require_once __DIR__ . '/../../../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], 'Print Debit Note',
        ($_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User') . " printed Debit Note #{$dn['debit_note_number']}");

    $stmtItems = $pdo->prepare("
        SELECT dni.*, p.sku
          FROM debit_note_items dni
          LEFT JOIN products p ON dni.product_id = p.product_id
         WHERE dni.debit_note_id = ?
    ");
    $stmtItems->execute([$id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error");
}

$currency = 'TZS';

$comp = ['name'=>'Business Management System','email'=>'','phone'=>'','address'=>'','postal_address'=>'','website'=>'','tin'=>'','vrn'=>'','logo'=>''];
try {
    $stmtC = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'company_%'");
    while ($r = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        $k = str_replace('company_', '', $r['setting_key']);
        if ($k === 'physical_address') $comp['address'] = $r['setting_value'];
        elseif ($k === 'logo')         $comp['logo']    = $r['setting_value'];
        else                           $comp[$k]        = $r['setting_value'];
    }
} catch (Exception $e) {}

$creator_name = trim(($dn['creator_first'] ?? '') . ' ' . ($dn['creator_last'] ?? '')) ?: ($dn['creator_username'] ?? '');
$wf_sigs = getWorkflowSignatures($pdo, 'debit_note', $id);
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => trim($dn['creator_role']  ?? ''),
    'reviewed_by_name'   => trim($dn['reviewer_name'] ?? ''),
    'reviewed_by_role'   => trim($dn['reviewer_role'] ?? ''),
    'approved_by_name'   => trim($dn['approver_name'] ?? ''),
    'approved_by_role'   => trim($dn['approver_role'] ?? ''),
    'created_sig_path'   => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']  ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']  ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at'] ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']  ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at'] ?? null,
    '__include_css'      => true,
];

// Debit Note's own Corporate-layout accent color — separate from Purchase Order
// and Purchase Return even though they share the same Navy/Corporate/Banded
// designs.
$accent = getSetting('print_template_color_dbn_corporate', '#000000');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DEBIT NOTE #<?= htmlspecialchars($dn['debit_note_number']) ?></title>
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

        .company-strip { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px solid #d8d8d8; }
        .company-strip img { max-height: 46px; width: auto; object-fit: contain; }
        .company-strip h1 { font-size: 16px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
        .company-strip p { font-size: 10px; color: #444; margin: 0; }

        .title-bar { background: var(--accent); color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .title-bar h2 { font-size: 20px; font-weight: 800; letter-spacing: 1.5px; }
        .title-bar .meta { text-align: right; font-size: 11.5px; }
        .title-bar .meta p { margin: 2px 0; }

        .section-label { background: var(--accent); color: #fff; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 6px 12px; margin-bottom: 0; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .section-body { border: 1px solid var(--accent); border-top: none; padding: 12px 14px; margin-bottom: 18px; }
        .section-body p { margin: 3px 0; font-size: 11.5px; }
        .section-body strong { font-weight: 700; }

        .two-col { display: flex; gap: 16px; }
        .two-col > div { width: 50%; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: var(--accent); color: #fff; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr { border-bottom: 1px solid #ddd; }
        tbody tr:last-child { border-bottom: 2px solid var(--accent); }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .totals { float: right; width: 300px; border: 1px solid var(--accent); padding: 12px 16px; margin-bottom: 18px; }
        .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 12px; border-bottom: 1px solid #ddd; }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total { border-top: 2px solid var(--accent); border-bottom: none; margin-top: 8px; padding-top: 9px; font-size: 14px; font-weight: 700; }

        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div { border: 1px solid var(--accent); padding: 12px 14px; margin-bottom: 10px; }
        .notes-section strong { display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; }
        .notes-section p { font-size: 11px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <!-- COMPANY STRIP -->
    <div class="company-strip">
        <?php if (!empty($comp['logo'])): ?>
        <img src="<?= htmlspecialchars('../../../../' . $comp['logo']) ?>" alt="Logo">
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
        <h2>DEBIT NOTE</h2>
        <div class="meta">
            <p><strong>Debit Note #:</strong> <?= htmlspecialchars($dn['debit_note_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($dn['debit_date'])) ?></p>
            <?php if (!empty($dn['return_number'])): ?><p><strong>Ref Return:</strong> <?= htmlspecialchars($dn['return_number']) ?></p><?php endif; ?>
        </div>
    </div>

    <!-- DEBIT TO + DEBIT NOTE INFORMATION -->
    <div class="two-col">
        <div>
            <div class="section-label">Debit To</div>
            <div class="section-body">
                <p><strong><?= htmlspecialchars($dn['supplier_name']) ?></strong></p>
                <?php if (!empty($dn['company_name'])): ?><p><?= htmlspecialchars($dn['company_name']) ?></p><?php endif; ?>
                <?php if (!empty($dn['s_postal_address'])): ?><p>P.O. Box <?= htmlspecialchars($dn['s_postal_address']) ?></p><?php endif; ?>
                <?php if (!empty($dn['s_address'])): ?><p><?= htmlspecialchars($dn['s_address']) ?></p><?php endif; ?>
                <?php if (!empty($dn['s_phone'])): ?><p><?= htmlspecialchars($dn['s_phone']) ?></p><?php endif; ?>
                <?php if (!empty($dn['s_email'])): ?><p><?= htmlspecialchars($dn['s_email']) ?></p><?php endif; ?>
                <?php
                $stv = [];
                if (!empty($dn['s_tin'])) $stv[] = 'TIN: ' . htmlspecialchars($dn['s_tin']);
                if (!empty($dn['s_vrn'])) $stv[] = 'VRN: ' . htmlspecialchars($dn['s_vrn']);
                if ($stv): ?><p><?= implode(' | ', $stv) ?></p><?php endif; ?>
            </div>
        </div>
        <div>
            <div class="section-label">Debit Note Information</div>
            <div class="section-body">
                <p><strong>Prepared By:</strong> <?= htmlspecialchars($creator_name ?: 'System') ?></p>
                <p><strong>Currency:</strong> <?= htmlspecialchars($currency) ?></p>
                <?php if (!empty($dn['warehouse_name'])): ?><p><strong>Returned From:</strong> <?= htmlspecialchars($dn['warehouse_name']) ?></p><?php endif; ?>
                <?php if (!empty($dn['reason'])): ?><p><strong>Reason:</strong> <?= htmlspecialchars($dn['reason']) ?></p><?php endif; ?>
                <p style="margin-top:8px;"><span style="display:inline-block;padding:4px 12px;background:var(--accent);color:#fff;font-weight:700;font-size:11px;text-transform:uppercase;border-radius:3px;"><?= strtoupper($dn['status']) ?></span></p>
            </div>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center" style="width:100px;">Product Code</th>
                <th>Item / Description</th>
                <th class="text-right" style="width:70px;">Qty</th>
                <th class="text-right" style="width:100px;">Unit Price</th>
                <th class="text-center" style="width:60px;">VAT</th>
                <th class="text-right" style="width:110px;">Total (<?= $currency ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $it): ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= !empty($it['sku']) ? htmlspecialchars($it['sku']) : '—' ?></td>
                <td><?= htmlspecialchars($it['description'] ?? 'Item') ?></td>
                <td class="text-right"><?= rtrim(rtrim(number_format($it['quantity'], 2), '0'), '.') ?></td>
                <td class="text-right"><?= number_format($it['unit_price'], 2) ?></td>
                <td class="text-center"><?= ((float)$it['tax_rate'] == 18) ? '18%' : '—' ?></td>
                <td class="text-right fw-bold"><?= number_format($it['total_amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- TOTALS -->
    <div class="totals">
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($dn['subtotal_amount'], 2) ?></span></div>
        <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($dn['total_tax'], 2) ?></span></div>
        <div class="totals-row grand-total"><span>TOTAL DEBIT:</span><span><?= $currency ?> <?= number_format($dn['grand_total'], 2) ?></span></div>
    </div>

    <!-- NOTES -->
    <div class="notes-section">
        <?php if (!empty($dn['notes'])): ?>
        <div><strong>Notes</strong><p><?= nl2br(htmlspecialchars($dn['notes'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
