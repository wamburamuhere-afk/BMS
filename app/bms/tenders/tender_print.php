<?php
// File: app/bms/tenders/tender_print.php
// scope-audit: skip — tender print hub; tenders reference customers (no direct project_id); deferred to Phase G-2
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('tenders');

includeHeader();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT tender_id, tender_no FROM tenders WHERE tender_id = ?");
$stmt->execute([$id]);
$tender = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tender) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Tender not found.</div></div>";
    includeFooter();
    exit;
}

$tenderNavActive = 'print';
$printApi = buildUrl('api/tender_print.php');
$fotApi = buildUrl('api/tender_form_of_tender.php');
?>

<div class="container-fluid px-4 mt-4 mb-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
        <div class="mb-3 mb-md-0 text-center text-md-start">
            <h2 class="fw-bold text-primary"><i class="bi bi-printer me-2"></i>Preview &amp; Print</h2>
            <p class="text-muted small mb-0">Tender No: <span class="fw-bold text-dark"><?= safe_output($tender['tender_no']) ?></span></p>
        </div>
        <a href="<?= getUrl('tenders') ?>" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-arrow-left"></i> Back to List</a>
    </div>

    <?php require __DIR__ . '/_tender_nav.php'; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <p class="text-muted small">Each document prints on the company letterhead — use "Print / Save PDF" in your browser's print dialog once it opens, then upload the PDFs to NeST or bind them for physical submission.</p>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= $fotApi ?>?action=PRINT&tender_id=<?= (int)$id ?>" target="_blank" class="btn btn-success"><i class="bi bi-envelope-paper"></i> Form of Tender</a>
                <a href="<?= $printApi ?>?action=PRINT_MATERIALS&tender_id=<?= (int)$id ?>" target="_blank" class="btn btn-success"><i class="bi bi-boxes"></i> Materials Schedule</a>
                <a href="<?= $printApi ?>?action=PRINT_BOQ&tender_id=<?= (int)$id ?>" target="_blank" class="btn btn-success"><i class="bi bi-receipt-cutoff"></i> Bills of Quantities</a>
                <a href="<?= $printApi ?>?action=PRINT_CHECKLIST&tender_id=<?= (int)$id ?>" target="_blank" class="btn btn-success"><i class="bi bi-check2-square"></i> Checklist</a>
                <a href="https://nest.go.tz" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i> Open NeST Portal</a>
            </div>
        </div>
    </div>
</div>

<?php includeFooter(); ?>
