<?php
// File: api/account/print_delivery_note.php
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

// Fetch DN details with supplier, customer, and warehouse info
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

// Direction-aware labels — the print layout/style is identical for both.
// Outbound is Sales-side / Customer-only now (dn_outbound.php); a handful of
// pre-existing outbound DNs still carry a Supplier/Sub-Contractor party — the
// vendor box below still serves those and all inbound (received-from) DNs.
$is_inbound     = ($dn['dn_type'] ?? 'inbound') !== 'outbound';
$is_to_customer = (!$is_inbound && ($dn['party_type'] ?? '') === 'customer');
$party_label    = (($dn['party_type'] ?? 'supplier') === 'subcontractor') ? 'Sub-Contractor' : 'Supplier';
$dn_no          = $is_inbound ? ($dn['dn_number'] ?: $dn['delivery_number']) : $dn['delivery_number'];

// Fetch Items
$stmtItems = $pdo->prepare("
    SELECT di.*, p.product_name, p.sku, p.unit
    FROM delivery_items di
    LEFT JOIN products p ON di.product_id = p.product_id
    WHERE di.delivery_id = ? ORDER BY di.delivery_item_id
");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Linked Invoice (outbound customer DNs) — boss's requirement: show whether
// it's Paid or just Approved.
$inv_stmt = $pdo->prepare("SELECT invoice_number, status FROM invoices WHERE delivery_id = ? AND status != 'cancelled' ORDER BY invoice_date DESC LIMIT 1");
$inv_stmt->execute([$id]);
$linked_invoice = $inv_stmt->fetch(PDO::FETCH_ASSOC);

// Company Settings
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

// Three-approval watermark — shown when status is not yet 'approved'.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Note #<?= htmlspecialchars($dn['delivery_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #1a252f;
            line-height: 1.5;
            padding: 20px 20px 0 20px;
            background: #fff;
        }

        /* ── HEADER ── */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            padding-bottom: 18px;
            border-bottom: 3px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .company-info { flex: 1; padding-right: 20px; }
        .company-info h1 {
            color: #0d6efd;
            font-size: 22px;
            font-weight: 800;
            text-transform: uppercase;
            margin: 0 0 10px 0;
            letter-spacing: 0.5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .company-addr-row {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .company-addr-row img {
            max-height: 60px;
            width: auto;
            flex-shrink: 0;
            object-fit: contain;
        }
        .company-addr-info p {
            margin: 2px 0;
            color: #1a252f;
            font-size: 11px;
            font-weight: 500;
        }

        /* ── TITLE BOX ── */
        .doc-title-box {
            text-align: right;
            background: #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            padding: 16px 22px;
            border-radius: 8px;
            min-width: 220px;
        }
        .doc-title-box h2 {
            margin: 0 0 10px 0;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .doc-title-box p {
            margin: 4px 0;
            font-size: 12px;
            color: #fff;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }

        /* ── INFO BOXES ── */
        .details-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 14px;
        }
        .box {
            width: 48%;
            background: #f4f6f8;
            padding: 14px 16px;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .box h3 {
            font-size: 11px;
            color: #1a252f;
            padding-bottom: 7px;
            margin-bottom: 10px;
            border-bottom: 1.5px solid #3498db;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .box p { margin: 3px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 600; }

        /* ── ITEMS TABLE ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th {
            background: #34495e;
            color: #fff;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 9px 10px;
            text-align: center;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        tbody tr {
            border-bottom: 1px solid #e4e8ec;
        }
        tbody tr:nth-child(even) {
            background: #f9fafb;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        tbody tr:last-child { border-bottom: 2px solid #3498db; }
        tbody tr td {
            height: 0.75cm;
            padding: 2px 10px;
            vertical-align: middle;
            font-size: 13px;
            line-height: 1.6;
            color: #1a252f;
        }
        .text-right  { text-align: right;  }
        .text-center { text-align: center; }
        .fw-bold     { font-weight: 700;   }

        /* ── NOTES ── */
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div {
            background: #f4f6f8;
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 3px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .notes-section strong {
            color: #1a252f;
            display: block;
            margin-bottom: 5px;
            font-size: 11.5px;
            font-weight: 700;
        }
        .notes-section p { color: #1a252f; font-size: 11px; }

        /* .signature-box / .signature-line CSS lives in workflow_signature_row.php (canonical) */

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
        }
    </style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <!-- HEADER -->
    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?>
                <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
                <?php endif; ?>
                <div class="company-addr-info">
                    <?php if (!empty($comp['address'])): ?>
                    <p><?= htmlspecialchars($comp['address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($comp['postal_address'])): ?>
                    <p>P.O. Box <?= htmlspecialchars($comp['postal_address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($comp['phone'])): ?>
                    <p>Phone: <?= htmlspecialchars($comp['phone']) ?></p>
                    <?php endif; ?>
                    <?php
                    $we = [];
                    if (!empty($comp['website'])) $we[] = 'Web: '   . htmlspecialchars($comp['website']);
                    if (!empty($comp['email']))   $we[] = 'Email: ' . htmlspecialchars($comp['email']);
                    if ($we): ?>
                    <p><?= implode(' | ', $we) ?></p>
                    <?php endif; ?>
                    <?php
                    $tv = [];
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                    if ($tv): ?>
                    <p><?= implode(' | ', $tv) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="doc-title-box">
            <h2>DELIVERY NOTE</h2>
            <p><strong>DN #:</strong> <?= htmlspecialchars($dn_no) ?></p>
            <p><strong>Type:</strong> <?= $is_inbound ? 'Inbound (Received)' : 'Outbound (Sent)' ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($dn['delivery_date'])) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($dn['status']) ?></p>
        </div>
    </div>

    <!-- CUSTOMER / VENDOR + DN INFO -->
    <div class="details-grid">
        <?php if ($is_to_customer): ?>
        <div class="box">
            <h3>Customer</h3>
            <p><strong><?= htmlspecialchars($dn['customer_name'] ?: 'N/A') ?></strong></p>
            <?php if (!empty($dn['customer_company'])): ?>
            <p><?= htmlspecialchars($dn['customer_company']) ?></p>
            <?php endif; ?>
            <?php if (!empty($dn['c_postal_address'])): ?>
            <p>P.O. Box <?= htmlspecialchars($dn['c_postal_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($dn['c_address'])): ?>
            <p><?= htmlspecialchars($dn['c_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($dn['c_phone'])): ?>
            <p><?= htmlspecialchars($dn['c_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($dn['c_email'])): ?>
            <p><?= htmlspecialchars($dn['c_email']) ?></p>
            <?php endif; ?>
            <?php
            $c_tv = [];
            if (!empty($dn['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($dn['c_tin']);
            if (!empty($dn['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($dn['c_vrn']);
            if ($c_tv): ?>
            <p><?= implode(' | ', $c_tv) ?></p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="box">
            <h3><?= $is_inbound ? 'Received From' : 'Sent To' ?> (<?= $party_label ?>)</h3>
            <p><strong><?= htmlspecialchars($dn['supplier_name'] ?: 'Local Inventory') ?></strong></p>
            <?php if (!empty($dn['supplier_company'])): ?>
            <p><?= htmlspecialchars($dn['supplier_company']) ?></p>
            <?php endif; ?>

            <?php if (!empty($dn['s_postal_address'])): ?>
            <p><?= htmlspecialchars($dn['s_postal_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($dn['s_address'])): ?>
            <p><?= htmlspecialchars($dn['s_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($dn['s_phone'])): ?>
            <p><?= htmlspecialchars($dn['s_phone']) ?></p>
            <?php endif; ?>
            <?php
            $s_tv = [];
            if (!empty($dn['s_tin'])) $s_tv[] = 'TIN: ' . htmlspecialchars($dn['s_tin']);
            if (!empty($dn['s_vrn'])) $s_tv[] = 'VRN: ' . htmlspecialchars($dn['s_vrn']);
            if ($s_tv): ?>
            <p><?= implode(' | ', $s_tv) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="box">
            <h3>Destination / Warehouse</h3>
            <p><strong>Warehouse:</strong> <?= htmlspecialchars($dn['warehouse_name'] ?: 'N/A') ?></p>
            <?php if (!empty($dn['warehouse_location'])): ?>
            <p><?= htmlspecialchars($dn['warehouse_location']) ?></p>
            <?php endif; ?>
            <hr style="margin: 8px 0; border: none; border-top: 1px solid #dee2e6;">
            <p><strong>Project:</strong> <?= htmlspecialchars($dn['project_name'] ?: 'N/A') ?></p>
            <?php if (!empty($dn['project_contract_no'])): ?>
            <p><strong>Contract:</strong> <?= htmlspecialchars($dn['project_contract_no']) ?></p>
            <?php endif; ?>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($dn['prepared_by_name'] ?: ($dn_creator_name ?: 'Staff')) ?></p>
            <?php if ($linked_invoice): ?>
            <p><strong>Invoice:</strong> <?= htmlspecialchars($linked_invoice['invoice_number']) ?> &mdash; <?= strtoupper(htmlspecialchars($linked_invoice['status'])) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th style="width:38px;">S/NO</th>
                <th style="width:120px;">SKU</th>
                <th>Item / Description</th>
                <th style="width:100px;"><?= $is_inbound ? 'Qty Received' : 'Qty Sent' ?></th>
                <th style="width:80px;">Unit</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $totalQty = 0;
            foreach ($items as $i => $item):
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
            <tr style="background: #f9fafb; font-weight: 700; border-top: 2px solid #3498db;">
                <td colspan="3" class="text-right">TOTAL QUANTITY</td>
                <td class="text-right"><?= number_format($totalQty, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <!-- NOTES -->
    <?php if (!empty($dn['notes'])): ?>
    <div class="notes-section">
        <div>
            <strong>Notes / Observations:</strong>
            <p><?= nl2br(htmlspecialchars($dn['notes'])) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- DRAFT WATERMARK (position:fixed; only when status !== 'approved') -->
    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>

    <!-- SIGNATURE / AUTHORIZATION — canonical partial (Created/Reviewed/Approved),
         plus a DN-specific "Received By" column in the same row. The wrapper below
         is local to this page only — workflow_signature_row.php itself is untouched,
         so every other print page keeps its unmodified 3-column row at full width.
         The #dnSigRow-scoped override just below narrows the columns so all 4 fit
         the ~733px printable width of an A4 page (3×210px+2×40px alone already
         uses ~710px — a 4th 210px column would overflow). Proof of physical
         receipt at the delivery destination — boss's requirement. -->
    <div id="dnSigRow" style="display:flex; align-items:flex-start; justify-content:space-between; gap:20px;">
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
