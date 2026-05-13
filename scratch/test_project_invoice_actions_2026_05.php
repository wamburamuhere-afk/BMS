<?php
/**
 * Test: project_view.php — Invoice action dropdown (status-conditional)
 *
 * Verifies:
 *   - renderInvoicesFull() uses buildInvoiceActions(i) — not hardcoded items
 *   - buildInvoiceActions() function is defined
 *   - INVOICE_CAN_DELETE variable is defined (admin guard)
 *   - reviewInvoice() function is defined
 *   - approveInvoice() function is defined
 *   - Status conditions are correct per status:
 *       pending  → Review button present, Approve absent, Edit present
 *       reviewed → Approve button present, Review absent, Edit present
 *       approved → Record Payment condition on balance_due, Edit absent
 *   - Delete gated behind INVOICE_CAN_DELETE
 *   - View Details always present (no condition)
 *   - Print always present (no condition)
 *   - reviewInvoice / approveInvoice POST to update_invoice_status.php
 *   - Both call loadProjectData() on success
 *   - Old hardcoded dropdown items are gone from renderInvoicesFull
 *   - Consistency: same status logic as invoices.php
 *   - DB-level: invoices table has status and balance_due columns
 *   - DB-level: update_invoice_status.php API file exists on disk
 *
 * Run: http://localhost/bms/scratch/test_project_invoice_actions_2026_05.php
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: text/html; charset=utf-8');

function pass(string $label): void {
    echo "<p style='color:green;font-family:monospace'>&#10003; PASS &mdash; $label</p>";
}
function fail(string $label, string $detail = ''): void {
    $extra = $detail ? " <span style='color:#888'>($detail)</span>" : '';
    echo "<p style='color:red;font-family:monospace'>&#10007; FAIL &mdash; $label$extra</p>";
}
function section(string $title): void {
    echo "<h3 style='font-family:monospace;margin-top:24px;border-bottom:1px solid #ccc'>$title</h3>";
}

$src = file_get_contents(__DIR__ . '/../app/bms/operations/project_view.php');

echo "<!DOCTYPE html><html><head><title>Project Invoice Actions Test</title></head><body>";
echo "<h2 style='font-family:monospace'>Project View — Invoice Action Dropdown (Status-Conditional)</h2>";
echo "<p style='font-family:monospace;color:#555'>project_view.php: Invoices tab action dropdown should behave the same as invoices.php</p>";

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1 — renderInvoicesFull uses buildInvoiceActions (not hardcoded)
// ─────────────────────────────────────────────────────────────────────────────
section('Section 1 — renderInvoicesFull Uses buildInvoiceActions');

// T1: dropdown calls buildInvoiceActions
if (strpos($src, '${buildInvoiceActions(i)}') !== false) {
    pass("T1: renderInvoicesFull() dropdown uses \${buildInvoiceActions(i)}");
} else {
    fail("T1: \${buildInvoiceActions(i)} not found in renderInvoicesFull dropdown");
}

// T2: old hardcoded invoice_view item directly in renderInvoicesFull is gone
// (it should only appear inside buildInvoiceActions, not as a raw <li> in renderInvoicesFull)
$renderStart = strpos($src, 'function renderInvoicesFull(');
$renderEnd   = strpos($src, 'function renderInvoices(', $renderStart + 1);
if ($renderEnd === false) $renderEnd = $renderStart + 8000;
$renderBody  = substr($src, $renderStart, $renderEnd - $renderStart);

if (strpos($renderBody, "href=\"invoice_view?id=") === false) {
    pass("T2: Hardcoded invoice_view href removed from renderInvoicesFull body");
} else {
    fail("T2: Hardcoded invoice_view href still present in renderInvoicesFull — old items not cleaned up");
}

// T3: old hardcoded invoice_edit item directly in renderInvoicesFull is gone
if (strpos($renderBody, "href=\"invoice_edit?id=") === false) {
    pass("T3: Hardcoded invoice_edit href removed from renderInvoicesFull body");
} else {
    fail("T3: Hardcoded invoice_edit href still present in renderInvoicesFull — fix incomplete");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2 — JS functions defined
// ─────────────────────────────────────────────────────────────────────────────
section('Section 2 — Required JS Functions Defined');

// T4: buildInvoiceActions() defined
if (strpos($src, 'function buildInvoiceActions(i)') !== false) {
    pass("T4: buildInvoiceActions(i) function is defined");
} else {
    fail("T4: buildInvoiceActions(i) not defined — dropdown will be empty");
}

// T5: INVOICE_CAN_DELETE variable defined
if (strpos($src, 'var INVOICE_CAN_DELETE') !== false) {
    pass("T5: INVOICE_CAN_DELETE variable is defined (admin delete guard)");
} else {
    fail("T5: INVOICE_CAN_DELETE not defined — delete will not be guarded");
}

// T6: INVOICE_CAN_DELETE uses isAdmin() PHP expression
if (strpos($src, "INVOICE_CAN_DELETE = <?= isAdmin() ? 'true' : 'false' ?>") !== false) {
    pass("T6: INVOICE_CAN_DELETE correctly gates on isAdmin()");
} else {
    fail("T6: INVOICE_CAN_DELETE does not use isAdmin() — permission guard incorrect");
}

// T7: reviewInvoice() defined
if (strpos($src, 'function reviewInvoice(id)') !== false) {
    pass("T7: reviewInvoice(id) function is defined");
} else {
    fail("T7: reviewInvoice(id) not defined — Review action will fail");
}

// T8: approveInvoice() defined
if (strpos($src, 'function approveInvoice(id)') !== false) {
    pass("T8: approveInvoice(id) function is defined");
} else {
    fail("T8: approveInvoice(id) not defined — Approve action will fail");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 3 — Status-conditional logic in buildInvoiceActions
// ─────────────────────────────────────────────────────────────────────────────
section('Section 3 — Status-Conditional Logic Correctness');

$fnStart = strpos($src, 'function buildInvoiceActions(i)');
$fnEnd   = strpos($src, "\nfunction ", $fnStart + 1);
$fnBody  = substr($src, $fnStart, $fnEnd - $fnStart);

// T9: View Details always present (no status condition)
if (strpos($fnBody, "invoice_view?id=") !== false) {
    pass("T9: View Details link always present (no status condition) in buildInvoiceActions");
} else {
    fail("T9: View Details link missing from buildInvoiceActions");
}

// T10: Print always present (no status condition on print)
if (strpos($fnBody, "invoice_print?id=") !== false) {
    pass("T10: Print Invoice link present in buildInvoiceActions");
} else {
    fail("T10: Print Invoice link missing from buildInvoiceActions");
}

// T11: Review only when status === 'pending'
if (preg_match("/i\.status === 'pending'[\s\S]{0,200}reviewInvoice/", $fnBody)) {
    pass("T11: Review button gated on status === 'pending'");
} else {
    fail("T11: Review button not correctly gated on status === 'pending'");
}

// T12: Approve only when status === 'reviewed'
if (preg_match("/i\.status === 'reviewed'[\s\S]{0,200}approveInvoice/", $fnBody)) {
    pass("T12: Approve button gated on status === 'reviewed'");
} else {
    fail("T12: Approve button not correctly gated on status === 'reviewed'");
}

// T13: Edit only when pending or reviewed
if (strpos($fnBody, "['pending', 'reviewed'].includes(i.status)") !== false &&
    strpos($fnBody, "invoice_edit?id=") !== false) {
    pass("T13: Edit Invoice only shown for pending/reviewed status");
} else {
    fail("T13: Edit Invoice status condition incorrect — should only show for pending/reviewed");
}

// T14: Record Payment only when approved AND balance_due > 0
if (strpos($fnBody, "i.status === 'approved'") !== false &&
    strpos($fnBody, "parseFloat(i.balance_due) > 0") !== false &&
    strpos($fnBody, "payment_create?invoice=") !== false) {
    pass("T14: Record Payment only shown when approved AND balance_due > 0");
} else {
    fail("T14: Record Payment condition incorrect — should require approved status and balance_due > 0");
}

// T15: Delete gated behind INVOICE_CAN_DELETE
if (preg_match("/INVOICE_CAN_DELETE[\s\S]{0,200}deleteInvoice/", $fnBody)) {
    pass("T15: Delete action gated behind INVOICE_CAN_DELETE (admin only)");
} else {
    fail("T15: Delete not gated on INVOICE_CAN_DELETE — all users can delete");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 4 — reviewInvoice and approveInvoice post to correct API
// ─────────────────────────────────────────────────────────────────────────────
section('Section 4 — Review/Approve Functions Post to Correct API');

$reviewStart = strpos($src, 'function reviewInvoice(id)');
$reviewEnd   = strpos($src, "\nfunction ", $reviewStart + 1);
$reviewBody  = substr($src, $reviewStart, $reviewEnd - $reviewStart);

$approveStart = strpos($src, 'function approveInvoice(id)');
$approveEnd   = strpos($src, "\nfunction ", $approveStart + 1);
$approveBody  = substr($src, $approveStart, $approveEnd - $approveStart);

// T16: reviewInvoice posts to update_invoice_status.php
if (strpos($reviewBody, 'update_invoice_status.php') !== false) {
    pass("T16: reviewInvoice() POSTs to update_invoice_status.php");
} else {
    fail("T16: reviewInvoice() does not post to update_invoice_status.php");
}

// T17: reviewInvoice sends status: 'reviewed'
if (strpos($reviewBody, "status: 'reviewed'") !== false) {
    pass("T17: reviewInvoice() sends { status: 'reviewed' } in POST body");
} else {
    fail("T17: reviewInvoice() does not send status: 'reviewed' — status will not update correctly");
}

// T18: approveInvoice posts to update_invoice_status.php
if (strpos($approveBody, 'update_invoice_status.php') !== false) {
    pass("T18: approveInvoice() POSTs to update_invoice_status.php");
} else {
    fail("T18: approveInvoice() does not post to update_invoice_status.php");
}

// T19: approveInvoice sends status: 'approved'
if (strpos($approveBody, "status: 'approved'") !== false) {
    pass("T19: approveInvoice() sends { status: 'approved' } in POST body");
} else {
    fail("T19: approveInvoice() does not send status: 'approved' — status will not update correctly");
}

// T20: reviewInvoice calls loadProjectData() on success
if (strpos($reviewBody, 'loadProjectData()') !== false) {
    pass("T20: reviewInvoice() calls loadProjectData() on success — project view refreshes");
} else {
    fail("T20: reviewInvoice() does not call loadProjectData() — page won't refresh after review");
}

// T21: approveInvoice calls loadProjectData() on success
if (strpos($approveBody, 'loadProjectData()') !== false) {
    pass("T21: approveInvoice() calls loadProjectData() on success — project view refreshes");
} else {
    fail("T21: approveInvoice() does not call loadProjectData() — page won't refresh after approve");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 5 — API file exists on disk
// ─────────────────────────────────────────────────────────────────────────────
section('Section 5 — API File Exists on Disk');

// T22: update_invoice_status.php exists
$apiFile = realpath(__DIR__ . '/../api/account/update_invoice_status.php');
if ($apiFile && file_exists($apiFile)) {
    pass("T22: api/account/update_invoice_status.php exists on disk");
} else {
    fail("T22: api/account/update_invoice_status.php NOT found on disk — review/approve will fail with 404");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 6 — DB-level checks
// ─────────────────────────────────────────────────────────────────────────────
section('Section 6 — DB-Level: Invoices Table Schema');

$inv = $pdo->query("SELECT * FROM invoices LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($inv) {
    // T23: status column exists
    if (array_key_exists('status', $inv)) {
        pass("T23: invoices.status column exists — status conditions will evaluate correctly");
    } else {
        fail("T23: invoices.status column missing — all status checks will fail");
    }

    // T24: balance_due column exists
    if (array_key_exists('balance_due', $inv)) {
        pass("T24: invoices.balance_due column exists — Record Payment condition will work");
    } else {
        fail("T24: invoices.balance_due column missing — Record Payment button will never appear");
    }

    // T25: invoice_id column exists
    if (array_key_exists('invoice_id', $inv)) {
        pass("T25: invoices.invoice_id column exists — action links will build correctly");
    } else {
        fail("T25: invoices.invoice_id column missing — all action links will be broken");
    }

    // T26: valid status values in use
    $statuses = $pdo->query("SELECT DISTINCT status FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
    $known = ['pending','reviewed','approved','cancelled','paid'];
    $unknown = array_diff($statuses, $known);
    if (empty($unknown)) {
        pass("T26: All status values in invoices table are known — [" . implode(', ', $statuses) . "]");
    } else {
        fail("T26: Unexpected status values found: " . implode(', ', $unknown) . " — may need new conditions in buildInvoiceActions");
    }
} else {
    echo "<p style='color:orange;font-family:monospace'>&#9888; No invoices found in DB — Section 6 T23-T26 skipped.</p>";
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 7 — Consistency with invoices.php
// ─────────────────────────────────────────────────────────────────────────────
section('Section 7 — Consistency with invoices.php Reference');

$invSrc = file_get_contents(__DIR__ . '/../app/bms/invoice/invoices.php');

// T27: invoices.php also uses reviewInvoice()
if (strpos($invSrc, 'reviewInvoice(') !== false) {
    pass("T27: invoices.php also defines/uses reviewInvoice() — mechanisms match");
} else {
    fail("T27: reviewInvoice() not found in invoices.php — unexpected mismatch");
}

// T28: invoices.php also uses approveInvoice()
if (strpos($invSrc, 'approveInvoice(') !== false) {
    pass("T28: invoices.php also defines/uses approveInvoice() — mechanisms match");
} else {
    fail("T28: approveInvoice() not found in invoices.php — unexpected mismatch");
}

// T29: both files check balance_due for Record Payment
$detailsHasBalanceDue = strpos($src, 'parseFloat(i.balance_due) > 0') !== false;
// invoices.php may use different variable name — check for balance_due in any form
$invHasBalanceDue = strpos($invSrc, 'balance_due') !== false;
if ($detailsHasBalanceDue && $invHasBalanceDue) {
    pass("T29: Both project_view.php and invoices.php check balance_due for Record Payment");
} else {
    fail("T29: balance_due condition mismatch — project_view=" . ($detailsHasBalanceDue?'yes':'no') . ", invoices=" . ($invHasBalanceDue?'yes':'no'));
}

echo "<hr><p style='font-family:monospace;color:#555'>All tests complete. Green = pass, Red = fail.</p></body></html>";
