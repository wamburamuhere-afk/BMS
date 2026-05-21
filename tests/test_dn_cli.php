<?php
/**
 * Delivery Note — CLI Test Suite
 * Tests: attachment removal (create form, view, APIs), DN number auto-generation,
 *        and all core DN logic remaining intact across 5 files.
 * Exit 0 = all pass | Exit 1 = one or more failures (blocks git push)
 */

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function readSrc(string $path): string {
    global $ROOT;
    $full = $ROOT . '/' . ltrim($path, '/');
    return file_exists($full) ? file_get_contents($full) : '';
}
function ok(string $msg): void   { global $pass; $pass++; echo "\033[32m  ✅\033[0m $msg\n"; }
function fail(string $msg): void { global $fail; $fail++; echo "\033[31m  ❌\033[0m $msg\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $src, string $needle, string $label): void {
    strpos($src, $needle) !== false ? ok($label) : fail($label);
}
function hasNot(string $src, string $needle, string $label): void {
    strpos($src, $needle) === false ? ok($label) : fail($label);
}
function fileSyntaxOk(string $rel): void {
    global $ROOT;
    $out = shell_exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1');
    strpos($out, 'No syntax errors') !== false ? ok("Syntax OK: $rel") : fail("Syntax ERROR: $rel — $out");
}

// ── 1. Required files exist ───────────────────────────────────────────────────
section('1. Required files exist');
$files = [
    'app/bms/grn/dn_create.php',
    'app/bms/grn/dn_view.php',
    'api/create_dn.php',
    'api/update_dn.php',
    'api/account/print_delivery_note.php',
];
foreach ($files as $f) {
    file_exists($ROOT . '/' . $f) ? ok($f) : fail("MISSING: $f");
}

// ── 2. PHP syntax — all DN files ─────────────────────────────────────────────
section('2. PHP syntax — all DN files');
foreach ($files as $f) {
    fileSyntaxOk($f);
}

// ── Load sources ──────────────────────────────────────────────────────────────
$create  = readSrc('app/bms/grn/dn_create.php');
$view    = readSrc('app/bms/grn/dn_view.php');
$apiC    = readSrc('api/create_dn.php');
$apiU    = readSrc('api/update_dn.php');
$print   = readSrc('api/account/print_delivery_note.php');

// ── 3. DN Number: manual input removed everywhere ─────────────────────────────
section('3. DN Number — manual input fully removed');
hasNot($create, 'name="dn_number"',           'dn_create.php: no manual dn_number <input>');
hasNot($create, "formData.append('dn_number'", 'dn_create.php: no dn_number in submitDN() FormData');
hasNot($apiC,   '$dn_number_input',            'create_dn.php: $dn_number_input variable removed');
hasNot($apiU,   '$dn_number_input',            'update_dn.php: $dn_number_input variable removed');
hasNot($apiU,   'dn_number=?',                 'update_dn.php: dn_number=? removed from UPDATE query');

// ── 4. DN Number: auto-generated number shown correctly ───────────────────────
section('4. DN Number — auto-generation intact and displayed correctly');
has($apiC,   "DN-' . date('Ymd')",             'create_dn.php: auto-generates delivery_number (DN-YYYYMMDD pattern)');
has($apiC,   'delivery_number',                 'create_dn.php: delivery_number still in INSERT');
has($create, "\$dn['delivery_number']",         'dn_create.php: shows delivery_number as read-only in edit mode');
has($create, 'Auto-generated',                  'dn_create.php: shows "Auto-generated" label under read-only number');
has($view,   'delivery_number',                 'dn_view.php: displays delivery_number in view');
has($print,  'delivery_number',                 'print_delivery_note.php: uses delivery_number in print header');
hasNot($print, 'dn_number',                     'print_delivery_note.php: does not reference manual dn_number field');

// ── 5. Attachments removed from create form (dn_create.php) ──────────────────
section('5. Attachments — removed from create/edit form');
hasNot($create, 'delivery_attachments',         'dn_create.php: no delivery_attachments DB query');
hasNot($create, '$dn_attachments',              'dn_create.php: $dn_attachments variable gone');
hasNot($create, 'Attachments & Documents',      'dn_create.php: attachment card heading gone');
hasNot($create, 'addAttachmentRow',             'dn_create.php: addAttachmentRow() JS function gone');
hasNot($create, 'removeAttachmentRow',          'dn_create.php: removeAttachmentRow() JS function gone');
hasNot($create, 'handleFileSelect',             'dn_create.php: handleFileSelect() JS function gone');
hasNot($create, 'attachment_names[]',           'dn_create.php: attachment_names[] input gone');
hasNot($create, 'existing_attachment',          'dn_create.php: existing_attachment handling gone');
hasNot($create, 'bi-paperclip',                 'dn_create.php: paperclip icon (attachment card) gone');

// ── 6. Attachments removed from view (dn_view.php) ───────────────────────────
section('6. Attachments — removed from view page');
hasNot($view, 'delivery_attachments',           'dn_view.php: no delivery_attachments query');
hasNot($view, '$attachments',                   'dn_view.php: $attachments variable gone');
hasNot($view, 'Documents & Attachments',        'dn_view.php: attachment card heading gone');
hasNot($view, 'No documents attached',          'dn_view.php: empty-state message gone');
hasNot($view, 'bi-paperclip',                   'dn_view.php: paperclip icon (attachment card) gone');
hasNot($view, 'format_bytes',                   'dn_view.php: format_bytes() call gone');

// ── 7. Attachments removed from create API (api/create_dn.php) ───────────────
section('7. Attachments — removed from create API');
hasNot($apiC, 'delivery_attachments',           'create_dn.php: no INSERT into delivery_attachments');
hasNot($apiC, 'attachment_names',               'create_dn.php: no attachment_names POST handling');
hasNot($apiC, "uploads/procurement/delivery_notes", 'create_dn.php: no attachment upload dir reference');

// ── 8. Attachments removed from update API (api/update_dn.php) ───────────────
section('8. Attachments — removed from update API');
hasNot($apiU, 'delivery_attachments',           'update_dn.php: no delivery_attachments queries');
hasNot($apiU, 'delete_attachment_ids',          'update_dn.php: 3a delete block gone');
hasNot($apiU, 'existing_attachment_ids',        'update_dn.php: 3b replace block gone');
hasNot($apiU, 'replace_attachments',            'update_dn.php: file replacement logic gone');
hasNot($apiU, 'attachment_names',               'update_dn.php: 3c add-new block gone');

// ── 9. Core DN logic still intact ────────────────────────────────────────────
section('9. Core DN logic still intact');
// create API
has($apiC, 'Validate warehouse',                'create_dn.php: warehouse validation present');
has($apiC, 'Validate supplier',                 'create_dn.php: supplier validation present');
has($apiC, 'Insert items',                      'create_dn.php: items loop present');
has($apiC, 'logActivity',                       'create_dn.php: logActivity call present');
has($apiC, 'beginTransaction',                  'create_dn.php: transaction present');
// update API
has($apiU, 'DELETE FROM delivery_items',        'update_dn.php: old items deleted on update');
has($apiU, 'INSERT INTO delivery_items',        'update_dn.php: new items inserted on update');
has($apiU, 'logActivity',                       'update_dn.php: logActivity call present');
// create form JS
has($create, 'function addDNItem',              'dn_create.php: addDNItem() JS function intact');
has($create, 'function submitDN',               'dn_create.php: submitDN() JS function intact');
has($create, 'function loadWarehouseStock',     'dn_create.php: loadWarehouseStock() intact');
has($create, 'select2',                         'dn_create.php: Select2 still initialised');
// view page
has($view, 'function changeDNStatus',           'dn_view.php: changeDNStatus() JS function intact');
has($view, 'function deleteDN',                 'dn_view.php: deleteDN() JS function intact');
has($view, 'dnItemsViewTable',                  'dn_view.php: items DataTable intact');

// ── Result ────────────────────────────────────────────────────────────────────
echo "\n\033[1m" . str_repeat('═', 40) . "\033[0m\n";
if ($fail === 0) {
    echo "\033[32m✅ All $pass tests passed — safe to push.\033[0m\n";
} else {
    echo "\033[31m❌ $fail test(s) failed out of " . ($pass + $fail) . " — push blocked.\033[0m\n";
}
echo "\033[1m" . str_repeat('═', 40) . "\033[0m\n\n";
exit($fail > 0 ? 1 : 0);
