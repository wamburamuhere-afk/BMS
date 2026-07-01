<?php
// File: print_lpo.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/permissions.php';
require_once __DIR__ . '/../../../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('lpo')) die("Access Denied");

$lpo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
assertScopeForRecordHtml('customer_lpos', 'lpo_id', $lpo_id);

global $pdo;
$stmt = $pdo->prepare("
    SELECT l.*,
           CASE WHEN c.customer_type = 'business' AND c.company_name != '' AND c.company_name IS NOT NULL
                THEN c.company_name ELSE c.customer_name END AS customer_display_name,
           c.address AS c_address, c.phone AS c_phone, c.email AS c_email,
           c.tax_id AS c_tin, c.vat_number AS c_vrn,
           u.username, u.first_name AS creator_first, u.last_name AS creator_last,
           COALESCE(u.user_role, u.role) AS creator_role,
           pr.project_name
    FROM customer_lpos l
    LEFT JOIN customers c ON l.customer_id = c.customer_id
    LEFT JOIN users u ON l.created_by = u.user_id
    LEFT JOIN projects pr ON l.project_id = pr.project_id
    WHERE l.lpo_id = ?
");
$stmt->execute([$lpo_id]);
$lpo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lpo) die("LPO not found");

$stmtItems = $pdo->prepare("SELECT * FROM customer_lpo_items WHERE lpo_id = ? ORDER BY sort_order, item_id");
$stmtItems->execute([$lpo_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$currency = $lpo['currency'] ?? 'TZS';
$subtotal = 0; $taxTotal = 0;
foreach ($items as $item) {
    $lineSub = (float)$item['quantity'] * (float)$item['unit_price'];
    $subtotal += $lineSub;
    $taxTotal += $lineSub * ((float)$item['tax_rate'] / 100);
}

// ── Three-approval workflow data for signature row + DRAFT watermark ──
$wf_status = $lpo['status'] ?? 'pending';
$wf_sigs   = $lpo_id ? getWorkflowSignatures($pdo, 'customer_lpo', $lpo_id) : [];

$lpo_creator_name = trim(($lpo['creator_first'] ?? '') . ' ' . ($lpo['creator_last'] ?? ''))
                    ?: ($lpo['username'] ?? '')
                    ?: ($lpo['prepared_by_name'] ?? '');
$lpo_creator_role = $lpo['creator_role'] ?: ($lpo['prepared_by_role'] ?? '');

$wf = [
    'created_by_name'    => $lpo_creator_name,
    'created_by_role'    => $lpo_creator_role,
    'reviewed_by_name'   => $lpo['reviewed_by_name'] ?? '',
    'reviewed_by_role'   => $lpo['reviewed_by_role'] ?? '',
    'approved_by_name'   => $lpo['approved_by_name'] ?? '',
    'approved_by_role'   => $lpo['approved_by_role'] ?? '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']  ?? null,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer LPO #<?= htmlspecialchars($lpo['lpo_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1a252f; line-height: 1.5; padding: 20px 20px 0 20px; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; padding-bottom: 18px; border-bottom: 3px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .company-info { flex: 1; padding-right: 20px; }
        .company-info h1 { color: #0d6efd; font-size: 22px; font-weight: 800; text-transform: uppercase; margin: 0 0 10px 0; letter-spacing: 0.5px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .company-addr-row { display: flex; align-items: flex-start; gap: 14px; }
        .company-addr-row img { max-height: 60px; width: auto; flex-shrink: 0; object-fit: contain; }
        .company-addr-info p { margin: 2px 0; color: #1a252f; font-size: 11px; font-weight: 500; }
        .lpo-title { text-align: right; background: #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; padding: 16px 22px; border-radius: 8px; min-width: 195px; }
        .lpo-title h2 { margin: 0 0 10px 0; color: #fff; font-size: 16px; font-weight: 700; letter-spacing: 1px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .lpo-title p { margin: 4px 0; font-size: 12px; color: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .lpo-title strong { font-weight: 600; }
        .details-grid { display: flex; justify-content: space-between; margin-bottom: 24px; gap: 14px; }
        .box { width: 48%; background: #f4f6f8; padding: 14px 16px; border-radius: 6px; border-left: 4px solid #3498db; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .box h3 { font-size: 11px; color: #1a252f; padding-bottom: 7px; margin-bottom: 10px; border-bottom: 1.5px solid #3498db; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
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
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?>
                <img src="<?= htmlspecialchars('../../../../' . $comp['logo']) ?>" alt="Logo">
                <?php endif; ?>
                <div class="company-addr-info">
                    <?php if (!empty($comp['address'])): ?><p><?= htmlspecialchars($comp['address']) ?></p><?php endif; ?>
                    <?php if (!empty($comp['postal_address'])): ?><p><?= htmlspecialchars($comp['postal_address']) ?></p><?php endif; ?>
                    <?php if (!empty($comp['phone'])): ?><p>Phone: <?= htmlspecialchars($comp['phone']) ?></p><?php endif; ?>
                    <?php
                    $we = [];
                    if (!empty($comp['website'])) $we[] = 'Web: '   . htmlspecialchars($comp['website']);
                    if (!empty($comp['email']))   $we[] = 'Email: ' . htmlspecialchars($comp['email']);
                    if ($we): ?><p><?= implode(' | ', $we) ?></p><?php endif; ?>
                    <?php
                    $tv = [];
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                    if ($tv): ?><p><?= implode(' | ', $tv) ?></p><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="lpo-title">
            <h2>CUSTOMER LPO</h2>
            <p><strong>LPO #:</strong> <?= htmlspecialchars($lpo['lpo_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($lpo['issue_date'])) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($lpo['status']) ?></p>
        </div>
    </div>

    <div class="details-grid">
        <div class="box">
            <h3>Customer</h3>
            <p><strong><?= htmlspecialchars($lpo['customer_display_name'] ?? '') ?></strong></p>
            <?php if (!empty($lpo['c_address'])): ?><p><?= htmlspecialchars($lpo['c_address']) ?></p><?php endif; ?>
            <?php if (!empty($lpo['c_phone'])): ?><p><?= htmlspecialchars($lpo['c_phone']) ?></p><?php endif; ?>
            <?php if (!empty($lpo['c_email'])): ?><p><?= htmlspecialchars($lpo['c_email']) ?></p><?php endif; ?>
            <?php
            $c_tv = [];
            if (!empty($lpo['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($lpo['c_tin']);
            if (!empty($lpo['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($lpo['c_vrn']);
            if ($c_tv): ?><p><?= implode(' | ', $c_tv) ?></p><?php endif; ?>
        </div>
        <div class="box">
            <h3>LPO Information</h3>
            <p><strong>Expiry Date:</strong> <?= !empty($lpo['expiry_date']) ? date('d M Y', strtotime($lpo['expiry_date'])) : 'Not specified' ?></p>
            <?php if (!empty($lpo['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($lpo['project_name']) ?></p><?php endif; ?>
            <?php if (!empty($lpo['description'])): ?><p><strong>Description:</strong> <?= htmlspecialchars($lpo['description']) ?></p><?php endif; ?>
            <p><strong>Created By:</strong> <?= htmlspecialchars($lpo['username'] ?? 'N/A') ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center">Item / Description</th>
                <th class="text-right" style="width:80px;">Qty</th>
                <th class="text-right" style="width:105px;">Unit Price</th>
                <th class="text-right" style="width:70px;">Tax</th>
                <th class="text-right" style="width:115px;">Total (<?= $currency ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item): ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($item['product_name']) ?></td>
                <td class="text-right"><?= floatval($item['quantity']) ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right"><?= number_format($item['tax_rate'], 1) ?>%</td>
                <td class="text-right fw-bold"><?= number_format($item['total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($subtotal, 2) ?></span></div>
        <div class="totals-row"><span>Total Tax:</span><span><?= $currency ?> <?= number_format($taxTotal, 2) ?></span></div>
        <div class="totals-row grand-total"><span>GRAND TOTAL:</span><span><?= $currency ?> <?= number_format($lpo['amount'], 2) ?></span></div>
    </div>

    <div class="notes-section">
        <?php if (!empty($lpo['notes'])): ?>
        <div><strong>Internal Notes:</strong><p><?= nl2br(htmlspecialchars($lpo['notes'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>

    <div style="clear: both;">
        <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
