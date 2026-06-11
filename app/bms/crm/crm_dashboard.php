<?php
ob_start();
$page_title = 'CRM Dashboard';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('crm_dashboard');
includeHeader();

$can_leads    = canView('crm_leads');
$can_pipeline = canView('crm_pipeline');
?>

<div class="container-fluid mt-3 mb-5 px-3 px-md-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <h4 class="mb-0"><i class="bi bi-speedometer2 me-2 text-primary"></i>CRM Dashboard</h4>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <select id="periodSelect" class="form-select form-select-sm" style="width:auto">
                <option value="this_month" selected>This Month</option>
                <option value="last_month">Last Month</option>
                <option value="this_year">This Year</option>
                <option value="all">All Time</option>
            </select>
            <?php if ($can_leads): ?>
            <a href="<?= getUrl('crm/leads') ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-person-plus me-1"></i>New Lead
            </a>
            <?php endif; ?>
            <?php if ($can_pipeline): ?>
            <a href="<?= getUrl('crm/pipeline') ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-kanban me-1"></i>Pipeline
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4" id="kpiRow">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-4 fw-bold text-primary" id="kpi-total">—</div>
                <div class="small text-muted">Total Leads</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-4 fw-bold text-info" id="kpi-new">—</div>
                <div class="small text-muted">New This Period</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-5 fw-bold text-success" id="kpi-pipeline">—</div>
                <div class="small text-muted">Pipeline Value</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-4 fw-bold text-warning" id="kpi-rate">—</div>
                <div class="small text-muted">Win Rate</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-4 fw-bold text-secondary" id="kpi-today">—</div>
                <div class="small text-muted">Due Today</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-4 fw-bold text-danger" id="kpi-overdue">—</div>
                <div class="small text-muted">Overdue</div>
            </div>
        </div>
    </div>

    <!-- Charts row 1 -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-5">
            <div class="card border-0 shadow-sm p-3 h-100">
                <div class="fw-semibold mb-2 small text-uppercase text-muted">Leads by Stage</div>
                <div style="position:relative;height:220px">
                    <canvas id="chartStage"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7">
            <div class="card border-0 shadow-sm p-3 h-100">
                <div class="fw-semibold mb-2 small text-uppercase text-muted">Leads by Source</div>
                <div style="position:relative;height:220px">
                    <canvas id="chartSource"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts row 2 -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-7">
            <div class="card border-0 shadow-sm p-3 h-100">
                <div class="fw-semibold mb-2 small text-uppercase text-muted">Monthly Pipeline (last 6 months)</div>
                <div style="position:relative;height:220px">
                    <canvas id="chartMonthly"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-5">
            <div class="card border-0 shadow-sm p-3 h-100">
                <div class="fw-semibold mb-2 small text-uppercase text-muted">Win / Loss Trend</div>
                <div style="position:relative;height:220px">
                    <canvas id="chartWinLoss"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Tables row -->
    <div class="row g-3">
        <!-- Recent Leads -->
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold small text-uppercase text-muted border-bottom py-2">
                    <i class="bi bi-person-lines-fill me-1"></i>Recent Leads
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle" id="tblRecent">
                            <thead class="table-light">
                                <tr>
                                    <th>Lead</th>
                                    <th>Company</th>
                                    <th>Stage</th>
                                    <th class="text-end">Value</th>
                                    <th class="text-end">Date</th>
                                </tr>
                            </thead>
                            <tbody id="bodyRecent">
                                <tr><td colspan="5" class="text-center py-3 text-muted">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Due Activities -->
        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold small text-uppercase text-muted border-bottom py-2">
                    <i class="bi bi-clock-history me-1"></i>Due / Overdue Activities
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle" id="tblDue">
                            <thead class="table-light">
                                <tr>
                                    <th>Lead</th>
                                    <th>Activity</th>
                                    <th>Due</th>
                                </tr>
                            </thead>
                            <tbody id="bodyDue">
                                <tr><td colspan="3" class="text-center py-3 text-muted">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Assignees -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold small text-uppercase text-muted border-bottom py-2">
                    <i class="bi bi-trophy me-1"></i>Top Performers (Won Leads)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle" id="tblTop">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>User</th>
                                    <th class="text-center">Won</th>
                                    <th class="text-end">Won Value</th>
                                </tr>
                            </thead>
                            <tbody id="bodyTop">
                                <tr><td colspan="4" class="text-center py-3 text-muted">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
const DASH_URL = '<?= buildUrl('api/crm/get_dashboard_data.php') ?>';
const LEAD_URL = '<?= getUrl('crm/lead_view') ?>';

const SOURCE_LABELS = {
    website:'Website', referral:'Referral', walk_in:'Walk-in',
    phone_call:'Phone Call', social_media:'Social Media', exhibition:'Exhibition',
    cold_call:'Cold Call', email_campaign:'Email Campaign', other:'Other'
};

const TYPE_META = {
    call:       {icon:'bi-telephone-fill',   color:'#0d6efd', label:'Call'},
    email:      {icon:'bi-envelope-fill',    color:'#6f42c1', label:'Email'},
    meeting:    {icon:'bi-people-fill',      color:'#198754', label:'Meeting'},
    note:       {icon:'bi-sticky-fill',      color:'#ffc107', label:'Note'},
    task:       {icon:'bi-check2-square',    color:'#0dcaf0', label:'Task'},
    site_visit: {icon:'bi-geo-alt-fill',     color:'#fd7e14', label:'Site Visit'},
};

let cStage, cSource, cMonthly, cWinLoss;

function fmtNum(n) {
    return Number(n).toLocaleString('en-TZ', {minimumFractionDigits:0, maximumFractionDigits:0});
}
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
}

function loadDashboard(period) {
    $.getJSON(DASH_URL, {period: period}, function(res) {
        if (!res.success) return;

        // KPIs
        const k = res.kpi;
        $('#kpi-total').text(fmtNum(k.total_leads));
        $('#kpi-new').text(fmtNum(k.new_this_period));
        $('#kpi-pipeline').text('TZS ' + fmtNum(k.pipeline_value));
        $('#kpi-rate').text(k.conversion_rate + '%');
        $('#kpi-today').text(fmtNum(k.activities_today));
        $('#kpi-overdue').text(fmtNum(k.overdue_activities));

        // Charts
        renderStage(res.charts.by_stage);
        renderSource(res.charts.by_source);
        renderMonthly(res.charts.monthly);
        renderWinLoss(res.charts.win_loss);

        // Tables
        renderRecent(res.tables.recent_leads);
        renderDue(res.tables.due_activities);
        renderTop(res.tables.top_assignees);
    });
}

function renderStage(rows) {
    const labels = rows.map(r => r.stage_name);
    const data   = rows.map(r => parseInt(r.cnt));
    const colors = rows.map(r => r.color || '#6c757d');
    if (cStage) cStage.destroy();
    cStage = new Chart(document.getElementById('chartStage'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2 }] },
        options: { responsive:true, maintainAspectRatio:false,
            plugins: { legend: { position:'right', labels:{boxWidth:12, font:{size:11}} } }
        }
    });
}

function renderSource(rows) {
    const labels = rows.map(r => SOURCE_LABELS[r.lead_source] || r.lead_source);
    const data   = rows.map(r => parseInt(r.cnt));
    if (cSource) cSource.destroy();
    cSource = new Chart(document.getElementById('chartSource'), {
        type: 'bar',
        data: { labels, datasets: [{ label:'Leads', data, backgroundColor:'rgba(13,110,253,.7)', borderRadius:4 }] },
        options: { responsive:true, maintainAspectRatio:false,
            plugins: { legend:{display:false} },
            scales: { y:{ beginAtZero:true, ticks:{stepSize:1} } }
        }
    });
}

function renderMonthly(rows) {
    const labels = rows.map(r => r.mo);
    const data   = rows.map(r => parseInt(r.created));
    if (cMonthly) cMonthly.destroy();
    cMonthly = new Chart(document.getElementById('chartMonthly'), {
        type: 'line',
        data: { labels, datasets: [{
            label:'Leads Created', data, fill:true,
            borderColor:'#0d6efd', backgroundColor:'rgba(13,110,253,.1)',
            tension:.3, pointRadius:4
        }]},
        options: { responsive:true, maintainAspectRatio:false,
            plugins: { legend:{display:false} },
            scales: { y:{ beginAtZero:true, ticks:{stepSize:1} } }
        }
    });
}

function renderWinLoss(rows) {
    const labels = rows.map(r => r.mo);
    const won    = rows.map(r => parseInt(r.won)  || 0);
    const lost   = rows.map(r => parseInt(r.lost) || 0);
    if (cWinLoss) cWinLoss.destroy();
    cWinLoss = new Chart(document.getElementById('chartWinLoss'), {
        type: 'bar',
        data: { labels, datasets: [
            { label:'Won',  data: won,  backgroundColor:'rgba(25,135,84,.75)',  borderRadius:3 },
            { label:'Lost', data: lost, backgroundColor:'rgba(220,53,69,.75)',  borderRadius:3 },
        ]},
        options: { responsive:true, maintainAspectRatio:false,
            plugins: { legend:{labels:{boxWidth:12,font:{size:11}}} },
            scales: { x:{stacked:false}, y:{ beginAtZero:true, ticks:{stepSize:1} } }
        }
    });
}

function renderRecent(rows) {
    if (!rows.length) {
        $('#bodyRecent').html('<tr><td colspan="5" class="text-center text-muted py-3">No leads yet</td></tr>');
        return;
    }
    let html = '';
    rows.forEach(r => {
        html += `<tr>
            <td><a href="${LEAD_URL}?id=${r.lead_id}" class="text-decoration-none fw-semibold">${safeOutput(r.full_name)}</a>
                <div class="text-muted small">${safeOutput(r.lead_code)}</div></td>
            <td class="text-muted small">${safeOutput(r.company_name || '—')}</td>
            <td><span class="badge rounded-pill" style="background:${safeOutput(r.stage_color||'#6c757d')}">${safeOutput(r.stage_name||'—')}</span></td>
            <td class="text-end small">TZS ${fmtNum(r.lead_value)}</td>
            <td class="text-end small text-muted">${fmtDate(r.created_at)}</td>
        </tr>`;
    });
    $('#bodyRecent').html(html);
}

function renderDue(rows) {
    if (!rows.length) {
        $('#bodyDue').html('<tr><td colspan="3" class="text-center text-muted py-3">No pending activities</td></tr>');
        return;
    }
    let html = '';
    const now = new Date();
    rows.forEach(r => {
        const due  = r.due_date ? new Date(r.due_date) : null;
        const late = due && due < now;
        const meta = TYPE_META[r.activity_type] || {icon:'bi-circle',color:'#6c757d',label:r.activity_type};
        html += `<tr class="${late ? 'table-danger' : ''}">
            <td><a href="${LEAD_URL}?id=${r.lead_id}" class="text-decoration-none small fw-semibold">${safeOutput(r.lead_name)}</a>
                <div class="text-muted" style="font-size:.7rem">${safeOutput(r.lead_code)}</div></td>
            <td><span class="badge" style="background:${meta.color};font-size:.68rem">${safeOutput(meta.label)}</span>
                <div class="small">${safeOutput(r.subject)}</div></td>
            <td class="small ${late?'text-danger fw-semibold':''}">${fmtDate(r.due_date)}</td>
        </tr>`;
    });
    $('#bodyDue').html(html);
}

function renderTop(rows) {
    if (!rows.length) {
        $('#bodyTop').html('<tr><td colspan="4" class="text-center text-muted py-3">No data</td></tr>');
        return;
    }
    let html = '';
    rows.forEach((r, i) => {
        const medals = ['🥇','🥈','🥉'];
        html += `<tr>
            <td class="text-muted">${medals[i] || (i+1)}</td>
            <td class="fw-semibold">${safeOutput(r.name)}</td>
            <td class="text-center"><span class="badge bg-success">${r.won_count}</span></td>
            <td class="text-end small">TZS ${fmtNum(r.won_value)}</td>
        </tr>`;
    });
    $('#bodyTop').html(html);
}

$(document).ready(function () {
    loadDashboard($('#periodSelect').val());
    $('#periodSelect').on('change', function () {
        loadDashboard($(this).val());
    });
});
</script>

<?php includeFooter(); ?>
