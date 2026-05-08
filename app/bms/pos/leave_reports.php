<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('leaves');

// Include the header
includeHeader();


// Get filters
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-12-31');
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : null;

// Build filter where clause
$where_clauses = ["start_date BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $start_date, ':end_date' => $end_date];

if ($department_id) {
    $where_clauses[] = "employee_id IN (SELECT employee_id FROM employees WHERE department_id = :dept_id)";
    $params[':dept_id'] = $department_id;
}

$where_sql = implode(" AND ", $where_clauses);

// 1. Status Overview Data
$status_stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM leaves WHERE $where_sql GROUP BY status");
$status_stmt->execute($params);
$status_data = $status_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Leaves by Department
$dept_stmt = $pdo->prepare("
    SELECT d.department_name, COUNT(l.leave_id) as count 
    FROM leaves l
    JOIN employees e ON l.employee_id = e.employee_id
    JOIN departments d ON e.department_id = d.department_id
    WHERE l.$where_sql
    GROUP BY d.department_name
    ORDER BY count DESC
");
$dept_stmt->execute($params);
$dept_data = $dept_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Leaves by Type
$type_stmt = $pdo->prepare("SELECT leave_type, COUNT(*) as count FROM leaves WHERE $where_sql GROUP BY leave_type ORDER BY count DESC");
$type_stmt->execute($params);
$type_data = $type_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. Monthly Trend Data
$trend_stmt = $pdo->prepare("
    SELECT DATE_FORMAT(start_date, '%b %Y') as month_name, COUNT(*) as count, DATE_FORMAT(start_date, '%Y-%m') as sort_key
    FROM leaves 
    WHERE $where_sql
    GROUP BY month_name, sort_key
    ORDER BY sort_key ASC
");
$trend_stmt->execute($params);
$trend_data = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    :root {
        --glass-bg: rgba(255, 255, 255, 0.7);
        --glass-border: rgba(255, 255, 255, 0.3);
        --premium-blue: #2563eb;
        --premium-indigo: #4f46e5;
        --premium-slate: #1e293b;
    }

    body {
        background: #f8fafc;
        background-image: radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.05) 0px, transparent 50%),
                          radial-gradient(at 100% 0%, rgba(79, 70, 229, 0.05) 0px, transparent 50%);
        min-height: 100vh;
    }

    .report-container {
        padding: 2rem 1.5rem;
    }

    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border);
        border-radius: 20px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.05);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        overflow: hidden;
    }

    .glass-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
    }

    .card-header-gradient {
        background: linear-gradient(135deg, var(--premium-slate), #334155);
        color: white;
        padding: 1.25rem;
        border: none;
    }

    .stat-pill {
        padding: 0.5rem 1rem;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.85rem;
    }

    .analytic-value {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--premium-slate);
        letter-spacing: -1px;
    }

    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
        padding: 1rem;
    }

    .filter-bar {
        background: white;
        padding: 1.5rem;
        border-radius: 15px;
        margin-bottom: 2rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    }

    .btn-premium {
        background: var(--premium-slate);
        color: white;
        border: none;
        padding: 0.6rem 2rem;
        border-radius: 10px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-premium:hover {
        background: #0f172a;
        color: white;
        transform: scale(1.02);
    }

    .icon-box {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1rem;
    }

    .table-premium {
        border-radius: 15px;
        overflow: hidden;
    }

    .table-premium thead {
        background: #f1f5f9;
        color: #475569;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
    }

    @page {
        size: A4;
        margin: 0 !important;
    }

    @media print {
        * { -webkit-print-color-adjust: exact !important; color-adjust: exact !important; }
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            height: auto !important;
            background: white !important;
            overflow: hidden !important;
        }
        .report-container {
            padding: 10mm 15mm !important;
            margin: 0 !important;
            width: 100% !important;
            height: auto !important;
        }
        .no-print, .filter-bar, .btn-premium, .navbar, footer, #footer { 
            display: none !important; 
        }
        .glass-card { 
            box-shadow: none !important; 
            border: 1px solid #e2e8f0 !important;
            break-inside: avoid;
            background: white !important;
            margin-bottom: 5mm !important;
        }
        .analytic-value {
            font-size: 2rem !important;
        }
        .chart-container {
            height: 220px !important;
        }
        h2, h5 { margin-top: 0 !important; }
    }
</style>

<div class="report-container">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h2 class="fw-bold text-slate-900 mb-1">Leave Analytics Command Center</h2>
            <p class="text-muted"><i class="bi bi-info-circle me-1"></i> Strategic overview of organizational leave patterns and workforce availability</p>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-dark me-2">
                <i class="bi bi-printer me-2"></i>Print Intelligence
            </button>
            <a href="<?= getUrl('leaves') ?>" class="btn btn-premium">
                <i class="bi bi-arrow-left me-2"></i>Back to Records
            </a>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold small text-uppercase">Start Period</label>
                <input type="date" name="start_date" class="form-control rounded-pill" value="<?= $start_date ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small text-uppercase">End Period</label>
                <input type="date" name="end_date" class="form-control rounded-pill" value="<?= $end_date ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small text-uppercase">Department Scope</label>
                <select name="department_id" class="form-select rounded-pill">
                    <option value="">Full Organization</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['department_id'] ?>" <?= $department_id == $dept['department_id'] ? 'selected' : '' ?>>
                            <?= safe_output($dept['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-dark w-100 rounded-pill py-2">
                    <i class="bi bi-funnel me-2"></i>Apply Analytics Scope
                </button>
            </div>
        </form>
    </div>

    <!-- Quick Stats Row -->
    <div class="row g-4 mb-5">
        <div class="col-xl-3 col-md-6">
            <div class="glass-card h-100 p-4">
                <div class="icon-box bg-primary-subtle text-primary">
                    <i class="bi bi-layers-fill fs-4"></i>
                </div>
                <div class="text-muted small text-uppercase fw-bold">Total Applications</div>
                <div class="analytic-value"><?= array_sum($status_data) ?></div>
                <div class="mt-2">
                    <span class="badge bg-success-subtle text-success stat-pill">+12% from last cycle</span>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="glass-card h-100 p-4">
                <div class="icon-box bg-warning-subtle text-warning">
                    <i class="bi bi-hourglass-split fs-4"></i>
                </div>
                <div class="text-muted small text-uppercase fw-bold">Pending Review</div>
                <div class="analytic-value text-warning"><?= $status_data['pending'] ?? 0 ?></div>
                <div class="progress mt-3" style="height: 6px;">
                    <div class="progress-bar bg-warning" style="width: <?= array_sum($status_data) > 0 ? (($status_data['pending'] ?? 0) / array_sum($status_data) * 100) : 0 ?>%"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="glass-card h-100 p-4">
                <div class="icon-box bg-success-subtle text-success">
                    <i class="bi bi-check-all fs-4"></i>
                </div>
                <div class="text-muted small text-uppercase fw-bold">Approved Rate</div>
                <div class="analytic-value text-success"><?= round(array_sum($status_data) > 0 ? (($status_data['approved'] ?? 0) / array_sum($status_data) * 100) : 0) ?>%</div>
                <div class="text-muted small mt-2"><?= $status_data['approved'] ?? 0 ?> Total authorizations</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="glass-card h-100 p-4">
                <div class="icon-box bg-info-subtle text-info">
                    <i class="bi bi-people-fill fs-4"></i>
                </div>
                <div class="text-muted small text-uppercase fw-bold">Departments Impacted</div>
                <div class="analytic-value"><?= count($dept_data) ?></div>
                <div class="text-muted small mt-2">Active cross-unit coverage</div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-5">
        <div class="col-lg-8">
            <div class="glass-card h-100">
                <div class="card-header-gradient">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2"></i> Leave Volume Trends</h5>
                </div>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="glass-card h-100">
                <div class="card-header-gradient">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-pie-chart-fill me-2"></i> Distribution by Type</h5>
                </div>
                <div class="chart-container">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Lower Row -->
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="glass-card h-100">
                <div class="card-header-gradient">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-building me-2"></i> Departmental Distribution</h5>
                </div>
                <div class="p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-premium">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th class="text-center">Volume</th>
                                    <th>Intensity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dept_data as $dept_name => $count): ?>
                                <tr>
                                    <td class="fw-bold"><?= safe_output($dept_name) ?></td>
                                    <td class="text-center"><span class="badge bg-slate-100 text-slate-700 px-3 py-2"><?= $count ?></span></td>
                                    <td>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar" style="width: <?= ($count / max(1, max($dept_data)) * 100) ?>%; background: #2563eb;"></div>
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
        <div class="col-lg-6">
            <div class="glass-card h-100">
                <div class="card-header-gradient">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2"></i> Decision Metrics</h5>
                </div>
                <div class="p-4 chart-container" style="height: 350px;">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // 1. Trend Chart
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_column($trend_data, 'month_name')) ?>,
            datasets: [{
                label: 'Application Volume',
                data: <?= json_encode(array_column($trend_data, 'count')) ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 6,
                pointBackgroundColor: '#fff',
                pointBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { borderDash: [5, 5] } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. Type Distribution Chart
    const typeCtx = document.getElementById('typeChart').getContext('2d');
    new Chart(typeCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_keys($type_data)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($type_data)) ?>,
                backgroundColor: [
                    '#2563eb', '#4f46e5', '#8b5cf6', '#ec4899', '#f97316', '#eab308'
                ],
                borderWidth: 0,
                hoverOffset: 20
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
            },
            cutout: '70%'
        }
    });

    // 3. Status Bar Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_keys($status_data)) ?>,
            datasets: [{
                label: 'Decision Count',
                data: <?= json_encode(array_values($status_data)) ?>,
                backgroundColor: (context) => {
                    const label = context.chart.data.labels[context.dataIndex].toLowerCase();
                    if (label === 'approved') return '#10b981';
                    if (label === 'pending') return '#f59e0b';
                    if (label === 'rejected') return '#ef4444';
                    return '#94a3b8';
                },
                borderRadius: 8
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { borderDash: [5, 5] } },
                y: { grid: { display: false } }
            }
        }
    });
</script>

<div class="no-print">
    <?php includeFooter(); ?>
</div>
