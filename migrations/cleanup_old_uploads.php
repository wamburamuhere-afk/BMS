<?php
/**
 * Upload Migration — Cleanup Old Files
 * ONLY run this after:
 *   1. migrate_uploads.php has been run
 *   2. verify_uploads.php shows zero missing files
 *   3. You have tested the system for several days with no broken links
 *
 * This script deletes original files that have been migrated to new locations.
 * It will NOT delete files that have no DB record at the new path.
 */
require_once __DIR__ . '/../roots.php';
if (!isAdmin()) { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/upload_migration_config.php';

$root      = ROOT_DIR;
$confirmed = isset($_POST['confirm']) && $_POST['confirm'] === 'DELETE_OLD_FILES';

$to_delete   = [];
$to_keep     = [];
$empty_dirs  = [];

// Find all files in OLD locations that have been successfully migrated
foreach ($FOLDER_MAP as $old_prefix => $new_prefix) {
    $old_abs = $root . '/' . rtrim($old_prefix, '/');
    if (!is_dir($old_abs)) continue;

    foreach (um_list_files($old_abs) as $old_file_abs) {
        $rel_within   = substr($old_file_abs, strlen($old_abs) + 1);
        $old_rel      = $old_prefix . str_replace('\\', '/', $rel_within);
        $new_rel      = $new_prefix . str_replace('\\', '/', $rel_within);
        $new_file_abs = $root . '/' . $new_rel;

        // Only mark for deletion if the new file EXISTS and DB no longer references old path
        $still_in_db = !empty(um_find_in_db($pdo, $old_rel, $DB_TABLES));
        $new_exists  = file_exists($new_file_abs);

        if ($new_exists && !$still_in_db) {
            $to_delete[] = [
                'old_abs' => $old_file_abs,
                'old_rel' => $old_rel,
                'new_rel' => $new_rel,
                'size'    => filesize($old_file_abs),
            ];
        } else {
            $to_keep[] = [
                'old_rel'      => $old_rel,
                'reason'       => !$new_exists ? 'new file missing' : 'still referenced in DB',
                'still_in_db'  => $still_in_db,
                'new_exists'   => $new_exists,
            ];
        }
    }
}

$total_size = array_sum(array_column($to_delete, 'size'));
$deleted    = [];
$del_errors = [];

if ($confirmed && !empty($to_delete)) {
    foreach ($to_delete as $f) {
        if (@unlink($f['old_abs'])) {
            $deleted[] = $f['old_rel'];
        } else {
            $del_errors[] = $f['old_rel'];
        }
    }

    // Remove empty old directories
    foreach (array_keys($FOLDER_MAP) as $old_prefix) {
        $old_abs = $root . '/' . rtrim($old_prefix, '/');
        if (!is_dir($old_abs)) continue;
        $remaining = um_list_files($old_abs);
        if (empty($remaining)) {
            // Remove empty subdirs bottom-up
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($old_abs, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $item) {
                if ($item->isDir()) @rmdir($item->getPathname());
            }
            if (@rmdir($old_abs)) $empty_dirs[] = $old_prefix;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cleanup Old Upload Files</title>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-0">
                <i class="bi bi-trash text-danger me-2"></i>Cleanup Old Upload Files
            </h3>
            <p class="text-muted mb-0 small">
                Only deletes files that have been successfully migrated AND are no longer referenced in the database.
            </p>
        </div>
        <div class="ms-auto">
            <a href="verify_uploads.php" class="btn btn-outline-primary">
                <i class="bi bi-shield-check me-1"></i> Verify First
            </a>
        </div>
    </div>

    <?php if ($confirmed): ?>
    <!-- Results after deletion -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #198754 !important;">
                <h2 class="fw-bold text-success mb-0"><?= count($deleted) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Files Deleted</small>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #0d6efd !important;">
                <h2 class="fw-bold text-primary mb-0"><?= count($empty_dirs) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Old Folders Removed</small>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #dc3545 !important;">
                <h2 class="fw-bold text-danger mb-0"><?= count($del_errors) ?></h2>
                <small class="text-muted fw-bold text-uppercase">Delete Errors</small>
            </div>
        </div>
    </div>

    <?php if (!empty($del_errors)): ?>
    <div class="alert alert-danger border-0 shadow-sm">
        <strong>Failed to delete:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($del_errors as $e): ?>
                <li><code><?= htmlspecialchars($e) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($deleted)): ?>
    <div class="alert alert-success border-0 shadow-sm">
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>Cleanup complete.</strong> <?= count($deleted) ?> old files deleted.
        <?php if (!empty($empty_dirs)): ?>
            <?= count($empty_dirs) ?> old empty folders removed:
            <?= implode(', ', array_map('htmlspecialchars', $empty_dirs)) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($to_keep)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-warning text-dark fw-bold">
            <i class="bi bi-shield-lock me-2"></i>Files Kept (<?= count($to_keep) ?>) — Protected from deletion
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr><th class="ps-3">Old Path</th><th>Reason Not Deleted</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($to_keep as $f): ?>
                        <tr>
                            <td class="ps-3"><code><?= htmlspecialchars($f['old_rel']) ?></code></td>
                            <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($f['reason']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Preview before confirmation -->

    <?php if (empty($to_delete)): ?>
    <div class="alert alert-success border-0 shadow-sm fs-5">
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>Nothing to clean up.</strong>
        Either migration has not been run yet, or all old files are still referenced in the database.
    </div>
    <?php else: ?>

    <div class="alert alert-warning border-0 shadow-sm">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>Warning:</strong> This action is <strong>permanent and irreversible</strong>.
        Only proceed if <code>verify_uploads.php</code> shows zero missing files and you have tested the system thoroughly.
        <strong>Consider taking a full backup before proceeding.</strong>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-trash me-2"></i>Files That Will Be Deleted (<?= count($to_delete) ?> files — <?= round($total_size/1024/1024, 2) ?> MB freed)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Old Path (will be deleted)</th>
                            <th>New Path (safe copy exists)</th>
                            <th class="text-end">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($to_delete as $i => $f): ?>
                        <tr>
                            <td class="ps-3 text-muted"><?= $i + 1 ?></td>
                            <td><code class="text-danger small"><?= htmlspecialchars($f['old_rel']) ?></code></td>
                            <td><code class="text-success small"><?= htmlspecialchars($f['new_rel']) ?></code></td>
                            <td class="text-end text-muted"><?= round($f['size']/1024, 1) ?> KB</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (!empty($to_keep)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-secondary text-white fw-bold">
            <i class="bi bi-shield-lock me-2"></i>Files That Will NOT Be Deleted (<?= count($to_keep) ?>) — Protected
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="bg-light">
                        <tr><th class="ps-3">Old Path</th><th>Reason</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($to_keep as $f): ?>
                        <tr>
                            <td class="ps-3"><code><?= htmlspecialchars($f['old_rel']) ?></code></td>
                            <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($f['reason']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Confirmation form -->
    <div class="card border-danger border-2 shadow mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold text-danger mb-3"><i class="bi bi-exclamation-octagon me-2"></i>Confirm Deletion</h5>
            <p class="mb-3">Type <strong>DELETE_OLD_FILES</strong> in the box below and click the button to proceed.</p>
            <form method="POST">
                <div class="input-group" style="max-width: 500px;">
                    <input type="text" name="confirm" class="form-control form-control-lg fw-bold"
                           placeholder="Type: DELETE_OLD_FILES" autocomplete="off">
                    <button type="submit" class="btn btn-danger btn-lg px-4">
                        <i class="bi bi-trash me-1"></i> Delete Old Files
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; // empty to_delete ?>
    <?php endif; // confirmed ?>

</div>
</body>
</html>
