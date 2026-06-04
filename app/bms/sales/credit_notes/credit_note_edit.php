<?php
// File: app/bms/sales/credit_notes/credit_note_edit.php
// scope-audit: skip — edits a credit note by its own PK; the underlying customer
// is master data and the linked return was scope-checked at creation time.
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('credit_notes');
if (!canEdit('credit_notes')) { header('Location: ' . getUrl('credit_notes')); exit; }

includeHeader();
global $pdo;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) { echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Invalid ID</div></div>'; includeFooter(); exit; }

$stmt = $pdo->prepare("
    SELECT cn.*, c.customer_name, c.company_name, sr.return_number
      FROM credit_notes cn
      LEFT JOIN customers c       ON cn.customer_id     = c.customer_id
      LEFT JOIN sales_returns sr  ON cn.sales_return_id = sr.sales_return_id
     WHERE cn.credit_note_id = ? AND cn.status != 'deleted'
");
$stmt->execute([$id]);
$cn = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cn) { echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Credit note not found</div></div>'; includeFooter(); exit; }
if ($cn['status'] !== 'pending') {
    echo '<div class="container-fluid mt-4"><div class="alert alert-warning">Only a <strong>pending</strong> credit note can be edited. This one is <strong>' . htmlspecialchars(ucfirst($cn['status'])) . '</strong>.</div><a href="' . getUrl('credit_note_view') . '?id=' . $id . '" class="btn btn-primary">View</a></div>';
    includeFooter(); exit;
}

$stmtI = $pdo->prepare("SELECT * FROM credit_note_items WHERE credit_note_id = ?");
$stmtI->execute([$id]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

$attachments = [];
try {
    $stmtA = $pdo->prepare("SELECT * FROM credit_note_attachments WHERE credit_note_id = ? ORDER BY uploaded_at DESC");
    $stmtA->execute([$id]);
    $attachments = $stmtA->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $attachments = []; }

require_once __DIR__ . '/../../../../helpers.php';
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Open Credit Note Edit',
    ($_SESSION['username'] ?? 'User') . " opened Credit Note #{$cn['credit_note_number']} for editing");

$cust_label = $cn['customer_name'] . (!empty($cn['company_name']) ? ' — ' . $cn['company_name'] : '');
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('credit_notes') ?>" class="text-decoration-none">Credit Notes</a></li>
            <li class="breadcrumb-item active">Edit <?= htmlspecialchars($cn['credit_note_number']) ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Credit Note</h4>
        <a href="<?= getUrl('credit_note_view') ?>?id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <form id="cnForm" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="credit_note_id" value="<?= $id ?>">

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-primary text-white py-2"><i class="bi bi-info-circle me-1"></i> Credit Note Details</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Credit Note #</label>
                                <input type="text" class="form-control" id="f_number" value="<?= htmlspecialchars($cn['credit_note_number']) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Credit Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="f_date" value="<?= htmlspecialchars($cn['credit_date']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Customer <span class="text-danger">*</span></label>
                                <select class="form-select" id="f_customer" required style="width:100%">
                                    <option value="<?= (int)$cn['customer_id'] ?>" selected><?= safe_output($cust_label) ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Approved Sales Return</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($cn['return_number'] ?: '—') ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reason</label>
                                <input type="text" class="form-control" id="f_reason" value="<?= htmlspecialchars($cn['reason'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <input type="text" class="form-control" id="f_notes" value="<?= htmlspecialchars($cn['notes'] ?? '') ?>">
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
                            <tbody id="cnItemsBody"></tbody>
                        </table>
                    </div>
                    <div class="p-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddLine"><i class="bi bi-plus-circle me-1"></i> Add Line</button>
                    </div>
                </div>

                <!-- Attachments -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white py-2 fw-bold"><i class="bi bi-paperclip text-primary me-1"></i> Attachments &amp; Documents</div>
                    <div class="card-body">
                        <?php if (!empty($attachments)): ?>
                        <div class="list-group list-group-flush mb-3">
                            <?php foreach ($attachments as $att):
                                $ext = strtolower(pathinfo($att['file_path'], PATHINFO_EXTENSION));
                                $file_url = '../../../../' . $att['file_path'];
                            ?>
                            <div class="list-group-item d-flex align-items-center justify-content-between py-2 px-0">
                                <span class="small"><i class="bi bi-paperclip text-muted me-1"></i><?= safe_output($att['file_name']) ?> <span class="text-muted">(<?= strtoupper($ext) ?>)</span></span>
                                <a href="<?= htmlspecialchars($file_url) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-arrow-down"></i></a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div id="attachment-fields">
                            <div class="row g-2 attachment-row mb-2 align-items-center">
                                <div class="col-md-5"><input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name"></div>
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
                        <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-check-circle me-1"></i> Save Changes</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const CN_API_UPDATE = '<?= buildUrl('api/sales/update_credit_note.php') ?>';
const CN_API_CUST   = '<?= buildUrl('api/sales/search_credit_customers.php') ?>';
const CN_API_PROD   = '<?= buildUrl('api/search_products.php') ?>';
const EXISTING_ITEMS = <?= json_encode(array_map(fn($i) => [
    'description' => $i['description'],
    'quantity'    => (float)$i['quantity'],
    'unit_price'  => (float)$i['unit_price'],
    'tax_rate'    => ((float)$i['tax_rate'] == 18) ? 18 : 0,
    'product_id'  => $i['product_id'] !== null ? (int)$i['product_id'] : '',
], $items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function money(v){ return Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function lineRow(d){
    d=d||{}; const qty=d.quantity!=null?d.quantity:1, price=d.unit_price!=null?d.unit_price:0,
    rate=(d.tax_rate==18)?18:0, pid=d.product_id!=null?d.product_id:'', name=d.description||'';
    const productCell = (d.readonly && pid)
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
    let sub=0,vat=0;
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
    $('#f_customer').select2({ theme:'bootstrap-5', placeholder:'Search customer...', allowClear:false, width:'100%',
        minimumInputLength:0, ajax:{ url:CN_API_CUST, dataType:'json', delay:300, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });

    $('#btnAddLine').on('click', ()=>addLine({readonly:false}));
    $('#cnItemsBody').on('input change', '.li-qty,.li-price,.li-vat', recalc);
    $('#cnItemsBody').on('click', '.li-del', function(){ $(this).closest('tr').remove(); recalc(); });

    (EXISTING_ITEMS.length ? EXISTING_ITEMS : []).forEach(it => addLine({ ...it, readonly:true }));
    if (!EXISTING_ITEMS.length) addLine({readonly:false});

    $('#cnForm').on('submit', function(e){
        e.preventDefault();
        if(!$('#f_customer').val()){ Swal.fire({icon:'error',title:'Customer required',text:'Please select a customer.'}); return; }
        const rows=[]; let valid=true;
        $('#cnItemsBody tr').each(function(){
            const pid=$(this).find('.li-pid').val(), name=$(this).find('.li-name').val().trim(),
            qty=parseFloat($(this).find('.li-qty').val())||0, price=parseFloat($(this).find('.li-price').val())||0, rate=parseFloat($(this).find('.li-vat').val())||0;
            if(!pid||qty<=0){ valid=false; return; }
            rows.push({description:name,quantity:qty,unit_price:price,tax_rate:rate,product_id:pid});
        });
        if(!rows.length||!valid){ Swal.fire({icon:'error',title:'Invalid items',text:'Each line must be a real product with quantity > 0.'}); return; }
        const fd = new FormData(this);
        fd.set('credit_note_id', $('[name=credit_note_id]').val());
        fd.set('credit_date', $('#f_date').val());
        fd.set('customer_id', $('#f_customer').val());
        fd.set('reason', $('#f_reason').val());
        fd.set('notes', $('#f_notes').val());
        fd.set('items', JSON.stringify(rows));
        const btn=$(this).find('[type="submit"]'); const orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:CN_API_UPDATE, type:'POST', data:fd, processData:false, contentType:false, dataType:'json',
            success:function(res){
                if(res.success){ Swal.fire({icon:'success',title:'Saved!',text:res.message,timer:1500,showConfirmButton:false})
                    .then(()=>{ window.location.href='credit_note_view?id='+$('[name=credit_note_id]').val(); }); }
                else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
            },
            error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); },
            complete:function(){ btn.prop('disabled',false).html(orig); }
        });
    });
});
</script>

<?php includeFooter(); ?>
