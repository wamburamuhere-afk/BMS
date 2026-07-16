<?php
// File: api/account/print_delivery_note_transit.php
// "Transit" template — clean blue-branded formal delivery form, inspired
// by a blue-logo-mark delivery form design. Same data source and fields
// as print_delivery_note.php; presentation only differs.
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

$accent = getSetting('print_template_color_dn_transit', '#1b5fa8');
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
            color: #1a252f;
            line-height: 1.5;
            padding: 24px 26px 0 26px;
            background: #fff;
        }

        .header-row { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .header-row img { max-height: 40px; width: auto; object-fit: contain; }
        .header-row h1 { font-size: 20px; font-weight: 800; color: var(--accent); letter-spacing: 0.5px; }
        .header-row .doc-suffix { font-size: 20px; font-weight: 400; color: #1a252f; margin-left: 6px; }

        .contact-strip { font-size: 10px; color: #5c6b73; margin-bottom: 18px; }
        .contact-strip span { margin-right: 12px; }

        .box { border: 1px solid var(--accent); border-radius: 6px; padding: 12px 14px; margin-bottom: 14px; }
        .box-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); border-bottom: 1px solid #cfd8e0; padding-bottom: 5px; margin-bottom: 8px; }

        .two-col { display: flex; gap: 14px; }
        .two-col .box { flex: 1; }

        .field-line { font-size: 11px; margin: 3px 0; }
        .field-line strong { display: inline-block; min-width: 90px; }

        table { width: 100%; border-collapse: collapse; margin: 14px 0 8px 0; }
        table.items-table thead th {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            padding: 8px 8px;
            font-size: 10.5px;
            text-transform: uppercase;
            text-align: left;
        }
        table.items-table tbody td { padding: 7px 8px; font-size: 11.5px; border-bottom: 1px solid #e4e8ec; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        tfoot td { font-weight: 700; padding: 8px 8px; border-top: 2px solid var(--accent); background: #f4f8fc; }

        .notes-section { margin: 18px 0; font-size: 11px; }
        .notes-section strong { display: block; margin-bottom: 4px; color: var(--accent); }

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

    <div class="header-row">
        <?php if (!empty($comp['logo'])): ?>
        <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
        <?php endif; ?>
        <h1><?= htmlspecialchars($comp['name']) ?> <span class="doc-suffix">Delivery Form</span></h1>
    </div>
    <div class="contact-strip">
        <?php if (!empty($comp['address'])): ?><span><?= htmlspecialchars($comp['address']) ?></span><?php endif; ?>
        <?php if (!empty($comp['phone'])): ?><span>Tel: <?= htmlspecialchars($comp['phone']) ?></span><?php endif; ?>
        <?php if (!empty($comp['email'])): ?><span>Email: <?= htmlspecialchars($comp['email']) ?></span><?php endif; ?>
        <?php
        $tv = [];
        if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
        if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
        if ($tv): ?>
        <span><?= implode(' | ', $tv) ?></span>
        <?php endif; ?>
    </div>

    <div class="two-col">
        <div class="box">
            <div class="box-title"><?= $is_to_customer ? 'Customer Information' : (($is_inbound ? 'Received From' : 'Sent To') . ' (' . $party_label . ')') ?></div>
            <?php if ($is_to_customer): ?>
            <div class="field-line"><strong>Name:</strong> <?= htmlspecialchars($dn['customer_name'] ?: 'N/A') ?></div>
            <?php if (!empty($dn['customer_company'])): ?><div class="field-line"><strong>Company:</strong> <?= htmlspecialchars($dn['customer_company']) ?></div><?php endif; ?>
            <div class="field-line"><strong>Address:</strong> <?= htmlspecialchars($dn['c_address'] ?: '') ?></div>
            <div class="field-line"><strong>Phone:</strong> <?= htmlspecialchars($dn['c_phone'] ?: '') ?></div>
            <?php if (!empty($dn['c_email'])): ?><div class="field-line"><strong>Email:</strong> <?= htmlspecialchars($dn['c_email']) ?></div><?php endif; ?>
            <?php else: ?>
            <div class="field-line"><strong>Name:</strong> <?= htmlspecialchars($dn['supplier_name'] ?: 'Local Inventory') ?></div>
            <?php if (!empty($dn['supplier_company'])): ?><div class="field-line"><strong>Company:</strong> <?= htmlspecialchars($dn['supplier_company']) ?></div><?php endif; ?>
            <div class="field-line"><strong>Address:</strong> <?= htmlspecialchars($dn['s_address'] ?: '') ?></div>
            <div class="field-line"><strong>Phone:</strong> <?= htmlspecialchars($dn['s_phone'] ?: '') ?></div>
            <?php endif; ?>
        </div>
        <div class="box">
            <div class="box-title">Delivery Details</div>
            <div class="field-line"><strong>DN #:</strong> <?= htmlspecialchars($dn_no) ?></div>
            <div class="field-line"><strong>Type:</strong> <?= $is_inbound ? 'Inbound (Received)' : 'Outbound (Sent)' ?></div>
            <div class="field-line"><strong>Date:</strong> <?= date('d M Y', strtotime($dn['delivery_date'])) ?></div>
            <div class="field-line"><strong>Status:</strong> <?= strtoupper($dn['status']) ?></div>
            <div class="field-line"><strong>Warehouse:</strong> <?= htmlspecialchars($dn['warehouse_name'] ?: 'N/A') ?></div>
        </div>
    </div>

    <div class="box">
        <div class="box-title">Instructions</div>
        <?php if (!empty($dn['project_name'])): ?><div class="field-line"><strong>Project:</strong> <?= htmlspecialchars($dn['project_name']) ?></div><?php endif; ?>
        <?php if (!empty($dn['project_contract_no'])): ?><div class="field-line"><strong>Contract:</strong> <?= htmlspecialchars($dn['project_contract_no']) ?></div><?php endif; ?>
        <div class="field-line"><strong>Prepared By:</strong> <?= htmlspecialchars($dn['prepared_by_name'] ?: ($dn_creator_name ?: 'Staff')) ?></div>
        <?php if ($linked_invoice): ?>
        <div class="field-line"><strong>Invoice:</strong> <?= htmlspecialchars($linked_invoice['invoice_number']) ?> &mdash; <?= strtoupper(htmlspecialchars($linked_invoice['status'])) ?></div>
        <?php endif; ?>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:34px;">S/NO</th>
                <th style="width:100px;">SKU</th>
                <th>Item / Description</th>
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
        <tfoot>
            <tr>
                <td colspan="3" class="text-right">TOTAL QUANTITY</td>
                <td class="text-right"><?= number_format($totalQty, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($dn['notes'])): ?>
    <div class="notes-section">
        <strong>Notes / Observations:</strong>
        <p><?= nl2br(htmlspecialchars($dn['notes'])) ?></p>
    </div>
    <?php endif; ?>

    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>

    <div id="dnSigRow" style="display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-top:24px;">
        <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
        <div class="signature-line" style="margin-top:46px;">
            Received By<br>
            <small>&nbsp;</small>
        </div>
    </div>
    <style>
        #dnSigRow .signature-box { gap: 20px; }
        #dnSigRow .signature-line { width: 160px; }
    </style>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
