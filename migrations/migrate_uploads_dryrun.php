<?php
/**
 * Upload Migration — DRY RUN
 * Shows exactly what WOULD happen. Makes zero changes to files or database.
 * Run this first, review the report, then run migrate_uploads.php when ready.
 */
require_once __DIR__ . '/../roots.php';
if (!isAdmin()) { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/upload_migration_config.php';

$root = ROOT_DIR;
$start = microtime(true);

// ── Collect results ─────────────────────────────────────────────────────────
$to_move      = [];  // files that will be copied to a new location
$db_updates   = [];  // DB records that will be changed
$no_db_record = [];  // files on disk with no DB record (orphaned)
$already_done = [];  // files already at the new location
$broken_db    = [];  // DB records pointing to files not on disk

// Scan folders on disk
foreach ($FOLDER_MAP as $old_prefix => $new_prefix) {
    $old_abs = $root . '/' . rtrim($old_prefix, '/');
    $new_abs = $root . '/' . rtrim($new_prefix, '/');

    if (!is_dir($old_abs)) continue;

    foreach (um_list_files($old_abs) as $old_file_abs) {
        $rel_within  = substr($old_file_abs, strlen($old_abs) + 1); // path inside old folder
        $old_rel     = $old_prefix . str_replace('\\', '/', $rel_within);
        $new_rel     = $new_prefix . str_replace('\\', '/', $rel_within);
        $new_file_abs = $root . '/' . $new_rel;

        // Already at destination?
        if (realpath($old_file_abs) === realpath($new_file_abs)) {
            $already_done[] = $old_rel;
            continue;
        }

        // Find DB references
        $db_refs = um_find_in_db($pdo, $old_rel, $DB_TABLES);

        $entry = [
            'old'     => $old_rel,
            'new'     => $new_rel,
            'size'    => filesize($old_file_abs),
            'db_refs' => $db_refs,
            'exists'  => file_exists($new_file_abs),
        ];

        if (empty($db_refs)) {
            $no_db_record[] = $entry;
        } else {
            $to_move[] = $entry;
            foreach ($db_refs as $ref) {
                $db_updates[] = [
                    'table' => $ref['table'],
                    'col'   => $ref['col'],
                    'pk'    => $ref['pk'],
                    'old'   => $old_rel,
                    'new'   => $new_rel,
                ];
            }
        }
    }
}

// Scan DB for records pointing to missing files
foreach ($DB_TABLES as $t) {
    try {
        if ($t['json']) {
            $rows = $pdo->query("SELECT {$t['pk']}, {$t['col']} FROM {$t['table']} WHERE {$t['col']} IS NOT NULL AND {$t['col']} != ''")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $data = json_decode($row[$t['col']], true);
                if (!$data) continue;
                array_walk_recursive($data, function($val) use ($root, $t, $row, &$broken_db) {
                    if (!empty($val) && strpos($val, 'uploads/') === 0 && !file_exists($root . '/' . $val)) {
                        $broken_db[] = ['table' => $t['table'], 'col' => $t['col'], 'pk' => $row[$t['pk']], 'path' => $val];
                    }
                });
            }
        } else {
            $where = isset($t['where']) ? "WHERE {$t['where']}" : "WHERE {$t['col']} IS NOT NULL AND {$t['col']} != ''";
            $rows = $pdo->query("SELECT {$t['pk']}, {$t['col']} FROM {$t['table']} $where")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $path = $row[$t['col']];
                if (empty($path)) continue;
                if (!file_exists($root . '/' . $path)) {
                    $broken_db[] = ['table' => $t['table'], 'col' => $t['col'], 'pk' => $row[$t['pk']], 'path' => $path];
                }
            }
        }
    } catch (PDOException $e) {}
}

$elapsed = round(microtime(true) - $start, 2);
$total_size = array_sum(array_column($to_move, 'size')) + array_sum(array_column($no_db_record, 'size'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload Migration — Dry Run</title>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-0"><i class="bi bi-eye text-primary me-2"></i>Upload Migration — Dry Run</h3>
            <p class="text-muted mb-0 small">No files moved. No database changes. Preview only. (scanned in <?= $elapsed ?>s)</p>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="migrate_uploads.php" class="btn btn-success px-4">
                <i class="bi bi-play-circle me-1"></i> Run Migration
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left: 4px solid #0d6efd !important;">
                <h2 class="fw-bold text-primary mb-0"><?= count($to_move) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Files to Move</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left: 4px solid #198754 !important;">
                <h2 class="fw-bold text-success mb-0"><?= count($db_updates) ?></h2>
                <small class="text-muted fw-bold text-uppercase">DB Records to Update</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left: 4px solid #ffc107 !important;">
                <h2 class="fw-bold text-warning mb-0"><?= count($no_db_record) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Files with No DB Record</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left: 4px solid #dc3545 !important;">
                <h2 class="fw-bold text-danger mb-0"><?= count($broken_db) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Already Broken DB Refs</small>
            </div>
        </div>
    </div>

    <?php if (!empty($to_move)): ?>
    <!-- Files to Move -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white fw-bold">
            <i class="bi bi-arrow-right-circle me-2"></i>Files That Will Be Moved (<?= count($to_move) ?>)
            <small class="ms-2 fw-normal opacity-75">Total: <?= round($total_size / 1024, 1) ?> KB</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Old Path</th>
                            <th>New Path</th>
                            <th class="text-end">Size</th>
                            <th>DB Tables</th>
                            <th>Conflict?</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($to_move as $i => $f): ?>
                        <tr>
                            <td class="ps-3 text-muted"><?= $i + 1 ?></td>
                            <td><code class="text-danger small"><?= htmlspecialchars($f['old']) ?></code></td>
                            <td><code class="text-success small"><?= htmlspecialchars($f['new']) ?></code></td>
                            <td class="text-end text-muted"><?= round($f['size'] / 1024, 1) ?> KB</td>
                            <td>
                                <?php foreach ($f['db_refs'] as $ref): ?>
                                    <span class="badge bg-info text-dark"><?= htmlspecialchars($ref['table']) ?>.<?= htmlspecialchars($ref['col']) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($f['exists']): ?>
                                    <span class="badge bg-warning text-dark">File exists at dest</span>
                                <?php else: ?>
                                    <span class="text-success">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($no_db_record)): ?>
    <!-- Files with no DB record -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-warning text-dark fw-bold">
            <i class="bi bi-exclamation-triangle me-2"></i>Files on Disk with No DB Record (<?= count($no_db_record) ?>)
            <small class="ms-2 fw-normal">These will still be copied to the new location for safety.</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr><th class="ps-3">#</th><th>Old Path</th><th>New Path</th><th class="text-end">Size</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($no_db_record as $i => $f): ?>
                        <tr>
                            <td class="ps-3 text-muted"><?= $i + 1 ?></td>
                            <td><code class="text-warning small"><?= htmlspecialchars($f['old']) ?></code></td>
                            <td><code class="text-muted small"><?= htmlspecialchars($f['new']) ?></code></td>
                            <td class="text-end text-muted"><?= round($f['size'] / 1024, 1) ?> KB</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($broken_db)): ?>
    <!-- Already broken DB records -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-x-circle me-2"></i>DB Records Already Pointing to Missing Files (<?= count($broken_db) ?>)
            <small class="ms-2 fw-normal">These are broken before migration starts. Migration will not fix them.</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr><th class="ps-3">Table</th><th>Column</th><th>PK Value</th><th>Missing Path</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($broken_db as $b): ?>
                        <tr>
                            <td class="ps-3"><code><?= htmlspecialchars($b['table']) ?></code></td>
                            <td><code><?= htmlspecialchars($b['col']) ?></code></td>
                            <td><?= htmlspecialchars($b['pk']) ?></td>
                            <td><code class="text-danger"><?= htmlspecialchars($b['path']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($already_done)): ?>
    <div class="alert alert-success border-0 shadow-sm">
        <i class="bi bi-check-circle me-2"></i>
        <strong><?= count($already_done) ?> file(s)</strong> are already at the correct destination — will be skipped.
    </div>
    <?php endif; ?>

    <div class="alert alert-info border-0 shadow-sm">
        <i class="bi bi-info-circle me-2"></i>
        This was a <strong>dry run</strong>. Nothing was changed.
        When ready, click <strong>Run Migration</strong> above.
    </div>

</div>
</body>
</html>
