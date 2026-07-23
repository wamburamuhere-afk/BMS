<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/workflow.php';          // workflowCaptureSignature / actor helpers
require_once __DIR__ . '/../core/revenue_posting.php';   // postInvoiceRevenue / postInvoiceCOGS
global $pdo;

echo "Starting migration: invoices auto-approve + GL accrual backfill...\n";

// Invoices no longer pass a review/approval workflow — they are born 'approved'
// and accrue to the ledger on creation (see api/account/save_invoice.php). This
// backfill brings EXISTING un-finalised invoices in line: every invoice still
// sitting at 'pending' or 'reviewed' is
//   (1) flipped to 'approved', with the CREATOR stamped as reviewer + approver
//       (so the printout shows real names, not "Not yet reviewed/approved"),
//   (2) accrued into the canonical ledger via postInvoiceRevenue / postInvoiceCOGS.
//
// Criteria-based (never hard-coded ids) and idempotent:
//   - re-running finds fewer/zero rows (they are now 'approved');
//   - the posting functions themselves skip anything already in the ledger.
// Each invoice is handled in its own transaction so one bad row can't abort the
// whole backfill.

try {
    $rows = $pdo->query("
        SELECT i.invoice_id, i.created_by, i.created_at,
               TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS creator_name,
               COALESCE(u.user_role, u.role, '') AS creator_role
          FROM invoices i
          LEFT JOIN users u ON u.user_id = i.created_by
         WHERE i.status IN ('pending','reviewed')
         ORDER BY i.invoice_id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Migration failed (could not read invoices): " . $e->getMessage() . "\n";
    exit(1);
}

echo "Found " . count($rows) . " invoice(s) at pending/reviewed to process.\n";

$flipped = 0; $posted = 0; $post_skipped = 0; $errors = 0;

foreach ($rows as $r) {
    $invId   = (int)$r['invoice_id'];
    $creator = (int)$r['created_by'];
    $cname   = $r['creator_name'] !== '' ? $r['creator_name'] : 'System';
    $crole   = $r['creator_role'];
    // Acting user for the ledger post must be positive; fall back to 1 (admin)
    // only when the invoice has no recorded creator.
    $actingUser = $creator > 0 ? $creator : 1;

    try {
        $pdo->beginTransaction();

        // (1) Flip to approved + stamp creator as reviewer AND approver. Keep the
        // original document date for the stamps (fall back to now if missing).
        $pdo->prepare("
            UPDATE invoices
               SET status           = 'approved',
                   reviewed_by      = ?, reviewed_by_name = ?, reviewed_by_role = ?,
                   reviewed_at      = COALESCE(reviewed_at, created_at, NOW()),
                   approved_by      = ?, approved_by_name = ?, approved_by_role = ?,
                   approved_at      = COALESCE(approved_at, created_at, NOW()),
                   updated_at       = NOW()
             WHERE invoice_id = ?
        ")->execute([
            $creator ?: null, $cname, $crole,
            $creator ?: null, $cname, $crole,
            $invId
        ]);
        $flipped++;

        // (1b) Capture reviewer + approver e-signatures for the creator (best-effort;
        // a missing signature-on-file must not abort the accrual).
        try {
            workflowCaptureSignature($pdo, 'invoice', $invId, 'reviewed', $actingUser, $cname, $crole);
            workflowCaptureSignature($pdo, 'invoice', $invId, 'approved', $actingUser, $cname, $crole);
        } catch (Throwable $sigE) {
            // ignore — names are already stamped on the invoice row above
        }

        // (2) Accrue to the ledger (idempotent — no double-posting on re-run).
        $rev = postInvoiceRevenue($pdo, $invId, $actingUser);
        postInvoiceCOGS($pdo, $invId, $actingUser);

        if (!empty($rev['posted'])) {
            $posted++;
        } else {
            $post_skipped++;
            echo "  · Invoice #$invId flipped; not accrued (" . ($rev['reason'] ?? 'unknown') . ").\n";
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors++;
        echo "  ! Invoice #$invId failed: " . $e->getMessage() . "\n";
    }
}

echo "Backfill summary: flipped=$flipped, accrued=$posted, flipped-but-not-accrued=$post_skipped, errors=$errors.\n";
echo "Migration complete.\n";

// Only a wholesale failure (nothing processed but errors on every row) is fatal.
if ($errors > 0 && $flipped === 0) {
    exit(1);
}
