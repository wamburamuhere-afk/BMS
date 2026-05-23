<?php
// migrations/2026_05_22_consolidate_document_categories.php
//
// Consolidates document_categories down to 5 canonical rows and re-points
// every document that referenced a removed row, so no data is lost.
//
// Designed to be SAFE across every live system regardless of starting
// state — empty table, partial seed, full duplicate seed, or ad-hoc custom
// categories. The migration is fully idempotent: running it twice on the
// same database is a no-op after the first pass.
//
// Canonical categories (matched by exact name):
//   1. Legal & Contracts
//   2. Financial Reports
//   3. HR & Employment
//   4. Compliance & Regulatory    (also folds the older 'Compliance & KYC')
//   5. General Documents          (the catch-all)
//
// Anything else found in the table is treated as junk / custom and its
// documents are folded into 'General Documents' before the row is removed.

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: consolidate document_categories to 5 canonical rows...\n";

// The 5 canonical categories — name is the unique business key.
$canonical = [
    'Legal & Contracts'       => ['#dc3545', 'Agreements, MoUs, NDAs and legal contracts'],
    'Financial Reports'       => ['#198754', 'Financial statements, audits and budgets'],
    'HR & Employment'         => ['#0d6efd', 'Employee contracts, payslips and HR records'],
    'Compliance & Regulatory' => ['#ffc107', 'TRA filings, OSHA certificates and regulatory licences'],
    'General Documents'       => ['#6c757d', 'Miscellaneous documents that do not fit other categories'],
];

// Old / alternative names that fold into a SPECIFIC canonical row instead
// of falling through to General Documents.
$aliases = [
    'Compliance & KYC' => 'Compliance & Regulatory',
];

try {
    $pdo->beginTransaction();

    // 1. Ensure each canonical row exists. Capture the chosen id per name.
    $canonicalIds = [];
    foreach ($canonical as $name => $meta) {
        [$color, $desc] = $meta;
        $stmt = $pdo->prepare(
            "SELECT id FROM document_categories WHERE category_name = ? ORDER BY id LIMIT 1"
        );
        $stmt->execute([$name]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $canonicalIds[$name] = (int) $existing;
            echo "  '$name' kept at #{$canonicalIds[$name]}.\n";
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO document_categories (category_name, description, color) VALUES (?, ?, ?)"
            );
            $ins->execute([$name, $desc, $color]);
            $canonicalIds[$name] = (int) $pdo->lastInsertId();
            echo "  Inserted '$name' as #{$canonicalIds[$name]}.\n";
        }
    }
    $generalId = $canonicalIds['General Documents'];

    // 2. Walk every existing row. Skip canonical kept rows. For the rest —
    //    re-point their documents to the right canonical id, then delete
    //    the row.
    $rows = $pdo->query(
        "SELECT id, category_name FROM document_categories"
    )->fetchAll(PDO::FETCH_ASSOC);

    $deleted = 0;
    $repointed = 0;
    foreach ($rows as $row) {
        $id   = (int) $row['id'];
        $name = $row['category_name'];

        // Canonical kept row — leave it alone.
        if (isset($canonicalIds[$name]) && $canonicalIds[$name] === $id) {
            continue;
        }

        // Choose the target canonical id.
        if (isset($canonicalIds[$name])) {
            $target = $canonicalIds[$name];                       // duplicate of a canonical name
        } elseif (isset($aliases[$name])) {
            $target = $canonicalIds[$aliases[$name]];             // old alias
        } else {
            $target = $generalId;                                 // junk / custom / non-canonical
        }

        // Re-point any documents attached to this row.
        $u = $pdo->prepare("UPDATE documents SET category_id = ? WHERE category_id = ?");
        $u->execute([$target, $id]);
        $moved = $u->rowCount();
        if ($moved > 0) {
            echo "  Re-pointed $moved doc(s) from '$name' (#$id) -> #$target.\n";
            $repointed += $moved;
        }

        // Delete the row.
        $pdo->prepare("DELETE FROM document_categories WHERE id = ?")->execute([$id]);
        $deleted++;
        echo "  Deleted '$name' (#$id).\n";
    }

    $pdo->commit();

    $finalCount = (int) $pdo->query("SELECT COUNT(*) FROM document_categories")->fetchColumn();
    echo "Final state: $finalCount canonical row(s). Removed $deleted row(s), re-pointed $repointed doc(s).\n";
    echo "Migration complete.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
