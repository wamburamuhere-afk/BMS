<?php
/**
 * BMS — Employee "Additional / Optional Documents" (wizard Step 5).
 *
 * The employee wizard used to allow exactly ONE extra document, stored flat on
 * `employees.other_doc_name` + a path inside the `documents` JSON blob. That
 * cannot express "several named documents", so the wizard now writes each extra
 * document as its own row in `employee_documents` (doc type 'Other') — the table
 * already built for HR compliance in Tier 2.
 *
 * Shared by api/add_employee.php and api/update_employee.php so the create and
 * update paths cannot drift apart. Both already run inside a transaction; these
 * functions never begin/commit one of their own.
 *
 * Uploads follow .claude/security.md §19 (extension + real MIME + size +
 * random filename + hardened uploads/ dir), mirroring api/add_employee_document.php.
 */

if (!function_exists('hrOtherDocTypeId')) {
    /**
     * Resolve the 'Other' document type id. Never hard-code 8 — the seed order
     * is not guaranteed across installs.
     */
    function hrOtherDocTypeId(PDO $pdo): int
    {
        $stmt = $pdo->prepare("SELECT doc_type_id FROM employee_document_types WHERE type_name = 'Other' AND status = 'active' LIMIT 1");
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new Exception("Document type 'Other' is missing from employee_document_types");
        }
        return (int)$id;
    }
}

if (!function_exists('hrExtraDocsFromRequest')) {
    /**
     * Normalise the repeatable wizard inputs into [['name' => ..., 'idx' => i], ...].
     *
     * The form posts parallel arrays:
     *   extra_doc_name[]  (text)
     *   extra_doc_file[]  (file)
     *
     * A row is only meaningful when BOTH a name and a file are present. Rows the
     * user added and left blank are skipped silently; a name without a file (or a
     * file without a name) is an error the user must fix — we don't guess.
     */
    function hrExtraDocsFromRequest(): array
    {
        $names = $_POST['extra_doc_name'] ?? [];
        if (!is_array($names)) return [];

        $files = $_FILES['extra_doc_file'] ?? null;
        $rows  = [];

        foreach ($names as $i => $rawName) {
            $name = trim((string)$rawName);
            $hasFile = $files
                && isset($files['error'][$i])
                && $files['error'][$i] !== UPLOAD_ERR_NO_FILE;

            if ($name === '' && !$hasFile) continue;                 // untouched row
            if ($name === '') throw new Exception('Each additional document needs a name. Please name the file you uploaded, or remove the row.');
            if (!$hasFile)    throw new Exception("Please choose a file for the additional document '$name', or remove the row.");

            $rows[] = ['name' => $name, 'idx' => $i];
        }
        return $rows;
    }
}

if (!function_exists('hrSaveExtraDocuments')) {
    /**
     * Validate + store every additional document posted by the wizard.
     * Returns the number of documents saved.
     *
     * @param array $employee  Needs first_name, last_name, project_id.
     */
    function hrSaveExtraDocuments(PDO $pdo, int $employee_id, array $employee, int $user_id): int
    {
        $rows = hrExtraDocsFromRequest();
        if (empty($rows)) return 0;

        $files       = $_FILES['extra_doc_file'];
        $doc_type_id = hrOtherDocTypeId($pdo);
        $emp_name    = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));

        $allowed_ext  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
        $allowed_mime = [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg', 'image/png', 'image/gif',
        ];

        $target_dir = __DIR__ . '/../uploads/employee_docs/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

        // Files land on disk before the caller's transaction commits. If a later
        // row is rejected the DB rolls back, so the already-moved files must be
        // removed too or they leak into uploads/ with no row pointing at them.
        $saved = 0;
        $moved = [];
        try {
        foreach ($rows as $row) {
            $i    = $row['idx'];
            $name = $row['name'];

            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                throw new Exception("Upload failed for additional document '$name'");
            }

            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) {
                throw new Exception("File type not allowed for '$name'");
            }

            $finfo     = new finfo(FILEINFO_MIME_TYPE);
            $real_mime = $finfo->file($files['tmp_name'][$i]);
            if (!in_array($real_mime, $allowed_mime, true)) {
                throw new Exception("File content does not match allowed types for '$name'");
            }

            if ($files['size'][$i] > 10 * 1024 * 1024) {
                throw new Exception("'$name' exceeds the 10MB size limit");
            }

            $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($files['tmp_name'][$i], $target_dir . $safe_name)) {
                throw new Exception("Upload failed for '$name'");
            }
            $moved[]  = $target_dir . $safe_name;
            $file_rel = 'uploads/employee_docs/' . $safe_name;

            $library_id = null;
            if (function_exists('registerFileInLibrary')) {
                $library_id = registerFileInLibrary(
                    $pdo, $file_rel, $files['name'][$i], (int)$files['size'][$i],
                    $name . ' — ' . $emp_name,
                    'hr,employee,other',
                    $user_id,
                    isset($employee['project_id']) && $employee['project_id'] !== null ? (int)$employee['project_id'] : null
                );
            }

            $pdo->prepare("
                INSERT INTO employee_documents (
                    employee_id, doc_type_id, document_name, file_path, original_filename,
                    file_size, library_document_id, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
            ")->execute([
                $employee_id, $doc_type_id, $name, $file_rel, $files['name'][$i],
                (int)$files['size'][$i], $library_id, $user_id,
            ]);

            $saved++;
        }
        } catch (Throwable $e) {
            foreach ($moved as $path) {
                if (is_file($path)) @unlink($path);
            }
            throw $e;
        }

        if ($saved > 0 && function_exists('logActivity')) {
            logActivity($pdo, $user_id, "Added $saved additional document(s) for employee #$employee_id");
        }
        return $saved;
    }
}

if (!function_exists('hrDeleteExtraDocuments')) {
    /**
     * Soft-delete additional documents the user removed in the edit wizard.
     * Every id is verified to belong to THIS employee, so a tampered POST cannot
     * delete another employee's document. Returns the number deleted.
     */
    function hrDeleteExtraDocuments(PDO $pdo, int $employee_id, int $user_id): int
    {
        $ids = $_POST['removed_extra_doc_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) return 0;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return 0;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            UPDATE employee_documents
               SET status = 'deleted', updated_by = ?, updated_at = NOW()
             WHERE emp_doc_id IN ($ph)
               AND employee_id = ?
               AND status != 'deleted'
        ");
        $stmt->execute(array_merge([$user_id], $ids, [$employee_id]));
        $n = $stmt->rowCount();

        if ($n > 0 && function_exists('logActivity')) {
            logActivity($pdo, $user_id, "Removed $n additional document(s) from employee #$employee_id");
        }
        return $n;
    }
}

if (!function_exists('hrFetchExtraDocuments')) {
    /**
     * The employee's active additional documents, for the edit wizard and the
     * details page.
     */
    function hrFetchExtraDocuments(PDO $pdo, int $employee_id): array
    {
        $stmt = $pdo->prepare("
            SELECT ed.emp_doc_id, ed.document_name, ed.file_path, ed.original_filename, ed.file_size
              FROM employee_documents ed
              JOIN employee_document_types dt ON dt.doc_type_id = ed.doc_type_id
             WHERE ed.employee_id = ?
               AND ed.status = 'active'
               AND dt.type_name = 'Other'
             ORDER BY ed.emp_doc_id ASC
        ");
        $stmt->execute([$employee_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
