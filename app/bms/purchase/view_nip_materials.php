<?php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('nip_materials');
includeHeader();

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($product_id <= 0) {
    echo "<script>window.location.href='" . getUrl('nip_materials') . "';</script>";
    exit();
}

// Fetch product details
$stmt = $pdo->prepare("
    SELECT p.*, 
           COALESCE(w.warehouse_name, '—') AS warehouse_name,
           COALESCE(pr.project_name, 'General') AS project_name,
           COALESCE(t.rate_name, '—') AS tax_name,
           COALESCE(t.rate_percentage, 0) AS tax_rate
    FROM products p
    LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
    LEFT JOIN projects pr ON w.project_id = pr.project_id
    LEFT JOIN tax_rates t ON p.tax_id = t.rate_id
    WHERE p.product_id = ? AND p.is_service = 1
");
$stmt->execute([$product_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Non-Inventory product not found.</div></div>";
    includeFooter();
    exit();
}

// Fetch components
$comp_stmt = $pdo->prepare("
    SELECT pac.*, p.product_name, p.sku
    FROM product_assembly_components pac
    JOIN products p ON pac.component_product_id = p.product_id
    WHERE pac.parent_product_id = ?
    ORDER BY p.product_name ASC
");
$comp_stmt->execute([$product_id]);
$components = $comp_stmt->fetchAll(PDO::FETCH_ASSOC);

$status_map = [
    'active'   => 'status-badge-active',
    'approved' => 'status-badge-active',
    'pending'  => 'status-badge-pending',
    'draft'    => 'status-badge-draft',
    'inactive' => 'status-badge-inactive'
];
$sc = $status_map[$row['status']] ?? 'status-badge-draft';
?>

<style>
.status-badge-active   { background:#e7f0ff!important; color:#0d6efd!important; border:1px solid #b8d0ff; }
.status-badge-pending  { background:#fff!important;    color:#0d6efd!important; border:1px solid #0d6efd; }
.status-badge-draft    { background:#f8f9fa!important; color:#6c757d!important; border:1px solid #ced4da; }
.status-badge-inactive { background:#f8f9fa!important; color:#6c757d!important; border:1px solid #ced4da; }

.nip-product-avatar { 
    width:56px; height:56px; border-radius:50%; background: #e7f0ff; 
    display:flex; align-items:center; justify-content:center; flex-shrink:0; 
}

@media print {
    .d-print-none { display:none !important; }
    .card { border: none !important; box-shadow: none !important; }
    body { padding-top: 0 !important; }
    .container-fluid { padding: 0 !important; }
}

.sticky-header-sub {
    position: sticky;
    top: 105px; /* Adjust based on your main header height */
    z-index: 999;
    background: #fff;
}
</style>

<div class="container-fluid mt-4 px-4">

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-4 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('nip_materials') ?>">Materials</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($row['product_name']) ?></li>
        </ol>
    </nav>

    <!-- 1. Page Header (Sticky) -->
    <div class="sticky-header-sub d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="nip-product-avatar">
                <i class="bi bi-boxes fs-3 text-primary"></i>
            </div>
            <div>
                <h2 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($row['product_name']) ?></h2>
                <span class="badge <?= $sc ?> px-3 py-2" style="font-size: 0.85rem;">
                    <?= ucfirst($row['status']) ?>
                </span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('nip_materials') ?>" class="btn btn-light border fw-bold">
                <i class="bi bi-arrow-left me-1"></i> Back to List
            </a>
            <button class="btn btn-primary px-4 shadow-sm fw-bold" onclick="location.reload()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Print Only Header -->
    <div class="d-none d-print-block mb-4 text-center">
        <?php 
        $logo = get_setting('company_logo');
        if ($logo): ?>
            <img src="<?= getUrl($logo) ?>" alt="Logo" height="60" class="mb-3">
        <?php endif; ?>
        <h2 class="fw-bold text-primary mb-1"><?= get_setting('company_name', 'BMS') ?></h2>
        <h4 class="text-dark fw-bold">Material Specification Sheet</h4>
        <div class="text-muted small">
            Product: <span class="text-dark fw-bold"><?= htmlspecialchars($row['product_name']) ?></span> | 
            Date: <?= date('d M, Y') ?>
        </div>
        <hr class="mt-3">
    </div>

    <!-- 2. Stat Cards Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border shadow-sm p-3 h-100 text-center">
                <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem;">Project</div>
                <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($row['project_name']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border shadow-sm p-3 h-100 text-center">
                <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem;">Warehouse</div>
                <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($row['warehouse_name']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border shadow-sm p-3 h-100 text-center">
                <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem;">Selling Price</div>
                <div class="fw-bold fs-5 text-primary"><?= format_currency($row['selling_price']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border shadow-sm p-3 h-100 text-center">
                <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem;">Tax Rate</div>
                <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($row['tax_name']) ?> (<?= $row['tax_rate'] ?>%)</div>
            </div>
        </div>
    </div>

    <!-- 3. Controls Row (Below cards, Left aligned, Small white buttons) -->
    <div class="d-flex align-items-center gap-2 mb-4 d-print-none">
        <button class="btn btn-sm btn-white border bg-white shadow-sm px-3 fw-bold" onclick="exportNIPDetailPDF()" style="font-size: 0.78rem; height: 32px;">
            <i class="bi bi-file-earmark-pdf me-1 text-danger"></i> Export PDF
        </button>
        <button class="btn btn-sm btn-white border bg-white shadow-sm px-3 fw-bold" onclick="window.print()" style="font-size: 0.78rem; height: 32px;">
            <i class="bi bi-printer me-1 text-primary"></i> Print Details
        </button>
        <div class="d-flex align-items-center gap-1 border rounded bg-white px-2 shadow-sm" style="height: 32px;">
            <span class="text-muted" style="font-size: 0.72rem;">Show:</span>
            <select class="form-select form-select-sm border-0 p-0" id="nipPerPage" style="width: auto; font-size: 0.75rem; background: none; cursor: pointer;">
                <option value="5">5</option>
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="all">All</option>
            </select>
        </div>
    </div>

    <!-- 4. Material Components List -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-header bg-primary text-white py-3">
            <h5 class="card-title mb-0 fw-bold">
                <i class="bi bi-list-check me-2"></i>Material Components (<?= count($components) ?>)
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="nipComponentTable">
                    <thead class="bg-light">
                        <tr class="small text-uppercase fw-bold">
                            <th class="ps-4 py-3" style="width: 8%;">S/No</th>
                            <th style="width: 42%;">Component Name</th>
                            <th style="width: 15%;" class="text-center">Unit</th>
                            <th style="width: 15%;" class="text-end">Qty / Unit</th>
                            <th style="width: 20%;" class="text-end pe-4">Total Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($components)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 opacity-25 d-block mb-2"></i>
                                No material components linked to this product.
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($components as $i => $c): ?>
                            <tr class="nip-comp-row">
                                <td class="ps-4 fw-bold text-muted"><?= $i + 1 ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($c['product_name']) ?></div>
                                    <?php if ($c['sku']): ?>
                                    <small class="text-muted"><?= htmlspecialchars($c['sku']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($c['unit']) ?></span>
                                </td>
                                <td class="text-end fw-bold text-primary">
                                    <?= number_format($c['qty_per_unit'], 4) ?>
                                </td>
                                <td class="text-end fw-bold pe-4">
                                    <?= number_format($c['total_qty'], 4) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
            <div id="detailPaginationInfo" class="text-muted small fw-bold"></div>
            <div id="detailPageControls" class="d-flex gap-1"></div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    let detailCurrentPage = 1;

    function renderDetailTable() {
        const perPageVal = $('#nipPerPage').val();
        const perPage = (perPageVal === 'all') ? 999999 : parseInt(perPageVal);
        const rows = $('.nip-comp-row');
        const total = rows.length;
        
        rows.hide();
        const totalPages = Math.ceil(total / perPage);
        if (detailCurrentPage > totalPages) detailCurrentPage = totalPages || 1;

        const startIdx = (detailCurrentPage - 1) * perPage;
        const endIdx   = startIdx + perPage;

        rows.each(function(i) {
            if (i >= startIdx && i < endIdx) {
                $(this).show();
            }
        });

        // Update Info
        const shownCount = Math.min(endIdx, total) - startIdx;
        let infoText = `Showing ${total > 0 ? startIdx + 1 : 0} to ${Math.min(endIdx, total)} of ${total} components`;
        $('#detailPaginationInfo').text(infoText);

        // Generate Buttons
        let btnHtml = '';
        if (totalPages > 1) {
            btnHtml = '<span class="text-muted small me-2 align-self-center">Show</span>';
            for (let p = 1; p <= totalPages; p++) {
                const activeClass = p === detailCurrentPage ? 'btn-secondary shadow-sm' : 'btn-outline-secondary';
                btnHtml += `<button class="btn btn-sm ${activeClass} fw-bold me-1" onclick="goToDetailPage(${p})" style="min-width:32px; border-radius: 4px;">${p}</button>`;
            }
        }
        $('#detailPageControls').html(btnHtml);
    }

    window.goToDetailPage = function(p) {
        detailCurrentPage = p;
        renderDetailTable();
    };

    $('#nipPerPage').on('change', function() {
        detailCurrentPage = 1;
        renderDetailTable();
    });
    
    // Initial trigger
    renderDetailTable();
});

async function exportNIPDetailPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'pt', 'a4');
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();

    Swal.fire({
        title: 'Preparing PDF...',
        text: 'Creating a high-quality material specification sheet.',
        icon: 'info',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    const companyName = "<?= get_setting('company_name', 'BMS') ?>";
    const companyLogo = "<?= !empty($row['company_logo']) ? getUrl($row['company_logo']) : (get_setting('company_logo') ? getUrl(get_setting('company_logo')) : '') ?>";
    const productName = "<?= addslashes($row['product_name']) ?>";
    const exportTime = "<?= date('d M, Y \a\t H:i:s') ?>";
    const exportUser = "<?= htmlspecialchars($_SESSION['first_name'] ?? '') ?> <?= htmlspecialchars($_SESSION['last_name'] ?? '') ?>";

    let logoImgData = null;
    if (companyLogo) {
        try {
            logoImgData = await new Promise((resolve) => {
                const img = new Image();
                img.crossOrigin = "Anonymous";
                img.onload = () => resolve({ data: img, w: img.naturalWidth, h: img.naturalHeight });
                img.onerror = () => resolve(null);
                img.src = companyLogo;
            });
        } catch(e) { logoImgData = null; }
    }

    let currentY = 50;
    if (logoImgData) {
        const hScale = 50 / logoImgData.h;
        const dw = logoImgData.w * hScale;
        doc.addImage(logoImgData.data, 'JPEG', (pageWidth - dw) / 2, currentY, dw, 50);
        currentY += 70;
    }

    doc.setFontSize(22);
    doc.setTextColor(13, 110, 253);
    doc.setFont('helvetica', 'bold');
    doc.text(companyName.toUpperCase(), pageWidth / 2, currentY, { align: 'center' });
    currentY += 30;

    doc.setFontSize(16);
    doc.setTextColor(0, 0, 0);
    doc.text("MATERIAL SPECIFICATION SHEET", pageWidth / 2, currentY, { align: 'center' });
    currentY += 10;
    
    doc.setFontSize(11);
    doc.setTextColor(100);
    doc.setFont('helvetica', 'normal');
    doc.text(`Product: ${productName}`, pageWidth / 2, currentY + 15, { align: 'center' });
    currentY += 40;

    // Stat Info
    const stats = [
        ["Project", "Warehouse", "Selling Price", "Tax Rate"],
        ["<?= addslashes($row['project_name']) ?>", "<?= addslashes($row['warehouse_name']) ?>", "<?= format_currency($row['selling_price']) ?>", "<?= $row['tax_name'] ?> (<?= $row['tax_rate'] ?>%)"]
    ];

    doc.autoTable({
        body: [stats[1]],
        head: [stats[0]],
        startY: currentY,
        theme: 'plain',
        headStyles: { fillColor: [248, 249, 250], textColor: 100, fontSize: 8, fontStyle: 'bold', halign: 'center' },
        styles: { fontSize: 12, halign: 'center', textColor: 0, fontStyle: 'bold' },
        margin: { left: 40, right: 40 }
    });

    currentY = doc.lastAutoTable.finalY + 40;

    // Components Table
    const tableHead = [["S/NO", "COMPONENT NAME", "UNIT", "QTY / UNIT", "TOTAL QTY"]];
    const tableBody = [];
    $('.nip-comp-row:visible').each(function() {
        const row = [];
        $(this).find('td').each(function(idx, td) {
            row.push($(td).text().trim().replace(/\s+/g, ' '));
        });
        tableBody.push(row);
    });

    doc.autoTable({
        head: tableHead,
        body: tableBody,
        startY: currentY,
        theme: 'striped',
        headStyles: { fillColor: [13, 110, 253], textColor: 255, halign: 'center', fontSize: 10 },
        styles: { fontSize: 9, halign: 'center', valign: 'middle' },
        columnStyles: {
            1: { halign: 'left' }
        },
        margin: { left: 40, right: 40, bottom: 60 },
        didDrawPage: (data) => {
            doc.setFontSize(8);
            doc.setTextColor(150);
            doc.text(`Printed by ${exportUser} on ${exportTime}`, pageWidth / 2, pageHeight - 35, { align: 'center' });
            doc.setTextColor(13, 110, 253);
            doc.setFont('helvetica', 'bold');
            doc.text(`Powered by BJP Technologies © <?= date('Y') ?>`, pageWidth / 2, pageHeight - 20, { align: 'center' });
            doc.text(`Page ${data.pageNumber}`, pageWidth - 50, pageHeight - 20);
        }
    });

    doc.save(`Material_Detail_${productName.replace(/[^a-z0-0]/gi, '_')}.pdf`);
    Swal.close();
}
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

<?php includeFooter(); ?>
