<?php
// File: app/bms/sales/print_sales_order_studio.php
// "Studio" template — elegant black & white minimalist order form, inspired
// by a Salford & Co.-style boutique order form. Same data source and fields
// as print_sales_order.php; presentation only differs.
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
    logActivity($pdo, $_SESSION['user_id'], "Print Sales Order", "$user_name printed Sales Order #{$order['order_number']} (Studio template)");

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

$accent = getSetting('print_template_color_so_studio', '#2b2b2b');
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
            color: #1c1c1c;
            line-height: 1.6;
            padding: 30px 40px 0 40px;
            background: #fff;
        }

        .brand { text-align: center; margin-bottom: 6px; }
        .brand img { max-height: 50px; width: auto; object-fit: contain; margin-bottom: 6px; }
        .brand h1 { font-size: 15px; letter-spacing: 4px; text-transform: uppercase; font-weight: 400; color: var(--accent); }
        .brand .contact { text-align: center; font-size: 9.5px; color: #6b6b6b; margin-top: 4px; letter-spacing: 0.3px; }

        .rule { border: none; border-top: 1.5px solid var(--accent); margin: 14px 0; }
        .rule.thin { border-top: 0.75px solid #c9c9c9; margin: 4px 0 16px 0; }

        .doc-title { text-align: center; font-size: 22px; letter-spacing: 8px; font-weight: 400; margin-bottom: 18px; text-transform: uppercase; }

        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 30px; margin-bottom: 20px; }
        .field-row { display: flex; align-items: baseline; font-size: 11.5px; padding-bottom: 4px; border-bottom: 0.75px solid #c9c9c9; }
        .field-row .flabel { color: #6b6b6b; margin-right: 6px; white-space: nowrap; }
        .field-row .fvalue { flex: 1; font-weight: 600; }

        .section-label { font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: #6b6b6b; margin: 18px 0 8px 0; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th {
            border-bottom: 1.5px solid var(--accent);
            border-top: 1.5px solid var(--accent);
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 8px 8px;
            text-align: left;
            color: #1c1c1c;
        }
        tbody tr td { padding: 7px 8px; font-size: 11.5px; border-bottom: 0.75px solid #e2e2e2; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .totals { width: 280px; margin-left: auto; margin-bottom: 22px; }
        .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 11.5px; border-bottom: 0.75px solid #e2e2e2; }
        .totals-row.grand-total {
            border-top: 1.5px solid var(--accent);
            border-bottom: none;
            margin-top: 6px;
            padding-top: 8px;
            font-size: 13.5px;
            font-weight: 700;
        }

        .notes-section > div { margin-bottom: 12px; font-size: 11px; }
        .notes-section strong { display: block; margin-bottom: 3px; letter-spacing: 0.5px; text-transform: uppercase; font-size: 9.5px; color: #6b6b6b; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:16px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>

    <div class="brand">
        <?php if (!empty($comp['logo'])): ?>
        <img src="<?= htmlspecialchars('../../../' . $comp['logo']) ?>" alt="Logo">
        <?php endif; ?>
        <h1><?= htmlspecialchars($comp['name']) ?></h1>
        <div class="contact">
            <?php
            $parts = [];
            if (!empty($comp['address'])) $parts[] = htmlspecialchars($comp['address']);
            if (!empty($comp['phone'])) $parts[] = 'Tel: ' . htmlspecialchars($comp['phone']);
            if (!empty($comp['email'])) $parts[] = htmlspecialchars($comp['email']);
            if (!empty($comp['tin'])) $parts[] = 'TIN: ' . htmlspecialchars($comp['tin']);
            if (!empty($comp['vrn'])) $parts[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
            echo implode('  &bull;  ', $parts);
            ?>
        </div>
    </div>
    <hr class="rule">

    <div class="doc-title">Sales Order</div>

    <div class="field-grid">
        <div class="field-row"><span class="flabel">NO:</span><span class="fvalue"><?= htmlspecialchars($order['order_number']) ?></span></div>
        <div class="field-row"><span class="flabel">DATE:</span><span class="fvalue"><?= date('d M Y', strtotime($order['order_date'])) ?></span></div>
        <div class="field-row"><span class="flabel">CUSTOMER:</span><span class="fvalue"><?= htmlspecialchars($order['customer_name']) ?></span></div>
        <div class="field-row"><span class="flabel">STATUS:</span><span class="fvalue"><?= strtoupper($order['status']) ?></span></div>
        <?php if (!empty($order['c_phone'])): ?>
        <div class="field-row"><span class="flabel">PHONE:</span><span class="fvalue"><?= htmlspecialchars($order['c_phone']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['c_email'])): ?>
        <div class="field-row"><span class="flabel">EMAIL:</span><span class="fvalue"><?= htmlspecialchars($order['c_email']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['company_name'])): ?>
        <div class="field-row"><span class="flabel">COMPANY:</span><span class="fvalue"><?= htmlspecialchars($order['company_name']) ?></span></div>
        <?php endif; ?>
        <?php
        $c_addr = trim(($order['c_postal_address'] ? 'P.O. Box ' . $order['c_postal_address'] . ', ' : '') . ($order['c_address'] ?? ''), ', ');
        if ($c_addr): ?>
        <div class="field-row"><span class="flabel">ADDRESS:</span><span class="fvalue"><?= htmlspecialchars($c_addr) ?></span></div>
        <?php endif; ?>
        <?php
        $c_tv = [];
        if (!empty($order['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($order['c_tin']);
        if (!empty($order['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($order['c_vrn']);
        if ($c_tv): ?>
        <div class="field-row"><span class="flabel">TAX:</span><span class="fvalue"><?= implode(' | ', $c_tv) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['project_contract_no'])): ?>
        <div class="field-row"><span class="flabel">CONTRACT NO:</span><span class="fvalue"><?= htmlspecialchars($order['project_contract_no']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['project_name'])): ?>
        <div class="field-row"><span class="flabel">PROJECT:</span><span class="fvalue"><?= htmlspecialchars($order['project_name']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['warehouse_name'])): ?>
        <div class="field-row"><span class="flabel">WAREHOUSE:</span><span class="fvalue"><?= htmlspecialchars($order['warehouse_name']) ?></span></div>
        <?php endif; ?>
        <div class="field-row"><span class="flabel">SALESPERSON:</span><span class="fvalue"><?= htmlspecialchars($order['salesperson_name'] ?? 'N/A') ?></span></div>
        <div class="field-row"><span class="flabel">PREPARED BY:</span><span class="fvalue"><?= htmlspecialchars(trim(($order['creator_first'] ?? '') . ' ' . ($order['creator_last'] ?? '')) ?: 'System') ?></span></div>
    </div>

    <div class="section-label">Items</div>
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:34px;">No.</th>
                <th class="text-center" style="width:90px;">Code</th>
                <th class="text-center">Description</th>
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
        <div class="totals-row"><span>Subtotal</span><span><?= $currency ?> <?= number_format($order['subtotal'], 2) ?></span></div>
        <?php if (floatval($order['discount_amount']) > 0): ?>
        <div class="totals-row"><span>Discount</span><span>-<?= $currency ?> <?= number_format($order['discount_amount'], 2) ?></span></div>
        <?php endif; ?>
        <div class="totals-row"><span>VAT (18%)</span><span><?= $currency ?> <?= number_format($order['tax_amount'], 2) ?></span></div>
        <?php if (floatval($order['shipping_cost']) > 0): ?>
        <div class="totals-row"><span>Shipping</span><span><?= $currency ?> <?= number_format($order['shipping_cost'], 2) ?></span></div>
        <?php endif; ?>
        <div class="totals-row grand-total"><span>Grand Total</span><span><?= $currency ?> <?= number_format($order['grand_total'], 2) ?></span></div>
    </div>

    <div class="notes-section">
        <?php if (!empty($order['notes'])): ?>
        <div><strong>Notes</strong><p><?= nl2br(htmlspecialchars($order['notes'])) ?></p></div>
        <?php endif; ?>
        <?php if (!empty($order['terms_conditions'])): ?>
        <div><strong>Terms &amp; Conditions</strong><p><?= nl2br(htmlspecialchars($order['terms_conditions'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>
    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
