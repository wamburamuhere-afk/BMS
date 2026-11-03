<?php
/**
 * app/superadmin/backups.php — is every company's data backed up?
 *
 * One row per database the scheduled backup covers (main install, every
 * company, control DB): last good full backup, last snapshot, state, last
 * error — plus the off-site (Google Drive) copy and recent failures.
 * Read-only. Data: core/backup_health.php (tenant_backup_log + the off-site
 * status file). Plan: backup_plan.md PR 3.
 *
 * Control database ONLY, like every other page in this panel.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/backup_health.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me = currentSuperadmin();
$health = null;
$failures = [];
$error = null;
try {
    $health = bmsBackupHealth();
    $failures = getControlPdo()->query("
        SELECT target_key, subdomain, kind, message, created_at, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min
          FROM tenant_backup_log
         WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 30 DAY
         ORDER BY id DESC LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('superadmin backups: ' . $e->getMessage());
    if ($health === null) $error = 'Backup status could not be read.';
}

/** "3 h ago" from minutes. */
function bk_ago(?int $min): string
{
    if ($min === null) return '—';
    if ($min < 1)    return 'just now';
    if ($min < 60)   return $min . ' min ago';
    if ($min < 2880) return (int)floor($min / 60) . ' h ago';
    return (int)floor($min / 1440) . ' days ago';
}
function bk_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
}
/** Status badge — blue scale for good, red for problems (ui-constants §UI-1). */
function bk_badge(string $state): string
{
    [$bg, $fg, $label] = [
        'ok'             => ['#0d6efd', '#fff', 'Healthy'],
        'pending'        => ['#e9ecef', '#495057', 'Pending (new)'],
        'failed'         => ['#dc3545', '#fff', 'Failed'],
        'overdue'        => ['#dc3545', '#fff', 'Overdue'],
        'never'          => ['#dc3545', '#fff', 'Never backed up'],
        'not_configured' => ['#6c757d', '#fff', 'Not configured'],
    ][$state] ?? ['#6c757d', '#fff', ucfirst($state)];
    return '<span class="badge" style="background:' . $bg . ';color:' . $fg . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}
$off = $health['offsite'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Backups | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .detail-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .detail-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    .stat-card { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; padding: .9rem 1rem; height: 100%; }
    .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; color: #0a3d8f; line-height: 1.2; }
    .stat-card .stat-label { font-size: .8rem; color: #495057; }
    .stat-card.bad { background: #fdecee; border-color: #f1aeb5; }
    .stat-card.bad .stat-value { color: #b02a37; }
</style>
</head>
<body>

<?php renderSuperadminHeader('backups', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h5 class="mb-0 fw-bold"><i class="bi bi-hdd-stack text-primary me-1"></i> Backups</h5>
        <span class="text-muted small">Full backup 00:30 · off-site copy 01:30 · database snapshots 06:30, 12:30, 18:30 (Dar es Salaam time)</span>
    </div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($error, '') ?></div>
<?php elseif (!$health['ready']): ?>
    <div class="alert alert-secondary">The control database is not set up on this host — nothing to show.</div>
<?php else: ?>

    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-value"><?= (int)$health['counts']['ok'] ?> / <?= (int)$health['counts']['total'] ?></div>
                <div class="stat-label"><i class="bi bi-check-circle me-1"></i>Databases backed up in the last 26 h</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card<?= $health['counts']['problem'] ? ' bad' : '' ?>">
                <div class="stat-value"><?= (int)$health['counts']['problem'] ?></div>
                <div class="stat-label"><i class="bi bi-exclamation-triangle me-1"></i>Need attention</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-value"><?= (int)$health['counts']['pending'] ?></div>
                <div class="stat-label"><i class="bi bi-hourglass-split me-1"></i>New companies waiting for their first night</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card<?= $off['state'] !== 'ok' ? ' bad' : '' ?>">
                <div class="stat-value" style="font-size:1.1rem;"><?= bk_badge($off['state'] === 'never' ? 'not_configured' : $off['state']) ?></div>
                <div class="stat-label mt-1"><i class="bi bi-cloud-arrow-up me-1"></i>Off-site copy (Google Drive)<?= $off['age_min'] !== null ? ' · ' . bk_ago($off['age_min']) : '' ?></div>
            </div>
        </div>
    </div>

    <?php if ($health['problems']): ?>
    <div class="alert alert-danger">
        <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i><?= count($health['problems']) ?> problem<?= count($health['problems']) === 1 ? '' : 's' ?></div>
        <ul class="mb-0 small">
            <?php foreach ($health['problems'] as $p): ?><li><?= safe_output($p, '') ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="card detail-card mb-3">
        <div class="card-header"><i class="bi bi-database text-primary me-1"></i> Every database</div>
        <div class="card-body">
            <div class="d-flex justify-content-end mb-2">
                <input type="search" id="backupTblSearch" class="form-control form-control-sm w-auto" placeholder="Search…">
            </div>
            <div class="table-responsive">
                <table id="backupTable" class="table table-sm align-middle" style="width:100%">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Status</th>
                            <th>Last full backup</th>
                            <th>Size</th>
                            <th>Last snapshot</th>
                            <th>Last error</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $rank = ['failed' => 0, 'never' => 1, 'overdue' => 2, 'pending' => 3, 'ok' => 4];
                    foreach ($health['targets'] as $t):
                        $full = $t['full_ok']; $snap = $t['snapshot_ok'];
                        $err  = $t['full_failed'];
                        if ($t['snapshot_failed'] && (!$err || (int)$t['snapshot_failed']['id'] > (int)$err['id'])) $err = $t['snapshot_failed'];
                        $errShown = $err && $t['state'] === 'failed';
                    ?>
                        <tr>
                            <td>
                                <?php if ($t['tenant_id']): ?>
                                    <a href="<?= htmlspecialchars(saUrl('tenants/view?id=' . (int)$t['tenant_id']), ENT_QUOTES) ?>" class="text-decoration-none fw-semibold"><?= safe_output($t['label'], '') ?></a>
                                    <div class="text-muted small"><?= safe_output($t['subdomain'], '') ?></div>
                                <?php else: ?>
                                    <span class="fw-semibold"><?= safe_output($t['label'], '') ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-order="<?= $rank[$t['state']] ?? 9 ?>"><?= bk_badge($t['state']) ?></td>
                            <td data-order="<?= $full ? -(int)$full['age_min'] : -99999999 ?>">
                                <?= $full ? bk_ago((int)$full['age_min']) . '<div class="text-muted small">' . safe_output($full['file_name'], '') . '</div>' : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td data-order="<?= $full ? (int)$full['bytes'] : 0 ?>"><?= $full ? bk_size((int)$full['bytes']) . ' <span class="text-muted small">· ' . (int)$full['files_count'] . ' files</span>' : '—' ?></td>
                            <td data-order="<?= $snap ? -(int)$snap['age_min'] : -99999999 ?>"><?= $snap ? bk_ago((int)$snap['age_min']) : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-danger small" style="max-width:360px;"><?= $errShown ? safe_output(mb_substr((string)$err['message'], 0, 300), '') : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card detail-card h-100">
                <div class="card-header"><i class="bi bi-cloud-arrow-up text-primary me-1"></i> Off-site copy (Google Drive, encrypted)</div>
                <div class="card-body small">
                    <?php $d = $off['data']; ?>
                    <div class="mb-2"><?= bk_badge($off['state'] === 'never' ? 'not_configured' : $off['state']) ?></div>
                    <?php if ($d): ?>
                        <table class="table table-sm mb-2">
                            <tr><th class="text-muted fw-normal">Last run</th><td><?= bk_ago($off['age_min']) ?> <span class="text-muted">(<?= safe_output($d['finished_at'] ?? '', '') ?>)</span></td></tr>
                            <tr><th class="text-muted fw-normal">Uploaded / removed</th><td><?= (int)($d['uploaded'] ?? 0) ?> / <?= (int)($d['deleted'] ?? 0) ?></td></tr>
                            <tr><th class="text-muted fw-normal">On Google Drive now</th><td><?= (int)($d['remote_files'] ?? 0) ?> files · <?= bk_size((int)($d['remote_bytes'] ?? 0)) ?></td></tr>
                            <tr><th class="text-muted fw-normal">Destination</th><td><?= safe_output(($d['remote'] ?? '') . ':' . ($d['host'] ?? ''), '') ?></td></tr>
                        </table>
                    <?php endif; ?>
                    <?php if ($off['problem']): ?><div class="text-danger mb-2"><?= safe_output($off['problem'], '') ?></div><?php endif; ?>
                    <?php if (in_array($off['state'], ['never', 'not_configured'], true)): ?>
                        <div class="text-muted">One-time setup on the server: install rclone, connect Google Drive, add the encrypted remote — steps in <code>backup_plan.md</code> §4.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card detail-card h-100">
                <div class="card-header"><i class="bi bi-x-octagon text-primary me-1"></i> Failures in the last 30 days</div>
                <div class="card-body">
                    <?php if (!$failures): ?>
                        <div class="text-center text-muted py-4 small"><i class="bi bi-check2-circle d-block fs-3 mb-1"></i>No failed backups in the last 30 days.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table id="failTable" class="table table-sm align-middle" style="width:100%">
                            <thead><tr><th>When</th><th>Database</th><th>Kind</th><th>Error</th></tr></thead>
                            <tbody>
                            <?php foreach ($failures as $f): ?>
                                <tr>
                                    <td data-order="<?= -(int)$f['age_min'] ?>"><?= bk_ago((int)$f['age_min']) ?></td>
                                    <td><?= safe_output($f['subdomain'] ?: $f['target_key'], '') ?></td>
                                    <td><?= safe_output($f['kind'], '') ?></td>
                                    <td class="small text-danger"><?= safe_output(mb_substr((string)$f['message'], 0, 300), '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
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
if (document.getElementById('backupTable')) {
    const backupTable = $('#backupTable').DataTable({
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [[1, 'asc']],
        dom: 'rtip',
        language: { emptyTable: 'No databases found.', zeroRecords: 'No matching databases.' }
    });
    $('#backupTblSearch').on('keyup', function () { backupTable.search(this.value).draw(); });
}
if (document.getElementById('failTable')) {
    $('#failTable').DataTable({
        responsive: false, scrollX: true, pageLength: 10, order: [[0, 'desc']], dom: 'rtip',
        language: { emptyTable: 'No failures.', zeroRecords: 'No matching failures.' }
    });
}
</script>
</body>
</html>
