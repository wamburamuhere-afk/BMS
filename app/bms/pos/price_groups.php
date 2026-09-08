<?php
ob_start();

$page_title = 'Price Groups';
require_once __DIR__ . '/../../../header.php';

// Phase 14 (pos_upgrade_plan.md §8) — selling price tiers. Gated behind the
// 'pos_advanced' tenant entitlement (same boundary as Registers/Loyalty),
// with 'pos_config_settings' as the actual RBAC permission — same dual-gate
// pattern already used by app/bms/pos/pos_config_settings.php's Registers
// section.
$pos_advanced_entitled = canView('pos_advanced');
$can_view = canView('pos_config_settings');
$can_edit = canEdit('pos_config_settings');

if (!$can_view) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

$groups = [];
if ($pos_advanced_entitled) {
    $groups = $pdo->query("
        SELECT price_group_id, name, is_default, status,
               (SELECT COUNT(*) FROM product_price_group_prices WHERE price_group_id = pg.price_group_id) AS override_count
        FROM price_groups pg
        ORDER BY is_default DESC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-tags text-primary me-2"></i><?= t('Price Groups') ?></h4>
        <?php if ($can_edit && $pos_advanced_entitled): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGroupModal">
            <i class="bi bi-plus-circle me-1"></i> <?= t('New Price Group') ?>
        </button>
        <?php endif; ?>
    </div>

    <?php if (!$pos_advanced_entitled): ?>
    <div class="alert alert-warning">
        <i class="bi bi-lock me-1"></i> <?= t('Price groups are not included in your plan.') ?>
    </div>
    <?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= count($groups) ?></div>
                <div class="small text-muted"><?= t('Total') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success"><?= count(array_filter($groups, fn($g) => $g['status'] === 'active')) ?></div>
                <div class="small text-muted"><?= t('Active') ?></div>
            </div>
        </div>
    </div>

    <div id="tableView">
        <table id="groupsTable" class="table table-hover align-middle w-100">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th><?= t('Name') ?></th>
                    <th><?= t('Status') ?></th>
                    <th><?= t('Product Overrides') ?></th>
                    <th class="text-end"><?= t('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $i => $g): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <?= safe_output($g['name']) ?>
                        <?php if ($g['is_default']): ?><span class="badge" style="background:#0d6efd;"><?= t('Default') ?></span><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge" style="background:<?= $g['status'] === 'active' ? '#0d6efd' : '#6c757d' ?>;color:#fff;">
                            <?= $g['status'] === 'active' ? t('Active') : t('Inactive') ?>
                        </span>
                    </td>
                    <td><?= (int)$g['override_count'] ?></td>
                    <td class="text-end">
                        <div class="dropdown d-flex justify-content-end">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear-fill me-1"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                <?php if ($can_edit): ?>
                                <li><button class="dropdown-item py-2 rounded" onclick="openPricesModal(<?= (int)$g['price_group_id'] ?>, <?= json_encode($g['name']) ?>)"><i class="bi bi-currency-exchange text-primary me-2"></i> <?= t('Manage Prices') ?></button></li>
                                <li><button class="dropdown-item py-2 rounded" onclick="editGroup(<?= (int)$g['price_group_id'] ?>, <?= json_encode($g['name']) ?>, <?= $g['is_default'] ? 1 : 0 ?>)"><i class="bi bi-pencil text-primary me-2"></i> <?= t('Edit') ?></button></li>
                                <?php if (!$g['is_default']): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><button class="dropdown-item py-2 rounded" onclick="toggleStatus(<?= (int)$g['price_group_id'] ?>, '<?= $g['status'] === 'active' ? 'inactive' : 'active' ?>')">
                                    <i class="bi bi-<?= $g['status'] === 'active' ? 'pause-circle text-warning' : 'play-circle text-success' ?> me-2"></i>
                                    <?= $g['status'] === 'active' ? t('Deactivate') : t('Activate') ?>
                                </button></li>
                                <?php endif; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="cardView" class="row g-2 d-none"></div>

    <?php endif; ?>
</div>

<!-- Add/Edit Group Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-tags me-1"></i> <span id="groupModalTitle"><?= t('New Price Group') ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="groupForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="price_group_id" id="f-group-id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="group-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Name') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="f-group-name" required maxlength="100">
                        <small class="text-muted d-none" id="f-group-default-note"><?= t('The default price group cannot be renamed.') ?></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> <?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Prices Modal -->
<div class="modal fade" id="pricesModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-currency-exchange me-1"></i> <span id="pricesModalTitle"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control mb-3" id="priceProductSearch" placeholder="<?= t('Search product by name or SKU') ?>">
                <div id="priceGridLoading" class="text-center py-4 d-none">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
                <table class="table table-sm table-hover" id="priceGridTable">
                    <thead>
                        <tr>
                            <th><?= t('Product') ?></th>
                            <th class="text-end"><?= t('Selling Price') ?></th>
                            <th class="text-end" style="width:160px;"><?= t('Override Price') ?></th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="priceGridBody"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Close') ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const CAN_EDIT = <?= json_encode($can_edit) ?>;
const GROUPS_DATA = <?= json_encode($groups) ?>;
let currentPriceGroupId = 0;

$(document).ready(function () {
    if ($('#groupsTable').length && !$.fn.DataTable.isDataTable('#groupsTable')) {
        $('#groupsTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [[1, 'asc']],
            dom: 'rtip'
        });
    }
    renderCards(GROUPS_DATA);

    function applyView() {
        if (window.innerWidth < 768) {
            $('#tableView').addClass('d-none');
            $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none');
            $('#cardView').addClass('d-none');
        }
    }
    applyView();
    $(window).on('resize', applyView);

    $('#addGroupModal').on('hidden.bs.modal', function () {
        $('#groupForm')[0].reset();
        $('#f-group-id').val('');
        $('#group-message').html('');
        $('#groupModalTitle').text(<?= json_encode(t('New Price Group')) ?>);
        $('#f-group-name').prop('readonly', false);
        $('#f-group-default-note').addClass('d-none');
    });

    $('#groupForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + <?= json_encode(t('Processing...')) ?>);
        $.ajax({
            url: '<?= buildUrl('api/pos/save_price_group.php') ?>',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addGroupModal')).hide();
                    Swal.fire({ icon: 'success', title: <?= json_encode(t('Saved!')) ?>, text: res.message, timer: 1800, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    $('#group-message').html('<div class="alert alert-danger py-2 mb-0">' + res.message + '</div>');
                }
            },
            error: function () { $('#group-message').html('<div class="alert alert-danger py-2 mb-0">' + <?= json_encode(t('Server error.')) ?> + '</div>'); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    let searchTimer = null;
    $('#priceProductSearch').on('input', function () {
        clearTimeout(searchTimer);
        const q = $(this).val();
        searchTimer = setTimeout(() => loadPriceGrid(currentPriceGroupId, q), 300);
    });
});

function editGroup(id, name, isDefault) {
    $('#groupModalTitle').text(<?= json_encode(t('Edit Price Group')) ?>);
    $('#f-group-id').val(id);
    $('#f-group-name').val(name);
    if (isDefault) {
        $('#f-group-name').prop('readonly', true);
        $('#f-group-default-note').removeClass('d-none');
    }
    new bootstrap.Modal(document.getElementById('addGroupModal')).show();
}

function toggleStatus(id, newStatus) {
    Swal.fire({
        title: <?= json_encode(t('Confirm')) ?>,
        text: newStatus === 'inactive' ? <?= json_encode(t('Deactivate this price group?')) ?> : <?= json_encode(t('Activate this price group?')) ?>,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: <?= json_encode(t('Yes')) ?>,
        cancelButtonText: <?= json_encode(t('Cancel')) ?>
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/pos/toggle_price_group_status.php') ?>', {
            price_group_id: id, status: newStatus, _csrf: <?= json_encode(csrf_token()) ?>
        }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: <?= json_encode(t('Done')) ?>, text: res.message, timer: 1500, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: res.message });
            }
        }, 'json');
    });
}

function openPricesModal(id, name) {
    currentPriceGroupId = id;
    $('#pricesModalTitle').text(name);
    $('#priceProductSearch').val('');
    new bootstrap.Modal(document.getElementById('pricesModal')).show();
    loadPriceGrid(id, '');
}

function loadPriceGrid(id, search) {
    $('#priceGridLoading').removeClass('d-none');
    $('#priceGridBody').empty();
    $.getJSON('<?= buildUrl('api/pos/get_price_group_products.php') ?>', { price_group_id: id, search: search }, function (res) {
        $('#priceGridLoading').addClass('d-none');
        if (!res.success || !res.data.length) {
            $('#priceGridBody').html('<tr><td colspan="4" class="text-center text-muted py-3">' + <?= json_encode(t('No products found')) ?> + '</td></tr>');
            return;
        }
        let html = '';
        res.data.forEach(p => {
            const overrideVal = p.override_price !== null ? p.override_price : '';
            html += `<tr data-product-id="${p.product_id}">
                <td><div class="fw-bold small">${safeOutput(p.product_name)}</div><div class="text-muted" style="font-size:11px;">${safeOutput(p.sku || '')}</div></td>
                <td class="text-end small">${Number(p.selling_price).toLocaleString()}</td>
                <td class="text-end">
                    <input type="number" class="form-control form-control-sm text-end price-override-input" min="0" step="0.01"
                           value="${overrideVal}" placeholder="${safeOutput(p.selling_price)}" ${CAN_EDIT ? '' : 'disabled'}>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-primary" onclick="savePriceOverride(this)" ${CAN_EDIT ? '' : 'disabled'}><i class="bi bi-check"></i></button>
                </td>
            </tr>`;
        });
        $('#priceGridBody').html(html);
    });
}

function savePriceOverride(btn) {
    const row = $(btn).closest('tr');
    const productId = row.data('product-id');
    const price = row.find('.price-override-input').val();
    $.post('<?= buildUrl('api/pos/save_price_group_product_price.php') ?>', {
        price_group_id: currentPriceGroupId, product_id: productId, price: price, _csrf: <?= json_encode(csrf_token()) ?>
    }, function (res) {
        if (res.success) {
            Swal.fire({ icon: 'success', title: res.message, timer: 1000, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: res.message });
        }
    }, 'json');
}

function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted">' + <?= json_encode(t('No records found')) ?> + '</div>');
        return;
    }
    let html = '';
    rows.forEach(g => {
        const statusColor = g.status === 'active' ? '#0d6efd' : '#6c757d';
        const statusLabel = g.status === 'active' ? <?= json_encode(t('Active')) ?> : <?= json_encode(t('Inactive')) ?>;
        let actions = '';
        if (CAN_EDIT) {
            actions += `<button class="btn btn-sm btn-outline-primary" onclick="openPricesModal(${g.price_group_id}, ${JSON.stringify(g.name)})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-currency-exchange"></i></button>`;
            actions += `<button class="btn btn-sm btn-outline-primary" onclick="editGroup(${g.price_group_id}, ${JSON.stringify(g.name)}, ${g.is_default ? 1 : 0})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-pencil"></i></button>`;
            if (!g.is_default) {
                const nextStatus = g.status === 'active' ? 'inactive' : 'active';
                actions += `<button class="btn btn-sm btn-outline-secondary" onclick="toggleStatus(${g.price_group_id}, '${nextStatus}')" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-${g.status === 'active' ? 'pause-circle' : 'play-circle'}"></i></button>`;
            }
        }
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="fw-bold">${safeOutput(g.name)} ${g.is_default ? '<span class="badge" style="background:#0d6efd;">' + <?= json_encode(t('Default')) ?> + '</span>' : ''}</div>
                    <small class="text-muted">
                        <span class="badge" style="background:${statusColor};color:#fff;">${statusLabel}</span>
                        · ${g.override_count} <?= t('Product Overrides') ?>
                    </small>
                </div>
                ${actions ? `<div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${actions}</div></div>` : ''}
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php
include("footer.php");
ob_end_flush();
?>
