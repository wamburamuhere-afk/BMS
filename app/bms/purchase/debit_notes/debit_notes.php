<?php
// File: app/bms/purchase/debit_notes/debit_notes.php
// scope-audit: skip — debit_notes has no direct project_id; scope is enforced via
// the linked purchase_order (po) join below, like the purchase return list.
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('debit_notes');

includeHeader();

global $pdo;

$can_create = canCreate('debit_notes');
$can_edit   = canEdit('debit_notes');
$can_delete = canDelete('debit_notes');

$rows = [];
try {
    $query = "
        SELECT
            dn.debit_note_id,
            dn.debit_note_number,
            dn.debit_date,
            dn.grand_total,
            dn.status,
            dn.purchase_return_id,
            dn.purchase_order_id,
            s.supplier_name,
            pr.return_number
        FROM debit_notes dn
        LEFT JOIN suppliers s          ON dn.supplier_id        = s.supplier_id
        LEFT JOIN purchase_returns pr  ON dn.purchase_return_id = pr.purchase_return_id
        LEFT JOIN purchase_orders po   ON pr.purchase_order_id  = po.purchase_order_id
        WHERE dn.status != 'deleted'
    ";
    $query .= scopeFilterSqlNullable('project', 'po');
    $query .= " ORDER BY dn.debit_date DESC, dn.debit_note_id DESC";
    $stmt = $pdo->query($query);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}

$stat_total    = count($rows);
$stat_pending  = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
$stat_approved = count(array_filter($rows, fn($r) => $r['status'] === 'approved'));
$stat_paid     = count(array_filter($rows, fn($r) => $r['status'] === 'paid'));
$stat_value    = array_sum(array_map(fn($r) => (float)$r['grand_total'], $rows));

$jsRows = array_map(function ($r) {
    if (!empty($r['purchase_return_id'])) {
        $origin = 'Return ' . ($r['return_number'] ?: ('#' . $r['purchase_return_id']));
    } elseif (!empty($r['purchase_order_id'])) {
        $origin = 'PO #' . $r['purchase_order_id'];
    } else {
        $origin = 'Standalone';
    }
    return [
        'id'       => (int)$r['debit_note_id'],
        'number'   => $r['debit_note_number'],
        'date'     => $r['debit_date'] ? date('d M Y', strtotime($r['debit_date'])) : '—',
        'supplier' => $r['supplier_name'] ?: 'Supplier',
        'origin'   => $origin,
        'amount'   => (float)$r['grand_total'],
        'status'   => $r['status'],
    ];
}, $rows);
?>

<style>
    .dn-stat-card { background:#e7f0ff; border:1px solid #b6ccfe !important; border-radius:12px; }
    .dn-stat-card .stat-num { font-size:1.4rem; font-weight:700; }
    .badge-status { min-width:92px; display:inline-block; padding:.4em .6em; border-radius:50rem; font-size:.72rem; font-weight:600; }
    #dnCardView .dn-card { border:1px solid #e6ecf5; border-radius:12px; }
    @media (max-width:767px){ #dnTableWrap { display:none; } #dnCardView { display:flex; } }
    @media (min-width:768px){ #dnCardView { display:none; } }
</style>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Debit Notes</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Debit Notes</h4>
            <p class="text-muted small mb-0">Supplier debit notes — issue, approve and record the refund received</p>
        </div>
        <?php if ($can_create): ?>
        <a href="<?= getUrl('debit_note_create') ?>" class="btn btn-primary shadow-sm px-4">
            <i class="bi bi-plus-circle me-1"></i> New Debit Note
        </a>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card dn-stat-card text-center p-3 h-100"><div class="stat-num text-primary"><?= number_format($stat_total) ?></div><div class="small text-muted">Total Notes</div></div></div>
        <div class="col-6 col-md-3"><div class="card dn-stat-card text-center p-3 h-100"><div class="stat-num text-secondary"><?= number_format($stat_pending) ?></div><div class="small text-muted">Pending</div></div></div>
        <div class="col-6 col-md-3"><div class="card dn-stat-card text-center p-3 h-100"><div class="stat-num text-primary"><?= number_format($stat_approved) ?></div><div class="small text-muted">Approved</div></div></div>
        <div class="col-6 col-md-3"><div class="card dn-stat-card text-center p-3 h-100"><div class="stat-num" style="color:#052c65"><?= number_format($stat_value, 0) ?></div><div class="small text-muted">TZS Value (<?= number_format($stat_paid) ?> settled)</div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Supplier</label>
                    <select id="filterSupplier" class="form-select"><option value="">All Suppliers</option>
                        <?php $sup=[]; foreach ($jsRows as $jr){ $sup[$jr['supplier']]=true; } $sup=array_keys($sup); sort($sup); foreach ($sup as $s): ?>
                            <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>"><?= safe_output($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Status</label>
                    <select id="filterStatus" class="form-select"><option value="">All Statuses</option>
                        <option value="pending">Pending</option><option value="reviewed">Reviewed</option>
                        <option value="approved">Approved</option><option value="paid">Paid</option>
                        <option value="rejected">Rejected</option><option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Search</label>
                    <input type="text" id="filterSearch" class="form-control" placeholder="Number / origin...">
                </div>
                <div class="col-md-2">
                    <button id="btnResetFilters" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-clockwise me-1"></i> Reset</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm" id="dnTableWrap">
        <div class="table-responsive">
            <table id="dnTable" class="table table-hover align-middle w-100 mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Debit Note #</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Origin</th>
                        <th class="text-end">Amount (TZS)</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div id="dnCardView" class="row g-2"></div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
const DN_DATA  = <?= json_encode(array_values($jsRows), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const DN_PERMS = { edit: <?= json_encode($can_edit) ?>, del: <?= json_encode($can_delete) ?> };
const DN_VIEW  = 'debit_note_view';
const DN_EDIT  = 'debit_note_edit';
const DN_PRINT = 'print_debit_note';
const DN_PRINT_NAVY = 'print_debit_note_navy';
const DN_PRINT_CORPORATE = 'print_debit_note_corporate';
const DN_PRINT_BANDED = 'print_debit_note_banded';

const DN_BADGE = { pending:['#e9ecef','#495057'], reviewed:['#bfdbfe','#1e3a8a'], approved:['#0d6efd','#fff'], paid:['#052c65','#fff'], rejected:['#dc3545','#fff'], cancelled:['#6c757d','#fff'] };
function dnBadge(s){ const c=DN_BADGE[s]||['#e9ecef','#495057']; const l=s.charAt(0).toUpperCase()+s.slice(1); return `<span class="badge-status" style="background:${c[0]};color:${c[1]};">${l}</span>`; }
function dnMoney(v){ return Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }

function dnActions(row){
    let items = `<li><a class="dropdown-item py-2 rounded" href="${DN_VIEW}?id=${row.id}"><i class="bi bi-eye text-primary me-2"></i> View</a></li>`;
    items += `<li>
        <div class="d-flex align-items-center dropdown-item py-0 pe-1 rounded">
            <a class="flex-grow-1 py-2 text-decoration-none text-dark" href="${DN_PRINT}?id=${row.id}" target="_blank"><i class="bi bi-printer text-primary me-2"></i> Print</a>
            <button type="button" class="btn btn-sm border-0 p-1 text-muted" title="Choose a different template" onclick="event.stopPropagation(); $('#dnTplSub${row.id}').toggleClass('d-none'); $(this).find('i').toggleClass('bi-chevron-down bi-chevron-up');">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <ul class="list-unstyled ms-4 mb-1 d-none" id="dnTplSub${row.id}">
            <li><a class="dropdown-item py-1 small text-muted" href="${DN_PRINT_NAVY}?id=${row.id}" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Navy Template</a></li>
            <li><a class="dropdown-item py-1 small text-muted" href="${DN_PRINT_CORPORATE}?id=${row.id}" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Corporate Template</a></li>
            <li><a class="dropdown-item py-1 small text-muted" href="${DN_PRINT_BANDED}?id=${row.id}" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Banded Template</a></li>
        </ul>
    </li>`;
    if (DN_PERMS.edit && row.status === 'pending') items += `<li><a class="dropdown-item py-2 rounded" href="${DN_EDIT}?id=${row.id}"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>`;
    if (DN_PERMS.del && row.status !== 'paid') items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" onclick="dnDelete(${row.id})"><i class="bi bi-trash text-danger me-2"></i> Delete</button></li>`;
    return `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
}

let dnTable;
$(document).ready(function () {
    if (typeof logReportAction === 'function') logReportAction('Viewed Debit Notes List', 'User viewed the debit notes list');
    dnTable = $('#dnTable').DataTable({
        data: DN_DATA, responsive:false, scrollX:true, pageLength:25, order:[[1,'desc']], dom:'rtip',
        language:{ emptyTable:'No debit notes found.', zeroRecords:'No matching debit notes.' },
        columns:[
            { data:null, orderable:false, render:(d,t,r,m)=>m.row+1 },
            { data:'number' },
            { data:'date' },
            { data:'supplier', render:(d,t,r)=> t==='display' ? `<div class="fw-semibold">${$('<div>').text(r.supplier).html()}</div>` : r.supplier },
            { data:'origin' },
            { data:'amount', className:'text-end', render:(d,t)=> t==='display'?dnMoney(d):d },
            { data:'status', className:'text-center', render:(d,t)=> t==='display'?dnBadge(d):d },
            { data:null, orderable:false, searchable:false, className:'text-end', render:(d,t,r)=>dnActions(r) },
        ],
        drawCallback:function(){ dnRenderCards(this.api().rows({page:'current',search:'applied'}).data().toArray()); }
    });

    $('#filterSupplier').select2({ theme:'bootstrap-5', placeholder:'All Suppliers', allowClear:true, width:'100%' });
    $('#filterStatus').select2({ theme:'bootstrap-5', placeholder:'All Statuses', allowClear:true, width:'100%' });
    $('#filterSupplier').on('change', function(){ const v=this.value?'^'+$.fn.dataTable.util.escapeRegex(this.value)+'$':''; dnTable.column(3).search(v,true,false).draw(); });
    $('#filterStatus').on('change', function(){ const v=this.value?'^'+this.value+'$':''; dnTable.column(6).search(v,true,false).draw(); });
    $('#filterSearch').on('keyup', function(){ dnTable.search(this.value).draw(); });
    $('#btnResetFilters').on('click', function(){ $('#filterSupplier').val('').trigger('change.select2'); $('#filterStatus').val('').trigger('change.select2'); $('#filterSearch').val(''); dnTable.column(3).search('').column(6).search('').search('').draw(); });
});

function dnRenderCards(rows){
    const $cv=$('#dnCardView');
    if(!rows.length){ $cv.html('<div class="col-12 text-center py-5 text-muted">No debit notes found.</div>'); return; }
    let html='';
    rows.forEach(r=>{ const esc=s=>$('<div>').text(s==null?'':s).html();
        html+=`<div class="col-12"><div class="card dn-card shadow-sm"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start"><div><div class="fw-bold text-primary">${esc(r.number)}</div><small class="text-muted">${esc(r.date)} · ${esc(r.origin)}</small></div>${dnBadge(r.status)}</div>
            <div class="mt-2 fw-semibold">${esc(r.supplier)}</div><div class="fw-bold">TZS ${dnMoney(r.amount)}</div></div>
            <div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                <a class="btn btn-sm btn-outline-primary" style="flex:1" href="${DN_VIEW}?id=${r.id}"><i class="bi bi-eye"></i></a>
                <a class="btn btn-sm btn-outline-primary" style="flex:1" href="${DN_PRINT}?id=${r.id}" target="_blank"><i class="bi bi-printer"></i></a>
                ${DN_PERMS.edit && r.status==='pending' ? `<a class="btn btn-sm btn-outline-primary" style="flex:1" href="${DN_EDIT}?id=${r.id}"><i class="bi bi-pencil"></i></a>`:''}
                ${DN_PERMS.del && r.status!=='paid' ? `<button class="btn btn-sm btn-outline-danger" style="flex:1" onclick="dnDelete(${r.id})"><i class="bi bi-trash"></i></button>`:''}
            </div></div></div></div>`;
    });
    $cv.html(html);
}

function dnDelete(id){
    Swal.fire({ title:'Delete Debit Note?', text:'This cannot be undone.', icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, Delete' })
    .then(r=>{ if(!r.isConfirmed) return;
        $.ajax({ url:'<?= buildUrl('api/purchase/delete_debit_note.php') ?>', type:'POST', dataType:'json',
            data:{ debit_note_id:id, _csrf:(typeof CSRF_TOKEN!=='undefined'?CSRF_TOKEN:'') },
            success:function(res){ if(res.success){ const idx=dnTable.rows().indexes().toArray().find(i=>dnTable.row(i).data().id===id); if(idx!==undefined) dnTable.row(idx).remove().draw(false); Swal.fire({icon:'success',title:'Deleted!',text:res.message,timer:1800,showConfirmButton:false}); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
            error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); }
        });
    });
}
</script>

<?php includeFooter(); ?>
