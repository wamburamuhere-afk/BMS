<?php
/**
 * Delivery Note — CLI Test Suite
 * Validates the Record (inbound) vs Create (outbound) Delivery Note design:
 * manual DN number + supplier/sub-contractor selection + mandatory named
 * multi-attachments for inbound; auto number + Sales-side/Customer-only party
 * + optional (Project-conditional) named multi-attachments for outbound;
 * separate list tabs.
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
function ok(string $msg): void   { global $pass; $pass++; echo "\033[32m  OK\033[0m $msg\n"; }
function fail(string $msg): void { global $fail; $fail++; echo "\033[31m  XX\033[0m $msg\n"; }
function section(string $t): void { echo "\n\033[1m-- $t --\033[0m\n"; }
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
    'app/bms/grn/dn_outbound.php',
    'app/bms/grn/dn_view.php',
    'app/bms/grn/delivery_notes.php',
    'api/create_dn.php',
    'api/update_dn.php',
    'api/get_delivery_notes_list.php',
    'api/dn_attachment_helper.php',
    'api/delete_dn_attachment.php',
    'api/account/print_delivery_note.php',
    'migrations/2026_05_21_dn_record_vs_create.php',
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
$create   = readSrc('app/bms/grn/dn_create.php');
$outbound = readSrc('app/bms/grn/dn_outbound.php');
$view     = readSrc('app/bms/grn/dn_view.php');
$list     = readSrc('app/bms/grn/delivery_notes.php');
$apiC     = readSrc('api/create_dn.php');
$apiU     = readSrc('api/update_dn.php');
$apiL     = readSrc('api/get_delivery_notes_list.php');
$helper   = readSrc('api/dn_attachment_helper.php');
$print    = readSrc('api/account/print_delivery_note.php');
$migr     = readSrc('migrations/2026_05_21_dn_record_vs_create.php');

// ── 3. Migration adds the new columns ─────────────────────────────────────────
section('3. Migration — direction & party columns');
has($migr, 'dn_type',          'migration: adds dn_type column');
has($migr, 'party_type',       'migration: adds party_type column');
has($migr, 'subcontractor_id', 'migration: adds subcontractor_id column');

// ── 4. Record DN (inbound) — manual number, party toggle, attachments ────────
section('4. Record DN form (inbound)');
has($create, 'name="dn_number"',          'dn_create.php: manual DN number input present');
has($create, "fd.append('dn_type', 'inbound')", 'dn_create.php: submits dn_type=inbound');
has($create, 'name="party_type"',         'dn_create.php: supplier/sub-contractor toggle present');
has($create, 'name="party_id"',           'dn_create.php: specific party selector present');
has($create, 'ALL_SUBCONTRACTORS',        'dn_create.php: loads sub-contractor list');
has($create, 'function addAttachmentRow', 'dn_create.php: addAttachmentRow() present');
has($create, 'function removeAttachmentRow', 'dn_create.php: removeAttachmentRow() present');
has($create, 'attachment_name[]',         'dn_create.php: attachment name field present');
has($create, 'attachment_file[]',         'dn_create.php: attachment file field present');
has($create, 'Add Attachment',            'dn_create.php: "Add Attachment" button present');
hasNot($create, 'bg-success',             'dn_create.php: no green theme (uses blue)');

// ── 5. Create DN (outbound) — auto number, Sales-side/Customer-only,
//    optional reference-document attachment (mandatory only with no Project) ─
section('5. Create DN form (outbound)');
has($outbound, "fd.append('dn_type', 'outbound')", 'dn_outbound.php: submits dn_type=outbound');
has($outbound, 'name="party_type"',       'dn_outbound.php: party_type field present (Customer-only — see $party_field_locked)');
has($outbound, 'Generated automatically', 'dn_outbound.php: DN number shown as auto-generated');
has($outbound, 'attachment_file[]',       'dn_outbound.php: reference-document attachment upload present (optional / Project-conditional)');

// ── 6. Create API handles both directions ────────────────────────────────────
section('6. create_dn.php — direction-aware');
has($apiC, "dn_type",                       'create_dn.php: reads dn_type');
has($apiC, 'party_type',                    'create_dn.php: reads party_type');
has($apiC, 'subcontractor_id',              'create_dn.php: stores subcontractor_id');
has($apiC, 'sub_contractors',               'create_dn.php: validates against sub_contractors');
has($apiC, 'dn_collect_attachment_pairs',   'create_dn.php: collects named attachments');
has($apiC, "nextCode(\$pdo, 'DN')",          'create_dn.php: auto-generates internal number (company-prefixed sequential)');
has($apiC, 'beginTransaction',              'create_dn.php: transaction present');
has($apiC, 'logActivity',                   'create_dn.php: logActivity present');

// ── 7. Update API handles both directions ────────────────────────────────────
section('7. update_dn.php — direction-aware');
has($apiU, 'party_type',                    'update_dn.php: reads party_type');
has($apiU, 'subcontractor_id',              'update_dn.php: stores subcontractor_id');
has($apiU, 'DELETE FROM delivery_items',    'update_dn.php: replaces items on update');
has($apiU, 'dn_collect_attachment_pairs',   'update_dn.php: appends named attachments');
has($apiU, 'logActivity',                   'update_dn.php: logActivity present');

// ── 8. Attachment helper — named, multi-file, 5-check security ────────────────
section('8. Attachment helper');
has($helper, 'function dn_collect_attachment_pairs', 'helper: dn_collect_attachment_pairs() present');
has($helper, 'function dn_save_attachments',         'helper: dn_save_attachments() present');
has($helper, 'finfo',                                'helper: real MIME check present');
has($helper, 'random_bytes',                         'helper: non-guessable filename');
has($helper, 'uploads/deliveries/',                  'helper: stores under uploads/ folder');
has($helper, 'registerFileInLibrary',                'helper: registers in document library');

// ── 9. List page — separate inbound/outbound tabs ────────────────────────────
section('9. List page — separate lists');
has($list, 'dnTypeTabs',           'delivery_notes.php: inbound/outbound tabs present');
has($list, 'currentDnType',        'delivery_notes.php: tracks active tab');
has($list, 'data-dntype="inbound"',  'delivery_notes.php: inbound tab');
has($list, 'data-dntype="outbound"', 'delivery_notes.php: outbound tab');
has($list, 'dnTypeBadge',          'delivery_notes.php: type badge renderer');
has($list, 'dnEditUrl',            'delivery_notes.php: direction-aware edit links');
has($apiL, "d.dn_type = ?",        'get_delivery_notes_list.php: filters by dn_type');
has($apiL, 'sub_contractors',      'get_delivery_notes_list.php: joins sub_contractors');
has($apiL, 'type_counts',          'get_delivery_notes_list.php: returns per-tab counts');

// ── 10. View & print — both directions ───────────────────────────────────────
section('10. View & print');
has($view, 'dn_type',              'dn_view.php: direction-aware');
has($view, 'delivery_attachments', 'dn_view.php: shows attachments');
has($view, 'sub_contractors',      'dn_view.php: resolves sub-contractor party');
has($view, 'function changeDNStatus', 'dn_view.php: changeDNStatus() intact');
has($view, 'function deleteDN',    'dn_view.php: deleteDN() intact');
has($print, 'sub_contractors',     'print_delivery_note.php: resolves sub-contractor party');
has($print, 'is_inbound',          'print_delivery_note.php: direction-aware labels');

// ── 11. Core DN form logic still intact ──────────────────────────────────────
section('11. Core DN logic intact');
has($create, 'function addDNItem',          'dn_create.php: addDNItem() intact');
has($create, 'function submitDN',           'dn_create.php: submitDN() intact');
has($create, 'function loadWarehouseStock', 'dn_create.php: loadWarehouseStock() intact');
has($outbound, 'function addDNItem',        'dn_outbound.php: addDNItem() intact');
has($outbound, 'function submitDN',         'dn_outbound.php: submitDN() intact');

// ── Result ────────────────────────────────────────────────────────────────────
echo "\n\033[1m" . str_repeat('=', 40) . "\033[0m\n";
if ($fail === 0) {
    echo "\033[32mAll $pass tests passed — safe to push.\033[0m\n";
} else {
    echo "\033[31m$fail test(s) failed out of " . ($pass + $fail) . " — push blocked.\033[0m\n";
}
echo "\033[1m" . str_repeat('=', 40) . "\033[0m\n\n";
exit($fail > 0 ? 1 : 0);
