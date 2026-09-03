<?php
/**
 * core/backup.php
 * ---------------
 * Canonical database-backup helpers, shared by:
 *   - app/constant/settings/backup_restore.php   (on-load auto backup)
 *   - api/backup_actions.php                      (create / pre-restore safety)
 *   - cron/auto_backup.php                        (scheduled nightly backup)
 *
 * Pure helpers, no output. Safe to require multiple times (function_exists
 * guards). Produces the same SQL format the system already uses, PLUS correct
 * handling of VIEWS (dumped as CREATE VIEW after tables — never as tables with
 * INSERTs, which was breaking restores).
 */

if (!function_exists('bms_write_dump')) {

    /**
     * Write a full SQL dump (schema + data for base tables; CREATE VIEW for
     * views) to $filepath. Streams row-by-row to keep memory low.
     *
     * @throws Exception on any failure (caller removes the partial file).
     */
    function bms_write_dump(PDO $pdo, string $filepath): void {
        @set_time_limit(0);

        $handle = fopen($filepath, 'w');
        if (!$handle) {
            throw new Exception("Cannot open file for writing: $filepath");
        }

        try {
            // Split base tables from views. SHOW FULL TABLES exposes Table_type
            // ('BASE TABLE' | 'VIEW') so views are handled separately.
            $baseTables = [];
            $views      = [];
            $stmt = $pdo->query("SHOW FULL TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                // [0] = name, [1] = Table_type
                if (isset($row[1]) && strtoupper($row[1]) === 'VIEW') {
                    $views[] = $row[0];
                } else {
                    $baseTables[] = $row[0];
                }
            }

            fwrite($handle, "-- BMS Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n");

            // ── Base tables: structure + data ──────────────────────────────
            foreach ($baseTables as $table) {
                $tq   = "`$table`";
                $create = $pdo->query("SHOW CREATE TABLE $tq")->fetch(PDO::FETCH_NUM);
                fwrite($handle, "\nDROP TABLE IF EXISTS $tq;\n");
                fwrite($handle, $create[1] . ";\n\n");

                // Column list, EXCLUDING generated columns.
                //
                // This used to be `SELECT *` + `INSERT INTO t VALUES(...)` with
                // no column list, which supplies a value for every GENERATED
                // column. MySQL rejects those rows outright:
                //   ERROR 3105: The value specified for generated column … is
                //   not allowed
                // and a plain `mysql < dump.sql` ABORTS there, leaving the
                // database half-restored. That is not a theoretical risk — on
                // 2026-09-03 it stopped a recovery at product_stocks, 211 of 303
                // tables in. A backup whose output cannot be restored is not a
                // backup. See tenant_isolation_plan.md, "Leak C".
                //
                // SHOW COLUMNS is used rather than information_schema so this
                // needs no privilege beyond what the dump already requires.
                $cols = [];
                $cstmt = $pdo->query("SHOW COLUMNS FROM $tq");
                while ($c = $cstmt->fetch(PDO::FETCH_ASSOC)) {
                    // Extra is 'STORED GENERATED' / 'VIRTUAL GENERATED' for
                    // generated columns; MySQL recomputes them on insert.
                    if (stripos((string)($c['Extra'] ?? ''), 'GENERATED') !== false) continue;
                    $cols[] = (string)$c['Field'];
                }

                // Every column generated — nothing to insert, the rows rebuild
                // themselves. (Not possible in practice, but never emit an
                // `INSERT ... () VALUES ()`.)
                if (!$cols) { fwrite($handle, "\n"); continue; }

                $colSql  = '`' . implode('`,`', $cols) . '`';
                $colList = '(' . $colSql . ')';

                $rows = $pdo->query("SELECT $colSql FROM $tq");
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $values = array_map(
                        fn($v) => is_null($v) ? 'NULL' : $pdo->quote($v),
                        $row
                    );
                    fwrite($handle, "INSERT INTO $tq $colList VALUES(" . implode(',', $values) . ");\n");
                }
                fwrite($handle, "\n");
            }

            // ── Views: CREATE VIEW only (no data), after the tables exist ───
            foreach ($views as $view) {
                $vq = "`$view`";
                try {
                    $cv = $pdo->query("SHOW CREATE VIEW $vq")->fetch(PDO::FETCH_ASSOC);
                    // SHOW CREATE VIEW columns: View, Create View, ...
                    $createView = $cv['Create View'] ?? ($cv['Create view'] ?? null);
                    if ($createView) {
                        fwrite($handle, "\nDROP VIEW IF EXISTS $vq;\n");
                        fwrite($handle, $createView . ";\n\n");
                    }
                } catch (Throwable $e) {
                    // A view referencing a missing/renamed table — skip it
                    // rather than abort the whole backup.
                    fwrite($handle, "\n-- (skipped view $vq: " . str_replace(["\n", "\r"], ' ', $e->getMessage()) . ")\n");
                }
            }

            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);
        } catch (Throwable $e) {
            if (is_resource($handle)) fclose($handle);
            throw $e instanceof Exception ? $e : new Exception($e->getMessage());
        }
    }

    /**
     * Delete auto/pre-restore backups older than $days days (by file mtime).
     * Manual ("bms_backup_*") and uploaded ("uploaded_*") files are NEVER
     * touched — only "auto_backup_*" and "pre_restore_*" are auto-pruned.
     *
     * @return string[]  filenames deleted
     */
    function bms_prune_backups(string $dir, int $days = 7): array {
        $dir = rtrim($dir, '/\\') . '/';
        $cutoff  = time() - ($days * 86400);
        $deleted = [];

        foreach (['auto_backup_*.sql', 'pre_restore_*.sql'] as $pattern) {
            foreach ((glob($dir . $pattern) ?: []) as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    if (@unlink($file)) $deleted[] = basename($file);
                }
            }
        }
        return $deleted;
    }
}
