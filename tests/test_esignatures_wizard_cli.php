<?php
/**
 * Document Signing Wizard — CLI Test Suite
 * Tests: API endpoint correctness, no broken paths, no invalid SQL columns,
 *        wizard JS structure, pdf-lib embedding logic, and all backend files.
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
function fileExists_(string $rel): void {
    global $ROOT;
    file_exists($ROOT . '/' . $rel) ? ok($rel) : fail("MISSING: $rel");
}

// ── 1. Required files exist ───────────────────────────────────────────────────
section('1. Required files exist');
$phpFiles = [
    'api/get_documents.php',
    'api/document/get_user_signatures_list.php',
    'api/document/apply_signature.php',
    'api/document/quick_upload_document.php',
    'api/document/save_signed_pdf.php',
    'api/document/upload_signature.php',
    'api/document/verify_signed_document.php',
    'app/constant/document/select_document_add_esignature.php',
];
foreach ($phpFiles as $f) {
    fileExists_($f);
}
fileExists_('assets/js/pdf-lib.min.js');

// ── 2. PHP syntax — all wizard files ─────────────────────────────────────────
section('2. PHP syntax — all wizard PHP files');
foreach ($phpFiles as $f) {
    fileSyntaxOk($f);
}

// ── Load sources ──────────────────────────────────────────────────────────────
$getDocApi    = readSrc('api/get_documents.php');
$sigListApi   = readSrc('api/document/get_user_signatures_list.php');
$applyApi     = readSrc('api/document/apply_signature.php');
$uploadApi    = readSrc('api/document/quick_upload_document.php');
$saveApi      = readSrc('api/document/save_signed_pdf.php');
$wizard       = readSrc('app/constant/document/select_document_add_esignature.php');
// The SHA-256 helper, Certificate of Completion generator, and the
// "draw signature + label" routine now live here — shared with
// create_document.php's one-click Save & Sign path so both produce
// byte-for-byte the same certificate/hash logic instead of two copies
// that could drift apart. The wizard calls into these, it no longer
// defines them inline.
$esignShared  = readSrc('assets/js/bms-esign-shared.js');

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
hasNot($wizard, "url: '/api/",                       'wizard: no hardcoded /api/ URL in DataTable ajax');
hasNot($wizard, "$.get('/ajax/",                     'wizard: no hardcoded /ajax/ in loadSignatures()');
hasNot($wizard, "$.post('/ajax/",                    'wizard: no hardcoded /ajax/ path used');
hasNot($wizard, "url: '/ajax/",                      'wizard: no hardcoded /ajax/ in quick upload');
hasNot($wizard, "documents/library?action=download", 'wizard: no wrong documents/library route');

// ── 5. Wizard — correct API paths via buildUrl() ──────────────────────────────
section('5. Wizard — correct API paths via buildUrl()');
has($wizard, 'buildUrl("api/get_documents.php")',                    'wizard: DataTable uses buildUrl(api/get_documents.php)');
has($wizard, 'buildUrl("api/document/get_user_signatures_list.php")', 'wizard: loadSignatures uses correct buildUrl path');
has($wizard, 'buildUrl("api/document/save_signed_pdf.php")',         'wizard: embedSignatureIntoPdf uses save_signed_pdf buildUrl');
has($wizard, 'buildUrl("api/document/verify_signed_document.php")',  'wizard: verify uses correct buildUrl path');
has($wizard, 'buildUrl("api/document/quick_upload_document.php")',   'wizard: quick upload uses correct buildUrl path');
has($wizard, 'buildUrl("document_library")',                         'wizard: download URL uses buildUrl(document_library)');

// ── 6. Wizard — JS bugs fixed ─────────────────────────────────────────────────
section('6. Wizard — JS bugs fixed');
hasNot($wizard, 'event.currentTarget',              'wizard: event.currentTarget removed from selectSignature()');
has($wizard,    'selectSignature(${sig.id}',        'wizard: selectSignature called in loadSignatures()');
has($wizard,    ', this)',                           'wizard: this passed to selectSignature onclick');
has($wizard,    'function selectSignature(id, path, el)', 'wizard: selectSignature accepts el parameter');
has($wizard,    '$(el).addClass',                   'wizard: selectSignature uses $(el) not $(event.currentTarget)');

// ── 7. Wizard — step navigation fixed ────────────────────────────────────────
section('7. Wizard — step navigation fixed');
has($wizard,   'dir > 0',                               'wizard: changeStep distinguishes forward vs backward');
has($wizard,   "removeClass('completed').addClass('active')", 'wizard: backward nav removes completed from target step');
has($wizard,   "toggleClass('d-none', onLast)",         'wizard: btnBack toggles d-none correctly');

// ── 8. Wizard — setPresetPosition repositions the element ────────────────────
section('8. Wizard — setPresetPosition repositions draggable');
has($wizard,    'sign-placement-area',       'wizard: setPresetPosition reads parent dimensions');
has($wizard,    'outerWidth',                'wizard: setPresetPosition reads signature element width');
has($wizard,    'outerHeight',               'wizard: setPresetPosition reads signature element height');
has($wizard,    'bottom_left',               'wizard: setPresetPosition handles bottom_left');
has($wizard,    'bottom_center',             'wizard: setPresetPosition handles bottom_center');
has($wizard,    'bottom_right',              'wizard: setPresetPosition handles bottom_right');
has($wizard,    "attr('data-x', x)",         'wizard: setPresetPosition updates data-x attribute');
has($wizard,    "attr('data-y', y)",         'wizard: setPresetPosition updates data-y attribute');
hasNot($wizard, 'Signature will be placed at', 'wizard: misleading Swal toast removed from setPresetPosition');

// ── 9. Backend API files — core logic intact ─────────────────────────────────
section('9. Backend API files — core logic intact');
has($sigListApi, 'user_signatures',  'get_user_signatures_list.php: queries user_signatures table');
has($sigListApi, 'user_id',          'get_user_signatures_list.php: filters by user_id');
has($sigListApi, 'thumbnail_path',   'get_user_signatures_list.php: returns thumbnail_path');
has($applyApi,   'document_signatures', 'apply_signature.php: writes to document_signatures table');
has($applyApi,   'csrf_check',       'apply_signature.php: CSRF check present');
has($applyApi,   'logActivity',      'apply_signature.php: logActivity present');
has($uploadApi,  'document_name',    'quick_upload_document.php: uses document_name from POST');
has($uploadApi,  'document_id',      'quick_upload_document.php: returns document_id');
has($uploadApi,  'file_path',        'quick_upload_document.php: returns file_path');

// ── 10. pdf-lib.js asset present and wired ───────────────────────────────────
section('10. pdf-lib.js asset — present and wired into wizard');
$pdfLibPath = $ROOT . '/assets/js/pdf-lib.min.js';
if (file_exists($pdfLibPath)) {
    ok('assets/js/pdf-lib.min.js exists');
    $size = filesize($pdfLibPath);
    $size > 100000 ? ok('pdf-lib.min.js size > 100 KB (not an empty stub)') : fail('pdf-lib.min.js too small — may be corrupt');
} else {
    fail('MISSING: assets/js/pdf-lib.min.js');
    fail('pdf-lib.min.js size check skipped');
}
has($wizard, "assets/js/pdf-lib.min.js",    'wizard: pdf-lib.min.js <script> tag present');
has($wizard, 'pdf.min.js',                  'wizard: pdf.min.js (PDF.js) still present');
has($wizard, 'assets/js/bms-esign-shared.js', 'wizard: bms-esign-shared.js <script> tag present');

// ── 11. PDF embedding logic — wizard JS ──────────────────────────────────────
section('11. PDF embedding logic — wizard JS');
has($wizard, 'async function processFinalSign',      'wizard: processFinalSign is async');
has($wizard, 'async function embedSignatureIntoPdf', 'wizard: embedSignatureIntoPdf function present');
hasNot($wizard, 'function recordSignatureOnly',      'wizard: misleading non-PDF recordSignatureOnly removed');
hasNot($wizard, 'function uint8ToBase64',            'wizard: dead uint8ToBase64 helper removed');
has($wizard, 'PDFLib.PDFDocument.load',              'wizard: loads existing PDF with pdf-lib');
has($wizard, 'pdfLibDoc.getPage',                    'wizard: retrieves target page from PDF');
has($wizard, 'pdfPage.getSize',                      'wizard: reads page dimensions for coordinate conversion');
has($wizard, 'embedPng',                             'wizard: embeds PNG signature images');
has($wizard, 'embedJpg',                             'wizard: embeds JPG signature images');
has($wizard, 'bmsDrawSignatureWithLabel',            'wizard: draws signature onto PDF page (via shared helper)');
has($esignShared, 'pdfPage.drawImage',               'bms-esign-shared.js: signature draw routine present');
has($wizard, 'pdfRenderScale',                       'wizard: uses PDF.js render scale for coordinate conversion');
has($wizard, 'pageH - (posY',                        'wizard: flips Y axis (PDF origin is bottom-left)');
has($wizard, 'pdfLibDoc.save()',                     'wizard: serialises modified PDF');
has($wizard, "new Blob([signedBytes]",               'wizard: sends signed PDF as Blob (not base64 string)');
has($wizard, 'signed_pdf_file',                      'wizard: Blob appended as signed_pdf_file FormData field');
has($wizard, 'new_document_id',                      'wizard: download button uses new signed document ID');
has($wizard, "endsWith('.pdf')",                     'wizard: PDF-only guard before signing');

// ── 12. save_signed_pdf.php — security & logic ───────────────────────────────
section('12. save_signed_pdf.php — security and logic');
has($saveApi,    '$_SESSION[\'user_id\']',     'save_signed_pdf.php: auth check present');
has($saveApi,    'csrf_check',                 'save_signed_pdf.php: CSRF check present');
has($saveApi,    'REQUEST_METHOD',             'save_signed_pdf.php: method check present');
has($saveApi,    'FILEINFO_MIME_TYPE',         'save_signed_pdf.php: validates MIME with finfo');
has($saveApi,    'application/pdf',            'save_signed_pdf.php: rejects non-PDF MIME types');
has($saveApi,    'UPLOAD_ERR_OK',              'save_signed_pdf.php: checks file upload error code');
has($saveApi,    'move_uploaded_file',         'save_signed_pdf.php: uses move_uploaded_file (not file_put_contents)');
has($saveApi,    'signed_pdf_file',            'save_signed_pdf.php: reads from $_FILES[signed_pdf_file]');
has($saveApi,    'user_signatures',            'save_signed_pdf.php: verifies signature belongs to user');
has($saveApi,    'document_signatures',        'save_signed_pdf.php: writes to document_signatures table');
has($saveApi,    'Signed)',                    'save_signed_pdf.php: names signed document with (Signed) suffix');
has($saveApi,    'new_document_id',            'save_signed_pdf.php: returns new_document_id in response');
has($saveApi,    'logActivity',                'save_signed_pdf.php: logActivity call present');
has($saveApi,    'logAudit',                   'save_signed_pdf.php: logAudit call present');
hasNot($saveApi, 'base64_decode',              'save_signed_pdf.php: no base64 decode (uses file upload, not base64 POST)');

// ── 13. save_signed_pdf.php — integrity, audit & hardening ────────────────────
section('13. save_signed_pdf.php — integrity, audit & hardening');
has($saveApi,    'canCreate(',                 'save_signed_pdf.php: enforces canCreate permission');
has($saveApi,    "hash_file('sha256'",         'save_signed_pdf.php: computes SHA-256 server-side');
has($saveApi,    'hash_before',                'save_signed_pdf.php: stores original-document hash');
has($saveApi,    'hash_after',                 'save_signed_pdf.php: stores signed-document hash');
has($saveApi,    'consent_text',               'save_signed_pdf.php: persists consent text');
has($saveApi,    'Consent is required',        'save_signed_pdf.php: rejects signing without consent');
has($saveApi,    'event_log',                  'save_signed_pdf.php: writes the audit event log');
has($saveApi,    'signing_reference',          'save_signed_pdf.php: records a signing reference');
has($saveApi,    'signed_document_id',         'save_signed_pdf.php: links the signed document row');
has($saveApi,    'ensureUploadHtaccess',       'save_signed_pdf.php: drops a protective .htaccess');
has($saveApi,    'A server error occurred',    'save_signed_pdf.php: returns a generic error message');
hasNot($saveApi, "'Server error: ' . \$e->getMessage()", 'save_signed_pdf.php: no exception text leaked to client');

// ── 14. verify_signed_document.php — integrity verification endpoint ─────────
section('14. verify_signed_document.php — integrity verification endpoint');
$verifyApi = readSrc('api/document/verify_signed_document.php');
has($verifyApi, 'isAuthenticated',     'verify_signed_document.php: auth check present');
has($verifyApi, 'canView',             'verify_signed_document.php: permission check present');
has($verifyApi, 'hash_file',           'verify_signed_document.php: re-hashes the stored file');
has($verifyApi, 'hash_equals',         'verify_signed_document.php: timing-safe hash comparison');
has($verifyApi, 'hash_after',          'verify_signed_document.php: compares against the recorded hash');
has($verifyApi, "'verified'",          'verify_signed_document.php: returns a verified flag');
has($verifyApi, 'signed_document_id',  'verify_signed_document.php: looks up by signed_document_id');

// ── 15. upload_signature.php — §19 file-upload hardening ──────────────────────
section('15. upload_signature.php — file-upload hardening');
$sigUploadApi = readSrc('api/document/upload_signature.php');
has($sigUploadApi,    'csrf_check',            'upload_signature.php: CSRF check present');
has($sigUploadApi,    'FILEINFO_MIME_TYPE',    'upload_signature.php: validates real MIME (magic bytes)');
has($sigUploadApi,    'allowed_ext',           'upload_signature.php: whitelists by extension');
has($sigUploadApi,    'random_bytes',          'upload_signature.php: non-guessable filename');
has($sigUploadApi,    '2 * 1024 * 1024',       'upload_signature.php: enforces a 2 MB size limit');
has($sigUploadApi,    'logActivity',           'upload_signature.php: logs the upload');
hasNot($sigUploadApi, '0777',                  'upload_signature.php: no world-writable 0777 mkdir');
hasNot($sigUploadApi, "in_array(\$file['type']", 'upload_signature.php: no longer trusts $_FILES[type]');

// ── 16. Wizard — certificate, consent & integrity wiring ─────────────────────
section('16. Wizard — certificate, consent & integrity wiring');
has($wizard, 'bmsSha256Hex(',                          'wizard: calls the shared client-side SHA-256 helper');
has($esignShared, 'async function bmsSha256Hex',       'bms-esign-shared.js: client-side SHA-256 helper present');
has($wizard, 'bmsAppendCertificatePage(',               'wizard: calls the shared Certificate of Completion generator');
has($esignShared, 'async function bmsAppendCertificatePage', 'bms-esign-shared.js: Certificate of Completion generator present');
has($esignShared, 'CERTIFICATE OF COMPLETION',          'bms-esign-shared.js: certificate page is titled');
has($wizard, 'CONSENT_TEXT',                         'wizard: canonical consent statement defined');
has($wizard, "fd.append('consent_text'",             'wizard: submits consent text to the server');
has($wizard, "fd.append('signing_reference'",        'wizard: submits a signing reference');
has($wizard, "fd.append('viewed_at'",                'wizard: submits the document-viewed timestamp');
has($wizard, 'function verifySignedDocument',        'wizard: integrity Verify action present');
has($wizard, 'btnVerifySigned',                      'wizard: Verify button wired on the finish step');
fileExists_('migrations/2026_05_21_esignature_audit_columns.php');
fileExists_('api/document/verify_signed_document.php');

// ── Result ────────────────────────────────────────────────────────────────────
echo "\n\033[1m" . str_repeat('═', 40) . "\033[0m\n";
if ($fail === 0) {
    echo "\033[32m✅ All $pass tests passed — safe to push.\033[0m\n";
} else {
    echo "\033[31m❌ $fail test(s) failed out of " . ($pass + $fail) . " — push blocked.\033[0m\n";
}
echo "\033[1m" . str_repeat('═', 40) . "\033[0m\n\n";
exit($fail > 0 ? 1 : 0);
