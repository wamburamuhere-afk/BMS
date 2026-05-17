<?php
/**
 * Upload Migration — LIVE RUN
 * Copies files to new locations and updates DB records.
 * NEVER deletes original files — safe to run on a live system.
 * Run migrate_uploads_dryrun.php first to preview what will happen.
 */
require_once __DIR__ . '/../roots.php';
if (!isAdmin()) { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/upload_migration_config.php';

$root  = ROOT_DIR;
$start = microtime(true);

// ── Log storage ─────────────────────────────────────────────────────────────
$log_success = [];
$log_skip    = [];
$log_error   = [];
$db_updated  = 0;

// ── Run migration ────────────────────────────────────────────────────────────
foreach ($FOLDER_MAP as $old_prefix => $new_prefix) {
    $old_abs = $root . '/' . rtrim($old_prefix, '/');
    $new_abs = $root . '/' . rtrim($new_prefix, '/');

    if (!is_dir($old_abs)) {
        $log_skip[] = "Folder not found on disk — skipped: $old_prefix";
        continue;
    }

    foreach (um_list_files($old_abs) as $old_file_abs) {
        $rel_within  = substr($old_file_abs, strlen($old_abs) + 1);
        $old_rel     = $old_prefix . str_replace('\\', '/', $rel_within);
        $new_rel     = $new_prefix . str_replace('\\', '/', $rel_within);

        $new_file_abs = $root . '/' . $new_rel;

        // Already at destination?
        if (realpath($old_file_abs) === realpath($new_file_abs)) {
            $log_skip[] = "Already at destination: $old_rel";
            continue;
        }

        // Handle filename conflict at destination
        if (file_exists($new_file_abs)) {
            $safe    = preg_replace('/[^a-z0-9]/', '_', trim($old_prefix, '/'));
            $new_rel = $new_prefix . $safe . '__' . str_replace('\\', '/', $rel_within);
            $new_file_abs = $root . '/' . $new_rel;
        }

        // ── Step 1: Create destination directory ──────────────────────────
        $dest_dir = dirname($new_file_abs);
        if (!is_dir($dest_dir)) {
            if (!mkdir($dest_dir, 0755, true)) {
                $log_error[] = "Failed to create directory: $dest_dir  [file: $old_rel]";
                continue;
            }
        }

        // ── Step 2: Copy file ─────────────────────────────────────────────
        if (!copy($old_file_abs, $new_file_abs)) {
            $log_error[] = "Copy failed: $old_rel → $new_rel";
            continue;
        }

        // ── Step 3: Verify copy ───────────────────────────────────────────
        if (!file_exists($new_file_abs) || filesize($new_file_abs) !== filesize($old_file_abs)) {
            @unlink($new_file_abs); // remove bad copy
            $log_error[] = "Verification failed (size mismatch or missing): $new_rel";
            continue;
        }

        // ── Step 4: Update DB records ─────────────────────────────────────
        $rows_updated = um_update_db($pdo, $old_rel, $new_rel, $DB_TABLES);
        $db_updated  += $rows_updated;

        // ── Step 5: Log success (original NOT deleted) ────────────────────
        $log_success[] = [
            'old'     => $old_rel,
            'new'     => $new_rel,
            'size'    => filesize($new_file_abs),
            'db_rows' => $rows_updated,
        ];
    }
}

$elapsed = round(microtime(true) - $start, 2);
$total_moved = count($log_success);
$total_size  = array_sum(array_column($log_success, 'size'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload Migration — Results</title>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-0">
                <i class="bi bi-check2-circle text-success me-2"></i>Upload Migration — Complete
            </h3>
            <p class="text-muted mb-0 small">Completed in <?= $elapsed ?>s — original files were NOT deleted</p>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="verify_uploads.php" class="btn btn-primary px-4">
                <i class="bi bi-shield-check me-1"></i> Verify Results
            </a>
            <a href="cleanup_old_uploads.php" class="btn btn-outline-danger px-4">
                <i class="bi bi-trash me-1"></i> Cleanup Old Files
            </a>
        </div>
    </div>

    <!-- Summary -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #198754 !important;">
                <h2 class="fw-bold text-success mb-0"><?= $total_moved ?></h2>
                <small class="text-muted fw-bold text-uppercase">Files Copied</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #0d6efd !important;">
                <h2 class="fw-bold text-primary mb-0"><?= $db_updated ?></h2>
                <small class="text-muted fw-bold text-uppercase">DB Records Updated</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #6c757d !important;">
                <h2 class="fw-bold text-secondary mb-0"><?= count($log_skip) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Skipped</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #dc3545 !important;">
                <h2 class="fw-bold text-danger mb-0"><?= count($log_error) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Errors</small>
            </div>
        </div>
    </div>

    <?php if (!empty($log_error)): ?>
    <div class="card border-danger border-0 shadow-sm mb-4">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-x-circle me-2"></i>Errors (<?= count($log_error) ?>) — These files were NOT moved
        </div>
        <div class="card-body p-0">
            <?php foreach ($log_error as $err): ?>
                <div class="px-3 py-2 border-bottom small font-monospace text-danger"><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($log_success)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-success text-white fw-bold">
            <i class="bi bi-check-circle me-2"></i>Successfully Migrated (<?= $total_moved ?> files — <?= round($total_size/1024/1024, 2) ?> MB)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Old Path (original — not deleted)</th>
                            <th>New Path (active)</th>
                            <th class="text-end">Size</th>
                            <th class="text-center">DB Rows</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($log_success as $i => $f): ?>
                        <tr>
                            <td class="ps-3 text-muted"><?= $i + 1 ?></td>
                            <td><code class="text-muted small"><?= htmlspecialchars($f['old']) ?></code></td>
                            <td><code class="text-success small"><?= htmlspecialchars($f['new']) ?></code></td>
                            <td class="text-end text-muted"><?= round($f['size']/1024, 1) ?> KB</td>
                            <td class="text-center">
                                <span class="badge bg-<?= $f['db_rows'] > 0 ? 'primary' : 'secondary' ?>">
                                    <?= $f['db_rows'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($log_skip)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-secondary text-white fw-bold">
            <i class="bi bi-skip-forward me-2"></i>Skipped (<?= count($log_skip) ?>)
        </div>
        <div class="card-body p-0">
            <?php foreach ($log_skip as $msg): ?>
                <div class="px-3 py-1 border-bottom small text-muted"><?= htmlspecialchars($msg) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="alert alert-warning border-0 shadow-sm">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Original files have NOT been deleted.</strong>
        Run <a href="verify_uploads.php" class="alert-link">verify_uploads.php</a> first to confirm everything is working,
        then use <a href="cleanup_old_uploads.php" class="alert-link">cleanup_old_uploads.php</a> when you are ready to remove old files.
    </div>

</div>
</body>
</html>
