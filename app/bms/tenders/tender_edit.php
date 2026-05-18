<?php
// File: app/bms/tenders/tender_edit.php
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('tenders');

// ============================================================
// AJAX POST HANDLER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    csrf_check();
    try {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) throw new Exception("Invalid Tender ID");

        $tender_no = trim($_POST['tender_no'] ?? '');
        
        // Fetch current tender data
        $stmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
        $stmt->execute([$id]);
        $current_tender = $stmt->fetch();
        if (!$current_tender) throw new Exception("Tender not found");

        // Duplicate check
        $check = $pdo->prepare("SELECT COUNT(*) FROM tenders WHERE tender_no = ? AND tender_id != ?");
        $check->execute([$tender_no, $id]);
        if ($check->fetchColumn() > 0) {
            throw new Exception("Tender Number '$tender_no' already exists on another record.");
        }

        $customer_id = (!empty($_POST['procuring_entity_id']) && is_numeric($_POST['procuring_entity_id']))
            ? intval($_POST['procuring_entity_id']) : null;
        $procuring_entity_name = $_POST['procuring_entity_name'] ?? null;

        // Tender document upload
        $document_path = $current_tender['tender_document'];
        if (isset($_FILES['tender_document']) && $_FILES['tender_document']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
            $allowed_mime = [
                'application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg', 'image/png'
            ];
            $tmp_name  = $_FILES['tender_document']['tmp_name'];
            $file_ext  = strtolower(pathinfo($_FILES['tender_document']['name'], PATHINFO_EXTENSION));
            $real_mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp_name);
            if (!in_array($file_ext, $allowed_ext))
                throw new Exception("File type .$file_ext is not allowed.");
            if (!in_array($real_mime, $allowed_mime))
                throw new Exception("File content type '$real_mime' is not permitted.");
            if ($_FILES['tender_document']['size'] > 20 * 1024 * 1024)
                throw new Exception("File exceeds the 20 MB size limit.");
            $upload_dir = ROOT_DIR . '/uploads/tenders/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = bin2hex(random_bytes(16)) . '.' . $file_ext;
            if (move_uploaded_file($tmp_name, $upload_dir . $filename)) {
                $document_path = 'uploads/tenders/' . $filename;
                registerFileInLibrary($pdo, $document_path, $_FILES['tender_document']['name'], $_FILES['tender_document']['size'], 'Tender Document (Updated) - ' . ($tender_no ?? 'N/A'), 'tender,document', $_SESSION['user_id']);
            }
        }

        // Location Info (directly save as text names)
        $region_input  = $_POST['region'] ?? null;
        $district_input = $_POST['district'] ?? null;
        $council_input = $_POST['council'] ?? null;
        $ward_input    = $_POST['ward'] ?? null;

        // Phase 3 – currency & Participation Fee
        $currency_choice   = $_POST['currency_choice'] ?? 'Tshs';
        $entrance_fee_tzs  = !empty($_POST['entrance_fee_tzs']) ? floatval($_POST['entrance_fee_tzs']) : null;
        $entrance_fee_usd  = !empty($_POST['entrance_fee_usd']) ? floatval($_POST['entrance_fee_usd']) : null;
        $primary_amount    = ($currency_choice === 'USD') ? $entrance_fee_usd : $entrance_fee_tzs;

        $category     = ($_POST['tender_category']     === 'Other') ? ($_POST['tender_category_other']     ?? 'Other') : $_POST['tender_category'];
        $sub_category = ($_POST['tender_sub_category'] === 'Other') ? ($_POST['tender_sub_category_other'] ?? 'Other') : $_POST['tender_sub_category'];
        $type         = ($_POST['tender_type']         === 'Other') ? ($_POST['tender_type_other']         ?? 'Other') : $_POST['tender_type'];

        $discipline = ($_POST['discipline'] === 'Other') ? ($_POST['discipline_other'] ?? 'Other') : $_POST['discipline'];
        $role       = ($_POST['tender_role'] === 'Other') ? ($_POST['tender_role_other'] ?? 'Other') : $_POST['tender_role'];

        $stmt = $pdo->prepare("
            UPDATE tenders SET
                customer_id = ?, procuring_entity_name = ?, acronym = ?, 
                country = ?, region_id = ?, district_id = ?, 
                council_id = ?, ward_id = ?, 
                contact_number = ?, physical_address = ?, postal_address = ?,
                tender_description = ?, tender_no = ?, tender_category = ?,
                tender_sub_category = ?, tender_type = ?,
                duration = ?, discipline = ?, tender_role = ?,
                publication_date = ?, submission_deadline = ?, 
                tender_document = ?,
                currency = ?, tender_sum = ?,
                entrance_fee_tzs = ?, entrance_fee_usd = ?,
                status = ?,
                updated_at = NOW()
            WHERE tender_id = ?
        ");
        $stmt->execute([
            $customer_id,
            $procuring_entity_name,
            $_POST['acronym'] ?? null,
            $_POST['country']   ?? null,
            $region_input,
            $district_input,
            $council_input,
            $ward_input,
            $_POST['contact_number']   ?? null,
            $_POST['physical_address'] ?? null,
            $_POST['postal_address']   ?? null,
            $_POST['tender_description'] ?? null,
            $tender_no,
            $category,
            $sub_category,
            $type,
            $_POST['duration']    ?? null,
            $discipline,
            $role,
            !empty($_POST['publication_date'])   ? $_POST['publication_date']   : null,
            !empty($_POST['submission_deadline']) ? $_POST['submission_deadline'] : null,
            $document_path,
            $currency_choice,
            $primary_amount,
            $entrance_fee_tzs,
            $entrance_fee_usd,
            $_POST['status'] ?? 'PENDING',
            $id
        ]);

        logActivity($pdo, $_SESSION['user_id'], 'UPDATE', "[Tender Update] Updated tender: $tender_no (ID: $id)");

        echo json_encode(['success' => true, 'message' => "Tender <b>$tender_no</b> updated successfully.", 'redirect' => getUrl('tender_view') . "?id=$id"]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// PAGE LOAD
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
$stmt->execute([$id]);
$tender = $stmt->fetch();

if (!$tender) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Tender not found.</div></div>";
    exit;
}

includeHeader();

$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// For Select2 autofill map
$customerMap = [];
foreach ($customers as $c) {
    $customerMap[$c['customer_name']] = $c['customer_id'];
}
$customerMapJson = json_encode($customerMap, JSON_HEX_APOS | JSON_HEX_QUOT);

// Resolve location names if they were numeric IDs (for backward compatibility)
$res_region = $tender['region_id'];
if (is_numeric($res_region) && $res_region > 0) {
    $st = $pdo->prepare("SELECT region_name FROM regions WHERE region_id = ?");
    $st->execute([$res_region]);
    $res_region = $st->fetchColumn() ?: $res_region;
}
$res_district = $tender['district_id'];
if (is_numeric($res_district) && $res_district > 0) {
    $st = $pdo->prepare("SELECT district_name FROM districts WHERE district_id = ?");
    $st->execute([$res_district]);
    $res_district = $st->fetchColumn() ?: $res_district;
}
$res_council = $tender['council_id'];
if (is_numeric($res_council) && $res_council > 0) {
    $st = $pdo->prepare("SELECT council_name FROM councils WHERE council_id = ?");
    $st->execute([$res_council]);
    $res_council = $st->fetchColumn() ?: $res_council;
}
$res_ward = $tender['ward_id'];
if (is_numeric($res_ward) && $res_ward > 0) {
    $st = $pdo->prepare("SELECT ward_name FROM wards WHERE ward_id = ?");
    $st->execute([$res_ward]);
    $res_ward = $st->fetchColumn() ?: $res_ward;
}

logActivity($pdo, $_SESSION['user_id'], 'VIEW', "[Tender Edit View] Accessed edit form for: " . $tender['tender_no']);
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

<div class="container-fluid mt-4 mb-5">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card border-0 shadow-lg overflow-hidden" style="border-radius:15px;">
                <div class="card-header bg-primary text-white p-4">
                    <h5 class="fw-bold mb-0 text-white"><i class="bi bi-pencil-square me-2"></i>Edit Tender: <?= safe_output($tender['tender_no']) ?></h5>
                    <!-- Phase indicator -->
                    <div class="d-flex gap-2 mt-3" id="phase-indicator">
                        <span class="badge bg-white text-primary px-3 py-2" id="ind-1"></span>
                        <span class="text-white opacity-50"></span>
                        <span class="badge bg-white bg-opacity-25 text-white px-3 py-2" id="ind-2"></span>
                        <span class="text-white opacity-50"></span>
                        <span class="badge bg-white bg-opacity-25 text-white px-3 py-2" id="ind-3"></span>
                    </div>
                </div>

                <div id="form-error-banner" class="d-none alert alert-danger m-3 mb-0 d-flex align-items-center gap-2" style="border-radius:8px;">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <span id="form-error-text"></span>
                </div>

                <form id="tenderWizardForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="card-body p-4 pt-3">

                        <!-- ======== PHASE 1: Institution Details ======== -->
                        <div class="wizard-phase" id="phase-1">
                            <div class="col-12"><h5 class="fw-bold text-primary mb-3">Institution Details</h5></div>
                            <div class="row g-4">
                                <div class="col-md-9">
                                    <label class="form-label fw-bold text-dark">Name of Procuring Entity <span class="text-danger">*</span></label>
                                    <select class="form-select select2-creatable" name="procuring_entity_name" id="procuring_entity" required>
                                        <option value="">-- Start typing or search from customers --</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?= safe_output($customer['customer_name']) ?>"
                                                    data-customer-id="<?= $customer['customer_id'] ?>"
                                                    <?= ($tender['customer_id'] == $customer['customer_id']) ? 'selected' : '' ?>>
                                                <?= safe_output($customer['customer_name']) ?><?= !empty($customer['company_name']) ? ' (' . safe_output($customer['company_name']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="ADD_NEW_CUSTOMER" class="fw-bold text-primary">--- CREATE NEW CUSTOMER ---</option>
                                    </select>
                                    <div class="invalid-feedback">Procuring entity is required.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Acronym</label>
                                    <input type="text" class="form-control" name="acronym" id="acronym_field" value="<?= safe_output($tender['acronym']) ?>" placeholder="e.g. TANROADS">
                                </div>
                                <input type="hidden" name="procuring_entity_id" id="procuring_entity_id" value="<?= $tender['customer_id'] ?>">

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Country <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="country" id="country" value="<?= safe_output($tender['country'] ?: "Tanzania") ?>" placeholder="Your country" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Region <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="region" id="region" value="<?= safe_output($res_region) ?>" placeholder="Your region" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">District <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="district" id="district" value="<?= safe_output($res_district) ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Council <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="council" id="council" value="<?= safe_output($res_council) ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Ward <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="ward" id="ward" value="<?= safe_output($res_ward) ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Contact Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="contact_number" id="contact_number" value="<?= safe_output($tender['contact_number']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Physical Address</label>
                                    <input type="text" class="form-control" name="physical_address" value="<?= safe_output($tender['physical_address']) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Postal Address</label>
                                    <input type="text" class="form-control" name="postal_address" value="<?= safe_output($tender['postal_address']) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- ======== PHASE 2: Tender Details ======== -->
                        <div class="wizard-phase d-none" id="phase-2">
                            <div class="col-12"><h5 class="fw-bold text-primary mb-3">Tender Details</h5></div>
                            <div class="row g-4">
                                <div class="col-12">
                                    <label class="form-label fw-bold">Tender Description <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="tender_description" rows="2" required><?= safe_output($tender['tender_description']) ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Tender NO <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="tender_no" value="<?= safe_output($tender['tender_no']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Tender Category <span class="text-danger">*</span></label>
                                    <?php 
                                    $standard_cats = ['Goods', 'Works', 'Consultancy', 'Non-consultancy'];
                                    $cat_is_other = !in_array($tender['tender_category'], $standard_cats);
                                    ?>
                                    <select class="form-select select2-basic select-with-other" name="tender_category" id="tender_category" data-other-target="#tender_category_other_wrapper" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($standard_cats as $cat): ?>
                                            <option value="<?= $cat ?>" <?= ($tender['tender_category'] === $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other" <?= $cat_is_other ? 'selected' : '' ?>>Other (Specify)</option>
                                    </select>
                                    <div id="tender_category_other_wrapper" class="<?= $cat_is_other ? '' : 'd-none' ?> mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="tender_category_other" value="<?= $cat_is_other ? safe_output($tender['tender_category']) : '' ?>" placeholder="Specify Category...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('tender_category','tender_category_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Tender Sub Category <span class="text-danger">*</span></label>
                                    <?php 
                                    $standard_subs = ['Framework', 'Closed Framework', 'Contract', 'Pre-Qualification'];
                                    $sub_is_other = !in_array($tender['tender_sub_category'], $standard_subs);
                                    ?>
                                    <select class="form-select select2-no-search select-with-other" name="tender_sub_category" id="tender_sub_category" data-other-target="#tender_sub_category_other_wrapper" required>
                                        <option value="">Select Sub Category</option>
                                        <?php foreach ($standard_subs as $sub): ?>
                                            <option value="<?= $sub ?>" <?= ($tender['tender_sub_category'] === $sub) ? 'selected' : '' ?>><?= $sub ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other" <?= $sub_is_other ? 'selected' : '' ?>>Other (Specify)</option>
                                    </select>
                                    <div id="tender_sub_category_other_wrapper" class="<?= $sub_is_other ? '' : 'd-none' ?> mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="tender_sub_category_other" value="<?= $sub_is_other ? safe_output($tender['tender_sub_category']) : '' ?>" placeholder="Specify Sub Category...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('tender_sub_category','tender_sub_category_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Type <span class="text-danger">*</span></label>
                                    <?php 
                                    $standard_types = ['Framework', 'Contract'];
                                    $type_is_other = !in_array($tender['tender_type'], $standard_types);
                                    ?>
                                    <select class="form-select select2-no-search select-with-other" name="tender_type" id="tender_type" data-other-target="#tender_type_other_wrapper" required>
                                        <option value="">Select Type</option>
                                        <?php foreach ($standard_types as $type): ?>
                                            <option value="<?= $type ?>" <?= ($tender['tender_type'] === $type) ? 'selected' : '' ?>><?= $type ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other" <?= $type_is_other ? 'selected' : '' ?>>Other (Specify)</option>
                                    </select>
                                    <div id="tender_type_other_wrapper" class="<?= $type_is_other ? '' : 'd-none' ?> mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="tender_type_other" value="<?= $type_is_other ? safe_output($tender['tender_type']) : '' ?>" placeholder="Specify Type...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('tender_type','tender_type_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Duration</label>
                                    <input type="text" class="form-control" name="duration" value="<?= safe_output($tender['duration']) ?>" placeholder="Total days">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Submission Deadline <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="submission_deadline" value="<?= $tender['submission_deadline'] ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Date of Invitation</label>
                                    <input type="date" class="form-control" name="publication_date" value="<?= $tender['publication_date'] ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Discipline <span class="text-danger">*</span></label>
                                    <?php 
                                    $standard_disc = ['Electrical works', 'Civil Works', 'Building Work', 'Mechanical works', 'Telecommunication', 'Renewable Energy works'];
                                    $disc_is_other = !in_array($tender['discipline'], $standard_disc);
                                    ?>
                                    <select class="form-select select2-basic select-with-other" name="discipline" id="discipline" data-other-target="#discipline_other_wrapper" required>
                                        <option value="">Select Discipline</option>
                                        <?php foreach ($standard_disc as $d): ?>
                                            <option value="<?= $d ?>" <?= ($tender['discipline'] === $d) ? 'selected' : '' ?>><?= $d ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other" <?= $disc_is_other ? 'selected' : '' ?>>Other (Specify...)</option>
                                    </select>
                                    <div id="discipline_other_wrapper" class="<?= $disc_is_other ? '' : 'd-none' ?> mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="discipline_other" value="<?= $disc_is_other ? safe_output($tender['discipline']) : '' ?>" placeholder="Specify Discipline...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('discipline','discipline_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Tender Role <span class="text-danger">*</span></label>
                                    <?php 
                                    $standard_roles = ['Main Contractor', 'Sub-Contractor', 'Lead Consultant', 'Sub-Consultant', 'Joint Venture Partner'];
                                    $role_is_other = !in_array($tender['tender_role'], $standard_roles);
                                    ?>
                                    <select class="form-select select2-basic select-with-other" name="tender_role" id="tender_role" data-other-target="#tender_role_other_wrapper" required>
                                        <option value="">Select Role</option>
                                        <?php foreach ($standard_roles as $r): ?>
                                            <option value="<?= $r ?>" <?= ($tender['tender_role'] === $r) ? 'selected' : '' ?>><?= $r ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other" <?= $role_is_other ? 'selected' : '' ?>>Other (Specify...)</option>
                                    </select>
                                    <div id="tender_role_other_wrapper" class="<?= $role_is_other ? '' : 'd-none' ?> mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="tender_role_other" value="<?= $role_is_other ? safe_output($tender['tender_role']) : '' ?>" placeholder="Specify Role...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('tender_role','tender_role_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Current Status <span class="text-danger">*</span></label>
                                    <select class="form-select" name="status" required>
                                        <?php 
                                        $statuses = ['PENDING', 'INVITATION', 'SUBMISSION', 'OPENING', 'EVALUATION', 'POST-QUALIFICATION', 'NEGOTIATION', 'AWARDED', 'LOSS', 'END TENDER', 'cancelled'];
                                        foreach($statuses as $st): ?>
                                            <option value="<?= $st ?>" <?= (strtoupper($tender['status']) === strtoupper($st)) ? 'selected' : '' ?>><?= strtoupper($st) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">Update Tender Docs / Others</label>
                                    <input type="file" class="form-control" name="tender_document">
                                    <?php if ($tender['tender_document']): ?>
                                        <div class="mt-2"><small class="text-success"><i class="bi bi-file-earmark-check"></i> Current: <a href="<?= buildUrl($tender['tender_document']) ?>" target="_blank" class="text-decoration-none">View Document</a></small></div>
                                    <?php endif; ?>
                                    <small class="text-muted fst-italic">Upload a new file only if you wish to replace the current one.</small>
                                </div>
                            </div>
                        </div>

                        <!-- ======== PHASE 3: TENDER Participation Fee ======== -->
                        <div class="wizard-phase d-none" id="phase-3">
                            <div class="col-12">
                                <h5 class="fw-bold text-primary mb-1"><i class="bi bi-currency-exchange me-2"></i>Tender Participation Fee</h5>
                                <p class="text-muted small mb-3">This is the document purchase / participation fee paid to obtain tender documents. It is <strong>not</strong> the contract bid amount.</p>
                            </div>
                            <div class="row g-4">
                                <div class="col-12">
                                    <label class="form-label fw-bold">Currency <span class="text-danger">*</span></label>
                                    <div class="d-flex gap-4 flex-wrap mt-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="currency_choice" id="cur_tzs" value="Tshs" <?= ($tender['currency'] === 'Tshs') ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-semibold" for="cur_tzs">Tshs (Tanzanian Shillings)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="currency_choice" id="cur_usd" value="USD" <?= ($tender['currency'] === 'USD') ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-semibold" for="cur_usd">USD (US Dollars)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="currency_choice" id="cur_both" value="Tshs & USD" <?= ($tender['currency'] === 'Tshs & USD') ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-semibold" for="cur_both">Tshs &amp; USD (Both)</label>
                                        </div>
                                    </div>
                                </div>

                                <div id="section_tzs" class="col-12">
                                    <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                                        <div class="card-header bg-primary text-white py-2 px-3 fw-bold">
                                            <i class="bi bi-cash me-2"></i>Tshs — Participation Fee
                                        </div>
                                        <div class="card-body p-3">
                                            <div class="row g-3">
                                                <div class="col-md-12">
                                                    <label class="form-label fw-bold">Participation Fee (Tshs)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-primary text-white fw-bold">Tshs</span>
                                                        <input type="number" step="0.01" min="0" class="form-control" name="entrance_fee_tzs" id="entrance_fee_tzs" value="<?= $tender['entrance_fee_tzs'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div id="section_usd" class="col-12 d-none">
                                    <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                                        <div class="card-header bg-success text-white py-2 px-3 fw-bold">
                                            <i class="bi bi-currency-dollar me-2"></i>USD — Participation Fee
                                        </div>
                                        <div class="card-body p-3">
                                            <div class="row g-3">
                                                <div class="col-md-12">
                                                    <label class="form-label fw-bold">Participation Fee (USD)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-success text-white fw-bold">USD</span>
                                                        <input type="number" step="0.01" min="0" class="form-control" name="entrance_fee_usd" id="entrance_fee_usd" value="<?= $tender['entrance_fee_usd'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /card-body -->

                    <div class="card-footer bg-light p-4 d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-outline-secondary px-4 d-none" id="btn-prev" onclick="movePhase(-1)">
                            <i class="bi bi-chevron-left"></i> Previous
                        </button>
                        <div class="ms-auto d-flex gap-2">
                            <a href="<?= getUrl('tender_view') . "?id=$id" ?>" class="btn btn-secondary px-4 shadow-sm align-self-center">
                                Cancel
                            </a>
                            <button type="button" class="btn btn-primary px-5 shadow-sm" id="btn-next" onclick="movePhase(1)">
                                Next Step <i class="bi bi-chevron-right ms-1"></i>
                            </button>
                            <button type="button" class="btn btn-success px-5 shadow-sm d-none" id="btn-submit">
                                <i class="bi bi-check-circle me-1"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const TOTAL_PHASES = 3;
let currentPhase = 1;

function movePhase(dir) {
    if (dir === 1) {
        const activePhase = document.getElementById(`phase-${currentPhase}`);
        const inputs = activePhase.querySelectorAll('input[required], select[required], textarea[required]');
        let valid = true;
        inputs.forEach(inp => {
            if (!inp.value.trim()) { inp.classList.add('is-invalid'); valid = false; }
            else inp.classList.remove('is-invalid');
        });
        if (!valid) {
            showError('Please fill in all required fields before proceeding.', currentPhase);
            return;
        }
    }
    hideError();
    currentPhase += dir;
    $('.wizard-phase').addClass('d-none');
    $(`#phase-${currentPhase}`).removeClass('d-none');
    updateButtons();
    updateIndicator();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateButtons() {
    $('#btn-prev').toggleClass('d-none', currentPhase === 1);
    $('#btn-next').toggleClass('d-none', currentPhase === TOTAL_PHASES);
    $('#btn-submit').toggleClass('d-none', currentPhase !== TOTAL_PHASES);
}

function updateIndicator() {
    for (let i = 1; i <= TOTAL_PHASES; i++) {
        const el = $(`#ind-${i}`);
        if (i === currentPhase) {
            el.removeClass('bg-opacity-25 text-white').addClass('text-primary');
            el.css('background-color', '#fff');
        } else {
            el.addClass('bg-opacity-25 text-white').removeClass('text-primary');
            el.css('background-color', '');
        }
    }
}

function showError(msg) {
    $('#form-error-text').html(msg);
    $('#form-error-banner').removeClass('d-none');
    $('#form-error-banner')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hideError() {
    $('#form-error-banner').addClass('d-none');
}

$(document).ready(function() {
    updateIndicator();
    updateButtons();

    $('#btn-submit').on('click', function() {
        const form = document.getElementById('tenderWizardForm');
        const $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');
        hideError();

        const formData = new FormData(form);

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Tender Updated!',
                        html: res.message,
                        confirmButtonColor: '#198754',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.href = res.redirect;
                    });
                } else {
                    showError(res.message);
                    $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
                }
            },
            error: function(xhr) {
                let msg = 'Server error occurred.';
                try { const r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch(e) {}
                showError(msg);
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
            }
        });
    });

    $('.select2-basic').select2({ theme: 'bootstrap-5', placeholder: '-- Choose --', allowClear: true });
    $('.select2-no-search').select2({ theme: 'bootstrap-5', placeholder: '-- Choose --', allowClear: true, minimumResultsForSearch: Infinity });
    $('.select2-creatable').select2({ theme: 'bootstrap-5', placeholder: '-- Start typing --', allowClear: true, tags: true });

    $('input[name="currency_choice"]').on('change', function() {
        const val = $(this).val();
        const tzs = val === 'Tshs' || val === 'Tshs & USD';
        const usd = val === 'USD'  || val === 'Tshs & USD';
        $('#section_tzs').toggleClass('d-none', !tzs);
        $('#section_usd').toggleClass('d-none', !usd);
        $('#entrance_fee_tzs').prop('required', tzs);
        $('#entrance_fee_usd').prop('required', usd);
    });
    $('input[name="currency_choice"]:checked').trigger('change');

    $('.select-with-other').on('change', function() {
        if ($(this).val() === 'Other') {
            const target = $(this).data('other-target');
            $(this).next('.select2-container').addClass('d-none');
            $(target).removeClass('d-none').find('input').focus();
        }
    });

    window.resetOther = function(selectId, wrapperId) {
        $(`#${wrapperId}`).addClass('d-none').find('input').val('');
        $(`#${selectId}`).val('').trigger('change').next('.select2-container').removeClass('d-none');
    };

    var customerIdMap = <?= $customerMapJson ?>;
    $('#procuring_entity').on('select2:select change', function() {
        var val = $(this).val();
        if (val === 'ADD_NEW_CUSTOMER') { window.location.href = '<?= getUrl("customers") ?>?action=add'; return; }
        var customerId = customerIdMap[val] || null;
        $('#procuring_entity_id').val(customerId || '');
        if (!customerId) return;
        $.getJSON('<?= getUrl("api/account/get_customer") ?>?id=' + customerId, function(res) {
            if (!res.success) return;
            var d = res.data;
            $('#country').val(d.country  || '');
            $('#region') .val(d.state    || '');
            $('#district').val(d.city    || '');
            $('#council') .val(d.council || '');
            $('#ward')    .val(d.ward    || '');
            $('#contact_number').val(d.phone || d.mobile || '');
            $('input[name="physical_address"]').val(d.address || '');
            $('input[name="postal_address"]').val(d.postal_address || '');
            $('#acronym_field').val(d.acronym || '');
        });
    });
});
</script>

<style>
.card-header { border-bottom: none !important; }
.form-label  { color: #555; font-size: 0.88rem; }
.form-control, .form-select { border-color: #dee2e6; padding: 0.6rem 0.8rem; }
.form-control:focus, .form-select:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13,110,253,.15); }
.select2-container--bootstrap-5 .select2-selection { min-height: 42px; border-color: #dee2e6; }
#phase-indicator .badge { font-size: 0.78rem; transition: all 0.3s; }
</style>

<?php includeFooter(); ?>
