<?php
// File: app/bms/purchase/debit_notes/debit_note_create.php
// scope-audit: skip — create form; any linked purchase return is scope-checked by
// api/purchase/get_debit_note_source.php before its data is returned.
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('debit_notes');
if (!canCreate('debit_notes')) { header('Location: ' . getUrl('debit_notes')); exit; }

includeHeader();
global $pdo;

$origin_return_id = isset($_GET['purchase_return_id']) ? intval($_GET['purchase_return_id']) : 0;

$year = date('Y');
$stmt = $pdo->prepare("SELECT debit_note_number FROM debit_notes WHERE debit_note_number LIKE ? ORDER BY debit_note_id DESC LIMIT 1");
$stmt->execute(["DBN-$year-%"]);
$last = $stmt->fetchColumn();
$seq = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int)$m[1] + 1) : 1;
$next_number = sprintf('DBN-%s-%04d', $year, $seq);
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('debit_notes') ?>" class="text-decoration-none">Debit Notes</a></li>
            <li class="breadcrumb-item active">New</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff text-primary me-2"></i>New Debit Note</h4>
        <a href="<?= getUrl('debit_notes') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <form id="dnForm" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="purchase_return_id" id="f_purchase_return_id" value="<?= $origin_return_id ?: '' ?>">

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-primary text-white py-2"><i class="bi bi-info-circle me-1"></i> Debit Note Details</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Debit Note # <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="debit_note_number" id="f_number" value="<?= htmlspecialchars($next_number) ?>" required readonly>
                                    <button type="button" class="btn btn-outline-secondary" id="btnRefreshNo" title="Regenerate"><i class="bi bi-arrow-clockwise"></i></button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Debit Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="debit_date" id="f_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" name="supplier_id" id="f_supplier" required style="width:100%"></select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Link Approved Purchase Return <small class="text-muted">(optional — auto-fills items)</small></label>
                                <select class="form-select" id="f_link_return" style="width:100%"></select>
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
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-list-ul text-primary me-1"></i> Line Items</span>
                        <button type="button" class="btn btn-sm btn-primary" id="btnAddLine"><i class="bi bi-plus-circle me-1"></i> Add Line</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:220px;">Description</th>
                                    <th class="text-center" style="width:110px;">Qty</th>
                                    <th class="text-end" style="width:140px;">Unit Price</th>
                                    <th class="text-center" style="width:120px;">VAT</th>
                                    <th class="text-end" style="width:140px;">Line Total</th>
                                    <th style="width:44px;"></th>
                                </tr>
                            </thead>
                            <tbody id="dnItemsBody"></tbody>
                        </table>
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
                        <div class="d-flex justify-content-between mb-3"><span class="h6 mb-0">Total Debit</span><span class="h5 fw-bold text-primary mb-0" id="sumTotal">0.00</span></div>
                        <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-check-circle me-1"></i> Create Debit Note</button>
                        <p class="small text-muted mt-2 mb-0">Created as <strong>Pending</strong>. It then follows Review → Approve → Refund received.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const DN_API_CREATE = '<?= buildUrl('api/purchase/create_debit_note.php') ?>';
const DN_API_SUP    = '<?= buildUrl('api/purchase/search_debit_suppliers.php') ?>';
const DN_API_RET    = '<?= buildUrl('api/purchase/search_approved_purchase_returns.php') ?>';
const DN_API_SRC    = '<?= buildUrl('api/purchase/get_debit_note_source.php') ?>';
const ORIGIN_RET_ID = <?= $origin_return_id ?: 'null' ?>;

function money(v){ return Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }

function lineRow(d){
    d = d || {};
    const desc = d.description || '', qty = d.quantity!=null?d.quantity:1, price = d.unit_price!=null?d.unit_price:0,
    rate = (d.tax_rate==18)?18:0, pid = d.product_id!=null?d.product_id:'';
    return `<tr>
        <td><input type="text" class="form-control form-control-sm li-desc" value="${$('<div>').text(desc).html()}" placeholder="Item / reason" required>
            <input type="hidden" class="li-pid" value="${pid}"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-center li-qty" value="${qty}"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end li-price" value="${price}"></td>
        <td><select class="form-select form-select-sm li-vat"><option value="0" ${rate===0?'selected':''}>No Tax</option><option value="18" ${rate===18?'selected':''}>VAT 18%</option></select></td>
        <td class="text-end fw-semibold li-total">0.00</td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger li-del"><i class="bi bi-x-lg"></i></button></td>
    </tr>`;
}
function recalc(){
    let sub=0, vat=0;
    $('#dnItemsBody tr').each(function(){
        const q=parseFloat($(this).find('.li-qty').val())||0, p=parseFloat($(this).find('.li-price').val())||0, r=parseFloat($(this).find('.li-vat').val())||0;
        const base=q*p, tax=base*(r/100); $(this).find('.li-total').text(money(base+tax)); sub+=base; vat+=tax;
    });
    $('#sumSubtotal').text(money(sub)); $('#sumVat').text(money(vat)); $('#sumTotal').text(money(sub+vat));
}
function addLine(d){ $('#dnItemsBody').append(lineRow(d)); recalc(); }

function loadSource(returnId){
    if(!returnId) return;
    Swal.fire({ title:'Loading return...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    $.getJSON(DN_API_SRC, { purchase_return_id: returnId })
        .done(function(res){
            Swal.close();
            if(!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message}); return; }
            const opt = new Option(res.supplier_name, res.supplier_id, true, true);
            $('#f_supplier').append(opt).trigger('change');
            $('#f_purchase_return_id').val(returnId);
            if(res.reason) $('#f_reason').val(res.reason);
            $('#dnItemsBody').empty();
            (res.items||[]).forEach(addLine);
            if(!(res.items||[]).length) addLine();
        })
        .fail(function(){ Swal.close(); Swal.fire({icon:'error',title:'Error',text:'Could not load the purchase return.'}); });
}

$(document).ready(function(){
    $('#f_supplier').select2({ theme:'bootstrap-5', placeholder:'Search supplier...', allowClear:true, width:'100%',
        minimumInputLength:1, ajax:{ url:DN_API_SUP, dataType:'json', delay:300, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
    $('#f_link_return').select2({ theme:'bootstrap-5', placeholder:'Search approved purchase return...', allowClear:true, width:'100%',
        minimumInputLength:1, ajax:{ url:DN_API_RET, dataType:'json', delay:300, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
    $('#f_link_return').on('select2:select', function(e){ loadSource(e.params.data.id); });
    $('#f_link_return').on('select2:clear', function(){ $('#f_purchase_return_id').val(''); });

    $('#btnAddLine').on('click', ()=>addLine());
    $('#dnItemsBody').on('input change', '.li-qty,.li-price,.li-vat', recalc);
    $('#dnItemsBody').on('click', '.li-del', function(){ $(this).closest('tr').remove(); recalc(); });

    $('#btnRefreshNo').on('click', function(){ $.getJSON(DN_API_CREATE, { action:'get_next_ref' }, function(res){ if(res.success) $('#f_number').val(res.ref); }); });

    if(ORIGIN_RET_ID){ loadSource(ORIGIN_RET_ID); } else { addLine(); }

    $('#dnForm').on('submit', function(e){
        e.preventDefault();
        if(!$('#f_supplier').val()){ Swal.fire({icon:'error',title:'Supplier required',text:'Please select a supplier.'}); return; }
        const rows=[]; let valid=true;
        $('#dnItemsBody tr').each(function(){
            const desc=$(this).find('.li-desc').val().trim(), qty=parseFloat($(this).find('.li-qty').val())||0,
            price=parseFloat($(this).find('.li-price').val())||0, rate=parseFloat($(this).find('.li-vat').val())||0, pid=$(this).find('.li-pid').val();
            if(!desc || qty<=0){ valid=false; return; }
            rows.push({ description:desc, quantity:qty, unit_price:price, tax_rate:rate, product_id:pid||null });
        });
        if(!rows.length || !valid){ Swal.fire({icon:'error',title:'Invalid items',text:'Add at least one line with a description and quantity > 0.'}); return; }
        const btn=$(this).find('[type="submit"]'); const orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:DN_API_CREATE, type:'POST', dataType:'json',
            data:{ _csrf:$('[name=_csrf]').val(), debit_note_number:$('#f_number').val(), debit_date:$('#f_date').val(),
                supplier_id:$('#f_supplier').val(), purchase_return_id:$('#f_purchase_return_id').val(),
                reason:$('#f_reason').val(), notes:$('#f_notes').val(), items:JSON.stringify(rows) },
            success:function(res){
                if(res.success){ Swal.fire({icon:'success',title:'Created!',text:res.message,timer:1600,showConfirmButton:false}).then(()=>{ window.location.href='debit_note_view?id='+res.id; }); }
                else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
            },
            error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); },
            complete:function(){ btn.prop('disabled',false).html(orig); }
        });
    });
});
</script>

<?php includeFooter(); ?>
