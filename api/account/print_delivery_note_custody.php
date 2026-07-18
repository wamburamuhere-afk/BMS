<?php
// File: api/account/print_delivery_note_custody.php
// "Custody" template — Sender/Receipt information panels with a
// Delivered-By/Received-By acknowledgment block, inspired by a green-and-pink
// delivery-form design. Same data source and fields as print_delivery_note.php
// (the reference design's Fragile/Handle-with-care checkbox row has no
// backing data in BMS, so it is intentionally omitted); presentation only
// differs otherwise.
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) die("Invalid Delivery Note ID");
assertScopeForRecordHtml('deliveries', 'delivery_id', $id);

global $pdo;

$stmt = $pdo->prepare("
    SELECT d.*,
           COALESCE(s.supplier_name, sc.supplier_name)       as supplier_name,
           COALESCE(s.company_name, sc.company_name)         as supplier_company,
           COALESCE(s.phone, sc.phone)                       as s_phone,
           COALESCE(s.email, sc.email)                       as s_email,
           COALESCE(s.address, sc.address)                   as s_address,
           COALESCE(s.tax_id, sc.tax_id)                     as s_tin,
           COALESCE(s.vat_number, sc.vat_number)             as s_vrn,
           COALESCE(s.postal_address, sc.postal_address)     as s_postal_address,
           c.customer_name, c.company_name as customer_company,
           c.phone as c_phone, c.email as c_email, c.address as c_address,
           c.postal_address as c_postal_address,
           c.tax_id as c_tin, c.vat_number as c_vrn,
           w.warehouse_name, w.location as warehouse_location,
           p.project_name, p.contract_number as project_contract_no,
           u.username as created_by_username,
           u.first_name AS creator_first,
           u.last_name  AS creator_last,
           COALESCE(u.user_role, u.role) AS creator_role,
           d.prepared_by_name, d.prepared_by_role, d.prepared_at,
           d.reviewed_by_name, d.reviewed_by_role, d.reviewed_at,
           d.approved_by_name, d.approved_by_role, d.approved_at
    FROM deliveries d
    LEFT JOIN suppliers s        ON d.supplier_id      = s.supplier_id
    LEFT JOIN sub_contractors sc ON d.subcontractor_id = sc.supplier_id
    LEFT JOIN customers c        ON d.customer_id      = c.customer_id
    LEFT JOIN warehouses w       ON d.warehouse_id     = w.warehouse_id
    LEFT JOIN projects p         ON d.project_id       = p.project_id
    LEFT JOIN users u            ON d.created_by       = u.user_id
    WHERE d.delivery_id = ?
");
$stmt->execute([$id]);
$dn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dn) die("Delivery Note not found");

$is_inbound     = ($dn['dn_type'] ?? 'inbound') !== 'outbound';
$is_to_customer = (!$is_inbound && ($dn['party_type'] ?? '') === 'customer');
$party_label    = (($dn['party_type'] ?? 'supplier') === 'subcontractor') ? 'Sub-Contractor' : 'Supplier';
$dn_no          = $is_inbound ? ($dn['dn_number'] ?: $dn['delivery_number']) : $dn['delivery_number'];

$stmtItems = $pdo->prepare("
    SELECT di.*, p.product_name, p.sku, p.unit
    FROM delivery_items di
    LEFT JOIN products p ON di.product_id = p.product_id
    WHERE di.delivery_id = ? ORDER BY di.delivery_item_id
");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$inv_stmt = $pdo->prepare("SELECT invoice_number, status FROM invoices WHERE delivery_id = ? AND status != 'cancelled' ORDER BY invoice_date DESC LIMIT 1");
$inv_stmt->execute([$id]);
$linked_invoice = $inv_stmt->fetch(PDO::FETCH_ASSOC);

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

$wf_status = $dn['status'] ?? 'pending';
$wf_sigs = getWorkflowSignatures($pdo, 'delivery', $id);
$dn_creator_name = trim(($dn['creator_first'] ?? '') . ' ' . ($dn['creator_last'] ?? ''))
                   ?: ($dn['created_by_username'] ?? '')
                   ?: ($dn['prepared_by_name']    ?? '');
$dn_creator_role = $dn['creator_role'] ?: ($dn['prepared_by_role'] ?? 'Authorized Staff');

$wf = [
    'created_by_name'    => $dn_creator_name,
    'created_by_role'    => $dn_creator_role,
    'reviewed_by_name'   => $dn['reviewed_by_name']  ?? '',
    'reviewed_by_role'   => $dn['reviewed_by_role']  ?? '',
    'approved_by_name'   => $dn['approved_by_name']  ?? '',
    'approved_by_role'   => $dn['approved_by_role']  ?? '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']  ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']  ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at'] ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']  ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at'] ?? null,
    '__include_css'      => true,
];

$accent = getSetting('print_template_color_dn_custody', '#6b7c5e');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Note #<?= htmlspecialchars($dn['delivery_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #2c2c2c;
            line-height: 1.5;
            padding: 0 26px 0 26px;
            background: #fff;
        }

        .top-strip { display: flex; justify-content: space-between; font-size: 9.5px; color: #6b6b6b; padding: 14px 0 10px 0; }

        .title-bar {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 3px;
            padding: 12px 0;
            margin: 0 -26px 16px -26px;
        }

        .company-block { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .company-block img { max-height: 40px; width: auto; object-fit: contain; }
        .company-block h1 { font-size: 14px; font-weight: 700; }

        .panel-row { display: flex; gap: 14px; margin-bottom: 14px; }
        .panel {
            flex: 1;
            background: #f2ede9;
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            border-radius: 6px;
            padding: 12px 14px;
        }
        .panel h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 8px; color: #4a4a3f; }
        .panel .field-line { font-size: 11px; margin: 3px 0; display: flex; }
        .panel .field-line .flabel { min-width: 60px; color: #6b6b5f; }

        .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #4a4a3f; margin: 16px 0 8px 0; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.items-table thead th { border-bottom: 2px solid var(--accent); padding: 6px 4px; font-size: 10.5px; text-transform: uppercase; text-align: left; color: #4a4a3f; }
        table.items-table tbody td { padding: 6px 4px; font-size: 11.5px; border-bottom: 1px solid #e5e0da; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        tfoot td { font-weight: 700; padding: 8px 4px; border-top: 2px solid var(--accent); }

        .total-qty-line { font-size: 11px; margin: 8px 0 16px 0; }
        .total-qty-line strong { color: #4a4a3f; }

        .ack-panel {
            background: #f2ede9;
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            border-radius: 6px;
            padding: 14px 16px;
            margin: 16px 0;
        }
        .ack-panel .section-title { margin-top: 0; }
        .ack-row { display: flex; justify-content: space-between; gap: 30px; }
        .ack-col { flex: 1; }
        .ack-col .ack-label { font-size: 11px; font-weight: 700; margin-bottom: 24px; }

        .notes-section { margin: 16px 0; font-size: 11px; }
        .notes-section strong { display: block; margin-bottom: 4px; color: #4a4a3f; }

        .bottom-bar { height: 10px; margin: 20px -26px 0 -26px; background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; border-radius: 6px 6px 0 0; }

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

    <div class="no-print" style="margin:16px 0; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>


    <div class="print-scale-wrapper">
    <div class="top-strip">
        <span><?= htmlspecialchars($comp['name']) ?></span>
        <span>
            <?php if (!empty($comp['website'])): ?><?= htmlspecialchars($comp['website']) ?><?php elseif (!empty($comp['email'])): ?><?= htmlspecialchars($comp['email']) ?><?php endif; ?>
        </span>
    </div>

    <div class="title-bar">DELIVERY FORM</div>

    <div class="company-block">
        <?php if (!empty($comp['logo'])): ?>
        <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div style="font-size:9.5px; color:#6b6b6b;">
                <?php
                $parts = [];
                if (!empty($comp['address'])) $parts[] = htmlspecialchars($comp['address']);
                if (!empty($comp['phone'])) $parts[] = 'Tel: ' . htmlspecialchars($comp['phone']);
                $tv = [];
                if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                if ($tv) $parts[] = implode(' | ', $tv);
                echo implode(' &bull; ', $parts);
                ?>
            </div>
        </div>
    </div>

    <div class="panel-row">
        <div class="panel">
            <h3>Sender Information</h3>
            <div class="field-line"><span class="flabel">Name:</span> <?= htmlspecialchars($comp['name']) ?></div>
            <div class="field-line"><span class="flabel">Address:</span> <?= htmlspecialchars($comp['address'] ?: '') ?></div>
            <div class="field-line"><span class="flabel">Phone:</span> <?= htmlspecialchars($comp['phone'] ?: '') ?></div>
            <?php if (!empty($comp['email'])): ?><div class="field-line"><span class="flabel">E-mail:</span> <?= htmlspecialchars($comp['email']) ?></div><?php endif; ?>
        </div>
        <div class="panel">
            <h3><?= $is_to_customer ? 'Receipt Information' : (($is_inbound ? 'Received From' : 'Sent To') . ' (' . $party_label . ')') ?></h3>
            <?php if ($is_to_customer): ?>
            <div class="field-line"><span class="flabel">Name:</span> <?= htmlspecialchars($dn['customer_name'] ?: 'N/A') ?></div>
            <div class="field-line"><span class="flabel">Address:</span> <?= htmlspecialchars($dn['c_address'] ?: '') ?></div>
            <div class="field-line"><span class="flabel">Phone:</span> <?= htmlspecialchars($dn['c_phone'] ?: '') ?></div>
            <?php if (!empty($dn['c_email'])): ?><div class="field-line"><span class="flabel">E-mail:</span> <?= htmlspecialchars($dn['c_email']) ?></div><?php endif; ?>
            <?php else: ?>
            <div class="field-line"><span class="flabel">Name:</span> <?= htmlspecialchars($dn['supplier_name'] ?: 'Local Inventory') ?></div>
            <div class="field-line"><span class="flabel">Address:</span> <?= htmlspecialchars($dn['s_address'] ?: '') ?></div>
            <div class="field-line"><span class="flabel">Phone:</span> <?= htmlspecialchars($dn['s_phone'] ?: '') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <h3>Delivery Details</h3>
        <div class="field-line"><span class="flabel">DN #:</span> <?= htmlspecialchars($dn_no) ?></div>
        <div class="field-line"><span class="flabel">Type:</span> <?= $is_inbound ? 'Inbound (Received)' : 'Outbound (Sent)' ?></div>
        <div class="field-line"><span class="flabel">Date:</span> <?= date('d M Y', strtotime($dn['delivery_date'])) ?></div>
        <div class="field-line"><span class="flabel">Status:</span> <?= strtoupper($dn['status']) ?></div>
        <div class="field-line"><span class="flabel">Warehouse:</span> <?= htmlspecialchars($dn['warehouse_name'] ?: 'N/A') ?></div>
        <?php if (!empty($dn['project_name'])): ?><div class="field-line"><span class="flabel">Project:</span> <?= htmlspecialchars($dn['project_name']) ?></div><?php endif; ?>
        <?php if (!empty($dn['project_contract_no'])): ?><div class="field-line"><span class="flabel">Contract:</span> <?= htmlspecialchars($dn['project_contract_no']) ?></div><?php endif; ?>
        <?php if ($linked_invoice): ?><div class="field-line"><span class="flabel">Invoice:</span> <?= htmlspecialchars($linked_invoice['invoice_number']) ?> &mdash; <?= strtoupper(htmlspecialchars($linked_invoice['status'])) ?></div><?php endif; ?>
    </div>

    <div class="section-title">Items</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:34px;">No.</th>
                <th style="width:100px;">SKU</th>
                <th>Item Description</th>
                <th class="text-right" style="width:90px;"><?= $is_inbound ? 'Qty Received' : 'Qty Sent' ?></th>
                <th class="text-center" style="width:70px;">Unit</th>
            </tr>
        </thead>
        <tbody>
            <?php $totalQty = 0; foreach ($items as $i => $item):
                $totalQty += floatval($item['quantity_delivered']);
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= !empty($item['sku']) ? htmlspecialchars($item['sku']) : '—' ?></td>
                <td><?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?></td>
                <td class="text-right fw-bold"><?= number_format($item['quantity_delivered'], 2) ?></td>
                <td class="text-center"><?= htmlspecialchars($item['unit'] ?: 'pcs') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="total-qty-line"><strong>Total Qty:</strong> <?= number_format($totalQty, 2) ?></div>

    <div class="ack-panel">
        <div class="section-title">Acknowledgment</div>
        <div class="ack-row">
            <div class="ack-col">
                <div class="ack-label">Delivered By: <?= htmlspecialchars($dn['prepared_by_name'] ?: ($dn_creator_name ?: 'Staff')) ?></div>
            </div>
            <div class="ack-col">
                <div class="ack-label">Received By:</div>
            </div>
        </div>
    </div>

    <?php if (!empty($dn['notes'])): ?>
    <div class="notes-section">
        <strong>Notes:</strong>
        <p><?= nl2br(htmlspecialchars($dn['notes'])) ?></p>
    </div>
    <?php endif; ?>

    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>
    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>

    <div class="bottom-bar"></div>
    </div>


    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
