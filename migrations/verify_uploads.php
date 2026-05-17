<?php
/**
 * Upload Migration — Verification
 * Checks every file path stored in the database actually exists on disk.
 * Run after migrate_uploads.php to confirm everything is healthy.
 * Makes no changes whatsoever.
 */
require_once __DIR__ . '/../roots.php';
if (!isAdmin()) { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/upload_migration_config.php';

$root  = ROOT_DIR;
$start = microtime(true);

$ok      = [];
$missing = [];
$empty   = [];

foreach ($DB_TABLES as $t) {
    try {
        if ($t['json']) {
            $rows = $pdo->query("SELECT {$t['pk']}, {$t['col']} FROM {$t['table']} WHERE {$t['col']} IS NOT NULL AND {$t['col']} != ''")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $data = json_decode($row[$t['col']], true);
                if (!$data) continue;
                array_walk_recursive($data, function($val) use ($root, $t, $row, &$ok, &$missing, &$empty) {
                    if (empty($val)) return;
                    $abs = $root . '/' . $val;
                    $entry = ['table' => $t['table'], 'col' => $t['col'], 'pk' => $row[$t['pk']], 'path' => $val];
                    if (file_exists($abs)) $ok[] = $entry;
                    else                  $missing[] = $entry;
                });
            }
        } else {
            $where = isset($t['where']) ? "WHERE {$t['where']}" : "WHERE {$t['col']} IS NOT NULL AND {$t['col']} != ''";
            $rows  = $pdo->query("SELECT {$t['pk']}, {$t['col']} FROM {$t['table']} $where")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $path  = $row[$t['col']];
                if (empty($path)) { $empty[] = ['table' => $t['table'], 'col' => $t['col'], 'pk' => $row[$t['pk']]]; continue; }
                $abs   = $root . '/' . $path;
                $entry = ['table' => $t['table'], 'col' => $t['col'], 'pk' => $row[$t['pk']], 'path' => $path];
                if (file_exists($abs)) $ok[] = $entry;
                else                  $missing[] = $entry;
            }
        }
    } catch (PDOException $e) {}
}

$elapsed = round(microtime(true) - $start, 2);
$health  = count($missing) === 0 ? 'All Good' : count($missing) . ' Problems Found';
$color   = count($missing) === 0 ? 'success' : 'danger';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload Verification</title>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-0">
                <i class="bi bi-shield-check text-<?= $color ?> me-2"></i>Upload Verification — <?= $health ?>
            </h3>
            <p class="text-muted mb-0 small">Scanned all DB file paths against disk. Completed in <?= $elapsed ?>s</p>
        </div>
        <div class="ms-auto">
            <a href="migrate_uploads_dryrun.php" class="btn btn-outline-primary">
                <i class="bi bi-eye me-1"></i> Dry Run
            </a>
        </div>
    </div>

    <!-- Summary -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #198754 !important;">
                <h2 class="fw-bold text-success mb-0"><?= count($ok) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Files Found on Disk</small>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #dc3545 !important;">
                <h2 class="fw-bold text-danger mb-0"><?= count($missing) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Missing Files</small>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #6c757d !important;">
                <h2 class="fw-bold text-secondary mb-0"><?= count($empty) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Empty Path Records</small>
            </div>
        </div>
    </div>

    <?php if (count($missing) === 0): ?>
    <div class="alert alert-success border-0 shadow-sm fs-5">
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>All <?= count($ok) ?> files are present on disk.</strong>
        The migration is complete and verified. You may now run
        <a href="cleanup_old_uploads.php" class="alert-link">cleanup_old_uploads.php</a> when ready.
    </div>
    <?php else: ?>
    <div class="card border-danger border-0 shadow-sm mb-4">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-x-circle me-2"></i>Missing Files — DB records pointing to files not on disk (<?= count($missing) ?>)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">Table</th>
                            <th>Column</th>
                            <th>Record ID</th>
                            <th>Path in DB</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($missing as $m): ?>
                        <tr>
                            <td class="ps-3"><code><?= htmlspecialchars($m['table']) ?></code></td>
                            <td><code><?= htmlspecialchars($m['col']) ?></code></td>
                            <td><?= htmlspecialchars($m['pk']) ?></td>
                            <td><code class="text-danger"><?= htmlspecialchars($m['path']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($ok)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-success text-white fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-check-circle me-2"></i>Verified Files (<?= count($ok) ?>)</span>
            <button class="btn btn-sm btn-light" onclick="document.getElementById('okTable').classList.toggle('d-none')">
                Toggle List
            </button>
        </div>
        <div id="okTable" class="card-body p-0 d-none">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr><th class="ps-3">Table</th><th>Column</th><th>Record ID</th><th>Path</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ok as $f): ?>
                        <tr>
                            <td class="ps-3"><code><?= htmlspecialchars($f['table']) ?></code></td>
                            <td><code><?= htmlspecialchars($f['col']) ?></code></td>
                            <td><?= htmlspecialchars($f['pk']) ?></td>
                            <td><code class="text-success"><?= htmlspecialchars($f['path']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
