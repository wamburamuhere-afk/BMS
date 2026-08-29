<?php
// app/bms/pos/hr_dashboard.php
// HR command centre — headcount, contract/probation expiry (previously
// notification-only plumbing with no visual surface anywhere — cron/check_hr_expiry.php
// dispatches alerts but nobody could just LOOK at the list), HR Actions +
// acknowledgment compliance, department distribution, and recruitment pipeline.
// Every number here is a real, already-used query (hr_actions.php, departments.php,
// api/get_openings.php, cron/check_hr_expiry.php) or a live link to the page that
// owns that data — nothing here is a duplicate source of truth.
// Standards: .claude/ui-constants.md.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/project_scope.php';

autoEnforcePermission('hr_dashboard');
includeHeader();
global $pdo;

$emp_scope = scopeFilterSqlNullable('project', 'e');
$year = (int)date('Y');

// ── KPI row ──────────────────────────────────────────────────────────────
$kpi_total_active = (int)$pdo->query("SELECT COUNT(*) FROM employees e WHERE e.status = 'active' $emp_scope")->fetchColumn();
// 'Inactive' mirrors inactive_employees.php's own definition exactly (status != 'active'
// covers both 'inactive' and 'terminated' — the natural complement of Active).
$kpi_inactive      = (int)$pdo->query("SELECT COUNT(*) FROM employees e WHERE e.status != 'active' $emp_scope")->fetchColumn();
$kpi_probation     = (int)$pdo->query("SELECT COUNT(*) FROM employees e WHERE e.status = 'active' AND e.employment_status = 'probation' $emp_scope")->fetchColumn();
$kpi_on_leave      = (int)$pdo->query("
    SELECT COUNT(DISTINCT l.employee_id) FROM leaves l
    JOIN employees e ON e.employee_id = l.employee_id
    WHERE l.status = 'approved' AND CURDATE() BETWEEN l.start_date AND l.end_date $emp_scope
")->fetchColumn();
$kpi_new_hires = (int)$pdo->query("SELECT COUNT(*) FROM employees e WHERE YEAR(e.hire_date) = $year $emp_scope")->fetchColumn();
$kpi_pending_actions = (int)$pdo->query("
    SELECT COUNT(*) FROM employee_lifecycle_events ele
    JOIN employees e ON e.employee_id = ele.employee_id
    WHERE ele.status = 'pending' $emp_scope
")->fetchColumn();

// Payroll actually disbursed this year ('paid' = the same status value
// api/bulk_update_payroll_status.php writes when a run is marked paid;
// amount_paid is the column that holds what actually went out, not the
// computed net_salary that may differ on a partial payment).
$payroll_row = $pdo->query("
    SELECT COALESCE(SUM(p.amount_paid), 0) AS total_paid, COUNT(DISTINCT p.employee_id) AS employees_paid
    FROM payroll p
    JOIN employees e ON e.employee_id = p.employee_id
    WHERE p.status = 'paid' AND p.year = $year $emp_scope
")->fetch(PDO::FETCH_ASSOC) ?: ['total_paid' => 0, 'employees_paid' => 0];
$kpi_payroll_amount   = (float)$payroll_row['total_paid'];
$kpi_payroll_employees = (int)$payroll_row['employees_paid'];

// ── Contract & probation expiry (mirrors cron/check_hr_expiry.php's own scan —
//    that job only ever fires notifications; this is the first place you can
//    actually SEE the list) ───────────────────────────────────────────────
$expiring_contracts = $pdo->query("
    SELECT ec.contract_id, ec.employee_id, ec.end_date, ec.contract_type,
           e.first_name, e.last_name, DATEDIFF(ec.end_date, CURDATE()) AS days_remaining
    FROM employee_contracts ec
    JOIN employees e ON e.employee_id = ec.employee_id
    WHERE ec.status = 'active' AND ec.end_date IS NOT NULL
      AND ec.end_date >= CURDATE() AND DATEDIFF(ec.end_date, CURDATE()) <= 60 $emp_scope
    ORDER BY ec.end_date ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$ending_probation = $pdo->query("
    SELECT e.employee_id, e.first_name, e.last_name, e.probation_end_date,
           DATEDIFF(e.probation_end_date, CURDATE()) AS days_remaining
    FROM employees e
    WHERE e.status = 'active' AND e.employment_status = 'probation' AND e.probation_end_date IS NOT NULL
      AND e.probation_end_date >= CURDATE() AND DATEDIFF(e.probation_end_date, CURDATE()) <= 30 $emp_scope
    ORDER BY e.probation_end_date ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── HR Actions this year, same shape as app/bms/pos/hr_actions.php's own stat row ──
$hr_action_stats = $pdo->query("
    SELECT
        SUM(ele.status = 'approved' AND ele.event_type IN ('promotion','demotion')) AS promotions,
        SUM(ele.status = 'approved' AND ele.event_type = 'transfer') AS transfers,
        SUM(ele.status = 'approved' AND ele.event_type = 'award') AS awards,
        SUM(ele.status = 'approved' AND ele.event_type IN ('warning','complaint')) AS warnings,
        SUM(ele.status = 'approved' AND ele.event_type IN ('resignation','termination')) AS exits
    FROM employee_lifecycle_events ele
    JOIN employees e ON e.employee_id = ele.employee_id
    WHERE ele.status != 'deleted' AND YEAR(ele.event_date) = YEAR(CURDATE()) $emp_scope
")->fetch(PDO::FETCH_ASSOC) ?: [];

// Acknowledgment compliance — ties directly into the acknowledgment feature:
// how many approved warnings/complaints are still waiting on the employee.
$pending_ack = (int)$pdo->query("
    SELECT COUNT(*) FROM employee_lifecycle_events ele
    JOIN employees e ON e.employee_id = ele.employee_id
    WHERE ele.status = 'approved' AND ele.event_type IN ('warning','complaint') AND ele.acknowledged_at IS NULL $emp_scope
")->fetchColumn();

// ── Department headcount distribution (same shape as departments.php) ─────
$dept_headcount = $pdo->query("
    SELECT dp.department_name, COUNT(e.employee_id) AS headcount
    FROM departments dp
    LEFT JOIN employees e ON e.department_id = dp.department_id AND e.status = 'active' $emp_scope
    WHERE dp.status = 'active'
    GROUP BY dp.department_id
    HAVING headcount > 0
    ORDER BY headcount DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Employees vs Payroll — the headline chart. Headcount is reconstructed
//    historically (employees carries only the CURRENT status, no history table):
//    cumulative hires by month-end minus cumulative exits by month-end, where an
//    "exit" is an approved termination/resignation lifecycle event on or before
//    that date. Payroll is the same 'paid' definition as the KPI card above.
//    Covers January through the current month of $year (a trailing YTD line,
//    not a full Jan-Dec chart padded with meaningless future zeros). ────────
$monthsSoFar = ($year === (int)date('Y')) ? (int)date('n') : 12;
$evp_labels = []; $evp_headcount = []; $evp_payroll = [];
for ($m = 1; $m <= $monthsSoFar; $m++) {
    $asOf = ($year === (int)date('Y') && $m === $monthsSoFar) ? date('Y-m-d') : date('Y-m-t', strtotime("$year-$m-01"));

    $hiresStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE e.hire_date <= ? $emp_scope");
    $hiresStmt->execute([$asOf]);
    $hiresToDate = (int)$hiresStmt->fetchColumn();

    $exitsStmt = $pdo->prepare("
        SELECT COUNT(*) FROM employee_lifecycle_events ele
        JOIN employees e ON e.employee_id = ele.employee_id
        WHERE ele.event_type IN ('termination', 'resignation') AND ele.status = 'approved' AND ele.event_date <= ? $emp_scope
    ");
    $exitsStmt->execute([$asOf]);
    $exitsToDate = (int)$exitsStmt->fetchColumn();

    $payrollStmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.amount_paid), 0) FROM payroll p
        JOIN employees e ON e.employee_id = p.employee_id
        WHERE p.status = 'paid' AND p.year = ? AND p.month = ? $emp_scope
    ");
    $payrollStmt->execute([$year, $m]);
    $monthPayroll = (float)$payrollStmt->fetchColumn();

    $evp_labels[]    = date('M', strtotime("$year-$m-01"));
    $evp_headcount[] = max(0, $hiresToDate - $exitsToDate);
    $evp_payroll[]   = $monthPayroll;
}

// ── Recruitment pipeline — identical queries to api/get_openings.php's stats block ──
$recruitment = [
    'open_positions'   => (int)$pdo->query("SELECT COUNT(*) FROM job_openings WHERE status = 'open'")->fetchColumn(),
    'total_candidates' => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE status = 'active'")->fetchColumn(),
    'in_interview'     => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE status = 'active' AND stage = 'interview'")->fetchColumn(),
    'hired_year'       => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE status = 'active' AND stage = 'hired' AND YEAR(updated_at) = $year")->fetchColumn(),
];
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-speedometer2 text-primary me-2"></i>HR Dashboard</h4>
            <p class="text-muted small mb-0">A single view across headcount, expiring contracts, HR actions, and recruitment.</p>
        </div>
    </div>

    <!-- KPI row — uniform card background (#d1e7dd) matching the rest of the app -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg-3">
            <a href="<?= getUrl('employees') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100" style="background:#d1e7dd;"><div class="fs-3 fw-bold text-primary"><?= $kpi_total_active ?></div><div class="small text-muted">Active Employees</div></div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="<?= getUrl('inactive_employees') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100" style="background:#d1e7dd;"><div class="fs-3 fw-bold text-secondary"><?= $kpi_inactive ?></div><div class="small text-muted">Inactive Employees</div></div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="<?= getUrl('employees') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100" style="background:#d1e7dd;"><div class="fs-3 fw-bold text-warning"><?= $kpi_probation ?></div><div class="small text-muted">On Probation</div></div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="<?= getUrl('leaves') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100" style="background:#d1e7dd;"><div class="fs-3 fw-bold text-info"><?= $kpi_on_leave ?></div><div class="small text-muted">On Leave Today</div></div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="<?= getUrl('employees') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100" style="background:#d1e7dd;"><div class="fs-3 fw-bold text-success"><?= $kpi_new_hires ?></div><div class="small text-muted">New Hires (<?= $year ?>)</div></div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="<?= getUrl('hr_actions') ?>?status=pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100" style="background:#d1e7dd;"><div class="fs-3 fw-bold text-danger"><?= $kpi_pending_actions ?></div><div class="small text-muted">HR Actions Pending</div></div>
            </a>
        </div>
        <div class="col-12 col-lg-6">
            <a href="<?= getUrl('payroll') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100" style="background:#d1e7dd;">
                    <div class="fs-3 fw-bold text-primary">TSh <?= number_format($kpi_payroll_amount, 0) ?></div>
                    <div class="small text-muted">Payroll Paid (<?= $year ?>) · <?= $kpi_payroll_employees ?> employee<?= $kpi_payroll_employees === 1 ? '' : 's' ?> paid</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Employees vs Payroll — the headline chart -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="mb-0"><i class="bi bi-graph-up text-primary me-1"></i> Employees vs Payroll — <?= $year ?></h6>
                <small class="text-muted">Headcount growth against payroll cost, month by month. The gap between the two lines is your average cost per head trending up or down.</small>
            </div>
            <a href="<?= getUrl('payroll') ?>" class="small text-decoration-none">View Payroll <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="card-body">
            <?php if (array_sum($evp_payroll) > 0 || array_sum($evp_headcount) > 0): ?>
            <div style="height:340px;"><canvas id="empVsPayrollChart"></canvas></div>
            <?php else: ?>
            <div class="text-center text-muted py-5">No employees or payroll runs recorded yet this year.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- Department headcount — compact ranked list, not a chart -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-diagram-2 text-primary me-1"></i> Headcount by Department</h6>
                    <a href="<?= getUrl('departments') ?>" class="small text-decoration-none">Manage Departments <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body p-0">
                    <?php if ($dept_headcount): $maxHeadcount = max(array_column($dept_headcount, 'headcount')); ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <?php foreach ($dept_headcount as $d): $pct = $maxHeadcount > 0 ? round(((int)$d['headcount'] / $maxHeadcount) * 100) : 0; ?>
                                <tr>
                                    <td class="ps-3" style="width:35%"><?= safe_output($d['department_name']) ?></td>
                                    <td>
                                        <div class="progress" style="height:10px;">
                                            <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="text-end pe-3 fw-semibold" style="width:10%"><?= (int)$d['headcount'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-5">No employees assigned to a department yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- HR Actions this year — compact badge strip, not a chart -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-person-lines-fill text-primary me-1"></i> HR Actions (<?= $year ?>)</h6>
                    <a href="<?= getUrl('hr_actions') ?>" class="small text-decoration-none">View All <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <?php $hrActionsTotal = array_sum(array_map('intval', $hr_action_stats)); ?>
                    <?php if ($hrActionsTotal > 0):
                        $hr_action_display = [
                            ['promotions', 'Promotions', 'primary'], ['transfers', 'Transfers', 'info'],
                            ['awards', 'Awards', 'success'], ['warnings', 'Warnings/Complaints', 'warning'],
                            ['exits', 'Exits', 'danger'],
                        ];
                    ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($hr_action_display as [$k, $label, $color]): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <?= $label ?>
                            <span class="badge bg-<?= $color ?> rounded-pill"><?= (int)($hr_action_stats[$k] ?? 0) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="text-center text-muted py-5">No approved HR actions recorded this year.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- Contract & probation expiry -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-hourglass-split text-warning me-1"></i> Expiring Soon</h6>
                    <a href="<?= getUrl('employee_contracts') ?>" class="small text-decoration-none">Manage Contracts <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body p-0">
                    <?php if (!$expiring_contracts && !$ending_probation): ?>
                    <div class="text-center text-muted py-4">Nothing expiring in the next 60 days.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th class="ps-3">Employee</th><th>What</th><th>Date</th><th class="text-end pe-3">Days Left</th></tr></thead>
                            <tbody>
                                <?php foreach ($expiring_contracts as $c): ?>
                                <tr>
                                    <td class="ps-3"><a href="<?= getUrl('employee_details') ?>?id=<?= (int)$c['employee_id'] ?>" class="text-decoration-none"><?= safe_output(trim($c['first_name'] . ' ' . $c['last_name'])) ?></a></td>
                                    <td>Contract <small class="text-muted">(<?= safe_output($c['contract_type'], '—') ?>)</small></td>
                                    <td><?= date('d M Y', strtotime($c['end_date'])) ?></td>
                                    <td class="text-end pe-3"><span class="badge <?= (int)$c['days_remaining'] <= 7 ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= (int)$c['days_remaining'] ?>d</span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php foreach ($ending_probation as $p): ?>
                                <tr>
                                    <td class="ps-3"><a href="<?= getUrl('employee_details') ?>?id=<?= (int)$p['employee_id'] ?>" class="text-decoration-none"><?= safe_output(trim($p['first_name'] . ' ' . $p['last_name'])) ?></a></td>
                                    <td>Probation ends</td>
                                    <td><?= date('d M Y', strtotime($p['probation_end_date'])) ?></td>
                                    <td class="text-end pe-3"><span class="badge <?= (int)$p['days_remaining'] <= 7 ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= (int)$p['days_remaining'] ?>d</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Compliance + recruitment -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-shield-check text-primary me-1"></i> Acknowledgment Compliance</h6>
                </div>
                <div class="card-body">
                    <?php if ($pending_ack > 0): ?>
                    <div class="d-flex align-items-center gap-3">
                        <div class="fs-2 fw-bold text-warning"><?= $pending_ack ?></div>
                        <div class="small text-muted">warning(s)/complaint(s) issued but not yet acknowledged by the employee.</div>
                    </div>
                    <a href="<?= getUrl('hr_actions') ?>?type=warning" class="small text-decoration-none">Review in HR Actions <i class="bi bi-arrow-right"></i></a>
                    <?php else: ?>
                    <div class="text-success"><i class="bi bi-check-circle-fill"></i> All issued warnings/complaints are acknowledged.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-person-plus text-primary me-1"></i> Recruitment Pipeline</h6>
                    <a href="<?= getUrl('recruitment') ?>" class="small text-decoration-none">Open <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <div class="row g-2 text-center">
                        <div class="col-3"><div class="fw-bold fs-5 text-primary"><?= $recruitment['open_positions'] ?></div><div class="small text-muted">Open</div></div>
                        <div class="col-3"><div class="fw-bold fs-5 text-secondary"><?= $recruitment['total_candidates'] ?></div><div class="small text-muted">Candidates</div></div>
                        <div class="col-3"><div class="fw-bold fs-5 text-info"><?= $recruitment['in_interview'] ?></div><div class="small text-muted">Interviewing</div></div>
                        <div class="col-3"><div class="fw-bold fs-5 text-success"><?= $recruitment['hired_year'] ?></div><div class="small text-muted">Hired <?= $year ?></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick links — every HR module built across this workstream, one hub -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h6 class="mb-0"><i class="bi bi-grid text-primary me-1"></i> HR Modules</h6></div>
        <div class="card-body">
            <div class="row g-2">
                <?php
                $links = [
                    ['employees', 'bi-person-badge', 'Employees'],
                    ['hr_actions', 'bi-person-lines-fill', 'HR Actions'],
                    ['org_chart', 'bi-diagram-3', 'Org Chart'],
                    ['departments', 'bi-diagram-2', 'Departments'],
                    ['designations', 'bi-person-badge', 'Designations'],
                    ['employment_types', 'bi-person-workspace', 'Employment Types'],
                    ['recruitment', 'bi-person-plus', 'Recruitment'],
                    ['hr_performance', 'bi-graph-up-arrow', 'Performance'],
                    ['trainings', 'bi-mortarboard', 'Training'],
                    ['hr_checklists', 'bi-check2-square', 'Checklists'],
                    ['leaves', 'bi-calendar', 'Leaves'],
                    ['company_calendar', 'bi-calendar-week', 'Working Days & Holidays'],
                    ['payroll', 'bi-cash', 'Payroll'],
                    ['salary_components', 'bi-sliders', 'Salary Components'],
                    ['attendance', 'bi-clock', 'Attendance'],
                    ['employee_contracts', 'bi-file-earmark-text', 'Contracts'],
                ];
                foreach ($links as [$key, $icon, $label]):
                    if (!canView($key)) continue;
                ?>
                <div class="col-6 col-md-3 col-lg-2">
                    <a href="<?= getUrl($key) ?>" class="btn btn-outline-primary w-100 h-100 py-3 d-flex flex-column align-items-center gap-1 text-decoration-none">
                        <i class="bi <?= $icon ?>" style="font-size:1.4rem;"></i>
                        <span class="small"><?= $label ?></span>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if (array_sum($evp_payroll) > 0 || array_sum($evp_headcount) > 0): ?>
new Chart(document.getElementById('empVsPayrollChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode($evp_labels) ?>,
        datasets: [{
            label: 'Payroll Paid (TSh)',
            data: <?= json_encode($evp_payroll) ?>,
            yAxisID: 'yMoney',
            borderColor: '#0d6efd',
            backgroundColor: function (context) {
                const chart = context.chart;
                const { ctx: c, chartArea } = chart;
                if (!chartArea) return 'rgba(13,110,253,0.08)';
                const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                gradient.addColorStop(0, 'rgba(13,110,253,0.18)');
                gradient.addColorStop(1, 'rgba(13,110,253,0.01)');
                return gradient;
            },
            fill: true, tension: 0.4, borderWidth: 2.5,
            pointRadius: 4, pointHoverRadius: 7,
            pointBackgroundColor: '#fff', pointBorderColor: '#0d6efd', pointBorderWidth: 2,
        }, {
            label: 'Headcount',
            data: <?= json_encode($evp_headcount) ?>,
            yAxisID: 'yCount',
            borderColor: '#198754',
            backgroundColor: function (context) {
                const chart = context.chart;
                const { ctx: c, chartArea } = chart;
                if (!chartArea) return 'rgba(25,135,84,0.05)';
                const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                gradient.addColorStop(0, 'rgba(25,135,84,0.13)');
                gradient.addColorStop(1, 'rgba(25,135,84,0.01)');
                return gradient;
            },
            fill: true, tension: 0.4, borderWidth: 2, borderDash: [6, 3],
            pointRadius: 4, pointHoverRadius: 7,
            pointBackgroundColor: '#fff', pointBorderColor: '#198754', pointBorderWidth: 2,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: {
            legend: {
                display: true, position: 'top', align: 'end',
                labels: { usePointStyle: true, pointStyleWidth: 12, boxHeight: 8, font: { size: 12 }, padding: 20 }
            },
            tooltip: {
                padding: 14, backgroundColor: 'rgba(17,24,39,0.9)',
                titleFont: { size: 13, weight: 'bold' }, bodyFont: { size: 12 },
                borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1, cornerRadius: 8,
                callbacks: {
                    label: function (context) {
                        const label = context.dataset.label || '';
                        const val = context.parsed.y;
                        if (context.dataset.yAxisID === 'yMoney') {
                            if (val >= 1000000) return label + ': TSh ' + (val / 1000000).toFixed(2) + 'M';
                            if (val >= 1000) return label + ': TSh ' + (val / 1000).toFixed(1) + 'K';
                            return label + ': TSh ' + val.toLocaleString();
                        }
                        return label + ': ' + val;
                    },
                    afterBody: function (items) {
                        const money = items.find(i => i.dataset.yAxisID === 'yMoney');
                        const count = items.find(i => i.dataset.yAxisID === 'yCount');
                        if (money && count && count.parsed.y > 0) {
                            const perHead = money.parsed.y / count.parsed.y;
                            return ['─────────────────', 'Avg cost per employee: TSh ' + Math.round(perHead).toLocaleString()];
                        }
                        return [];
                    }
                }
            }
        },
        scales: {
            yMoney: {
                beginAtZero: true, position: 'left',
                ticks: {
                    color: '#6b7280', font: { size: 11 }, maxTicksLimit: 7,
                    callback: function (value) {
                        if (value >= 1000000) return 'TSh ' + (value / 1000000).toFixed(1) + 'M';
                        if (value >= 1000) return 'TSh ' + (value / 1000).toFixed(0) + 'K';
                        return 'TSh ' + value;
                    }
                },
                grid: { color: 'rgba(107,114,128,0.15)', lineWidth: 1, drawBorder: false },
                border: { dash: [4, 4], display: false }
            },
            yCount: {
                beginAtZero: true, position: 'right',
                ticks: { color: '#6b7280', font: { size: 11 }, precision: 0 },
                grid: { display: false },
                border: { display: false }
            },
            x: {
                ticks: { color: '#6b7280', font: { size: 11 }, maxRotation: 0 },
                grid: { color: 'rgba(107,114,128,0.08)', lineWidth: 1, drawBorder: false },
                border: { display: false }
            }
        }
    }
});
<?php endif; ?>
</script>

<?php includeFooter(); ?>
