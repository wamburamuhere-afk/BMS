<?php
/**
 * BMS — Employee "Additional / Optional Documents" guard (wizard Step 5).
 *
 * Covers core/employee_extra_documents.php: the repeatable name+file rows the
 * wizard posts, their §19 upload validation, ownership on delete, and the fetch
 * used to populate the edit wizard.
 *
 * move_uploaded_file() only accepts a genuinely uploaded file, so the happy-path
 * *move* cannot run under CLI. Everything up to and including the validation gate
 * is exercised here; the insert/fetch/delete cycle is exercised against real rows.
 *
 * Run:
 *   php tests/test_employee_extra_documents_cli.php
 *
 * Exit 0 = all checks pass.  Exit 1 = at least one check failed.
 */

require_once dirname(__DIR__) . '/roots.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/core/employee_extra_documents.php';

$failures = 0;
$passes   = 0;

function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

/** Build the $_FILES shape PHP produces for name="extra_doc_file[]". */
function setFiles(array $rows): void {
    $F = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($rows as $r) {
        $F['name'][]     = $r['name'] ?? '';
        $F['type'][]     = 'application/octet-stream';
        $F['tmp_name'][] = $r['tmp'] ?? '';
        $F['error'][]    = $r['err'] ?? UPLOAD_ERR_OK;
        $F['size'][]     = (!empty($r['tmp']) && is_file($r['tmp'])) ? filesize($r['tmp']) : 0;
    }
    $_FILES['extra_doc_file'] = $F;
}

/** Run hrSaveExtraDocuments and return the rejection message, or null if it got past validation. */
function rejection(PDO $pdo, int $eid, array $emp): ?string {
    try {
        hrSaveExtraDocuments($pdo, $eid, $emp, 1);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

echo "\n\033[1m═══ Employee additional documents (wizard Step 5) ═══\033[0m\n";

$_SESSION['user_id'] = 1;
$tmp = sys_get_temp_dir() . '/bms_hr_doc_test';
if (!is_dir($tmp)) mkdir($tmp, 0777, true);
$pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
$phpBytes = "<?php echo 'pwned'; ?>";
$goodPdf  = $tmp . '/good.pdf';   file_put_contents($goodPdf, $pdfBytes);
$evilPdf  = $tmp . '/evil.pdf';   file_put_contents($evilPdf, $phpBytes);   // PHP wearing a .pdf name
$evilPhp  = $tmp . '/evil.php';   file_put_contents($evilPhp, $phpBytes);

$emp = $pdo->query("SELECT employee_id, first_name, last_name, project_id FROM employees ORDER BY employee_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$emp) { echo "  no employees in DB — cannot run\n"; exit(0); }
$eid = (int)$emp['employee_id'];

head("Document type 'Other' resolves without hard-coding an id");
try {
    $t = hrOtherDocTypeId($pdo);
    $t > 0 ? ok("resolved 'Other' -> doc_type_id $t") : bad('resolved a non-positive id');
} catch (Throwable $e) { bad('could not resolve: ' . $e->getMessage()); }

head('Name / file pairing');
$_POST['extra_doc_name'] = ['Guarantee Letter'];
setFiles([['name' => '', 'err' => UPLOAD_ERR_NO_FILE]]);
$m = rejection($pdo, $eid, $emp);
$m && str_contains($m, 'choose a file') ? ok('a name with no file is rejected') : bad("name-without-file not rejected (got: " . var_export($m, true) . ")");

$_POST['extra_doc_name'] = [''];
setFiles([['name' => 'good.pdf', 'tmp' => $goodPdf]]);
$m = rejection($pdo, $eid, $emp);
$m && str_contains($m, 'needs a name') ? ok('a file with no name is rejected') : bad("file-without-name not rejected (got: " . var_export($m, true) . ")");

$_POST['extra_doc_name'] = [''];
setFiles([['name' => '', 'err' => UPLOAD_ERR_NO_FILE]]);
try {
    $n = hrSaveExtraDocuments($pdo, $eid, $emp, 1);
    $n === 0 ? ok('an untouched blank row is skipped silently') : bad("blank row saved $n document(s)");
} catch (Throwable $e) { bad('blank row raised: ' . $e->getMessage()); }

head('Upload validation (§19)');
$_POST['extra_doc_name'] = ['Evil'];
setFiles([['name' => 'evil.pdf', 'tmp' => $evilPdf]]);
$m = rejection($pdo, $eid, $emp);
$m && str_contains($m, 'does not match allowed types')
    ? ok('PHP disguised as .pdf is rejected on real MIME, not extension')
    : bad("disguised PHP not rejected (got: " . var_export($m, true) . ")");

$_POST['extra_doc_name'] = ['Script'];
setFiles([['name' => 'evil.php', 'tmp' => $evilPhp]]);
$m = rejection($pdo, $eid, $emp);
$m && str_contains($m, 'not allowed') ? ok('.php extension is rejected') : bad("bad extension not rejected (got: " . var_export($m, true) . ")");

head('Delete is scoped to the owning employee');
$other = $pdo->query("SELECT employee_id FROM employees WHERE employee_id != $eid ORDER BY employee_id LIMIT 1")->fetchColumn();
if ($other === false) {
    echo "  \033[33m—\033[0m skipped: need two employees\n";
} else {
    $other = (int)$other;
    $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_type_id, document_name, file_path, status, created_by, created_at)
                   VALUES (?, ?, '__TEST Doc', 'uploads/employee_docs/__test.pdf', 'active', 1, NOW())")
        ->execute([$other, hrOtherDocTypeId($pdo)]);
    $victim = (int)$pdo->lastInsertId();

    $_POST['removed_extra_doc_ids'] = [$victim];
    $n = hrDeleteExtraDocuments($pdo, $eid, 1);          // wrong employee
    $status = $pdo->query("SELECT status FROM employee_documents WHERE emp_doc_id = $victim")->fetchColumn();
    ($n === 0 && $status === 'active')
        ? ok("employee #$eid cannot delete employee #$other's document")
        : bad("cross-employee delete succeeded (n=$n, status=$status)");

    $n = hrDeleteExtraDocuments($pdo, $other, 1);        // rightful owner
    $status = $pdo->query("SELECT status FROM employee_documents WHERE emp_doc_id = $victim")->fetchColumn();
    ($n === 1 && $status === 'deleted')
        ? ok('the owning employee soft-deletes it (status = deleted)')
        : bad("owner delete failed (n=$n, status=$status)");

    head('Fetch returns only active rows');
    $rows = hrFetchExtraDocuments($pdo, $other);
    $ids = array_column($rows, 'emp_doc_id');
    !in_array($victim, $ids, true) ? ok('soft-deleted document is not returned to the wizard')
                                   : bad('soft-deleted document still returned');

    $pdo->exec("DELETE FROM employee_documents WHERE emp_doc_id = $victim");
}

head('Wizard no longer posts the retired single-slot fields');
$src = @file_get_contents(dirname(__DIR__) . '/app/bms/pos/employees.php') ?: '';
strpos($src, 'name="other_doc_name"') === false ? ok('other_doc_name input removed') : bad('other_doc_name input still present');
strpos($src, 'name="other_doc_file"') === false ? ok('other_doc_file input removed') : bad('other_doc_file input still present');
strpos($src, 'name="bank_branch"')    === false ? ok('bank_branch input removed from the wizard') : bad('bank_branch input still present');
strpos($src, 'name="extra_doc_name[]"') !== false ? ok('repeatable extra_doc_name[] present') : bad('extra_doc_name[] missing');
strpos($src, 'name="extra_doc_file[]"') !== false ? ok('repeatable extra_doc_file[] present') : bad('extra_doc_file[] missing');

array_map('unlink', glob("$tmp/*"));

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) { echo "\033[32m✅ All $passes checks passed.\033[0m\n"; exit(0); }
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
