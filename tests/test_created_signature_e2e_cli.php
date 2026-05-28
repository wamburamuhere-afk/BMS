<?php
/**
 * End-to-end test: Created-By signature is actually captured and rendered
 * ----------------------------------------------------------------------
 *   php tests/test_created_signature_e2e_cli.php
 *
 * For each of the 8 individual-document print types we verify the full chain:
 *
 *   1. workflowCaptureSignature($pdo, '<entity_type>', $fakeId, 'created', ...)
 *      writes a real row into workflow_signatures.
 *   2. getWorkflowSignatures($pdo, '<entity_type>', $fakeId) reads it back with
 *      sig_path matching the user's most recent user_signatures.file_path.
 *   3. The canonical includes/workflow_signature_row.php partial, when fed
 *      the resulting $wf array, renders an <img> + "Digitally signed" + the
 *      creator's name in the Created By column.
 *
 * Plus a sanity scenario for a user with no e-signature on file: the row is
 * still written (sig_path = NULL), no <img> renders, the name still shows.
 *
 * Uses fake high entity_id values (>= 999990) so it never collides with real
 * data. All test rows are deleted before AND after the run.
 *
 * Exit 0 = all pass (safe to push). Exit 1 = failures found.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/workflow.php';

global $pdo;

$pass = 0; $fail = 0;
function ok(string $msg): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $msg\n"; }
function bad(string $msg): void { global $fail; $fail++; echo "  \033[31m❌ $msg\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

// ── Pre-flight: pick a real user with a signature for the happy path ─────
section('Pre-flight: locate a user with an active e-signature');

$userWithSig = $pdo->query("
    SELECT u.user_id, u.first_name, u.last_name, u.username,
           COALESCE(u.user_role, u.role, 'Staff') AS role,
           us.file_path AS sig_path
      FROM users u
      JOIN user_signatures us ON us.user_id = u.user_id
  ORDER BY us.updated_at DESC, us.id DESC
     LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$userWithSig) {
    bad('no users with an active e-signature were found — cannot run happy-path scenarios');
    echo "\nResults: $pass passed, $fail failed\n";
    exit(1);
}

$sigUserId   = (int)$userWithSig['user_id'];
$sigUserName = trim(($userWithSig['first_name'] ?? '') . ' ' . ($userWithSig['last_name'] ?? ''))
               ?: ($userWithSig['username'] ?? 'Test User');
$sigUserRole = $userWithSig['role'];
$expectedSig = $userWithSig['sig_path'];
ok("using user_id={$sigUserId} ({$sigUserName}) with sig_path={$expectedSig}");

// ── Pick a user WITHOUT a signature for the NULL-path scenario ──────────
$userNoSig = $pdo->query("
    SELECT u.user_id, u.first_name, u.last_name, u.username,
           COALESCE(u.user_role, u.role, 'Staff') AS role
      FROM users u
 LEFT JOIN user_signatures us ON us.user_id = u.user_id
     WHERE us.id IS NULL
  ORDER BY u.user_id ASC
     LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$haveNoSigUser = (bool)$userNoSig;
if ($haveNoSigUser) {
    $noSigUserId   = (int)$userNoSig['user_id'];
    $noSigUserName = trim(($userNoSig['first_name'] ?? '') . ' ' . ($userNoSig['last_name'] ?? ''))
                     ?: ($userNoSig['username'] ?? 'No-Sig User');
    $noSigUserRole = $userNoSig['role'];
    ok("found user_id={$noSigUserId} ({$noSigUserName}) with no signature for NULL scenario");
} else {
    ok('skipping NULL-path scenario (every user has a signature in this DB)');
}

// ── Entity types & their fake IDs ─────────────────────────────────────────
$entityTypes = [
    'quotation',
    'sales_order',
    'invoice',
    'purchase_order',
    'rfq',
    'grn',
    'delivery',
    'ipc',
    'purchase_return',
    'sales_return',
];
$fakeIdBase = 999990; // happy path: 999990 + index; no-sig: 999970 + index

// ── Cleanup any stale test rows from prior runs ───────────────────────────
$cleanup = $pdo->prepare(
    "DELETE FROM workflow_signatures
      WHERE entity_type = ?
        AND entity_id BETWEEN 999970 AND 999999"
);
foreach ($entityTypes as $et) $cleanup->execute([$et]);

// ─────────────────────────────────────────────────────────────────────────
section('Happy path: capture + read back + render for each entity type');
// ─────────────────────────────────────────────────────────────────────────

foreach ($entityTypes as $i => $entityType) {
    $eid = $fakeIdBase + $i;

    // 1. CAPTURE
    try {
        $result = workflowCaptureSignature(
            $pdo, $entityType, $eid, 'created',
            $sigUserId, $sigUserName, $sigUserRole
        );
    } catch (Throwable $e) {
        bad("{$entityType}: workflowCaptureSignature() threw — " . $e->getMessage());
        continue;
    }
    if (!is_array($result) || !array_key_exists('sig_path', $result)) {
        bad("{$entityType}: capture returned unexpected shape");
        continue;
    }
    if ($result['sig_path'] !== $expectedSig) {
        bad("{$entityType}: capture returned sig_path={$result['sig_path']}, expected {$expectedSig}");
        continue;
    }
    if (!$result['has_signature']) {
        bad("{$entityType}: capture has_signature was false despite user having a signature");
        continue;
    }

    // 2. READ BACK via getWorkflowSignatures
    $wfSigs = getWorkflowSignatures($pdo, $entityType, $eid);
    if (empty($wfSigs['created']) || ($wfSigs['created']['sig_path'] ?? null) !== $expectedSig) {
        bad("{$entityType}: getWorkflowSignatures() did not return sig_path for 'created'");
        continue;
    }
    if (($wfSigs['created']['user_name'] ?? '') !== $sigUserName) {
        bad("{$entityType}: getWorkflowSignatures() user_name mismatch (got `{$wfSigs['created']['user_name']}`)");
        continue;
    }
    if (($wfSigs['created']['user_role'] ?? '') !== $sigUserRole) {
        bad("{$entityType}: getWorkflowSignatures() user_role mismatch (got `{$wfSigs['created']['user_role']}`)");
        continue;
    }

    // 3. RENDER via the canonical partial
    $wf = [
        'created_by_name'    => $sigUserName,
        'created_by_role'    => $sigUserRole,
        'reviewed_by_name'   => '',
        'reviewed_by_role'   => '',
        'approved_by_name'   => '',
        'approved_by_role'   => '',
        'created_sig_path'   => $wfSigs['created']['sig_path'],
        'created_signed_at'  => $wfSigs['created']['signed_at'],
        'reviewed_sig_path'  => null,
        'reviewed_signed_at' => null,
        'approved_sig_path'  => null,
        'approved_signed_at' => null,
        '__include_css'      => true,
    ];
    ob_start();
    require ROOT_DIR . '/includes/workflow_signature_row.php';
    $html = ob_get_clean();

    // Must contain an <img> with the user's sig_path basename
    $sigBasename = basename($expectedSig);
    if (strpos($html, '<img src="') === false) {
        bad("{$entityType}: rendered HTML has no <img> tag at all");
        continue;
    }
    if (strpos($html, $sigBasename) === false) {
        bad("{$entityType}: rendered HTML <img> does not reference {$sigBasename}");
        continue;
    }
    if (strpos($html, 'Digitally signed') === false) {
        bad("{$entityType}: rendered HTML missing 'Digitally signed' caption");
        continue;
    }
    if (strpos($html, htmlspecialchars($sigUserName)) === false) {
        bad("{$entityType}: creator's name not present in rendered HTML");
        continue;
    }
    // Reviewed / Approved blocks must NOT have the img (we passed null sig_path)
    // Quick guard: only 1 <img> tag should be present.
    if (substr_count($html, '<img src="') !== 1) {
        bad("{$entityType}: expected exactly 1 <img>, found " . substr_count($html, '<img src="'));
        continue;
    }

    ok("{$entityType}: captured -> readback -> render all green (img + caption + name)");
}

// ─────────────────────────────────────────────────────────────────────────
section('NULL path: user without signature still writes a row, no <img> renders');
// ─────────────────────────────────────────────────────────────────────────

if ($haveNoSigUser) {
    foreach ($entityTypes as $i => $entityType) {
        $eid = ($fakeIdBase - 20) + $i; // no-sig range

        try {
            $result = workflowCaptureSignature(
                $pdo, $entityType, $eid, 'created',
                $noSigUserId, $noSigUserName, $noSigUserRole
            );
        } catch (Throwable $e) {
            bad("{$entityType} (no-sig): workflowCaptureSignature() threw — " . $e->getMessage());
            continue;
        }
        if ($result['sig_path'] !== null || $result['has_signature'] !== false) {
            bad("{$entityType} (no-sig): expected sig_path=NULL + has_signature=false");
            continue;
        }

        $wfSigs = getWorkflowSignatures($pdo, $entityType, $eid);
        // Note: `?? null` would mask a missing key as null. Use array_key_exists.
        if (!is_array($wfSigs['created'] ?? null)
            || !array_key_exists('sig_path', $wfSigs['created'])
            || $wfSigs['created']['sig_path'] !== null) {
            bad("{$entityType} (no-sig): readback sig_path should be NULL");
            continue;
        }
        if (($wfSigs['created']['user_name'] ?? '') !== $noSigUserName) {
            bad("{$entityType} (no-sig): readback user_name mismatch");
            continue;
        }

        $wf = [
            'created_by_name'    => $noSigUserName,
            'created_by_role'    => $noSigUserRole,
            'reviewed_by_name'   => '',
            'reviewed_by_role'   => '',
            'approved_by_name'   => '',
            'approved_by_role'   => '',
            'created_sig_path'   => null,
            'created_signed_at'  => null,
            'reviewed_sig_path'  => null,
            'reviewed_signed_at' => null,
            'approved_sig_path'  => null,
            'approved_signed_at' => null,
            '__include_css'      => true,
        ];
        ob_start();
        require ROOT_DIR . '/includes/workflow_signature_row.php';
        $html = ob_get_clean();

        if (strpos($html, '<img src="') !== false) {
            bad("{$entityType} (no-sig): rendered <img> when no signature was present");
            continue;
        }
        if (strpos($html, htmlspecialchars($noSigUserName)) === false) {
            bad("{$entityType} (no-sig): creator's name missing from rendered HTML");
            continue;
        }
        ok("{$entityType} (no-sig): row written with NULL sig_path, render shows name without <img>");
    }
} else {
    ok('NULL-path scenarios skipped (no users without signatures available)');
}

// ─────────────────────────────────────────────────────────────────────────
section('Idempotency: re-running capture for the same entity does not create dupes');
// ─────────────────────────────────────────────────────────────────────────

$entityType = 'quotation';
$eid = 999990; // same as happy-path quotation
// Already captured once above. Re-capture:
workflowCaptureSignature(
    $pdo, $entityType, $eid, 'created',
    $sigUserId, $sigUserName, $sigUserRole
);
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM workflow_signatures
      WHERE entity_type = ? AND entity_id = ? AND action = 'created'"
);
$countStmt->execute([$entityType, $eid]);
$cnt = (int)$countStmt->fetchColumn();
if ($cnt === 1) {
    ok("re-capture of {$entityType}/{$eid} still produces exactly 1 row (ON DUPLICATE KEY UPDATE)");
} else {
    bad("expected 1 row for {$entityType}/{$eid} after re-capture, got {$cnt}");
}

// ─────────────────────────────────────────────────────────────────────────
// Cleanup
// ─────────────────────────────────────────────────────────────────────────
foreach ($entityTypes as $et) $cleanup->execute([$et]);

echo "\nResults: \033[32m$pass passed\033[0m, "
   . ($fail === 0 ? "\033[32m0 failed\033[0m" : "\033[31m$fail failed\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
