<?php
ob_start();
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('employees');

// D5 — apply any approved resignation whose last working day has passed,
// so the status shown below is always current
require_once __DIR__ . '/../../../core/lifecycle_effects.php';
applyDueLifecycleEffects($pdo);

// Include the header
includeHeader();


$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($employee_id <= 0) {
    header("Location: " . getUrl('employees') . "?error=Invalid+Employee+ID");
    exit();
}

// ── Project context (clean deep-link from Project Details) ───────────────────
// Arriving as ?project=<id>&back=<tab> shows a "Back to Project" banner and
// rebuilds the return URL server-side, so the address bar stays clean.
$proj_ctx_id     = isset($_GET['project']) ? intval($_GET['project']) : 0;
$proj_ctx_back   = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['back'] ?? ''));
$proj_ctx_name   = '';
$proj_ctx_return = '';
if ($proj_ctx_id > 0) {
    $pcs = $pdo->prepare("SELECT project_name FROM projects WHERE project_id = ?");
    $pcs->execute([$proj_ctx_id]);
    $proj_ctx_name   = $pcs->fetchColumn() ?: '';
    $proj_ctx_tab    = $proj_ctx_back !== '' ? $proj_ctx_back : 'staff';
    $proj_ctx_return = getUrl('project_view') . '?id=' . $proj_ctx_id . '&tab=' . $proj_ctx_tab;
}

// Fetch Employee Details
$stmt = $pdo->prepare("
    SELECT e.*, d.department_name, des.designation_name, et.type_name as employment_type, pr.project_name,
    (SELECT COUNT(*) FROM attendance WHERE employee_id = e.employee_id AND status = 'present') as total_attendance,
    (SELECT COUNT(*) FROM leaves WHERE employee_id = e.employee_id AND status = 'approved') as total_leaves,
    (SELECT COUNT(*) FROM employee_lifecycle_events WHERE employee_id = e.employee_id AND event_type = 'award' AND status = 'approved') as total_awards,
    (SELECT COUNT(*) FROM employee_lifecycle_events WHERE employee_id = e.employee_id AND event_type = 'warning' AND status = 'approved') as total_warnings
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN designations des ON e.designation_id = des.designation_id
    LEFT JOIN employment_types et ON e.employment_type_id = et.type_id
    LEFT JOIN projects pr ON e.project_id = pr.project_id
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

// Attendance — mark/correct this employee's own attendance directly from their
// profile (matches the API's own gate in api/mark_attendance.php).
$can_mark_attendance = canCreate('attendance');

// Leave — apply for / review this employee's own leave directly from their profile.
// Mirrors leaves.php's own gates exactly (api/apply_leave.php checks canCreate('leaves');
// approve/reject/delete on leaves.php are gated on canEdit/canDelete('leaves')).
$can_apply_leave    = canCreate('leaves');
$can_approve_leaves = isAdmin() || canEdit('leaves');
$can_delete_leaves  = isAdmin() || canDelete('leaves');
$leave_types = $pdo->query("SELECT * FROM leave_types WHERE status = 'active' ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
$handover_candidates = $pdo->query("SELECT employee_id, first_name, last_name FROM employees WHERE status = 'active' AND employee_id != " . (int)$employee_id . " ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);

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

// Employee Documents (Tier 2, Phase 2.2) — new typed & expiry-tracked system.
// The legacy JSON documents below stay read-only and untouched (D9).
$can_view_documents   = canView('employee_documents');
$can_create_documents = canCreate('employee_documents');
$can_delete_documents = canDelete('employee_documents');
$emp_documents = [];
$doc_types     = [];
if ($can_view_documents) {
    $ed_stmt = $pdo->prepare("
        SELECT ed.*, dt.type_name,
               DATEDIFF(ed.expire_date, CURDATE()) AS days_to_expiry
        FROM employee_documents ed
        JOIN employee_document_types dt ON dt.doc_type_id = ed.doc_type_id
        WHERE ed.employee_id = ? AND ed.status = 'active'
        ORDER BY ed.created_at DESC
    ");
    $ed_stmt->execute([$employee_id]);
    $emp_documents = $ed_stmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($can_create_documents) {
    $doc_types = $pdo->query("SELECT doc_type_id, type_name, requires_expiry FROM employee_document_types
                               WHERE status = 'active' ORDER BY sort_order, type_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Employee Contracts (Tier 2, Phase 2.3) — history newest-first, current
// contract highlighted. Activation/renewal/termination happen on the
// Employee Contracts page (api/change_contract_status.php); this card is
// read-only + a Renew shortcut.
$can_view_contracts = canView('employee_contracts');
$emp_contracts = [];
if ($can_view_contracts) {
    $ec_stmt = $pdo->prepare("
        SELECT ec.*, DATEDIFF(ec.end_date, CURDATE()) AS days_to_expiry
        FROM employee_contracts ec
        WHERE ec.employee_id = ? AND ec.status != 'deleted'
        ORDER BY ec.created_at DESC
    ");
    $ec_stmt->execute([$employee_id]);
    $emp_contracts = $ec_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Performance — appraisals (Tier 3, Phase 3.3) — latest approved + history
$can_view_performance = canView('hr_performance');
$latest_appraisal = null;
$appraisal_history = [];
if ($can_view_performance) {
    $pa_stmt = $pdo->prepare("
        SELECT a.appraisal_id, a.overall_rating, a.appraisal_date, a.status,
               c.cycle_name, au.username AS approved_by_name
        FROM employee_appraisals a
        LEFT JOIN appraisal_cycles c ON c.cycle_id = a.cycle_id
        LEFT JOIN users au ON au.user_id = a.approved_by
        WHERE a.employee_id = ? AND a.status = 'approved'
        ORDER BY a.appraisal_date DESC, a.appraisal_id DESC
    ");
    $pa_stmt->execute([$employee_id]);
    $appraisal_history = $pa_stmt->fetchAll(PDO::FETCH_ASSOC);
    $latest_appraisal = $appraisal_history[0] ?? null;
}
// Active goals (Tier 3, Phase 3.4) — shown inside the Performance card
$active_goals = [];
if ($can_view_performance) {
    $ag_stmt = $pdo->prepare("
        SELECT goal_id, subject, progress, end_date, status,
               DATEDIFF(end_date, CURDATE()) AS days_to_due
        FROM employee_goals
        WHERE employee_id = ? AND status IN ('not_started','in_progress')
        ORDER BY end_date ASC
    ");
    $ag_stmt->execute([$employee_id]);
    $active_goals = $ag_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Active checklists (Tier 4, Phase 4.4)
$can_view_checklists = canView('hr_checklists');
$can_edit_checklists = canEdit('hr_checklists');
$emp_checklists = [];
if ($can_view_checklists) {
    $cl_stmt = $pdo->prepare("
        SELECT ec.checklist_id, ec.checklist_type, ec.status,
               (SELECT COUNT(*) FROM employee_checklist_items i WHERE i.checklist_id=ec.checklist_id) AS total,
               (SELECT COUNT(*) FROM employee_checklist_items i WHERE i.checklist_id=ec.checklist_id AND i.is_done=1) AS done
        FROM employee_checklists ec
        WHERE ec.employee_id = ? AND ec.status = 'in_progress'
        ORDER BY ec.created_at DESC
    ");
    $cl_stmt->execute([$employee_id]);
    $emp_checklists = $cl_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Trips + upcoming meetings (Tier 4, Phase 4.3)
$can_view_trips = canView('employee_trips');
$emp_trips = [];
if ($can_view_trips) {
    $tr_stmt = $pdo->prepare("SELECT trip_id, destination, start_date, end_date, status FROM employee_trips
                               WHERE employee_id = ? AND status != 'deleted' ORDER BY start_date DESC LIMIT 5");
    $tr_stmt->execute([$employee_id]);
    $emp_trips = $tr_stmt->fetchAll(PDO::FETCH_ASSOC);
}
$can_view_meetings = canView('meetings');
$emp_meetings = [];
if ($can_view_meetings) {
    $mt_stmt = $pdo->prepare("SELECT m.meeting_id, m.title, m.meeting_date, m.start_time
                               FROM meeting_attendees ma JOIN meetings m ON m.meeting_id = ma.meeting_id
                               WHERE ma.employee_id = ? AND m.status = 'scheduled' AND m.meeting_date >= CURDATE()
                               ORDER BY m.meeting_date ASC LIMIT 5");
    $mt_stmt->execute([$employee_id]);
    $emp_meetings = $mt_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Training history (Tier 3, Phase 3.5)
$can_view_training = canView('trainings');
$training_history = [];
if ($can_view_training) {
    $th_stmt = $pdo->prepare("
        SELECT p.participant_id, p.status AS part_status, p.certificate_path, p.certificate_expire_date,
               DATEDIFF(p.certificate_expire_date, CURDATE()) AS cert_days_left,
               t.title, t.start_date, t.end_date, tt.type_name
        FROM training_participants p
        JOIN trainings t ON t.training_id = p.training_id AND t.status != 'deleted'
        LEFT JOIN training_types tt ON tt.training_type_id = t.training_type_id
        WHERE p.employee_id = ?
        ORDER BY t.start_date DESC
    ");
    $th_stmt->execute([$employee_id]);
    $training_history = $th_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Direct Reports (Tier 2, Phase 2.4) — employees whose reporting_to_id points here
$dr_stmt = $pdo->prepare("SELECT employee_id, first_name, last_name FROM employees
                           WHERE reporting_to_id = ? AND (status IS NULL OR status != 'deleted')
                           ORDER BY first_name, last_name");
$dr_stmt->execute([$employee_id]);
$direct_reports = $dr_stmt->fetchAll(PDO::FETCH_ASSOC);

// Service Record (Tier 1, Phase 1.5) — this employee's lifecycle timeline,
// newest first. Old/new ids resolved via LEFT JOIN like the rest of the page.
$can_create_lifecycle = canCreate('employee_lifecycle');
$can_view_lifecycle   = canView('employee_lifecycle');
$sr_events = [];
if ($can_view_lifecycle) {
    $sr_stmt = $pdo->prepare("
        SELECT ele.*,
               od.designation_name AS old_designation_name, nd.designation_name AS new_designation_name,
               odp.department_name AS old_department_name, ndp.department_name AS new_department_name,
               op.project_name AS old_project_name, np.project_name AS new_project_name,
               cu.username AS created_by_name, au.username AS approved_by_name
        FROM employee_lifecycle_events ele
        LEFT JOIN designations od ON od.designation_id = ele.old_designation_id
        LEFT JOIN designations nd ON nd.designation_id = ele.new_designation_id
        LEFT JOIN departments odp ON odp.department_id = ele.old_department_id
        LEFT JOIN departments ndp ON ndp.department_id = ele.new_department_id
        LEFT JOIN projects op ON op.project_id = ele.old_project_id
        LEFT JOIN projects np ON np.project_id = ele.new_project_id
        LEFT JOIN users cu ON cu.user_id = ele.created_by
        LEFT JOIN users au ON au.user_id = ele.approved_by
        WHERE ele.employee_id = ? AND ele.status != 'deleted'
        ORDER BY ele.event_date DESC, ele.event_id DESC
    ");
    $sr_stmt->execute([$employee_id]);
    $sr_events = $sr_stmt->fetchAll(PDO::FETCH_ASSOC);
}
// icon + colour per event type (blue-scale per §UI-1; red only for termination)
$sr_meta = [
    'promotion'   => ['bi-arrow-up-circle',  '#0d6efd'],
    'demotion'    => ['bi-arrow-down-circle','#6c757d'],
    'transfer'    => ['bi-arrow-left-right', '#084298'],
    'award'       => ['bi-trophy',           '#1e3a8a'],
    'warning'     => ['bi-exclamation-triangle', '#495057'],
    'complaint'   => ['bi-chat-left-text',   '#495057'],
    'resignation' => ['bi-box-arrow-right',  '#6c757d'],
    'termination' => ['bi-x-octagon',        '#dc3545'],
];
$sr_status_badge = [
    'pending'   => ['#e9ecef', '#495057'],
    'approved'  => ['#0d6efd', '#fff'],
    'rejected'  => ['#dc3545', '#fff'],
    'cancelled' => ['#6c757d', '#fff'],
];
?>

<style>
#employeeExtrasTabs .nav-link {
    border: 1px solid #dee2e6;
    color: #495057;
    border-radius: 6px;
    font-size: 0.82rem;
    padding: 6px 14px;
    white-space: nowrap;
}
#employeeExtrasTabs .nav-link.active,
#employeeExtrasTabs .nav-link:hover {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}
@media (max-width: 576px) {
    #employeeExtrasTabs .nav-link { font-size: 0.75rem; padding: 5px 10px; }
}

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
    <?php if ($proj_ctx_id > 0): ?>
    <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 px-3 mb-3 d-print-none">
        <span class="small mb-0"><i class="bi bi-diagram-3 me-1"></i>Viewing within project: <strong><?= safe_output($proj_ctx_name) ?></strong></span>
        <a href="<?= htmlspecialchars($proj_ctx_return) ?>" class="btn btn-outline-primary btn-sm text-nowrap">
            <i class="bi bi-arrow-left me-1"></i> Back to Project
        </a>
    </div>
    <?php endif; ?>
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
            <div class="d-flex gap-2 d-print-none">
                <?php if ($can_create_lifecycle): ?>
                <div class="dropdown">
                    <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-lines-fill me-1"></i> HR Action
                    </button>
                    <ul class="dropdown-menu shadow border-0 p-2">
                        <li><button class="dropdown-item py-2 rounded" onclick="openLifecycleModal('promotion')"><i class="bi bi-arrow-up-circle text-primary me-2"></i> Promote</button></li>
                        <li><button class="dropdown-item py-2 rounded" onclick="openLifecycleModal('transfer')"><i class="bi bi-arrow-left-right text-primary me-2"></i> Transfer</button></li>
                        <li><button class="dropdown-item py-2 rounded" onclick="openLifecycleModal('award')"><i class="bi bi-trophy text-primary me-2"></i> Award</button></li>
                        <li><button class="dropdown-item py-2 rounded" onclick="openLifecycleModal('warning')"><i class="bi bi-exclamation-triangle text-primary me-2"></i> Warn</button></li>
                        <li><button class="dropdown-item py-2 rounded" onclick="openLifecycleModal('complaint')"><i class="bi bi-chat-left-text text-primary me-2"></i> Record Complaint</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><button class="dropdown-item py-2 rounded" onclick="openLifecycleModal('resignation')"><i class="bi bi-box-arrow-right me-2"></i> Resignation</button></li>
                        <li><button class="dropdown-item py-2 rounded text-danger" onclick="openLifecycleModal('termination')"><i class="bi bi-x-octagon text-danger me-2"></i> Termination</button></li>
                    </ul>
                </div>
                <?php endif; ?>
                <button onclick="printEmployeeReport()" class="btn btn-info text-white shadow-sm">
                    <i class="bi bi-printer"></i> Print Employee Profile
                </button>
            </div>
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
                    
                    <h4 class="card-title mb-1"><?= safe_output(implode(' ', array_filter([$employee['first_name'], $employee['middle_name'] ?? '', $employee['last_name']]))) ?></h4>
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
                        <a href="javascript:void(0)" onclick="showEmpTab('pane-attendance')" class="btn btn-outline-primary d-print-none">
                            <i class="bi bi-clock"></i> View Attendance
                        </a>
                        <a href="javascript:void(0)" onclick="showEmpTab('pane-payroll')" class="btn btn-outline-info d-print-none">
                            <i class="bi bi-cash-stack"></i> View Payroll
                        </a>
                        <a href="javascript:void(0)" onclick="showEmpTab('pane-leave')" class="btn btn-outline-secondary d-print-none">
                            <i class="bi bi-calendar-week"></i> View Leave
                        </a>
                    </div>
                </div>
                <div class="card-footer bg-light p-3">
                    <div class="row text-center">
                        <div class="col-3 border-end">
                            <h5 class="mb-0"><?= $employee['total_attendance'] ?></h5>
                            <small class="text-muted">Attendance</small>
                        </div>
                        <div class="col-3 border-end">
                            <h5 class="mb-0"><?= $employee['total_leaves'] ?></h5>
                            <small class="text-muted">Leaves</small>
                        </div>
                        <div class="col-3 border-end">
                            <h5 class="mb-0"><?= (int)$employee['total_awards'] ?></h5>
                            <small class="text-muted">Awards</small>
                        </div>
                        <div class="col-3">
                            <h5 class="mb-0"><?= (int)$employee['total_warnings'] ?></h5>
                            <small class="text-muted">Warnings</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Direct Reports (Tier 2, Phase 2.4) -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-people text-primary"></i> Direct Reports</h5>
                    <span class="badge bg-primary"><?= count($direct_reports) ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($direct_reports)): ?>
                    <p class="text-muted mb-0 small">No direct reports.</p>
                    <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($direct_reports as $dr): ?>
                        <li class="d-flex align-items-center mb-2">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2" style="width:32px;height:32px;font-size:.75rem;font-weight:600;flex-shrink:0;">
                                <?= strtoupper(substr($dr['first_name'], 0, 1) . substr($dr['last_name'], 0, 1)) ?>
                            </div>
                            <a href="<?= getUrl('employee_details') ?>?id=<?= (int)$dr['employee_id'] ?>" class="text-decoration-none text-dark">
                                <?= safe_output(trim($dr['first_name'] . ' ' . $dr['last_name'])) ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
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
                        <?php if (!empty($employee['alternate_phone'])): ?>
                        <li class="mb-3">
                            <i class="bi bi-telephone-plus text-primary me-2"></i>
                            <strong>Alternate Phone:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['alternate_phone']) ?></span>
                        </li>
                        <?php endif; ?>
                        <li class="mb-3">
                            <i class="bi bi-geo-alt text-primary me-2"></i>
                            <strong>Address:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['physical_address'] ?? $employee['address']) ?></span>
                        </li>
                        <?php if (!empty($employee['postal_address'])): ?>
                        <li class="mb-3">
                            <i class="bi bi-mailbox text-primary me-2"></i>
                            <strong>Postal Address:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['postal_address']) ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($employee['country'])): ?>
                        <li class="mb-3">
                            <i class="bi bi-globe text-primary me-2"></i>
                            <strong>Country:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['country']) ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($employee['state'])): ?>
                        <li class="mb-3">
                            <i class="bi bi-globe text-primary me-2"></i>
                            <strong>Region:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['state']) ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($employee['city'])): ?>
                        <li class="mb-3">
                            <i class="bi bi-globe text-primary me-2"></i>
                            <strong>District:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['city']) ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($employee['ward'])): ?>
                        <li class="mb-3">
                            <i class="bi bi-globe text-primary me-2"></i>
                            <strong>Ward:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['ward']) ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($employee['village'])): ?>
                        <li class="mb-3">
                            <i class="bi bi-globe text-primary me-2"></i>
                            <strong>Street/Village:</strong><br>
                            <span class="ms-4"><?= safe_output($employee['village']) ?></span>
                        </li>
                        <?php endif; ?>
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
                            <label class="text-muted small text-uppercase">Employment Type</label>
                            <p class="fw-bold"><?= safe_output($employee['employment_type'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Join Date</label>
                            <p class="fw-bold"><?= !empty($employee['hire_date']) ? date('M d, Y', strtotime($employee['hire_date'])) : '-' ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Reporting To</label>
                            <p class="fw-bold"><?= safe_output($employee['reporting_to'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Work Location</label>
                            <p class="fw-bold"><?= safe_output($employee['work_location'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Project</label>
                            <p class="fw-bold"><?= safe_output($employee['project_name'] ?? 'N/A') ?></p>
                        </div>
                        <?php if ($employee['employment_status'] === 'probation' && !empty($employee['probation_end_date'])): ?>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Probation End Date</label>
                            <p class="fw-bold"><?= date('M d, Y', strtotime($employee['probation_end_date'])) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee['employment_status'] === 'contract' && !empty($employee['contract_end_date'])): ?>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Contract End Date</label>
                            <p class="fw-bold"><?= date('M d, Y', strtotime($employee['contract_end_date'])) ?></p>
                        </div>
                        <?php endif; ?>

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
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Marital Status</label>
                            <p class="fw-bold"><?= !empty($employee['marital_status']) ? ucfirst($employee['marital_status']) : 'N/A' ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Passport Number</label>
                            <p class="fw-bold"><?= safe_output($employee['passport_number'] ?? 'N/A') ?></p>
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
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Bank Branch</label>
                            <p class="fw-bold"><?= safe_output($employee['bank_branch'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Mobile Money</label>
                            <p class="fw-bold"><?= safe_output($employee['mobile_money'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                </div>
                </div>
            </div>

            <!-- Compensation & Payment -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-cash-coin text-success me-1"></i> Compensation &amp; Payment</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Hourly Rate</label>
                            <p class="fw-bold"><?= !empty($employee['hourly_rate']) ? format_currency($employee['hourly_rate']) : 'N/A' ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Currency</label>
                            <p class="fw-bold"><?= safe_output($employee['currency'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Payment Frequency</label>
                            <p class="fw-bold"><?= !empty($employee['payment_frequency']) ? ucfirst(str_replace('_', ' ', $employee['payment_frequency'])) : 'N/A' ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Payment Method</label>
                            <p class="fw-bold"><?= !empty($employee['payment_method']) ? ucfirst(str_replace('_', ' ', $employee['payment_method'])) : 'N/A' ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Tax ID (TIN)</label>
                            <p class="fw-bold"><?= safe_output($employee['tax_id'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="text-muted small text-uppercase">Social Security Number</label>
                            <p class="fw-bold"><?= safe_output($employee['social_security_number'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-12">
                            <label class="text-muted small text-uppercase">Benefits</label>
                            <p class="fw-bold">
                                <?php
                                $benefits = !empty($employee['benefits']) ? json_decode($employee['benefits'], true) : [];
                                if (is_array($benefits) && count($benefits)):
                                    foreach ($benefits as $b): ?>
                                        <span class="badge bg-success me-1"><?= ucfirst(str_replace('_', ' ', $b)) ?></span>
                                    <?php endforeach;
                                else: ?>
                                    <span class="text-muted fw-normal">None</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

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

            <!-- Everything below this line is NOT part of the employee's registration record —
                 it's a separate log/module (history, uploads, HR actions) that accumulates over
                 time. Tabbed so the page shows one thing at a time instead of a wall of tables,
                 and entirely d-print-none: none of it — including Documents — belongs on a
                 printed employee profile. -->
            <ul class="nav nav-pills mb-3 d-print-none flex-wrap" id="employeeExtrasTabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pane-service" type="button" role="tab"><i class="bi bi-clock-history me-1"></i> Service Record</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-salary" type="button" role="tab"><i class="bi bi-sliders me-1"></i> Salary Structure</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-documents" type="button" role="tab"><i class="bi bi-folder2-open me-1"></i> Documents</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-contracts" type="button" role="tab"><i class="bi bi-file-earmark-text me-1"></i> Contracts</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-performance" type="button" role="tab"><i class="bi bi-graph-up-arrow me-1"></i> Performance</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-training" type="button" role="tab"><i class="bi bi-mortarboard me-1"></i> Training</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-onboarding" type="button" role="tab"><i class="bi bi-check2-square me-1"></i> Onboarding</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-meetings" type="button" role="tab"><i class="bi bi-calendar-event me-1"></i> Meetings &amp; Trips</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-notes" type="button" role="tab"><i class="bi bi-sticky me-1"></i> Notes</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-attendance" type="button" role="tab"><i class="bi bi-calendar-check me-1"></i> Attendance</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-payroll" type="button" role="tab"><i class="bi bi-cash-stack me-1"></i> Payroll</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-leave" type="button" role="tab"><i class="bi bi-calendar-week me-1"></i> Leave</button></li>
            </ul>
            <div class="tab-content d-print-none">
            <div class="tab-pane fade show active" id="pane-service" role="tabpanel">
            <?php if ($can_view_lifecycle): ?>
            <!-- Service Record (Tier 1 — lifecycle timeline) -->
            <div class="card shadow-sm mb-4" id="serviceRecordCard">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-clock-history text-primary me-1"></i> Service Record</h5>
                    <span class="badge" style="background:#e7f0ff;color:#084298;border:1px solid #b6ccfe"><?= count($sr_events) ?> event<?= count($sr_events) === 1 ? '' : 's' ?></span>
                </div>
                <div class="card-body">
                    <?php if (!$sr_events): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-clock-history" style="font-size:2rem;"></i>
                        <p class="mb-0 mt-2">No service record entries yet. Promotions, transfers, awards and other HR actions will appear here once recorded.</p>
                    </div>
                    <?php else: ?>
                    <div class="sr-timeline">
                        <?php foreach ($sr_events as $ev):
                            [$icon, $color] = $sr_meta[$ev['event_type']] ?? ['bi-tag', '#495057'];
                            [$sbg, $sfg] = $sr_status_badge[$ev['status']] ?? ['#e9ecef', '#495057'];
                            // old→new line per type
                            $change = '';
                            switch ($ev['event_type']) {
                                case 'promotion':
                                case 'demotion':
                                    $change = safe_output($ev['old_designation_name'], '—') . ' → ' . safe_output($ev['new_designation_name'], '—');
                                    if ($ev['new_salary'] !== null) $change .= ' · salary ' . number_format((float)$ev['old_salary'], 0) . ' → ' . number_format((float)$ev['new_salary'], 0);
                                    break;
                                case 'transfer':
                                    $bits = [];
                                    if ($ev['new_department_id']) $bits[] = safe_output($ev['old_department_name'], '—') . ' → ' . safe_output($ev['new_department_name'], '—');
                                    if ($ev['new_project_id']) $bits[] = safe_output($ev['old_project_name'], 'No project') . ' → ' . safe_output($ev['new_project_name'], '—');
                                    $change = implode(' · ', $bits);
                                    break;
                                case 'award':       $change = safe_output($ev['award_type'], '—') . ($ev['award_amount'] ? ' · ' . number_format((float)$ev['award_amount'], 0) : ''); break;
                                case 'warning':     $change = $ev['severity'] ? ucfirst($ev['severity']) . ' warning' : ''; break;
                                case 'complaint':   $change = 'By: ' . safe_output($ev['complainant'], '—'); break;
                                case 'resignation': $change = 'Last working day: ' . safe_output($ev['end_date'], '—'); break;
                                case 'termination': $change = safe_output($ev['termination_type'], '—'); break;
                            }
                        ?>
                        <div class="d-flex gap-3 pb-3 mb-1 border-start ms-2 ps-3 position-relative" style="border-color:#b6ccfe!important;">
                            <span class="position-absolute rounded-circle d-flex align-items-center justify-content-center"
                                  style="left:-14px;top:0;width:26px;height:26px;background:<?= $color ?>;color:#fff;font-size:.8rem;">
                                <i class="bi <?= $icon ?>"></i>
                            </span>
                            <div class="flex-grow-1 ms-2">
                                <div class="d-flex justify-content-between flex-wrap gap-1">
                                    <strong><?= safe_output($ev['title']) ?></strong>
                                    <span>
                                        <span class="badge" style="background:<?= $sbg ?>;color:<?= $sfg ?>"><?= ucfirst($ev['status']) ?></span>
                                        <small class="text-muted ms-1"><?= date('d M Y', strtotime($ev['event_date'])) ?></small>
                                    </span>
                                </div>
                                <?php if ($change): ?><div class="small text-muted"><?= $change ?></div><?php endif; ?>
                                <?php if (!empty($ev['description'])): ?>
                                <div class="small mt-1"><?= safe_output($ev['description']) ?></div>
                                <?php endif; ?>
                                <div class="small text-muted mt-1">
                                    Recorded by <?= safe_output($ev['created_by_name'], '—') ?>
                                    <?php if ($ev['approved_by_name']): ?>
                                        · <?= $ev['status'] === 'rejected' ? 'Rejected' : 'Approved' ?> by <?= safe_output($ev['approved_by_name']) ?><?= $ev['approved_at'] ? ' on ' . date('d M Y', strtotime($ev['approved_at'])) : '' ?>
                                    <?php endif; ?>
                                    <?php if (!empty($ev['reject_reason'])): ?>
                                        · <span class="text-danger">Reason: <?= safe_output($ev['reject_reason']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($ev['attachment_path'])): ?>
                                        · <a href="<?= buildUrl('api/download_lifecycle_attachment.php') ?>?event_id=<?= (int)$ev['event_id'] ?>" class="d-print-none"><i class="bi bi-paperclip"></i> <?= safe_output($ev['attachment_name'], 'Attachment') ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-salary" role="tabpanel">

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
                        <table class="table table-sm align-middle mb-0" id="salaryStructureTable">
                            <thead class="table-light">
                                <tr><th class="ps-3 no-sort">S/NO</th><th>Component</th><th>Type</th><th>Basis</th><th class="text-end">Value</th><th class="text-end pe-3 d-print-none no-sort">Action</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$sc_rows): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No components assigned. Payroll will use this employee's basic salary (and any legacy allowances/deductions).</td></tr>
                                <?php else: $sn = 1; foreach ($sc_rows as $r):
                                    $val = ($r['calculation_type'] === 'percentage') ? round($basic * (float)$r['amount'] / 100, 2) : (float)$r['amount'];
                                    $isDed = $r['component_type'] === 'deduction'; ?>
                                <tr>
                                    <td class="ps-3"><?= $sn++ ?></td>
                                    <td class="fw-semibold"><?= safe_output($r['component_name']) ?></td>
                                    <td><span class="badge-status" style="background:<?= $isDed ? '#dc3545' : '#0d6efd' ?>;color:#fff;font-size:.62rem;padding:.3em .55em;border-radius:6px;"><?= strtoupper($r['component_type']) ?></span></td>
                                    <td class="small"><?= $r['calculation_type'] === 'percentage' ? number_format((float)$r['amount'], 2) . '% of basic' : 'Fixed' ?></td>
                                    <td class="text-end <?= $isDed ? 'text-danger' : '' ?>"><?= ($isDed ? '−' : '') . number_format($val, 2) ?></td>
                                    <td class="text-end pe-3 d-print-none">
                                        <?php if ($can_edit_salary): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill text-primary"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2">
                                                <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="removeComponent(<?= (int)$r['employee_component_id'] ?>)"><i class="bi bi-trash me-2"></i> Remove</a></li>
                                            </ul>
                                        </div>
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
            <div class="modal fade" id="assignCompModal" tabindex="-1" aria-hidden="true" data-bs-focus="false">
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
            </div>
            <div class="tab-pane fade" id="pane-documents" role="tabpanel">

            <!-- Documents Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Employee Documents</h5>
                    <?php if ($can_create_documents): ?>
                    <button class="btn btn-sm btn-primary d-print-none" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                        <i class="bi bi-cloud-upload me-1"></i> Upload Document
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($can_view_documents && !empty($emp_documents)): ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle" id="empDocsTable">
                                <thead>
                                    <tr>
                                        <th class="no-sort">S/NO</th><th>Type</th><th>Name</th><th>Issued</th><th>Expires</th><th></th>
                                        <th class="text-end d-print-none no-sort">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $sn = 1; foreach ($emp_documents as $d):
                                    $chip = '';
                                    if (!empty($d['expire_date'])) {
                                        $dte = (int)$d['days_to_expiry'];
                                        if ($dte < 0)        $chip = '<span class="badge bg-danger">Expired</span>';
                                        elseif ($dte <= 30)  $chip = '<span class="badge bg-warning text-dark">' . $dte . 'd left</span>';
                                        else                 $chip = '<span class="badge bg-success">Valid</span>';
                                    }
                                ?>
                                    <tr>
                                        <td><?= $sn++ ?></td>
                                        <td><?= safe_output($d['type_name']) ?></td>
                                        <td><?= safe_output($d['document_name']) ?></td>
                                        <td><?= safe_output($d['issue_date'], '—') ?></td>
                                        <td><?= safe_output($d['expire_date'], '—') ?></td>
                                        <td><?= $chip ?></td>
                                        <td class="text-end d-print-none">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light border shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-gear-fill text-primary"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2">
                                                    <li><a class="dropdown-item py-2" href="<?= buildUrl('api/download_employee_document.php') ?>?emp_doc_id=<?= (int)$d['emp_doc_id'] ?>" target="_blank"><i class="bi bi-download text-primary me-2"></i> Download</a></li>
                                                    <?php if ($can_delete_documents): ?>
                                                    <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteEmpDoc(<?= (int)$d['emp_doc_id'] ?>)"><i class="bi bi-trash me-2"></i> Delete</a></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

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
                        foreach ($doc_labels as $key => $info) {
                            if (isset($docs[$key])) { $has_docs = true; break; }
                        }
                        if ($has_docs):
                        ?>
                        <div class="col-12 d-print-none">
                            <p class="text-muted small mb-2"><i class="bi bi-archive me-1"></i> Legacy files</p>
                        </div>
                        <?php
                        endif;
                        foreach ($doc_labels as $key => $info):
                            if (isset($docs[$key])):
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

                        if (!$has_docs && !($can_view_documents && !empty($emp_documents))): ?>
                        <div class="col-12 text-center py-4">
                            <p class="text-muted mb-0"><i class="bi bi-file-earmark-x me-1"></i> No documents uploaded for this employee.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($can_create_documents): ?>
            <!-- Upload Employee Document Modal (Tier 2, Phase 2.2) -->
            <div class="modal fade" id="uploadDocModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="bi bi-cloud-upload me-1"></i> Upload Document</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="uploadDocForm" autocomplete="off">
                            <div class="modal-body">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
                                <div id="upload-doc-message" class="mb-2"></div>
                                <div class="mb-3">
                                    <label class="form-label">Document Type <span class="text-danger">*</span></label>
                                    <select name="doc_type_id" id="upload_doc_type_id" class="form-select select2-static" required>
                                        <option value=""></option>
                                        <?php foreach ($doc_types as $dt): ?>
                                        <option value="<?= (int)$dt['doc_type_id'] ?>" data-requires-expiry="<?= (int)$dt['requires_expiry'] ?>">
                                            <?= safe_output($dt['type_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Document Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="document_name" required>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <label class="form-label">Issue Date</label>
                                        <input type="date" class="form-control" name="issue_date">
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label class="form-label" id="upload_expiry_label">Expiry Date</label>
                                        <input type="date" class="form-control" name="expire_date" id="upload_expire_date">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" name="notes" rows="2"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">File <span class="text-danger">*</span></label>
                                    <input type="file" class="form-control" name="file" required>
                                    <div class="form-text">PDF, Word, Excel or image. Max 10MB.</div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Upload</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-contracts" role="tabpanel">

            <?php if ($can_view_contracts): ?>
            <!-- Contracts Card (Tier 2, Phase 2.3) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Contracts</h5>
                    <a href="<?= getUrl('employee_contracts') ?>" class="btn btn-sm btn-outline-primary d-print-none">
                        <i class="bi bi-arrow-repeat me-1"></i> Manage Contracts
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($emp_contracts)): ?>
                    <p class="text-muted mb-0"><i class="bi bi-file-earmark-x me-1"></i> No contracts recorded for this employee.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="empContractsTable">
                            <thead>
                                <tr><th class="no-sort">S/NO</th><th>Type</th><th>Start</th><th>End</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php $sn = 1; foreach ($emp_contracts as $c):
                                $isActive = ($c['status'] === 'active');
                                $chip = '';
                                if ($isActive && !empty($c['end_date'])) {
                                    $dte = (int)$c['days_to_expiry'];
                                    if ($dte < 0)       $chip = '<span class="badge bg-danger">Expired</span>';
                                    elseif ($dte <= 60) $chip = '<span class="badge bg-warning text-dark">' . $dte . 'd left</span>';
                                }
                                $status_colors = ['draft' => 'secondary', 'active' => 'primary', 'expired' => 'danger', 'renewed' => 'secondary', 'terminated' => 'danger'];
                                $status_color = $status_colors[$c['status']] ?? 'secondary';
                            ?>
                                <tr <?= $isActive ? 'class="table-primary"' : '' ?>>
                                    <td><?= $sn++ ?></td>
                                    <td><?= safe_output($c['contract_type']) ?></td>
                                    <td><?= safe_output($c['start_date']) ?></td>
                                    <td><?= safe_output($c['end_date'], 'Open-ended') ?> <?= $chip ?></td>
                                    <td><span class="badge bg-<?= $status_color ?>"><?= ucfirst($c['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-performance" role="tabpanel">

            <?php if ($can_view_performance): ?>
            <!-- Performance Card (Tier 3, Phase 3.3) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-graph-up-arrow text-primary me-1"></i> Performance</h5>
                    <a href="<?= getUrl('hr_performance') ?>" class="btn btn-sm btn-outline-primary d-print-none">
                        <i class="bi bi-clipboard-check me-1"></i> Appraisals
                    </a>
                </div>
                <div class="card-body">
                    <?php
                    $starRow = function ($v) {
                        $v = (int)round($v);
                        $h = '';
                        for ($i = 1; $i <= 5; $i++) $h .= '<span style="color:' . ($i <= $v ? '#f5b301' : '#ced4da') . '">&#9733;</span>';
                        return $h;
                    };
                    ?>
                    <?php if (!$latest_appraisal): ?>
                    <p class="text-muted mb-0"><i class="bi bi-clipboard-x me-1"></i> No approved appraisals yet.</p>
                    <?php else: ?>
                    <div class="mb-3 p-3 rounded" style="background:#e7f0ff;border:1px solid #b6ccfe">
                        <div class="d-flex justify-content-between align-items-start flex-wrap">
                            <div>
                                <div class="small text-muted">Latest — <?= safe_output($latest_appraisal['cycle_name']) ?></div>
                                <div class="fs-5"><?= $starRow($latest_appraisal['overall_rating']) ?>
                                    <strong class="ms-1"><?= number_format((float)$latest_appraisal['overall_rating'], 2) ?>/5</strong></div>
                            </div>
                            <div class="small text-muted text-end">
                                <?= safe_output($latest_appraisal['appraisal_date']) ?><br>
                                <?php if (!empty($latest_appraisal['approved_by_name'])): ?>Approved by <?= safe_output($latest_appraisal['approved_by_name']) ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if (count($appraisal_history) > 1): ?>
                    <div class="small text-muted mb-1">History</div>
                    <ul class="list-unstyled mb-0">
                        <?php foreach (array_slice($appraisal_history, 1) as $h): ?>
                        <li class="d-flex justify-content-between py-1 border-bottom">
                            <span><?= safe_output($h['cycle_name']) ?></span>
                            <span><?= $starRow($h['overall_rating']) ?> <small class="text-muted"><?= number_format((float)$h['overall_rating'], 2) ?></small></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($active_goals)): ?>
                    <hr>
                    <div class="small text-muted mb-2"><i class="bi bi-flag me-1"></i> Active Goals</div>
                    <?php foreach ($active_goals as $g):
                        $g_overdue = (int)$g['days_to_due'] < 0;
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small">
                            <span><?= safe_output($g['subject']) ?><?php if ($g_overdue): ?> <span class="badge bg-danger">Overdue</span><?php endif; ?></span>
                            <span class="text-muted"><?= (int)$g['progress'] ?>%</span>
                        </div>
                        <div class="progress" style="height:8px">
                            <div class="progress-bar <?= $g_overdue ? 'bg-danger' : 'bg-primary' ?>" style="width:<?= (int)$g['progress'] ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-training" role="tabpanel">

            <?php if ($can_view_training): ?>
            <!-- Training Card (Tier 3, Phase 3.5) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-mortarboard text-primary me-1"></i> Training</h5>
                    <a href="<?= getUrl('trainings') ?>" class="btn btn-sm btn-outline-primary d-print-none">
                        <i class="bi bi-mortarboard me-1"></i> All Trainings
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($training_history)): ?>
                    <p class="text-muted mb-0"><i class="bi bi-mortarboard me-1"></i> No training records yet.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="empTrainingTable">
                            <thead><tr><th class="no-sort">S/NO</th><th>Training</th><th>Type</th><th>Date</th><th>Result</th><th class="d-print-none no-sort">Certificate</th></tr></thead>
                            <tbody>
                            <?php $sn = 1; foreach ($training_history as $th):
                                $pmap = ['enrolled'=>'secondary','attended'=>'info','completed'=>'success','failed'=>'danger','withdrawn'=>'dark'];
                                $pcolor = $pmap[$th['part_status']] ?? 'secondary';
                                $cchip = '';
                                if (!empty($th['certificate_path']) && !empty($th['certificate_expire_date'])) {
                                    $cd = (int)$th['cert_days_left'];
                                    if ($cd < 0) $cchip = '<span class="badge bg-danger">Expired</span>';
                                    elseif ($cd <= 30) $cchip = '<span class="badge bg-warning text-dark">' . $cd . 'd</span>';
                                }
                            ?>
                                <tr>
                                    <td><?= $sn++ ?></td>
                                    <td><?= safe_output($th['title']) ?></td>
                                    <td><?= safe_output($th['type_name'], '—') ?></td>
                                    <td><?= safe_output($th['start_date']) ?></td>
                                    <td><span class="badge bg-<?= $pcolor ?>"><?= ucfirst($th['part_status']) ?></span></td>
                                    <td class="d-print-none">
                                        <?php if (!empty($th['certificate_path'])): ?>
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm btn-light border shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill text-primary"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2">
                                                <li><a class="dropdown-item py-2" href="<?= buildUrl('api/download_training_certificate.php') ?>?participant_id=<?= (int)$th['participant_id'] ?>" target="_blank"><i class="bi bi-download text-primary me-2"></i> Download Certificate</a></li>
                                            </ul>
                                        </div>
                                        <?= $cchip ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-onboarding" role="tabpanel">

            <?php if ($can_view_checklists && !empty($emp_checklists)): ?>
            <!-- Active Checklist Card (Tier 4, Phase 4.4) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-check2-square text-primary me-1"></i> Onboarding / Offboarding</h5>
                    <a href="<?= getUrl('hr_checklists') ?>" class="btn btn-sm btn-outline-primary d-print-none"><i class="bi bi-list-check me-1"></i> Manage</a>
                </div>
                <div class="card-body">
                    <?php foreach ($emp_checklists as $cl):
                        $pct = (int)$cl['total'] > 0 ? round((int)$cl['done'] / (int)$cl['total'] * 100) : 0;
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small">
                            <span><span class="badge bg-<?= $cl['checklist_type'] === 'onboarding' ? 'success' : 'secondary' ?>"><?= ucfirst($cl['checklist_type']) ?></span></span>
                            <span class="text-muted"><?= (int)$cl['done'] ?>/<?= (int)$cl['total'] ?></span>
                        </div>
                        <div class="progress" style="height:10px"><div class="progress-bar <?= $pct === 100 ? 'bg-success' : 'bg-primary' ?>" style="width:<?= $pct ?>%"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-meetings" role="tabpanel">

            <?php if (($can_view_meetings && !empty($emp_meetings)) || ($can_view_trips && !empty($emp_trips))): ?>
            <!-- Meetings & Trips Card (Tier 4, Phase 4.3) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-calendar-event text-primary me-1"></i> Meetings &amp; Trips</h5>
                </div>
                <div class="card-body">
                    <?php if ($can_view_meetings && !empty($emp_meetings)): ?>
                    <div class="small text-muted mb-1"><i class="bi bi-calendar-event me-1"></i> Upcoming meetings</div>
                    <ul class="list-unstyled mb-3">
                        <?php foreach ($emp_meetings as $m): ?>
                        <li class="d-flex justify-content-between py-1 border-bottom">
                            <span><?= safe_output($m['title']) ?></span>
                            <span class="text-muted small"><?= safe_output($m['meeting_date']) ?><?php if (!empty($m['start_time'])): ?> <?= safe_output(substr($m['start_time'], 0, 5)) ?><?php endif; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if ($can_view_trips && !empty($emp_trips)): ?>
                    <div class="small text-muted mb-1"><i class="bi bi-airplane me-1"></i> Trips</div>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($emp_trips as $t):
                            $tmap = ['pending'=>'warning','approved'=>'primary','completed'=>'success','rejected'=>'danger','cancelled'=>'secondary'];
                            $tc = $tmap[$t['status']] ?? 'secondary';
                        ?>
                        <li class="d-flex justify-content-between py-1 border-bottom">
                            <span><?= safe_output($t['destination']) ?> <small class="text-muted"><?= safe_output($t['start_date']) ?></small></span>
                            <span class="badge bg-<?= $tc ?>"><?= ucfirst($t['status']) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-notes" role="tabpanel">

            <?php $notes_text = trim(($employee['notes'] ?? '') . "\n" . ($employee['additional_notes'] ?? '')); ?>
            <?php if ($notes_text !== ''): ?>
            <!-- Notes Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-sticky text-warning me-1"></i> Notes</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($employee['notes'])): ?>
                    <p class="mb-2"><?= nl2br(safe_output($employee['notes'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($employee['additional_notes'])): ?>
                    <p class="mb-0 text-muted"><strong>Additional:</strong> <?= nl2br(safe_output($employee['additional_notes'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-attendance" role="tabpanel">

            <!-- Full Attendance History (all records, since day one) — attendance.php's
                 capture/history view only lists active employees, so this is the only
                 place to review an inactive employee's attendance log at all. -->
            <?php
                $attCountStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ?");
                $attCountStmt->execute([$employee_id]);
                $attTotal = (int)$attCountStmt->fetchColumn();
                $attLimit = 200;
                $stmt_att = $pdo->prepare("
                    SELECT attendance_date, check_in_time, check_out_time, total_hours, overtime_hours, status
                      FROM attendance
                     WHERE employee_id = ?
                  ORDER BY attendance_date DESC
                     LIMIT $attLimit
                ");
                $stmt_att->execute([$employee_id]);
                $all_attendance = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
                $attStatusBadge = function ($s) {
                    $map = [
                        'present'  => 'background:#0d6efd;color:#fff;',
                        'late'     => 'background:#cfe2ff;color:#084298;',
                        'half_day' => 'background:#bfdbfe;color:#1e3a8a;',
                        'absent'   => 'background:#dc3545;color:#fff;',
                        'leave'    => 'background:#6c757d;color:#fff;',
                        'holiday'  => 'background:#e9ecef;color:#495057;',
                        'weekend'  => 'background:#e9ecef;color:#495057;',
                    ];
                    $st = $map[$s] ?? 'background:#e9ecef;color:#495057;';
                    return '<span class="badge" style="' . $st . 'padding:5px 10px;border-radius:20px;">' . ucfirst(str_replace('_', ' ', $s ?: '—')) . '</span>';
                };
            ?>
            <div class="card shadow-sm mb-4" id="attendanceHistoryCard">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-calendar-check text-primary me-2"></i>Attendance History</h5>
                    <div class="d-flex align-items-center gap-3">
                        <span class="small text-muted">
                            <?= $attTotal ?> record(s) total<?= $attTotal > $attLimit ? " · showing most recent $attLimit" : '' ?>
                        </span>
                        <?php if ($can_mark_attendance): ?>
                        <button type="button" class="btn btn-sm btn-primary d-print-none" data-bs-toggle="modal" data-bs-target="#markEmployeeAttendanceModal">
                            <i class="bi bi-clock-history me-1"></i> Mark Attendance
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="attendanceHistoryTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3 no-sort">S/NO</th>
                                <th>Date</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th class="text-end">Hours</th>
                                <th class="text-end">Overtime</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($all_attendance) > 0):
                                $sn = 1;
                                foreach ($all_attendance as $att):
                            ?>
                            <tr>
                                <td class="ps-3"><?= $sn++ ?></td>
                                <td><?= date('d M Y', strtotime($att['attendance_date'])) ?></td>
                                <td><?= !empty($att['check_in_time']) ? date('h:i A', strtotime($att['check_in_time'])) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= !empty($att['check_out_time']) ? date('h:i A', strtotime($att['check_out_time'])) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end"><?= number_format((float)($att['total_hours'] ?? 0), 2) ?></td>
                                <td class="text-end text-muted"><?= number_format((float)($att['overtime_hours'] ?? 0), 2) ?></td>
                                <td><?= $attStatusBadge($att['status']) ?></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No attendance records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($can_mark_attendance): ?>
            <!-- Mark Attendance Modal — locked to this employee (no employee picker),
                 so this can never accidentally record another employee's attendance.
                 Posts to the existing api/mark_attendance.php, which already upserts
                 by employee_id + date (updates the day's record if one exists). -->
            <div class="modal fade" id="markEmployeeAttendanceModal" tabindex="-1" aria-hidden="true" data-bs-focus="false">
                <div class="modal-dialog">
                    <div class="modal-content border-0 shadow">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="bi bi-clock-history me-1"></i> Mark Attendance — <?= safe_output($employee['first_name'] . ' ' . $employee['last_name']) ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="markEmpAttendanceForm" autocomplete="off">
                            <div class="modal-body">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
                                <div id="mea-message" class="mb-2"></div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="attendance_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small fw-bold">Check In</label>
                                        <input type="time" class="form-control" name="check_in_time">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small fw-bold">Check Out</label>
                                        <input type="time" class="form-control" name="check_out_time">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Status <span class="text-danger">*</span></label>
                                    <select class="form-select" name="status" required>
                                        <option value="present" selected>Present</option>
                                        <option value="absent">Absent</option>
                                        <option value="late">Late</option>
                                        <option value="half_day">Half Day</option>
                                        <option value="leave">Leave</option>
                                        <option value="holiday">Holiday</option>
                                        <option value="weekend">Weekend</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Notes</label>
                                    <textarea class="form-control" name="notes" rows="2"></textarea>
                                </div>
                                <div class="form-text text-muted">If a record already exists for the selected date, it will be updated instead of duplicated.</div>
                            </div>
                            <div class="modal-footer bg-light border-0">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check-circle me-1"></i> Save</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            </div>
            <div class="tab-pane fade" id="pane-payroll" role="tabpanel">

            <!-- Full Payroll & Payment History (all records, since day one) -->
            <?php
                $stmt_pay = $pdo->prepare("
                    SELECT p.*, a.account_name AS paid_from_name
                      FROM payroll p
                      LEFT JOIN accounts a ON a.account_id = p.paid_from_account_id
                     WHERE p.employee_id = ?
                  ORDER BY p.payroll_period DESC, p.payroll_date DESC
                ");
                $stmt_pay->execute([$employee_id]);
                $all_payrolls = $stmt_pay->fetchAll(PDO::FETCH_ASSOC);
                $paid_total = 0.0;
                foreach ($all_payrolls as $pp) { if (($pp['payment_status'] ?? '') === 'paid') $paid_total += (float)$pp['net_salary']; }
                $statusBadge = function ($s) {
                    $map = [
                        'paid'       => 'background:#052c65;color:#fff;',
                        'approved'   => 'background:#0d6efd;color:#fff;',
                        'rejected'   => 'background:#dc3545;color:#fff;',
                        'cancelled'  => 'background:#6c757d;color:#fff;',
                    ];
                    $st = $map[$s] ?? 'background:#e9ecef;color:#495057;';
                    return '<span class="badge" style="' . $st . 'padding:5px 10px;border-radius:20px;">' . ucfirst($s ?: 'pending') . '</span>';
                };
            ?>
             <div class="card shadow-sm mb-4" id="payrollHistoryCard">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-cash-stack text-primary me-2"></i>Payroll &amp; Payment History</h5>
                    <span class="small text-muted"><?= count($all_payrolls) ?> record(s) · Paid to date: <strong><?= format_currency($paid_total) ?></strong></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="payrollHistoryTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3 no-sort">S/NO</th>
                                <th>Period</th>
                                <th>Date Paid</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">NSSF</th>
                                <th class="text-end">PAYE</th>
                                <th class="text-end">Net Salary</th>
                                <th>Status</th>
                                <th>Paid From</th>
                                <th class="d-print-none no-sort">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($all_payrolls) > 0):
                                $sn = 1;
                                foreach ($all_payrolls as $pay):
                            ?>
                            <tr>
                                <td class="ps-3"><?= $sn++ ?></td>
                                <td><?= date('F Y', strtotime($pay['payroll_period'] . '-01')) ?></td>
                                <td><?= !empty($pay['payment_date']) ? date('d M, Y', strtotime($pay['payment_date'])) : '<span class="text-muted">unpaid</span>' ?></td>
                                <td class="text-end"><?= format_currency($pay['gross_salary'] ?? 0) ?></td>
                                <td class="text-end text-muted"><?= format_currency($pay['nssf_employee'] ?? 0) ?></td>
                                <td class="text-end text-muted"><?= format_currency($pay['tax_amount'] ?? 0) ?></td>
                                <td class="text-end fw-bold"><?= format_currency($pay['net_salary']) ?></td>
                                <td><?= $statusBadge($pay['payment_status'] ?? 'pending') ?></td>
                                <td><?= !empty($pay['paid_from_name']) ? safe_output($pay['paid_from_name']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="d-print-none">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill text-primary"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2">
                                            <li><a class="dropdown-item py-2" href="<?= getUrl('payslip') ?>?id=<?= $pay['payroll_id'] ?>" target="_blank"><i class="bi bi-printer text-primary me-2"></i> Print Payslip</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="10" class="text-center text-muted py-3">No payroll records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
            <div class="tab-pane fade" id="pane-leave" role="tabpanel">

            <!-- Full Leave History (all records, since day one) -->
            <?php
                $stmt_leave = $pdo->prepare("
                    SELECT l.*, lt.type_name AS official_type
                      FROM leaves l
                      LEFT JOIN leave_types lt ON lt.type_id = l.leave_type_id
                     WHERE l.employee_id = ?
                  ORDER BY l.start_date DESC
                ");
                $stmt_leave->execute([$employee_id]);
                $all_leaves = $stmt_leave->fetchAll(PDO::FETCH_ASSOC);
                $leaveStatusBadge = function ($s) {
                    $map = [
                        'pending'   => 'background:#ffc107;color:#000;',
                        'approved'  => 'background:#198754;color:#fff;',
                        'rejected'  => 'background:#dc3545;color:#fff;',
                        'cancelled' => 'background:#6c757d;color:#fff;',
                        'taken'     => 'background:#0d6efd;color:#fff;',
                    ];
                    $st = $map[$s] ?? 'background:#e9ecef;color:#495057;';
                    return '<span class="badge" style="' . $st . 'padding:5px 10px;border-radius:20px;">' . ucfirst($s ?: 'pending') . '</span>';
                };
            ?>
            <div class="card shadow-sm mb-4" id="leaveHistoryCard">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-calendar-week text-primary me-2"></i>Leave History</h5>
                    <div class="d-flex align-items-center gap-3">
                        <span class="small text-muted"><?= count($all_leaves) ?> record(s) total</span>
                        <?php if ($can_apply_leave): ?>
                        <button type="button" class="btn btn-sm btn-primary d-print-none" data-bs-toggle="modal" data-bs-target="#applyEmpLeaveModal">
                            <i class="bi bi-plus-circle me-1"></i> Apply for Leave
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="leaveHistoryTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3 no-sort">S/NO</th>
                                <th>Type</th>
                                <th>Start</th>
                                <th>End</th>
                                <th class="text-end">Days</th>
                                <th>Status</th>
                                <th class="d-print-none no-sort">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($all_leaves) > 0):
                                $sn = 1;
                                foreach ($all_leaves as $lv):
                            ?>
                            <tr>
                                <td class="ps-3"><?= $sn++ ?></td>
                                <td><?= safe_output($lv['official_type'] ?? '', '—') ?></td>
                                <td><?= date('d M Y', strtotime($lv['start_date'])) ?></td>
                                <td><?= date('d M Y', strtotime($lv['end_date'])) ?></td>
                                <td class="text-end"><?= $lv['total_days'] ?></td>
                                <td><?= $leaveStatusBadge($lv['status']) ?></td>
                                <td class="d-print-none">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill text-primary"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2">
                                            <li><a class="dropdown-item py-2" href="<?= getUrl('leave_details') ?>?id=<?= $lv['leave_id'] ?>" target="_blank"><i class="bi bi-eye text-primary me-2"></i> View</a></li>
                                            <?php if ($can_approve_leaves && $lv['status'] === 'pending'): ?>
                                            <li><a class="dropdown-item py-2 text-success" href="javascript:void(0)" onclick="approveEmpLeave(<?= $lv['leave_id'] ?>)"><i class="bi bi-check-circle me-2"></i> Approve</a></li>
                                            <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="rejectEmpLeave(<?= $lv['leave_id'] ?>)"><i class="bi bi-x-circle me-2"></i> Reject</a></li>
                                            <?php endif; ?>
                                            <?php if ($can_delete_leaves): ?>
                                            <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteEmpLeave(<?= $lv['leave_id'] ?>)"><i class="bi bi-trash me-2"></i> Delete</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No leave records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($can_apply_leave): ?>
            <!-- Apply for Leave Modal — locked to this employee (a single hidden employee_id
                 field, no picker at all), so this can never file a leave application for
                 anyone else. Posts to the existing api/apply_leave.php; approve/reject/delete
                 below reuse api/approve_leave.php, api/reject_leave.php, api/delete_leave.php
                 verbatim from leaves.php. -->
            <div class="modal fade" id="applyEmpLeaveModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content border-0 shadow">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Apply for Leave — <?= safe_output($employee['first_name'] . ' ' . $employee['last_name']) ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="applyEmpLeaveForm" autocomplete="off">
                            <div class="modal-body">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
                                <div id="ael-message" class="mb-2"></div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small fw-bold">Leave Type <span class="text-danger">*</span></label>
                                        <select class="form-select select2-static" id="ael_leave_type" name="leave_type_id" required onchange="updateEmpLeaveTypeInfo()">
                                            <option value="">Select Type</option>
                                            <?php foreach ($leave_types as $type): ?>
                                            <option value="<?= (int)$type['type_id'] ?>"
                                                    data-max-days="<?= (int)$type['max_days_per_year'] ?>"
                                                    data-requires-doc="<?= (int)$type['requires_document'] ?>"
                                                    data-is-paid="<?= (int)$type['is_paid'] ?>">
                                                <?= safe_output($type['type_name']) ?> (Max: <?= (int)$type['max_days_per_year'] ?> days/year, <?= (int)$type['max_consecutive_days'] ?> consecutive)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="ael_paid_badge" class="mt-1"></div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label small fw-bold">Start Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="ael_start_date" name="start_date" required onchange="calculateEmpLeaveDays()">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label small fw-bold">End Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="ael_end_date" name="end_date" required onchange="calculateEmpLeaveDays()">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label small fw-bold">Total Days</label>
                                        <input type="number" class="form-control" id="ael_total_days" name="total_days" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label small fw-bold">Half Day</label>
                                        <select class="form-select" id="ael_half_day" name="half_day" onchange="calculateEmpLeaveDays()">
                                            <option value="none">No</option>
                                            <option value="first_half">First Half</option>
                                            <option value="second_half">Second Half</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label small fw-bold">Contact During Leave</label>
                                        <input type="text" class="form-control" name="contact_during_leave" placeholder="Phone number or email">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small fw-bold">Handover To</label>
                                        <select class="form-select select2-static" name="handover_to">
                                            <option value="">Select Colleague</option>
                                            <?php foreach ($handover_candidates as $hc): ?>
                                            <option value="<?= (int)$hc['employee_id'] ?>"><?= safe_output($hc['first_name'] . ' ' . $hc['last_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div id="ael_documentSection" style="display:none;">
                                            <label class="form-label small fw-bold">Supporting Document</label>
                                            <input type="file" class="form-control" name="document" accept=".pdf,.jpg,.jpeg,.png">
                                            <small class="text-muted">e.g. medical certificate</small>
                                        </div>
                                    </div>
                                    <div class="col-12 mb-2">
                                        <label class="form-label small fw-bold">Reason <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="reason" rows="3" required placeholder="Please provide a reason for this leave"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <div class="card">
                                            <div class="card-header bg-light py-2"><h6 class="mb-0 small"><i class="bi bi-info-circle"></i> Leave Balance</h6></div>
                                            <div class="card-body py-2" id="ael_balanceInfo">
                                                <p class="text-muted mb-0 small">Select a leave type to view balance information.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light border-0">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check-circle me-1"></i> Submit Application</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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

    // ── Tab-content tables → DataTables (search/sort/pagination) ────────
    // Every table on this page that actually has rows becomes a DataTable; sections that
    // are lists/cards/timelines (Service Record, Performance, Onboarding, Meetings & Trips,
    // Notes) are left alone since there's no table there to upgrade.
    var empTableIds = [
        'salaryStructureTable', 'empDocsTable', 'empContractsTable', 'empTrainingTable',
        'attendanceHistoryTable', 'payrollHistoryTable', 'leaveHistoryTable'
    ];
    // Plain for-loop with a try/catch per table, not .forEach() — an exception thrown
    // while processing one table would otherwise abort the whole forEach and silently
    // skip every table after it.
    for (var __i = 0; __i < empTableIds.length; __i++) {
        try {
            var __id = empTableIds[__i];
            var $t = $('#' + __id);
            if ($t.length && !$.fn.DataTable.isDataTable($t)) {
                $t.DataTable({
                    responsive: false,
                    dom: 'frtip',
                    pageLength: 10,
                    order: [],
                    columnDefs: [{ orderable: false, targets: 'no-sort' }],
                    language: { search: "_INPUT_", searchPlaceholder: "Search..." }
                });
            }
        } catch (e) {
            console.error('DataTable init failed for #' + empTableIds[__i], e);
        }
    }
    // Every one of these tables lives inside a Bootstrap tab-pane that's hidden
    // (display:none) at the point .DataTable() runs above — only the first tab
    // (Service Record, which has no table) starts active. DataTables measures column
    // widths against the container's visible width, so a table built while hidden ends
    // up with collapsed/zero-width columns the moment its tab is shown. Re-measure via
    // columns.adjust() whenever a pill tab becomes active.
    $('#employeeExtrasTabs button[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
        var pane = document.querySelector(e.target.getAttribute('data-bs-target'));
        if (!pane) return;
        $(pane).find('table').each(function () {
            if ($.fn.DataTable.isDataTable(this)) $(this).DataTable().columns.adjust();
        });
    });

    // ── Salary Structure (Plan H1) ──────────────────────────────────────
    const SC_ASSIGN_URL = '<?= buildUrl('api/pos/assign_salary_component.php') ?>';
    const SC_REMOVE_URL = '<?= buildUrl('api/pos/remove_salary_component.php') ?>';
    const SC_CSRF = '<?= csrf_token() ?>';

    // The modal is rendered inside a column/card; move it to <body> so its Select2
    // dropdown is not clipped by the column's stacking/overflow context (which is why
    // the Component picker appeared empty). Bootstrap modals belong at the top level.
    $('#assignCompModal').appendTo('body');

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

    // ── Attendance — mark/correct this employee's own record ────────────
    $('#markEmployeeAttendanceModal').appendTo('body');
    $('#markEmployeeAttendanceModal').on('hidden.bs.modal', function () {
        const f = $('#markEmpAttendanceForm')[0];
        if (f) f.reset();
        $('#mea-message').html('');
    });

    $('#markEmpAttendanceForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({
            url: '<?= buildUrl('api/mark_attendance.php') ?>', type: 'POST',
            data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('markEmployeeAttendanceModal')).hide();
                    Swal.fire({ icon:'success', title:'Saved!', text:res.message, timer:1600, showConfirmButton:false }).then(() => location.reload());
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: res.message || 'Could not save attendance.' });
                }
            },
            error: function () { Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    // ── Leave — apply for / review this employee's own leave ────────────
    const EMP_LEAVE_ID = <?= (int)$employee_id ?>;

    $('#applyEmpLeaveModal').appendTo('body');
    $('#applyEmpLeaveModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#applyEmpLeaveModal'), width:'100%' });
        });
    });
    $('#applyEmpLeaveModal').on('hidden.bs.modal', function () {
        const f = $('#applyEmpLeaveForm')[0];
        if (f) f.reset();
        $('#ael-message').html('');
        $('#ael_balanceInfo').html('<p class="text-muted mb-0 small">Select a leave type to view balance information.</p>');
    });

    function empLeaveDaysFor(startDate, endDate, halfDay) {
        const start = new Date(startDate), end = new Date(endDate);
        if (end < start) return null;
        const diffDays = Math.round((end - start) / 86400000) + 1;
        if (halfDay === 'first_half' || halfDay === 'second_half') return Math.max(0.5, diffDays - 0.5);
        return diffDays;
    }

    window.calculateEmpLeaveDays = function () {
        const startDate = $('#ael_start_date').val(), endDate = $('#ael_end_date').val();
        if (!startDate || !endDate) return;
        const totalDays = empLeaveDaysFor(startDate, endDate, $('#ael_half_day').val());
        if (totalDays === null) {
            Swal.fire({ icon:'warning', title:'Invalid Date Range', text:'End date cannot be before the start date.' });
            $('#ael_total_days').val(0); $('#ael_end_date').val('');
            return;
        }
        $('#ael_total_days').val(totalDays);
        updateEmpLeaveBalance();
    };

    window.updateEmpLeaveTypeInfo = function () {
        const opt = $('#ael_leave_type').find(':selected');
        const requiresDoc = opt.data('requires-doc') == 1;
        const isPaid = String(opt.data('is-paid')) === '1';
        $('#ael_documentSection').toggle(requiresDoc);
        const $badge = $('#ael_paid_badge').empty();
        if ($('#ael_leave_type').val()) {
            $badge.append($('<span>').addClass('badge').css({ background:isPaid?'#0d6efd':'#6c757d', color:'#fff' }).text(isPaid?'Paid Leave':'Unpaid Leave'));
        }
        updateEmpLeaveBalance();
    };

    window.updateEmpLeaveBalance = function () {
        const leaveTypeId = $('#ael_leave_type').val();
        if (!leaveTypeId) return;
        $.ajax({
            url: '<?= buildUrl('api/get_leave_balance.php') ?>', type: 'GET',
            data: { employee_id: EMP_LEAVE_ID, leave_type_id: leaveTypeId }, dataType: 'json',
            success: function (res) {
                if (!res.success) return;
                const maxDays = res.max_days_per_year || 0;
                const usedDays = parseFloat(res.balance.used_days) || 0;
                const remaining = maxDays - usedDays;
                $('#ael_balanceInfo').html(`
                    <div class="row">
                        <div class="col-4 text-center"><h5 class="text-primary mb-0">${remaining}</h5><small class="text-muted">Remaining</small></div>
                        <div class="col-4 text-center"><h5 class="mb-0">${usedDays}</h5><small class="text-muted">Used</small></div>
                        <div class="col-4 text-center"><h5 class="mb-0">${maxDays}</h5><small class="text-muted">Annual Limit</small></div>
                    </div>
                    <div class="progress mt-2" style="height:8px;"><div class="progress-bar bg-success" style="width:${maxDays>0?(usedDays/maxDays)*100:0}%"></div></div>
                `);
            }
        });
    };

    $('#applyEmpLeaveForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Submitting...');
        $.ajax({
            url: '<?= buildUrl('api/apply_leave.php') ?>', type: 'POST',
            data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('applyEmpLeaveModal')).hide();
                    Swal.fire({ icon:'success', title:'Submitted!', text:res.message, timer:1600, showConfirmButton:false }).then(() => location.reload());
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: res.message || 'Could not submit leave application.' });
                }
            },
            error: function () { Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    window.approveEmpLeave = function (leaveId) {
        Swal.fire({ title:'Approve Leave?', text:'Are you sure you want to approve this leave application?', icon:'question',
            showCancelButton:true, confirmButtonText:'Yes, Approve' })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url: '<?= buildUrl('api/approve_leave.php') ?>', type:'POST', dataType:'json',
                data: { leave_id:leaveId, action:'approve' },
                success:function(res){ if(res.success){ Swal.fire({icon:'success',title:'Approved!',text:res.message,timer:1500,showConfirmButton:false}).then(()=>location.reload()); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Error approving leave.'}); } });
        });
    };

    window.rejectEmpLeave = function (leaveId) {
        Swal.fire({ title:'Reject Application', text:'Please provide a reason for rejection:', input:'textarea',
            inputPlaceholder:'Reason for rejection...', showCancelButton:true, confirmButtonText:'Reject Application', confirmButtonColor:'#d33',
            preConfirm: (reason) => { if (!reason) Swal.showValidationMessage('Reason is required for rejection'); return reason; } })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url: '<?= buildUrl('api/reject_leave.php') ?>', type:'POST', dataType:'json',
                data: { leave_id:leaveId, reason:r.value },
                success:function(res){ if(res.success){ Swal.fire({icon:'success',title:'Rejected!',text:res.message,timer:1500,showConfirmButton:false}).then(()=>location.reload()); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Error rejecting leave.'}); } });
        });
    };

    window.deleteEmpLeave = function (leaveId) {
        Swal.fire({ title:'Delete Application?', text:'This action cannot be undone.', icon:'warning',
            showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, Delete' })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url: '<?= buildUrl('api/delete_leave.php') ?>', type:'POST', dataType:'json',
                data: { leave_id:leaveId },
                success:function(res){ if(res.success){ Swal.fire({icon:'success',title:'Deleted!',text:res.message,timer:1500,showConfirmButton:false}).then(()=>location.reload()); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Error deleting leave.'}); } });
        });
    };

    window.removeComponent = function (id) {
        Swal.fire({ title:'Remove this component?', text:'It will no longer apply to future payslips.', icon:'warning',
            showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, remove' })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url:SC_REMOVE_URL, type:'POST', dataType:'json', data:{ employee_component_id:id, _csrf:SC_CSRF },
                success:function(res){ if(res.success){ location.reload(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    };

    // ── Employee Documents (Tier 2, Phase 2.2) ──────────────────────────
    const ED_DELETE_URL = '<?= buildUrl('api/delete_employee_document.php') ?>';
    const ED_CSRF = '<?= csrf_token() ?>';

    $('#uploadDocModal').appendTo('body');
    $('#uploadDocModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#uploadDocModal'), placeholder:'Select type...', width:'100%' });
        });
    });
    $('#uploadDocModal').on('hidden.bs.modal', function () {
        $('#uploadDocForm')[0].reset();
        $('#upload_doc_type_id').val('').trigger('change');
        $('#upload-doc-message').html('');
    });

    $('#upload_doc_type_id').on('change', function () {
        const requiresExpiry = $(this).find(':selected').data('requires-expiry') == 1;
        $('#upload_expiry_label').html('Expiry Date' + (requiresExpiry ? ' <span class="text-danger">*</span>' : ''));
        $('#upload_expire_date').prop('required', requiresExpiry);
    });

    $('#uploadDocForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Uploading...');
        $.ajax({
            url: '<?= buildUrl('api/add_employee_document.php') ?>', type: 'POST',
            data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon:'success', title:'Uploaded!', text:res.message, timer:1600, showConfirmButton:false }).then(() => location.reload());
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: res.message || 'Could not upload document.' });
                }
            },
            error: function () { Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    window.deleteEmpDoc = function (id) {
        Swal.fire({ title:'Delete this document?', text:'This cannot be undone.', icon:'warning',
            showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, delete' })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url: ED_DELETE_URL, type:'POST', dataType:'json', data:{ emp_doc_id:id, _csrf:ED_CSRF },
                success:function(res){ if (res.success) { location.reload(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    };
});

function editEmployee(id) {
    window.location.href = APP_URL + '/employees?edit_id=' + id;
}

// Jump to one of the Attendance/Payroll/Leave tabs from the sidebar's "View..." buttons —
// activates the pill tab directly (bootstrap.Tab), then scrolls the tab bar into view.
// Simpler and more reliable than the old hash+scrollIntoView approach: there's no longer
// any external link into these specific cards (the employee list's Actions dropdown and
// mobile card buttons now only go to "View Details"), so nothing needs URL-hash support —
// this page's own buttons can just drive the tab directly.
function showEmpTab(paneId) {
    var btn = document.querySelector('#employeeExtrasTabs [data-bs-target="#' + paneId + '"]');
    if (!btn) return;
    bootstrap.Tab.getOrCreateInstance(btn).show();
    var tabs = document.getElementById('employeeExtrasTabs');
    if (tabs) tabs.scrollIntoView({ behavior: 'auto', block: 'start' });
}
</script>

<?php if ($can_create_lifecycle):
    // Shared New-HR-Action modal, locked to this employee (Tier 1, Phase 1.5)
    $lifecycle_preselect = [
        'employee_id' => (int)$employee_id,
        'name' => trim(implode(' ', array_filter([$employee['first_name'], $employee['last_name']]))),
    ];
    require __DIR__ . '/includes/lifecycle_modal.php';
?>
<script>
// Open the shared modal with the action type pre-picked (HR Action dropdown)
function openLifecycleModal(type) {
    const el = document.getElementById('lifecycleModal');
    $(el).one('shown.bs.modal', function () {
        $('#lc_type').val(type).trigger('change');
    });
    new bootstrap.Modal(el).show();
}
// After a save, reload so the timeline, status badge and mini-stats refresh
window.onLifecycleSaved = function () { location.reload(); };
</script>
<?php endif; ?>

<?php
include("footer.php");
ob_end_flush();
?>
