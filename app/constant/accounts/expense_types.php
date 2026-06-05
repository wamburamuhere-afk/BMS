<?php
// File: app/constant/accounts/expense_types.php
// Dedicated "Expense Types & Categories" management page. Replaces the inline
// quick-manage modal that used to live on the expense create form. Reuses the
// EXISTING schema APIs (no DB change):
//   - api/finance/get_expense_schema.php     → read types + nested category tree
//   - api/finance/manage_expense_schema.php  → add/edit/delete types & categories
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('expenses');   // view-gate; write actions gated below + in the API
includeHeader();
global $pdo;

require_once __DIR__ . '/../../../helpers.php';
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'View Expense Types & Categories',
    ($_SESSION['username'] ?? 'User') . ' opened the Expense Types & Categories page');

// Write affordances mirror the manage API gate (canEdit on expenses OR categories).
$can_manage = canEdit('expenses') || canEdit('categories');
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('expenses') ?>" class="text-decoration-none">Expenses</a></li>
            <li class="breadcrumb-item active">Expense Types &amp; Categories</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill text-primary me-2"></i>Expense Types &amp; Categories</h4>
            <p class="text-muted small mb-0">Define every expense type, its categories and sub-categories. Used by the Expense form.</p>
        </div>
        <a href="<?= getUrl('expenses') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Expenses</a>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-primary" id="stat-types">0</div><div class="small text-muted">Expense Types</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-success" id="stat-projects">0</div><div class="small text-muted">Apply to Projects</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold" style="color:#052c65" id="stat-cats">0</div><div class="small text-muted">Categories</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-warning" id="stat-subs">0</div><div class="small text-muted">Sub-categories</div></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="row g-0">
                <!-- Left: Types -->
                <div class="col-md-4 border-end bg-light" style="min-height:460px;">
                    <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                        <span class="small fw-bold text-uppercase text-muted">Expense Types</span>
                    </div>
                    <div class="list-group list-group-flush" id="types-list" style="max-height:420px; overflow-y:auto;">
                        <div class="p-4 text-center text-muted"><div class="spinner-border spinner-border-sm text-primary"></div></div>
                    </div>
                    <?php if ($can_manage): ?>
                    <div class="p-3 border-top bg-white">
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="new-type-name" class="form-control" placeholder="New type name..." onkeydown="if(event.key==='Enter'){ addType(event); }">
                            <button class="btn btn-primary" type="button" onclick="addType(event)" id="btnAddType"><i class="bi bi-plus-lg"></i></button>
                        </div>
                        <div class="form-check form-switch ms-1">
                            <input class="form-check-input" type="checkbox" id="new-type-show-project" checked>
                            <label class="form-check-label small text-muted" for="new-type-show-project">Applies to Projects</label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right: category tree -->
                <div class="col-md-8">
                    <div id="cat-placeholder" class="h-100 d-flex flex-column align-items-center justify-content-center text-muted p-5 text-center" style="min-height:460px;">
                        <i class="bi bi-tags display-4 mb-3 opacity-25"></i>
                        <p class="mb-0">Select an Expense Type on the left to manage its categories &amp; sub-categories.</p>
                    </div>
                    <div id="cat-container" class="d-none flex-column h-100">
                        <div class="px-3 py-2 border-bottom bg-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="min-height:52px">
                            <div id="cat-breadcrumb" class="d-flex align-items-center gap-1 flex-wrap small"></div>
                            <?php if ($can_manage): ?>
                            <div class="d-flex align-items-center gap-2">
                                <button class="btn btn-sm btn-outline-secondary border-0" id="btnRenameType" onclick="renameActiveType(event)" title="Rename this Expense Type">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary border-0" id="btnToggleShowProject" onclick="toggleShowProject(event)" title="Toggle: Applies to Projects">
                                    <i class="bi bi-diagram-3" id="btnToggleShowProjectIcon"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger border-0" id="btnDeleteType" onclick="deleteActiveType(event)" title="Delete this Expense Type">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-3 flex-grow-1" style="max-height:360px; overflow-y:auto;">
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
        </div>
    </div>
</div>

<script>
const CAN_MANAGE = <?= $can_manage ? 'true' : 'false' ?>;
const SCHEMA_URL = '<?= buildUrl('api/finance/get_expense_schema.php') ?>';
const MANAGE_URL = '<?= buildUrl('api/finance/manage_expense_schema.php') ?>';

let expenseSchema   = [];
let activeTypeId    = null;
let activeCatPath   = [];   // [{id, name}] — drill-down navigation stack

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
function jsq(s){ return String(s).replace(/'/g, "\\'"); }
function showToast(icon, title){
    Swal.fire({ toast:true, position:'top-end', icon:icon, title:title, showConfirmButton:false, timer:2200, timerProgressBar:true });
}

// ── Load schema ──────────────────────────────────────────────────────────
function loadSchema(cb){
    $.getJSON(SCHEMA_URL, function(res){
        if (res.success){
            expenseSchema = res.data || [];
            renderTypes();
            updateStats();
            if (cb) cb();
        } else {
            $('#types-list').html('<div class="p-4 text-center text-danger small">Could not load expense types.</div>');
        }
    }).fail(function(){
        $('#types-list').html('<div class="p-4 text-center text-danger small">Could not load expense types.</div>');
    });
}

function countTree(cats, depth, acc){
    (cats || []).forEach(function(c){
        if (depth === 0) acc.cats++; else acc.subs++;
        if (c.children && c.children.length) countTree(c.children, depth + 1, acc);
    });
    return acc;
}
function updateStats(){
    let projects = 0, acc = { cats:0, subs:0 };
    expenseSchema.forEach(function(t){
        if (t.show_project == 1) projects++;
        countTree(t.categories, 0, acc);
    });
    $('#stat-types').text(expenseSchema.length);
    $('#stat-projects').text(projects);
    $('#stat-cats').text(acc.cats);
    $('#stat-subs').text(acc.subs);
}

// ── Types list ───────────────────────────────────────────────────────────
function renderTypes(){
    const $list = $('#types-list');
    $list.empty();
    if (expenseSchema.length === 0){
        $list.html('<div class="p-4 text-center text-muted small fst-italic">No types defined yet.</div>');
        return;
    }
    expenseSchema.forEach(function(type){
        const isActive    = activeTypeId == type.id;
        const showProject = type.show_project == 1;
        const badge = showProject
            ? '<span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size:0.65rem" title="Applies to Projects"><i class="bi bi-diagram-3"></i></span>'
            : '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1" style="font-size:0.65rem" title="Does not apply to Projects"><i class="bi bi-diagram-3-fill"></i> Off</span>';
        $list.append(
            '<button type="button" class="list-group-item list-group-item-action border-0 py-3 px-3 d-flex align-items-center justify-content-between ' + (isActive ? 'bg-primary text-white shadow-sm' : '') + '"' +
            ' onclick="selectType(' + type.id + ", '" + jsq(type.name) + "')\">" +
                '<div class="d-flex align-items-center flex-wrap gap-1">' +
                    '<i class="bi bi-folder2-open me-2 ' + (isActive ? 'text-white' : 'text-primary') + '"></i>' +
                    '<span class="' + (isActive ? 'fw-bold' : '') + '">' + esc(type.name) + '</span>' +
                    badge +
                '</div>' +
                '<i class="bi bi-chevron-right small opacity-50"></i>' +
            '</button>'
        );
    });
    // Re-sync the right pane if a type is selected
    if (activeTypeId){
        const active = expenseSchema.find(t => t.id == activeTypeId);
        if (active){ renderBreadcrumb(); renderCategories(categoriesAtCurrentLevel()); }
        else { resetCategories(); }
    }
}

function selectType(id, name){
    activeTypeId  = id;
    activeCatPath = [];
    $('#cat-placeholder').addClass('d-none');
    $('#cat-container').removeClass('d-none').addClass('d-flex');

    renderTypes();   // re-renders the list and highlights the active type
    renderBreadcrumb();
    const typeData = expenseSchema.find(t => t.id == id);
    renderCategories(typeData ? typeData.categories : []);
    updateToggleButton();
}

function updateToggleButton(){
    if (!CAN_MANAGE) return;
    const t = expenseSchema.find(x => x.id == activeTypeId);
    const $btn = $('#btnToggleShowProject');
    const $icon = $('#btnToggleShowProjectIcon');
    if (t && t.show_project == 0){
        $btn.attr('title', 'Projects: OFF — click to enable').removeClass('btn-outline-secondary').addClass('btn-outline-warning');
        $icon.attr('class', 'bi bi-diagram-3-fill text-warning');
    } else {
        $btn.attr('title', 'Projects: ON — click to disable').removeClass('btn-outline-warning').addClass('btn-outline-secondary');
        $icon.attr('class', 'bi bi-diagram-3 text-success');
    }
}

// ── Category tree ────────────────────────────────────────────────────────
function findCat(cats, id){
    for (let i = 0; i < cats.length; i++){
        if (cats[i].id == id) return cats[i];
        if (cats[i].children && cats[i].children.length){
            const f = findCat(cats[i].children, id);
            if (f) return f;
        }
    }
    return null;
}

function categoriesAtCurrentLevel(){
    const t = expenseSchema.find(x => x.id == activeTypeId);
    if (!t) return [];
    if (activeCatPath.length === 0) return t.categories || [];
    let pool = t.categories || [];
    for (let i = 0; i < activeCatPath.length; i++){
        const node = findCat(pool, activeCatPath[i].id);
        if (!node) return [];
        pool = node.children || [];
    }
    return pool;
}

function renderCategories(categories){
    const $list = $('#cat-list');
    $list.empty();
    const level = activeCatPath.length === 0 ? 'categories' : 'sub-categories';
    if (!categories || categories.length === 0){
        $list.html('<div class="text-center py-4 text-muted opacity-75"><i class="bi bi-info-circle me-1"></i> No ' + level + ' yet.' + (CAN_MANAGE ? ' Add one below.' : '') + '</div>');
        return;
    }
    categories.forEach(function(cat){
        const childCount = (cat.children || []).length;
        const safe = jsq(cat.name);
        let actions = '';
        if (CAN_MANAGE){
            actions =
                '<div class="dropdown">' +
                    '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear"></i></button>' +
                    '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' +
                        '<li><a class="dropdown-item" href="#" onclick="drillDown(' + cat.id + ", '" + safe + "'); return false;\"><i class=\"bi bi-folder-plus text-primary me-2\"></i> Add Sub-category</a></li>" +
                        '<li><a class="dropdown-item" href="#" onclick="renameCategory(' + cat.id + ", '" + safe + "'); return false;\"><i class=\"bi bi-pencil text-warning me-2\"></i> Edit (Rename)</a></li>" +
                        '<li><hr class="dropdown-divider"></li>' +
                        '<li><a class="dropdown-item text-danger" href="#" onclick="deleteCategory(event, ' + cat.id + ", '" + safe + "', " + childCount + "); return false;\"><i class=\"bi bi-trash me-2\"></i> Delete</a></li>" +
                    '</ul>' +
                '</div>';
        } else if (childCount > 0){
            actions = '<button class="btn btn-sm btn-outline-primary" onclick="drillDown(' + cat.id + ", '" + safe + "')\"><i class=\"bi bi-chevron-right\"></i></button>";
        }
        $list.append(
            '<div class="d-flex align-items-center gap-2 p-2 rounded border bg-light-subtle">' +
                '<div class="flex-grow-1 small fw-bold">' + esc(cat.name) +
                    (childCount > 0 ? ' <span class="badge bg-primary ms-1" style="font-size:0.6rem">' + childCount + ' sub</span>' : '') +
                '</div>' + actions +
            '</div>'
        );
    });
}

function drillDown(id, name){
    activeCatPath.push({ id:id, name:name });
    renderBreadcrumb();
    renderCategories(categoriesAtCurrentLevel());
    $('#new-cat-name').val('').focus();
}

function navigateBreadcrumb(index){
    activeCatPath = index < 0 ? [] : activeCatPath.slice(0, index + 1);
    renderBreadcrumb();
    renderCategories(categoriesAtCurrentLevel());
}

function renderBreadcrumb(){
    const t = expenseSchema.find(x => x.id == activeTypeId);
    const typeName = t ? t.name : '';
    const atRoot = activeCatPath.length === 0;
    let html = '<span class="badge bg-primary px-2 py-1" style="cursor:pointer" onclick="navigateBreadcrumb(-1)"><i class="bi bi-folder me-1"></i>' + esc(typeName) + '</span>';
    activeCatPath.forEach(function(item, idx){
        const isLast = idx === activeCatPath.length - 1;
        html += '<i class="bi bi-chevron-right small text-muted mx-1"></i>' +
                '<span class="badge ' + (isLast ? 'bg-secondary' : 'bg-light text-dark border') + ' px-2 py-1" style="cursor:pointer" onclick="navigateBreadcrumb(' + idx + ')">' + esc(item.name) + '</span>';
    });
    $('#cat-breadcrumb').html(html);
    if (CAN_MANAGE){
        atRoot ? $('#btnDeleteType').show() : $('#btnDeleteType').hide();
        $('#btnRenameType').toggle(atRoot);
        $('#btnToggleShowProject').toggle(atRoot);
        $('#new-cat-name').attr('placeholder', 'New ' + (atRoot ? 'category' : 'sub-category') + ' name...');
    }
}

function resetCategories(){
    activeTypeId = null;
    activeCatPath = [];
    $('#cat-placeholder').removeClass('d-none');
    $('#cat-container').addClass('d-none').removeClass('d-flex');
}

// ── Mutations (reuse manage_expense_schema.php) ──────────────────────────
function addType(e){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    const name = $('#new-type-name').val().trim();
    const showProject = $('#new-type-show-project').is(':checked') ? 1 : 0;
    if (!name) return;
    const $btn = $('#btnAddType');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.post(MANAGE_URL, { action:'add_type', name:name, show_project:showProject }, function(res){
        $btn.prop('disabled', false).html('<i class="bi bi-plus-lg"></i>');
        if (res.success){
            $('#new-type-name').val('');
            $('#new-type-show-project').prop('checked', true);
            loadSchema(function(){ selectType(res.id, name); showToast('success', 'Type added.'); });
        } else { Swal.fire('Error', res.message, 'error'); }
    }, 'json').fail(function(){ $btn.prop('disabled', false).html('<i class="bi bi-plus-lg"></i>'); Swal.fire('Error', 'Could not add type.', 'error'); });
}

function renameActiveType(e){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    if (!activeTypeId) return;
    const t = expenseSchema.find(x => x.id == activeTypeId);
    Swal.fire({
        title:'Rename Expense Type', input:'text', inputValue: t ? t.name : '',
        inputAttributes:{ autocomplete:'off' }, showCancelButton:true, confirmButtonText:'Save',
        inputValidator:(v)=>{ if (!v || !v.trim()) return 'Name cannot be empty.'; }
    }).then(function(r){
        if (!r.isConfirmed) return;
        const newName = r.value.trim();
        $.post(MANAGE_URL, { action:'edit_type', id:activeTypeId, name:newName }, function(res){
            if (res.success){ loadSchema(function(){ selectType(activeTypeId, newName); showToast('success', 'Type renamed.'); }); }
            else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function deleteActiveType(e){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    if (!activeTypeId) return;
    Swal.fire({
        title:'Delete Expense Type?', text:'This also deletes all of its categories. This cannot be undone.',
        icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, Delete'
    }).then(function(r){
        if (!r.isConfirmed) return;
        $.post(MANAGE_URL, { action:'delete_type', id:activeTypeId }, function(res){
            if (res.success){ resetCategories(); loadSchema(function(){ showToast('info', 'Expense Type deleted.'); }); }
            else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function toggleShowProject(e){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    if (!activeTypeId) return;
    const t = expenseSchema.find(x => x.id == activeTypeId);
    const label = t ? t.name : 'this type';
    const isOn = t && t.show_project == 1;
    Swal.fire({
        title: isOn ? 'Disable Projects?' : 'Enable Projects?',
        text: 'This ' + (isOn ? 'disables' : 'enables') + ' project linking for "' + label + '". Takes effect on new expense entries.',
        icon:'question', showCancelButton:true, confirmButtonColor: isOn ? '#6c757d' : '#198754',
        confirmButtonText: isOn ? 'Yes, Disable' : 'Yes, Enable'
    }).then(function(r){
        if (!r.isConfirmed) return;
        $.post(MANAGE_URL, { action:'toggle_show_project', id:activeTypeId }, function(res){
            if (res.success){ loadSchema(function(){ selectType(activeTypeId, label); showToast('success', '"' + label + '" project setting updated.'); }); }
            else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function addCategory(e){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    const name = $('#new-cat-name').val().trim();
    if (!name || !activeTypeId) return;
    const parentId = activeCatPath.length > 0 ? activeCatPath[activeCatPath.length - 1].id : null;
    const $btn = $('#btnAddCat');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.post(MANAGE_URL, { action:'add_category', type_id:activeTypeId, parent_id:parentId, name:name }, function(res){
        $btn.prop('disabled', false).html('<i class="bi bi-plus-circle me-1"></i> Add');
        if (res.success){
            $('#new-cat-name').val('').focus();
            loadSchema(function(){ renderBreadcrumb(); renderCategories(categoriesAtCurrentLevel()); showToast('success', 'Category added.'); });
        } else { Swal.fire('Error', res.message, 'error'); }
    }, 'json').fail(function(){ $btn.prop('disabled', false).html('<i class="bi bi-plus-circle me-1"></i> Add'); Swal.fire('Error', 'Could not add category.', 'error'); });
}

function renameCategory(id, currentName){
    Swal.fire({
        title:'Rename Category', input:'text', inputValue:currentName,
        inputAttributes:{ autocomplete:'off' }, showCancelButton:true, confirmButtonText:'Save',
        inputValidator:(v)=>{ if (!v || !v.trim()) return 'Name cannot be empty.'; }
    }).then(function(r){
        if (!r.isConfirmed) return;
        $.post(MANAGE_URL, { action:'edit_category', id:id, name:r.value.trim() }, function(res){
            if (res.success){ loadSchema(function(){ renderBreadcrumb(); renderCategories(categoriesAtCurrentLevel()); showToast('success', 'Renamed.'); }); }
            else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function deleteCategory(e, id, name, childCount){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    const sub = childCount > 0 ? '\n\nThis also removes its ' + childCount + ' sub-categor' + (childCount === 1 ? 'y' : 'ies') + ' and their children.' : '';
    Swal.fire({
        title:'Delete "' + name + '"?', text:'This action cannot be undone.' + sub,
        icon:'warning', showCancelButton:true, confirmButtonText:'Yes, delete', confirmButtonColor:'#dc3545'
    }).then(function(r){
        if (!r.isConfirmed) return;
        $.post(MANAGE_URL, { action:'delete_category', id:id }, function(res){
            if (res.success){ loadSchema(function(){ renderBreadcrumb(); renderCategories(categoriesAtCurrentLevel()); showToast('info', 'Category removed.'); }); }
            else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

$(document).ready(function(){ loadSchema(); });
</script>

<?php includeFooter(); ?>
