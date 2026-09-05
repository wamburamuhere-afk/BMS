<?php
/**
 * app/superadmin/activity.php — the full platform activity log.
 *
 * The dashboard's own "Recent Platform Activity" panel deliberately caps at
 * 12 rows (tenantAdminLog(null, 12)) so the dashboard itself never grows —
 * this page is where "View All" goes. Reached FROM the dashboard, so it has
 * no nav slot of its own (same convention as tenant_new.php/tenant_view.php)
 * — it shows a contextual title + back-link instead.
 *
 * Control database ONLY, like every other page in this panel.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me  = currentSuperadmin();
$log = [];
$error = null;
try {
    // 500 is tenantAdminLog()'s own cap (max(1, min(500, $limit))) — matches
    // the "reviewable audit trail", not "everything ever", scope this page
    // is for. DataTable gives search/sort/pagination over that window.
    $log = tenantAdminLog(null, 500);
} catch (Throwable $e) {
    error_log('superadmin activity: ' . $e->getMessage());
    $error = 'The activity log could not be read.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Activity Log | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .detail-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .detail-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
</style>
</head>
<body>

<?php renderSuperadminHeader('dashboard', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= saUrl('dashboard') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-left"></i></a>
        <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-1"></i> Activity Log <span class="text-muted small fw-normal">— all platform operators</span></h5>
    </div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($error, '') ?></div>
<?php elseif (!$log): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-activity fs-1 d-block mb-2"></i>
        No activity recorded yet.
    </div>
<?php else: ?>

    <div class="card detail-card">
        <div class="card-header"><i class="bi bi-list-ul text-primary me-1"></i> Last <?= count($log) ?> platform actions</div>
        <div class="card-body">
            <div class="d-flex justify-content-end mb-2">
                <input type="search" id="activityTblSearch" class="form-control form-control-sm w-auto" placeholder="Search…">
            </div>

            <div class="table-responsive">
                <table id="activityTable" class="table table-sm align-middle" style="width:100%">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Tenant</th>
                            <th>Detail</th>
                            <th>Actor</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($log as $l): [$icon, $color, $label] = saActionMeta((string)$l['action']); ?>
                        <tr>
                            <td><i class="bi <?= $icon ?> text-<?= $color ?> me-1"></i><?= safe_output($label, '') ?></td>
                            <td><?= safe_output($l['subdomain'] ?? '', '—') ?></td>
                            <td class="text-muted" style="font-size:.85rem;"><?= safe_output($l['detail'] ?? '', '') ?></td>
                            <td><?= safe_output($l['actor_email'] ?? '', 'system') ?></td>
                            <td data-order="<?= safe_output($l['created_at'], '') ?>"><?= saTimeAgo((string)$l['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
if (document.getElementById('activityTable')) {
    const activityTable = $('#activityTable').DataTable({
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [[4, 'desc']],
        dom: 'rtip',
        language: { emptyTable: 'No activity found.', zeroRecords: 'No matching activity.' }
    });
    $('#activityTblSearch').on('keyup', function () { activityTable.search(this.value).draw(); });
}
</script>
</body>
</html>
