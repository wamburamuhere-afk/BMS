<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_kyc');

$can_create = canCreate('mm_kyc');

// Stats
$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           COUNT(DISTINCT customer_phone) AS unique_customers,
           SUM(CASE WHEN id_type='nida' THEN 1 ELSE 0 END) AS nida_count,
           SUM(CASE WHEN kyc_required=1 AND kyc_document_id IS NULL THEN 1 ELSE 0 END) AS pending_kyc
    FROM (
        SELECT t.customer_phone, t.kyc_required, t.kyc_document_id,
               k.id_type
        FROM mm_transactions t
        LEFT JOIN mm_kyc_records k ON k.mm_txn_id = t.mm_txn_id
        WHERE t.status = 'posted'
    ) sub
")->fetch(PDO::FETCH_ASSOC);

// KYC records
$kycRecords = $pdo->query("
    SELECT k.*,
           t.txn_code, t.txn_date, t.txn_type, t.principal_amount,
           a.agent_name, n.network_name, n.color_hex,
           u.name AS captured_by_name
    FROM mm_kyc_records k
    JOIN mm_transactions t ON t.mm_txn_id = k.mm_txn_id
    JOIN mm_agents a        ON a.agent_id  = t.agent_id
    JOIN mm_networks n      ON n.network_id = t.network_id
    LEFT JOIN users u       ON u.user_id    = k.captured_by
    ORDER BY k.captured_at DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

// Transactions requiring KYC but missing it
$pendingKyc = $pdo->query("
    SELECT t.mm_txn_id, t.txn_code, t.txn_date, t.txn_type,
           t.customer_phone, t.customer_name, t.principal_amount,
           a.agent_name, n.network_name
    FROM mm_transactions t
    JOIN mm_agents a    ON a.agent_id   = t.agent_id
    JOIN mm_networks n  ON n.network_id = t.network_id
    WHERE t.kyc_required = 1 AND t.kyc_document_id IS NULL AND t.status = 'posted'
    ORDER BY t.txn_date DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$idTypes = ['nida' => 'NIDA', 'voters' => 'Voter ID', 'passport' => 'Passport', 'driving_licence' => 'Driving Licence'];

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View KYC Records', 'Viewed MM KYC Records');
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-shield-check text-success fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('KYC Records') ?></h4>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= (int)$stats['total'] ?></div>
                <div class="small text-muted"><?= t('Total KYC Records') ?></div>
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
                <div class="fs-4 fw-bold text-<?= (int)$stats['pending_kyc'] > 0 ? 'danger' : 'secondary' ?>"><?= (int)$stats['pending_kyc'] ?></div>
                <div class="small text-muted"><?= t('Pending KYC') ?></div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#kycRecordsTab"><?= t('KYC Records') ?> <span class="badge bg-secondary ms-1"><?= count($kycRecords) ?></span></a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#pendingTab"><?= t('Pending KYC') ?> <?= count($pendingKyc) > 0 ? '<span class="badge bg-danger ms-1">'.count($pendingKyc).'</span>' : '' ?></a></li>
    </ul>

    <div class="tab-content">
        <!-- KYC Records tab -->
        <div class="tab-pane fade show active" id="kycRecordsTab">
            <div class="table-responsive">
                <table id="kycTable" class="table table-hover align-middle w-100">
                    <thead class="table-dark">
                        <tr>
                            <th><?= t('Transaction') ?></th>
                            <th><?= t('Date') ?></th>
                            <th><?= t('Customer') ?></th>
                            <th><?= t('Phone') ?></th>
                            <th><?= t('ID Type') ?></th>
                            <th><?= t('ID Number') ?></th>
                            <th><?= t('Network') ?></th>
                            <th><?= t('Agent') ?></th>
                            <th class="text-end"><?= t('Amount') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kycRecords as $r): ?>
                        <tr>
                            <td><code><?= safe_output($r['txn_code']) ?></code></td>
                            <td><?= safe_output($r['txn_date']) ?></td>
                            <td><?= safe_output($r['customer_name'] ?? '—') ?></td>
                            <td><?= safe_output($r['customer_phone']) ?></td>
                            <td><span class="badge bg-info"><?= safe_output($idTypes[$r['id_type']] ?? $r['id_type']) ?></span></td>
                            <td><code><?= safe_output($r['id_number']) ?></code></td>
                            <td><span class="badge rounded-pill" style="background:<?= safe_output($r['color_hex'] ?: '#6c757d') ?>"><?= safe_output($r['network_name']) ?></span></td>
                            <td><?= safe_output($r['agent_name']) ?></td>
                            <td class="text-end"><?= number_format((float)$r['principal_amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pending KYC tab -->
        <div class="tab-pane fade" id="pendingTab">
            <?php if (empty($pendingKyc)): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= t('All KYC-required transactions have been documented.') ?></div>
            <?php else: ?>
            <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= t('These transactions require KYC documentation but have not been captured yet.') ?></div>
            <div class="table-responsive">
                <table id="pendingTable" class="table table-hover align-middle w-100">
                    <thead class="table-dark">
                        <tr>
                            <th><?= t('Transaction') ?></th>
                            <th><?= t('Date') ?></th>
                            <th><?= t('Type') ?></th>
                            <th><?= t('Customer Phone') ?></th>
                            <th><?= t('Customer Name') ?></th>
                            <th class="text-end"><?= t('Amount') ?></th>
                            <th><?= t('Agent') ?></th>
                            <th><?= t('Network') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingKyc as $r): ?>
                        <tr>
                            <td><code><?= safe_output($r['txn_code']) ?></code></td>
                            <td><?= safe_output($r['txn_date']) ?></td>
                            <td><?= safe_output(ucwords(str_replace('_',' ',$r['txn_type']))) ?></td>
                            <td><?= safe_output($r['customer_phone'] ?? '—') ?></td>
                            <td><?= safe_output($r['customer_name'] ?? '—') ?></td>
                            <td class="text-end"><?= number_format((float)$r['principal_amount']) ?></td>
                            <td><?= safe_output($r['agent_name']) ?></td>
                            <td><?= safe_output($r['network_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#kycTable')) {
        $('#kycTable').DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [[1,'desc']] });
    }
    if (!$.fn.DataTable.isDataTable('#pendingTable') && $('#pendingTable').length) {
        $('#pendingTable').DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [[1,'desc']] });
    }
});
</script>
<?php includeFooter(); ?>
