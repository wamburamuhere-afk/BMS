<?php
// app/bms/pos/restock_modal_js.php — script for the Receive Stock window
// (restock_modal.php). Plain JavaScript, included inside a <script> block by
// the POS (pos_scripts_new.php) and the Receive Stock page (receive_stock.php).
//
// The window's price lookup needs a shop. With more than one shop the window
// has its own Shop field; otherwise it uses #posWarehouseId (the POS till) or
// #restockDefaultWarehouseId (the Receive Stock page's only shop).
//
// ?product_id=N pre-selects that product (Products list "Receive Stock") —
// looked up with the same filter and label as api/pos/search_products_for_restock.php;
// an unknown id or a service just opens the window empty.
$__restockPre = null;
if (ctype_digit((string)($_GET['product_id'] ?? ''))) {
    try {
        $__rp = $pdo->prepare("SELECT product_id, product_name, sku FROM products WHERE product_id = ? AND status != 'deleted' AND is_service != 1");
        $__rp->execute([(int)$_GET['product_id']]);
        if ($__r = $__rp->fetch(PDO::FETCH_ASSOC)) {
            $__n = applyCaseMode($__r['product_name']);
            $__restockPre = ['id' => (int)$__r['product_id'], 'text' => $__r['sku'] ? ($__r['sku'] . ' — ' . $__n) : $__n];
        }
    } catch (Throwable $e) { $__restockPre = null; }
}
?>
const RESTOCK_T = {
    saving: <?= json_encode(t('Saving...')) ?>,
    saved: <?= json_encode(t('Product restocked successfully!')) ?>,
    failed: <?= json_encode(t('Restock Failed')) ?>,
    selectProductFirst: <?= json_encode(t('Select a product first.')) ?>
};
const RESTOCK_PRESELECT = <?= json_encode($__restockPre) ?>;

function restockShopId() {
    if ($('#restock_warehouse_id').length) return $('#restock_warehouse_id').val();
    return $('#posWarehouseId').val() || $('#restockDefaultWarehouseId').val() || '';
}

function restockResetForm() {
    $('#restockProductForm')[0].reset();
    $('#restock_product_id').val(null).trigger('change');
    $('#restock_date').val(<?= json_encode(date('Y-m-d')) ?>);
    $('#restock-message').html('');
}

// Opens the window. With its own Shop field, it starts on the till's shop
// (the common single-shop case needs no extra click).
function openRestockProductModal() {
    if ($('#restock_warehouse_id').length && $('#posWarehouseId').val()) {
        $('#restock_warehouse_id').val($('#posWarehouseId').val());
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('restockProductModal')).show();
    if (RESTOCK_PRESELECT && !$('#restock_product_id').val()) {
        $('#restock_product_id').append(new Option(RESTOCK_PRESELECT.text, RESTOCK_PRESELECT.id, true, true)).trigger('change')
            .trigger({ type: 'select2:select', params: { data: RESTOCK_PRESELECT } });   // loads its last prices
    }
}

// Wires the window once. onSaved runs after a successful save (the success
// message has already been shown).
function initRestockModal(onSaved) {
    if (!$('#restockProductModal').length) return;

    $('#restock_product_id').select2({
        theme: 'bootstrap-5', width: '100%', dropdownParent: $('#restockProductModal'),
        placeholder: <?= json_encode(t('Search product by name or SKU')) ?>,
        ajax: {
            url: '<?= buildUrl('/api/pos/search_products_for_restock.php') ?>',
            dataType: 'json', delay: 300, cache: true,
            data: p => ({ q: p.term })
        }
    });

    // Prefill buying/wholesale/retail price from the product's most recent
    // batch in that shop (falls back to the product's own purchase/selling
    // price when it has never been batch-tracked there).
    $('#restock_product_id').on('select2:select', function () {
        const pid = $(this).val();
        const wid = restockShopId();
        if (!pid || !wid) return;
        $.getJSON('<?= buildUrl('/api/pos/get_restock_defaults.php') ?>', { product_id: pid, warehouse_id: wid }, function (res) {
            if (res.success) {
                $('#restock_buying_price').val(res.data.buying_price || '');
                $('#restock_wholesale_price').val(res.data.wholesale_price || '');
                $('#restock_selling_price').val(res.data.selling_price || '');
            }
        });
    });

    $('#restockProductModal').on('hidden.bs.modal', restockResetForm);

    $('#restockProductForm').on('submit', function (e) {
        e.preventDefault();
        if (!$('#restock_product_id').val()) {
            Swal.fire({ icon: 'warning', title: RESTOCK_T.selectProductFirst });
            return;
        }
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        const showError = msg => $('#restock-message').empty()
            .append($('<div class="alert alert-danger py-2 mb-0"></div>').text(msg));
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + RESTOCK_T.saving);
        $.ajax({
            url: '<?= buildUrl('/api/pos/quick_restock.php') ?>',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: RESTOCK_T.saved, timer: 1800, showConfirmButton: false })
                        .then(() => { if (typeof onSaved === 'function') onSaved(); });
                } else {
                    showError(res.message);
                }
            },
            error: function (xhr) {
                // A caught server-side exception still replies with real JSON
                // (success:false, message:<reason>) but a non-2xx status code —
                // jQuery routes that here, so read the reason from the body.
                let msg = RESTOCK_T.failed;
                try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
                showError(msg);
            },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });
}
