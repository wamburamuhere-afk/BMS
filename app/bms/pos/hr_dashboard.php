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

// ── KPI row ──────────────────────────────────────────────────────────────
$kpi_total_active = (int)$pdo->query("SELECT COUNT(*) FROM employees e WHERE e.status = 'active' $emp_scope")->fetchColumn();
$kpi_probation     = (int)$pdo->query("SELECT COUNT(*) FROM employees e WHERE e.status = 'active' AND e.employment_status = 'probation' $emp_scope")->fetchColumn();
$kpi_on_leave      = (int)$pdo->query("
    SELECT COUNT(DISTINCT l.employee_id) FROM leaves l
    JOIN employees e ON e.employee_id = l.employee_id
    WHERE l.status = 'approved' AND CURDATE() BETWEEN l.start_date AND l.end_date $emp_scope
")->fetchColumn();
$kpi_pending_actions = (int)$pdo->query("
    SELECT COUNT(*) FROM employee_lifecycle_events ele
    JOIN employees e ON e.employee_id = ele.employee_id
    WHERE ele.status = 'pending' $emp_scope
")->fetchColumn();

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

// ── Recruitment pipeline — identical queries to api/get_openings.php's stats block ──
$year = (int)date('Y');
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

    <!-- KPI row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="<?= getUrl('employees') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100"><div class="fs-3 fw-bold text-primary"><?= $kpi_total_active ?></div><div class="small text-muted">Active Employees</div></div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="<?= getUrl('employees') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100"><div class="fs-3 fw-bold text-warning"><?= $kpi_probation ?></div><div class="small text-muted">On Probation</div></div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="<?= getUrl('leaves') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100"><div class="fs-3 fw-bold text-info"><?= $kpi_on_leave ?></div><div class="small text-muted">On Leave Today</div></div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="<?= getUrl('hr_actions') ?>?status=pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center p-3 h-100"><div class="fs-3 fw-bold text-danger"><?= $kpi_pending_actions ?></div><div class="small text-muted">HR Actions Pending</div></div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- Department headcount chart -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-bar-chart text-primary me-1"></i> Headcount by Department</h6>
                    <a href="<?= getUrl('departments') ?>" class="small text-decoration-none">Manage Departments <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <?php if ($dept_headcount): ?>
                    <canvas id="deptChart" height="110"></canvas>
                    <?php else: ?>
                    <div class="text-center text-muted py-5">No employees assigned to a department yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- HR Actions breakdown chart -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-pie-chart text-primary me-1"></i> HR Actions (<?= $year ?>)</h6>
                    <a href="<?= getUrl('hr_actions') ?>" class="small text-decoration-none">View All <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <?php $hrActionsTotal = array_sum(array_map('intval', $hr_action_stats)); ?>
                    <?php if ($hrActionsTotal > 0): ?>
                    <canvas id="hrActionsChart" height="180"></canvas>
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
<?php if ($dept_headcount): ?>
new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($dept_headcount, 'department_name')) ?>,
        datasets: [{
            label: 'Active Employees',
            data: <?= json_encode(array_map('intval', array_column($dept_headcount, 'headcount'))) ?>,
            backgroundColor: '#0d6efd'
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
<?php endif; ?>
<?php if ($hrActionsTotal > 0): ?>
new Chart(document.getElementById('hrActionsChart'), {
    type: 'doughnut',
    data: {
        labels: ['Promotions', 'Transfers', 'Awards', 'Warnings/Complaints', 'Exits'],
        datasets: [{
            data: [<?= (int)($hr_action_stats['promotions'] ?? 0) ?>, <?= (int)($hr_action_stats['transfers'] ?? 0) ?>, <?= (int)($hr_action_stats['awards'] ?? 0) ?>, <?= (int)($hr_action_stats['warnings'] ?? 0) ?>, <?= (int)($hr_action_stats['exits'] ?? 0) ?>],
            backgroundColor: ['#0d6efd', '#6f42c1', '#198754', '#ffc107', '#dc3545']
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
});
<?php endif; ?>
</script>

<?php includeFooter(); ?>
