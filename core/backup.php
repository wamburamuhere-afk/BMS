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
                        fwrite($handle, bms_portable_view_sql($createView) . ";\n\n");
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
     * Make a CREATE VIEW statement portable between MySQL accounts.
     *
     * SHOW CREATE VIEW embeds the account that owns the view:
     *     CREATE ALGORITHM=UNDEFINED DEFINER=`bejundas`@`localhost` SQL SECURITY … VIEW …
     *
     * Restoring that as a DIFFERENT user — a tenant's `bms_u{id}`, or `bms_u1`
     * after the Tenant #1 cutover — makes MySQL demand SYSTEM_USER and refuse:
     *     "Access denied; you need (at least one of) the SYSTEM_USER privilege(s)"
     * Observed on 2026-09-03 restoring into bms_t9002.
     *
     * A dump is a portable artefact; it must not carry the identity of whoever
     * happened to create it. Dropping DEFINER makes the restoring account the
     * definer, and SQL SECURITY INVOKER means the view runs with the rights of
     * whoever queries it — which is what a per-tenant database wants anyway.
     */
    function bms_portable_view_sql(string $createView): string
    {
        $sql = preg_replace('/\s+DEFINER\s*=\s*`(?:[^`]|``)*`@`(?:[^`]|``)*`/i', '', $createView);
        $sql = preg_replace('/\s+DEFINER\s*=\s*CURRENT_USER\b/i', '', $sql);
        $sql = preg_replace('/\bSQL\s+SECURITY\s+DEFINER\b/i', 'SQL SECURITY INVOKER', $sql);
        return $sql;
    }

    /**
     * Upgrade a LEGACY dump so it can actually be restored.
     *
     * Dumps written before 2026-09-03 used `INSERT INTO t VALUES(...)` with no
     * column list, supplying a value for every GENERATED column. MySQL rejects
     * those rows (ERROR 3105) and, because mysqli::multi_query stops at the
     * first failing statement, EVERY TABLE AFTER THE FIRST OFFENDER IS SKIPPED.
     * A restore therefore appears to "complete with 1 error" while having
     * silently loaded only part of the database — which is exactly how a
     * recovery on 2026-09-03 stopped at product_stocks, 211 of 303 tables in.
     *
     * Fixing the writer does not help files already on disk: every backup taken
     * before that date is affected. This converts them in memory, so an old file
     * restores correctly instead of truncating the database.
     *
     * It reads the dump's OWN `CREATE TABLE` statements to learn which columns
     * are generated — no database access, so it works on any file from any
     * server. Statements it does not recognise are passed through untouched.
     *
     * @return array{sql:string, tables:string[], rows:int}
     *         tables = tables whose INSERTs were rewritten.
     */
    function bms_upgrade_legacy_dump(string $sql): array
    {
        // Strip DEFINER clauses globally before line-by-line processing.
        // Covers ALL object types: VIEW, PROCEDURE, FUNCTION, TRIGGER, EVENT —
        // across BMS legacy backups, cross-account migrations, and phpMyAdmin exports.
        // Uses \s+DEFINER (not \bDEFINER) to avoid false-matching inside quoted data.
        $sql = preg_replace('/\s+DEFINER\s*=\s*`(?:[^`]|``)*`@`(?:[^`]|``)*`/i', '', $sql);
        $sql = preg_replace('/\s+DEFINER\s*=\s*CURRENT_USER\b/i', '', $sql);
        $sql = preg_replace('/\bSQL\s+SECURITY\s+DEFINER\b/i', 'SQL SECURITY INVOKER', $sql);
        $sql = preg_replace('/\/\*![0-9]+\s+DEFINER=[^*]*\*\//i', '', $sql); // phpMyAdmin format

        $lines    = preg_split("/\r\n|\n|\r/", $sql);
        $out      = [];
        $columns  = [];      // table => ordered column names
        $generated= [];      // table => [ordinal => true]
        $touched  = [];
        $rewritten= 0;

        $curTable = null;    // table whose CREATE TABLE block we are inside

        foreach ($lines as $line) {
            // ── inside a CREATE TABLE block: collect the column order ────────
            if ($curTable !== null) {
                $trimmed = ltrim($line);
                if ($trimmed !== '' && $trimmed[0] === ')') {
                    $curTable = null;                    // end of the block
                } elseif (preg_match('/^`((?:[^`]|``)*)`\s+(.*)$/', $trimmed, $m)) {
                    $col = str_replace('``', '`', $m[1]);
                    $idx = count($columns[$curTable]);
                    $columns[$curTable][] = $col;
                    if (preg_match('/\bGENERATED\s+ALWAYS\s+AS\b/i', $m[2])) {
                        $generated[$curTable][$idx] = true;
                    }
                }
                $out[] = $line;
                continue;
            }

            // ── start of a CREATE TABLE block ────────────────────────────────
            if (preg_match('/^\s*CREATE\s+TABLE\s+`((?:[^`]|``)*)`/i', $line, $m)) {
                $curTable = str_replace('``', '`', $m[1]);
                $columns[$curTable]   = [];
                $generated[$curTable] = [];
                $out[] = $line;
                continue;
            }

            // ── a legacy INSERT with no column list ──────────────────────────
            if (preg_match('/^INSERT INTO `((?:[^`]|``)*)` VALUES\((.*)\);\s*$/', $line, $m)) {
                $tbl = str_replace('``', '`', $m[1]);
                if (!empty($generated[$tbl])) {
                    $vals = bms_split_sql_values($m[2]);
                    $cols = $columns[$tbl];
                    // Only rewrite when the row really matches the table shape;
                    // anything else is passed through rather than guessed at.
                    if ($vals !== null && count($vals) === count($cols)) {
                        $keepCols = [];
                        $keepVals = [];
                        foreach ($cols as $i => $c) {
                            if (isset($generated[$tbl][$i])) continue;
                            $keepCols[] = '`' . str_replace('`', '``', $c) . '`';
                            $keepVals[] = $vals[$i];
                        }
                        $out[] = 'INSERT INTO `' . str_replace('`', '``', $tbl) . '` ('
                               . implode(',', $keepCols) . ') VALUES('
                               . implode(',', $keepVals) . ');';
                        $touched[$tbl] = true;
                        $rewritten++;
                        continue;
                    }
                }
            }

            // ── a CREATE VIEW carrying someone else's DEFINER ────────────────
            if (preg_match('/^\s*CREATE\s+(ALGORITHM|DEFINER|SQL SECURITY|OR REPLACE|VIEW)/i', $line)
                && stripos($line, ' VIEW ') !== false) {
                $out[] = bms_portable_view_sql($line);
                continue;
            }

            $out[] = $line;
        }

        return [
            'sql'    => implode("\n", $out),
            'tables' => array_keys($touched),
            'rows'   => $rewritten,
        ];
    }

    /**
     * Split the inside of `VALUES(...)` into its individual value expressions.
     *
     * Commas inside quoted strings are not separators, and a quote may be
     * escaped either by a backslash or by doubling. Getting this wrong would
     * silently corrupt data, so it returns NULL when the input does not parse
     * cleanly and the caller passes the line through untouched instead.
     *
     * @return string[]|null
     */
    function bms_split_sql_values(string $s): ?array
    {
        $vals = [];
        $buf  = '';
        $len  = strlen($s);
        $inStr = false;
        $depth = 0;                       // parentheses, e.g. a function call

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];

            if ($inStr) {
                $buf .= $ch;
                if ($ch === '\\') {                       // backslash escape
                    if ($i + 1 < $len) { $buf .= $s[++$i]; }
                    else return null;                     // dangling escape
                } elseif ($ch === "'") {
                    if ($i + 1 < $len && $s[$i + 1] === "'") { $buf .= $s[++$i]; }  // '' escape
                    else $inStr = false;
                }
                continue;
            }

            if ($ch === "'")            { $inStr = true;  $buf .= $ch; continue; }
            if ($ch === '(')            { $depth++;       $buf .= $ch; continue; }
            if ($ch === ')')            { $depth--;       $buf .= $ch; continue; }
            if ($ch === ',' && $depth === 0) { $vals[] = $buf; $buf = ''; continue; }

            $buf .= $ch;
        }

        if ($inStr || $depth !== 0) return null;          // unbalanced — do not guess
        $vals[] = $buf;
        return $vals;
    }

    /**
     * Delete auto/pre-restore backups older than $days days (by file mtime).
     * Manual ("bms_backup_*") and uploaded ("uploaded_*") files are NEVER
     * touched — only "auto_backup_*" and "pre_restore_*" are auto-pruned.
     * Handles both .sql and .zip formats.
     *
     * @return string[]  filenames deleted
     */
    function bms_prune_backups(string $dir, int $days = 7): array {
        $dir = rtrim($dir, '/\\') . '/';
        $cutoff  = time() - ($days * 86400);
        $deleted = [];

        foreach (['auto_backup_*.sql', 'pre_restore_*.sql', 'auto_backup_*.zip', 'pre_restore_*.zip'] as $pattern) {
            foreach ((glob($dir . $pattern) ?: []) as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    if (@unlink($file)) $deleted[] = basename($file);
                }
            }
        }
        return $deleted;
    }

    /**
     * Write a full backup ZIP: database dump + uploads/ folder + manifest.json.
     *
     * This is the canonical full-backup writer used by the UI, the cron, and the
     * pre-restore safety snapshot. A .zip backup can be restored to recover BOTH
     * the database and every uploaded file (images, documents, attachments).
     *
     * ZIP layout:
     *   database.sql        — full SQL dump (same as bms_write_dump output)
     *   uploads/<path>      — every file under $uploadsDir, relative structure kept
     *   manifest.json       — metadata for integrity checks on restore
     *
     * @param  PDO    $pdo         Connected, tenant-scoped PDO handle.
     * @param  string $zipPath     Absolute path for the output .zip file.
     * @param  string $uploadsDir  Absolute path to the uploads root (ROOT_DIR/uploads).
     * @return array{files_count:int, db_size_mb:float, files_size_mb:float}
     * @throws Exception on failure (partial .zip is deleted before throwing).
     */
    function bms_write_zip_backup(PDO $pdo, string $zipPath, string $uploadsDir): array
    {
        @set_time_limit(0);

        if (!class_exists('ZipArchive')) {
            throw new Exception(
                'PHP ZipArchive extension is not available. ' .
                'Enable php_zip in php.ini and restart Apache/PHP-FPM.'
            );
        }

        // Write the SQL dump to a temp file first so ZipArchive can stream it
        // on close() without loading the whole dump into memory.
        $tempSql = tempnam(sys_get_temp_dir(), 'bms_sql_');
        if ($tempSql === false) {
            throw new Exception('Cannot create a temporary file for the SQL dump.');
        }

        $zip       = new ZipArchive();
        $zipOpened = false;

        try {
            bms_write_dump($pdo, $tempSql);

            $res = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($res !== true) {
                throw new Exception("Cannot create ZIP archive (ZipArchive error code $res): $zipPath");
            }
            $zipOpened = true;

            // Queue the SQL dump — ZipArchive reads this file on close()
            $zip->addFile($tempSql, 'database.sql');

            // Add every file under uploads/, preserving the relative path
            $filesCount = 0;
            $filesSize  = 0;
            if (is_dir($uploadsDir)) {
                $realUploads = realpath($uploadsDir);
                if ($realUploads !== false) {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator(
                            $uploadsDir,
                            RecursiveDirectoryIterator::SKIP_DOTS
                        )
                    );
                    foreach ($it as $file) {
                        if (!$file->isFile()) continue;
                        $realFile = realpath($file->getPathname());
                        if ($realFile === false) continue;
                        // Symlink guard — only files physically under uploadsDir
                        $prefix = $realUploads . DIRECTORY_SEPARATOR;
                        if (strpos($realFile, $prefix) !== 0) continue;
                        $relPath = 'uploads/' . str_replace(
                            DIRECTORY_SEPARATOR, '/',
                            substr($realFile, strlen($prefix))
                        );
                        $zip->addFile($realFile, $relPath);
                        $filesCount++;
                        $filesSize += $file->getSize();
                    }
                }
            }

            // Manifest — written last so file counts are accurate
            $dbSizeMb    = round(filesize($tempSql) / 1048576, 2);
            $filesSizeMb = round($filesSize / 1048576, 2);
            $zip->addFromString('manifest.json', json_encode([
                'bms_backup_format' => '1.0',
                'created_at'        => date('c'),
                'db_size_mb'        => $dbSizeMb,
                'files_count'       => $filesCount,
                'files_size_mb'     => $filesSizeMb,
            ], JSON_PRETTY_PRINT));

            // Finalize the archive — ZipArchive reads addFile() sources here
            if (!$zip->close()) {
                $zipOpened = false;
                throw new Exception('ZipArchive::close() failed — archive may be incomplete.');
            }
            $zipOpened = false;

            @unlink($tempSql);

            return [
                'files_count'  => $filesCount,
                'db_size_mb'   => $dbSizeMb,
                'files_size_mb'=> $filesSizeMb,
            ];

        } catch (Throwable $e) {
            if ($zipOpened) @$zip->close();
            if (is_file($zipPath)) @unlink($zipPath);
            @unlink($tempSql);
            throw $e instanceof Exception ? $e : new Exception($e->getMessage());
        }
    }

    /**
     * Extract and validate a BMS backup ZIP into a temporary directory.
     *
     * Validates that the archive contains the required entries (manifest.json +
     * database.sql) before returning. On failure the temp directory is cleaned
     * up and an exception is thrown.
     *
     * The CALLER must call bms_delete_dir($result['temp_dir']) after it is
     * finished with the extracted files to free the disk space.
     *
     * @param  string $zipPath  Absolute path to a .zip backup file.
     * @return array{temp_dir:string, sql_path:string, uploads_path:string|null, manifest:array}
     * @throws Exception if the file is not a valid BMS backup.
     */
    function bms_extract_zip_backup(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('PHP ZipArchive extension is not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new Exception('Cannot open ZIP file — it may be corrupt or not a valid ZIP.');
        }

        // Validate required entries before extracting anything
        if ($zip->locateName('database.sql') === false) {
            $zip->close();
            throw new Exception('Invalid backup: missing database.sql inside the ZIP.');
        }
        if ($zip->locateName('manifest.json') === false) {
            $zip->close();
            throw new Exception('Invalid backup: missing manifest.json — this may be a non-BMS archive.');
        }

        $manifestJson = $zip->getFromName('manifest.json');
        $zip->close();

        $manifest = @json_decode($manifestJson, true);
        if (!is_array($manifest) || empty($manifest['bms_backup_format'])) {
            throw new Exception('Invalid backup: manifest.json is malformed or unrecognised format.');
        }

        // Extract to an isolated temp directory
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_restore_' . bin2hex(random_bytes(8));
        if (!mkdir($tempDir, 0700, true)) {
            throw new Exception("Cannot create temp extraction directory: $tempDir");
        }

        $zip2 = new ZipArchive();
        if ($zip2->open($zipPath) !== true) {
            bms_delete_dir($tempDir);
            throw new Exception('Cannot re-open ZIP for extraction.');
        }

        if (!$zip2->extractTo($tempDir)) {
            $zip2->close();
            bms_delete_dir($tempDir);
            throw new Exception('ZIP extraction failed — possible disk space or permissions issue.');
        }
        $zip2->close();

        $sqlPath     = $tempDir . DIRECTORY_SEPARATOR . 'database.sql';
        $uploadsPath = $tempDir . DIRECTORY_SEPARATOR . 'uploads';

        if (!is_file($sqlPath)) {
            bms_delete_dir($tempDir);
            throw new Exception('Extraction succeeded but database.sql is missing from the extracted files.');
        }

        return [
            'temp_dir'     => $tempDir,
            'sql_path'     => $sqlPath,
            'uploads_path' => is_dir($uploadsPath) ? $uploadsPath : null,
            'manifest'     => $manifest,
        ];
    }

    /**
     * Recursively copy $src directory into $dst, creating $dst if needed.
     * Overwrites existing files; does not delete files absent from $src.
     *
     * @return int  Number of files copied.
     */
    function bms_copy_dir(string $src, string $dst): int
    {
        if (!is_dir($src)) return 0;
        if (!is_dir($dst)) @mkdir($dst, 0755, true);

        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $dest = $dst . DIRECTORY_SEPARATOR . $it->getSubPathname();
            if ($file->isDir()) {
                if (!is_dir($dest)) @mkdir($dest, 0755, true);
            } else {
                // Ensure parent directory exists
                $destDir = dirname($dest);
                if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                if (@copy($file->getPathname(), $dest)) $count++;
            }
        }
        return $count;
    }

    /**
     * Recursively delete a directory and all its contents.
     * Used to clean up temp extraction directories after restore.
     */
    function bms_delete_dir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
