<?php
// File: app/bms/purchase/debit_notes/debit_note_create.php
// scope-audit: skip — create form; the linked purchase return is scope-checked by
// api/purchase/get_debit_note_source.php before its data is returned.
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('debit_notes');
if (!canCreate('debit_notes')) { header('Location: ' . getUrl('debit_notes')); exit; }

includeHeader();
global $pdo;

$origin_return_id = isset($_GET['purchase_return_id']) ? intval($_GET['purchase_return_id']) : 0;

// Project context — when creating from inside a project workspace (?project=ID),
// the new note is tagged to that project and navigation stays anchored to it.
$project_ctx  = isset($_GET['project']) ? intval($_GET['project']) : 0;
$project_name = '';
if ($project_ctx > 0) {
    if (!userCan('project', $project_ctx)) { $project_ctx = 0; }  // ignore out-of-scope context
    else {
        $pstmt = $pdo->prepare("SELECT project_name FROM projects WHERE project_id = ?");
        $pstmt->execute([$project_ctx]);
        $project_name = $pstmt->fetchColumn() ?: '';
    }
}

$year = date('Y');
$stmt = $pdo->prepare("SELECT debit_note_number FROM debit_notes WHERE debit_note_number LIKE ? ORDER BY debit_note_id DESC LIMIT 1");
$stmt->execute(["DBN-$year-%"]);
$last = $stmt->fetchColumn();
$seq = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int)$m[1] + 1) : 1;
$next_number = sprintf('DBN-%s-%04d', $year, $seq);

require_once __DIR__ . '/../../../../helpers.php';
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Open Debit Note Form',
    ($_SESSION['username'] ?? 'User') . ' opened the Debit Note create form'
    . ($origin_return_id ? " (from purchase return #$origin_return_id)" : ''));
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
        <h4 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff text-primary me-2"></i>New Debit Note<?php if ($project_ctx): ?> <span class="badge bg-primary fs-6 align-middle"><i class="bi bi-kanban me-1"></i><?= safe_output($project_name) ?></span><?php endif; ?></h4>
        <div class="d-flex gap-2">
            <?php if ($project_ctx): ?>
            <a href="<?= getUrl('project_view') ?>?id=<?= $project_ctx ?>&tab=proc-debit-notes" class="btn btn-outline-primary"><i class="bi bi-kanban me-1"></i> Back to Project</a>
            <?php endif; ?>
            <a href="<?= getUrl('debit_notes') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back to List</a>
        </div>
    </div>

    <form id="dnForm" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="purchase_return_id" id="f_purchase_return_id" value="<?= $origin_return_id ?: '' ?>">
        <input type="hidden" name="project_id" id="f_project_id" value="<?= $project_ctx ?: '' ?>">

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
                                <div class="form-text">Only suppliers with an approved return awaiting a debit note.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Approved Purchase Return <span class="text-danger">*</span></label>
                                <select class="form-select" id="f_link_return" required style="width:100%"></select>
                                <div class="form-text">Required — the debit note is raised from this return.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Returned From (Warehouse)</label>
                                <input type="text" class="form-control" id="f_warehouse" value="" readonly placeholder="—">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reason</label>
                                <input type="text" class="form-control" name="reason" id="f_reason" placeholder="e.g. Goods returned, overcharge...">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <input type="text" class="form-control" name="notes" id="f_notes" placeholder="Internal notes (optional)">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white py-2 fw-bold"><i class="bi bi-list-ul text-primary me-1"></i> Line Items</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
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
                            <tbody id="dnItemsBody"></tbody>
                        </table>
                    </div>
                    <!-- Add Line: bottom-left, below the rows -->
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
const DN_API_PROD   = '<?= buildUrl('api/search_products.php') ?>';
const ORIGIN_RET_ID = <?= $origin_return_id ?: 'null' ?>;
const PROJECT_ID    = <?= $project_ctx ?: 'null' ?>;

function money(v){ return Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return $('<div>').text(s==null?'':s).html(); }

// A line is always a REAL product. Return items render the product name read-only;
// manually-added rows use a product search (Select2) so they are real too.
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
    $('#dnItemsBody tr').each(function(){
        const q=parseFloat($(this).find('.li-qty').val())||0, p=parseFloat($(this).find('.li-price').val())||0, r=parseFloat($(this).find('.li-vat').val())||0;
        const base=q*p, tax=base*(r/100); $(this).find('.li-total').text(money(base+tax)); sub+=base; vat+=tax;
    });
    $('#sumSubtotal').text(money(sub)); $('#sumVat').text(money(vat)); $('#sumTotal').text(money(sub+vat));
}
// Append a row; init product search on manual rows.
function addLine(d){
    const $row = $(lineRow(d || {readonly:false})).appendTo('#dnItemsBody');
    const $prod = $row.find('.li-product');
    if ($prod.length) {
        $prod.select2({ theme:'bootstrap-5', placeholder:'Search product...', allowClear:false, width:'100%',
            minimumInputLength:0,
            ajax:{ url:DN_API_PROD, dataType:'json', delay:250, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
        $prod.on('select2:select', function(e){
            const p = e.params.data;
            $row.find('.li-pid').val(p.id);
            $row.find('.li-name').val(p.name || p.text);
            if (!parseFloat($row.find('.li-price').val())) $row.find('.li-price').val(p.price || 0);
            $row.find('.li-vat').val(String(p.tax_rate||0));
            recalc();
        });
    }
    recalc();
}

function loadSource(returnId){
    if(!returnId) return;
    Swal.fire({ title:'Loading return...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    $.getJSON(DN_API_SRC, { purchase_return_id: returnId })
        .done(function(res){
            Swal.close();
            if(!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message}); $('#f_purchase_return_id').val(''); return; }
            // Set supplier (inject the option) + warehouse + reason.
            // IMPORTANT: trigger 'change.select2' (NOT 'change') — a plain change
            // would fire the supplier-change handler below and wipe the return the
            // user just selected. 'change.select2' only refreshes the Select2 box.
            if ($('#f_supplier').find("option[value='"+res.supplier_id+"']").length === 0) {
                $('#f_supplier').append(new Option(res.supplier_name, res.supplier_id, true, true));
            }
            $('#f_supplier').val(String(res.supplier_id)).trigger('change.select2');
            // Make the visible return picker show this return (covers both the
            // manual-pick path and the origin "Create Debit Note" button path).
            if ($('#f_link_return').find("option[value='"+returnId+"']").length === 0) {
                $('#f_link_return').append(new Option(res.return_number || ('Return #'+returnId), returnId, true, true));
            }
            $('#f_link_return').val(String(returnId)).trigger('change.select2');
            $('#f_purchase_return_id').val(returnId);
            $('#f_warehouse').val(res.warehouse_name || '—');
            if(res.reason) $('#f_reason').val(res.reason);
            $('#dnItemsBody').empty();
            (res.items||[]).forEach(it => addLine({ ...it, readonly:true }));
            if(!(res.items||[]).length) addLine({readonly:false});
        })
        .fail(function(){ Swal.close(); Swal.fire({icon:'error',title:'Error',text:'Could not load the purchase return.'}); });
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
    $('#f_supplier').select2({ theme:'bootstrap-5', placeholder:'Select supplier...', allowClear:true, width:'100%',
        minimumInputLength:0,
        ajax:{ url:DN_API_SUP, dataType:'json', delay:250, data:p=>({q:p.term, project_id:PROJECT_ID||''}), processResults:d=>({results:d.results}), cache:true } });
    $('#f_link_return').select2({ theme:'bootstrap-5', placeholder:'Select approved purchase return...', allowClear:true, width:'100%',
        minimumInputLength:0,
        ajax:{ url:DN_API_RET, dataType:'json', delay:250, data:p=>({q:p.term, supplier_id:$('#f_supplier').val()||'', project_id:PROJECT_ID||''}), processResults:d=>({results:d.results}), cache:false } });

    // Pick a supplier → narrow the return picker to that supplier.
    $('#f_supplier').on('change', function(){ $('#f_link_return').val(null).trigger('change'); });
    $('#f_link_return').on('select2:select', function(e){ loadSource(e.params.data.id); });
    $('#f_link_return').on('select2:clear', function(){ $('#f_purchase_return_id').val(''); $('#f_warehouse').val(''); });

    $('#btnAddLine').on('click', ()=>addLine({readonly:false}));
    $('#dnItemsBody').on('input change', '.li-qty,.li-price,.li-vat', recalc);
    $('#dnItemsBody').on('click', '.li-del', function(){ $(this).closest('tr').remove(); recalc(); });

    $('#btnRefreshNo').on('click', function(){ $.getJSON(DN_API_CREATE, { action:'get_next_ref' }, function(res){ if(res.success) $('#f_number').val(res.ref); }); });

    // From the purchase-return "Create Debit Note" button → preload everything.
    if(ORIGIN_RET_ID){ loadSource(ORIGIN_RET_ID); }

    $('#dnForm').on('submit', function(e){
        e.preventDefault();
        if(!$('#f_purchase_return_id').val()){ Swal.fire({icon:'error',title:'Return required',text:'Select an approved purchase return.'}); return; }
        if(!$('#f_supplier').val()){ Swal.fire({icon:'error',title:'Supplier required',text:'Please select a supplier.'}); return; }
        const rows=[]; let valid=true;
        $('#dnItemsBody tr').each(function(){
            const pid=$(this).find('.li-pid').val(), name=$(this).find('.li-name').val().trim(),
                  qty=parseFloat($(this).find('.li-qty').val())||0, price=parseFloat($(this).find('.li-price').val())||0,
                  rate=parseFloat($(this).find('.li-vat').val())||0;
            if(!pid || qty<=0){ valid=false; return; }
            rows.push({ description:name, quantity:qty, unit_price:price, tax_rate:rate, product_id:pid });
        });
        if(!rows.length || !valid){ Swal.fire({icon:'error',title:'Invalid items',text:'Each line must be a real product with quantity > 0.'}); return; }

        const fd = new FormData(this);            // captures fields + attachment files
        fd.set('supplier_id', $('#f_supplier').val());
        fd.set('items', JSON.stringify(rows));

        const btn=$(this).find('[type="submit"]'); const orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:DN_API_CREATE, type:'POST', data:fd, processData:false, contentType:false, dataType:'json',
            success:function(res){
                if(res.success){
                    const pid = $('#f_project_id').val();
                    const dest = 'debit_note_view?id=' + res.id + (pid ? '&project_id=' + pid : '');
                    Swal.fire({icon:'success',title:'Created!',text:res.message,timer:1600,showConfirmButton:false}).then(()=>{ window.location.href = dest; });
                }
                else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
            },
            error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); },
            complete:function(){ btn.prop('disabled',false).html(orig); }
        });
    });
});
</script>

<?php includeFooter(); ?>
