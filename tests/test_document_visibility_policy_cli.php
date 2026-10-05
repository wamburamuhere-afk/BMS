<?php
/**
 * Document visibility policy — regression test.
 *
 * Verifies:
 *  1. Migration: see_all_documents column exists and is set correctly.
 *  2. canSeeAllDocuments(): admin + management roles → true; other roles → false.
 *  3. api/document/get_documents.php (main library): uses document_library permission key;
 *     managers see all docs; others see only public+own+assigned.
 *  4. api/get_documents.php (wizard picker): same policy.
 *  5. userCanAccessDocument(): managers bypass access_level check; others follow rules.
 *  6. document_library.php gate: uses document_library key (not documents).
 *
 * Run: php tests/test_document_visibility_policy_cli.php
 *   Exit 0 = all pass | Exit 1 = failures
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/document_access.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

// ─────────────────────────────────────────────────────────────────────────────
section('1. php -l');
foreach ([
    'core/document_access.php',
    'api/document/get_documents.php',
    'api/get_documents.php',
    'app/constant/document/document_library.php',
    'migrations/tenant/2026_10_05_document_visibility_policy.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    check($rc === 0, "$rel — no syntax errors", "$rel — php -l failed: " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. Static — canView key is document_library, not documents');

$libSrc  = file_get_contents("$root/app/constant/document/document_library.php");
$apiSrc  = file_get_contents("$root/api/document/get_documents.php");
$pickSrc = file_get_contents("$root/api/get_documents.php");

check(strpos($libSrc,  "canView('document_library')") !== false, "document_library.php gate uses canView('document_library')",  "document_library.php still uses canView('documents')");
check(strpos($apiSrc,  "canView('document_library')") !== false, "api/document/get_documents.php uses canView('document_library')", "api/document/get_documents.php still uses canView('documents')");
check(strpos($pickSrc, "canView('document_library')") !== false, "api/get_documents.php uses canView('document_library')",         "api/get_documents.php still uses canView('documents')");

section('3. Static — canSeeAllDocuments() guards the list queries');
check(strpos($apiSrc,  'canSeeAllDocuments()') !== false, "api/document/get_documents.php calls canSeeAllDocuments()", "api/document/get_documents.php still calls isAdmin()");
check(strpos($pickSrc, 'canSeeAllDocuments()') !== false, "api/get_documents.php calls canSeeAllDocuments()",          "api/get_documents.php still calls isAdmin()");
check(strpos($apiSrc,  "OR d.access_level = ''") !== false, "api/document/get_documents.php includes blank access_level in public filter", "api/document/get_documents.php misses blank access_level rows");
check(strpos($pickSrc, "OR d.access_level = ''") !== false, "api/get_documents.php includes blank access_level in public filter",          "api/get_documents.php misses blank access_level rows");

section('4. Static — document_access.php has canSeeAllDocuments function');
$dacSrc = file_get_contents("$root/core/document_access.php");
check(strpos($dacSrc, 'function canSeeAllDocuments') !== false, 'canSeeAllDocuments() declared in document_access.php', 'canSeeAllDocuments() missing from document_access.php');
check(strpos($dacSrc, 'see_all_documents') !== false, 'document_access.php queries see_all_documents column', 'document_access.php does not reference see_all_documents');
check(strpos($dacSrc, 'canSeeAllDocuments()') !== false && strpos($dacSrc, 'userCanAccessDocument') !== false, 'userCanAccessDocument delegates to canSeeAllDocuments', 'userCanAccessDocument does not call canSeeAllDocuments');

// ─────────────────────────────────────────────────────────────────────────────
section('5. Live — migration: column exists, correct roles have flag');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php)\n";
} else {
    global $pdo;
    try {
        $cols = array_column($pdo->query("DESCRIBE roles")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        check(in_array('see_all_documents', $cols, true), 'roles.see_all_documents column exists', 'roles.see_all_documents column MISSING — run migration 2026_10_05_document_visibility_policy');

        if (in_array('see_all_documents', $cols, true)) {
            $rows = $pdo->query("SELECT role_name, see_all_documents FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);

            $shouldSeeAll = ['Admin', 'Managing Director', 'Director', 'CFO', 'Credit Manager'];
            $shouldFilter = ['Staff', 'Accountant', 'Secretary (PS)'];

            foreach ($shouldSeeAll as $rn) {
                if (!array_key_exists($rn, $rows)) continue; // role may not exist in this tenant
                check((bool)$rows[$rn], "$rn has see_all_documents=1", "$rn should have see_all_documents=1 but has 0");
            }
            foreach ($shouldFilter as $rn) {
                if (!array_key_exists($rn, $rows)) continue;
                check(!(bool)$rows[$rn], "$rn has see_all_documents=0 (filtered access)", "$rn should have see_all_documents=0 but has 1");
            }
        }
    } catch (Throwable $e) {
        fail('Migration check threw: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Live — canSeeAllDocuments() returns correct value per role');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped\n";
} else {
    $migCols = array_column($pdo->query("DESCRIBE roles")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('see_all_documents', $migCols, true)) {
        echo "  \033[33m⊘\033[0m  Skipped (migration not yet applied — run 2026_10_05_document_visibility_policy)\n";
    } else {
    $roleMap = $pdo->query("SELECT role_id, role_name, is_admin, COALESCE(see_all_documents,0) see_all FROM roles")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($roleMap as $r) {
        unset($_SESSION['_see_all_docs'], $_SESSION['is_admin']);
        $_SESSION['role_id'] = $r['role_id'];
        if ($r['is_admin']) $_SESSION['is_admin'] = 1;
        $got = canSeeAllDocuments();
        $expected = (bool)$r['is_admin'] || (bool)$r['see_all'];
        check($got === $expected,
            "role {$r['role_name']}: canSeeAllDocuments()=" . ($got?'true':'false') . ' correct',
            "role {$r['role_name']}: canSeeAllDocuments()=" . ($got?'true':'false') . " but expected " . ($expected?'true':'false'));
        unset($_SESSION['_see_all_docs']);
    }
    } // end if column exists
}

// ─────────────────────────────────────────────────────────────────────────────
section('7. Live — userCanAccessDocument() respects the new policy');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped\n";
} else {
    try {
        $ownerId    = 999901;
        $staffId    = 999902;
        $managerId  = 999903; // simulated manager (see_all_documents via session flag)

        // Insert a private document owned by ownerId
        $ins = $pdo->prepare("INSERT INTO documents (document_name,file_path,file_type,version,uploaded_by,access_level,source) VALUES ('VIS-TEST private','uploads/vis_test.pdf','pdf','1.0',?,'private','created')");
        $ins->execute([$ownerId]);
        $privId = (int)$pdo->lastInsertId();

        $ins->execute([$ownerId]);
        $ins2 = $pdo->prepare("INSERT INTO documents (document_name,file_path,file_type,version,uploaded_by,access_level,source) VALUES ('VIS-TEST public','uploads/vis_test2.pdf','pdf','1.0',?,'public','created')");
        $ins2->execute([$ownerId]);
        $pubId = (int)$pdo->lastInsertId();

        // Staff (no see_all) cannot access private doc
        unset($_SESSION['_see_all_docs'], $_SESSION['is_admin']);
        $_SESSION['user_id'] = $staffId;
        $_SESSION['role_id'] = 4; // Staff role
        check(!userCanAccessDocument($pdo, $privId), 'Staff CANNOT access a private document uploaded by someone else', 'REGRESSION: Staff was granted access to a private document');
        check(userCanAccessDocument($pdo, $pubId),   'Staff CAN access a public document', 'Staff was denied a public document');
        unset($_SESSION['_see_all_docs']);

        // Simulate a manager: set see_all_documents via the DB for their role
        // (We test by temporarily patching the session cache directly)
        unset($_SESSION['_see_all_docs'], $_SESSION['is_admin']);
        $_SESSION['user_id'] = $managerId;
        $_SESSION['role_id'] = 2; // Managing Director
        // The MD role should have see_all_documents=1 after migration
        $colCheck = array_column($pdo->query("DESCRIBE roles")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (in_array('see_all_documents', $colCheck)) {
            check(userCanAccessDocument($pdo, $privId), 'Managing Director CAN access any private document', 'Managing Director was denied a private document');
        } else {
            echo "  \033[33m⊘\033[0m  MD test skipped (column not yet migrated)\n";
        }
        unset($_SESSION['_see_all_docs']);

        // Owner can always access own private document regardless of role
        unset($_SESSION['_see_all_docs'], $_SESSION['is_admin']);
        $_SESSION['user_id'] = $ownerId;
        $_SESSION['role_id'] = 4;
        check(userCanAccessDocument($pdo, $privId), 'Owner CAN access their own private document', 'Owner was denied their own document');
        unset($_SESSION['_see_all_docs']);

        // Cleanup
        $pdo->prepare("DELETE FROM documents WHERE id IN (?,?)")->execute([$privId, $pubId]);
        pass('test fixtures cleaned up');
    } catch (Throwable $e) {
        fail('Live userCanAccessDocument test threw: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
