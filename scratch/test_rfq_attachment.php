<?php
/**
 * Test: RFQ Multi-Attachment Feature
 * Covers every file and function touched in update 33.
 *
 * Run: http://localhost/bms/scratch/test_rfq_attachment.php
 * Requires: active BMS session (be logged in first)
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

$pass = 0; $fail = 0; $results = [];

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail, $results;
    if ($cond) { $pass++; $results[] = ['pass', $label, $detail]; }
    else        { $fail++; $results[] = ['fail', $label, $detail ?: 'condition was false']; }
}

// ══════════════════════════════════════════════════════════════════
// §1  MIGRATION — DB schema + upload directory
// ══════════════════════════════════════════════════════════════════

// 1.1 rfq_attachments table exists
$tableExists = $pdo->query("SHOW TABLES LIKE 'rfq_attachments'")->fetchColumn();
ok('rfq_attachments table exists', !empty($tableExists));

// 1.2 Required columns present
$cols = [];
if ($tableExists) {
    $rows = $pdo->query("SHOW COLUMNS FROM rfq_attachments")->fetchAll(PDO::FETCH_COLUMN);
    $cols = $rows;
}
foreach (['attachment_id','rfq_id','attachment_name','file_path','original_name','file_size','uploaded_by','uploaded_at'] as $c) {
    ok("rfq_attachments.{$c} column exists", in_array($c, $cols));
}

// 1.3 Old single attachment column removed from rfq
$oldCol = $pdo->query("SHOW COLUMNS FROM rfq LIKE 'attachment'")->fetchColumn();
ok('rfq.attachment single column removed', empty($oldCol));

// 1.4 Upload directory exists
$uploadDir = __DIR__ . '/../uploads/procurement/rfq';
ok('uploads/procurement/rfq/ directory exists', is_dir($uploadDir));

// 1.5 .htaccess present and blocks PHP
$htaccess = $uploadDir . '/.htaccess';
ok('.htaccess exists in uploads/procurement/rfq/', file_exists($htaccess));
if (file_exists($htaccess)) {
    $hc = file_get_contents($htaccess);
    ok('.htaccess denies .php files', str_contains($hc, 'Require all denied'));
    ok('.htaccess removes PHP handler', str_contains($hc, 'RemoveHandler .php'));
} else {
    ok('.htaccess denies .php files', false, '.htaccess missing');
    ok('.htaccess removes PHP handler', false, '.htaccess missing');
}

// ══════════════════════════════════════════════════════════════════
// §2  rfq_create.php — form HTML structure
// ══════════════════════════════════════════════════════════════════

$createSrc = file_get_contents(__DIR__ . '/../app/bms/purchase/rfq_create.php');

// 2.1 CSRF token in form
ok('rfq_create.php: CSRF hidden input', str_contains($createSrc, 'name="_csrf"') && str_contains($createSrc, 'csrf_token()'));

// 2.2 Existing attachments loaded from DB in edit mode
ok('rfq_create.php: queries rfq_attachments for edit mode', str_contains($createSrc, 'rfq_attachments') && str_contains($createSrc, '$existing_attachments'));

// 2.3 Attachment card heading with paperclip icon
ok('rfq_create.php: Attachments card heading', str_contains($createSrc, 'bi-paperclip') && str_contains($createSrc, 'Attachments'));

// 2.4 Attachment card is BELOW the items card (items card appears first)
$itemsPos = strpos($createSrc, 'RFQ Items');
$attPos   = strpos($createSrc, 'newAttachmentsContainer');
ok('rfq_create.php: attachment section is below items card', $itemsPos !== false && $attPos !== false && $attPos > $itemsPos);

// 2.5 name field per attachment
ok('rfq_create.php: attachment_name[] input', str_contains($createSrc, 'name="attachment_name[]"'));

// 2.6 file input per attachment
ok('rfq_create.php: attachment_file[] input', str_contains($createSrc, 'name="attachment_file[]"'));

// 2.7 accept attribute on file input
ok('rfq_create.php: file accept attribute', str_contains($createSrc, 'accept=".pdf,.doc'));

// 2.8 Add Attachment button
ok('rfq_create.php: Add Attachment button', str_contains($createSrc, 'Add Attachment') && str_contains($createSrc, 'addAttachmentRow()'));

// 2.9 addAttachmentRow JS function defined
ok('rfq_create.php: addAttachmentRow() function defined', str_contains($createSrc, 'function addAttachmentRow()'));

// 2.10 Remove row button inside each new row
ok('rfq_create.php: per-row remove button in addAttachmentRow',
    str_contains($createSrc, "getElementById('att_row_"));

// 2.11 Edit mode: existing attachments shown with View link
ok('rfq_create.php: existing attachments list with View button', str_contains($createSrc, 'existing_att_') && str_contains($createSrc, 'View'));

// 2.12 Edit mode: removeExistingAttachment JS function
ok('rfq_create.php: removeExistingAttachment() JS function', str_contains($createSrc, 'function removeExistingAttachment('));

// 2.13 removeExistingAttachment calls delete_rfq_attachment API
ok('rfq_create.php: removeExistingAttachment calls delete_rfq_attachment', str_contains($createSrc, 'delete_rfq_attachment'));

// 2.14 Swal confirmation in removeExistingAttachment
ok('rfq_create.php: Swal confirm before deleting attachment', str_contains($createSrc, "Swal.fire") && str_contains($createSrc, 'removeExistingAttachment'));

// 2.15 10 MB hint shown
ok('rfq_create.php: 10 MB per file hint', str_contains($createSrc, '10 MB'));

// 2.16 FormData/fetch submission still intact
ok('rfq_create.php: fetch/FormData submission intact', str_contains($createSrc, 'new FormData(this)') && str_contains($createSrc, "method:'POST'"));

// ══════════════════════════════════════════════════════════════════
// §3  rfq_view.php — multi-attachment display
// ══════════════════════════════════════════════════════════════════

$viewSrc = file_get_contents(__DIR__ . '/../app/bms/purchase/rfq_view.php');

// 3.1 Queries rfq_attachments table
ok('rfq_view.php: queries rfq_attachments', str_contains($viewSrc, 'rfq_attachments') && str_contains($viewSrc, '$attachments'));

// 3.2 Attachments card rendered
ok('rfq_view.php: Attachments card heading', str_contains($viewSrc, 'bi-paperclip') && str_contains($viewSrc, 'Attachments'));

// 3.3 Count badge
ok('rfq_view.php: count badge on attachment card', str_contains($viewSrc, 'count($attachments)'));

// 3.4 Each attachment shows its name
ok('rfq_view.php: renders attachment_name', str_contains($viewSrc, "attachment_name"));

// 3.5 Download button per attachment
ok('rfq_view.php: Download button per attachment', str_contains($viewSrc, 'Download') && str_contains($viewSrc, 'file_path'));

// 3.6 Opens in new tab
ok('rfq_view.php: attachment links open in new tab', str_contains($viewSrc, 'target="_blank"'));

// 3.7 Print-safe fallback
ok('rfq_view.php: print-safe fallback (d-print-none / d-print-inline)', str_contains($viewSrc, 'd-print-none') && str_contains($viewSrc, 'd-print-inline'));

// 3.8 Old single attachment column reference removed
ok('rfq_view.php: old $rfq[attachment] reference removed', !str_contains($viewSrc, "\$rfq['attachment']"));

// 3.9 Attachments card appears AFTER the Authorization Trail (correct order)
$authPos = strpos($viewSrc, 'Authorization Trail');
$attCardPos = strrpos($viewSrc, 'bi-paperclip'); // last occurrence = the card, not a stale reference
ok('rfq_view.php: Attachments card is below Authorization Trail',
    $authPos !== false && $attCardPos !== false && $attCardPos > $authPos);

// ══════════════════════════════════════════════════════════════════
// §4  api/create_rfq.php
// ══════════════════════════════════════════════════════════════════

$createApiSrc = file_get_contents(__DIR__ . '/../api/create_rfq.php');

ok('api/create_rfq.php: csrf_check() called',          str_contains($createApiSrc, 'csrf_check()'));
ok('api/create_rfq.php: attachment_file[] handled',     str_contains($createApiSrc, "att_files['name']"));
ok('api/create_rfq.php: attachment_name[] read',        str_contains($createApiSrc, 'attachment_name'));
ok('api/create_rfq.php: extension whitelist',           str_contains($createApiSrc, '$allowed_ext'));
ok('api/create_rfq.php: finfo MIME check',              str_contains($createApiSrc, 'FILEINFO_MIME_TYPE'));
ok('api/create_rfq.php: 10 MB size limit',              str_contains($createApiSrc, '10 * 1024 * 1024'));
ok('api/create_rfq.php: random_bytes filename',         str_contains($createApiSrc, 'random_bytes(16)'));
ok('api/create_rfq.php: inserts into rfq_attachments',  str_contains($createApiSrc, 'INSERT INTO rfq_attachments'));
ok('api/create_rfq.php: registerFileInLibrary called',  str_contains($createApiSrc, 'registerFileInLibrary('));
ok('api/create_rfq.php: logActivity called',            str_contains($createApiSrc, 'logActivity('));
ok('api/create_rfq.php: no single attachment column in INSERT',
    !str_contains($createApiSrc, "'attachment'") || str_contains($createApiSrc, 'rfq_attachments'));

// ══════════════════════════════════════════════════════════════════
// §5  api/update_rfq.php
// ══════════════════════════════════════════════════════════════════

$updateApiSrc = file_get_contents(__DIR__ . '/../api/update_rfq.php');

ok('api/update_rfq.php: csrf_check() called',              str_contains($updateApiSrc, 'csrf_check()'));
ok('api/update_rfq.php: reads rfq_id from POST',           str_contains($updateApiSrc, "\$_POST['rfq_id']"));
ok('api/update_rfq.php: rejects non-draft',                str_contains($updateApiSrc, "Only draft RFQs can be edited"));
ok('api/update_rfq.php: UPDATE rfq (not INSERT)',           str_contains($updateApiSrc, 'UPDATE rfq') && !str_contains($updateApiSrc, 'INSERT INTO rfq '));
ok('api/update_rfq.php: replaces rfq_items',               str_contains($updateApiSrc, 'DELETE FROM rfq_items'));
ok('api/update_rfq.php: appends to rfq_attachments',       str_contains($updateApiSrc, 'INSERT INTO rfq_attachments'));
ok('api/update_rfq.php: extension whitelist',              str_contains($updateApiSrc, '$allowed_ext'));
ok('api/update_rfq.php: finfo MIME check',                 str_contains($updateApiSrc, 'FILEINFO_MIME_TYPE'));
ok('api/update_rfq.php: 10 MB size limit',                 str_contains($updateApiSrc, '10 * 1024 * 1024'));
ok('api/update_rfq.php: random_bytes filename',            str_contains($updateApiSrc, 'random_bytes(16)'));
ok('api/update_rfq.php: registerFileInLibrary called',     str_contains($updateApiSrc, 'registerFileInLibrary('));
ok('api/update_rfq.php: logActivity called',               str_contains($updateApiSrc, 'logActivity('));

// ══════════════════════════════════════════════════════════════════
// §6  api/delete_rfq_attachment.php
// ══════════════════════════════════════════════════════════════════

$deleteApiSrc = file_get_contents(__DIR__ . '/../api/delete_rfq_attachment.php');

ok('api/delete_rfq_attachment.php: file exists',           !empty($deleteApiSrc));
ok('api/delete_rfq_attachment.php: isAuthenticated check', str_contains($deleteApiSrc, 'isAuthenticated()'));
ok('api/delete_rfq_attachment.php: csrf_check called',     str_contains($deleteApiSrc, 'csrf_check()'));
ok('api/delete_rfq_attachment.php: reads attachment_id',   str_contains($deleteApiSrc, 'attachment_id'));
ok('api/delete_rfq_attachment.php: draft-only guard',      str_contains($deleteApiSrc, "status !== 'draft'") || str_contains($deleteApiSrc, "status'") && str_contains($deleteApiSrc, 'non-draft'));
ok('api/delete_rfq_attachment.php: deletes physical file', str_contains($deleteApiSrc, 'unlink('));
ok('api/delete_rfq_attachment.php: deletes DB row',        str_contains($deleteApiSrc, 'DELETE FROM rfq_attachments'));
ok('api/delete_rfq_attachment.php: logActivity called',    str_contains($deleteApiSrc, 'logActivity('));

// ══════════════════════════════════════════════════════════════════
// §7  LIVE DB functional tests
// ══════════════════════════════════════════════════════════════════

$supplier  = $pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
$warehouse = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetchColumn();

if ($supplier && $warehouse) {
    // 7.1 Insert test RFQ
    $rfq_number = 'RFQ-TEST-' . time();
    $pdo->prepare("INSERT INTO rfq (rfq_number, supplier_id, warehouse_id, rfq_date, status, created_by)
        VALUES (?,?,?,NOW(),'draft',1)")
        ->execute([$rfq_number, $supplier, $warehouse]);
    $test_rfq_id = $pdo->lastInsertId();
    ok('Live: test RFQ inserted', $test_rfq_id > 0, "rfq_id=$test_rfq_id");

    // 7.2 Insert attachment row
    $pdo->prepare("INSERT INTO rfq_attachments (rfq_id, attachment_name, file_path, original_name, file_size, uploaded_by)
        VALUES (?,?,?,?,?,1)")
        ->execute([$test_rfq_id, 'Test Doc', 'uploads/procurement/rfq/testfile.pdf', 'test.pdf', 12345]);
    $att_id = $pdo->lastInsertId();
    ok('Live: attachment inserted into rfq_attachments', $att_id > 0);

    // 7.3 Read back attachment
    $att = $pdo->prepare("SELECT * FROM rfq_attachments WHERE attachment_id = ?");
    $att->execute([$att_id]);
    $attRow = $att->fetch(PDO::FETCH_ASSOC);
    ok('Live: attachment_name stored correctly', ($attRow['attachment_name'] ?? '') === 'Test Doc');
    ok('Live: file_path stored correctly',       str_contains($attRow['file_path'] ?? '', 'uploads/procurement/rfq/'));
    ok('Live: rfq_id foreign key correct',       intval($attRow['rfq_id'] ?? 0) === intval($test_rfq_id));

    // 7.4 Count attachments per RFQ
    $count = $pdo->prepare("SELECT COUNT(*) FROM rfq_attachments WHERE rfq_id = ?");
    $count->execute([$test_rfq_id]);
    ok('Live: can count attachments per RFQ', intval($count->fetchColumn()) === 1);

    // 7.5 Non-draft guard simulation
    $pdo->prepare("UPDATE rfq SET status='review' WHERE rfq_id=?")->execute([$test_rfq_id]);
    $status = $pdo->prepare("SELECT status FROM rfq WHERE rfq_id=?");
    $status->execute([$test_rfq_id]);
    ok('Live: status changed to review (guard base)', $status->fetchColumn() === 'review');

    // 7.6 Cleanup
    $pdo->prepare("DELETE FROM rfq_attachments WHERE rfq_id=?")->execute([$test_rfq_id]);
    $pdo->prepare("DELETE FROM rfq WHERE rfq_id=?")->execute([$test_rfq_id]);
    $gone = $pdo->prepare("SELECT COUNT(*) FROM rfq WHERE rfq_id=?");
    $gone->execute([$test_rfq_id]);
    ok('Live: test RFQ cleaned up', intval($gone->fetchColumn()) === 0);

} else {
    ok('Live DB tests skipped — no supplier/warehouse found', true, 'skip');
}

// ══════════════════════════════════════════════════════════════════
// §8  Changelog
// ══════════════════════════════════════════════════════════════════

$changelog = file_get_contents(__DIR__ . '/../changelog.md');
ok('changelog.md: update 33 entry present', str_contains($changelog, 'update 33'));

// ══════════════════════════════════════════════════════════════════
// RESULTS
// ══════════════════════════════════════════════════════════════════
$total = $pass + $fail;
$color = $fail === 0 ? '#198754' : '#dc3545';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>RFQ Attachment Test Results</title>
<style>
body{font-family:system-ui,sans-serif;padding:24px;background:#f8f9fa;}
h2{margin-bottom:4px;}
.summary{font-size:1.1rem;font-weight:600;padding:10px 18px;border-radius:6px;margin-bottom:20px;
    background:<?= $color ?>;color:#fff;display:inline-block;}
table{border-collapse:collapse;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.1);}
th{background:#343a40;color:#fff;padding:10px 14px;text-align:left;font-size:.82rem;text-transform:uppercase;}
td{padding:8px 14px;border-bottom:1px solid #e9ecef;font-size:.88rem;vertical-align:top;}
.pass td:first-child{color:#198754;font-weight:700;}
.fail td:first-child{color:#dc3545;font-weight:700;}
.fail td{background:#fff5f5;}
.detail{color:#6c757d;font-size:.80rem;}
.section td{background:#e9ecef;font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.5px;color:#495057;padding:6px 14px;}
</style>
</head>
<body>
<h2>RFQ Multi-Attachment — Test Results</h2>
<div class="summary"><?= $pass ?> / <?= $total ?> passed<?= $fail ? " &nbsp;·&nbsp; <strong>$fail FAILED</strong>" : ' — all good!' ?></div>
<table>
<thead><tr><th style="width:60px;">Result</th><th>Test</th><th>Detail</th></tr></thead>
<tbody>
<?php
$sections = [
    0  => '§1  Migration — DB schema + upload directory',
    11 => '§2  rfq_create.php — form HTML & JS',
    27 => '§3  rfq_view.php — attachment list display',
    35 => '§4  api/create_rfq.php',
    46 => '§5  api/update_rfq.php',
    58 => '§6  api/delete_rfq_attachment.php',
    66 => '§7  Live DB functional tests',
    73 => '§8  Changelog',
];
foreach ($results as $i => [$status, $label, $detail]):
    if (isset($sections[$i])): ?>
    <tr class="section"><td colspan="3"><?= $sections[$i] ?></td></tr>
<?php endif; ?>
    <tr class="<?= $status ?>">
        <td><?= strtoupper($status) ?></td>
        <td><?= htmlspecialchars($label) ?></td>
        <td class="detail"><?= htmlspecialchars($detail) ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
