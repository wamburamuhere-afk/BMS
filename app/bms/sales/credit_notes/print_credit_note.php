<?php
// File: app/bms/sales/credit_notes/print_credit_note.php
// Formal Credit Note print — follows i_e_print.md (canonical margins, blue header,
// shared footer) + includes/workflow_signature_row.php (Created/Reviewed/Approved).
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/permissions.php';
require_once __DIR__ . '/../../../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('credit_notes')) die("Access Denied");

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) die("Invalid Credit Note ID");

global $pdo;

// Project-scope guard via the linked sales return → sales order
$_p = $pdo->prepare("
    SELECT so.project_id
      FROM credit_notes cn
      LEFT JOIN sales_returns sr ON cn.sales_return_id = sr.sales_return_id
      LEFT JOIN sales_orders so  ON sr.sales_order_id  = so.sales_order_id
     WHERE cn.credit_note_id = ? LIMIT 1
");
$_p->execute([$id]);
$_pid = $_p->fetchColumn();
if ($_pid && !userCan('project', (int)$_pid)) {
    http_response_code(403);
    die('Access denied: this credit note belongs to a project not in your scope.');
}

try {
    $stmt = $pdo->prepare("
        SELECT cn.*,
               c.customer_name, c.company_name, c.email AS c_email, c.phone AS c_phone,
               c.address AS c_address, c.postal_address AS c_postal_address,
               c.tax_id AS c_tin, c.vat_number AS c_vrn,
               sr.return_number,
               u.first_name AS creator_first, u.last_name AS creator_last, u.username AS creator_username,
               COALESCE(u.user_role,  u.role)                                          AS creator_role,
               TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
               COALESCE(ur.user_role, ur.role)                                        AS reviewer_role,
               TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
               COALESCE(ua.user_role, ua.role)                                        AS approver_role
          FROM credit_notes cn
          LEFT JOIN customers c      ON cn.customer_id  = c.customer_id
          LEFT JOIN sales_returns sr ON cn.sales_return_id = sr.sales_return_id
          LEFT JOIN users u  ON cn.created_by  = u.user_id
          LEFT JOIN users ur ON cn.reviewed_by = ur.user_id
          LEFT JOIN users ua ON cn.approved_by = ua.user_id
         WHERE cn.credit_note_id = ?
    ");
    $stmt->execute([$id]);
    $cn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cn) die("Credit note not found");

    require_once __DIR__ . '/../../../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], 'Print Credit Note',
        ($_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User') . " printed Credit Note #{$cn['credit_note_number']}");

    // SKU (Product Code) is resolved from the real product at PRINT time only.
    $stmtItems = $pdo->prepare("
        SELECT cni.*, p.sku
          FROM credit_note_items cni
          LEFT JOIN products p ON cni.product_id = p.product_id
         WHERE cni.credit_note_id = ?
    ");
    $stmtItems->execute([$id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error");
}

$currency = 'TZS';

// Company settings
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

// Workflow signature data for the canonical partial
$creator_name = trim(($cn['creator_first'] ?? '') . ' ' . ($cn['creator_last'] ?? '')) ?: ($cn['creator_username'] ?? '');
$wf_sigs = getWorkflowSignatures($pdo, 'credit_note', $id);
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => trim($cn['creator_role']  ?? ''),
    'reviewed_by_name'   => trim($cn['reviewer_name'] ?? ''),
    'reviewed_by_role'   => trim($cn['reviewer_role'] ?? ''),
    'approved_by_name'   => trim($cn['approver_name'] ?? ''),
    'approved_by_role'   => trim($cn['approver_role'] ?? ''),
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
    <title>CREDIT NOTE #<?= htmlspecialchars($cn['credit_note_number']) ?></title>
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
</head>
<body onload="window.print()">
    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>

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
            <h2>CREDIT NOTE</h2>
            <p><strong>Credit Note #:</strong> <?= htmlspecialchars($cn['credit_note_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($cn['credit_date'])) ?></p>
            <?php if (!empty($cn['return_number'])): ?><p><strong>Ref Return:</strong> <?= htmlspecialchars($cn['return_number']) ?></p><?php endif; ?>
            <p><strong>Status:</strong> <?= strtoupper($cn['status']) ?></p>
        </div>
    </div>

    <div class="details-grid">
        <div class="box">
            <h3>Credit To</h3>
            <p><strong><?= htmlspecialchars($cn['customer_name']) ?></strong></p>
            <?php if (!empty($cn['company_name'])): ?><p><?= htmlspecialchars($cn['company_name']) ?></p><?php endif; ?>
            <?php if (!empty($cn['c_postal_address'])): ?><p>P.O. Box <?= htmlspecialchars($cn['c_postal_address']) ?></p><?php endif; ?>
            <?php if (!empty($cn['c_address'])): ?><p><?= htmlspecialchars($cn['c_address']) ?></p><?php endif; ?>
            <?php if (!empty($cn['c_phone'])): ?><p><?= htmlspecialchars($cn['c_phone']) ?></p><?php endif; ?>
            <?php if (!empty($cn['c_email'])): ?><p><?= htmlspecialchars($cn['c_email']) ?></p><?php endif; ?>
            <?php $ctv = [];
                if (!empty($cn['c_tin'])) $ctv[] = 'TIN: ' . htmlspecialchars($cn['c_tin']);
                if (!empty($cn['c_vrn'])) $ctv[] = 'VRN: ' . htmlspecialchars($cn['c_vrn']);
                if ($ctv): ?><p><?= implode(' | ', $ctv) ?></p><?php endif; ?>
        </div>
        <div class="box">
            <h3>Credit Note Information</h3>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($creator_name ?: 'System') ?></p>
            <p><strong>Currency:</strong> <?= htmlspecialchars($currency) ?></p>
            <?php if (!empty($cn['reason'])): ?><p><strong>Reason:</strong> <?= htmlspecialchars($cn['reason']) ?></p><?php endif; ?>
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
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($cn['subtotal_amount'], 2) ?></span></div>
        <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($cn['total_tax'], 2) ?></span></div>
        <div class="totals-row grand-total"><span>TOTAL CREDIT:</span><span><?= $currency ?> <?= number_format($cn['grand_total'], 2) ?></span></div>
    </div>

    <div class="notes-section">
        <?php if (!empty($cn['notes'])): ?>
        <div><strong>Notes:</strong><p><?= nl2br(htmlspecialchars($cn['notes'])) ?></p></div>
        <?php endif; ?>
    </div>

    <!-- SIGNATURE — canonical partial (Created / Reviewed / Approved + e-signatures) -->
    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</body>
</html>
