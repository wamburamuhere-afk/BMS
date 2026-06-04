<?php
// File: app/bms/sales/credit_notes/credit_note_create.php
// scope-audit: skip — create form; the linked sales return is scope-checked by
// api/sales/get_credit_note_source.php before its data is returned.
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('credit_notes');
if (!canCreate('credit_notes')) { header('Location: ' . getUrl('credit_notes')); exit; }

includeHeader();
global $pdo;

// Optional origin sales return (from the "Create Credit Note" button on a return)
$origin_return_id = isset($_GET['sales_return_id']) ? intval($_GET['sales_return_id']) : 0;

$year = date('Y');
$stmt = $pdo->prepare("SELECT credit_note_number FROM credit_notes WHERE credit_note_number LIKE ? ORDER BY credit_note_id DESC LIMIT 1");
$stmt->execute(["CN-$year-%"]);
$last = $stmt->fetchColumn();
$seq = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int)$m[1] + 1) : 1;
$next_number = sprintf('CN-%s-%04d', $year, $seq);

require_once __DIR__ . '/../../../../helpers.php';
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Open Credit Note Form',
    ($_SESSION['username'] ?? 'User') . ' opened the Credit Note create form'
    . ($origin_return_id ? " (from sales return #$origin_return_id)" : ''));
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('credit_notes') ?>" class="text-decoration-none">Credit Notes</a></li>
            <li class="breadcrumb-item active">New</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-receipt text-primary me-2"></i>New Credit Note</h4>
        <a href="<?= getUrl('credit_notes') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back to List</a>
    </div>

    <form id="cnForm" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="sales_return_id" id="f_sales_return_id" value="<?= $origin_return_id ?: '' ?>">

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-primary text-white py-2"><i class="bi bi-info-circle me-1"></i> Credit Note Details</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Credit Note # <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="credit_note_number" id="f_number" value="<?= htmlspecialchars($next_number) ?>" required readonly>
                                    <button type="button" class="btn btn-outline-secondary" id="btnRefreshNo" title="Regenerate"><i class="bi bi-arrow-clockwise"></i></button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Credit Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="credit_date" id="f_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Customer <span class="text-danger">*</span></label>
                                <select class="form-select" name="customer_id" id="f_customer" required style="width:100%"></select>
                                <div class="form-text">Only customers with an approved return awaiting a credit note.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Approved Sales Return <span class="text-danger">*</span></label>
                                <select class="form-select" id="f_link_return" required style="width:100%"></select>
                                <div class="form-text">Required — the credit note is raised from this return.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reason</label>
                                <input type="text" class="form-control" name="reason" id="f_reason" placeholder="e.g. Goods returned, overcharge...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Notes</label>
                                <input type="text" class="form-control" name="notes" id="f_notes" placeholder="Internal notes (optional)">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white py-2 fw-bold"><i class="bi bi-list-ul text-primary me-1"></i> Line Items</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="cnItems">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:240px;">Product</th>
                                    <th class="text-center" style="width:110px;">Qty</th>
                                    <th class="text-end" style="width:140px;">Unit Price</th>
                                    <th class="text-center" style="width:120px;">VAT</th>
                                    <th class="text-end" style="width:140px;">Line Total</th>
                                    <th style="width:44px;"></th>
                                </tr>
                            </thead>
                            <tbody id="cnItemsBody"></tbody>
                        </table>
                    </div>
                    <div class="p-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddLine"><i class="bi bi-plus-circle me-1"></i> Add Line</button>
                    </div>
                </div>

                <!-- Attachments (modelled on GRN) -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white py-2 fw-bold"><i class="bi bi-paperclip text-primary me-1"></i> Attachments &amp; Documents</div>
                    <div class="card-body">
                        <div id="attachment-fields">
                            <div class="row g-2 attachment-row mb-2 align-items-center">
                                <div class="col-md-5"><input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name (e.g. Credit memo, Email approval)"></div>
                                <div class="col-md-6"><input type="file" class="form-control form-control-sm" name="attachments[]"></div>
                                <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeAttachmentRow(this)"><i class="bi bi-trash3"></i></button></div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addAttachmentRow()"><i class="bi bi-plus-circle me-1"></i> Add Attachment</button>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm" style="position:sticky; top:80px;">
                    <div class="card-header bg-primary text-white py-2"><i class="bi bi-calculator me-1"></i> Summary</div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><span class="fw-semibold" id="sumSubtotal">0.00</span></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">VAT (18%)</span><span class="fw-semibold" id="sumVat">0.00</span></div>
                        <hr>
                        <div class="d-flex justify-content-between mb-3"><span class="h6 mb-0">Total Credit</span><span class="h5 fw-bold text-primary mb-0" id="sumTotal">0.00</span></div>
                        <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-check-circle me-1"></i> Create Credit Note</button>
                        <p class="small text-muted mt-2 mb-0">Created as <strong>Pending</strong>. It then follows Review → Approve → Refund.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const CN_API_CREATE = '<?= buildUrl('api/sales/create_credit_note.php') ?>';
const CN_API_CUST   = '<?= buildUrl('api/sales/search_credit_customers.php') ?>';
const CN_API_RET    = '<?= buildUrl('api/sales/search_approved_sales_returns.php') ?>';
const CN_API_SRC    = '<?= buildUrl('api/sales/get_credit_note_source.php') ?>';
const CN_API_PROD   = '<?= buildUrl('api/search_products.php') ?>';
const ORIGIN_RET_ID = <?= $origin_return_id ?: 'null' ?>;

function money(v){ return Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return $('<div>').text(s==null?'':s).html(); }

function lineRow(d){
    d = d || {};
    const qty = d.quantity!=null?d.quantity:1, price = d.unit_price!=null?d.unit_price:0,
          rate = (d.tax_rate==18)?18:0, pid = d.product_id!=null?d.product_id:'', name = d.description||'';
    const productCell = d.readonly
        ? `<span class="fw-semibold">${esc(name)}</span>`
        : `<select class="form-select form-select-sm li-product" style="width:100%"></select>`;
    return `<tr>
        <td>${productCell}<input type="hidden" class="li-pid" value="${pid}"><input type="hidden" class="li-name" value="${esc(name)}"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-center li-qty" value="${qty}"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end li-price" value="${price}"></td>
        <td><select class="form-select form-select-sm li-vat"><option value="0" ${rate===0?'selected':''}>No Tax</option><option value="18" ${rate===18?'selected':''}>VAT 18%</option></select></td>
        <td class="text-end fw-semibold li-total">0.00</td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger li-del" title="Remove"><i class="bi bi-trash3"></i></button></td>
    </tr>`;
}
function recalc(){
    let sub=0, vat=0;
    $('#cnItemsBody tr').each(function(){
        const q=parseFloat($(this).find('.li-qty').val())||0, p=parseFloat($(this).find('.li-price').val())||0, r=parseFloat($(this).find('.li-vat').val())||0;
        const base=q*p, tax=base*(r/100); $(this).find('.li-total').text(money(base+tax)); sub+=base; vat+=tax;
    });
    $('#sumSubtotal').text(money(sub)); $('#sumVat').text(money(vat)); $('#sumTotal').text(money(sub+vat));
}
function addLine(d){
    const $row = $(lineRow(d || {readonly:false})).appendTo('#cnItemsBody');
    const $prod = $row.find('.li-product');
    if ($prod.length) {
        $prod.select2({ theme:'bootstrap-5', placeholder:'Search product...', allowClear:false, width:'100%',
            minimumInputLength:0,
            ajax:{ url:CN_API_PROD, dataType:'json', delay:250, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
        $prod.on('select2:select', function(e){
            const p=e.params.data;
            $row.find('.li-pid').val(p.id); $row.find('.li-name').val(p.name||p.text);
            if(!parseFloat($row.find('.li-price').val())) $row.find('.li-price').val(p.price||0);
            $row.find('.li-vat').val(String(p.tax_rate||0)); recalc();
        });
    }
    recalc();
}

function loadSource(returnId){
    if(!returnId) return;
    Swal.fire({ title:'Loading return...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    $.getJSON(CN_API_SRC, { sales_return_id: returnId })
        .done(function(res){
            Swal.close();
            if(!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message}); $('#f_sales_return_id').val(''); return; }
            const opt = new Option(res.customer_name, res.customer_id, true, true);
            $('#f_customer').append(opt).trigger('change');
            $('#f_sales_return_id').val(returnId);
            if(res.reason) $('#f_reason').val(res.reason);
            $('#cnItemsBody').empty();
            (res.items||[]).forEach(it => addLine({ ...it, readonly:true }));
            if(!(res.items||[]).length) addLine({readonly:false});
        })
        .fail(function(){ Swal.close(); Swal.fire({icon:'error',title:'Error',text:'Could not load the sales return.'}); });
}

// ── Attachments (GRN pattern) ────────────────────────────────────────────────
function addAttachmentRow(){
    $('#attachment-fields').append(`
        <div class="row g-2 attachment-row mb-2 align-items-center">
            <div class="col-md-5"><input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name"></div>
            <div class="col-md-6"><input type="file" class="form-control form-control-sm" name="attachments[]"></div>
            <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeAttachmentRow(this)"><i class="bi bi-trash3"></i></button></div>
        </div>`);
}
function removeAttachmentRow(btn){
    if ($('.attachment-row').length > 1) $(btn).closest('.attachment-row').remove();
    else $(btn).closest('.attachment-row').find('input').val('');
}

$(document).ready(function(){
    // Curated, show-on-open pickers (minimumInputLength:0).
    $('#f_customer').select2({ theme:'bootstrap-5', placeholder:'Select customer...', allowClear:true, width:'100%',
        minimumInputLength:0,
        ajax:{ url:CN_API_CUST, dataType:'json', delay:250, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
    $('#f_link_return').select2({ theme:'bootstrap-5', placeholder:'Select approved sales return...', allowClear:true, width:'100%',
        minimumInputLength:0,
        ajax:{ url:CN_API_RET, dataType:'json', delay:250, data:p=>({q:p.term, customer_id:$('#f_customer').val()||''}), processResults:d=>({results:d.results}), cache:false } });

    $('#f_customer').on('change', function(){ $('#f_link_return').val(null).trigger('change'); });
    $('#f_link_return').on('select2:select', function(e){ loadSource(e.params.data.id); });
    $('#f_link_return').on('select2:clear', function(){ $('#f_sales_return_id').val(''); });

    $('#btnAddLine').on('click', ()=>addLine({readonly:false}));
    $('#cnItemsBody').on('input change', '.li-qty,.li-price,.li-vat', recalc);
    $('#cnItemsBody').on('click', '.li-del', function(){ $(this).closest('tr').remove(); recalc(); });

    $('#btnRefreshNo').on('click', function(){ $.getJSON(CN_API_CREATE, { action:'get_next_ref' }, function(res){ if(res.success) $('#f_number').val(res.ref); }); });

    if(ORIGIN_RET_ID){ loadSource(ORIGIN_RET_ID); }

    $('#cnForm').on('submit', function(e){
        e.preventDefault();
        if(!$('#f_sales_return_id').val()){ Swal.fire({icon:'error',title:'Return required',text:'Select an approved sales return.'}); return; }
        if(!$('#f_customer').val()){ Swal.fire({icon:'error',title:'Customer required',text:'Please select a customer.'}); return; }
        const rows=[]; let valid=true;
        $('#cnItemsBody tr').each(function(){
            const pid=$(this).find('.li-pid').val(), name=$(this).find('.li-name').val().trim(),
                  qty=parseFloat($(this).find('.li-qty').val())||0, price=parseFloat($(this).find('.li-price').val())||0,
                  rate=parseFloat($(this).find('.li-vat').val())||0;
            if(!pid || qty<=0){ valid=false; return; }
            rows.push({ description:name, quantity:qty, unit_price:price, tax_rate:rate, product_id:pid });
        });
        if(!rows.length || !valid){ Swal.fire({icon:'error',title:'Invalid items',text:'Each line must be a real product with quantity > 0.'}); return; }

        const fd = new FormData(this);
        fd.set('customer_id', $('#f_customer').val());
        fd.set('items', JSON.stringify(rows));

        const btn=$(this).find('[type="submit"]'); const orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:CN_API_CREATE, type:'POST', data:fd, processData:false, contentType:false, dataType:'json',
            success:function(res){
                if(res.success){ Swal.fire({icon:'success',title:'Created!',text:res.message,timer:1600,showConfirmButton:false}).then(()=>{ window.location.href='credit_note_view?id='+res.id; }); }
                else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
            },
            error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); },
            complete:function(){ btn.prop('disabled',false).html(orig); }
        });
    });
});
</script>

<?php includeFooter(); ?>
