<?php
ob_start();
$page_title = 'CRM Reports';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('crm_reports');
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View CRM reports', 'User opened CRM Reports page');

$default_from = date('Y-01-01');
$default_to   = date('Y-m-d');
?>

<style>
.report-tab { cursor:pointer; border-bottom:2px solid transparent; padding:8px 14px; font-size:.88rem; font-weight:600; color:#6c757d; transition:color .15s,border-color .15s; white-space:nowrap; }
.report-tab.active { color:#0d6efd; border-bottom-color:#0d6efd; }
.report-tab:hover  { color:#0d6efd; }
</style>

<div class="container-fluid mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-bar-chart text-primary me-2"></i>CRM Reports</h4>
    </div>

    <!-- Date range filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" id="rptFrom" class="form-control form-control-sm" value="<?= $default_from ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" id="rptTo" class="form-control form-control-sm" value="<?= $default_to ?>">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary" onclick="loadReport()"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-outline-secondary" onclick="setPeriod('month')">This Month</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="setPeriod('quarter')">This Quarter</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="setPeriod('year')">This Year</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab nav -->
    <div class="d-flex gap-1 border-bottom mb-4 overflow-x-auto pb-0">
        <?php
        $tabs = [
            'funnel'   => ['icon' => 'bi-funnel-fill',       'label' => 'Conversion Funnel'],
            'agent'    => ['icon' => 'bi-people-fill',        'label' => 'Agent Performance'],
            'activity' => ['icon' => 'bi-telephone-fill',     'label' => 'Activity Report'],
            'forecast' => ['icon' => 'bi-graph-up-arrow',     'label' => 'Pipeline Forecast'],
            'winloss'  => ['icon' => 'bi-trophy-fill',        'label' => 'Win / Loss Analysis'],
            'campaign' => ['icon' => 'bi-megaphone-fill',     'label' => 'Campaign ROI'],
            'source'   => ['icon' => 'bi-signpost-split-fill','label' => 'Lead Sources'],
        ];
        foreach ($tabs as $key => $t): ?>
        <div class="report-tab <?= $key === 'funnel' ? 'active' : '' ?>" data-report="<?= $key ?>" onclick="switchTab('<?= $key ?>')">
            <i class="bi <?= $t['icon'] ?> me-1"></i><?= $t['label'] ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Report content area -->
    <div id="reportContent">
        <div class="text-center py-5 text-muted">
            <div class="spinner-border spinner-border-sm me-1"></div> Loading report…
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const REPORT_URL = '<?= buildUrl('api/crm/get_reports_data.php') ?>';
let currentReport = 'funnel';
let chartInstance = null;

function setPeriod(p) {
    const now = new Date();
    let from, to = now.toISOString().split('T')[0];
    if (p === 'month') {
        from = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
    } else if (p === 'quarter') {
        const q = Math.floor(now.getMonth() / 3);
        from = new Date(now.getFullYear(), q * 3, 1).toISOString().split('T')[0];
    } else {
        from = now.getFullYear() + '-01-01';
    }
    $('#rptFrom').val(from); $('#rptTo').val(to);
    loadReport();
}

function switchTab(report) {
    currentReport = report;
    $('.report-tab').removeClass('active');
    $(`.report-tab[data-report="${report}"]`).addClass('active');
    loadReport();
}

function loadReport() {
    $('#reportContent').html('<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-1"></div> Loading…</div>');
    if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
    $.getJSON(REPORT_URL, { report: currentReport, from: $('#rptFrom').val(), to: $('#rptTo').val() })
        .done(res => {
            if (!res.success) { $('#reportContent').html(`<div class="alert alert-danger">${res.message}</div>`); return; }
            renderReport(currentReport, res);
        })
        .fail(() => { $('#reportContent').html('<div class="alert alert-danger">Failed to load report.</div>'); });
}

const fmt  = n => Number(n||0).toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:0});
const esc  = s => String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const pct  = n => (parseFloat(n)||0).toFixed(1) + '%';

function renderReport(report, res) {
    let html = '';
    switch (report) {
        case 'funnel': {
            const data = res.data || [];
            if (!data.length) { $('#reportContent').html('<div class="alert alert-info">No data for selected period.</div>'); return; }
            html = `<div class="row g-4">
                <div class="col-lg-5">
                    <canvas id="rptChart" style="max-height:350px"></canvas>
                </div>
                <div class="col-lg-7">
                    <div class="table-responsive"><table class="table table-hover align-middle">
                        <thead class="table-light"><tr><th>Stage</th><th class="text-end">Leads</th><th class="text-end">Value (TZS)</th></tr></thead>
                        <tbody>`;
            data.forEach(s => {
                html += `<tr>
                    <td><span class="badge me-2" style="background:${esc(s.color)}">&nbsp;</span>${esc(s.stage_name)}</td>
                    <td class="text-end fw-bold">${fmt(s.lead_count)}</td>
                    <td class="text-end">${fmt(s.total_value)}</td>
                </tr>`;
            });
            html += `</tbody></table></div></div></div>`;
            $('#reportContent').html(html);
            const ctx = document.getElementById('rptChart').getContext('2d');
            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(s => s.stage_name),
                    datasets: [{ label: 'Leads', data: data.map(s => s.lead_count),
                        backgroundColor: data.map(s => s.color + 'cc'), borderColor: data.map(s => s.color), borderWidth: 1 }]
                },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
            break;
        }

        case 'agent': {
            const data = res.data || [];
            html = `<div class="table-responsive"><table class="table table-hover align-middle">
                <thead class="table-light"><tr><th>Agent</th><th class="text-end">Leads</th><th class="text-end">Won</th><th class="text-end">Lost</th><th class="text-end">Win Rate</th><th class="text-end">Avg Won Value (TZS)</th><th class="text-end">Total Won (TZS)</th><th class="text-end">Activities</th></tr></thead>
                <tbody>`;
            if (!data.length) html += '<tr><td colspan="8" class="text-center text-muted py-4">No data.</td></tr>';
            data.forEach(a => {
                html += `<tr>
                    <td class="fw-semibold">${esc(a.agent_name||'Unassigned')}</td>
                    <td class="text-end">${fmt(a.total_leads)}</td>
                    <td class="text-end text-primary fw-bold">${fmt(a.won_leads)}</td>
                    <td class="text-end text-danger">${fmt(a.lost_leads)}</td>
                    <td class="text-end">${pct(a.win_rate)}</td>
                    <td class="text-end">${fmt(a.avg_won_value)}</td>
                    <td class="text-end fw-bold">${fmt(a.total_won_value)}</td>
                    <td class="text-end">${fmt(a.activities_logged)}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            $('#reportContent').html(html);
            break;
        }

        case 'activity': {
            const data = res.data || [];
            const typeLabels = { call:'Call', email:'Email', meeting:'Meeting', note:'Note', task:'Task', site_visit:'Site Visit' };
            html = `<div class="table-responsive"><table class="table table-hover align-middle">
                <thead class="table-light"><tr><th>Type</th><th>Agent</th><th class="text-end">Total</th><th class="text-end">Done</th><th class="text-end">Pending</th><th class="text-end">Overdue</th></tr></thead>
                <tbody>`;
            if (!data.length) html += '<tr><td colspan="6" class="text-center text-muted py-4">No activities in this period.</td></tr>';
            data.forEach(a => {
                html += `<tr>
                    <td><span class="badge" style="background:#e7f0ff;color:#0d6efd">${esc(typeLabels[a.activity_type]||a.activity_type)}</span></td>
                    <td>${esc(a.agent_name||'—')}</td>
                    <td class="text-end fw-bold">${fmt(a.total)}</td>
                    <td class="text-end text-primary">${fmt(a.done)}</td>
                    <td class="text-end text-muted">${fmt(a.pending)}</td>
                    <td class="text-end text-danger">${fmt(a.overdue)}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            $('#reportContent').html(html);
            break;
        }

        case 'forecast': {
            const data = res.data || [];
            const t = res.totals || {};
            html = `<div class="row g-3 mb-4">
                <div class="col-4"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                    <div class="fs-5 fw-bold text-primary">${fmt(t['30d']??0)}</div><div class="small text-muted">30-Day Weighted (TZS)</div>
                </div></div>
                <div class="col-4"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                    <div class="fs-5 fw-bold text-primary">${fmt(t['60d']??0)}</div><div class="small text-muted">60-Day Weighted (TZS)</div>
                </div></div>
                <div class="col-4"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                    <div class="fs-5 fw-bold text-primary">${fmt(t['90d']??0)}</div><div class="small text-muted">90-Day Weighted (TZS)</div>
                </div></div>
            </div>
            <div class="table-responsive"><table class="table table-hover align-middle">
                <thead class="table-light"><tr><th>Lead</th><th>Stage</th><th class="text-end">Value (TZS)</th><th class="text-end">Prob%</th><th class="text-end">Weighted (TZS)</th><th>Close Date</th><th>Assigned</th></tr></thead>
                <tbody>`;
            if (!data.length) html += '<tr><td colspan="7" class="text-center text-muted py-4">No open leads with close dates in next 90 days.</td></tr>';
            data.forEach(r => {
                html += `<tr>
                    <td><div class="fw-semibold">${esc(r.first_name)} ${esc(r.last_name||'')}</div><small class="text-muted">${esc(r.company_name||'')}</small></td>
                    <td>${esc(r.stage_name||'—')}</td>
                    <td class="text-end">${fmt(r.lead_value)}</td>
                    <td class="text-end">${r.probability}%</td>
                    <td class="text-end fw-bold text-primary">${fmt(r.weighted_value)}</td>
                    <td>${esc(r.expected_close_date||'—')}</td>
                    <td>${esc(r.assigned_name||'—')}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            $('#reportContent').html(html);
            break;
        }

        case 'winloss': {
            const lost = res.lost || [], won = res.won || {};
            html = `<div class="row g-3 mb-4">
                <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                    <div class="fs-4 fw-bold text-primary">${fmt(won.won_count||0)}</div><div class="small text-muted">Deals Won</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                    <div class="fs-4 fw-bold text-primary">${fmt(won.won_value||0)}</div><div class="small text-muted">Won Value (TZS)</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                    <div class="fs-4 fw-bold text-danger">${lost.reduce((s,r)=>s+parseInt(r.count),0)}</div><div class="small text-muted">Deals Lost</div>
                </div></div>
            </div>
            <div class="table-responsive"><table class="table table-hover align-middle">
                <thead class="table-light"><tr><th>Lost Reason</th><th>Stage Lost At</th><th class="text-end">Count</th><th class="text-end">Value (TZS)</th></tr></thead>
                <tbody>`;
            if (!lost.length) html += '<tr><td colspan="4" class="text-center text-muted py-4">No lost leads in this period.</td></tr>';
            lost.forEach(r => {
                html += `<tr>
                    <td>${esc(r.lost_reason||'(No reason given)')}</td>
                    <td>${esc(r.lost_at_stage||'—')}</td>
                    <td class="text-end">${fmt(r.count)}</td>
                    <td class="text-end">${fmt(r.total_value)}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            $('#reportContent').html(html);
            break;
        }

        case 'campaign': {
            const data = res.data || [];
            html = `<div class="table-responsive"><table class="table table-hover align-middle">
                <thead class="table-light"><tr><th>Campaign</th><th>Type</th><th class="text-end">Budget (TZS)</th><th class="text-end">Leads</th><th class="text-end">Converted</th><th class="text-end">Win Rate</th><th class="text-end">Won Value (TZS)</th><th class="text-end">ROI %</th></tr></thead>
                <tbody>`;
            if (!data.length) html += '<tr><td colspan="8" class="text-center text-muted py-4">No campaign data.</td></tr>';
            data.forEach(c => {
                const roi = c.roi_pct !== null ? c.roi_pct + '%' : '—';
                html += `<tr>
                    <td class="fw-semibold">${esc(c.campaign_name)}</td>
                    <td>${esc(c.type)}</td>
                    <td class="text-end">${fmt(c.budget)}</td>
                    <td class="text-end">${fmt(c.leads_count)}</td>
                    <td class="text-end">${fmt(c.converted_count)}</td>
                    <td class="text-end">${pct(c.conversion_rate)}</td>
                    <td class="text-end fw-bold text-primary">${fmt(c.won_value)}</td>
                    <td class="text-end">${roi}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            $('#reportContent').html(html);
            break;
        }

        case 'source': {
            const data = res.data || [];
            const sourceLabel = { website:'Website', referral:'Referral', walk_in:'Walk-in', phone_call:'Phone Call',
                social_media:'Social Media', exhibition:'Exhibition', cold_call:'Cold Call', email_campaign:'Email Campaign', other:'Other' };
            html = `<div class="table-responsive"><table class="table table-hover align-middle">
                <thead class="table-light"><tr><th>Source</th><th class="text-end">Total Leads</th><th class="text-end">Won</th><th class="text-end">Converted</th><th class="text-end">Win Rate</th><th class="text-end">Avg Value (TZS)</th><th class="text-end">Won Value (TZS)</th></tr></thead>
                <tbody>`;
            if (!data.length) html += '<tr><td colspan="7" class="text-center text-muted py-4">No data.</td></tr>';
            data.forEach(s => {
                html += `<tr>
                    <td class="fw-semibold">${esc(sourceLabel[s.lead_source]||s.lead_source)}</td>
                    <td class="text-end">${fmt(s.total)}</td>
                    <td class="text-end text-primary fw-bold">${fmt(s.won)}</td>
                    <td class="text-end">${fmt(s.converted)}</td>
                    <td class="text-end">${pct(s.win_rate)}</td>
                    <td class="text-end">${fmt(s.avg_value)}</td>
                    <td class="text-end">${fmt(s.won_value)}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            $('#reportContent').html(html);
            break;
        }
    }
}

$(function () { loadReport(); });
</script>

<?php includeFooter(); ob_end_flush(); ?>
