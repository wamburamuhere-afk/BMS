<?php
define('BMS_SUPPRESS_PRINT_HEADER', true);
require_once __DIR__ . '/../../../roots.php';
includeHeader();

if (!isAuthenticated()) {
    header('Location: ' . getUrl('login'));
    exit();
}

$inspection_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$inspection_id) {
    echo '<div class="container py-5 text-center"><p class="text-danger">Invalid inspection ID.</p></div>';
    includeFooter();
    exit();
}

$stmt = $pdo->prepare("
    SELECT i.*, p.project_name, p.project_id, p.contract_number,
           m.description AS milestone_description
    FROM project_inspections i
    JOIN projects p ON i.project_id = p.project_id
    LEFT JOIN project_milestones m ON i.milestone_id = m.id
    WHERE i.inspection_id = ?
");
$stmt->execute([$inspection_id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    echo '<div class="container py-5 text-center"><p class="text-danger">Inspection not found.</p></div>';
    includeFooter();
    exit();
}

$project_id = $r['project_id'];

// Load inspectors
$ins_q = $pdo->prepare("SELECT inspector_name, inspector_org FROM inspection_inspectors WHERE inspection_id = ? ORDER BY sort_order ASC");
$ins_q->execute([$inspection_id]);
$inspectors = $ins_q->fetchAll(PDO::FETCH_ASSOC);
if (empty($inspectors) && !empty($r['inspector_name'])) {
    $inspectors = [['inspector_name' => $r['inspector_name'], 'inspector_org' => $r['inspector_org'] ?? '']];
}

// Load attachments
$att_q = $pdo->prepare("SELECT id, original_name, display_name, file_name, file_type, file_size FROM inspection_attachments WHERE inspection_id = ? ORDER BY uploaded_at ASC");
$att_q->execute([$inspection_id]);
$attachments = $att_q->fetchAll(PDO::FETCH_ASSOC);

$result_class = ['Pass' => 'success', 'Fail' => 'danger', 'Conditional Pass' => 'warning'];
$status_class  = ['Pending' => 'warning', 'Completed' => 'success', 'Cancelled' => 'secondary', 'Open' => 'info', 'Closed' => 'dark', 'Pending Reinspection' => 'warning'];
$res_badge  = '<span class="badge bg-' . ($result_class[$r['result']] ?? 'secondary') . '">' . htmlspecialchars($r['result'] ?: 'Pending') . '</span>';
$stat_badge = '<span class="badge bg-' . ($status_class[$r['status']] ?? 'secondary') . '">' . htmlspecialchars($r['status'] ?: '-') . '</span>';

$company_name = getSetting('company_name') ?: 'BJP Technologies';
$company_logo = getSetting('company_logo') ?: '';
$upload_base  = getUrl('uploads/inspections/' . $inspection_id . '/');
?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Breadcrumb + Action Buttons -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 d-print-none">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none text-muted">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= getUrl('projects') ?>" class="text-decoration-none text-muted">Projects</a></li>
                <li class="breadcrumb-item"><a href="<?= getUrl('project_view') ?>?id=<?= $project_id ?>" class="text-decoration-none text-muted"><?= htmlspecialchars($r['project_name']) ?></a></li>
                <li class="breadcrumb-item active">Inspection <?= htmlspecialchars($r['inspection_no']) ?></li>
            </ol>
        </nav>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
            <a href="<?= getUrl('project_view') ?>?id=<?= $project_id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Project</a>
        </div>
    </div>

    <!-- Print header -->
    <div class="d-none d-print-block mb-4 text-center">
        <?php if ($company_logo): ?>
        <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height:70px;width:auto;" class="mb-2">
        <?php endif; ?>
        <div class="fw-bold fs-5"><?= htmlspecialchars($company_name) ?></div>
        <div class="fw-bold mt-1"><?= htmlspecialchars($r['project_name']) ?></div>
        <?php if (!empty($r['contract_number'])): ?>
        <div class="text-muted small mt-1">Contract No: <strong><?= htmlspecialchars($r['contract_number']) ?></strong></div>
        <?php endif; ?>
        <div class="text-muted small mt-1">Inspection Report — <?= htmlspecialchars($r['inspection_no']) ?></div>
    </div>

    <!-- Main Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
            <div>
                <h5 class="mb-0 fw-bold"><i class="bi bi-clipboard-check me-2"></i>Inspection <?= htmlspecialchars($r['inspection_no']) ?></h5>
                <small class="opacity-75"><?= htmlspecialchars($r['project_name']) ?></small>
            </div>
            <div class="d-flex gap-2 d-print-none">
                <?= $res_badge ?> <?= $stat_badge ?>
            </div>
        </div>
        <div class="card-body">

            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Inspection No</div>
                    <div class="fw-bold"><?= htmlspecialchars($r['inspection_no']) ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Date</div>
                    <div class="fw-bold"><?= htmlspecialchars($r['inspection_date'] ?: '-') ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Time</div>
                    <div class="fw-bold"><?= htmlspecialchars($r['inspection_time'] ?: '-') ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Type</div>
                    <div class="fw-bold"><?= htmlspecialchars($r['inspection_type'] ?: '-') ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Milestone</div>
                    <div class="fw-bold"><?= htmlspecialchars($r['milestone_description'] ?: '-') ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Location / Area</div>
                    <div class="fw-bold"><?= htmlspecialchars($r['location_area'] ?: '-') ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Result</div>
                    <div><?= $res_badge ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Status</div>
                    <div><?= $stat_badge ?></div>
                </div>
                <?php if (!empty($r['inspected_scope'])): ?>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Inspected Scope</div>
                    <div class="fw-bold"><?= number_format($r['inspected_scope'], 2) ?></div>
                </div>
                <?php endif; ?>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Re-inspection Required</div>
                    <div class="fw-bold"><?= $r['reinspection_required'] ? 'Yes' : 'No' ?></div>
                </div>
                <?php if ($r['reinspection_required'] && $r['reinspection_date']): ?>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Re-inspection Date</div>
                    <div class="fw-bold"><?= htmlspecialchars($r['reinspection_date']) ?></div>
                </div>
                <?php endif; ?>
                <div class="col-md-3 col-6">
                    <div class="text-muted small mb-1">Signed Off By</div>
                    <div class="fw-bold"><?= htmlspecialchars($r['signed_off_by'] ?: '-') ?></div>
                </div>
            </div>

            <hr>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="text-muted small mb-1">Defects Found</div>
                    <div class="bg-light rounded p-2" style="min-height:48px;white-space:pre-wrap;"><?= htmlspecialchars($r['defects_found'] ?: '-') ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small mb-1">Corrective Action</div>
                    <div class="bg-light rounded p-2" style="min-height:48px;white-space:pre-wrap;"><?= htmlspecialchars($r['corrective_action'] ?: '-') ?></div>
                </div>
                <?php if (!empty($r['notes'])): ?>
                <div class="col-12">
                    <div class="text-muted small mb-1">Notes</div>
                    <div class="bg-light rounded p-2" style="white-space:pre-wrap;"><?= htmlspecialchars($r['notes']) ?></div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Inspectors -->
    <?php if (!empty($inspectors)): ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2"></i>Inspectors</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered table-sm align-middle mb-0">
                <thead class="table-light small fw-bold text-muted">
                    <tr>
                        <th width="50" class="text-center">S/No</th>
                        <th>Name</th>
                        <th>Organisation</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($inspectors as $i => $ins): ?>
                    <tr>
                        <td class="text-center"><?= $i + 1 ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($ins['inspector_name']) ?></td>
                        <td><?= htmlspecialchars($ins['inspector_org'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Attachments — DataTable -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i>Attachments</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="overflow:visible;">
                <table class="table table-bordered table-sm align-middle mb-0" id="attachmentsTable">
                    <thead class="table-light small fw-bold text-muted">
                        <tr>
                            <th width="50" class="text-center">S/No</th>
                            <th>Name</th>
                            <th width="80">Type</th>
                            <th width="100">Size</th>
                            <th width="90" class="text-center d-print-none">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($attachments as $i => $att):
                        $label    = !empty($att['display_name']) ? $att['display_name'] : $att['original_name'];
                        $file_url = $upload_base . rawurlencode($att['file_name']);
                        $size_kb  = $att['file_size'] > 0 ? round($att['file_size'] / 1024, 1) . ' KB' : '-';
                        $type_uc  = strtoupper(htmlspecialchars($att['file_type']));
                    ?>
                    <tr>
                        <td class="text-center"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($label) ?></td>
                        <td><span class="badge bg-secondary"><?= $type_uc ?></span></td>
                        <td><?= $size_kb ?></td>
                        <td class="text-center d-print-none">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2"
                                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-gear-fill me-1"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                    <li>
                                        <a class="dropdown-item py-2 rounded" href="<?= $file_url ?>" target="_blank">
                                            <i class="bi bi-eye text-primary me-2"></i>View Online
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item py-2 rounded" href="<?= $file_url ?>" download="<?= htmlspecialchars($att['original_name']) ?>">
                                            <i class="bi bi-download text-success me-2"></i>Download
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider my-1"></li>
                                    <li>
                                        <a class="dropdown-item py-2 rounded text-danger" href="javascript:void(0)"
                                           onclick="deleteAttachment(<?= $att['id'] ?>, this)">
                                            <i class="bi bi-trash me-2"></i>Delete
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


</div>

<script>
const APP_URL_IV = '<?= rtrim(getUrl(''), '/') ?>';

$(document).ready(function () {
    $('#attachmentsTable').DataTable({
        pageLength: 25,
        responsive: true,
        dom: 'rtip',
        language: { emptyTable: 'No attachments found.' },
        columnDefs: [{ orderable: false, targets: [4] }]
    });
});

function deleteAttachment(id, el) {
    Swal.fire({
        title: 'Delete Attachment?',
        text: 'This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Delete'
    }).then(function (result) {
        if (!result.isConfirmed) return;
        $.post(APP_URL_IV + '/api/operations/delete_inspection_attachment.php', { id: id }, function (res) {
            if (res.success) {
                var row = $(el).closest('tr');
                $('#attachmentsTable').DataTable().row(row).remove().draw();
                Swal.fire({ icon: 'success', title: 'Deleted', timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}
</script>

<style>
@page { margin: 10mm 8mm 16mm 8mm; }
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</style>
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
<?php includeFooter(); ?>
