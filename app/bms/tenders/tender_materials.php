<?php
// File: app/bms/tenders/tender_materials.php
// scope-audit: skip — tender materials schedule view; tenders reference customers (no direct project_id); deferred to Phase G-2
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('tenders');
$can_edit = canEdit('tenders') || canCreate('tenders');

includeHeader();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT tender_id, tender_no FROM tenders WHERE tender_id = ?");
$stmt->execute([$id]);
$tender = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tender) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Tender not found.</div></div>";
    includeFooter();
    exit;
}

$itemsStmt = $pdo->prepare("SELECT * FROM tender_materials WHERE tender_id = ? ORDER BY sort_order, material_id");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$tenderNavActive = 'materials';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

<div class="container-fluid px-4 mt-4 mb-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
        <div class="mb-3 mb-md-0 text-center text-md-start">
            <h2 class="fw-bold text-primary"><i class="bi bi-boxes me-2"></i>Materials Schedule</h2>
            <p class="text-muted small mb-0">Tender No: <span class="fw-bold text-dark"><?= safe_output($tender['tender_no']) ?></span></p>
        </div>
        <a href="<?= getUrl('tenders') ?>" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-arrow-left"></i> Back to List</a>
    </div>

    <?php require __DIR__ . '/_tender_nav.php'; ?>

    <div id="materials-message" class="mb-3"></div>

    <p class="text-muted small">Pricing estimate for materials this bid will need. Pick an existing catalogue item (including Non-Inventory Products) where one matches, or type a new name — either way it's linked to your project's material list automatically if this tender is won.</p>

    <form id="materialsForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="tender_id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="SAVE_MATERIALS">

        <div class="card border-0 shadow-sm mb-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px;">#</th>
                            <th>Material</th>
                            <th>Specification</th>
                            <th style="width:100px;">Unit</th>
                            <th style="width:110px;">Qty</th>
                            <th style="width:130px;">Rate (TZS)</th>
                            <th style="width:140px;" class="text-end">Amount</th>
                            <?php if ($can_edit): ?><th style="width:40px;"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="materialsBody">
                        <?php if (!$items): ?>
                        <tr class="text-muted"><td colspan="8">No materials yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($items as $i => $item): $mid = $item['material_id']; ?>
                        <tr data-material-id="<?= $mid ?>">
                            <td><?= $i + 1 ?></td>
                            <td>
                                <select class="form-select form-select-sm material-select2" style="width:100%" data-material-id="<?= $mid ?>">
                                    <?php if ($item['material'] !== ''): ?>
                                    <option value="<?= (int)$item['product_id'] ?>" selected><?= safe_output($item['material']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <input type="hidden" name="items[<?= $mid ?>][product_id]" class="material-product-id" value="<?= (int)$item['product_id'] ?>">
                                <input type="hidden" name="items[<?= $mid ?>][material]" class="material-name" value="<?= safe_output($item['material']) ?>">
                            </td>
                            <td><input type="text" class="form-control form-control-sm" name="items[<?= $mid ?>][specification]" value="<?= safe_output($item['specification']) ?>" <?= $can_edit ? '' : 'readonly' ?>></td>
                            <td><input type="text" class="form-control form-control-sm" name="items[<?= $mid ?>][unit]" value="<?= safe_output($item['unit']) ?>" <?= $can_edit ? '' : 'readonly' ?>></td>
                            <td><input type="number" step="0.001" class="form-control form-control-sm mat-qty" name="items[<?= $mid ?>][qty]" value="<?= safe_output($item['qty'], '0') ?>" <?= $can_edit ? '' : 'readonly' ?>></td>
                            <td><input type="number" step="0.01" class="form-control form-control-sm mat-rate" name="items[<?= $mid ?>][rate]" value="<?= safe_output($item['rate'], '0') ?>" <?= $can_edit ? '' : 'readonly' ?>></td>
                            <td class="text-end mat-amount fw-bold"><?= number_format((float)$item['amount'], 2) ?></td>
                            <?php if ($can_edit): ?>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteMaterial(<?= $mid ?>)"><i class="bi bi-x"></i></button></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="6" class="text-end">TOTAL</td>
                            <td class="text-end" id="materialsTotal"><?= number_format(array_sum(array_column($items, 'amount')), 2) ?></td>
                            <?php if ($can_edit): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <?php if ($can_edit): ?>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="button" class="btn btn-outline-primary" onclick="addMaterial()"><i class="bi bi-plus-circle"></i> Add Material</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Schedule</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<script>
const MATERIALS_API = '<?= buildUrl('api/tender_materials.php') ?>';
const TENDER_ID = <?= (int)$id ?>;

function materialsMessage(type, text) {
    $('#materials-message').html(`<div class="alert alert-${type} py-2">${text}</div>`);
}

function initMaterialSelect2($el) {
    $el.select2({
        theme: 'bootstrap-5',
        placeholder: 'Type or pick a material...',
        allowClear: true,
        width: '100%',
        tags: true,
        ajax: {
            url: MATERIALS_API,
            dataType: 'json',
            delay: 250,
            data: params => ({ action: 'SEARCH_PRODUCTS', term: params.term || '' }),
            processResults: data => ({
                results: (data.products || []).map(p => ({ id: p.product_id, text: p.product_name }))
            })
        }
    }).on('select2:select', function (e) {
        const row = $(this).closest('tr');
        const data = e.params.data;
        // A real catalogue hit has a numeric id from the DB; a free-typed tag's
        // id equals its own text (Select2's default tag behaviour) — only the
        // former is a real product_id.
        const isCatalogueItem = data.id && String(parseInt(data.id, 10)) === String(data.id);
        row.find('.material-product-id').val(isCatalogueItem ? data.id : '');
        row.find('.material-name').val(data.text);
    }).on('select2:unselect select2:clear', function () {
        const row = $(this).closest('tr');
        row.find('.material-product-id').val('');
        row.find('.material-name').val('');
    });
}

function recalcMaterialsTotal() {
    let total = 0;
    document.querySelectorAll('#materialsBody tr[data-material-id]').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.mat-qty')?.value || 0);
        const rate = parseFloat(tr.querySelector('.mat-rate')?.value || 0);
        const amount = qty * rate;
        tr.querySelector('.mat-amount').textContent = amount.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        total += amount;
    });
    document.getElementById('materialsTotal').textContent = total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
}

document.addEventListener('input', function (e) {
    if (e.target.matches('.mat-qty, .mat-rate')) recalcMaterialsTotal();
});

function addMaterial() {
    $.post(MATERIALS_API, { action: 'ADD_ITEM', tender_id: TENDER_ID, _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { location.reload(); } else { materialsMessage('danger', res.message); }
    }, 'json');
}

function deleteMaterial(materialId) {
    $.post(MATERIALS_API, { action: 'DELETE_ITEM', tender_id: TENDER_ID, material_id: materialId, _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { location.reload(); } else { materialsMessage('danger', res.message); }
    }, 'json');
}

$('#materialsForm').on('submit', function (e) {
    e.preventDefault();
    const $btn = $(this).find('[type="submit"]');
    const orig = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');

    $.ajax({
        url: MATERIALS_API,
        method: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function (res) {
            if (res.success) { materialsMessage('success', res.message); recalcMaterialsTotal(); }
            else { materialsMessage('danger', res.message); }
        },
        error: function () { materialsMessage('danger', 'Server error. Please try again.'); },
        complete: function () { $btn.prop('disabled', false).html(orig); }
    });
});

$(document).ready(function () {
    $('.material-select2').each(function () { initMaterialSelect2($(this)); });
    recalcMaterialsTotal();
});
</script>

<?php includeFooter(); ?>
