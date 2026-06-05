<?php
ob_start();
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('employees');

// Include the header
includeHeader();


$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($employee_id <= 0) {
    header("Location: " . getUrl('employees') . "?error=Invalid+Employee+ID");
    exit();
}

// Fetch Employee Details
$stmt = $pdo->prepare("
    SELECT e.*, d.department_name, des.designation_name,
    (SELECT COUNT(*) FROM attendance WHERE employee_id = e.employee_id AND status = 'present') as total_attendance,
    (SELECT COUNT(*) FROM leaves WHERE employee_id = e.employee_id AND status = 'approved') as total_leaves
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.department_id 
    LEFT JOIN designations des ON e.designation_id = des.designation_id
    WHERE e.employee_id = ?
");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header("Location: " . getUrl('employees') . "?error=Employee+Not+Found");
    exit();
}

// Phase D — project-scope gate
$emp_project_id = $employee['project_id'] ?? null;
if (!empty($emp_project_id) && function_exists('userCan') && !userCan('project', (int)$emp_project_id)) {
    header("Location: " . getUrl('employees') . "?error=Access+denied:+this+employee+is+not+in+your+project+scope");
    exit();
}

// Salary Structure (Plan H1) — the active components assigned to this employee, and
// the master list of components available to assign.
$can_edit_salary = isAdmin() || canEdit('payroll');
$sc_master = $pdo->query("SELECT component_id, component_name, component_type, calculation_type, default_amount
                            FROM salary_components WHERE status = 'active' ORDER BY component_type, component_name")->fetchAll(PDO::FETCH_ASSOC);
$sc_assigned = $pdo->prepare("SELECT esc.*, sc.component_name, sc.component_type, sc.calculation_type
                                FROM employee_salary_components esc
                                JOIN salary_components sc ON esc.component_id = sc.component_id
                               WHERE esc.employee_id = ? AND esc.status = 'active'
                            ORDER BY sc.component_type, sc.component_name");
$sc_assigned->execute([$employee_id]);
$sc_rows = $sc_assigned->fetchAll(PDO::FETCH_ASSOC);
$basic = (float)($employee['basic_salary'] ?? 0);
// Live structure totals (a % component resolves against basic for the preview).
$struct_earn = $basic; $struct_deduct = 0.0;
foreach ($sc_rows as $r) {
    $val = ($r['calculation_type'] === 'percentage') ? round($basic * (float)$r['amount'] / 100, 2) : (float)$r['amount'];
    if ($r['component_type'] === 'deduction') $struct_deduct += $val; else $struct_earn += $val;
}
$struct_net = $struct_earn - $struct_deduct;
?>

<style>
@page { margin: 10mm 8mm 16mm 8mm; }
@media print {
    body {
        padding-top: 0 !important;
        background: white !important;
    }

    .container-fluid {
        margin-top: 0 !important;
        padding-top: 0 !important;
        margin-bottom: 0 !important;
    }

    .d-print-none,
    .navbar,
    .btn,
    .breadcrumb,
    .card-placeholder,
    header {
        display: none !important;
    }

    .card {
        border: 1px solid #eee !important;
        box-shadow: none !important;
        margin-bottom: 30px !important;
        page-break-inside: avoid !important;
    }
}
</style>

<div class="container-fluid mt-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        
        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">EMPLOYEE PROFILE REPORT</h2>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>
    <div class="row mb-3">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <a href="<?= getUrl('employees') ?>" class="btn btn-outline-secondary d-print-none">
                <i class="bi bi-arrow-left"></i> Back to Employees
            </a>
            <button onclick="printEmployeeReport()" class="btn btn-info text-white shadow-sm d-print-none">
                <i class="bi bi-printer"></i> Print Employee Profile
            </button>
        </div>
    </div>

    <div class="row">
        <!-- Sidebar / Profile Card -->
        <div class="col-md-4 col-xl-3">
            <div class="card mb-4 shadow-sm">
                <div class="card-body text-center">
                    <?php if (!empty($employee['profile_image'])): ?>
                        <img src="<?= safe_output($employee['profile_image']) ?>" class="rounded-circle img-fluid mb-3" style="width: 150px; height: 150px; object-fit: cover;">
                    <?php else: ?>
                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 150px; height: 150px;">
                            <i class="bi bi-person-fill" style="font-size: 5rem; color: #adb5bd;"></i>
                        </div>
                    <?php endif; ?>
                    
                    <h4 class="card-title mb-1"><?= safe_output($employee['first_name'] . ' ' . $employee['last_name']) ?></h4>
                    <p class="text-muted mb-2"><?= safe_output($employee['designation_name']) ?></p>
                    <span class="badge bg-<?= $employee['employment_status'] === 'active' ? 'success' : 'secondary' ?> mb-3">
                        <?= ucfirst(str_replace('_', ' ', $employee['employment_status'])) ?>
                    </span>
                    
                    <div class="d-grid gap-2 d-print-none">
                         <?php if (isAdmin() || canEdit('employees')): ?>
                        <button class="btn btn-primary" onclick="editEmployee(<?= $employee['employee_id'] ?>)">
                            <i class="bi bi-pencil"></i> Edit Profile
                        </button>
                        <?php endif; ?>
                        <a href="<?= getUrl('attendance') ?>?employee=<?= $employee['employee_id'] ?>" class="btn btn-outline-primary">
                            <i class="bi bi-clock"></i> View Attendance
                        </a>
                        <a href="<?= getUrl('payroll') ?>?employee=<?= $employee['employee_id'] ?>" class="btn btn-outline-info">
                            <i class="bi bi-cash-stack"></i> View Payroll
                        </a>
                    </div>
                </div>
                <div class="card-footer bg-light p-3">
                    <div class="row text-center">
                        <div class="col-6 border-end">
                            <h5 class="mb-0"><?= $employee['total_attendance'] ?></h5>
                            <small class="text-muted">Attendance</small>
                        </div>
                        <div class="col-6">
                            <h5 class="mb-0"><?= $employee['total_leaves'] ?></h5>
                            <small class="text-muted">Leaves</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Info -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="card-title mb-0"><i class="bi bi-person-lines-fill"></i> Contact Info</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-3">
                            <i class="bi bi-envelope text-primary me-2"></i> 
                            <strong>Email:</strong><br>
                            <a href="mailto:<?= safe_output($employee['email']) ?>" class="text-decoration-none ms-4"><?= safe_output($employee['email']) ?></a>
                        </li>
                        <li class="mb-3">
                            <i class="bi bi-telephone text-primary me-2"></i> 
                            <strong>Phone:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['phone']) ?></span>
                        </li>
                        <li class="mb-3">
                            <i class="bi bi-geo-alt text-primary me-2"></i> 
                            <strong>Address:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['address']) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-8 col-xl-9">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0">Personal & Employment Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Employee ID</label>
                            <p class="fw-bold"><?= safe_output($employee['employee_number']) ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Department</label>
                            <p class="fw-bold"><?= safe_output($employee['department_name']) ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Join Date</label>
                            <p class="fw-bold"><?= !empty($employee['hire_date']) ? date('M d, Y', strtotime($employee['hire_date'])) : '-' ?></p>
                        </div>
                        
                        <div class="col-12"><hr class="my-2"></div>
                        
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Date of Birth</label>
                            <p class="fw-bold"><?= !empty($employee['date_of_birth']) ? date('M d, Y', strtotime($employee['date_of_birth'])) : '-' ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Gender</label>
                            <p class="fw-bold"><?= ucfirst($employee['gender']) ?></p>
                        </div>
                         <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">NIDA / ID Number</label>
                            <p class="fw-bold"><?= safe_output($employee['national_id'] ?? 'N/A') ?></p>
                        </div>

                        <div class="col-12"><hr class="my-2"></div>

                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Basic Salary</label>
                            <p class="fw-bold text-success"><?= format_currency($employee['basic_salary']) ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Bank Account</label>
                            <p class="fw-bold"><?= safe_output($employee['bank_account'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Bank Name</label>
                            <p class="fw-bold"><?= safe_output($employee['bank_name'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                </div>
                </div>
            </div>

            <!-- Salary Structure (Plan H1) -->
            <div class="card shadow-sm mb-4" id="salaryStructureCard">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-sliders text-primary me-1"></i> Salary Structure</h5>
                    <?php if ($can_edit_salary): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#assignCompModal"><i class="bi bi-plus-circle me-1"></i> Add Component</button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="row g-2 mb-3">
                        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center" style="border-color:#b6ccfe!important;"><div class="small text-muted text-uppercase fw-bold" style="font-size:.62rem;">Basic</div><div class="fw-bold"><?= number_format($basic, 2) ?></div></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center" style="border-color:#b6ccfe!important;"><div class="small text-muted text-uppercase fw-bold" style="font-size:.62rem;">Gross Earnings</div><div class="fw-bold text-primary"><?= number_format($struct_earn, 2) ?></div></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center" style="border-color:#b6ccfe!important;"><div class="small text-muted text-uppercase fw-bold" style="font-size:.62rem;">Deductions</div><div class="fw-bold text-danger"><?= number_format($struct_deduct, 2) ?></div></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center" style="background:#e7f0ff;border:1px solid #b6ccfe;"><div class="small text-muted text-uppercase fw-bold" style="font-size:.62rem;">Net (est.)</div><div class="fw-bold" style="color:#052c65;"><?= number_format($struct_net, 2) ?></div></div></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr><th class="ps-3">Component</th><th>Type</th><th>Basis</th><th class="text-end">Value</th><th class="text-end pe-3 d-print-none">Action</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$sc_rows): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No components assigned. Payroll will use this employee's basic salary (and any legacy allowances/deductions).</td></tr>
                                <?php else: foreach ($sc_rows as $r):
                                    $val = ($r['calculation_type'] === 'percentage') ? round($basic * (float)$r['amount'] / 100, 2) : (float)$r['amount'];
                                    $isDed = $r['component_type'] === 'deduction'; ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= safe_output($r['component_name']) ?></td>
                                    <td><span class="badge-status" style="background:<?= $isDed ? '#dc3545' : '#0d6efd' ?>;color:#fff;font-size:.62rem;padding:.3em .55em;border-radius:6px;"><?= strtoupper($r['component_type']) ?></span></td>
                                    <td class="small"><?= $r['calculation_type'] === 'percentage' ? number_format((float)$r['amount'], 2) . '% of basic' : 'Fixed' ?></td>
                                    <td class="text-end <?= $isDed ? 'text-danger' : '' ?>"><?= ($isDed ? '−' : '') . number_format($val, 2) ?></td>
                                    <td class="text-end pe-3 d-print-none">
                                        <?php if ($can_edit_salary): ?>
                                        <button class="btn btn-sm btn-outline-danger" onclick="removeComponent(<?= (int)$r['employee_component_id'] ?>)" title="Remove"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($can_edit_salary): ?>
            <!-- Assign component modal (§UI-1 blue header) -->
            <div class="modal fade" id="assignCompModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content border-0 shadow">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="bi bi-sliders me-1"></i> Add Salary Component</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="assignCompForm" autocomplete="off">
                            <div class="modal-body">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Component <span class="text-danger">*</span></label>
                                    <select class="form-select select2-static" name="component_id" id="ac-comp" required>
                                        <option value="">Select a component…</option>
                                        <?php foreach ($sc_master as $c): ?>
                                            <option value="<?= (int)$c['component_id'] ?>" data-calc="<?= htmlspecialchars($c['calculation_type']) ?>" data-default="<?= htmlspecialchars($c['default_amount']) ?>" data-type="<?= htmlspecialchars($c['component_type']) ?>">
                                                <?= safe_output($c['component_name']) ?> (<?= ucfirst($c['component_type']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text text-muted">Manage the master list in <a href="<?= getUrl('salary_components') ?>" target="_blank">Salary Components</a>.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Value for this employee <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="amount" id="ac-amount" step="0.01" min="0" required placeholder="0.00">
                                        <span class="input-group-text" id="ac-unit">amount</span>
                                    </div>
                                    <div class="form-text text-muted">Defaults from the component; override per employee. A % resolves against basic salary.</div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Effective Date</label>
                                    <input type="date" class="form-control" name="effective_date" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="modal-footer bg-light border-0">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check-circle me-1"></i> Assign</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Emergency Contact Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 text-danger"><i class="bi bi-person-exclamation"></i> Emergency Contact</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-md-3">
                            <label class="text-muted small text-uppercase">Contact Name</label>
                            <p class="fw-bold"><?= safe_output($employee['emergency_contact'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <label class="text-muted small text-uppercase">Relationship</label>
                            <p class="fw-bold"><?= safe_output($employee['emergency_contact_relationship'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <label class="text-muted small text-uppercase">Phone Number</label>
                            <p class="fw-bold text-primary"><?= safe_output($employee['emergency_contact_phone'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <label class="text-muted small text-uppercase">Email Address</label>
                            <p class="fw-bold"><?= safe_output($employee['emergency_contact_email'] ?? 'N/A') ?></p>
                        </div>
                        
                        <div class="col-12"><hr class="my-2"></div>
                        
                        <div class="col-sm-6 col-md-6">
                            <label class="text-muted small text-uppercase">Postal Address</label>
                            <p class="fw-bold"><?= safe_output($employee['emergency_contact_postal_address'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-6">
                            <label class="text-muted small text-uppercase">Physical Address</label>
                            <p class="fw-bold"><?= safe_output($employee['emergency_contact_physical_address'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Documents Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Employee Documents</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php 
                        $docs = !empty($employee['documents']) ? json_decode($employee['documents'], true) : [];
                        $doc_labels = [
                            'cv' => ['label' => 'CV / Resume', 'icon' => 'bi-file-earmark-person'],
                            'id' => ['label' => 'ID Copy', 'icon' => 'bi-card-list'],
                            'certificates' => ['label' => 'Certificates', 'icon' => 'bi-patch-check'],
                            'intro_letter' => ['label' => 'Introduction Letter', 'icon' => 'bi-house-check'],
                            'app_letter' => ['label' => 'Application Letter', 'icon' => 'bi-file-earmark-text'],
                            'other_doc' => ['label' => (!empty($employee['other_doc_name']) ? $employee['other_doc_name'] : 'Other Document'), 'icon' => 'bi-paperclip']
                        ];
                        
                        $has_docs = false;
                        foreach ($doc_labels as $key => $info):
                            if (isset($docs[$key])):
                                $has_docs = true;
                                $file_path = getUrl('') . $docs[$key];
                        ?>
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-center p-3 border rounded">
                                <i class="bi <?= $info['icon'] ?> fs-2 text-primary me-3"></i>
                                <div>
                                    <h6 class="mb-1"><?= $info['label'] ?></h6>
                                    <a href="<?= $file_path ?>" target="_blank" class="btn btn-sm btn-primary">
                                        <i class="bi bi-download"></i> View / Download
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php 
                            endif;
                        endforeach;
                        
                        if (!$has_docs):
                        ?>
                        <div class="col-12 text-center py-4">
                            <p class="text-muted mb-0"><i class="bi bi-file-earmark-x me-1"></i> No documents uploaded for this employee.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Payrolls -->
             <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Recent Payroll History</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">S/NO</th>
                                <th>Period</th>
                                <th>Date Paid</th>
                                <th>Net Salary</th>
                                <th>Status</th>
                                <th class="d-print-none">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $stmt_pay = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? ORDER BY payroll_period DESC LIMIT 5");
                            $stmt_pay->execute([$employee_id]);
                            $recent_payrolls = $stmt_pay->fetchAll(PDO::FETCH_ASSOC);

                            if(count($recent_payrolls) > 0):
                                $sn = 1;
                                foreach($recent_payrolls as $pay):
                            ?>
                            <tr>
                                <td class="ps-3"><?= $sn++ ?></td>
                                <td><?= date('F Y', strtotime($pay['payroll_period'] . '-01')) ?></td>
                                <td><?= !empty($pay['payment_date']) ? date('d M, Y', strtotime($pay['payment_date'])) : '-' ?></td>
                                <td class="fw-bold"><?= format_currency($pay['net_salary']) ?></td>
                                <td><span class="badge bg-<?= ($pay['payment_status'] == 'paid' ? 'success' : 'warning') ?>"><?= ucfirst($pay['payment_status']) ?></span></td>
                                <td class="d-print-none">
                                    <a href="<?= getUrl('payslip') ?>?id=<?= $pay['payroll_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No recent payroll records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>

   
</div>

<?php 
// Include Edit Modal (Reuse logic from employees.php)
// We need to ensure the JS for editing is available. 
// Ideally, we move the modal and JS to a shared file or include employees.php's modal here.
// For now, I'll direct them back to employees.php for editing to ensure consistency, 
// OR I can duplicate the modal/script or include a shared component.
// Given strict instructions to "make everything work", let's include the common scripts.
?>

<script>
$(document).ready(function() {

    // Global print function for this page
    window.printEmployeeReport = function() {
        window.print();
    };

    // ── Salary Structure (Plan H1) ──────────────────────────────────────
    const SC_ASSIGN_URL = '<?= buildUrl('api/pos/assign_salary_component.php') ?>';
    const SC_REMOVE_URL = '<?= buildUrl('api/pos/remove_salary_component.php') ?>';
    const SC_CSRF = '<?= csrf_token() ?>';

    $('#assignCompModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#assignCompModal'), width:'100%' });
        });
    });

    // Prefill the value + unit from the chosen component's default.
    $('#ac-comp').on('change', function () {
        const opt = $(this).find(':selected');
        const calc = opt.data('calc'), def = opt.data('default');
        $('#ac-unit').text(calc === 'percentage' ? '%' : 'amount');
        if (def !== undefined && def !== '') $('#ac-amount').val(def);
    });

    $('#assignCompForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:SC_ASSIGN_URL, type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
            success:function(res){
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('assignCompModal')).hide();
                    Swal.fire({ icon:'success', title:'Added!', text:res.message, timer:1600, showConfirmButton:false }).then(()=>location.reload());
                } else { Swal.fire({ icon:'error', title:'Error', text:res.message || 'Could not assign.' }); }
            },
            error:function(){ Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); },
            complete:function(){ btn.prop('disabled', false).html(orig); }
        });
    });

    window.removeComponent = function (id) {
        Swal.fire({ title:'Remove this component?', text:'It will no longer apply to future payslips.', icon:'warning',
            showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, remove' })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url:SC_REMOVE_URL, type:'POST', dataType:'json', data:{ employee_component_id:id, _csrf:SC_CSRF },
                success:function(res){ if(res.success){ location.reload(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    };
});

function editEmployee(id) {
    window.location.href = APP_URL + '/employees?edit_id=' + id;
}
</script>

<?php
include("footer.php");
ob_end_flush();
?>
