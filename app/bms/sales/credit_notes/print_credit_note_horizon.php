<?php
// File: app/bms/sales/credit_notes/print_credit_note_horizon.php
// "Horizon" template — a full-width colour band anchors the header before the
// page drops to white, in the same structural family as this project's
// Navy/Corporate/Banded layouts. Same data as print_credit_note.php;
// presentation only differs. Own accent color, separate from Ledger and Ember.
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/permissions.php';
require_once __DIR__ . '/../../../../core/workflow.php';
require_once __DIR__ . '/../../../../core/warehouse_scope.php';

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
               inv.invoice_number,
               pa.account_name AS paid_from_name,
               u.first_name AS creator_first, u.last_name AS creator_last, u.username AS creator_username,
               COALESCE(u.user_role,  u.role)                                          AS creator_role,
               TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
               COALESCE(ur.user_role, ur.role)                                        AS reviewer_role,
               TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
               COALESCE(ua.user_role, ua.role)                                        AS approver_role,
               w.warehouse_id AS resolved_warehouse_id, w.warehouse_name
          FROM credit_notes cn
          LEFT JOIN customers c      ON cn.customer_id  = c.customer_id
          LEFT JOIN sales_returns sr ON cn.sales_return_id = sr.sales_return_id
          LEFT JOIN invoices inv     ON cn.invoice_id = inv.invoice_id
          LEFT JOIN sales_orders so  ON sr.sales_order_id = so.sales_order_id
          LEFT JOIN warehouses w     ON w.warehouse_id = COALESCE(inv.warehouse_id, so.warehouse_id)
          LEFT JOIN accounts pa      ON cn.paid_from_account_id = pa.account_id
          LEFT JOIN users u  ON cn.created_by  = u.user_id
          LEFT JOIN users ur ON cn.reviewed_by = ur.user_id
          LEFT JOIN users ua ON cn.approved_by = ua.user_id
         WHERE cn.credit_note_id = ?
    ");
    $stmt->execute([$id]);
    $cn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cn) die("Credit note not found");

    // Phase 6 (pos_upgrade_plan.md): gate directly on warehouse scope (the
    // source invoice/sales order's warehouse), not just project.
    if (!empty($cn['resolved_warehouse_id']) && !userCan('warehouse', (int)$cn['resolved_warehouse_id'])) {
        http_response_code(403);
        die('Access denied: this warehouse is not in your assigned scope.');
    }

    require_once __DIR__ . '/../../../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], 'Print Credit Note',
        ($_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User') . " printed Credit Note #{$cn['credit_note_number']} (Horizon template)");

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

$accent = getSetting('print_template_color_cn_horizon', '#1F5AA8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CREDIT NOTE #<?= htmlspecialchars($cn['credit_note_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1a252f; line-height: 1.5; background: #fff; }

        .band { background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; padding: 22px 30px; display: flex; justify-content: space-between; align-items: center; color: #fff; margin-bottom: 26px; }
        .band .company-block { display: flex; align-items: center; gap: 14px; }
        .band .company-block img { max-height: 50px; width: auto; object-fit: contain; background: #fff; border-radius: 4px; padding: 3px; }
        .band h1 { font-size: 17px; font-weight: 700; letter-spacing: 0.2px; }
        .band .sub { font-size: 10px; opacity: 0.92; margin-top: 3px; }
        .band .doc-title-block { text-align: right; }
        .band .doc-title-block h2 { font-size: 24px; font-weight: 800; letter-spacing: 2px; }
        .band .doc-title-block .num { font-size: 12px; font-weight: 600; margin-top: 4px; opacity: 0.95; }

        .body-wrap { padding: 0 30px; }

        .meta-strip { display: flex; background: #f2f6fa; border-radius: 6px; padding: 12px 18px; margin-bottom: 22px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .meta-strip .cell { flex: 1; }
        .meta-strip .lbl { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #5c6b73; font-weight: 700; }
        .meta-strip .val { font-size: 12px; font-weight: 700; margin-top: 2px; color: #1a252f; }
        .meta-strip .val.status { color: var(--accent); }

        .panel-row { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 22px; }
        .panel { flex: 1; background: #f8f9fa; border-radius: 6px; padding: 14px 16px; border-left: 4px solid var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .panel h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 8px; color: #1a252f; }
        .panel p { font-size: 11.5px; margin: 3px 0; color: #1a252f; }
        .panel strong { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th { background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; color: #fff; font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr:nth-child(even) { background: #f9fafb; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr td { padding: 8px 10px; font-size: 11.5px; color: #1a252f; }
        .text-right { text-align: right; } .text-center { text-align: center; } .fw-bold { font-weight: 700; }

        .bottom-row { display: flex; justify-content: space-between; gap: 18px; margin: 20px 0; align-items: flex-start; }
        .payment-info { flex: 1; }
        .payment-info h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 8px; }
        .payment-info p { font-size: 11px; margin: 3px 0; }

        .totals { width: 300px; background: #f2f6fa; border-radius: 6px; padding: 14px 18px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .totals-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 12px; border-bottom: 1px solid #e0e6ea; }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total { border-top: 2px solid var(--accent); border-bottom: none; margin-top: 8px; padding-top: 10px; font-size: 14px; font-weight: 800; color: var(--accent); }

        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; padding-bottom: 4px; }
        .notes-section > div { background: #f8f9fa; padding: 12px 14px; border-radius: 6px; margin-bottom: 10px; border-left: 3px solid var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .notes-section strong { display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 700; }
        .notes-section p { font-size: 11px; color: #1a252f; }

        @page { margin: 0mm 0mm 16mm 0mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } }
    </style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">
    <div class="no-print" style="margin:16px 30px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>

    <div class="band">
        <div class="company-block">
            <?php if (!empty($comp['logo'])): ?><img src="<?= htmlspecialchars('../../../../' . $comp['logo']) ?>" alt="Logo"><?php endif; ?>
            <div>
                <h1><?= htmlspecialchars($comp['name']) ?></h1>
                <div class="sub">
                    <?php
                    $parts = [];
                    if (!empty($comp['address'])) $parts[] = htmlspecialchars($comp['address']);
                    if (!empty($comp['postal_address'])) $parts[] = 'P.O. Box ' . htmlspecialchars($comp['postal_address']);
                    if (!empty($comp['phone'])) $parts[] = 'Tel: ' . htmlspecialchars($comp['phone']);
                    if (!empty($comp['email'])) $parts[] = htmlspecialchars($comp['email']);
                    $tv = [];
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                    if ($tv) $parts[] = implode(' | ', $tv);
                    echo implode(' &bull; ', $parts);
                    ?>
                </div>
            </div>
        </div>
        <div class="doc-title-block">
            <h2>CREDIT NOTE</h2>
            <div class="num"><?= htmlspecialchars($cn['credit_note_number']) ?></div>
        </div>
    </div>

    <div class="body-wrap">

    <div class="meta-strip">
        <div class="cell"><div class="lbl">Date</div><div class="val"><?= date('d M Y', strtotime($cn['credit_date'])) ?></div></div>
        <?php if (!empty($cn['return_number'])): ?><div class="cell"><div class="lbl">Ref Return</div><div class="val"><?= htmlspecialchars($cn['return_number']) ?></div></div><?php endif; ?>
        <?php if (!empty($cn['invoice_number'])): ?><div class="cell"><div class="lbl">Ref Invoice</div><div class="val"><?= htmlspecialchars($cn['invoice_number']) ?></div></div><?php endif; ?>
        <?php if (!empty($cn['warehouse_name'])): ?><div class="cell"><div class="lbl">Warehouse</div><div class="val"><?= htmlspecialchars($cn['warehouse_name']) ?></div></div><?php endif; ?>
        <div class="cell"><div class="lbl">Status</div><div class="val status"><?= strtoupper($cn['status']) ?></div></div>
    </div>

    <div class="panel-row">
        <div class="panel">
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
        <div class="panel">
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

    <div class="bottom-row">
        <?php if ($cn['status'] === 'paid' && (!empty($cn['paid_from_name']) || !empty($cn['paid_at']))): ?>
        <div class="payment-info">
            <h3>Payment Info</h3>
            <?php if (!empty($cn['paid_from_name'])): ?><p><strong>Paid From:</strong> <?= htmlspecialchars($cn['paid_from_name']) ?></p><?php endif; ?>
            <?php if (!empty($cn['paid_at'])): ?><p><strong>Paid On:</strong> <?= date('d M Y', strtotime($cn['paid_at'])) ?></p><?php endif; ?>
            <?php if (!empty($cn['payment_reference'])): ?><p><strong>Reference:</strong> <?= htmlspecialchars($cn['payment_reference']) ?></p><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="flex:1;"></div>
        <?php endif; ?>

        <div class="totals">
            <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($cn['subtotal_amount'], 2) ?></span></div>
            <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($cn['total_tax'], 2) ?></span></div>
            <div class="totals-row grand-total"><span>TOTAL CREDIT:</span><span><?= $currency ?> <?= number_format($cn['grand_total'], 2) ?></span></div>
        </div>
    </div>

    <div class="notes-section">
        <?php if (!empty($cn['notes'])): ?>
        <div><strong>Notes:</strong><p><?= nl2br(htmlspecialchars($cn['notes'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

    </div>
</body>
</html>
