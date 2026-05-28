<?php
/**
 * 2026_05_28_move_document_templates_to_uploads
 *
 * Relocates document-template uploads from the tracked `docs/templates/`
 * directory to the gitignored `uploads/document_templates/` directory.
 *
 * Background — `api/save_document_template.php` previously wrote uploaded
 * template files into `docs/templates/` (a path tracked by git). On every
 * production deploy that uploaded file then sat as an untracked working-tree
 * file, eventually colliding with a same-named file pulled from main and
 * causing `git pull` to abort. The 2026-05-28 incident traced 3 of 5 hosts
 * stuck behind PR #424 to exactly this scenario (see changelog updates 194
 * and 195). The upload endpoint was redirected to `uploads/document_templates/`
 * in the same PR as this migration; this script handles the data side.
 *
 * For each row in `document_templates` whose `file_path` starts with
 * `docs/templates/`:
 *   1. Compute the new path: `uploads/document_templates/<basename>`.
 *   2. If the physical file exists at the old absolute path AND not yet at
 *      the new one, rename() it into place.
 *   3. Update the DB row's `file_path` to the new value.
 *
 * Idempotent: re-running after a partial / completed run does nothing.
 * - Rows already pointing at `uploads/document_templates/` are skipped.
 * - rename() is only attempted when the source file actually exists and the
 *   destination does not — so a half-finished run resumes cleanly.
 *
 * Safe-skip conditions (no failure, just log + exit 0):
 *   - `document_templates` table doesn't exist on this server.
 *   - No rows match the old-path prefix.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: move document_templates uploads to uploads/document_templates/...\n";

try {
    // ── Guard: table must exist on this server ─────────────────────────
    $tbl = $pdo->query("SHOW TABLES LIKE 'document_templates'")->fetch();
    if (!$tbl) {
        echo "  Table 'document_templates' not found on this server — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // ── Ensure destination directory exists ────────────────────────────
    $newDirAbs = ROOT_DIR . '/uploads/document_templates/';
    if (!is_dir($newDirAbs)) {
        if (!mkdir($newDirAbs, 0777, true) && !is_dir($newDirAbs)) {
            echo "  Failed to create destination directory: $newDirAbs\n";
            exit(1);
        }
        echo "  ✓ Created destination directory: uploads/document_templates/\n";
    } else {
        echo "  ✓ Destination directory already exists.\n";
    }

    // ── Find rows still pointing at the old path ───────────────────────
    $stmt = $pdo->query(
        "SELECT id, file_path FROM document_templates
         WHERE file_path LIKE 'docs/templates/%'"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "  No rows reference docs/templates/ — nothing to migrate.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    echo "  Found " . count($rows) . " row(s) to migrate.\n";

    $movedFiles  = 0;
    $missingFiles = 0;
    $alreadyAt   = 0;
    $rowsUpdated = 0;

    $updateStmt = $pdo->prepare(
        "UPDATE document_templates SET file_path = ? WHERE id = ?"
    );

    foreach ($rows as $row) {
        $oldPath = $row['file_path'];                                  // e.g. docs/templates/1778941921_foo.png
        $basename = basename($oldPath);
        $newPath = 'uploads/document_templates/' . $basename;           // new DB value

        $oldAbs = ROOT_DIR . '/' . $oldPath;
        $newAbs = ROOT_DIR . '/' . $newPath;

        if (file_exists($newAbs)) {
            // Already moved on a previous run (or a manual copy beat us to it).
            $alreadyAt++;
        } elseif (file_exists($oldAbs)) {
            if (rename($oldAbs, $newAbs)) {
                $movedFiles++;
            } else {
                echo "  ✗ Failed to move: $oldPath → $newPath\n";
                exit(1);
            }
        } else {
            // DB row references a file that doesn't physically exist on this
            // host. This happens on fresh tenants seeded from one tenant's
            // dump. We still update the DB row so future uploads land in
            // the right place and the path prefix is consistent.
            $missingFiles++;
        }

        $updateStmt->execute([$newPath, (int)$row['id']]);
        $rowsUpdated++;
    }

    echo "  ✓ Physical files moved: $movedFiles\n";
    echo "  ✓ Already at destination: $alreadyAt\n";
    echo "  ✓ DB rows updated: $rowsUpdated\n";
    if ($missingFiles > 0) {
        echo "  ⚠ DB rows updated but physical file missing on this host: $missingFiles\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
