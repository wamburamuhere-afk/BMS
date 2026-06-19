<?php
/**
 * Migration: rename revenues.status = 'posted' → 'approved'.
 *
 * The "posted" step is removed from the revenue workflow. Approval IS posting.
 * Existing posted records are reassigned to 'approved' so the UI and reports
 * reflect the correct status without a separate "post" concept.
 *
 * Idempotent: safe to run again — only touches rows still set to 'posted'.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

echo "Migration: revenue_posted_to_approved\n";
echo str_repeat('-', 50) . "\n";

$count = (int)$pdo->query("SELECT COUNT(*) FROM revenues WHERE status = 'posted'")->fetchColumn();
echo "Found $count revenue(s) with status='posted'.\n";

if ($count > 0) {
    $pdo->exec("UPDATE revenues SET status = 'approved' WHERE status = 'posted'");
    echo "Updated $count row(s) to status='approved'.\n";
} else {
    echo "Nothing to update.\n";
}

echo "\nDone.\n";
