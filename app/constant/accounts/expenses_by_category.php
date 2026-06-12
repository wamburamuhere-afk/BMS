<?php
// app/constant/accounts/expenses_by_category.php
// "Expenses by Category" — Type → Category → Sub-category tree with roll-up
// (parent total = own + all descendants), switchable between collapsed-to-Types
// and drill-to-each-expense. Reads api/account/get_expenses_by_category.php
// (read-only; attribution resolved from the live map, nothing mutated).
//
// The drill-down expense rows carry the SAME status-gated action menu as the
// expenses list: View Details, Print Voucher, Edit (pending/reviewed), the one
// permitted status transition (pending→reviewed→approved→paid / reject), Delete.
// Status & Delete call the same APIs inline; View/Edit/Print open the detail page.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('expenses');
includeHeader();

$can_edit   = canEdit('expenses');
$can_delete = canDelete('expenses');
$year       = date('Y');
?>

<div class="container-fluid py-4 px-4">
    <nav aria-label="breadcrumb" class="d-print-none">
        <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('expenses') ?>" class="text-decoration-none">Expenses</a></li>
            <li class="breadcrumb-item active">By Category</li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold text-primary mb-1"><i class="bi bi-diagram-3-fill me-2"></i>Expenses by Category</h2>
            <p class="text-muted mb-0">Spend rolled up across Type → Category → Sub-category</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('expenses') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back to Expenses</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold text-primary" id="stat-total">0.00</div><div class="small text-muted">Total Expenses</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold text-success" id="stat-types">0</div><div class="small text-muted">Expense Types</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold" style="color:#052c65" id="stat-records">0</div><div class="small text-muted">Expenses</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold text-warning" id="stat-uncat">0.00</div><div class="small text-muted">Uncategorised</div></div></div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" class="form-control form-control-sm" id="f_from" value="<?= $year ?>-01-01">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" class="form-control form-control-sm" id="f_to" value="<?= $year ?>-12-31">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select class="form-select form-select-sm" id="f_status">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="approved">Approved</option>
                        <option value="paid">Paid</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex gap-2">
                    <button class="btn btn-primary btn-sm flex-fill" onclick="loadTree()"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TREE VIEW ──────────────────────────────────────────────────────── -->
    <div id="treeView">
        <!-- Spend-by-Type chart -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="mb-0 fw-bold"><i class="bi bi-pie-chart-fill text-primary me-2"></i>Spend by Type</h6>
            </div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-5"><div style="height:230px;"><canvas id="typeChart"></canvas></div></div>
                    <div class="col-md-7"><div id="typeLegend" class="row g-2"></div></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <!-- Tabs (built from data) -->
                <ul class="nav nav-tabs mb-3 flex-nowrap" id="typeTabs" style="overflow-x:auto; white-space:nowrap;"></ul>

                <!-- Toolbar -->
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <button class="btn btn-sm btn-primary" id="btnCollapse" onclick="setCollapsed(true)"><i class="bi bi-dash-square me-1"></i>Collapse to Types</button>
                    <button class="btn btn-sm btn-outline-secondary" id="btnExpand" onclick="setCollapsed(false)"><i class="bi bi-plus-square me-1"></i>Expand All</button>
                    <span class="ms-auto badge rounded-pill" id="reconcileBadge"></span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="treeTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:30px;"></th>
                                <th>Type / Category</th>
                                <th class="text-end">Spend (incl. sub)</th>
                                <th class="text-center" style="width:90px;"># Exp.</th>
                                <th class="text-center" style="width:70px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="treeBody">
                            <tr><td colspan="5" class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm text-primary"></div> Loading…</td></tr>
                        </tbody>
                        <tfoot id="treeFoot"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── DRILL VIEW ─────────────────────────────────────────────────────── -->
    <div id="drillView" class="d-none">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
                <div>
                    <div class="small text-muted" id="drillCrumb"></div>
                    <h5 class="fw-bold mb-0" id="drillTitle">—</h5>
                </div>
                <div class="text-end">
                    <div class="small text-muted text-uppercase fw-bold" style="font-size:.6rem;">Subtree Total</div>
                    <div class="fs-4 fw-bold text-success" id="drillTotal">0.00</div>
                </div>
            </div>
            <div class="card-body">
                <button class="btn btn-sm btn-outline-secondary mb-3" onclick="backToTree()"><i class="bi bi-arrow-left me-1"></i>Back to tree</button>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width:54px;">S/NO</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Paid To</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end" style="width:70px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="drillBody"></tbody>
                        <tfoot id="drillFoot"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const API_TREE   = '<?= buildUrl('api/account/get_expenses_by_category.php') ?>';
const URL_DETAILS= '<?= getUrl('expenses/details') ?>';
const URL_EDIT   = '<?= getUrl('expenses') ?>';
const CAN_EDIT   = <?= $can_edit ? 'true' : 'false' ?>;
const CAN_DELETE = <?= $can_delete ? 'true' : 'false' ?>;

let TREE = null;          // last tree payload
let activeType = '';      // '' = all
let collapsed = false;

const fmt = v => parseFloat(v||0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
const esc = t => $('<div>').text(t==null?'':t).html();
function badgeClass(s){ return s==='approved'?'success':s==='reviewed'?'primary':s==='pending'?'warning':s==='rejected'?'danger':s==='paid'?'info':'secondary'; }

// ── Load + render the tree ────────────────────────────────────────────────
function loadTree(){
    const p = { mode:'tree', date_from:$('#f_from').val(), date_to:$('#f_to').val(), status:$('#f_status').val() };
    $('#treeBody').html('<tr><td colspan="5" class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm text-primary"></div> Loading…</td></tr>');
    $.getJSON(API_TREE, p).done(res => {
        if(!res || !res.success){ $('#treeBody').html('<tr><td colspan="5" class="text-center text-danger py-4">Could not load.</td></tr>'); return; }
        TREE = res;
        $('#stat-total').text(fmt(res.grand_total));
        $('#stat-types').text(res.types.filter(t=>t.total_spend>0).length);
        $('#stat-records').text(res.expense_count);
        $('#stat-uncat').text(fmt(res.uncategorised.total));
        const rb = $('#reconcileBadge');
        if(res.reconciles){ rb.attr('class','badge rounded-pill bg-success-subtle text-success border border-success').html('<i class="bi bi-check-circle me-1"></i>Reconciles to total'); }
        else { rb.attr('class','badge rounded-pill bg-danger-subtle text-danger border border-danger').html('Does not reconcile'); }
        renderTabs();
        renderTree();
        buildChart();
    }).fail(()=> $('#treeBody').html('<tr><td colspan="5" class="text-center text-danger py-4">Server error.</td></tr>'));
}

// ── Donut: spend by Type (+ Uncategorised) ────────────────────────────────
const PALETTE = ['#0d6efd','#20c997','#fd7e14','#6f42c1','#d63384','#0dcaf0','#ffc107'];
let typeChart = null;
function buildChart(){
    const items = TREE.types.filter(t => parseFloat(t.total_spend) > 0)
        .map((t,i) => ({ name:t.name, val:parseFloat(t.total_spend), color:PALETTE[i % PALETTE.length] }));
    if(parseFloat(TREE.uncategorised.total) > 0)
        items.push({ name:'Uncategorised', val:parseFloat(TREE.uncategorised.total), color:'#adb5bd' });

    // Legend with values + share
    const total = TREE.grand_total || items.reduce((a,b)=>a+b.val,0);
    $('#typeLegend').html(items.map(it => {
        const pct = total>0 ? (it.val/total*100).toFixed(1) : '0.0';
        return `<div class="col-12 col-sm-6"><div class="d-flex align-items-center gap-2 small">
            <span class="rounded-circle" style="width:11px;height:11px;background:${it.color};flex:none;"></span>
            <span class="flex-grow-1 text-truncate">${esc(it.name)}</span>
            <span class="fw-bold">${fmt(it.val)}</span>
            <span class="text-muted" style="width:46px;text-align:right;">${pct}%</span>
        </div></div>`;
    }).join('') || '<div class="text-muted small">No spend in this period.</div>');

    if(typeChart){ typeChart.destroy(); typeChart = null; }
    if(!items.length) return;
    typeChart = new Chart(document.getElementById('typeChart'), {
        type: 'doughnut',
        data: { labels: items.map(i=>i.name),
                datasets: [{ data: items.map(i=>i.val), backgroundColor: items.map(i=>i.color), borderWidth: 1 }] },
        options: { responsive:true, maintainAspectRatio:false, cutout:'62%',
                   plugins: { legend:{ display:false },
                              tooltip:{ callbacks:{ label: c => ' ' + c.label + ': ' + fmt(c.raw) } } } }
    });
}

function renderTabs(){
    let html = `<li class="nav-item"><a class="nav-link ${activeType===''?'active':''}" href="#" onclick="selectTab('');return false;">All Types</a></li>`;
    TREE.types.forEach(t => {
        html += `<li class="nav-item"><a class="nav-link ${activeType==t.type_id?'active':''}" href="#" onclick="selectTab(${t.type_id});return false;">${esc(t.name)}</a></li>`;
    });
    $('#typeTabs').html(html);
}
function selectTab(tid){ activeType = tid; renderTabs(); renderTree(); }
function setCollapsed(v){ collapsed = v; $('#btnCollapse').toggleClass('btn-primary',v).toggleClass('btn-outline-secondary',!v); $('#btnExpand').toggleClass('btn-primary',!v).toggleClass('btn-outline-secondary',v); renderTree(); }

function renderTree(){
    let html = '', shownTotal = 0;
    const types = activeType==='' ? TREE.types : TREE.types.filter(t=>t.type_id==activeType);

    types.forEach((t,i) => {
        const accent = ['#0d6efd','#20c997','#fd7e14','#6f42c1','#d63384'][i % 5];
        shownTotal += parseFloat(t.total_spend);
        const share = TREE.grand_total>0 ? (t.total_spend/TREE.grand_total*100).toFixed(1) : '0.0';
        html += `<tr class="table-light fw-bold">
            <td><i class="bi bi-folder2-open" style="color:${accent}"></i></td>
            <td><span class="d-inline-block rounded-circle me-2" style="width:9px;height:9px;background:${accent}"></span>${esc(t.name)}
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1" style="font-size:.6rem;">Type</span></td>
            <td class="text-end fw-bold text-success">${fmt(t.total_spend)}<br><small class="text-muted fw-normal">${share}%</small></td>
            <td class="text-center"><span class="badge bg-secondary-subtle text-dark">${t.categories.reduce((a,c)=>a+c.rollup_count,0)}</span></td>
            <td class="text-center">${gearTree('type',t.type_id,t.name)}</td></tr>`;
        if(!collapsed) t.categories.forEach(c => html += renderCatRow(c, 1));
    });

    // Uncategorised group (always last, never hidden)
    if(TREE.uncategorised.total != 0 || TREE.uncategorised.count > 0){
        const u = TREE.uncategorised;
        const ushare = TREE.grand_total>0 ? (u.total/TREE.grand_total*100).toFixed(1) : '0.0';
        if(activeType===''){
            html += `<tr class="fw-bold" style="background:#fff8ec;">
                <td><i class="bi bi-question-circle text-warning"></i></td>
                <td class="text-muted">Uncategorised <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1" style="font-size:.6rem;">No category</span></td>
                <td class="text-end fw-bold text-warning">${fmt(u.total)}<br><small class="text-muted fw-normal">${ushare}%</small></td>
                <td class="text-center"><span class="badge bg-secondary-subtle text-dark">${u.count}</span></td>
                <td class="text-center">${gearTree('uncategorised',0,'Uncategorised')}</td></tr>`;
        }
    }

    $('#treeBody').html(html || '<tr><td colspan="5" class="text-center text-muted py-4">No expenses in this period.</td></tr>');

    const grand = activeType==='' ? TREE.grand_total : shownTotal;
    $('#treeFoot').html(`<tr style="border-top:2px solid #dee2e6;"><td></td>
        <td class="fw-bold">${activeType===''?'Grand Total':'Subtotal'}</td>
        <td class="text-end fw-bold text-primary">${fmt(grand)}</td><td></td><td></td></tr>`);
}

function renderCatRow(c, level){
    const pad = level * 22;
    const isParent = c.has_children;
    const amt = isParent
        ? `<span class="fw-bold text-success" title="Includes sub-categories">${fmt(c.rollup_spend)}</span><br><small class="text-muted" title="Own spend">own: ${fmt(c.own_spend)}</small>`
        : `<span>${fmt(c.own_spend)}</span>`;
    let html = `<tr>
        <td></td>
        <td><span style="padding-left:${pad}px;"><i class="bi ${isParent?'bi-folder':'bi-dot'} text-muted me-1"></i>${esc(c.name)}
            ${isParent?`<span class="badge bg-secondary-subtle text-dark ms-1" style="font-size:.6rem;">${c.children.length} sub</span>`:''}</span></td>
        <td class="text-end">${amt}</td>
        <td class="text-center"><span class="badge bg-secondary-subtle text-dark">${c.rollup_count}</span></td>
        <td class="text-center">${gearTree('category',c.id,c.name)}</td></tr>`;
    if(!collapsed && isParent) c.children.forEach(ch => html += renderCatRow(ch, level+1));
    return html;
}

// gear on a TREE node → drill into its expenses
function gearTree(nodeType, nodeId, name){
    return `<div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><a class="dropdown-item" href="#" onclick="drill('${nodeType}',${nodeId},'${esc(name).replace(/'/g,"\\'")}');return false;"><i class="bi bi-eye text-info"></i> View expenses</a></li>
        </ul></div>`;
}

// ── Drill: list every expense under a node ────────────────────────────────
function drill(nodeType, nodeId, name){
    $('#treeView').addClass('d-none'); $('#drillView').removeClass('d-none');
    $('#drillTitle').text(name); $('#drillCrumb').text('Expenses by Category › drill');
    $('#drillBody').html('<tr><td colspan="7" class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm text-primary"></div> Loading…</td></tr>');
    $('#drillFoot').html('');
    const p = { mode:'drill', node_type:nodeType, node_id:nodeId, date_from:$('#f_from').val(), date_to:$('#f_to').val(), status:$('#f_status').val() };
    $.getJSON(API_TREE, p).done(res => {
        if(!res || !res.success){ $('#drillBody').html('<tr><td colspan="7" class="text-center text-danger py-4">Could not load.</td></tr>'); return; }
        $('#drillTitle').text(res.node.name);
        $('#drillTotal').text(fmt(res.subtotal));
        if(!res.rows.length){ $('#drillBody').html('<tr><td colspan="7" class="text-center text-muted py-4">No expenses here.</td></tr>'); return; }
        let html='';
        res.rows.forEach((r,i) => {
            const dt = r.expense_date ? new Date(r.expense_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '-';
            html += `<tr>
                <td class="text-center text-muted fw-bold">${i+1}</td>
                <td>${dt}</td>
                <td><strong>${esc(r.description||'-')}</strong>${r.reference_number?`<br><small class="text-muted">${esc(r.reference_number)}</small>`:''}</td>
                <td>${esc(r.paid_to_name||'-')}${r.paid_to_type?` <span class="badge bg-light text-muted border" style="font-size:.6rem;">${esc(r.paid_to_type)}</span>`:''}</td>
                <td class="text-center"><span class="badge bg-${badgeClass(r.status)}">${(r.status||'').charAt(0).toUpperCase()+(r.status||'').slice(1)}</span></td>
                <td class="text-end fw-bold text-danger">${fmt(r.amount)}</td>
                <td class="text-end">${gearExpense(r)}</td></tr>`;
        });
        $('#drillBody').html(html);
        $('#drillFoot').html(`<tr style="border-top:2px solid #dee2e6;"><td colspan="5" class="text-end fw-bold">Subtotal</td><td class="text-end fw-bold text-primary">${fmt(res.subtotal)}</td><td></td></tr>`);
    }).fail(()=> $('#drillBody').html('<tr><td colspan="7" class="text-center text-danger py-4">Server error.</td></tr>'));
}
function backToTree(){ $('#drillView').addClass('d-none'); $('#treeView').removeClass('d-none'); }

// FROZEN expense action menu — identical items + status gating to the list.
// View/Edit/Print open the detail page; status + delete call the same APIs inline.
function gearExpense(r){
    const id = r.expense_id, s = r.status;
    let li = `<li><a class="dropdown-item" href="${URL_DETAILS}?id=${id}"><i class="bi bi-eye text-info"></i> View Details</a></li>`;
    li += `<li><a class="dropdown-item" href="${URL_DETAILS}?id=${id}"><i class="bi bi-printer text-secondary"></i> Print Voucher</a></li>`;
    if(CAN_EDIT && (s==='pending' || s==='reviewed')){
        li += `<li><hr class="dropdown-divider opacity-50"></li>`;
        li += `<li><a class="dropdown-item" href="${URL_EDIT}?edit=${id}"><i class="bi bi-pencil text-primary"></i> Edit Expense</a></li>`;
    }
    if(CAN_EDIT){
        if(s==='pending')      li += `<li><a class="dropdown-item" href="#" onclick="exStatus(${id},'reviewed');return false;"><i class="bi bi-search text-info"></i> Mark as Reviewed</a></li>`;
        else if(s==='reviewed'){ li += `<li><a class="dropdown-item" href="#" onclick="exStatus(${id},'approved');return false;"><i class="bi bi-check-circle text-success"></i> Approve</a></li>`;
                                 li += `<li><a class="dropdown-item" href="#" onclick="exStatus(${id},'rejected');return false;"><i class="bi bi-x-circle text-danger"></i> Reject</a></li>`; }
        else if(s==='approved') li += `<li><a class="dropdown-item" href="#" onclick="exStatus(${id},'paid');return false;"><i class="bi bi-cash text-success"></i> Mark as Paid</a></li>`;
    }
    if(CAN_DELETE){
        li += `<li><hr class="dropdown-divider opacity-50"></li>`;
        li += `<li><a class="dropdown-item text-danger" href="#" onclick="exDelete(${id});return false;"><i class="bi bi-trash"></i> Delete</a></li>`;
    }
    return `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">${li}</ul></div>`;
}

// Same APIs as the list; on success re-run the current drill so figures refresh.
let _lastDrill = null;
function rememberDrill(nt,nid,name){ _lastDrill = {nt,nid,name}; }
function reloadDrill(){ if(_lastDrill) drill(_lastDrill.nt,_lastDrill.nid,_lastDrill.name); }

function exStatus(id, status){
    Swal.fire({ title:'Update Status?', text:`Are you sure you want to mark this as ${status}?`, icon:'question', showCancelButton:true, confirmButtonText:'Yes, Proceed' })
    .then(r => { if(!r.isConfirmed) return;
        $.post('/api/update_expense_status.php', { expense_id:id, status:status }, res => {
            if(res.success){ if(typeof logReportAction==='function') logReportAction('Updated Expense Status','Expense #'+id+' → '+status);
                Swal.fire({ icon:'success', title:'Updated!', text:res.message, timer:1800, showConfirmButton:false }).then(()=>{ reloadDrill(); }); }
            else Swal.fire('Error', res.message, 'error');
        }, 'json').fail(()=>Swal.fire('Error','Server error','error'));
    });
}
function exDelete(id){
    Swal.fire({ title:'Delete Expense?', text:'Permanently delete this expense? This cannot be undone.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, Delete' })
    .then(r => { if(!r.isConfirmed) return;
        $.post('/api/delete_expense.php', { expense_id:id }, res => {
            if(res.success){ if(typeof logReportAction==='function') logReportAction('Deleted Expense Record','Expense #'+id);
                Swal.fire({ icon:'success', title:'Deleted!', text:'Expense record deleted.', timer:1800, showConfirmButton:false }).then(()=>{ reloadDrill(); }); }
            else Swal.fire('Error', res.message, 'error');
        }, 'json').fail(()=>Swal.fire('Error','Server error','error'));
    });
}

// remember the active drill so status/delete can refresh it
const _origDrill = drill;
drill = function(nt,nid,name){ rememberDrill(nt,nid,name); _origDrill(nt,nid,name); };

$(document).ready(loadTree);
</script>

<?php
includeFooter();
ob_end_flush();
