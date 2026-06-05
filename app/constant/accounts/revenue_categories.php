<?php
// app/constant/accounts/revenue_categories.php
// Dedicated "Revenue Categories" management page — a category → sub-category tree
// driven by the existing schema APIs (no extra DB work at runtime):
//   - api/finance/get_revenue_schema.php     → read the tree
//   - api/finance/manage_revenue_schema.php  → add / edit / delete (+ add-sub)
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

autoEnforcePermission('revenue_categories');
includeHeader();
global $pdo;

logActivity($pdo, $_SESSION['user_id'] ?? 0, 'View Revenue Categories',
    ($_SESSION['username'] ?? 'User') . ' opened the Revenue Categories page');

$can_manage = canEdit('revenue') || canEdit('revenue_categories');
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('revenue') ?>" class="text-decoration-none">Revenue</a></li>
            <li class="breadcrumb-item active">Categories</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill text-primary me-2"></i>Revenue Categories</h4>
            <p class="text-muted small mb-0">Group non-sales income into categories and sub-categories. Used by the Revenue form.</p>
        </div>
        <a href="<?= getUrl('revenue') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Revenue</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-primary" id="stat-cats">0</div><div class="small text-muted">Categories</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-warning" id="stat-subs">0</div><div class="small text-muted">Sub-categories</div></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="px-3 py-2 border-bottom bg-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="min-height:52px">
                <div id="breadcrumb" class="d-flex align-items-center gap-1 flex-wrap small"></div>
            </div>
            <div class="p-3" style="min-height:300px; max-height:460px; overflow-y:auto;">
                <div id="cat-list" class="d-flex flex-column gap-2"></div>
            </div>
            <?php if ($can_manage): ?>
            <div class="p-3 border-top">
                <div class="input-group">
                    <input type="text" id="new-cat-name" class="form-control" placeholder="New category name..." onkeydown="if(event.key==='Enter'){ addCategory(event); }">
                    <button class="btn btn-success" type="button" onclick="addCategory(event)" id="btnAddCat"><i class="bi bi-plus-circle me-1"></i> Add</button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const CAN_MANAGE = <?= $can_manage ? 'true' : 'false' ?>;
const SCHEMA_URL = '<?= buildUrl('api/finance/get_revenue_schema.php') ?>';
const MANAGE_URL = '<?= buildUrl('api/finance/manage_revenue_schema.php') ?>';
const CSRF       = '<?= csrf_token() ?>';

let tree = [];
let path = [];   // [{id,name}] drill-down stack

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
function showToast(icon, title){ Swal.fire({ toast:true, position:'top-end', icon:icon, title:title, showConfirmButton:false, timer:2200, timerProgressBar:true }); }

function loadTree(cb){
    $.getJSON(SCHEMA_URL, function(res){
        if (res.success){ tree = res.data || []; renderBreadcrumb(); renderCategories(currentLevel()); updateStats(); if (cb) cb(); }
        else { $('#cat-list').html('<div class="text-center text-danger py-4">Could not load categories.</div>'); }
    }).fail(function(){ $('#cat-list').html('<div class="text-center text-danger py-4">Could not load categories.</div>'); });
}

function findCat(cats, id){ for (const c of cats){ if (c.id == id) return c; if (c.children && c.children.length){ const f = findCat(c.children, id); if (f) return f; } } return null; }
function currentLevel(){ if (!path.length) return tree; let pool = tree; for (const p of path){ const n = findCat(pool, p.id); if (!n) return []; pool = n.children || []; } return pool; }
function countTree(cats, depth, acc){ (cats||[]).forEach(c => { if (depth===0) acc.c++; else acc.s++; if (c.children && c.children.length) countTree(c.children, depth+1, acc); }); return acc; }
function updateStats(){ const a = countTree(tree, 0, {c:0,s:0}); $('#stat-cats').text(a.c); $('#stat-subs').text(a.s); }

function renderCategories(cats){
    const $list = $('#cat-list'); $list.empty();
    const level = path.length === 0 ? 'categories' : 'sub-categories';
    if (!cats || !cats.length){ $list.html('<div class="text-center py-4 text-muted opacity-75"><i class="bi bi-info-circle me-1"></i> No ' + level + ' yet.' + (CAN_MANAGE ? ' Add one below.' : '') + '</div>'); return; }
    cats.forEach(c => {
        const childCount = (c.children || []).length;
        const safe = String(c.name).replace(/'/g, "\\'");
        let actions = '';
        if (CAN_MANAGE){
            actions = '<div class="dropdown">' +
                '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' +
                    '<li><a class="dropdown-item" href="#" onclick="drillDown(' + c.id + ", '" + safe + "'); return false;\"><i class=\"bi bi-folder-plus text-primary me-2\"></i> Add Sub-category</a></li>" +
                    '<li><a class="dropdown-item" href="#" onclick="renameCategory(' + c.id + ", '" + safe + "'); return false;\"><i class=\"bi bi-pencil text-warning me-2\"></i> Edit (Rename)</a></li>" +
                    '<li><hr class="dropdown-divider"></li>' +
                    '<li><a class="dropdown-item text-danger" href="#" onclick="deleteCategory(event, ' + c.id + ", '" + safe + "', " + childCount + "); return false;\"><i class=\"bi bi-trash me-2\"></i> Delete</a></li>" +
                '</ul></div>';
        } else if (childCount > 0){
            actions = '<button class="btn btn-sm btn-outline-primary" onclick="drillDown(' + c.id + ", '" + safe + "')\"><i class=\"bi bi-chevron-right\"></i></button>";
        }
        $list.append('<div class="d-flex align-items-center gap-2 p-2 rounded border bg-light-subtle">' +
            '<div class="flex-grow-1 small fw-bold">' + esc(c.name) + (childCount > 0 ? ' <span class="badge bg-primary ms-1" style="font-size:0.6rem">' + childCount + ' sub</span>' : '') + '</div>' +
            actions + '</div>');
    });
}

function drillDown(id, name){ path.push({ id:id, name:name }); renderBreadcrumb(); renderCategories(currentLevel()); $('#new-cat-name').val('').focus(); }
function navigate(index){ path = index < 0 ? [] : path.slice(0, index + 1); renderBreadcrumb(); renderCategories(currentLevel()); }
function renderBreadcrumb(){
    let html = '<span class="badge bg-primary px-2 py-1" style="cursor:pointer" onclick="navigate(-1)"><i class="bi bi-folder me-1"></i>Revenue Categories</span>';
    path.forEach((item, idx) => {
        const isLast = idx === path.length - 1;
        html += '<i class="bi bi-chevron-right small text-muted mx-1"></i><span class="badge ' + (isLast ? 'bg-secondary' : 'bg-light text-dark border') + ' px-2 py-1" style="cursor:pointer" onclick="navigate(' + idx + ')">' + esc(item.name) + '</span>';
    });
    $('#breadcrumb').html(html);
    $('#new-cat-name').attr('placeholder', 'New ' + (path.length === 0 ? 'category' : 'sub-category') + ' name...');
}

function addCategory(e){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    const name = $('#new-cat-name').val().trim(); if (!name) return;
    const parentId = path.length ? path[path.length - 1].id : '';
    const $btn = $('#btnAddCat'); $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.post(MANAGE_URL, { action:'add_category', name:name, parent_id:parentId, _csrf:CSRF }, function(res){
        $btn.prop('disabled', false).html('<i class="bi bi-plus-circle me-1"></i> Add');
        if (res.success){ $('#new-cat-name').val('').focus(); loadTree(() => showToast('success', 'Category added.')); }
        else { Swal.fire('Error', res.message, 'error'); }
    }, 'json').fail(function(){ $btn.prop('disabled', false).html('<i class="bi bi-plus-circle me-1"></i> Add'); Swal.fire('Error', 'Could not add category.', 'error'); });
}

function renameCategory(id, current){
    Swal.fire({ title:'Rename Category', input:'text', inputValue:current, inputAttributes:{ autocomplete:'off' },
        showCancelButton:true, confirmButtonText:'Save', inputValidator:(v)=>{ if (!v || !v.trim()) return 'Name cannot be empty.'; } })
    .then(r => { if (!r.isConfirmed) return;
        $.post(MANAGE_URL, { action:'edit_category', id:id, name:r.value.trim(), _csrf:CSRF }, function(res){
            if (res.success){ loadTree(() => showToast('success', 'Renamed.')); } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function deleteCategory(e, id, name, childCount){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    const sub = childCount > 0 ? '\n\nThis also removes its ' + childCount + ' sub-categor' + (childCount === 1 ? 'y' : 'ies') + '.' : '';
    Swal.fire({ title:'Delete "' + name + '"?', text:'This action cannot be undone.' + sub, icon:'warning',
        showCancelButton:true, confirmButtonText:'Yes, delete', confirmButtonColor:'#dc3545' })
    .then(r => { if (!r.isConfirmed) return;
        $.post(MANAGE_URL, { action:'delete_category', id:id, _csrf:CSRF }, function(res){
            if (res.success){
                // if we deleted the node we're inside, step up a level
                if (path.some(p => p.id == id)) { const i = path.findIndex(p => p.id == id); path = path.slice(0, i); }
                loadTree(() => showToast('info', 'Category removed.'));
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

$(document).ready(function(){ loadTree(); });
</script>

<?php includeFooter(); ?>
