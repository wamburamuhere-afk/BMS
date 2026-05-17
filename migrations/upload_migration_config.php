<?php
/**
 * Upload Migration — Shared Configuration
 * Included by all four migration scripts. Not run directly.
 */

// ── Folder mapping: old relative path → new relative path ──────────────────
// All paths relative to ROOT_DIR (app root). Trailing slash required.
$FOLDER_MAP = [
    'uploads/employees/documents/'    => 'uploads/hr/employees/',
    'uploads/leaves/'                  => 'uploads/hr/leaves/',
    'uploads/purchase_orders/'         => 'uploads/finance/purchase_orders/',
    'uploads/payments/'                => 'uploads/finance/invoices/',
    'uploads/petty_cash/'              => 'uploads/finance/petty_cash/',
    'uploads/vouchers/'                => 'uploads/finance/vouchers/',
    'uploads/grn/'                     => 'uploads/finance/grn/',
    'uploads/deliveries/'              => 'uploads/procurement/delivery_notes/',
    'uploads/purchase_returns/'        => 'uploads/procurement/purchase_returns/',
    'uploads/contracts/'               => 'uploads/projects/contracts/',
    'uploads/project_scopes/'          => 'uploads/projects/scopes/',
    'uploads/project_reports/'         => 'uploads/projects/reports/',
    'uploads/inspections/'             => 'uploads/projects/inspections/',
    'uploads/tenders/submission/'      => 'uploads/tenders/submissions/',
    'uploads/tenders/post_qualification/' => 'uploads/tenders/evaluation/',
    'uploads/tenders/opening/'         => 'uploads/tenders/evaluation/',
    'uploads/tenders/award/'           => 'uploads/tenders/awards/',
    'uploads/customers/'               => 'uploads/parties/customers/',
    'uploads/customer_photos/'         => 'uploads/parties/customers/',
    'uploads/document_library/'        => 'uploads/documents/',
    'uploads/collateral/'              => 'uploads/loans/collateral/',
    'uploads/guarantors/'              => 'uploads/loans/guarantors/',
    'uploads/business_attachments/'    => 'uploads/loans/documents/',
    'uploads/company_documents/'       => 'uploads/loans/documents/',
    'uploads/id_attachments/'          => 'uploads/loans/documents/',
    'uploads/company_id_attachments/'  => 'uploads/loans/documents/',
    'uploads/rep_id_attachments/'      => 'uploads/loans/documents/',
    'uploads/company_photos/'          => 'uploads/loans/documents/',
    'uploads/dynamic_attachments/'     => 'uploads/loans/documents/',
    'uploads/company/'                 => 'uploads/system/logo/',
    'assets/images/'                   => 'uploads/system/logo/',
    'backups/'                         => 'uploads/system/backups/',
];

// ── Database tables that store file paths ──────────────────────────────────
// json:  true  = column is a JSON object with multiple paths inside
// where: extra WHERE clause to restrict rows (e.g. system_settings)
// pk:    primary key column name (used for JSON updates)
$DB_TABLES = [
    ['table' => 'documents',                           'col' => 'file_path',           'pk' => 'document_id',  'json' => false],
    ['table' => 'user_signatures',                     'col' => 'file_path',           'pk' => 'signature_id', 'json' => false],
    ['table' => 'loan_documents',                      'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'suppliers',                           'col' => 'logo_path',           'pk' => 'supplier_id',  'json' => false],
    ['table' => 'sub_contractors',                     'col' => 'logo_path',           'pk' => 'supplier_id',  'json' => false],
    ['table' => 'customers',                           'col' => 'logo_path',           'pk' => 'customer_id',  'json' => false],
    ['table' => 'projects',                            'col' => 'contract_attachment', 'pk' => 'project_id',   'json' => false],
    ['table' => 'payment_attachments',                 'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'purchase_order_attachments',          'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'purchase_receipt_attachments',        'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'delivery_attachments',                'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'project_progress_report_attachments', 'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'project_scope_documents',             'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'leaves',                              'col' => 'document_path',       'pk' => 'leave_id',     'json' => false],
    ['table' => 'compliance_records',                  'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'document_templates',                  'col' => 'file_path',           'pk' => 'id',           'json' => false],
    ['table' => 'system_settings',                     'col' => 'setting_value',       'pk' => 'setting_key',  'json' => false,
     'where' => "setting_key = 'company_logo'"],
    // JSON column: employees.documents stores multiple paths in a JSON object
    ['table' => 'employees',                           'col' => 'documents',           'pk' => 'employee_id',  'json' => true],
];

// ── Helper: recursively list all files under a directory ───────────────────
function um_list_files($dir) {
    $result = [];
    if (!is_dir($dir)) return $result;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($items as $file) {
        if ($file->isFile()) {
            $result[] = $file->getPathname();
        }
    }
    return $result;
}

// ── Helper: find which DB tables reference a given path ────────────────────
function um_find_in_db($pdo, $rel_path, $DB_TABLES) {
    $matches = [];
    foreach ($DB_TABLES as $t) {
        try {
            if ($t['json']) {
                $rows = $pdo->query("SELECT {$t['pk']}, {$t['col']} FROM {$t['table']} WHERE {$t['col']} IS NOT NULL AND {$t['col']} != ''")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $data = json_decode($row[$t['col']], true);
                    if (!$data) continue;
                    $found = false;
                    array_walk_recursive($data, function($val) use ($rel_path, &$found) {
                        if ($val === $rel_path) $found = true;
                    });
                    if ($found) $matches[] = ['table' => $t['table'], 'col' => $t['col'], 'pk' => $row[$t['pk']]];
                }
            } else {
                $where = isset($t['where']) ? "AND {$t['where']}" : '';
                $stmt = $pdo->prepare("SELECT {$t['pk']} FROM {$t['table']} WHERE {$t['col']} = ? $where");
                $stmt->execute([$rel_path]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pk) {
                    $matches[] = ['table' => $t['table'], 'col' => $t['col'], 'pk' => $pk];
                }
            }
        } catch (PDOException $e) {
            // table may not exist on this install — skip silently
        }
    }
    return $matches;
}

// ── Helper: update DB paths (old → new) for a single file ──────────────────
function um_update_db($pdo, $old_rel, $new_rel, $DB_TABLES) {
    $count = 0;
    foreach ($DB_TABLES as $t) {
        try {
            if ($t['json']) {
                $rows = $pdo->query("SELECT {$t['pk']}, {$t['col']} FROM {$t['table']} WHERE {$t['col']} IS NOT NULL AND {$t['col']} != ''")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $data = json_decode($row[$t['col']], true);
                    if (!$data) continue;
                    $changed = false;
                    array_walk_recursive($data, function(&$val) use ($old_rel, $new_rel, &$changed) {
                        if ($val === $old_rel) { $val = $new_rel; $changed = true; }
                    });
                    if ($changed) {
                        $pdo->prepare("UPDATE {$t['table']} SET {$t['col']} = ? WHERE {$t['pk']} = ?")->execute([json_encode($data), $row[$t['pk']]]);
                        $count++;
                    }
                }
            } else {
                $where = isset($t['where']) ? "AND {$t['where']}" : '';
                $stmt = $pdo->prepare("UPDATE {$t['table']} SET {$t['col']} = ? WHERE {$t['col']} = ? $where");
                $stmt->execute([$new_rel, $old_rel]);
                $count += $stmt->rowCount();
            }
        } catch (PDOException $e) {
            // table may not exist — skip
        }
    }
    return $count;
}

// ── Helper: resolve destination path, handle filename conflicts ─────────────
function um_dest_path($new_abs, $old_prefix, $filename) {
    $dest = $new_abs . $filename;
    if (file_exists($dest)) {
        $safe_prefix = preg_replace('/[^a-z0-9]/', '_', trim($old_prefix, '/'));
        $dest = $new_abs . $safe_prefix . '__' . $filename;
    }
    return $dest;
}
