<?php
/**
 * core/note_attachments.php
 * -------------------------
 * Shared attachment-upload helper for Credit Notes and Debit Notes, modelled on
 * the GRN attachment flow but applying the §19 file-upload security standard
 * (extension whitelist + magic-byte MIME + size cap + non-guessable name +
 * .htaccess hardening). One row per (Document Name + uploaded file).
 *
 * Reads the canonical multipart fields posted by the note create/edit forms:
 *   $_FILES['attachments']   (file[])
 *   $_POST['attachment_names'] (string[])  — the per-file Document Name
 */

if (!function_exists('saveNoteAttachments')) {
    /**
     * @param string $table  e.g. 'debit_note_attachments' | 'credit_note_attachments'
     * @param string $fkCol  e.g. 'debit_note_id' | 'credit_note_id'
     * @param string $subdir e.g. 'debit_notes' | 'credit_notes' (under uploads/finance/)
     * @return array{saved:int,errors:array<int,string>}
     */
    function saveNoteAttachments(PDO $pdo, string $table, string $fkCol, int $noteId, string $subdir): array
    {
        $out = ['saved' => 0, 'errors' => []];
        if (empty($_FILES['attachments']) || empty($_FILES['attachments']['name'][0])) return $out;

        $names = $_POST['attachment_names'] ?? [];
        $dir   = __DIR__ . '/../uploads/finance/' . $subdir . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        // Harden the folder (§19) — block script execution.
        $ht = $dir . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht,
                "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\n"
              . "Options -ExecCGI\nRemoveHandler .php .phtml .php5\nRemoveType .php .phtml .php5\n");
        }

        $allowed_ext  = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif'];
        $allowed_mime = [
            'application/pdf','application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg','image/png','image/gif',
        ];
        $max   = 10 * 1024 * 1024;
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $ins = $pdo->prepare("
            INSERT INTO `$table` (`$fkCol`, file_name, file_path, file_type, file_size, uploaded_by, uploaded_at, description)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");

        $count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

            $tmp  = $_FILES['attachments']['tmp_name'][$i];
            $orig = $_FILES['attachments']['name'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed_ext, true)) { $out['errors'][] = "$orig: file type not allowed"; continue; }
            $mime = $finfo->file($tmp);
            if (!in_array($mime, $allowed_mime, true)) { $out['errors'][] = "$orig: content does not match an allowed type"; continue; }
            if (($_FILES['attachments']['size'][$i] ?? 0) > $max) { $out['errors'][] = "$orig: exceeds 10MB"; continue; }

            $safe = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $dir . $safe;
            if (!move_uploaded_file($tmp, $dest)) { $out['errors'][] = "$orig: upload failed"; continue; }

            $rel     = 'uploads/finance/' . $subdir . '/' . $safe;
            $docName = !empty($names[$i]) ? trim($names[$i]) : $orig;
            $ins->execute([$noteId, $docName, $rel, $mime, (int)$_FILES['attachments']['size'][$i],
                           $_SESSION['user_id'] ?? null, $docName]);
            $out['saved']++;
        }
        return $out;
    }
}
