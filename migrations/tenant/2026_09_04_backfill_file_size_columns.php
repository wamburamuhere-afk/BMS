<?php
/**
 * migrations/tenant/2026_09_04_backfill_file_size_columns.php
 *
 * ternant.md Phase 12 finding #6: five tables store an uploaded file but never
 * recorded its size — customer_attachments, document_templates,
 * project_scope_documents, user_signatures, compliance_records. Left alone,
 * every tenant's storage-usage total (core/tenant_quotas.php) would undercount
 * forever, silently, because these five rows would always contribute 0.
 *
 * Adds `file_size INT NOT NULL DEFAULT 0` to each (idempotent — skipped if
 * already present, so a re-run after a partial failure is safe), then
 * best-effort backfills existing rows from the file still on disk.
 *
 * A row whose file is gone (deleted outside the app, moved, or never actually
 * written) is left at 0 rather than failing the migration — a storage total
 * that slightly undercounts a handful of already-orphaned historical rows is
 * an acceptable, bounded gap. A migration that aborts on one bad row, blocking
 * the schema change for every tenant behind it, is not.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

// This file lives at migrations/tenant/, so two levels up is the webroot — the
// physical root every `file_path` value in these tables is relative to. Computed
// locally rather than via ROOT_DIR: this bootstrap deliberately never loads
// roots.php/config.php, so no such constant exists here.
$webRoot = realpath(__DIR__ . '/../..');

echo "Starting tenant migration: backfill file_size on 5 tables...\n";

// table => primary key column. Every one of the five uses `id`, confirmed by
// reading schema/tenant_schema_template.sql directly rather than assuming.
$tables = [
    'customer_attachments'    => 'id',
    'document_templates'      => 'id',
    'project_scope_documents' => 'id',
    'user_signatures'         => 'id',
    'compliance_records'      => 'id',
];

try {
    foreach ($tables as $table => $pk) {
        $has = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'file_size'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `file_size` INT NOT NULL DEFAULT 0");
            echo "  + {$table}.file_size added\n";
        } else {
            echo "  · {$table}.file_size already present\n";
        }

        $rows = $pdo->query("
            SELECT `{$pk}` AS pk, file_path FROM `{$table}`
            WHERE file_path IS NOT NULL AND file_path != '' AND file_size = 0
        ")->fetchAll();

        $filled = 0;
        $missing = 0;
        $upd = $pdo->prepare("UPDATE `{$table}` SET file_size = ? WHERE `{$pk}` = ?");
        foreach ($rows as $row) {
            $abs = $webRoot . '/' . ltrim(str_replace('\\', '/', (string)$row['file_path']), '/');
            if (is_file($abs)) {
                $size = @filesize($abs);
                if ($size !== false && $size > 0) {
                    $upd->execute([$size, $row['pk']]);
                    $filled++;
                    continue;
                }
            }
            $missing++;   // file no longer on disk — left at 0, not fatal
        }
        echo "  · {$table}: {$filled} row(s) backfilled, {$missing} file(s) not found on disk\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
