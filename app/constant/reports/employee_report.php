<?php
// app/constant/reports/employee_report.php
// Professional Workforce Analysis — AJAX (get_employee_report.php), Chart.js
// charts that also print, DataTable, Select2 + Project scope.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('employee_report');

$projects = $pdo->query(
    "SELECT project_id, project_name FROM projects
      WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
      ORDER BY project_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$currency = get_setting('currency', 'TZS');
?>

<div class="container-fluid py-4">
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">WORKFORCE ANALYSIS REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Headcount, departmental distribution &amp; payroll commitment</p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-person-badge me-2"></i>Employee Report</h2>
            <p class="text-muted mb-0">Human capital and departmental distribution</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()"><i class="bi bi-printer me-2"></i> Print</button>
        </div>
    </div>

    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-4"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Project</label>
                    <select name="project_id" id="f-project" class="form-select" style="width:100%">
                        <option value="">All My Projects</option>
                        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-4"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Department</label>
                    <select name="department_id" id="f-dept" class="form-select" style="width:100%">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= safe_output($d['department_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Status</label>
                    <select name="status" id="f-status" class="form-select" style="width:100%">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="probation">Probation</option>
                        <option value="contract">Contract</option>
                        <option value="on_leave">On Leave</option>
                        <option value="terminated">Terminated</option>
                        <option value="resigned">Resigned</option>
                    </select></div>
                <div class="col-md-1"><button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter"></i></button></div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4" id="summaryCards">
        <?php foreach ([['Total Workforce','stat-total'],['Active Staff','stat-active'],['Payroll Commitment','stat-salary'],['Departments','stat-depts']] as $c): ?>
            <div class="col-6 col-md-3"><div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                <div class="card-body p-3 text-center"><p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12 col-md-5"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Headcount by Department</div>
            <div class="card-body"><div style="height:230px;"><canvas id="chartDept"></canvas></div></div></div></div>
        <div class="col-12 col-md-3"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>By Status</div>
            <div class="card-body"><div style="height:230px;"><canvas id="chartStatus"></canvas></div></div></div></div>
        <div class="col-12 col-md-4"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-cash-stack text-primary me-2"></i>Payroll by Department</div>
            <div class="card-body"><div style="height:230px;"><canvas id="chartSalary"></canvas></div></div></div></div>
    </div>

    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-people me-2"></i>Employees</h6></div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="empTable">
                <thead class="table-light"><tr>
                    <th class="ps-3">S/No</th><th>Name</th><th>Department</th><th>Position</th>
                    <th>Hire Date</th><th class="text-center">Status</th><th class="pe-3 text-end">Basic Salary</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div></div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #empTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    .badge-status { font-size: .68rem; padding: .35em .6em; border-radius: 6px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        .table-responsive { overflow: visible !important; }
        .dataTables_scroll, .dataTables_scrollHead, .dataTables_scrollBody { overflow: visible !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #chartRow .card, #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
        #empTable { border: 1px solid #000 !important; }
        #empTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #empTable td { border: 1px solid #dee2e6 !important; }
        .badge-status { border: 1px solid #999 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_employee_report.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc  = t => $('<div>').text(t == null ? '' : t).html();

    const SB = { active:'#052c65', probation:'#cfe2ff', contract:'#bfdbfe', on_leave:'#6ea8fe', terminated:'#dc3545', resigned:'#6c757d' };
    const SF = { active:'#fff', probation:'#084298', contract:'#1e3a8a', on_leave:'#fff', terminated:'#fff', resigned:'#fff' };
    function badge(s){ const k=(s||'').toLowerCase(); return `<span class="badge-status" style="background:${SB[k]||'#0d6efd'};color:${SF[k]||'#fff'};">${(s||'').replace(/_/g,' ').toUpperCase()}</span>`; }

    $('#f-project, #f-dept, #f-status').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    const table = $('#empTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: 5, className: 'text-center' }, { targets: 6, className: 'text-end' }],
        language: { emptyTable: 'No employees found.', zeroRecords: 'No matching records.' }
    });

    let cDept, cStatus, cSalary;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        [cDept, cStatus, cSalary].forEach(c => c && c.destroy());
        const blues = ['#0d6efd', '#052c65', '#6ea8fe', '#cfe2ff', '#1e3a8a', '#9ec5fe'];
        cDept = new Chart(document.getElementById('chartDept'), {
            type: 'bar', data: { labels: charts.by_department.map(r=>r.label), datasets: [{ label:'Headcount', data: charts.by_department.map(r=>+r.value), backgroundColor: BLUE }] },
            options: { ...baseOpts, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{ticks:{font:{size:9}}},y:{ticks:{font:{size:9}}}} } });
        cStatus = new Chart(document.getElementById('chartStatus'), {
            type: 'doughnut', data: { labels: charts.by_status.map(r=>r.label), datasets: [{ data: charts.by_status.map(r=>+r.value), backgroundColor: blues }] }, options: { ...baseOpts } });
        cSalary = new Chart(document.getElementById('chartSalary'), {
            type: 'bar', data: { labels: charts.salary_by_dept.map(r=>r.label), datasets: [{ label:'Payroll', data: charts.salary_by_dept.map(r=>+r.value), backgroundColor: '#052c65' }] },
            options: { ...baseOpts, plugins:{legend:{display:false}}, scales:{y:{ticks:{font:{size:9}}},x:{ticks:{font:{size:9}}}} } });
    }

    function loadReport() {
        const params = { project_id: $('#f-project').val() || '', department_id: $('#f-dept').val() || '', status: $('#f-status').val() || '' };
        $.getJSON(DATA_URL, params).done(function (res) {
            if (!res || !res.success) { Swal.fire({ icon:'error', title:'Error', text:(res&&res.message)||'Could not load the report.' }); return; }
            const s = res.summary;
            $('#stat-total').text(Number(s.total_workforce).toLocaleString());
            $('#stat-active').text(Number(s.active).toLocaleString());
            $('#stat-salary').text(fmt(s.total_salary));
            $('#stat-depts').text(Number(s.departments).toLocaleString());
            renderCharts(res.charts);
            table.clear();
            res.rows.forEach((r, i) => table.row.add([
                i + 1, esc((r.full_name||'').trim()), esc(r.department), esc(r.position),
                r.hire_date ? new Date(r.hire_date).toLocaleDateString() : '—', badge(r.status), fmt(r.basic_salary)
            ]));
            table.draw();
        }).fail(() => Swal.fire({ icon:'error', title:'Error', text:'Server error loading the report.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project, #f-dept, #f-status').on('change', loadReport);
    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Employee Report', 'Loaded workforce analysis report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
