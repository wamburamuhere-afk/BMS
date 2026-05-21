<?php
/**
 * Document Signing Wizard — CLI Test Suite
 * Tests: API endpoint correctness, no broken paths, no invalid SQL columns,
 *        wizard JS structure, and all backend files present.
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
    'api/get_documents.php',
    'api/document/get_user_signatures_list.php',
    'api/document/apply_signature.php',
    'api/document/quick_upload_document.php',
    'app/constant/document/select_document_add_esignature.php',
];
foreach ($files as $f) {
    file_exists($ROOT . '/' . $f) ? ok($f) : fail("MISSING: $f");
}

// ── 2. PHP syntax — all wizard files ─────────────────────────────────────────
section('2. PHP syntax — all wizard files');
foreach ($files as $f) {
    fileSyntaxOk($f);
}

// ── Load sources ──────────────────────────────────────────────────────────────
$getDocApi    = readSrc('api/get_documents.php');
$sigListApi   = readSrc('api/document/get_user_signatures_list.php');
$applyApi     = readSrc('api/document/apply_signature.php');
$uploadApi    = readSrc('api/document/quick_upload_document.php');
$wizard       = readSrc('app/constant/document/select_document_add_esignature.php');

// ── 3. api/get_documents.php — SQL correctness ────────────────────────────────
section('3. api/get_documents.php — SQL correctness');
hasNot($getDocApi, "d.status",              'get_documents.php: no d.status column (documents table has no status column)');
hasNot($getDocApi, "status != 'deleted'",   'get_documents.php: no status filter (column does not exist)');
has($getDocApi,   'WHERE 1=1',              'get_documents.php: uses WHERE 1=1 base clause');
has($getDocApi,   'document_name',          'get_documents.php: selects document_name');
has($getDocApi,   'file_path',              'get_documents.php: selects file_path');
has($getDocApi,   'file_size',              'get_documents.php: selects file_size');
has($getDocApi,   'uploaded_at',            'get_documents.php: selects uploaded_at');
has($getDocApi,   'category_name',          'get_documents.php: selects category_name via JOIN');
has($getDocApi,   'LEFT JOIN document_categories', 'get_documents.php: joins document_categories');
has($getDocApi,   'recordsTotal',           'get_documents.php: returns recordsTotal');
has($getDocApi,   'recordsFiltered',        'get_documents.php: returns recordsFiltered');
has($getDocApi,   'PDO::PARAM_INT',         'get_documents.php: LIMIT params bound as INT');
has($getDocApi,   '$_SESSION[\'user_id\']', 'get_documents.php: auth check present');

// ── 4. Wizard — no hardcoded absolute paths ───────────────────────────────────
section('4. Wizard — no hardcoded absolute paths');
hasNot($wizard, "url: '/api/",                      'wizard: no hardcoded /api/ URL in DataTable ajax');
hasNot($wizard, "$.get('/ajax/",                    'wizard: no hardcoded /ajax/ in loadSignatures()');
hasNot($wizard, "$.post('/ajax/",                   'wizard: no hardcoded /ajax/ in processFinalSign()');
hasNot($wizard, "url: '/ajax/",                     'wizard: no hardcoded /ajax/ in quick upload');
hasNot($wizard, "documents/library?action=download",'wizard: no wrong documents/library route');

// ── 5. Wizard — correct API paths via buildUrl() ──────────────────────────────
section('5. Wizard — correct API paths via buildUrl()');
has($wizard, 'buildUrl("api/get_documents.php")',                   'wizard: DataTable uses buildUrl(api/get_documents.php)');
has($wizard, 'buildUrl("api/document/get_user_signatures_list.php")', 'wizard: loadSignatures uses correct buildUrl path');
has($wizard, 'buildUrl("api/document/apply_signature.php")',        'wizard: processFinalSign uses correct buildUrl path');
has($wizard, 'buildUrl("api/document/quick_upload_document.php")',  'wizard: quick upload uses correct buildUrl path');
has($wizard, 'buildUrl("document_library")',                        'wizard: download URL uses buildUrl(document_library)');

// ── 6. Wizard — JS bugs fixed ─────────────────────────────────────────────────
section('6. Wizard — JS bugs fixed');
hasNot($wizard, 'event.currentTarget',  'wizard: event.currentTarget removed from selectSignature()');
has($wizard,    'selectSignature(${sig.id}',  'wizard: selectSignature called in loadSignatures()');
has($wizard,    ', this)',              'wizard: this passed to selectSignature onclick');
has($wizard,    'function selectSignature(id, path, el)', 'wizard: selectSignature accepts el parameter');
has($wizard,    '$(el).addClass',      'wizard: selectSignature uses $(el) not $(event.currentTarget)');

// ── 7. Wizard — step navigation fixed ────────────────────────────────────────
section('7. Wizard — step navigation fixed');
has($wizard,   'dir > 0',              'wizard: changeStep distinguishes forward vs backward');
has($wizard,   "removeClass('completed').addClass('active')", 'wizard: backward nav removes completed from target step');
has($wizard,   "toggleClass('d-none', onLast)", 'wizard: btnBack toggles d-none correctly');

// ── 8. Wizard — setPresetPosition repositions the element ────────────────────
section('8. Wizard — setPresetPosition repositions draggable');
has($wizard,   'sign-placement-area',  'wizard: setPresetPosition reads parent dimensions');
has($wizard,   'outerWidth',           'wizard: setPresetPosition reads signature element width');
has($wizard,   'outerHeight',          'wizard: setPresetPosition reads signature element height');
has($wizard,   'bottom_left',          'wizard: setPresetPosition handles bottom_left');
has($wizard,   'bottom_center',        'wizard: setPresetPosition handles bottom_center');
has($wizard,   'bottom_right',         'wizard: setPresetPosition handles bottom_right');
has($wizard,   "attr('data-x', x)",    'wizard: setPresetPosition updates data-x attribute');
has($wizard,   "attr('data-y', y)",    'wizard: setPresetPosition updates data-y attribute');
hasNot($wizard,'Signature will be placed at', 'wizard: misleading Swal toast removed from setPresetPosition');

// ── 9. Backend API files — core logic intact ─────────────────────────────────
section('9. Backend API files — core logic intact');
has($sigListApi, 'user_signatures',    'get_user_signatures_list.php: queries user_signatures table');
has($sigListApi, 'user_id',            'get_user_signatures_list.php: filters by user_id');
has($sigListApi, 'thumbnail_path',     'get_user_signatures_list.php: returns thumbnail_path');
has($applyApi,   'document_signatures','apply_signature.php: writes to document_signatures table');
has($applyApi,   'csrf_check',         'apply_signature.php: CSRF check present');
has($applyApi,   'logActivity',        'apply_signature.php: logActivity present');
has($uploadApi,  'document_name',      'quick_upload_document.php: uses document_name from POST');
has($uploadApi,  'document_id',        'quick_upload_document.php: returns document_id');
has($uploadApi,  'file_path',          'quick_upload_document.php: returns file_path');

// ── Result ────────────────────────────────────────────────────────────────────
echo "\n\033[1m" . str_repeat('═', 40) . "\033[0m\n";
if ($fail === 0) {
    echo "\033[32m✅ All $pass tests passed — safe to push.\033[0m\n";
} else {
    echo "\033[31m❌ $fail test(s) failed out of " . ($pass + $fail) . " — push blocked.\033[0m\n";
}
echo "\033[1m" . str_repeat('═', 40) . "\033[0m\n\n";
exit($fail > 0 ? 1 : 0);
