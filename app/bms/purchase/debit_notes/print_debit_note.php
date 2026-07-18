<?php
// File: app/bms/purchase/debit_notes/print_debit_note.php
// Formal Debit Note print — follows i_e_print.md (canonical margins, blue header,
// shared footer) + includes/workflow_signature_row.php (Created/Reviewed/Approved).
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

// Project-scope guard via the linked purchase return → purchase order
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

    // SKU (Product Code) is resolved from the real product at PRINT time only.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DEBIT NOTE #<?= htmlspecialchars($dn['debit_note_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1a252f; line-height: 1.5; padding: 20px 20px 0 20px; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; padding-bottom: 18px; border-bottom: 3px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .company-info { flex: 1; padding-right: 20px; }
        .company-info h1 { color: #0d6efd; font-size: 22px; font-weight: 800; text-transform: uppercase; margin: 0 0 10px 0; letter-spacing: 0.5px; }
        .company-addr-row { display: flex; align-items: flex-start; gap: 14px; }
        .company-addr-row img { max-height: 60px; width: auto; flex-shrink: 0; object-fit: contain; }
        .company-addr-info p { margin: 2px 0; color: #1a252f; font-size: 11px; font-weight: 500; }
        .doc-title-box { text-align: right; background: #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; padding: 16px 22px; border-radius: 8px; min-width: 220px; }
        .doc-title-box h2 { margin: 0 0 10px 0; color: #fff; font-size: 16px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .doc-title-box p { margin: 4px 0; font-size: 12px; color: #fff; }
        .doc-title-box strong { font-weight: 600; }
        .details-grid { display: flex; justify-content: space-between; margin-bottom: 24px; gap: 14px; }
        .box { width: 48%; background: #f4f6f8; padding: 14px 16px; border-radius: 6px; border-left: 4px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .box h3 { font-size: 11px; color: #1a252f; padding-bottom: 7px; margin-bottom: 10px; border-bottom: 1.5px solid #3498db; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .box p { margin: 3px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #34495e; color: #fff; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr:nth-child(even) { background: #f9fafb; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr:last-child { border-bottom: 2px solid #3498db; }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; color: #1a252f; }
        .text-right { text-align: right; } .text-center { text-align: center; } .fw-bold { font-weight: 700; }
        .totals { float: right; width: 310px; background: #f4f6f8; padding: 14px 18px; border-radius: 6px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .totals-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 12px; color: #1a252f; border-bottom: 1px solid #e4e8ec; }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total { border-top: 2px solid #3498db; border-bottom: none; margin-top: 8px; padding-top: 10px; font-size: 14px; font-weight: 700; color: #1a252f; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div { background: #f4f6f8; padding: 12px 14px; border-radius: 6px; margin-bottom: 10px; border-left: 3px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .notes-section strong { color: #1a252f; display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 700; }
        .notes-section p { color: #1a252f; font-size: 11px; }
        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } .box, .totals, .notes-section > div { box-shadow: none; border: 1px solid #e0e0e0; } }
    </style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">
    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>


    <div class="print-scale-wrapper">
    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?><img src="<?= htmlspecialchars('../../../../' . $comp['logo']) ?>" alt="Logo"><?php endif; ?>
                <div class="company-addr-info">
                    <?php if (!empty($comp['address'])): ?><p><?= htmlspecialchars($comp['address']) ?></p><?php endif; ?>
                    <?php if (!empty($comp['postal_address'])): ?><p>P.O. Box <?= htmlspecialchars($comp['postal_address']) ?></p><?php endif; ?>
                    <?php if (!empty($comp['phone'])): ?><p>Phone: <?= htmlspecialchars($comp['phone']) ?></p><?php endif; ?>
                    <?php if (!empty($comp['website'])): ?><p>Web: <?= htmlspecialchars($comp['website']) ?></p><?php endif; ?>
                    <?php if (!empty($comp['email'])): ?><p>Email: <?= htmlspecialchars($comp['email']) ?></p><?php endif; ?>
                    <?php $tv = [];
                        if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                        if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                        if ($tv): ?><p><?= implode(' | ', $tv) ?></p><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="doc-title-box">
            <h2>DEBIT NOTE</h2>
            <p><strong>Debit Note #:</strong> <?= htmlspecialchars($dn['debit_note_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($dn['debit_date'])) ?></p>
            <?php if (!empty($dn['return_number'])): ?><p><strong>Ref Return:</strong> <?= htmlspecialchars($dn['return_number']) ?></p><?php endif; ?>
            <p><strong>Status:</strong> <?= strtoupper($dn['status']) ?></p>
        </div>
    </div>

    <div class="details-grid">
        <div class="box">
            <h3>Debit To</h3>
            <p><strong><?= htmlspecialchars($dn['supplier_name']) ?></strong></p>
            <?php if (!empty($dn['company_name'])): ?><p><?= htmlspecialchars($dn['company_name']) ?></p><?php endif; ?>
            <?php if (!empty($dn['s_postal_address'])): ?><p>P.O. Box <?= htmlspecialchars($dn['s_postal_address']) ?></p><?php endif; ?>
            <?php if (!empty($dn['s_address'])): ?><p><?= htmlspecialchars($dn['s_address']) ?></p><?php endif; ?>
            <?php if (!empty($dn['s_phone'])): ?><p><?= htmlspecialchars($dn['s_phone']) ?></p><?php endif; ?>
            <?php if (!empty($dn['s_email'])): ?><p><?= htmlspecialchars($dn['s_email']) ?></p><?php endif; ?>
            <?php $stv = [];
                if (!empty($dn['s_tin'])) $stv[] = 'TIN: ' . htmlspecialchars($dn['s_tin']);
                if (!empty($dn['s_vrn'])) $stv[] = 'VRN: ' . htmlspecialchars($dn['s_vrn']);
                if ($stv): ?><p><?= implode(' | ', $stv) ?></p><?php endif; ?>
        </div>
        <div class="box">
            <h3>Debit Note Information</h3>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($creator_name ?: 'System') ?></p>
            <p><strong>Currency:</strong> <?= htmlspecialchars($currency) ?></p>
            <?php if (!empty($dn['warehouse_name'])): ?><p><strong>Returned From:</strong> <?= htmlspecialchars($dn['warehouse_name']) ?></p><?php endif; ?>
            <?php if (!empty($dn['reason'])): ?><p><strong>Reason:</strong> <?= htmlspecialchars($dn['reason']) ?></p><?php endif; ?>
        </div>
    </div>

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

    <div class="totals">
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($dn['subtotal_amount'], 2) ?></span></div>
        <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($dn['total_tax'], 2) ?></span></div>
        <div class="totals-row grand-total"><span>TOTAL DEBIT:</span><span><?= $currency ?> <?= number_format($dn['grand_total'], 2) ?></span></div>
    </div>

    <div class="notes-section">
        <?php if (!empty($dn['notes'])): ?>
        <div><strong>Notes:</strong><p><?= nl2br(htmlspecialchars($dn['notes'])) ?></p></div>
        <?php endif; ?>
    </div>

    <!-- SIGNATURE — canonical partial (Created / Reviewed / Approved + e-signatures) -->
    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>


    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</body>
</html>
