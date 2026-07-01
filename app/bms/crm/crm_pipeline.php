<?php
ob_start();
$page_title = 'CRM Pipeline';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('crm_pipeline');
includeHeader();

$can_edit   = canEdit('crm_pipeline');
$can_create = canCreate('crm_leads');
$can_convert = canCreate('crm_convert');
$stages = $pdo->query("SELECT stage_id, stage_name, color, is_won, is_lost FROM crm_pipeline_stages WHERE status = 'active' ORDER BY stage_order ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.pipeline-board      { display:flex; gap:14px; overflow-x:auto; padding-bottom:16px; min-height:calc(100vh - 220px); align-items:flex-start; }
.pipeline-col        { flex:0 0 270px; min-width:270px; border-radius:10px; background:#f1f5fb; display:flex; flex-direction:column; max-height:calc(100vh - 220px); }
.pipeline-col-header { padding:10px 12px; border-radius:10px 10px 0 0; display:flex; justify-content:space-between; align-items:center; }
.pipeline-col-header .col-name  { font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#fff; }
.pipeline-col-header .col-count { font-size:.78rem; background:rgba(255,255,255,.25); color:#fff; border-radius:20px; padding:1px 8px; }
.pipeline-col-header .col-value { font-size:.72rem; color:rgba(255,255,255,.85); margin-top:2px; }
.pipeline-cards      { flex:1; overflow-y:auto; padding:8px; display:flex; flex-direction:column; gap:8px; min-height:60px; }
.pipeline-card       { background:#fff; border-radius:8px; padding:10px 12px; box-shadow:0 1px 4px rgba(0,0,0,.09); cursor:grab; border-left:3px solid transparent; transition:box-shadow .15s, transform .1s; }
.pipeline-card:active{ cursor:grabbing; }
.pipeline-card:hover { box-shadow:0 3px 10px rgba(0,0,0,.14); transform:translateY(-1px); }
.pipeline-card .lead-name    { font-weight:600; font-size:.88rem; color:#212529; }
.pipeline-card .lead-company { font-size:.78rem; color:#6c757d; }
.pipeline-card .lead-value   { font-size:.80rem; font-weight:700; color:#0d6efd; }
.pipeline-card .lead-meta    { font-size:.72rem; color:#6c757d; margin-top:4px; display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.pipeline-card .prob-bar     { height:3px; border-radius:3px; background:#e9ecef; margin-top:6px; overflow:hidden; }
.pipeline-card .prob-fill    { height:3px; border-radius:3px; }
.avatar-chip { display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; border-radius:50%; font-size:.65rem; font-weight:700; color:#fff; background:#6c757d; flex-shrink:0; }
.sortable-ghost { opacity:.4; background:#cfe2ff !important; }
.pipeline-col-add   { padding:6px 8px; }
.pipeline-col-add button { width:100%; font-size:.78rem; border:1px dashed #bbb; background:transparent; border-radius:6px; color:#6c757d; padding:5px; transition:background .15s; }
.pipeline-col-add button:hover { background:#e9ecef; }
/* Mobile accordion */
@media (max-width:767px) {
    .pipeline-board { flex-direction:column; overflow-x:visible; }
    .pipeline-col   { flex:none; width:100%; min-width:unset; max-height:none; }
    .pipeline-cards { max-height:400px; }
}
</style>

<div class="container-fluid mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-kanban text-primary me-2"></i>Pipeline Board</h4>
            <p class="text-muted small mb-0">Drag leads between stages to update progress</p>
        </div>
        <div class="d-flex gap-2">
            <?php if (canEdit('crm_pipeline') && isAdmin()): ?>
            <a href="<?= getUrl('crm/pipeline_stages') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear me-1"></i> Manage Stages
            </a>
            <?php endif; ?>
            <?php if ($can_create): ?>
            <button class="btn btn-primary btn-sm" onclick="openAddLeadModal()">
                <i class="bi bi-plus-circle me-1"></i> Add Lead
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div id="boardWrap">
        <div class="pipeline-board" id="pipelineBoard">
            <div class="text-center text-muted py-5 w-100" id="boardLoading">
                <div class="spinner-border spinner-border-sm me-2"></div> Loading pipeline…
            </div>
        </div>
    </div>
</div>

<!-- Lost reason modal -->
<div class="modal fade" id="lostModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title mb-0"><i class="bi bi-x-circle me-1"></i>Mark as Lost</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-bold">Reason for losing this lead <span class="text-muted fw-normal">(optional)</span></label>
                <textarea class="form-control form-control-sm" id="lostReasonInput" rows="3" placeholder="e.g. Budget, Competitor chosen…"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-secondary btn-sm" id="lostCancelBtn">Cancel (undo move)</button>
                <button class="btn btn-danger btn-sm" id="lostConfirmBtn">Confirm Lost</button>
            </div>
        </div>
    </div>
</div>

<!-- Quick-view offcanvas -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="quickViewCanvas" style="width:360px">
    <div class="offcanvas-header bg-primary text-white">
        <h6 class="offcanvas-title mb-0"><i class="bi bi-eye me-2"></i>Lead Quick View</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0" id="quickViewBody">
        <div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
const PIPELINE_URL = '<?= buildUrl('api/crm/get_pipeline_data.php') ?>';
const MOVE_URL     = '<?= buildUrl('api/crm/move_lead_stage.php') ?>';
const LEAD_VIEW    = '<?= getUrl('crm/lead_view') ?>';
const CAN_EDIT     = <?= json_encode($can_edit) ?>;
const CAN_CONVERT  = <?= json_encode($can_convert) ?>;
const CSRF         = '<?= csrf_token() ?>';
const fmt = n => 'TZS ' + Number(n||0).toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:0});
const esc = s => String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

let _pendingMove = null; // {leadId, newStageId, fromStageId, cardEl, fromEl}
let stagesData   = [];

function probColor(p) {
    p = parseInt(p)||0;
    if (p >= 80) return '#198754';
    if (p >= 50) return '#ffc107';
    return '#dc3545';
}

function cardHtml(lead, stageColor) {
    const initials = ((lead.first_name||'')[0]||'').toUpperCase();
    const name = esc((lead.first_name||'') + ' ' + (lead.last_name||'')).trim();
    const company = lead.company_name ? `<div class="lead-company">${esc(lead.company_name)}</div>` : '';
    const closeDate = lead.expected_close_date
        ? `<span><i class="bi bi-calendar2 me-1"></i>${lead.expected_close_date}</span>` : '';
    const daysChip = parseInt(lead.days_in_stage) > 7
        ? `<span class="badge" style="background:#cfe2ff;color:#084298;font-size:.65rem">${lead.days_in_stage}d</span>` : '';
    const assignee = lead.assigned_user_name
        ? `<span class="avatar-chip" title="${esc(lead.assigned_user_name)}" style="background:${stageColor}">${(lead.assigned_user_name[0]||'').toUpperCase()}</span>` : '';
    const converted = parseInt(lead.converted) ? `<span class="badge bg-success" style="font-size:.62rem">Converted</span>` : '';
    return `
    <div class="pipeline-card" data-id="${lead.lead_id}" data-stage="${lead.pipeline_stage_id}" onclick="goLead(${lead.lead_id})">
        <div class="lead-name">${name} ${converted}</div>
        ${company}
        <div class="lead-value">${fmt(lead.lead_value)}</div>
        <div class="prob-bar"><div class="prob-fill" style="width:${lead.probability}%;background:${probColor(lead.probability)}"></div></div>
        <div class="lead-meta">
            ${closeDate}
            ${daysChip}
            ${assignee}
        </div>
    </div>`;
}

function renderBoard(data) {
    stagesData = data;
    let html = '';
    data.forEach(stage => {
        const hdr = `
        <div class="pipeline-col-header" style="background:${stage.color}">
            <div>
                <div class="col-name">${esc(stage.stage_name)}</div>
                <div class="col-value">${fmt(stage.total_value)}</div>
            </div>
            <span class="col-count">${stage.count}</span>
        </div>`;
        let cards = '';
        stage.leads.forEach(lead => { cards += cardHtml(lead, stage.color); });
        const addBtn = CAN_EDIT ? `<div class="pipeline-col-add"><button onclick="openAddLeadModal(${stage.stage_id})"><i class="bi bi-plus"></i> Add Lead</button></div>` : '';
        html += `<div class="pipeline-col" data-stage-id="${stage.stage_id}" data-is-won="${stage.is_won?1:0}" data-is-lost="${stage.is_lost?1:0}">
            ${hdr}
            <div class="pipeline-cards" id="col-${stage.stage_id}">${cards}</div>
            ${addBtn}
        </div>`;
    });
    document.getElementById('pipelineBoard').innerHTML = html;
    initSortable();
}

function initSortable() {
    if (!CAN_EDIT) return;
    document.querySelectorAll('.pipeline-cards').forEach(el => {
        Sortable.create(el, {
            group: 'pipeline',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function(evt) {
                const leadId    = parseInt(evt.item.dataset.id);
                const newStageId = parseInt(evt.to.closest('.pipeline-col').dataset.stageId);
                const oldStageId = parseInt(evt.from.closest('.pipeline-col').dataset.stageId);
                if (newStageId === oldStageId) return;

                const isLost = evt.to.closest('.pipeline-col').dataset.isLost === '1';
                if (isLost) {
                    _pendingMove = { leadId, newStageId, oldStageId, cardEl: evt.item, fromEl: evt.from };
                    document.getElementById('lostReasonInput').value = '';
                    new bootstrap.Modal(document.getElementById('lostModal')).show();
                } else {
                    doMove(leadId, newStageId, '');
                }
            }
        });
    });
}

function doMove(leadId, newStageId, lostReason) {
    $.post(MOVE_URL, { lead_id: leadId, new_stage_id: newStageId, lost_reason: lostReason, _csrf: CSRF })
        .done(res => {
            if (!res.success) {
                Swal.fire({ icon:'error', title:'Error', text: res.message });
                loadBoard(); // re-render from server
            }
            // On success the card is already in position from the drag
            updateColCounts();
        })
        .fail(() => { Swal.fire({icon:'error',title:'Error',text:'Server error'}); loadBoard(); });
}

function updateColCounts() {
    document.querySelectorAll('.pipeline-col').forEach(col => {
        const count = col.querySelectorAll('.pipeline-card').length;
        col.querySelector('.col-count').textContent = count;
    });
}

const LEAD_DATA_URL = '<?= buildUrl('api/crm/get_lead.php') ?>';
const ACT_LIST_URL  = '<?= buildUrl('api/crm/get_activities.php') ?>';

function goLead(id) {
    // On small screens navigate directly; on desktop show quick-view
    if (window.innerWidth < 768) {
        window.location.href = LEAD_VIEW + '?id=' + id;
        return;
    }
    openQuickView(id);
}

function openQuickView(id) {
    $('#quickViewBody').html('<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm"></div></div>');
    new bootstrap.Offcanvas(document.getElementById('quickViewCanvas')).show();
    $.getJSON(LEAD_DATA_URL, { id: id }, function (res) {
        if (!res.success) { $('#quickViewBody').html('<div class="alert alert-danger m-3">Failed to load lead.</div>'); return; }
        const d = res.data;
        const fmt = n => Number(n||0).toLocaleString(undefined,{minimumFractionDigits:0});
        const fmtD = s => s ? new Date(s).toLocaleDateString(undefined,{day:'2-digit',month:'short',year:'numeric'}) : '—';
        const scoreCol = d.lead_score >= 70 ? '#198754' : (d.lead_score >= 40 ? '#ffc107' : '#dc3545');
        let html = `<div class="p-3">
            <div class="fw-bold mb-1" style="font-size:1rem">${esc(d.first_name||'')} ${esc(d.last_name||'')}</div>
            ${d.company_name ? `<div class="text-muted small mb-2"><i class="bi bi-building me-1"></i>${esc(d.company_name)}</div>` : ''}
            <div class="d-flex gap-2 flex-wrap mb-3">
                <span class="badge" style="background:${esc(d.stage_color||'#6c757d')};color:#fff">${esc(d.stage_name||'—')}</span>
                <span class="badge" style="background:${scoreCol}22;color:${scoreCol};border:1px solid ${scoreCol}">Score: ${d.lead_score||0}</span>
            </div>
            <table class="table table-sm table-borderless mb-3" style="font-size:.84rem">
                <tr><td class="text-muted">Value</td><td class="fw-bold text-primary">TZS ${fmt(d.lead_value)}</td></tr>
                <tr><td class="text-muted">Probability</td><td>${d.probability}%</td></tr>
                <tr><td class="text-muted">Source</td><td>${esc(d.lead_source||'—')}</td></tr>
                ${d.assigned_name ? `<tr><td class="text-muted">Assigned</td><td>${esc(d.assigned_name)}</td></tr>` : ''}
                ${d.expected_close_date ? `<tr><td class="text-muted">Close Date</td><td>${fmtD(d.expected_close_date)}</td></tr>` : ''}
                ${d.last_activity ? `<tr><td class="text-muted">Last Activity</td><td>${fmtD(d.last_activity)}</td></tr>` : ''}
            </table>
            <div id="qv-activities" class="mb-3"><div class="text-muted small"><div class="spinner-border spinner-border-sm me-1"></div> Loading activities…</div></div>
            <div class="d-grid gap-2">
                <a href="${LEAD_VIEW}?id=${id}" class="btn btn-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Open Full View</a>
            </div>
        </div>`;
        $('#quickViewBody').html(html);

        // Load last 3 activities
        $.getJSON(ACT_LIST_URL, { lead_id: id }, function (ar) {
            if (!ar.success || !ar.data.length) { $('#qv-activities').html('<div class="small text-muted">No activities yet.</div>'); return; }
            const recent = ar.data.slice(0, 3);
            const icons = { call:'bi-telephone', email:'bi-envelope', meeting:'bi-people', note:'bi-sticky', task:'bi-check2-square', site_visit:'bi-geo-alt' };
            let ahtml = recent.map(a => `
                <div class="d-flex align-items-start gap-2 mb-2">
                    <i class="bi ${icons[a.activity_type]||'bi-dot'} text-primary mt-1" style="flex-shrink:0"></i>
                    <div style="font-size:.81rem">
                        <div class="fw-semibold">${esc(a.subject)}</div>
                        <div class="text-muted">${fmtD(a.activity_date)}</div>
                    </div>
                </div>`).join('');
            $('#qv-activities').html(`<div class="fw-semibold mb-1" style="font-size:.78rem;text-transform:uppercase;color:#6c757d">Recent Activities</div>${ahtml}`);
        });
    });
}

function openAddLeadModal(stageId) {
    // Redirect to leads page with stage pre-selected (the leads page owns the add modal)
    let url = '<?= getUrl('crm/leads') ?>';
    if (stageId) url += '?add=1&stage_id=' + stageId;
    else url += '?add=1';
    window.location.href = url;
}

function loadBoard() {
    $.getJSON(PIPELINE_URL)
        .done(res => {
            if (res.success) { renderBoard(res.data); }
            else { $('#pipelineBoard').html('<div class="alert alert-danger m-3">' + (res.message||'Failed to load pipeline') + '</div>'); }
        })
        .fail(() => { $('#pipelineBoard').html('<div class="alert alert-danger m-3">Server error loading pipeline.</div>'); });
}

// Lost modal handlers
document.getElementById('lostConfirmBtn').addEventListener('click', function() {
    if (!_pendingMove) return;
    bootstrap.Modal.getInstance(document.getElementById('lostModal')).hide();
    doMove(_pendingMove.leadId, _pendingMove.newStageId, document.getElementById('lostReasonInput').value.trim());
    _pendingMove = null;
});
document.getElementById('lostCancelBtn').addEventListener('click', function() {
    bootstrap.Modal.getInstance(document.getElementById('lostModal')).hide();
    if (_pendingMove) {
        // move card back to original column
        _pendingMove.fromEl.appendChild(_pendingMove.cardEl);
        _pendingMove = null;
    }
});

$(function(){ loadBoard(); });
</script>

<?php includeFooter(); ob_end_flush(); ?>
