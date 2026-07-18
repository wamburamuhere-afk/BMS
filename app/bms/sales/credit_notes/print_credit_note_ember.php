<?php
// File: app/bms/sales/credit_notes/print_credit_note_ember.php
// "Ember" template — bold modern grid with a prominent TOTAL CREDIT figure up
// top (mirrors Invoice's Summit energy). Same data as print_credit_note.php;
// presentation only differs. Own accent color, separate from Ledger and Horizon.
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
        ($_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User') . " printed Credit Note #{$cn['credit_note_number']} (Ember template)");

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

$accent = getSetting('print_template_color_cn_ember', '#B3402C');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CREDIT NOTE #<?= htmlspecialchars($cn['credit_note_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #17222b; line-height: 1.5; padding: 24px 26px 0 26px; background: #fff; }

        .brand-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .brand-row .company-block { display: flex; align-items: center; gap: 12px; }
        .brand-row img { max-height: 46px; width: auto; object-fit: contain; }
        .brand-row h1 { font-size: 15px; font-weight: 700; }
        .brand-row .sub { font-size: 9.5px; color: #5c6b73; margin-top: 3px; }

        .doc-title { text-align: center; font-size: 30px; font-weight: 800; letter-spacing: 3px; margin: 10px 0 14px 0; color: #17222b; }

        .meta-bar { display: flex; border-top: 2px solid var(--accent); border-bottom: 2px solid var(--accent); padding: 10px 4px; margin-bottom: 20px; }
        .meta-bar .cell { flex: 1; }
        .meta-bar .cell:last-child { text-align: right; }
        .meta-bar .lbl { font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.6px; color: #5c6b73; font-weight: 700; }
        .meta-bar .val { font-size: 12px; font-weight: 700; margin-top: 2px; }
        .meta-bar .val.total-credit { font-size: 16px; color: var(--accent); }

        .panel-row { display: flex; gap: 14px; margin-bottom: 18px; }
        .panel { flex: 1; }
        .panel h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 6px; }
        .panel p { font-size: 11px; margin: 3px 0; }
        .panel strong { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th { background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; color: #fff; font-weight: 700; font-size: 10.5px; text-transform: uppercase; padding: 8px 10px; text-align: left; }
        tbody tr td { padding: 8px 10px; font-size: 11.5px; border: 1px solid #d8dee1; }
        .text-right { text-align: right; } .text-center { text-align: center; } .fw-bold { font-weight: 700; }

        .bottom-row { display: flex; justify-content: space-between; gap: 16px; margin: 16px 0; align-items: flex-start; }
        .payment-info { flex: 1; }
        .payment-info h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 6px; }
        .payment-info p { font-size: 10.5px; margin: 2px 0; }

        .totals { width: 300px; }
        .totals-row { display: flex; justify-content: space-between; padding: 5px 10px; font-size: 11.5px; }
        .totals-row.grand-total {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            margin-bottom: 3px;
        }
        .totals-row.plain { background: transparent; color: #17222b; }
        .totals-row.grand-total { font-weight: 800; font-size: 13px; }

        .terms-block { margin: 16px 0; font-size: 11px; }
        .terms-block strong { display: block; margin-bottom: 4px; font-size: 11.5px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } }
    </style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">
    <div class="no-print" style="margin-bottom:16px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>


    <div class="print-scale-wrapper">
    <div class="brand-row">
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
    </div>

    <div class="doc-title">CREDIT NOTE</div>

    <div class="meta-bar">
        <div class="cell">
            <div class="lbl">Credit To</div>
            <div class="val"><?= htmlspecialchars($cn['customer_name']) ?></div>
        </div>
        <div class="cell">
            <div class="lbl">Date</div>
            <div class="val"><?= date('d/m/Y', strtotime($cn['credit_date'])) ?></div>
            <div class="lbl" style="margin-top:4px;">Credit Note No</div>
            <div class="val"><?= htmlspecialchars($cn['credit_note_number']) ?></div>
        </div>
        <div class="cell">
            <div class="lbl">Total Credit</div>
            <div class="val total-credit"><?= $currency ?> <?= number_format($cn['grand_total'], 2) ?></div>
            <div class="lbl" style="margin-top:4px;">Status</div>
            <div class="val"><?= strtoupper($cn['status']) ?></div>
        </div>
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
            <?php if (!empty($cn['return_number'])): ?><p><strong>Ref Return:</strong> <?= htmlspecialchars($cn['return_number']) ?></p><?php endif; ?>
            <?php if (!empty($cn['invoice_number'])): ?><p><strong>Ref Invoice:</strong> <?= htmlspecialchars($cn['invoice_number']) ?></p><?php endif; ?>
            <?php if (!empty($cn['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($cn['warehouse_name']) ?></p><?php endif; ?>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($creator_name ?: 'System') ?></p>
            <p><strong>Currency:</strong> <?= htmlspecialchars($currency) ?></p>
            <?php if (!empty($cn['reason'])): ?><p><strong>Reason:</strong> <?= htmlspecialchars($cn['reason']) ?></p><?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:34px;">S/NO</th>
                <th class="text-center" style="width:90px;">Product Code</th>
                <th>Item / Description</th>
                <th class="text-right" style="width:60px;">Qty</th>
                <th class="text-right" style="width:90px;">Unit Price</th>
                <th class="text-center" style="width:50px;">VAT</th>
                <th class="text-right" style="width:105px;">Total (<?= $currency ?>)</th>
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
            <?php if (!empty($cn['paid_from_name'])): ?><p>Paid From: <?= htmlspecialchars($cn['paid_from_name']) ?></p><?php endif; ?>
            <?php if (!empty($cn['paid_at'])): ?><p>Paid On: <?= date('d M Y', strtotime($cn['paid_at'])) ?></p><?php endif; ?>
            <?php if (!empty($cn['payment_reference'])): ?><p>Reference: <?= htmlspecialchars($cn['payment_reference']) ?></p><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="flex:1;"></div>
        <?php endif; ?>

        <div class="totals">
            <div class="totals-row plain"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($cn['subtotal_amount'], 2) ?></span></div>
            <div class="totals-row plain"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($cn['total_tax'], 2) ?></span></div>
            <div class="totals-row grand-total"><span>TOTAL CREDIT:</span><span><?= $currency ?> <?= number_format($cn['grand_total'], 2) ?></span></div>
        </div>
    </div>

    <div class="terms-block">
        <?php if (!empty($cn['notes'])): ?>
        <strong>Notes:</strong>
        <p><?= nl2br(htmlspecialchars($cn['notes'])) ?></p>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
