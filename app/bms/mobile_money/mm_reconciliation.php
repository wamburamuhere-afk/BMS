<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_reconciliation');

$can_create = canCreate('mm_reconciliation');
$can_edit   = canEdit('mm_reconciliation');
$can_delete = canDelete('mm_reconciliation');

$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(status='open') AS open_count,
           SUM(status='resolved') AS resolved_count,
           SUM(status='disputed') AS disputed_count
    FROM mm_reconciliations
    WHERE 1=1 " . mmScopeSql('till_id') . "
")->fetch(PDO::FETCH_ASSOC);

$recons = $pdo->query("
    SELECT r.*,
           t.till_number, a.agent_name,
           n.network_name, n.color_hex,
           CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
    FROM mm_reconciliations r
    JOIN mm_tills t    ON t.till_id    = r.till_id
    JOIN mm_agents a   ON a.agent_id   = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    LEFT JOIN users u  ON u.user_id    = r.created_by
    WHERE 1=1 " . mmScopeSql('r.till_id') . "
    ORDER BY r.recon_date DESC, r.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// New-reconciliation form: only tills this user may reconcile.
$tills = $pdo->query("
    SELECT t.till_id, t.till_number, a.agent_name, n.network_name
    FROM mm_tills t
    JOIN mm_agents a   ON a.agent_id   = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    WHERE t.status = 'active' " . mmScopeSql('t.till_id', 'till', 'can_reconcile') . "
    ORDER BY a.agent_name, t.till_number
")->fetchAll(PDO::FETCH_ASSOC);
if (!$tills) $can_create = false;

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View Reconciliations', 'Viewed MM Daily Reconciliations');
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-clipboard-check text-info fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Daily Reconciliation') ?></h4>
        <?php if ($can_create): ?>
        <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#startReconModal">
            <i class="bi bi-plus-circle me-1"></i><?= t('Start Reconciliation') ?>
        </button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-primary"><?= (int)$stats['total'] ?></div>
                <div class="small text-muted"><?= t('Total') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-warning"><?= (int)$stats['open_count'] ?></div>
                <div class="small text-muted"><?= t('Open') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-success"><?= (int)$stats['resolved_count'] ?></div>
                <div class="small text-muted"><?= t('Resolved') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-danger"><?= (int)$stats['disputed_count'] ?></div>
                <div class="small text-muted"><?= t('Disputed') ?></div>
            </div>
        </div>
    </div>

<style>
.mm-stat-card{background:#d1e7dd!important;border-color:#badbcc!important;border-radius:12px;transition:transform .2s}
.mm-stat-card:hover{transform:translateY(-3px)}
.mm-stat-card .fw-bold,.mm-stat-card .fs-3,.mm-stat-card .fs-4,.mm-stat-card .fs-5{color:#0f5132!important}
.mm-stat-card .text-muted,.mm-stat-card .small{color:#0f5132!important;opacity:.85}
.mm-thead th{background:#fff!important;color:#212529;border-bottom:2px solid #dee2e6!important;text-align:center;font-weight:600;font-size:.8rem;padding:10px 8px}
.mm-sno{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#f0f2f5;color:#6b7280;font-size:.7rem;font-weight:700;flex-shrink:0}
.mm-kv{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.mm-kv:last-child{border:0}
.mm-kv .kv-lbl{color:#9ca3af}
.mm-kv .kv-val{font-weight:500;text-align:right;max-width:65%;word-break:break-word}
.mm-card-foot{display:flex;gap:6px;padding:8px 12px;border-top:1px solid #f3f4f6}
.mm-card-foot .btn{flex:1;font-size:.78rem;padding:3px 6px}
</style>
    <div id="tableView">
    <div class="table-responsive">
        <table id="reconTable" class="table table-hover align-middle w-100">
            <thead class="mm-thead">
                <tr>
                    <th class="text-center" style="width:48px"><?= t('S/No') ?></th>
                    <th class="text-center"><?= t('Code') ?></th>
                    <th class="text-center"><?= t('Date') ?></th>
                    <th class="text-center"><?= t('Till') ?></th>
                    <th class="text-center"><?= t('Agent') ?></th>
                    <th class="text-center"><?= t('Cash Var') ?></th>
                    <th class="text-center"><?= t('Float Var') ?></th>
                    <th class="text-center"><?= t('Status') ?></th>
                    <th class="text-center"><?= t('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $sno = 1; foreach ($recons as $r): $rowRec = mmTillInScope((int)$r['till_id'], 'can_reconcile'); ?>
                <tr data-id="<?= (int)$r['recon_id'] ?>" data-code="<?= htmlspecialchars($r['recon_code']) ?>" data-date="<?= htmlspecialchars($r['recon_date']) ?>" data-till="<?= htmlspecialchars($r['till_number']) ?>" data-agent="<?= htmlspecialchars($r['agent_name']) ?>" data-status="<?= htmlspecialchars($r['status']) ?>" data-notes="<?= htmlspecialchars($r['resolved_notes'] ?: '') ?>" data-can-rec="<?= $rowRec ? '1' : '0' ?>">
                    <td class="text-center text-muted small"><?= $sno++ ?></td>
                    <td><code><?= safe_output($r['recon_code']) ?></code></td>
                    <td><?= safe_output($r['recon_date']) ?></td>
                    <td>
                        <span class="badge rounded-pill" style="background:<?= safe_output($r['color_hex'] ?: '#6c757d') ?>"><?= safe_output($r['network_name']) ?></span>
                        <?= safe_output($r['till_number']) ?>
                    </td>
                    <td><?= safe_output($r['agent_name']) ?></td>
                    <td class="text-end <?= (float)($r['cash_variance'] ?? 0) != 0 ? 'text-danger fw-bold' : '' ?>">
                        <?= $r['cash_variance'] !== null ? number_format((float)$r['cash_variance']) : '—' ?>
                    </td>
                    <td class="text-end <?= (float)($r['float_variance'] ?? 0) != 0 ? 'text-danger fw-bold' : '' ?>">
                        <?= $r['float_variance'] !== null ? number_format((float)$r['float_variance']) : '—' ?>
                    </td>
                    <td>
                        <?php $badge = ['open'=>'warning','resolved'=>'success','disputed'=>'danger','closed'=>'secondary']; ?>
                        <span class="badge bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= safe_output(ucfirst($r['status'])) ?></span>
                    </td>
                    <td class="text-center">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear-fill"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:130px;font-size:.85rem">
                                <li><a class="dropdown-item" href="<?= getUrl('mm_recon_view') ?>?id=<?= $r['recon_id'] ?>"><i class="bi bi-eye me-2 text-info"></i><?= t('View') ?></a></li>
                                <?php if ($r['status'] === 'open'): ?>
                                <?php if ($can_edit && $rowRec): ?>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li><a class="dropdown-item" href="#" onclick="editRecon(<?= (int)$r['recon_id'] ?>,'<?= addslashes(htmlspecialchars($r['recon_date'])) ?>','<?= addslashes(htmlspecialchars($r['resolved_notes'] ?: '')) ?>');return false"><i class="bi bi-pencil me-2 text-warning"></i><?= t('Edit') ?></a></li>
                                <?php endif; ?>
                                <?php if ($can_delete && $rowRec): ?>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="cancelRecon(<?= (int)$r['recon_id'] ?>,'<?= addslashes(htmlspecialchars($r['recon_code'])) ?>');return false"><i class="bi bi-x-circle me-2"></i><?= t('Cancel') ?></a></li>
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
    </div><!-- end tableView -->
    <div id="cardView" class="row g-2 d-none mt-2"></div>
</div>

<?php if ($can_edit): ?>
<div class="modal fade" id="editReconModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i><?= t('Edit Reconciliation') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editReconForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_method" value="EDIT">
                    <input type="hidden" name="recon_id" id="edit_recon_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="edit-recon-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Reconciliation Date') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="recon_date" id="edit_recon_date" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Notes') ?></label>
                        <textarea class="form-control" name="notes" id="edit_recon_notes" rows="3" placeholder="<?= t('Optional notes…') ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle me-1"></i><?= t('Update') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_create): ?>
<div class="modal fade" id="startReconModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-clipboard-plus me-1"></i><?= t('Start Reconciliation') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="startReconForm" method="post" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="start-recon-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Till') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="till_id" required>
                            <option value=""></option>
                            <?php foreach ($tills as $ti): ?>
                            <option value="<?= $ti['till_id'] ?>"><?= safe_output($ti['agent_name'] . ' / ' . $ti['till_number'] . ' (' . $ti['network_name'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Reconciliation Date') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="recon_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Start') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#reconTable')) {
        $('#reconTable').DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [[2,'desc']], columnDefs:[{orderable:false,targets:0}],
            drawCallback: function(){ renderCards(this.api().rows({page:'current'}).nodes()); }
        });
    }
    function applyView(){if(window.innerWidth<768){$('#tableView').addClass('d-none');$('#cardView').removeClass('d-none');}else{$('#tableView').removeClass('d-none');$('#cardView').addClass('d-none');}}
    applyView(); $(window).on('resize',applyView);
    $('#startReconModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#startReconModal'), placeholder: '<?= t('Select...') ?>', allowClear: true, width: '100%' });
            }
        });
    });
    $('#startReconForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= t('Starting...') ?>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_reconciliation.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Started!') ?>', timer: 1800, showConfirmButton: false })
                        .then(() => window.location = '<?= getUrl('mm_recon_view') ?>?id=' + res.recon_id);
                } else { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message }); }
            },
            error: function (xhr) { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: xhr.responseJSON?.message||'<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });
    $('#editReconForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= t('Saving…') ?>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_reconciliation.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Updated!') ?>', timer: 1500, showConfirmButton: false }).then(function(){ location.reload(); });
                } else { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: '<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('.modal').on('hidden.bs.modal', function () { $(this).find('form')[0]?.reset(); $(this).find('[id$="-message"]').html(''); });
});

function editRecon(id, date, notes) {
    $('#edit_recon_id').val(id);
    $('#edit_recon_date').val(date);
    $('#edit_recon_notes').val(notes);
    new bootstrap.Modal(document.getElementById('editReconModal')).show();
}

function cancelRecon(id, code) {
    Swal.fire({
        title: '<?= t('Cancel Reconciliation?') ?>',
        html: '<?= t('This will cancel') ?> <strong>' + code + '</strong>. <?= t('This cannot be undone.') ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: '<?= t('Yes, Cancel') ?>',
        cancelButtonText: '<?= t('Keep') ?>'
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_reconciliation.php') ?>',
            type: 'POST',
            data: { _method: 'DELETE', recon_id: id, _csrf: '<?= csrf_token() ?>' },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Cancelled!') ?>', timer: 1500, showConfirmButton: false }).then(function(){ location.reload(); });
                } else {
                    Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: '<?= t('Server error.') ?>' }); }
        });
    });
}

function renderCards(nodes) {
    if (!nodes.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No reconciliations found') ?></div>'); return; }
    let html = '';
    $(nodes).each(function (idx) {
        const $tr = $(this);
        const id = $tr.data('id'), sno = idx + 1;
        const code = $tr.data('code'), date = $tr.data('date');
        const till = $tr.data('till'), agent = $tr.data('agent'), status = $tr.data('status');
        const notes = $tr.data('notes') || '';
        const isOpen = status === 'open';
        const canRec = $tr.data('can-rec') == 1;
        const canEdit = <?= json_encode((bool)$can_edit) ?> && canRec;
        const canDelete = <?= json_encode((bool)$can_delete) ?> && canRec;
        const sBadge = status === 'resolved' ? 'bg-success' : (status === 'open' ? 'bg-warning text-dark' : (status === 'disputed' ? 'bg-danger' : 'bg-secondary'));
        html += `<div class="col-12"><div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
          <div class="card-body p-3 pb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="mm-sno">${sno}</span>
              <span class="badge ${sBadge}" style="font-size:.73rem">${safeOutput(status.charAt(0).toUpperCase()+status.slice(1))}</span>
            </div>
            <div class="fw-semibold mb-2" style="font-size:.95rem"><code>${safeOutput(code)}</code></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Date') ?></span><span class="kv-val">${safeOutput(date)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Till') ?></span><span class="kv-val">${safeOutput(till)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Agent') ?></span><span class="kv-val">${safeOutput(agent)}</span></div>
          </div>
          <div class="mm-card-foot">
            <a href="<?= getUrl('mm_recon_view') ?>?id=${id}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye me-1"></i><?= t('View') ?></a>
            ${isOpen && canEdit ? `<button class="btn btn-sm btn-outline-primary" onclick="editRecon(${id},'${date}','${notes.replace(/'/g,&quot;\\&apos;&quot;)}')"><i class="bi bi-pencil me-1"></i><?= t('Edit') ?></button>` : ''}
            ${isOpen && canDelete ? `<button class="btn btn-sm btn-outline-danger" onclick="cancelRecon(${id},'${safeOutput(code)}')"><i class="bi bi-x-circle me-1"></i><?= t('Cancel') ?></button>` : ''}
          </div>
        </div></div>`;
    });
    $('#cardView').html(html);
}
</script>
<?php includeFooter(); ?>
