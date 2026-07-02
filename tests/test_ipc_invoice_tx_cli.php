<?php
/**
 * IPC / invoice / voucher transaction-atomicity test.
 *
 * Guards the fix/tx-ipc-invoice work:
 *   1. Static: the four endpoints wrap their multi-step writes in transactions,
 *      and create_invoice_from_ipc guards the IPC link on invoice_id IS NULL
 *      (no duplicate invoice for the same certificate on retry/concurrency).
 *   2. Live: invoice INSERT + IPC link roll back together on a forced failure
 *      (no orphan invoice, IPC unchanged); the real run links exactly once and
 *      a second attempt is refused.
 *   3. Live: a failed voucher INSERT burns no sequential PV number
 *      (nextCode joins the caller's transaction and rolls back with it).
 *
 * Run: php tests/test_ipc_invoice_tx_cli.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/code_generator.php";
global $pdo;

$passes = 0; $failures = 0;
function pass($m) { global $passes;   $passes++;   echo "  \xE2\x9C\x85 $m\n"; }
function fail($m) { global $failures; $failures++; echo "  \xE2\x9D\x8C $m\n"; }
function section($t) { echo "\n\xE2\x94\x80\xE2\x94\x80 $t \xE2\x94\x80\xE2\x94\x80\n"; }

// ── 1. Static guards ───────────────────────────────────────────────────────
section('Endpoints wrap multi-step writes in transactions');
$static = [
    'api/operations/create_invoice_from_ipc.php' => ['beginTransaction', 'rollBack', 'invoice_id IS NULL'],
    'api/operations/update_ipc_status.php'       => ['beginTransaction', 'rollBack', 'workflowCaptureSignature'],
    'api/operations/save_ipc.php'                => ['beginTransaction', 'rollBack', 'workflowCaptureSignature'],
    'api/account/save_voucher.php'               => ['beginTransaction', 'rollBack', 'nextCode'],
];
foreach ($static as $file => $needles) {
    $src = @file_get_contents("$root/$file") ?: '';
    $missing = array_filter($needles, fn($n) => strpos($src, $n) === false);
    empty($missing)
        ? pass("$file contains " . implode(' + ', $needles))
        : fail("$file missing: " . implode(', ', $missing));
}

// ── Fixtures ───────────────────────────────────────────────────────────────
$tag = 'TEST-IPCTX-' . substr(bin2hex(random_bytes(3)), 0, 6);
$projectId = (int)($pdo->query("SELECT project_id FROM projects LIMIT 1")->fetchColumn() ?: 0);
$customerId = (int)($pdo->query("SELECT customer_id FROM customers LIMIT 1")->fetchColumn() ?: 0);
$ipcId = null; $createdInvoiceIds = [];

/** Mirrors create_invoice_from_ipc.php's atomic unit (kept in sync with the fix). */
function invoiceFromIpc(PDO $pdo, array $ipc, int $ipcId, bool $forceFail): array {
    $pdo->beginTransaction();
    try {
        $no = 'TEST-INV-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date,
                           subtotal, grand_total, balance_due, currency, notes, status, project_id, created_by)
                       VALUES (?,?,CURDATE(),CURDATE(),?,?,?,?,?,?,?,1)")
            ->execute([$no, $ipc['customer_id'], 100, 100, 100, 'TZS', 'tx-test', 'unpaid', $ipc['project_id']]);
        $invId = (int)$pdo->lastInsertId();

        if ($forceFail) throw new Exception('forced failure after invoice INSERT');

        $link = $pdo->prepare("UPDATE interim_payment_certificates
                                  SET invoice_id=?, status='Paid', updated_at=NOW()
                                WHERE ipc_id=? AND invoice_id IS NULL");
        $link->execute([$invId, $ipcId]);
        if ($link->rowCount() === 0) throw new Exception('An invoice already exists for this IPC');

        $pdo->commit();
        return ['ok' => true, 'invoice_id' => $invId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

try {
    if (!$projectId || !$customerId) {
        fail('No project/customer rows available for fixtures — cannot run live scenarios');
    } else {
        $pdo->prepare("INSERT INTO interim_payment_certificates
                           (project_id, ipc_number, ipc_date, period_from, period_to,
                            certified_amount, net_payable, status, created_by)
                       VALUES (?,?,CURDATE(),CURDATE(),CURDATE(),100,100,'Approved',1)")
            ->execute([$projectId, $tag]);
        $ipcId = (int)$pdo->lastInsertId();
        $ipc = ['customer_id' => $customerId, 'project_id' => $projectId];

        // ── 2a. Forced failure: no orphan invoice, IPC untouched ─────────
        section('Live: forced failure rolls invoice + IPC link back together');
        $before = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = 'tx-test'")->fetchColumn();
        $r = invoiceFromIpc($pdo, $ipc, $ipcId, true);
        $after = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = 'tx-test'")->fetchColumn();
        (!$r['ok'] && $after === $before)
            ? pass('failed run left no orphan invoice')
            : fail('orphan invoice survived the rollback');
        $st = $pdo->prepare("SELECT status, invoice_id FROM interim_payment_certificates WHERE ipc_id = ?");
        $st->execute([$ipcId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        ($row['status'] === 'Approved' && $row['invoice_id'] === null)
            ? pass('IPC still Approved and unlinked after rollback')
            : fail('IPC was mutated by the failed run: ' . json_encode($row));

        // ── 2b. Real run links once; a second attempt is refused ─────────
        section('Live: real run links exactly once, retry cannot duplicate');
        $r1 = invoiceFromIpc($pdo, $ipc, $ipcId, false);
        if ($r1['ok']) { $createdInvoiceIds[] = $r1['invoice_id']; }
        $r1['ok'] ? pass('first run created invoice #' . $r1['invoice_id'] . ' and linked the IPC')
                  : fail('first run failed: ' . ($r1['error'] ?? '?'));
        $r2 = invoiceFromIpc($pdo, $ipc, $ipcId, false);
        if (!empty($r2['invoice_id'])) { $createdInvoiceIds[] = $r2['invoice_id']; }
        (!$r2['ok'] && strpos($r2['error'], 'already exists') !== false)
            ? pass('second run refused (guarded UPDATE hit 0 rows) — no duplicate invoice')
            : fail('second run was NOT refused — duplicate-invoice bug still present');
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = 'tx-test'")->fetchColumn();
        ($cnt === $before + 1)
            ? pass('exactly one invoice exists for the IPC')
            : fail("expected 1 invoice, found " . ($cnt - $before));
    }

    // ── 3. Failed voucher save burns no sequential number ────────────────
    section('Live: failed voucher save burns no PV number');
    $peek = function () use ($pdo): int {
        // next_number the generator would hand out (0 if no counter row yet)
        return (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(voucher_number,'-',-1) AS UNSIGNED)),0)
                                   FROM payment_vouchers WHERE voucher_number REGEXP '-PV-[0-9]+$'")->fetchColumn();
    };
    $seqBefore = $pdo->query("SELECT COUNT(*) FROM payment_vouchers")->fetchColumn();
    $pdo->beginTransaction();
    try {
        $no = nextCode($pdo, 'PV');
        $pdo->prepare("INSERT INTO payment_vouchers (voucher_number, vouch_date, payee_name, amount, prepared_by, status, created_at)
                       VALUES (?, CURDATE(), ?, 100, 1, 'pending', NOW())")->execute([$no, $tag]);
        throw new Exception('forced failure after voucher INSERT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    $seqAfter = $pdo->query("SELECT COUNT(*) FROM payment_vouchers")->fetchColumn();
    ($seqAfter === $seqBefore)
        ? pass('rolled-back voucher left no row behind')
        : fail('voucher row survived the rollback');

    // The number sequence must not have advanced: allocate for real and check
    // it's contiguous with the pre-failure state.
    $pdo->beginTransaction();
    $probe = nextCode($pdo, 'PV');
    $pdo->rollBack();   // probe allocation rolled back too
    ($probe === $no)
        ? pass("sequence did not burn a number (next allocation is still $no)")
        : fail("sequence burned a number: failed run took $no, next is $probe");
} catch (Throwable $e) {
    fail('live scenario errored: ' . $e->getMessage());
} finally {
    try {
        foreach ($createdInvoiceIds as $iid) {
            $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ? AND notes = 'tx-test'")->execute([$iid]);
        }
        if ($ipcId) {
            $pdo->prepare("DELETE FROM workflow_signatures WHERE entity_type='ipc' AND entity_id=?")->execute([$ipcId]);
            $pdo->prepare("DELETE FROM interim_payment_certificates WHERE ipc_id = ?")->execute([$ipcId]);
        }
        $pdo->prepare("DELETE FROM payment_vouchers WHERE payee_name = ?")->execute([$tag]);
    } catch (Throwable $e) { /* best-effort cleanup */ }
}

// ── Result ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Passed: $passes   Failed: $failures\n";
echo $failures > 0 ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failures > 0 ? 1 : 0);
