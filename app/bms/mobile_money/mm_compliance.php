<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_compliance');

$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           COUNT(DISTINCT customer_phone) AS unique_customers,
           SUM(CASE WHEN id_type='nida' THEN 1 ELSE 0 END) AS nida_count
    FROM (
        SELECT t.customer_phone, k.id_type
        FROM mm_transactions t
        LEFT JOIN mm_kyc_records k ON k.mm_txn_id = t.mm_txn_id
        WHERE t.status = 'posted'
    ) sub
")->fetch(PDO::FETCH_ASSOC);

$pendingCount = (int)$pdo->query("
    SELECT COUNT(*) FROM mm_transactions t
    WHERE t.kyc_required=1 AND t.kyc_document_id IS NULL AND t.status='posted'
")->fetchColumn();

$kycRecords = $pdo->query("
    SELECT k.*,
           t.txn_code, t.txn_date, t.txn_type, t.principal_amount,
           a.agent_name, n.network_name, n.color_hex,
           CONCAT(u.first_name, ' ', u.last_name) AS captured_by_name
    FROM mm_kyc_records k
    JOIN mm_transactions t ON t.mm_txn_id  = k.mm_txn_id
    JOIN mm_agents a        ON a.agent_id   = t.agent_id
    JOIN mm_networks n      ON n.network_id = t.network_id
    LEFT JOIN users u       ON u.user_id    = k.captured_by
    ORDER BY k.captured_at DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$pendingKyc = $pdo->query("
    SELECT t.mm_txn_id, t.txn_code, t.txn_date, t.txn_type,
           t.customer_phone, t.customer_name, t.principal_amount,
           a.agent_name, n.network_name
    FROM mm_transactions t
    JOIN mm_agents a    ON a.agent_id   = t.agent_id
    JOIN mm_networks n  ON n.network_id = t.network_id
    WHERE t.kyc_required=1 AND t.kyc_document_id IS NULL AND t.status='posted'
    ORDER BY t.txn_date DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$idTypes = ['nida' => 'NIDA', 'voters' => 'Voter ID', 'passport' => 'Passport', 'driving_licence' => 'Driving Licence'];

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View Compliance/KYC', 'Viewed MM KYC Records');
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-shield-check text-success fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Compliance / KYC') ?></h4>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= (int)$stats['total'] ?></div>
                <div class="small text-muted"><?= t('KYC Records') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-info"><?= (int)$stats['unique_customers'] ?></div>
                <div class="small text-muted"><?= t('Unique Customers') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success"><?= (int)$stats['nida_count'] ?></div>
                <div class="small text-muted"><?= t('NIDA Verified') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-<?= $pendingCount > 0 ? 'danger' : 'secondary' ?>"><?= $pendingCount ?></div>
                <div class="small text-muted"><?= t('Pending KYC') ?></div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#kycRecordsTab"><?= t('KYC Records') ?> <span class="badge bg-secondary ms-1"><?= count($kycRecords) ?></span></a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#pendingTab"><?= t('Pending') ?> <?= $pendingCount > 0 ? '<span class="badge bg-danger ms-1">'.$pendingCount.'</span>' : '' ?></a></li>
    </ul>

<style>
.mm-thead th{background:#fff!important;color:#212529;border-bottom:2px solid #dee2e6!important;text-align:center;font-weight:600;font-size:.8rem;padding:10px 8px}
.mm-sno{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#f0f2f5;color:#6b7280;font-size:.7rem;font-weight:700;flex-shrink:0}
</style>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="kycRecordsTab">
            <div id="kycTableView">
            <div class="table-responsive">
                <table id="kycTable" class="table table-hover align-middle w-100">
                    <thead class="mm-thead">
                        <tr>
                            <th class="text-center" style="width:48px"><?= t('S/No') ?></th>
                            <th class="text-center"><?= t('Transaction') ?></th>
                            <th class="text-center"><?= t('Date') ?></th>
                            <th class="text-center"><?= t('Customer') ?></th>
                            <th class="text-center"><?= t('Phone') ?></th>
                            <th class="text-center"><?= t('ID Type') ?></th>
                            <th class="text-center"><?= t('ID Number') ?></th>
                            <th class="text-center"><?= t('Network') ?></th>
                            <th class="text-center"><?= t('Agent') ?></th>
                            <th class="text-center"><?= t('Amount') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($kycRecords as $r): ?>
                        <tr data-id="<?= (int)$r['mm_txn_id'] ?>" data-code="<?= htmlspecialchars($r['txn_code'] ?: '') ?>" data-date="<?= htmlspecialchars($r['txn_date'] ?: '') ?>" data-customer="<?= htmlspecialchars($r['customer_name'] ?? '—') ?>" data-phone="<?= htmlspecialchars($r['customer_phone'] ?: '') ?>" data-id-type="<?= htmlspecialchars($idTypes[$r['id_type']] ?? $r['id_type']) ?>" data-id-number="<?= htmlspecialchars($r['id_number'] ?: '') ?>" data-network="<?= htmlspecialchars($r['network_name'] ?: '') ?>" data-agent="<?= htmlspecialchars($r['agent_name'] ?: '') ?>" data-amount="<?= number_format((float)$r['principal_amount']) ?>">
                            <td class="text-center text-muted small"><?= $sno++ ?></td>
                            <td><code><?= safe_output($r['txn_code']) ?></code></td>
                            <td><?= safe_output($r['txn_date']) ?></td>
                            <td><?= safe_output($r['customer_name'] ?? '—') ?></td>
                            <td><?= safe_output($r['customer_phone']) ?></td>
                            <td><span class="badge bg-info"><?= safe_output($idTypes[$r['id_type']] ?? $r['id_type']) ?></span></td>
                            <td><code><?= safe_output($r['id_number']) ?></code></td>
                            <td><span class="badge rounded-pill" style="background:<?= safe_output($r['color_hex'] ?: '#6c757d') ?>"><?= safe_output($r['network_name']) ?></span></td>
                            <td><?= safe_output($r['agent_name']) ?></td>
                            <td class="text-center"><?= number_format((float)$r['principal_amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div><!-- end kycTableView -->
            <div id="kycCardView" class="row g-2 d-none mt-2"></div>
        </div>

        <div class="tab-pane fade" id="pendingTab">
            <?php if (empty($pendingKyc)): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= t('All KYC-required transactions are documented.') ?></div>
            <?php else: ?>
            <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= t('These transactions require KYC documentation but have not been captured.') ?></div>
            <div id="pendingTableView">
            <div class="table-responsive">
                <table id="pendingTable" class="table table-hover align-middle w-100">
                    <thead class="mm-thead">
                        <tr>
                            <th class="text-center" style="width:48px"><?= t('S/No') ?></th>
                            <th class="text-center"><?= t('Transaction') ?></th>
                            <th class="text-center"><?= t('Date') ?></th>
                            <th class="text-center"><?= t('Type') ?></th>
                            <th class="text-center"><?= t('Phone') ?></th>
                            <th class="text-center"><?= t('Customer') ?></th>
                            <th class="text-center"><?= t('Amount') ?></th>
                            <th class="text-center"><?= t('Agent') ?></th>
                            <th class="text-center"><?= t('Network') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno2 = 1; foreach ($pendingKyc as $r): ?>
                        <tr data-id="<?= (int)$r['mm_txn_id'] ?>" data-code="<?= htmlspecialchars($r['txn_code'] ?: '') ?>" data-date="<?= htmlspecialchars($r['txn_date'] ?: '') ?>" data-type="<?= htmlspecialchars(ucwords(str_replace('_',' ',$r['txn_type']))) ?>" data-phone="<?= htmlspecialchars($r['customer_phone'] ?? '—') ?>" data-customer="<?= htmlspecialchars($r['customer_name'] ?? '—') ?>" data-amount="<?= number_format((float)$r['principal_amount']) ?>" data-agent="<?= htmlspecialchars($r['agent_name'] ?: '') ?>" data-network="<?= htmlspecialchars($r['network_name'] ?: '') ?>">
                            <td class="text-center text-muted small"><?= $sno2++ ?></td>
                            <td><code><?= safe_output($r['txn_code']) ?></code></td>
                            <td><?= safe_output($r['txn_date']) ?></td>
                            <td><?= safe_output(ucwords(str_replace('_',' ',$r['txn_type']))) ?></td>
                            <td><?= safe_output($r['customer_phone'] ?? '—') ?></td>
                            <td><?= safe_output($r['customer_name'] ?? '—') ?></td>
                            <td class="text-center"><?= number_format((float)$r['principal_amount']) ?></td>
                            <td><?= safe_output($r['agent_name']) ?></td>
                            <td><?= safe_output($r['network_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div><!-- end pendingTableView -->
            <div id="pendingCardView" class="row g-2 d-none mt-2"></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#kycTable')) {
        $('#kycTable').DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [[2,'desc']], columnDefs:[{orderable:false,targets:0}],
            drawCallback: function(){ renderKycCards(this.api().rows({page:'current'}).nodes()); }
        });
    }
    if ($('#pendingTable').length && !$.fn.DataTable.isDataTable('#pendingTable')) {
        $('#pendingTable').DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [[2,'desc']], columnDefs:[{orderable:false,targets:0}],
            drawCallback: function(){ renderPendingCards(this.api().rows({page:'current'}).nodes()); }
        });
    }

    function applyKycView(){if(window.innerWidth<768){$('#kycTableView').addClass('d-none');$('#kycCardView').removeClass('d-none');}else{$('#kycTableView').removeClass('d-none');$('#kycCardView').addClass('d-none');}}
    function applyPendingView(){if($('#pendingTable').length){if(window.innerWidth<768){$('#pendingTableView').addClass('d-none');$('#pendingCardView').removeClass('d-none');}else{$('#pendingTableView').removeClass('d-none');$('#pendingCardView').addClass('d-none');}}}

    applyKycView(); applyPendingView();
    $(window).on('resize', function(){ applyKycView(); applyPendingView(); });
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function(){ applyKycView(); applyPendingView(); });

    $('#receiveModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#receiveModal'), placeholder: '<?= t('Select...') ?>', allowClear: true, width: '100%' });
            }
        });
    });

    $('#receiveForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= t('Posting...') ?>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_commission_received.php') ?>',
            type: 'POST',
            data: new FormData(this),
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Posted!') ?>', text: res.message, timer: 2000, showConfirmButton: false }).then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message });
                }
            },
            error: function (xhr) { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: xhr.responseJSON?.message||'<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('.modal').on('hidden.bs.modal', function () {
        $(this).find('form')[0]?.reset();
        $(this).find('[id$="-message"]').html('');
    });
});

function renderKycCards(nodes) {
    if (!nodes.length) { $('#kycCardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No KYC records found') ?></div>'); return; }
    let html = '';
    $(nodes).each(function (idx) {
        const $tr = $(this), sno = idx + 1;
        const code = $tr.data('code'), date = $tr.data('date');
        const customer = $tr.data('customer'), phone = $tr.data('phone');
        const idType = $tr.data('id-type'), idNumber = $tr.data('id-number');
        const network = $tr.data('network'), agent = $tr.data('agent'), amount = $tr.data('amount');
        html += `<div class="col-12"><div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
          <div class="card-body p-3 pb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="mm-sno">${sno}</span>
              <span class="badge bg-info" style="font-size:.73rem">${safeOutput(idType)}</span>
            </div>
            <div class="fw-semibold mb-2" style="font-size:.95rem"><code>${safeOutput(code)}</code></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Date') ?></span><span class="kv-val">${safeOutput(date)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Customer') ?></span><span class="kv-val">${safeOutput(customer)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Phone') ?></span><span class="kv-val">${safeOutput(phone)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('ID Number') ?></span><span class="kv-val">${safeOutput(idNumber)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Network') ?></span><span class="kv-val">${safeOutput(network)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Agent') ?></span><span class="kv-val">${safeOutput(agent)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Amount (TZS)') ?></span><span class="kv-val fw-bold">${safeOutput(amount)}</span></div>
          </div>
        </div></div>`;
    });
    $('#kycCardView').html(html);
}

function renderPendingCards(nodes) {
    if (!nodes.length) { $('#pendingCardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No pending KYC found') ?></div>'); return; }
    let html = '';
    $(nodes).each(function (idx) {
        const $tr = $(this), sno = idx + 1;
        const code = $tr.data('code'), date = $tr.data('date'), type = $tr.data('type');
        const phone = $tr.data('phone'), customer = $tr.data('customer'), amount = $tr.data('amount');
        const agent = $tr.data('agent'), network = $tr.data('network');
        html += `<div class="col-12"><div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden;border-left:3px solid #dc3545">
          <div class="card-body p-3 pb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="mm-sno">${sno}</span>
              <span class="badge bg-warning text-dark" style="font-size:.73rem"><?= t('Pending KYC') ?></span>
            </div>
            <div class="fw-semibold mb-2" style="font-size:.95rem"><code>${safeOutput(code)}</code></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Date') ?></span><span class="kv-val">${safeOutput(date)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Type') ?></span><span class="kv-val">${safeOutput(type)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Customer') ?></span><span class="kv-val">${safeOutput(customer)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Phone') ?></span><span class="kv-val">${safeOutput(phone)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Agent') ?></span><span class="kv-val">${safeOutput(agent)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Network') ?></span><span class="kv-val">${safeOutput(network)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Amount (TZS)') ?></span><span class="kv-val fw-bold">${safeOutput(amount)}</span></div>
          </div>
        </div></div>`;
    });
    $('#pendingCardView').html(html);
}
</script>
<?php includeFooter(); ?>
