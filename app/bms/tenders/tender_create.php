<?php
// File: app/bms/tenders/tender_create.php
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('tenders');

// ============================================================
// AJAX POST HANDLER  (called by the AJAX form submit below)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    csrf_check();
    try {
        $tender_no = trim($_POST['tender_no'] ?? '');

        // Duplicate check
        $check = $pdo->prepare("SELECT COUNT(*) FROM tenders WHERE tender_no = ?");
        $check->execute([$tender_no]);
        if ($check->fetchColumn() > 0) {
            throw new Exception("Tender Number '$tender_no' already exists. Please use a unique number.");
        }

        $customer_id = (!empty($_POST['procuring_entity_id']) && is_numeric($_POST['procuring_entity_id']))
            ? intval($_POST['procuring_entity_id']) : null;
        $procuring_entity_name = $_POST['procuring_entity_name'] ?? null;

        // Tender document upload — §19 five-check pattern
        $document_path = null;
        if (isset($_FILES['tender_document']) && $_FILES['tender_document']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
            $allowed_mime = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg', 'image/png',
            ];
            $file_ext  = strtolower(pathinfo($_FILES['tender_document']['name'], PATHINFO_EXTENSION));
            $real_mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['tender_document']['tmp_name']);

            if (!in_array($file_ext, $allowed_ext, true))
                throw new Exception('Tender document type not allowed. Accepted: ' . implode(', ', $allowed_ext));
            if (!in_array($real_mime, $allowed_mime, true))
                throw new Exception('Tender document content does not match an allowed file type.');
            if ($_FILES['tender_document']['size'] > 20 * 1024 * 1024)
                throw new Exception('Tender document exceeds the 20 MB size limit.');

            $upload_dir = ROOT_DIR . '/uploads/tenders/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = bin2hex(random_bytes(16)) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['tender_document']['tmp_name'], $upload_dir . $filename)) {
                $document_path = 'uploads/tenders/' . $filename;
                registerFileInLibrary($pdo, $document_path, $_FILES['tender_document']['name'], $_FILES['tender_document']['size'], 'Tender Document - ' . ($tender_no ?? 'N/A'), 'tender,document', $_SESSION['user_id']);
            }
        }

        // Council / Ward / Region / District (directly save as text names)
        $region_input  = $_POST['region'] ?? null;
        $district_input = $_POST['district'] ?? null;
        $council_input = $_POST['council'] ?? null;
        $ward_input    = $_POST['ward'] ?? null;

        // Phase 3 – currency & entrance fee (document purchase fee paid to participate)
        $currency_choice    = $_POST['currency_choice'] ?? 'Tshs';
        $entrance_fee_tzs   = null;
        $entrance_fee_usd   = null;
        if ($currency_choice === 'Tshs') {
            $entrance_fee_tzs = !empty($_POST['tender_amount_tzs']) ? floatval($_POST['tender_amount_tzs']) : null;
            $primary_amount   = $entrance_fee_tzs;
        } elseif ($currency_choice === 'USD') {
            $entrance_fee_usd = !empty($_POST['tender_amount_usd']) ? floatval($_POST['tender_amount_usd']) : null;
            $primary_amount   = $entrance_fee_usd;
        } else { // Tshs & USD
            $entrance_fee_tzs = !empty($_POST['tender_amount_tzs']) ? floatval($_POST['tender_amount_tzs']) : null;
            $entrance_fee_usd = !empty($_POST['tender_amount_usd']) ? floatval($_POST['tender_amount_usd']) : null;
            $primary_amount   = $entrance_fee_tzs;
        }

        $category     = ($_POST['tender_category']     === 'Other') ? ($_POST['tender_category_other']     ?? 'Other') : $_POST['tender_category'];
        $sub_category = ($_POST['tender_sub_category'] === 'Other') ? ($_POST['tender_sub_category_other'] ?? 'Other') : $_POST['tender_sub_category'];
        $type         = ($_POST['tender_type']         === 'Other') ? ($_POST['tender_type_other']         ?? 'Other') : $_POST['tender_type'];

        $discipline = ($_POST['discipline'] === 'Other') ? ($_POST['discipline_other'] ?? 'Other') : $_POST['discipline'];
        $role       = ($_POST['tender_role'] === 'Other') ? ($_POST['tender_role_other'] ?? 'Other') : $_POST['tender_role'];

        $stmt = $pdo->prepare("
            INSERT INTO tenders (
                customer_id, procuring_entity_name, acronym, region_id, district_id,
                council_id, ward_id, contact_number, physical_address, postal_address,
                tender_description, tender_no, tender_category,
                tender_sub_category, tender_type,
                duration, discipline, tender_role,
                publication_date, submission_deadline, tender_document,
                currency, tender_sum, entrance_fee_tzs, entrance_fee_usd,
                status, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                'PENDING', ?
            )
        ");
        $stmt->execute([
            $customer_id,
            $procuring_entity_name,
            $_POST['acronym'] ?? null,
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
            $_SESSION['user_id']
        ]);

        $tender_id = $pdo->lastInsertId();

        logActivity($pdo, $_SESSION['user_id'], 'CREATE', "[Tender Registration] Registered new tender: $tender_no (ID: $tender_id)");

        echo json_encode(['success' => true, 'message' => "Tender <b>$tender_no</b> has been registered successfully.", 'redirect' => getUrl('tenders')]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// PAGE LOAD
// ============================================================
includeHeader();

$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Build a JS-friendly map of customer_name => customer_id
$customerMap = [];
foreach ($customers as $c) {
    $customerMap[$c['customer_name']] = $c['customer_id'];
}
$customerMapJson = json_encode($customerMap, JSON_HEX_APOS | JSON_HEX_QUOT);

logActivity($pdo, $_SESSION['user_id'], 'VIEW', "[Tender Registration View] Accessed tender registration form");
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

<div class="container-fluid mt-4 mb-5">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card border-0 shadow-lg overflow-hidden" style="border-radius:15px;">
                <div class="card-header bg-primary text-white p-4">
                    <h5 class="fw-bold mb-0 text-white"><i class="bi bi-file-earmark-plus me-2"></i>Register New Tender</h5>
                    <!-- Phase indicator -->
                    <div class="d-flex gap-2 mt-3" id="phase-indicator">
                        <span class="badge bg-white text-primary px-3 py-2" id="ind-1"></span>
                        <span class="text-white opacity-50"></span>
                        <span class="badge bg-white bg-opacity-25 text-white px-3 py-2" id="ind-2"></span>
                        <span class="text-white opacity-50"></span>
                        <span class="badge bg-white bg-opacity-25 text-white px-3 py-2" id="ind-3"></span>
                    </div>
                </div>

                <!-- ERROR BANNER (Phase-specific) -->
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
                                                    data-customer-id="<?= $customer['customer_id'] ?>">
                                                <?= safe_output($customer['customer_name']) ?><?= !empty($customer['company_name']) ? ' (' . safe_output($customer['company_name']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="ADD_NEW_CUSTOMER" class="fw-bold text-primary">--- CREATE NEW CUSTOMER ---</option>
                                    </select>
                                    <div class="invalid-feedback">Procuring entity is required.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Acronym</label>
                                    <input type="text" class="form-control" name="acronym" id="acronym_field" placeholder="e.g. TANROADS">
                                </div>
                                <!-- Hidden: tracks the linked customer ID for autofill -->
                                <input type="hidden" name="procuring_entity_id" id="procuring_entity_id" value="">

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Country <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="country" id="country" placeholder="Your country" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Region <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="region" id="region" placeholder="Your region" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">District <span class="text-danger">*</span> your district</label>
                                    <input type="text" class="form-control" name="district" id="district" placeholder="" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Council <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="council" id="council" placeholder="your council" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Ward <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="ward" id="ward" placeholder="your ward" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Contact Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="contact_number" id="contact_number" placeholder="+255-765-272-200" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Physical Address</label>
                                    <input type="text" class="form-control" name="physical_address">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Postal Address</label>
                                    <input type="text" class="form-control" name="postal_address">
                                </div>
                            </div>
                        </div>

                        <!-- ======== PHASE 2: Tender Details ======== -->
                        <div class="wizard-phase d-none" id="phase-2">
                            <div class="col-12"><h5 class="fw-bold text-primary mb-3">Tender Details</h5></div>
                            <div class="row g-4">
                                <div class="col-12">
                                    <label class="form-label fw-bold">Tender Description <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="tender_description" rows="2" placeholder="Describe the tender scope..." required></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Tender NO <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="tender_no" placeholder="TR/001/..." required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Tender Category <span class="text-danger">*</span></label>
                                    <select class="form-select select2-basic select-with-other" name="tender_category" id="tender_category" data-other-target="#tender_category_other_wrapper" required>
                                        <option value="">Select Category</option>
                                        <option value="Goods">Goods</option>
                                        <option value="Works">Works</option>
                                        <option value="Consultancy">Consultancy</option>
                                        <option value="Non-consultancy">Non-consultancy</option>
                                        <option value="Other">Other (Specify)</option>
                                    </select>
                                    <div id="tender_category_other_wrapper" class="d-none mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="tender_category_other" placeholder="Specify Category...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('tender_category','tender_category_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Tender Sub Category <span class="text-danger">*</span></label>
                                    <select class="form-select select2-no-search select-with-other" name="tender_sub_category" id="tender_sub_category" data-other-target="#tender_sub_category_other_wrapper" required>
                                        <option value="">Select Sub Category</option>
                                        <option value="Framework">Framework</option>
                                        <option value="Closed Framework">Closed Framework</option>
                                        <option value="Contract">Contract</option>
                                        <option value="Pre-Qualification">Pre-Qualification</option>
                                        <option value="Other">Other (Specify)</option>
                                    </select>
                                    <div id="tender_sub_category_other_wrapper" class="d-none mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="tender_sub_category_other" placeholder="Specify Sub Category...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('tender_sub_category','tender_sub_category_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Type <span class="text-danger">*</span></label>
                                    <select class="form-select select2-no-search select-with-other" name="tender_type" id="tender_type" data-other-target="#tender_type_other_wrapper" required>
                                        <option value="">Select Type</option>
                                        <option value="Framework">Framework</option>
                                        <option value="Contract">Contract</option>
                                        <option value="Other">Other (Specify)</option>
                                    </select>
                                    <div id="tender_type_other_wrapper" class="d-none mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="tender_type_other" placeholder="Specify Type...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('tender_type','tender_type_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Duration</label>
                                    <input type="text" class="form-control" name="duration" placeholder="Total days">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Submission Deadline <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="submission_deadline" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Date of Invitation</label>
                                    <input type="date" class="form-control" name="publication_date">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Discipline <span class="text-danger">*</span></label>
                                    <select class="form-select select2-basic select-with-other" name="discipline" id="discipline" data-other-target="#discipline_other_wrapper" required>
                                        <option value="">Select Discipline</option>
                                        <option value="Electrical works">Electrical works</option>
                                        <option value="Civil Works">Civil Works</option>
                                        <option value="Building Work">Building Work</option>
                                        <option value="Mechanical works">Mechanical works</option>
                                        <option value="Telecommunication">Telecommunication</option>
                                        <option value="Renewable Energy works">Renewable Energy works</option>
                                        <option value="Other">Other (Specify...)</option>
                                    </select>
                                    <div id="discipline_other_wrapper" class="d-none mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="discipline_other" placeholder="Specify Discipline...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('discipline','discipline_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Tender Role <span class="text-danger">*</span></label>
                                    <select class="form-select select2-basic select-with-other" name="tender_role" id="tender_role" data-other-target="#tender_role_other_wrapper" required>
                                        <option value="">Select Role</option>
                                        <option value="Main Contractor">Main Contractor</option>
                                        <option value="Sub-Contractor">Sub-Contractor</option>
                                        <option value="Lead Consultant">Lead Consultant</option>
                                        <option value="Sub-Consultant">Sub-Consultant</option>
                                        <option value="Joint Venture Partner">Joint Venture Partner</option>
                                        <option value="Other">Other (Specify...)</option>
                                    </select>
                                    <div id="tender_role_other_wrapper" class="d-none mt-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="tender_role_other" placeholder="Specify Role...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="resetOther('tender_role','tender_role_other_wrapper')"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">Attach Tender Docs / Others</label>
                                    <input type="file" class="form-control" name="tender_document">
                                    <small class="text-muted fst-italic">Upload any additional documents related to this tender invitation.</small>
                                </div>
                            </div>
                        </div>

                        <!-- ======== PHASE 3: TENDER ENTRANCE FEE ======== -->
                        <div class="wizard-phase d-none" id="phase-3">
                            <div class="col-12">
                                <h5 class="fw-bold text-primary mb-1"><i class="bi bi-ticket-perforated me-2"></i>Tender Entrance Fee</h5>
                                <p class="text-muted small mb-3">The fee paid to purchase tender documents and participate. This is <strong>not</strong> the bid/contract amount — that is recorded later at the Financial Submission stage.</p>
                            </div>
                            <div class="row g-4">

                                <!-- Currency Selector -->
                                <div class="col-12">
                                    <label class="form-label fw-bold">Currency <span class="text-danger">*</span></label>
                                    <div class="d-flex gap-4 flex-wrap mt-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="currency_choice" id="cur_tzs" value="Tshs" checked>
                                            <label class="form-check-label fw-semibold" for="cur_tzs">Tshs (Tanzanian Shillings)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="currency_choice" id="cur_usd" value="USD">
                                            <label class="form-check-label fw-semibold" for="cur_usd">USD (US Dollars)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="currency_choice" id="cur_both" value="Tshs & USD">
                                            <label class="form-check-label fw-semibold" for="cur_both">Tshs &amp; USD (Both)</label>
                                        </div>
                                    </div>
                                </div>

                                <!-- TShs Section -->
                                <div id="section_tzs" class="col-12">
                                    <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                                        <div class="card-header bg-primary text-white py-2 px-3 fw-bold">
                                            <i class="bi bi-cash me-2"></i>Tshs — Entrance Fee
                                        </div>
                                        <div class="card-body p-3">
                                            <div class="row g-3">
                                                <div class="col-md-12">
                                                    <label class="form-label fw-bold">Entrance Fee (Tshs) <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-primary text-white fw-bold">Tshs</span>
                                                        <input type="number" step="0.01" min="0" class="form-control" name="tender_amount_tzs" id="tender_amount_tzs" placeholder="e.g. 50000000">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- USD Section -->
                                <div id="section_usd" class="col-12 d-none">
                                    <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                                        <div class="card-header bg-success text-white py-2 px-3 fw-bold">
                                            <i class="bi bi-currency-dollar me-2"></i>USD — Entrance Fee
                                        </div>
                                        <div class="card-body p-3">
                                            <div class="row g-3">
                                                <div class="col-md-12">
                                                    <label class="form-label fw-bold">Entrance Fee (USD) <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-success text-white fw-bold">USD</span>
                                                        <input type="number" step="0.01" min="0" class="form-control" name="tender_amount_usd" id="tender_amount_usd" placeholder="e.g. 20000">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div><!-- /phase-3 -->

                    </div><!-- /card-body -->

                    <!-- ======== Footer Buttons ======== -->
                    <div class="card-footer bg-light p-4 d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-outline-secondary px-4 d-none" id="btn-prev" onclick="movePhase(-1)">
                            <i class="bi bi-chevron-left"></i> Previous
                        </button>
                        <div class="ms-auto d-flex gap-2">
                            <a href="<?= getUrl('tenders') ?>" class="btn btn-secondary px-4 d-none" id="btn-cancel">
                                Cancel
                            </a>
                            <button type="button" class="btn btn-primary px-5" id="btn-next" onclick="movePhase(1)">
                                Next Step <i class="bi bi-chevron-right ms-1"></i>
                            </button>
                            <button type="button" class="btn btn-primary px-5 d-none" id="btn-submit">
                                <i class="bi bi-check-circle me-1"></i> Finish &amp; Submit
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

/* ---- Phase navigation ---- */
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
            showError('Please fill in all required fields before proceeding to the next step.', currentPhase);
            return;
        }
    }
    hideError();
    currentPhase += dir;
    logActivityAction('NAVIGATE', 'Tender Form Navigation', 'Moved to phase ' + currentPhase + ' in registration form');
    $('.wizard-phase').addClass('d-none');
    $(`#phase-${currentPhase}`).removeClass('d-none');
    updateButtons();
    updateIndicator();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateButtons() {
    $('#btn-prev').toggleClass('d-none', currentPhase === 1);
    $('#btn-next').toggleClass('d-none', currentPhase === TOTAL_PHASES);
    $('#btn-cancel').toggleClass('d-none', currentPhase !== TOTAL_PHASES);
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

function showError(msg, phase) {
    if (phase) {
        // Jump to the right phase if error came from server mentioning a specific phase
    }
    $('#form-error-text').html(msg);
    $('#form-error-banner').removeClass('d-none');
    $('#form-error-banner')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hideError() {
    $('#form-error-banner').addClass('d-none');
}

/* ---- Form submit via AJAX ---- */
$(document).ready(function() {

    $('#btn-submit').on('click', function() {
        const form = document.getElementById('tenderWizardForm');
        // Validate phase 3 fields
        const phase3 = document.getElementById('phase-3');
        const inputs = phase3.querySelectorAll('input[required]');
        let valid = true;
        inputs.forEach(inp => {
            if (!inp.value.trim()) { inp.classList.add('is-invalid'); valid = false; }
            else inp.classList.remove('is-invalid');
        });
        if (!valid) {
            showError('Please fill in all required fields in this step before submitting.');
            return;
        }

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
                        title: 'Tender Registered!',
                        html: res.message,
                        confirmButtonColor: '#198754',
                        confirmButtonText: '<i class="bi bi-check-lg me-1"></i> OK',
                        allowOutsideClick: false
                    }).then(() => {
                        window.location.href = res.redirect;
                    });
                } else {
                    showError(res.message);
                    $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Finish &amp; Submit');
                }
            },
            error: function(xhr) {
                let msg = 'An unexpected server error occurred. Please try again.';
                try { const r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch(e) {}
                showError(msg);
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Finish &amp; Submit');
            }
        });
    });

    /* ---- Select2 init ---- */
    $('.select2-basic').select2({ theme: 'bootstrap-5', placeholder: '-- Choose --', allowClear: true });
    $('.select2-no-search').select2({ theme: 'bootstrap-5', placeholder: '-- Choose --', allowClear: true, minimumResultsForSearch: Infinity, dropdownParent: $('#phase-2') });
    $('.select2-creatable').select2({ theme: 'bootstrap-5', placeholder: '-- Start typing or search --', allowClear: true, tags: true });

    /* ---- Currency toggle ---- */
    $('input[name="currency_choice"]').on('change', function() {
        const val = $(this).val();
        const tzs = val === 'Tshs' || val === 'Tshs & USD';
        const usd = val === 'USD'  || val === 'Tshs & USD';
        $('#section_tzs').toggleClass('d-none', !tzs);
        $('#section_usd').toggleClass('d-none', !usd);
        $('#tender_amount_tzs').prop('required', tzs);
        $('#tender_amount_usd').prop('required', usd);
    });
    $('input[name="currency_choice"]:checked').trigger('change');

    /* ---- "Other" toggle ---- */
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

    /* ---- Redirect to Add Customer ---- */
    var customerIdMap = <?= $customerMapJson ?>;

    $('#procuring_entity').on('select2:select change', function() {
        var val = $(this).val();

        if (val === 'ADD_NEW_CUSTOMER') {
            window.location.href = '<?= getUrl("customers") ?>?action=add';
            return;
        }

        // Look up the customer_id from map
        var customerId = customerIdMap[val] || null;
        $('#procuring_entity_id').val(customerId || '');

        if (!customerId) return; // free-text typed — no autofill available

        // Autofill Institution Details from customer record
        $.getJSON('<?= getUrl("api/account/get_customer") ?>?id=' + customerId, function(res) {
            if (!res.success) return;
            var d = res.data;
            $('#country').val(d.country  || '');
            $('#region') .val(d.state    || '');   // state → Region
            $('#district').val(d.city    || '');   // city  → District
            $('#council') .val(d.council || '');
            $('#ward')    .val(d.ward    || '');
            $('#contact_number').val(d.phone || d.mobile || '');
            $('input[name="physical_address"]').val(d.address        || '');
            $('input[name="postal_address"]')  .val(d.postal_address || '');
            $('#acronym_field').val(d.acronym || '');
        });
    });

    // Cascading logic for location fields removed as requested - using text inputs instead.
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
