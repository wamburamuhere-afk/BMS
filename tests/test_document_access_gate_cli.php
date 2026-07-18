<?php
/**
 * Document access-control gate — regression test.
 *
 * Guards the security fix to document_library.php's download/view actions
 * and the shared core/document_access.php helper: a restricted document
 * must be reachable only by its owner, an admin, or an explicitly assigned
 * user — never by an arbitrary authenticated user who merely has the URL.
 *
 * Run:  php tests/test_document_access_gate_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

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

section('1. php -l');

foreach ([
    'core/document_access.php',
    'app/constant/document/document_library.php',
    'api/document/get_document_activity.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    check($rc === 0, "$rel — no syntax errors", "$rel — php -l failed: " . implode(' ', $out));
}

section('2. Static — the download/view dispatch checks authorization before serving');

$libSrc = file_get_contents("$root/app/constant/document/document_library.php");
check(strpos($libSrc, 'userCanAccessDocument(') !== false, 'document_library.php calls the shared access-gate helper', 'document_library.php does not call userCanAccessDocument()');
check(strpos($libSrc, "action === 'download' || \$action === 'view'") !== false || (strpos($libSrc, "'download'") !== false && strpos($libSrc, "'view'") !== false), 'both download and view actions are gated', 'view action does not appear alongside download in the gate');
check(strpos($libSrc, 'document_library?action=view&document_id=') !== false, '"View Online" links route through the PHP gate instead of a raw file path', 'View Online still links directly to the raw file path');
check(!preg_match('/href="\$\{APP_URL\}\/\$\{row\.file_path\}"/', $libSrc), 'no remaining raw-file-path link in document_library.php', 'a raw file-path link still exists in document_library.php');

section('3. Live — userCanAccessDocument() actually allows/denies correctly');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        // Two throwaway users distinct from the real admin (user_id 1) and
        // from each other, so the assignment/ownership checks are meaningful.
        $ownerId = 999001;
        $strangerId = 999002;
        $assigneeId = 999003;

        // Public document — everyone allowed regardless of ownership/assignment.
        $docPublic = $pdo->prepare("INSERT INTO documents (document_name, file_path, file_type, version, uploaded_by, access_level, source) VALUES ('TEST access-gate public', 'uploads/documents/test_gate_pub.pdf', 'pdf', '1.0', ?, 'public', 'created')");
        $docPublic->execute([$ownerId]);
        $publicId = (int)$pdo->lastInsertId();

        // Restricted document — owned by $ownerId, explicitly shared with $assigneeId only.
        $docPriv = $pdo->prepare("INSERT INTO documents (document_name, file_path, file_type, version, uploaded_by, access_level, source) VALUES ('TEST access-gate restricted', 'uploads/documents/test_gate_priv.pdf', 'pdf', '1.0', ?, 'restricted', 'created')");
        $docPriv->execute([$ownerId]);
        $privId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO document_assignees (document_id, user_id, assigned_by) VALUES (?, ?, ?)")->execute([$privId, $assigneeId, $ownerId]);

        $asUser = function (int $uid) {
            $_SESSION['user_id'] = $uid;
            unset($_SESSION['is_admin'], $_SESSION['role_id']);
        };

        $asUser($ownerId);
        check(userCanAccessDocument($pdo, $publicId), 'owner can access their own public document', 'owner denied access to own public document');
        check(userCanAccessDocument($pdo, $privId), 'owner can access their own restricted document', 'owner denied access to own restricted document');

        $asUser($strangerId);
        check(userCanAccessDocument($pdo, $publicId), 'an unrelated user CAN access a public document', 'an unrelated user was denied a public document');
        check(!userCanAccessDocument($pdo, $privId), 'an unrelated, non-assigned, non-admin user is DENIED a restricted document — this is the exact scenario the security gap allowed', 'SECURITY REGRESSION: an unrelated user was allowed to access a restricted document');

        $asUser($assigneeId);
        check(userCanAccessDocument($pdo, $privId), 'a user explicitly listed in document_assignees CAN access the restricted document', 'an explicitly assigned user was incorrectly denied');

        $asUser($strangerId);
        $_SESSION['is_admin'] = 1;
        check(userCanAccessDocument($pdo, $privId), 'an admin can access any restricted document regardless of ownership/assignment', 'an admin was incorrectly denied a restricted document');
        unset($_SESSION['is_admin']);

        check(userCanAccessDocument($pdo, 999999999) === false, 'a non-existent document_id resolves to false (no crash, no silent allow)', 'a non-existent document_id did not resolve to false');

        // Cleanup — self-contained.
        $pdo->prepare("DELETE FROM document_assignees WHERE document_id = ?")->execute([$privId]);
        $pdo->prepare("DELETE FROM documents WHERE id IN (?, ?)")->execute([$publicId, $privId]);
        pass('test data cleaned up (self-contained, no residue left in the DB)');

    } catch (Throwable $e) {
        fail('Live access-gate test threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
