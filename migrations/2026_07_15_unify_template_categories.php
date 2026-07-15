<?php
/**
 * 2026_07_15_unify_template_categories.php
 * ------------------------------------------------------
 * Create Document carried TWO parallel category taxonomies: `document_categories`
 * (the curated 5-row set that files a saved document) and `template_categories`
 * (a separate 8-row set grouping templates in the chooser). Separate id spaces,
 * no correspondence — so the user classified the same thing twice.
 *
 * This retires `template_categories` and files templates under the SAME curated
 * `document_categories` the rest of the document system already uses, so there
 * is ONE taxonomy for the chooser, the "Save as Template" picker, and the
 * document's own filing field. `document_categories` is deliberately kept at its
 * curated set (guarded by tests/test_document_categories_cli.php) — the 8
 * template groups are MAPPED into it, not bulk-imported, so the filing taxonomy
 * stays clean instead of bloating to 13 mixed rows.
 *
 * Mapping (template group -> canonical document category):
 *   Loan Agreements, Contracts        -> Legal & Contracts
 *   Certificates                      -> Compliance & Regulatory
 *   Application Forms, Letters,
 *   Notices, Reports, Policies        -> General Documents
 * Anything unmapped falls back to General Documents (never lost).
 *
 * SAFE TO RE-RUN — guarded on the existence of `template_categories`; once it's
 * dropped, the migration is a no-op.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: retire template_categories, file templates under document_categories...\n";

try {
    $hasTable = $pdo->query("SHOW TABLES LIKE 'template_categories'")->fetch();
    if (!$hasTable) {
        echo "  ~ template_categories already removed — nothing to do.\n";
        echo "Migration complete.\n";
        return;
    }

    // Canonical document_categories, by name → id.
    $docCats = [];
    foreach ($pdo->query("SELECT id, category_name FROM document_categories") as $r) {
        $docCats[$r['category_name']] = (int)$r['id'];
    }
    $fallback = $docCats['General Documents'] ?? null;
    if ($fallback === null) {
        // Safety: never proceed without a catch-all to land templates in.
        throw new PDOException("Canonical 'General Documents' category is missing — run the document_categories consolidation migration first.");
    }

    // template group name → canonical document category name.
    $groupMap = [
        'Loan Agreements'   => 'Legal & Contracts',
        'Contracts'         => 'Legal & Contracts',
        'Certificates'      => 'Compliance & Regulatory',
        'Application Forms' => 'General Documents',
        'Letters'           => 'General Documents',
        'Notices'           => 'General Documents',
        'Reports'           => 'General Documents',
        'Policies'          => 'General Documents',
    ];

    // Re-file each template by its CURRENT template-category name.
    $rows = $pdo->query("
        SELECT dt.id AS tpl_id, tc.category_name AS tpl_cat
        FROM document_templates dt
        JOIN template_categories tc ON tc.id = dt.category_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("UPDATE document_templates SET category_id = ? WHERE id = ?");
    $moved = 0;
    foreach ($rows as $row) {
        $canonName = $groupMap[$row['tpl_cat']] ?? 'General Documents';
        $target = $docCats[$canonName] ?? $fallback;
        $upd->execute([$target, (int)$row['tpl_id']]);
        $moved++;
    }
    echo "  ~ Re-filed $moved template(s) under canonical document categories.\n";

    // Drop the redundant table (DDL — auto-commits).
    $pdo->exec("DROP TABLE template_categories");
    echo "  - Dropped redundant template_categories table.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
