<?php
// File: api/account/print_delivery_note_depot.php
// "Depot" template — clean white/orange form with dual side-by-side
// signatures, inspired by a white-and-orange minimalist delivery form.
// Same data source and fields as print_delivery_note.php; presentation
// only differs.
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

$accent = getSetting('print_template_color_dn_depot', '#e05a1c');
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
            color: #1a1a1a;
            line-height: 1.5;
            padding: 24px 26px 0 26px;
            background: #fff;
        }

        .company-block { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
        .company-block img { max-height: 44px; width: auto; object-fit: contain; }
        .company-block h1 { font-size: 14px; font-weight: 700; }
        .company-block .sub { font-size: 9.5px; color: #6b6b6b; margin-top: 3px; }

        .doc-title { text-align: center; font-size: 26px; font-weight: 800; letter-spacing: 2px; color: var(--accent); margin: 8px 0 18px 0; }

        .section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #1a1a1a; padding-bottom: 4px; margin-bottom: 8px; }

        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 30px; margin-bottom: 18px; }
        .field-row { font-size: 11px; }
        .field-row .flabel { font-weight: 700; }
        .field-row .fvalue { border-bottom: 1px solid #999; display: inline-block; min-width: 160px; padding-bottom: 1px; }

        table { width: 100%; border-collapse: collapse; margin: 14px 0 8px 0; }
        table.items-table thead th { border-bottom: 2px solid #1a1a1a; padding: 6px 4px; font-size: 10.5px; text-transform: uppercase; text-align: left; }
        table.items-table tbody td { padding: 7px 4px; font-size: 11.5px; border-bottom: 1px solid #ddd; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        tfoot td { font-weight: 700; padding: 8px 4px; border-top: 2px solid #1a1a1a; }

        .notes-section { margin: 18px 0; font-size: 11px; }
        .notes-section strong { display: block; margin-bottom: 4px; }

        .dual-sig-row { display: flex; justify-content: space-between; gap: 40px; margin-top: 36px; }
        .dual-sig-row .sig-col { flex: 1; text-align: center; }
        .dual-sig-row .sig-line { border-top: 1px solid #1a1a1a; margin-top: 40px; padding-top: 6px; font-size: 11px; font-weight: 700; }

        .accent-bar { height: 10px; margin: 0 -26px; background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; }

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
    <div class="company-block">
        <?php if (!empty($comp['logo'])): ?>
        <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="sub">
                <?php
                $parts = [];
                if (!empty($comp['address'])) $parts[] = htmlspecialchars($comp['address']);
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

    <div class="doc-title">DELIVERY FORM</div>

    <div class="section-label"><?= $is_to_customer ? 'Customer Information' : (($is_inbound ? 'Received From' : 'Sent To') . ' (' . $party_label . ')') ?></div>
    <div class="field-grid">
        <?php if ($is_to_customer): ?>
        <div class="field-row"><span class="flabel">NAME:</span> <span class="fvalue"><?= htmlspecialchars($dn['customer_name'] ?: 'N/A') ?></span></div>
        <div class="field-row"><span class="flabel">ADDRESS:</span> <span class="fvalue"><?= htmlspecialchars($dn['c_address'] ?: '') ?></span></div>
        <div class="field-row"><span class="flabel">PHONE:</span> <span class="fvalue"><?= htmlspecialchars($dn['c_phone'] ?: '') ?></span></div>
        <div class="field-row"><span class="flabel">EMAIL:</span> <span class="fvalue"><?= htmlspecialchars($dn['c_email'] ?: '') ?></span></div>
        <?php
        $c_tv = [];
        if (!empty($dn['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($dn['c_tin']);
        if (!empty($dn['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($dn['c_vrn']);
        if ($c_tv): ?>
        <div class="field-row"><span class="flabel">TAX:</span> <span class="fvalue"><?= implode(' | ', $c_tv) ?></span></div>
        <?php endif; ?>
        <?php else: ?>
        <div class="field-row"><span class="flabel">NAME:</span> <span class="fvalue"><?= htmlspecialchars($dn['supplier_name'] ?: 'Local Inventory') ?></span></div>
        <div class="field-row"><span class="flabel">ADDRESS:</span> <span class="fvalue"><?= htmlspecialchars($dn['s_address'] ?: '') ?></span></div>
        <div class="field-row"><span class="flabel">PHONE:</span> <span class="fvalue"><?= htmlspecialchars($dn['s_phone'] ?: '') ?></span></div>
        <?php
        $s_tv = [];
        if (!empty($dn['s_tin'])) $s_tv[] = 'TIN: ' . htmlspecialchars($dn['s_tin']);
        if (!empty($dn['s_vrn'])) $s_tv[] = 'VRN: ' . htmlspecialchars($dn['s_vrn']);
        if ($s_tv): ?>
        <div class="field-row"><span class="flabel">TAX:</span> <span class="fvalue"><?= implode(' | ', $s_tv) ?></span></div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="section-label">Delivery Details</div>
    <div class="field-grid">
        <div class="field-row"><span class="flabel">DN #:</span> <span class="fvalue"><?= htmlspecialchars($dn_no) ?></span></div>
        <div class="field-row"><span class="flabel">TYPE:</span> <span class="fvalue"><?= $is_inbound ? 'Inbound (Received)' : 'Outbound (Sent)' ?></span></div>
        <div class="field-row"><span class="flabel">DATE:</span> <span class="fvalue"><?= date('d M Y', strtotime($dn['delivery_date'])) ?></span></div>
        <div class="field-row"><span class="flabel">STATUS:</span> <span class="fvalue"><?= strtoupper($dn['status']) ?></span></div>
        <div class="field-row"><span class="flabel">WAREHOUSE:</span> <span class="fvalue"><?= htmlspecialchars($dn['warehouse_name'] ?: 'N/A') ?></span></div>
        <?php if (!empty($dn['project_name'])): ?>
        <div class="field-row"><span class="flabel">PROJECT:</span> <span class="fvalue"><?= htmlspecialchars($dn['project_name']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($dn['project_contract_no'])): ?>
        <div class="field-row"><span class="flabel">CONTRACT:</span> <span class="fvalue"><?= htmlspecialchars($dn['project_contract_no']) ?></span></div>
        <?php endif; ?>
        <div class="field-row"><span class="flabel">PREPARED BY:</span> <span class="fvalue"><?= htmlspecialchars($dn['prepared_by_name'] ?: ($dn_creator_name ?: 'Staff')) ?></span></div>
        <?php if ($linked_invoice): ?>
        <div class="field-row"><span class="flabel">INVOICE:</span> <span class="fvalue"><?= htmlspecialchars($linked_invoice['invoice_number']) ?> &mdash; <?= strtoupper(htmlspecialchars($linked_invoice['status'])) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="section-label">Items To Be Delivered</div>
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

    <div class="accent-bar"></div>
    </div>


    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
