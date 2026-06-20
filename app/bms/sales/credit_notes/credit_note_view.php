<?php
// File: app/bms/sales/credit_notes/credit_note_view.php
// scope-audit: skip — viewed by own PK; the linked return was scope-checked at
// creation, and credit notes carry no direct project_id.
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/payment_source.php';

autoEnforcePermission('credit_notes');
includeHeader();
global $pdo;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) { echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Invalid ID</div></div>'; includeFooter(); exit; }

$stmt = $pdo->prepare("
    SELECT cn.*,
           c.customer_name, c.company_name, c.email AS c_email, c.phone AS c_phone,
           sr.return_number,
           uc.username AS created_by_name,
           TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
           TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
           pa.account_name AS paid_from_name
      FROM credit_notes cn
      LEFT JOIN customers c      ON cn.customer_id          = c.customer_id
      LEFT JOIN sales_returns sr ON cn.sales_return_id      = sr.sales_return_id
      LEFT JOIN users uc         ON cn.created_by           = uc.user_id
      LEFT JOIN users ur         ON cn.reviewed_by          = ur.user_id
      LEFT JOIN users ua         ON cn.approved_by          = ua.user_id
      LEFT JOIN accounts pa      ON cn.paid_from_account_id = pa.account_id
     WHERE cn.credit_note_id = ? AND cn.status != 'deleted'
");
$stmt->execute([$id]);
$cn = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cn) { echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Credit note not found</div></div>'; includeFooter(); exit; }

$stmtI = $pdo->prepare("SELECT * FROM credit_note_items WHERE credit_note_id = ?");
$stmtI->execute([$id]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// Attachments (modelled on grn_view.php) — degrade to [] if the table is absent.
$attachments = [];
try {
    $stmtA = $pdo->prepare("SELECT * FROM credit_note_attachments WHERE credit_note_id = ? ORDER BY uploaded_at DESC");
    $stmtA->execute([$id]);
    $attachments = $stmtA->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $attachments = []; }

require_once __DIR__ . '/../../../../helpers.php';
logActivity($pdo, $_SESSION['user_id'], 'View Credit Note', ($_SESSION['username'] ?? 'User') . " viewed Credit Note #{$cn['credit_note_number']}");

$can_review  = canReview('credit_notes');
$can_approve = canApprove('credit_notes');
$can_edit    = canEdit('credit_notes');
$status      = $cn['status'];

$badge = [
    'pending'   => ['#e9ecef', '#495057'],
    'reviewed'  => ['#bfdbfe', '#1e3a8a'],
    'approved'  => ['#0d6efd', '#fff'],
    'paid'      => ['#052c65', '#fff'],
    'rejected'  => ['#dc3545', '#fff'],
    'cancelled' => ['#6c757d', '#fff'],
][$status] ?? ['#e9ecef', '#495057'];
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('credit_notes') ?>" class="text-decoration-none">Credit Notes</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($cn['credit_note_number']) ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1 fw-bold"><i class="bi bi-receipt text-primary me-2"></i><?= htmlspecialchars($cn['credit_note_number']) ?></h4>
            <span class="badge" style="background:<?= $badge[0] ?>;color:<?= $badge[1] ?>;padding:.45em .8em;border-radius:50rem;"><?= strtoupper($status) ?></span>
            <span class="text-muted ms-2"><i class="bi bi-calendar-event"></i> <?= date('d M Y', strtotime($cn['credit_date'])) ?></span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($status === 'pending' && $can_review): ?>
                <button class="btn btn-primary" onclick="cnReview()"><i class="bi bi-send-check me-1"></i> Send for Review</button>
            <?php endif; ?>
            <?php if ($status === 'reviewed' && $can_approve): ?>
                <button class="btn btn-primary" onclick="cnApprove()"><i class="bi bi-check2-all me-1"></i> Approve</button>
            <?php endif; ?>
            <?php if ($status === 'approved' && $can_approve): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#payModal"><i class="bi bi-cash-coin me-1"></i> Record Payment</button>
            <?php endif; ?>
            <?php if ($status === 'pending' && $can_edit): ?>
                <a class="btn btn-outline-primary" href="<?= getUrl('credit_note_edit') ?>?id=<?= $id ?>"><i class="bi bi-pencil me-1"></i> Edit</a>
            <?php endif; ?>
            <a class="btn btn-outline-primary" href="<?= getUrl('print_credit_note') ?>?id=<?= $id ?>" target="_blank"><i class="bi bi-printer me-1"></i> Print</a>
            <a class="btn btn-outline-secondary" href="<?= getUrl('credit_notes') ?>"><i class="bi bi-arrow-left me-1"></i> Back</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2 fw-bold"><i class="bi bi-list-ul text-primary me-1"></i> Credited Items</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Description</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-center">VAT</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $it): ?>
                            <tr>
                                <td><?= safe_output($it['description']) ?></td>
                                <td class="text-center"><?= rtrim(rtrim(number_format($it['quantity'], 2), '0'), '.') ?></td>
                                <td class="text-end"><?= number_format($it['unit_price'], 2) ?></td>
                                <td class="text-center"><?= ((float)$it['tax_rate'] == 18) ? '18%' : '—' ?></td>
                                <td class="text-end fw-semibold"><?= number_format($it['total_amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr><td colspan="4" class="text-end text-muted">Subtotal</td><td class="text-end"><?= number_format($cn['subtotal_amount'], 2) ?></td></tr>
                            <tr><td colspan="4" class="text-end text-muted">VAT (18%)</td><td class="text-end"><?= number_format($cn['total_tax'], 2) ?></td></tr>
                            <tr><td colspan="4" class="text-end fw-bold">Total Credit</td><td class="text-end fw-bold text-primary"><?= number_format($cn['grand_total'], 2) ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <?php if (!empty($cn['reason']) || !empty($cn['notes'])): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <?php if (!empty($cn['reason'])): ?><p class="mb-1"><strong>Reason:</strong> <?= safe_output($cn['reason']) ?></p><?php endif; ?>
                    <?php if (!empty($cn['notes'])): ?><p class="mb-0 text-muted"><strong>Notes:</strong> <?= nl2br(safe_output($cn['notes'])) ?></p><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-primary text-white py-2"><i class="bi bi-person me-1"></i> Customer</div>
                <div class="card-body">
                    <h6 class="fw-bold mb-1"><?= safe_output($cn['customer_name']) ?></h6>
                    <?php if (!empty($cn['company_name'])): ?><p class="text-muted small mb-2"><?= safe_output($cn['company_name']) ?></p><?php endif; ?>
                    <?php if (!empty($cn['c_email'])): ?><div class="small"><i class="bi bi-envelope text-muted me-1"></i><?= safe_output($cn['c_email']) ?></div><?php endif; ?>
                    <?php if (!empty($cn['c_phone'])): ?><div class="small"><i class="bi bi-telephone text-muted me-1"></i><?= safe_output($cn['c_phone']) ?></div><?php endif; ?>
                    <hr>
                    <div class="small"><strong>Origin:</strong>
                        <?php if (!empty($cn['sales_return_id'])): ?>
                            <a href="<?= getUrl('sales_return_view') ?>?id=<?= (int)$cn['sales_return_id'] ?>" class="text-decoration-none"><?= safe_output($cn['return_number'] ?: ('Return #' . $cn['sales_return_id'])) ?></a>
                        <?php elseif (!empty($cn['invoice_id'])): ?>
                            Invoice #<?= (int)$cn['invoice_id'] ?>
                        <?php else: ?>
                            <span class="text-muted">Standalone</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($attachments)): ?>
            <div class="card border-0 shadow-sm mb-3 d-print-none">
                <div class="card-header bg-white py-2 fw-bold"><i class="bi bi-paperclip text-primary me-1"></i> Attachments &amp; Documents</div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($attachments as $att):
                            $ext = strtolower(pathinfo($att['file_path'], PATHINFO_EXTENSION));
                            $icon = 'bi-file-earmark-text'; $icol = 'text-primary';
                            if (in_array($ext, ['jpg','jpeg','png','gif'])) { $icon = 'bi-file-earmark-image'; $icol = 'text-success'; }
                            if ($ext === 'pdf') { $icon = 'bi-file-earmark-pdf'; $icol = 'text-danger'; }
                            $file_url = '../../../../' . $att['file_path'];
                        ?>
                        <div class="list-group-item d-flex align-items-center justify-content-between py-2">
                            <div class="d-flex align-items-center text-truncate me-2">
                                <i class="bi <?= $icon ?> fs-5 <?= $icol ?> me-2"></i>
                                <span class="fw-semibold text-truncate"><?= safe_output($att['file_name']) ?></span>
                                <span class="text-muted small ms-2">(<?= strtoupper($ext) ?>)</span>
                            </div>
                            <a href="<?= htmlspecialchars($file_url) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-arrow-down"></i></a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2 fw-bold"><i class="bi bi-shield-check text-primary me-1"></i> Approval Trail</div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Created</span><span><?= safe_output($cn['created_by_name'] ?: '—') ?><?= $cn['created_at'] ? ' · ' . date('d M', strtotime($cn['created_at'])) : '' ?></span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Reviewed</span><span><?= $cn['reviewed_by'] ? safe_output($cn['reviewer_name']) . ($cn['reviewed_at'] ? ' · ' . date('d M', strtotime($cn['reviewed_at'])) : '') : '<span class="text-muted">Pending</span>' ?></span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Approved</span><span><?= $cn['approved_by'] ? safe_output($cn['approver_name']) . ($cn['approved_at'] ? ' · ' . date('d M', strtotime($cn['approved_at'])) : '') : '<span class="text-muted">Pending</span>' ?></span></div>
                    <div class="d-flex justify-content-between"><span class="text-muted">Refunded</span><span><?php if ($status === 'paid'): ?><?= safe_output($cn['paid_from_name'] ?: 'Paid') ?><?= $cn['paid_at'] ? ' · ' . date('d M', strtotime($cn['paid_at'])) : '' ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></span></div>
                </div>
            </div>

            <?php if ($status === 'paid'): ?>
            <div class="alert" style="background:#052c65;color:#fff;border:0;">
                <i class="bi bi-check-circle me-1"></i> Refund of <strong>TZS <?= number_format($cn['grand_total'], 2) ?></strong> paid from <strong><?= safe_output($cn['paid_from_name'] ?: 'account') ?></strong>.
                <?php if (!empty($cn['payment_reference'])): ?><div class="small mt-1">Ref: <?= safe_output($cn['payment_reference']) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($status === 'approved' && $can_approve): ?>
<!-- Record Payment modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i> Record Refund Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="payForm">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="credit_note_id" value="<?= $id ?>">
                    <p class="mb-3">Refund <strong class="text-primary">TZS <?= number_format($cn['grand_total'], 2) ?></strong> to <strong><?= safe_output($cn['customer_name']) ?></strong>.</p>
                    <div class="mb-3">
                        <label class="form-label">Paid From <span class="text-danger">*</span></label>
                        <select class="form-select" name="paid_from_account_id" id="pay_account" required style="width:100%">
                            <option value="">-- Select cash/bank account --</option>
                            <?= paidFromSelectOptions($pdo) ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Payment Reference <small class="text-muted">(optional)</small></label>
                        <input type="text" class="form-control" name="payment_reference" placeholder="Cheque / transfer ref...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Pay & Mark Refunded</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const CN_ID = <?= $id ?>;
function cnPost(url, okTitle){
    Swal.fire({ title:'Processing...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    $.post(url, { credit_note_id: CN_ID, _csrf: (typeof CSRF_TOKEN!=='undefined'?CSRF_TOKEN:'') }, function(res){
        if(res.success){
            Swal.fire({icon:'success',title:okTitle,text:(res.sig_warning||res.message),showConfirmButton:true})
                .then(()=>location.reload());
        } else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
    }, 'json').fail(function(xhr){
        let msg='Server error.'; try{ const r=JSON.parse(xhr.responseText); if(r&&r.message) msg=r.message; }catch(e){}
        Swal.fire({icon:'error',title:'Error',text:msg});
    });
}
function cnReview(){
    Swal.fire({title:'Send for Review?',text:'Marks the note reviewed and captures your e-signature.',icon:'question',showCancelButton:true,confirmButtonColor:'#0d6efd',confirmButtonText:'Yes, send'})
        .then(r=>{ if(r.isConfirmed) cnPost('<?= buildUrl('api/sales/review_credit_note.php') ?>','Reviewed'); });
}
function cnApprove(){
    Swal.fire({title:'Approve Credit Note?',text:'Captures your e-signature as approver.',icon:'question',showCancelButton:true,confirmButtonColor:'#0d6efd',confirmButtonText:'Yes, approve'})
        .then(r=>{ if(r.isConfirmed) cnPost('<?= buildUrl('api/sales/approve_credit_note.php') ?>','Approved'); });
}

$(document).ready(function(){
    $('#payModal').on('shown.bs.modal', function(){
        if(!$('#pay_account').hasClass('select2-hidden-accessible')){
            $('#pay_account').select2({ theme:'bootstrap-5', dropdownParent:$('#payModal'), placeholder:'Select cash/bank account', allowClear:true, width:'100%' });
        }
    });
    $('#payForm').on('submit', function(e){
        e.preventDefault();
        if(!$('#pay_account').val()){ Swal.fire({icon:'error',title:'Account required',text:'Choose the Paid From account.'}); return; }
        const btn=$(this).find('[type="submit"]'); const orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Paying...');
        $.ajax({ url:'<?= buildUrl('api/sales/pay_credit_note.php') ?>', type:'POST', dataType:'json', data:$(this).serialize(),
            success:function(res){
                if(res.success){ bootstrap.Modal.getInstance(document.getElementById('payModal')).hide();
                    Swal.fire({icon:'success',title:'Refund recorded!',text:res.message,showConfirmButton:true}).then(()=>location.reload()); }
                else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
            },
            error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); },
            complete:function(){ btn.prop('disabled',false).html(orig); }
        });
    });
});
</script>

<?php includeFooter(); ?>
