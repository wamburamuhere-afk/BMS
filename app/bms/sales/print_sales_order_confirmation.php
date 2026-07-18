<?php
// File: app/bms/sales/print_sales_order_confirmation.php
// "Confirmation" template — letter-style Sales Order confirmation, inspired by
// a Hanover & Tyke-style formal order-confirmation letter. Same data source
// and fields as print_sales_order.php; presentation only differs.
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('sales_orders')) die("Access Denied");

$sales_order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($sales_order_id <= 0) die("Invalid Order ID");

assertScopeForRecordHtml('sales_orders', 'sales_order_id', $sales_order_id);

global $pdo;

try {
    $stmt = $pdo->prepare("
        SELECT
            so.*,
            c.customer_name,
            c.company_name,
            c.email as c_email,
            c.phone as c_phone,
            c.address as c_address,
            c.postal_address as c_postal_address,
            c.tax_id as c_tin,
            c.vat_number as c_vrn,
            u.username as salesperson_name,
            u_creator.first_name as creator_first,
            u_creator.last_name  as creator_last,
            u_creator.username   as creator_username,
            COALESCE(u_creator.user_role, u_creator.role) as creator_role,
            pr.project_name,
            pr.contract_number as project_contract_no,
            w.warehouse_name,
            so.reviewed_by_name, so.reviewed_by_role, so.reviewed_at,
            so.approved_by_name, so.approved_by_role, so.approved_at
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        LEFT JOIN users u ON so.salesperson_id = u.user_id
        LEFT JOIN users u_creator ON so.created_by = u_creator.user_id
        LEFT JOIN projects pr ON so.project_id = pr.project_id
        LEFT JOIN warehouses w ON so.warehouse_id = w.warehouse_id
        WHERE so.sales_order_id = ? AND (so.is_quote = 0 OR so.is_quote IS NULL)
    ");
    $stmt->execute([$sales_order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) die("Order not found");

    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], "Print Sales Order", "$user_name printed Sales Order #{$order['order_number']} (Confirmation template)");

    $stmtItems = $pdo->prepare("
        SELECT soi.*, p.product_name, p.sku, p.unit
        FROM sales_order_items soi
        LEFT JOIN products p ON soi.product_id = p.product_id
        WHERE soi.order_id = ?
    ");
    $stmtItems->execute([$sales_order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$currency = $order['currency'] ?? 'TZS';

$wf_status = $order['status'] ?? 'pending';
$creator_name = trim(($order['creator_first'] ?? '') . ' ' . ($order['creator_last'] ?? ''));
if ($creator_name === '') $creator_name = trim($order['creator_username'] ?? '');
if ($creator_name === '') $creator_name = $order['salesperson_name'] ?? '';
$creator_role = trim($order['creator_role'] ?? '');
$wf_sigs = $sales_order_id ? getWorkflowSignatures($pdo, 'sales_order', $sales_order_id) : [];
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => $creator_role,
    'reviewed_by_name'   => $order['reviewed_by_name'] ?? '',
    'reviewed_by_role'   => $order['reviewed_by_role'] ?? '',
    'approved_by_name'   => $order['approved_by_name'] ?? '',
    'approved_by_role'   => $order['approved_by_role'] ?? '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']  ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']  ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at'] ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']  ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at'] ?? null,
    '__include_css'      => true,
];

$order['subtotal']        = $order['subtotal']        ?? $order['total_amount'] ?? 0;
$order['tax_amount']      = $order['tax_amount']      ?? 0;
$order['discount_amount'] = $order['discount_amount'] ?? 0;
$order['shipping_cost']   = $order['shipping_cost']   ?? 0;
$order['grand_total']     = $order['grand_total']     ?? 0;

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

$accent = getSetting('print_template_color_so_confirmation', '#c8981f');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SALES ORDER #<?= htmlspecialchars($order['order_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 12px;
            color: #26221c;
            line-height: 1.6;
            padding: 26px 34px 0 34px;
            background: #fff;
        }

        /* ── LETTERHEAD BLOCK ── */
        .letterhead {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            padding: 22px 26px;
            border-radius: 0 26px 26px 0;
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 22px;
        }
        .letterhead img { max-height: 54px; width: auto; object-fit: contain; background: #fff; padding: 3px; border-radius: 4px; }
        .letterhead h1 { font-size: 21px; font-weight: 700; color: #fff; margin-bottom: 4px; }
        .letterhead .contact { font-size: 10.5px; color: #fff; opacity: 0.95; }
        .letterhead .contact span { margin-right: 12px; }

        .dateline { text-align: right; font-size: 11.5px; color: #26221c; margin-bottom: 20px; }

        .to-block { margin-bottom: 18px; font-size: 11.5px; }
        .to-block p { margin: 1px 0; }

        .subject-line { font-weight: 700; font-size: 12.5px; margin: 16px 0 12px 0; }

        .body-para { font-size: 11.8px; margin-bottom: 12px; text-align: justify; }

        .order-facts { font-size: 11.5px; margin: 14px 0 18px 22px; }
        .order-facts div { margin: 3px 0; }
        .order-facts strong { display: inline-block; min-width: 130px; color: #26221c; }

        table { width: 100%; border-collapse: collapse; margin: 16px 0 18px 0; }
        th {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 8px 10px;
            text-align: left;
        }
        tbody tr { border-bottom: 1px solid #e4ddc9; }
        tbody tr td { padding: 6px 10px; font-size: 11.5px; vertical-align: middle; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .totals { width: 300px; margin-left: auto; margin-bottom: 20px; }
        .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 11.5px; border-bottom: 1px solid #e4ddc9; }
        .totals-row.grand-total {
            border-top: 2px solid var(--accent);
            border-bottom: none;
            margin-top: 6px;
            padding-top: 8px;
            font-size: 13.5px;
            font-weight: 700;
        }

        .closing { margin-top: 20px; font-size: 11.8px; }
        .closing p { margin-bottom: 26px; }

        .notes-section { margin-top: 16px; font-size: 11px; }
        .notes-section strong { display: block; margin-bottom: 3px; }
        .notes-section > div { margin-bottom: 10px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
        }
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
    <div class="letterhead">
        <?php if (!empty($comp['logo'])): ?>
        <img src="<?= htmlspecialchars('../../../' . $comp['logo']) ?>" alt="Logo">
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="contact">
                <?php if (!empty($comp['address'])): ?><span><?= htmlspecialchars($comp['address']) ?></span><?php endif; ?>
                <?php if (!empty($comp['phone'])): ?><span>Tel: <?= htmlspecialchars($comp['phone']) ?></span><?php endif; ?>
                <?php if (!empty($comp['email'])): ?><span>Email: <?= htmlspecialchars($comp['email']) ?></span><?php endif; ?>
            </div>
            <?php
            $tv = [];
            if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
            if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
            if ($tv): ?>
            <div class="contact"><?= implode(' | ', $tv) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dateline"><?= date('d F Y', strtotime($order['order_date'])) ?></div>

    <div class="to-block">
        <p><strong>To:</strong></p>
        <p><?= htmlspecialchars($order['customer_name']) ?></p>
        <?php if (!empty($order['company_name'])): ?><p><?= htmlspecialchars($order['company_name']) ?></p><?php endif; ?>
        <?php if (!empty($order['c_postal_address'])): ?><p>P.O. Box <?= htmlspecialchars($order['c_postal_address']) ?></p><?php endif; ?>
        <?php if (!empty($order['c_address'])): ?><p><?= htmlspecialchars($order['c_address']) ?></p><?php endif; ?>
        <?php if (!empty($order['c_phone'])): ?><p><?= htmlspecialchars($order['c_phone']) ?></p><?php endif; ?>
        <?php if (!empty($order['c_email'])): ?><p><?= htmlspecialchars($order['c_email']) ?></p><?php endif; ?>
        <?php
        $c_tv = [];
        if (!empty($order['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($order['c_tin']);
        if (!empty($order['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($order['c_vrn']);
        if ($c_tv): ?>
        <p><?= implode(' | ', $c_tv) ?></p>
        <?php endif; ?>
    </div>

    <div class="subject-line">Subject: Confirmation of Sales Order No. <?= htmlspecialchars($order['order_number']) ?></div>

    <div class="body-para">
        Dear <?= htmlspecialchars($order['customer_name']) ?>,<br><br>
        We are pleased to confirm the details of your Sales Order below. Please review the items and terms, and contact us should you have any questions.
    </div>

    <div class="order-facts">
        <div><strong>Order Number:</strong> <?= htmlspecialchars($order['order_number']) ?></div>
        <div><strong>Order Date:</strong> <?= date('d M Y', strtotime($order['order_date'])) ?></div>
        <div><strong>Status:</strong> <?= strtoupper($order['status']) ?></div>
        <?php if (!empty($order['project_contract_no'])): ?><div><strong>Contract No:</strong> <?= htmlspecialchars($order['project_contract_no']) ?></div><?php endif; ?>
        <?php if (!empty($order['project_name'])): ?><div><strong>Project:</strong> <?= htmlspecialchars($order['project_name']) ?></div><?php endif; ?>
        <?php if (!empty($order['warehouse_name'])): ?><div><strong>Warehouse:</strong> <?= htmlspecialchars($order['warehouse_name']) ?></div><?php endif; ?>
        <div><strong>Salesperson:</strong> <?= htmlspecialchars($order['salesperson_name'] ?? 'N/A') ?></div>
        <div><strong>Prepared By:</strong> <?= htmlspecialchars(trim(($order['creator_first'] ?? '') . ' ' . ($order['creator_last'] ?? '')) ?: 'System') ?></div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:34px;">S/NO</th>
                <th class="text-center" style="width:90px;">Product Code</th>
                <th class="text-center">Item / Description</th>
                <th class="text-right" style="width:70px;">Qty</th>
                <th class="text-right" style="width:95px;">Unit Price</th>
                <th class="text-right" style="width:105px;">Total (<?= $currency ?>)</th>
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

    <div class="totals">
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($order['subtotal'], 2) ?></span></div>
        <?php if (floatval($order['discount_amount']) > 0): ?>
        <div class="totals-row"><span>Discount:</span><span>-<?= $currency ?> <?= number_format($order['discount_amount'], 2) ?></span></div>
        <?php endif; ?>
        <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($order['tax_amount'], 2) ?></span></div>
        <?php if (floatval($order['shipping_cost']) > 0): ?>
        <div class="totals-row"><span>Shipping:</span><span><?= $currency ?> <?= number_format($order['shipping_cost'], 2) ?></span></div>
        <?php endif; ?>
        <div class="totals-row grand-total"><span>GRAND TOTAL:</span><span><?= $currency ?> <?= number_format($order['grand_total'], 2) ?></span></div>
    </div>

    <div class="notes-section">
        <?php if (!empty($order['notes'])): ?>
        <div><strong>Notes:</strong><p><?= nl2br(htmlspecialchars($order['notes'])) ?></p></div>
        <?php endif; ?>
        <?php if (!empty($order['terms_conditions'])): ?>
        <div><strong>Terms &amp; Conditions:</strong><p><?= nl2br(htmlspecialchars($order['terms_conditions'])) ?></p></div>
        <?php endif; ?>
    </div>

    <div class="closing">
        <p>We appreciate your business and look forward to fulfilling this order promptly.</p>
        <p>Yours sincerely,</p>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>
    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
