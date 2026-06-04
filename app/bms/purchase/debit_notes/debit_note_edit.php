<?php
// File: app/bms/purchase/debit_notes/debit_note_edit.php
// scope-audit: skip — edits a debit note by its own PK; the supplier is master
// data and the linked return was scope-checked at creation time.
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('debit_notes');
if (!canEdit('debit_notes')) { header('Location: ' . getUrl('debit_notes')); exit; }

includeHeader();
global $pdo;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) { echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Invalid ID</div></div>'; includeFooter(); exit; }

// Carry project context (in-project edit) so Back / Save return to the
// project-anchored view, never stranding the user outside the project.
$proj_ctx = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$proj_qs  = $proj_ctx ? ('&project_id=' . $proj_ctx) : '';

$stmt = $pdo->prepare("
    SELECT dn.*, s.supplier_name, s.company_name
      FROM debit_notes dn
      LEFT JOIN suppliers s ON dn.supplier_id = s.supplier_id
     WHERE dn.debit_note_id = ? AND dn.status != 'deleted'
");
$stmt->execute([$id]);
$dn = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dn) { echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Debit note not found</div></div>'; includeFooter(); exit; }
if ($dn['status'] !== 'pending') {
    echo '<div class="container-fluid mt-4"><div class="alert alert-warning">Only a <strong>pending</strong> debit note can be edited. This one is <strong>' . htmlspecialchars(ucfirst($dn['status'])) . '</strong>.</div><a href="' . getUrl('debit_note_view') . '?id=' . $id . '" class="btn btn-primary">View</a></div>';
    includeFooter(); exit;
}

$stmtI = $pdo->prepare("SELECT * FROM debit_note_items WHERE debit_note_id = ?");
$stmtI->execute([$id]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../../../helpers.php';
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Open Debit Note Edit',
    ($_SESSION['username'] ?? 'User') . " opened Debit Note #{$dn['debit_note_number']} for editing");

$sup_label = $dn['supplier_name'] . (!empty($dn['company_name']) ? ' — ' . $dn['company_name'] : '');
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('debit_notes') ?>" class="text-decoration-none">Debit Notes</a></li>
            <li class="breadcrumb-item active">Edit <?= htmlspecialchars($dn['debit_note_number']) ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Debit Note</h4>
        <a href="<?= getUrl('debit_note_view') ?>?id=<?= $id ?><?= $proj_qs ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <form id="dnForm" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="debit_note_id" value="<?= $id ?>">

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-primary text-white py-2"><i class="bi bi-info-circle me-1"></i> Debit Note Details</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Debit Note #</label>
                                <input type="text" class="form-control" id="f_number" value="<?= htmlspecialchars($dn['debit_note_number']) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Debit Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="f_date" value="<?= htmlspecialchars($dn['debit_date']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" id="f_supplier" required style="width:100%">
                                    <option value="<?= (int)$dn['supplier_id'] ?>" selected><?= safe_output($sup_label) ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reason</label>
                                <input type="text" class="form-control" id="f_reason" value="<?= htmlspecialchars($dn['reason'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <input type="text" class="form-control" id="f_notes" value="<?= htmlspecialchars($dn['notes'] ?? '') ?>">
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
                        <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-check-circle me-1"></i> Save Changes</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const DN_API_UPDATE = '<?= buildUrl('api/purchase/update_debit_note.php') ?>';
const DN_API_SUP    = '<?= buildUrl('api/purchase/search_debit_suppliers.php') ?>';
const EXISTING_ITEMS = <?= json_encode(array_map(fn($i) => [
    'description' => $i['description'],
    'quantity'    => (float)$i['quantity'],
    'unit_price'  => (float)$i['unit_price'],
    'tax_rate'    => ((float)$i['tax_rate'] == 18) ? 18 : 0,
    'product_id'  => $i['product_id'] !== null ? (int)$i['product_id'] : '',
], $items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function money(v){ return Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function lineRow(d){
    d=d||{}; const desc=d.description||'', qty=d.quantity!=null?d.quantity:1, price=d.unit_price!=null?d.unit_price:0,
    rate=(d.tax_rate==18)?18:0, pid=d.product_id!=null?d.product_id:'';
    return `<tr>
        <td><input type="text" class="form-control form-control-sm li-desc" value="${$('<div>').text(desc).html()}" required>
            <input type="hidden" class="li-pid" value="${pid}"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-center li-qty" value="${qty}"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end li-price" value="${price}"></td>
        <td><select class="form-select form-select-sm li-vat"><option value="0" ${rate===0?'selected':''}>No Tax</option><option value="18" ${rate===18?'selected':''}>VAT 18%</option></select></td>
        <td class="text-end fw-semibold li-total">0.00</td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger li-del"><i class="bi bi-x-lg"></i></button></td>
    </tr>`;
}
function recalc(){
    let sub=0,vat=0;
    $('#dnItemsBody tr').each(function(){
        const q=parseFloat($(this).find('.li-qty').val())||0, p=parseFloat($(this).find('.li-price').val())||0, r=parseFloat($(this).find('.li-vat').val())||0;
        const base=q*p, tax=base*(r/100); $(this).find('.li-total').text(money(base+tax)); sub+=base; vat+=tax;
    });
    $('#sumSubtotal').text(money(sub)); $('#sumVat').text(money(vat)); $('#sumTotal').text(money(sub+vat));
}
function addLine(d){ $('#dnItemsBody').append(lineRow(d)); recalc(); }

$(document).ready(function(){
    $('#f_supplier').select2({ theme:'bootstrap-5', placeholder:'Search supplier...', allowClear:false, width:'100%',
        minimumInputLength:1, ajax:{ url:DN_API_SUP, dataType:'json', delay:300, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
    $('#btnAddLine').on('click', ()=>addLine());
    $('#dnItemsBody').on('input change', '.li-qty,.li-price,.li-vat', recalc);
    $('#dnItemsBody').on('click', '.li-del', function(){ $(this).closest('tr').remove(); recalc(); });
    (EXISTING_ITEMS.length ? EXISTING_ITEMS : [{}]).forEach(addLine);

    $('#dnForm').on('submit', function(e){
        e.preventDefault();
        if(!$('#f_supplier').val()){ Swal.fire({icon:'error',title:'Supplier required',text:'Please select a supplier.'}); return; }
        const rows=[]; let valid=true;
        $('#dnItemsBody tr').each(function(){
            const desc=$(this).find('.li-desc').val().trim(), qty=parseFloat($(this).find('.li-qty').val())||0,
            price=parseFloat($(this).find('.li-price').val())||0, rate=parseFloat($(this).find('.li-vat').val())||0, pid=$(this).find('.li-pid').val();
            if(!desc||qty<=0){ valid=false; return; }
            rows.push({description:desc,quantity:qty,unit_price:price,tax_rate:rate,product_id:pid||null});
        });
        if(!rows.length||!valid){ Swal.fire({icon:'error',title:'Invalid items',text:'Add at least one valid line.'}); return; }
        const btn=$(this).find('[type="submit"]'); const orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:DN_API_UPDATE, type:'POST', dataType:'json',
            data:{ _csrf:$('[name=_csrf]').val(), debit_note_id:$('[name=debit_note_id]').val(),
                debit_date:$('#f_date').val(), supplier_id:$('#f_supplier').val(),
                reason:$('#f_reason').val(), notes:$('#f_notes').val(), items:JSON.stringify(rows) },
            success:function(res){
                if(res.success){ Swal.fire({icon:'success',title:'Saved!',text:res.message,timer:1500,showConfirmButton:false}).then(()=>{ window.location.href='debit_note_view?id='+$('[name=debit_note_id]').val()+'<?= $proj_qs ?>'; }); }
                else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
            },
            error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); },
            complete:function(){ btn.prop('disabled',false).html(orig); }
        });
    });
});
</script>

<?php includeFooter(); ?>
