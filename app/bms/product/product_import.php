<?php
/**
 * File: app/bms/product/product_import.php
 * Import Products from a CSV — check the file first (preview), then import.
 * The work is done by api/import_products.php → core/product_import.php, which
 * runs each row through the same steps as Add Product, all or nothing.
 */
ob_start();
require_once 'header.php';

autoEnforcePermission('products');
requireCreatePermission('products');

require_once ROOT_DIR . '/core/warehouse_scope.php';
require_once ROOT_DIR . '/core/product_import.php';
try { $shops = warehousesForSelect($pdo); } catch (PDOException $e) { $shops = []; }
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <h2 class="mb-0 fs-4 fw-bold"><i class="bi bi-file-earmark-arrow-up text-primary me-1"></i> <?= t('Import Products') ?></h2>
            <p class="text-muted mb-0 small mt-1"><?= t('Add many products at once from a CSV file. Products whose name already exists are skipped.') ?></p>
        </div>
        <a href="<?= getUrl('products') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> <?= t('Back to Products') ?></a>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold text-primary"><span class="badge bg-primary me-1">1</span> <?= t('Download the template') ?></h6>
                    <p class="text-muted small mb-2"><?= t('Fill it in Excel or Google Sheets, one product per row, then save it as CSV.') ?></p>
                    <button type="button" class="btn btn-outline-primary btn-sm mb-4" id="btnTemplate"><i class="bi bi-download me-1"></i> <?= t('Download CSV template') ?></button>

                    <h6 class="fw-bold text-primary"><span class="badge bg-primary me-1">2</span> <?= t('Choose the file') ?></h6>
                    <form id="importForm" autocomplete="off" novalidate>
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input class="form-control mb-3" type="file" id="importFile" name="file" accept=".csv" required>
                        <?php if (count($shops) > 1): ?>
                        <label class="form-label small fw-bold" for="importShop"><?= t('Shop for the opening stock') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static mb-3" id="importShop" name="warehouse_id" required>
                            <option value=""><?= t('-- Select --') ?></option>
                            <?php foreach ($shops as $s): ?>
                            <option value="<?= (int)$s['warehouse_id'] ?>"><?= safe_output($s['warehouse_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php elseif (count($shops) === 1): ?>
                        <input type="hidden" name="warehouse_id" value="<?= (int)$shops[0]['warehouse_id'] ?>">
                        <p class="small text-muted mb-3"><i class="bi bi-shop me-1"></i> <?= t('Opening stock goes into') ?>: <strong><?= safe_output($shops[0]['warehouse_name']) ?></strong></p>
                        <?php else: ?>
                        <div class="alert alert-warning small py-2"><?= t('Create a shop first — opening stock needs a shop to go into.') ?></div>
                        <?php endif; ?>

                        <h6 class="fw-bold text-primary"><span class="badge bg-primary me-1">3</span> <?= t('Check, then import') ?></h6>
                        <p class="text-muted small mb-2"><?= t('Checking saves nothing. You see what will happen to every row before anything is imported.') ?></p>
                        <button type="submit" class="btn btn-primary w-100" id="btnPreview"><i class="bi bi-search me-1"></i> <?= t('Check the file') ?></button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-body">
                    <h6 class="fw-bold text-primary mb-2"><i class="bi bi-book me-1"></i> <?= t('Columns') ?></h6>
                    <div class="table-responsive">
                    <table class="table table-sm small mb-0">
                        <thead><tr><th><?= t('Column') ?></th><th><?= t('Required') ?></th><th><?= t('Notes') ?></th></tr></thead>
                        <tbody>
                            <tr><td><code>product_name</code></td><td><?= t('Yes') ?></td><td><?= t('Skipped if a product with this name already exists.') ?></td></tr>
                            <tr><td><code>category</code></td><td><?= t('No') ?></td><td><?= t('Uses the category with this name, or creates it.') ?></td></tr>
                            <tr><td><code>unit</code></td><td><?= t('No') ?></td><td><?= t('e.g. pcs, kg, lt — empty means pcs.') ?></td></tr>
                            <tr><td><code>cost_price</code></td><td><?= t('Yes') ?></td><td><?= t('Buying price.') ?></td></tr>
                            <tr><td><code>wholesale_price</code></td><td><?= t('No') ?></td><td></td></tr>
                            <tr><td><code>selling_price</code></td><td><?= t('Yes') ?></td><td><?= t('Retail price, above 0.') ?></td></tr>
                            <tr><td><code>opening_stock</code></td><td><?= t('No') ?></td><td><?= t('Quantity in the shop now.') ?></td></tr>
                            <tr><td><code>min_stock_level</code></td><td><?= t('No') ?></td><td><?= t('Low stock alert.') ?></td></tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body" id="previewArea">
                    <div class="text-center text-muted py-5" id="previewEmpty">
                        <i class="bi bi-table" style="font-size:2.5rem"></i>
                        <p class="mt-2 mb-0"><?= t('Choose a file and press "Check the file" — the rows appear here.') ?></p>
                    </div>
                    <div id="previewResult" hidden>
                        <div class="d-flex flex-wrap gap-2 mb-3" id="previewSummary"></div>
                        <div id="previewCats" class="small mb-3"></div>
                        <div class="table-responsive" style="max-height:60vh">
                            <table class="table table-sm align-middle small" id="previewTable">
                                <thead class="table-light" style="position:sticky;top:0">
                                    <tr><th>#</th><th><?= t('Product') ?></th><th><?= t('Category') ?></th><th><?= t('Unit') ?></th>
                                        <th class="text-end"><?= t('Buying') ?></th><th class="text-end"><?= t('Wholesale') ?></th><th class="text-end"><?= t('Selling') ?></th>
                                        <th class="text-end"><?= t('Stock') ?></th><th><?= t('Result') ?></th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-primary w-100 mt-3" id="btnImport" disabled><i class="bi bi-check-circle me-1"></i> <span></span></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.pi-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:600; white-space:nowrap; }
.pi-new { background:#0d6efd; color:#fff; } .pi-exists, .pi-duplicate { background:#6c757d; color:#fff; } .pi-error { background:#dc3545; color:#fff; }
.pi-stat { background:#e7f0ff; border:1px solid #b6ccfe; border-radius:8px; padding:6px 12px; font-size:.85rem; }
#previewTable td.num, #previewTable th.text-end { font-variant-numeric: tabular-nums; }
</style>

<script>
$(function () {
    const API = '<?= buildUrl('api/import_products.php') ?>', PRODUCTS_URL = '<?= getUrl('products') ?>';
    const TEMPLATE = <?= json_encode(piTemplateCsv()) ?>;
    const L = <?= json_encode([
        'new' => t('Will be added'), 'exists' => t('Already exists'), 'duplicate' => t('Repeated in file'), 'error' => t('Error'),
        'importN' => t('Import %d products'), 'nothing' => t('Nothing new to import'), 'fixErrors' => t('Fix the rows with an error, then check again'),
        'newCats' => t('New categories that will be created:'), 'checking' => t('Checking the file...'), 'importing' => t('Importing — do not close this page...'),
        'error' => t('Error'), 'serverError' => t('Server error. Please try again.'), 'chooseFile' => t('Choose a CSV file first.'),
        'chooseShop' => t('Choose the shop for the opening stock.'), 'done' => t('Import finished'), 'confirmQ' => t('Import %d products?'),
        'confirmText' => t('They are added with their opening stock in the chosen shop. Products that already exist are not touched.'),
        'yes' => t('Yes, import'), 'cancel' => t('Cancel'), 'goProducts' => t('Open Products'),
    ]) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = v => (v === null || v === '' || v === undefined) ? '<span class="text-muted">—</span>' : Number(v).toLocaleString();
    let lastNew = 0;

    $('#importShop').select2 && $('#importShop').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#importFile, #importShop').on('change', () => { $('#previewResult').prop('hidden', true); $('#previewEmpty').prop('hidden', false); });

    $('#btnTemplate').on('click', function () {
        const blob = new Blob(['﻿' + TEMPLATE], { type: 'text/csv' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = 'products_import_template.csv';
        document.body.appendChild(a); a.click(); a.remove();
        if (typeof logReportAction === 'function') logReportAction('Downloaded Product Import Template', 'User downloaded the CSV template for product import');
    });

    function send(action) {
        const form = document.getElementById('importForm');
        if (!$('#importFile').val()) { Swal.fire({ icon: 'info', title: L.chooseFile }); return null; }
        if (action === 'commit' && $('#importShop').length && !$('#importShop').val()) { Swal.fire({ icon: 'info', title: L.chooseShop }); return null; }
        const fd = new FormData(form);
        fd.append('action', action);
        return $.ajax({ url: API, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json' });
    }

    function showPlan(res) {
        const s = res.summary || {}, plan = res.plan || [];
        $('#previewEmpty').prop('hidden', true); $('#previewResult').prop('hidden', false);
        $('#previewSummary').html(['new', 'exists', 'duplicate', 'error'].filter(k => s[k]).map(k =>
            `<span class="pi-stat"><span class="pi-badge pi-${k}">${esc(L[k])}</span> <b>${Number(s[k])}</b></span>`).join(''));
        $('#previewCats').html((s.new_categories || []).length ? `<i class="bi bi-tags text-primary me-1"></i>${esc(L.newCats)} <b>${(s.new_categories || []).map(esc).join(', ')}</b>` : '');
        $('#previewTable tbody').html(plan.map(p => `<tr>
            <td class="text-muted">${Number(p.line)}</td>
            <td class="fw-semibold">${esc(p.product_name)}</td>
            <td>${esc(p.category)}${p.status === 'new' && p.category_new ? ' <span class="pi-badge pi-new">+</span>' : ''}</td>
            <td>${esc(p.unit)}</td>
            <td class="text-end num">${num(p.cost_price)}</td><td class="text-end num">${num(p.wholesale_price)}</td>
            <td class="text-end num">${num(p.selling_price)}</td><td class="text-end num">${num(p.opening_stock)}</td>
            <td><span class="pi-badge pi-${esc(p.status)}">${esc(L[p.status])}</span>${p.message ? `<div class="text-muted" style="font-size:.72rem">${esc(p.message)}</div>` : ''}</td>
        </tr>`).join(''));
        lastNew = s.new || 0;
        const blocked = (s.error || 0) > 0 || lastNew === 0;
        $('#btnImport').prop('disabled', blocked).find('span').text(
            (s.error || 0) > 0 ? L.fixErrors : (lastNew === 0 ? L.nothing : L.importN.replace('%d', lastNew)));
    }

    $('#importForm').on('submit', function (e) {
        e.preventDefault();
        const req = send('preview');
        if (!req) return;
        const btn = $('#btnPreview'), orig = btn.html();
        btn.prop('disabled', true).html(`<span class="spinner-border spinner-border-sm me-1"></span> ${esc(L.checking)}`);
        req.done(res => { if (res.success) showPlan(res); else Swal.fire({ icon: 'error', title: L.error, text: res.message }); })
           .fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.serverError }))
           .always(() => btn.prop('disabled', false).html(orig));
    });

    $('#btnImport').on('click', function () {
        Swal.fire({ title: L.confirmQ.replace('%d', lastNew), text: L.confirmText, icon: 'question', showCancelButton: true,
                    confirmButtonText: L.yes, cancelButtonText: L.cancel, confirmButtonColor: '#0d6efd' })
            .then(x => {
                if (!x.isConfirmed) return;
                const req = send('commit');
                if (!req) return;
                Swal.fire({ title: L.importing, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                req.done(res => {
                    if (!res.success) { Swal.fire({ icon: 'error', title: L.error, text: res.message }); if (res.plan) showPlan(res); return; }
                    $('#previewResult').prop('hidden', true); $('#previewEmpty').prop('hidden', false);
                    $('#importFile').val('');
                    Swal.fire({ icon: 'success', title: L.done, text: res.message, showCancelButton: true, confirmButtonText: L.goProducts, cancelButtonText: L.cancel, confirmButtonColor: '#0d6efd' })
                        .then(y => { if (y.isConfirmed) window.location.href = PRODUCTS_URL; });
                }).fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.serverError }));
            });
    });

    if (typeof logReportAction === 'function') logReportAction('Viewed Product Import Page', 'User viewed the product import page');
});
</script>

<?php
require_once 'footer.php';
ob_end_flush();
?>
