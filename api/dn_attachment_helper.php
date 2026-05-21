<?php
// File: api/dn_attachment_helper.php
// Shared helpers for Delivery Note attachment handling — named, multi-file.
// Each attachment carries a user-given name plus the uploaded file.
// Used by api/create_dn.php and api/update_dn.php.

if (!function_exists('dn_collect_attachment_pairs')) {
    /**
     * Read attachment_name[] + attachment_file[] from the request and return a
     * flat list of ['name' => string, 'file' => single-file array] pairs.
     * Rows without an uploaded file are skipped; a blank name falls back to the
     * original file name (without extension).
     */
    function dn_collect_attachment_pairs(): array
    {
        $pairs = [];
        $files = $_FILES['attachment_file'] ?? null;
        $names = $_POST['attachment_name'] ?? [];
        if (!is_array($names)) $names = [$names];
        if (!$files || !isset($files['name'])) return $pairs;

        if (is_array($files['name'])) {
            foreach ($files['name'] as $i => $fname) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $fname !== '') {
                    $custom = trim((string)($names[$i] ?? ''));
                    $pairs[] = [
                        'name' => $custom !== '' ? $custom : pathinfo($fname, PATHINFO_FILENAME),
                        'file' => [
                            'name'     => $fname,
                            'type'     => $files['type'][$i] ?? '',
                            'tmp_name' => $files['tmp_name'][$i] ?? '',
                            'error'    => $files['error'][$i],
                            'size'     => $files['size'][$i] ?? 0,
                        ],
                    ];
                }
            }
        } elseif (($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $files['name'] !== '') {
            $custom = trim((string)($names[0] ?? ''));
            $pairs[] = [
                'name' => $custom !== '' ? $custom : pathinfo($files['name'], PATHINFO_FILENAME),
                'file' => $files,
            ];
        }
        return $pairs;
    }
}

if (!function_exists('dn_save_attachments')) {
    /**
     * Validate (5-check security per §19) and store named DN attachment files,
     * inserting one row per file into delivery_attachments. Files are stored under
     * the central uploads/ folder (uploads/deliveries/) and registered in the
     * document library. $pairs = list of ['name'=>string,'file'=>single-file array].
     * Throws Exception on any invalid file so the caller can roll back.
     */
    function dn_save_attachments(PDO $pdo, int $delivery_id, array $pairs, int $user_id, ?int $project_id = null): void
    {
        if (empty($pairs)) return;

        $allowed_ext  = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];
        $allowed_mime = [
            'application/pdf', 'image/jpeg', 'image/png', 'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $max_size = 10 * 1024 * 1024; // 10MB

        // General uploads folder — uploads/deliveries/
        $dir = __DIR__ . '/../uploads/deliveries/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $ins = $pdo->prepare("
            INSERT INTO delivery_attachments
                (delivery_id, file_name, file_path, file_type, file_size, uploaded_by, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($pairs as $pair) {
            $file  = $pair['file'];
            $label = $pair['name'];

            // 1. Extension whitelist
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) {
                throw new Exception("File type .$ext is not allowed.");
            }
            // 2. Real MIME (magic bytes)
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($mime, $allowed_mime, true)) {
                throw new Exception("File '{$file['name']}' content does not match an allowed type.");
            }
            // 3. Size limit
            if ($file['size'] > $max_size) {
                throw new Exception("File '{$file['name']}' exceeds the 10MB limit.");
            }
            // 4. Non-guessable name
            $safe = bin2hex(random_bytes(16)) . '.' . $ext;
            $target = $dir . $safe;
            // 5. Store under uploads/ (folder has .htaccess execution guard)
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                throw new Exception("Failed to store uploaded file '{$file['name']}'.");
            }

            $rel = 'uploads/deliveries/' . $safe;
            $ins->execute([$delivery_id, $label, $rel, $mime, (int)$file['size'], $user_id]);

            if (function_exists('registerFileInLibrary')) {
                registerFileInLibrary(
                    $pdo, $rel, $file['name'], (int)$file['size'],
                    $label, 'delivery-note,supplier-dn', $user_id, $project_id
                );
            }
        }
    }
}
