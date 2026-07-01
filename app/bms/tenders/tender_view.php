<?php
// File: app/bms/tenders/tender_view.php
// scope-audit: skip — tender view page; tenders reference customers (no direct project_id); deferred to Phase G-2
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('tenders');

includeHeader();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT t.*, c.customer_name as entity_name, r.region_name, d.district_name, co.council_name, w.ward_name 
                       FROM tenders t 
                       LEFT JOIN customers c ON t.customer_id = c.customer_id 
                       LEFT JOIN regions r ON t.region_id = r.region_id
                       LEFT JOIN districts d ON t.district_id = d.district_id
                       LEFT JOIN councils co ON t.council_id = co.council_id
                       LEFT JOIN wards w ON t.ward_id = w.ward_id
                       WHERE t.tender_id = ?");
$stmt->execute([$id]);
$tender = $stmt->fetch();

if (!$tender) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Tender not found.</div></div>";
    includeFooter();
    exit;
}

// Fallback logic for raw text location names (if join found nothing)
$display_region   = $tender['region_name']   ?: ($tender['region_id']   ?: 'N/A');
$display_district = $tender['district_name'] ?: ($tender['district_id'] ?: 'N/A');
$display_council  = $tender['council_name']  ?: ($tender['council_id']  ?: 'N/A');
$display_ward     = $tender['ward_name']     ?: ($tender['ward_id']     ?: 'N/A');

// Log View
logActivity($pdo, $_SESSION['user_id'], 'View tender', "User viewed tender details: " . $tender['tender_no'] . " (ID $id)");

// Fetch Audit Trail (Searching for tender ID in description)
$logs_stmt = $pdo->prepare("SELECT a.*, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as full_name FROM activity_logs a 
                            LEFT JOIN users u ON a.user_id = u.user_id 
                            WHERE a.description LIKE ? ESCAPE '!'
                            ORDER BY a.created_at DESC");
$search_term = "%tender #$id%";
$logs_stmt->execute([$search_term]);
$logs = $logs_stmt->fetchAll();

// Fetch Assigned Staff
$staff_stmt = $pdo->prepare("SELECT ts.role_position, e.first_name, e.last_name, e.employee_number, d.designation_name 
                             FROM tender_staff ts
                             JOIN employees e ON ts.employee_id = e.employee_id
                             LEFT JOIN designations d ON e.designation_id = d.designation_id
                             WHERE ts.tender_id = ?");
$staff_stmt->execute([$id]);
$assigned_staff = $staff_stmt->fetchAll();

// Fetch Company Settings for Header
$company_name = getSetting('company_name', 'BMS');
$company_logo = getSetting('company_logo', '');
?>

<div class="container-fluid px-4 mt-4 mb-5">
    <!-- Professional BMS Branded Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
      
        
        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 8px 0; font-size: 18pt; letter-spacing: 1px;">TENDER DETAILS REPORT</h2>
        <p class="text-dark mb-1" style="font-size: 11pt;">Tender NO: <span class="fw-bold"><?= safe_output($tender['tender_no']) ?></span></p>
        <div style="border-bottom: 3px solid #0d6efd; width: 120px; margin: 15px auto 25px;"></div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <div class="d-print-none mb-3 mb-md-0 text-center text-md-start">
            <h2 class="fw-bold text-primary"><i class="bi bi-file-earmark-text me-2"></i>Tender Details</h2>
            <p class="text-muted small mb-0">Viewing comprehensive details for Tender No: <span class="fw-bold text-dark"><?= safe_output($tender['tender_no']) ?></span></p>
        </div>
        <div class="d-flex flex-nowrap gap-1 gap-md-2 no-print justify-content-center justify-content-md-end">
            <a href="<?= getUrl('tenders') ?>" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-arrow-left"></i> <span class="d-none d-sm-inline">Back to List</span><span class="d-inline d-sm-none">Back</span></a>
            <button onclick="printTender()" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-printer"></i> Print</button>
            <a href="<?= getUrl('tender_edit') ?>?id=<?= $id ?>" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-pencil"></i> <span class="d-none d-sm-inline">Edit Tender</span><span class="d-inline d-sm-none">Edit</span></a>
        </div>
    </div>

    <!-- 1: Tender & Institution Information -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-building me-2"></i>Institution Details</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <tr>
                                <th class="bg-light ps-3 text-nowrap" width="35%">Procuring Entity</th>
                            <td class="fw-bold"><?= safe_output($tender['entity_name'] ?: $tender['procuring_entity_name']) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Acronym</th>
                            <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2"><?= safe_output($tender['acronym']) ?></span></td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Location</th>
                            <td><?= safe_output($display_ward) ?>, <?= safe_output($display_council) ?>, <?= safe_output($display_district) ?>, <?= safe_output($display_region) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Contacts</th>
                            <td><?= safe_output($tender['contact_number']) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Physical Address</th>
                            <td><?= safe_output($tender['physical_address']) ?></td>
                        </tr>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-info-circle me-2"></i>Tender Details</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <tr>
                                <th class="bg-light ps-3 text-nowrap" width="35%">Description</th>
                            <td><?= nl2br(safe_output($tender['tender_description'])) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Category</th>
                            <td><?= safe_output($tender['tender_category']) ?> <?= $tender['tender_category_specify'] ? "({$tender['tender_category_specify']})" : '' ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Deadline</th>
                            <td class="text-danger fw-bold"><i class="bi bi-calendar-event me-1"></i><?= format_date($tender['submission_deadline'], 'd M Y, H:i') ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Current Status</th>
                            <td><span class="badge bg-primary px-3"><?= strtoupper($tender['status']) ?></span></td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Entrance Fee</th>
                            <td>
                                <?php
                                $ef_tzs = $tender['entrance_fee_tzs'] ?? null;
                                $ef_usd = $tender['entrance_fee_usd'] ?? null;
                                if ($ef_tzs || $ef_usd):
                                    if ($ef_tzs && $ef_usd)      echo 'TSh ' . number_format($ef_tzs, 2) . ' / USD ' . number_format($ef_usd, 2);
                                    elseif ($ef_usd)             echo 'USD ' . number_format($ef_usd, 2);
                                    else                         echo 'TSh ' . number_format($ef_tzs, 2);
                                else: ?>
                                    <span class="text-muted fst-italic small">Not recorded</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th class="bg-light ps-3">Tender Sum <small class="text-muted fw-normal">(Contract Sum)</small></th>
                            <td class="fw-bold text-primary fs-5">
                                <?php
                                $cs_tzs = $tender['tender_amount_tzs'] ?? null;
                                $cs_usd = $tender['tender_amount_usd'] ?? null;
                                if ($cs_tzs || $cs_usd):
                                    if ($cs_tzs && $cs_usd)     echo 'TSh ' . number_format($cs_tzs, 2) . ' / USD ' . number_format($cs_usd, 2);
                                    elseif ($cs_usd)            echo 'USD ' . number_format($cs_usd, 2);
                                    else                        echo 'TSh ' . number_format($cs_tzs, 2);
                                else: ?>
                                    <span class="text-muted fst-italic fs-6 fw-normal">Not yet submitted — set during Financial Submission</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2: Assigned Staff -->
    <div class="card border-0 shadow-sm mb-4 border-top border-primary border-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-people me-2"></i>Technical Assigned Staff</h5>
            <span class="badge bg-primary rounded-pill"><?= count($assigned_staff) ?> Members</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr class="small text-uppercase">
                            <th class="ps-3" width="60">S/NO</th>
                            <th>Full Name</th>
                            <th>Employee No</th>
                            <th>Designation</th>
                            <th>Role in Tender</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($assigned_staff)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted small"><i class="bi bi-info-circle me-1"></i> No technical staff have been assigned to this tender yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($assigned_staff as $idx => $s): ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-muted"><?= $idx + 1 ?></td>
                                    <td class="fw-bold text-dark"><?= safe_output($s['first_name'] . ' ' . $s['last_name']) ?></td>
                                    <td><?= safe_output($s['employee_number']) ?></td>
                                    <td><small class="text-muted fw-bold"><?= safe_output($s['designation_name'] ?? '-') ?></small></td>
                                    <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2"><?= safe_output($s['role_position']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 3: Documents -->
    <?php
    $doc_map = [
        'tender_document'             => ['label' => 'Tender Document',            'icon' => 'bi-file-earmark-text'],
        'participation_fee_document'  => ['label' => 'Participation Fee Document', 'icon' => 'bi-receipt'],
        'opening_document'            => ['label' => 'Opening Document',           'icon' => 'bi-folder2-open'],
        'evaluation_document'         => ['label' => 'Evaluation Document',        'icon' => 'bi-clipboard-data'],
        'post_qualification_document' => ['label' => 'Post Qualification Document','icon' => 'bi-patch-check'],
        'award_letter_document'       => ['label' => 'Award Letter',               'icon' => 'bi-trophy'],
        'submission_document'         => ['label' => 'Submission Document',        'icon' => 'bi-send'],
        'submission_document_tzs'     => ['label' => 'Submission Document (TZS)',  'icon' => 'bi-send'],
        'submission_document_usd'     => ['label' => 'Submission Document (USD)',  'icon' => 'bi-send'],
    ];
    $uploaded_docs = [];
    foreach ($doc_map as $col => $meta) {
        if (!empty($tender[$col])) {
            $ext = strtolower(pathinfo($tender[$col], PATHINFO_EXTENSION));
            $file_icon = match($ext) {
                'pdf'         => 'bi-file-earmark-pdf text-danger',
                'doc', 'docx' => 'bi-file-earmark-word text-primary',
                'xls', 'xlsx' => 'bi-file-earmark-excel text-success',
                'png', 'jpg', 'jpeg' => 'bi-file-earmark-image text-info',
                default       => 'bi-file-earmark text-secondary',
            };
            $uploaded_docs[] = [
                'col'       => $col,
                'label'     => $meta['label'],
                'name'      => basename($tender[$col]),
                'ext'       => $ext,
                'file_icon' => $file_icon,
                'viewable'  => in_array($ext, ['pdf', 'png', 'jpg', 'jpeg'], true),
            ];
        }
    }
    ?>
    <div class="card border-0 shadow-sm mb-4 border-top border-primary border-4 d-print-none">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-folder2-open me-2"></i>Uploaded Documents</h5>
            <span class="badge bg-primary rounded-pill"><?= count($uploaded_docs) ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($uploaded_docs)): ?>
                <p class="text-muted text-center py-3 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>No documents have been uploaded for this tender yet.
                </p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($uploaded_docs as $doc): ?>
                    <?php $view_url = buildUrl('api/view_tender_document.php') . '?id=' . $id . '&col=' . urlencode($doc['col']); ?>
                    <div class="col-lg-4 col-md-6">
                        <div class="card border h-100 shadow-sm">
                            <div class="card-body d-flex align-items-center gap-3 p-3">
                                <i class="bi <?= $doc['file_icon'] ?> flex-shrink-0" style="font-size:2rem;"></i>
                                <div class="overflow-hidden">
                                    <div class="fw-semibold small text-dark"><?= safe_output($doc['label']) ?></div>
                                    <div class="text-muted text-truncate" style="font-size:0.73rem;" title="<?= safe_output($doc['name']) ?>">
                                        <?= safe_output($doc['name']) ?>
                                    </div>
                                    <span class="badge bg-light text-secondary border mt-1" style="font-size:0.65rem;">
                                        <?= strtoupper($doc['ext']) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-footer bg-white border-top p-2 d-flex gap-2">
                                <?php if ($doc['viewable']): ?>
                                <button class="btn btn-sm btn-outline-primary flex-fill"
                                        onclick="viewDoc('<?= safe_output($view_url) ?>', '<?= safe_output(addslashes($doc['label'])) ?>')">
                                    <i class="bi bi-eye me-1"></i>View
                                </button>
                                <?php endif; ?>
                                <a href="<?= safe_output($view_url) ?>&amp;download=1"
                                   class="btn btn-sm btn-outline-secondary flex-fill"
                                   target="_blank">
                                    <i class="bi bi-download me-1"></i>Download
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4: Audit Trail -->
    <div class="card border-0 shadow-sm border-top border-primary border-4 d-print-none">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>Audit Trail & Activity Log</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="bg-light">
                        <tr class="small text-uppercase">
                            <th class="ps-3" width="180">Date & Time</th>
                            <th width="150">User</th>
                            <th width="100">Action</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($logs)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted small">No audit trail records found for this tender.</td></tr>
                        <?php else: ?>
                            <?php foreach($logs as $log): ?>
                                <tr class="small">
                                    <td class="ps-3 text-muted"><?= format_date($log['created_at'], 'd M Y, H:i:s') ?></td>
                                    <td class="fw-bold text-primary"><?= safe_output($log['full_name']) ?></td>
                                    <td><span class="badge bg-primary bg-opacity-75 small px-2"><?= $log['action'] ?></span></td>
                                    <td class="text-muted"><?= safe_output($log['description']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
        </div>
    </div>
    
</div>

<!-- Document Viewer Modal -->
<div class="modal fade" id="docViewerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title mb-0" id="docViewerTitle"><i class="bi bi-file-earmark me-1"></i></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="min-height:78vh;">
                <iframe id="docViewerFrame" src="" style="width:100%;height:78vh;border:none;display:block;"></iframe>
            </div>
            <div class="modal-footer py-2 d-print-none">
                <a id="docViewerDownload" href="#" class="btn btn-outline-secondary btn-sm" target="_blank">
                    <i class="bi bi-download me-1"></i>Download
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function printTender() {
    logActivityAction('PRINT', 'Tender Print', 'Printed details for tender: <?= safe_output($tender['tender_no']) ?>', 'tender', <?= $id ?>);
    window.print();
}

function viewDoc(url, label) {
    document.getElementById('docViewerTitle').innerHTML = '<i class="bi bi-file-earmark me-1"></i> ' + label;
    document.getElementById('docViewerFrame').src = url;
    document.getElementById('docViewerDownload').href = url + '&download=1';
    new bootstrap.Modal(document.getElementById('docViewerModal')).show();
}

document.getElementById('docViewerModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('docViewerFrame').src = '';
});
</script>

<style>
    @media (max-width: 768px) {
        .card-header h5 { font-size: 1rem; }
        .table th, .table td { font-size: 0.8rem; padding: 0.5rem !important; }
        .container-fluid { padding-left: 10px !important; padding-right: 10px !important; }
    }
    .table-responsive { border: none; }
    @page { margin: 10mm 8mm 16mm 8mm; }
    @media print {
        .btn, .no-print, header, footer, .d-print-none, .d-print-none * { display: none !important; }
        .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; margin-bottom: 25px !important; break-inside: avoid; }
        .container-fluid { padding: 0 !important; margin-top: 0 !important; max-width: 100% !important; overflow: visible !important; }
        .text-primary { color: #0d6efd !important; }
        .text-dark { color: #000 !important; }
        body { margin: 0; padding: 0 15px; }
        #tenderReportContainer { margin-bottom: 80px !important; }
    }
</style>

<?php includeFooter(); ?>
