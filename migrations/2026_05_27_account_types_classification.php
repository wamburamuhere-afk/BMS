<?php
/**
 * 2026_05_27_account_types_classification
 *
 * Adds canonical classification metadata to `account_types` so that all five
 * financial reports (Income Statement, Balance Sheet, Cash Flow, Trial
 * Balance, General Ledger) can derive a single source of truth instead of
 * each computing its own brittle account-name heuristics.
 *
 * New columns on account_types:
 *   - statement           ENUM('BS','IS')      → which statement this type rolls up to
 *   - category            ENUM('asset','liability','equity','revenue','expense','cogs')
 *                                              → canonical category every report uses
 *   - normal_side         ENUM('debit','credit')
 *                                              → natural balance side (Trial Balance
 *                                                presents each account on this side)
 *   - cash_flow_category  ENUM('operating','investing','financing','cash','none')
 *                                              → where this type's net change goes
 *                                                on the Cash Flow Statement
 *
 * Idempotent: every step guards with SHOW COLUMNS / SHOW TABLES so it can
 * safely re-run on any server. No raw DDL inside a transaction.
 *
 * Seeding strategy: existing `type_name` values are mapped to category /
 * normal_side / cash_flow_category by a deterministic LIKE-pattern table.
 * Accountants can fine-tune later via a Settings UI (out of scope for this
 * migration). Defaults are conservative — if a type_name doesn't match any
 * rule, it's left NULL and the report shows a warning rather than guessing.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: account_types classification columns + seed...\n";

try {
    // ── Guard: account_types table must exist on this server ───────────
    $tbl = $pdo->query("SHOW TABLES LIKE 'account_types'")->fetch();
    if (!$tbl) {
        echo "Table 'account_types' not found on this server — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // ── 1. Add 4 new classification columns ────────────────────────────
    $columns = [
        'statement'          => "ENUM('BS','IS') NULL AFTER type_name",
        'category'           => "ENUM('asset','liability','equity','revenue','expense','cogs') NULL AFTER statement",
        'normal_side'        => "ENUM('debit','credit') NULL AFTER category",
        'cash_flow_category' => "ENUM('operating','investing','financing','cash','none') NULL AFTER normal_side",
    ];

    foreach ($columns as $col => $spec) {
        $res = $pdo->query("SHOW COLUMNS FROM account_types LIKE " . $pdo->quote($col));
        if (!$res->fetch()) {
            $pdo->exec("ALTER TABLE account_types ADD COLUMN `$col` $spec");
            echo "✓ Column 'account_types.$col' added.\n";
        } else {
            echo "✓ Column 'account_types.$col' already exists — skipped.\n";
        }
    }

    // ── 2. Seed classification from type_name (deterministic mapping) ──
    //
    // Patterns are checked in order; first match wins. Patterns use LOWER()
    // case-insensitive LIKE. The rules below cover the standard chart-of-
    // accounts vocabulary; anything that doesn't match stays NULL and the
    // accountant can classify it manually.

    $seedRules = [
        // [ pattern, category, normal_side, statement, cash_flow_category ]

        // ── Income Statement: Revenue ──────────────────────────────────
        ['%revenue%',          'revenue',  'credit', 'IS', 'none'],
        ['%income%',           'revenue',  'credit', 'IS', 'none'],
        ['%sales%',            'revenue',  'credit', 'IS', 'none'],

        // ── Income Statement: Cost of Goods Sold ───────────────────────
        ['%cost of goods%',    'cogs',     'debit',  'IS', 'none'],
        ['%cost of sales%',    'cogs',     'debit',  'IS', 'none'],
        ['%cogs%',             'cogs',     'debit',  'IS', 'none'],

        // ── Income Statement: Expenses ─────────────────────────────────
        ['%expense%',          'expense',  'debit',  'IS', 'none'],

        // ── Balance Sheet: Cash (special — flows separately) ───────────
        ['%cash%',             'asset',    'debit',  'BS', 'cash'],

        // ── Balance Sheet: Fixed / Non-Current Assets (Investing) ──────
        ['%fixed asset%',      'asset',    'debit',  'BS', 'investing'],
        ['%non-current asset%','asset',    'debit',  'BS', 'investing'],
        ['%non current asset%','asset',    'debit',  'BS', 'investing'],

        // ── Balance Sheet: Current Assets (Operating) ──────────────────
        ['%current asset%',    'asset',    'debit',  'BS', 'operating'],
        ['%accounts receivable%','asset',  'debit',  'BS', 'operating'],
        ['%receivable%',       'asset',    'debit',  'BS', 'operating'],
        ['%inventory%',        'asset',    'debit',  'BS', 'operating'],

        // ── Balance Sheet: generic asset (fallback) ────────────────────
        ['%asset%',            'asset',    'debit',  'BS', 'operating'],

        // ── Balance Sheet: Long-term / Non-Current Liabilities (Financing) ──
        ['%long term liability%','liability','credit','BS', 'financing'],
        ['%long-term liability%','liability','credit','BS', 'financing'],
        ['%non-current liability%','liability','credit','BS','financing'],
        ['%non current liability%','liability','credit','BS','financing'],
        ['%loan%',             'liability', 'credit', 'BS', 'financing'],
        ['%mortgage%',         'liability', 'credit', 'BS', 'financing'],

        // ── Balance Sheet: Current Liabilities (Operating) ─────────────
        ['%current liability%','liability', 'credit', 'BS', 'operating'],
        ['%accounts payable%', 'liability', 'credit', 'BS', 'operating'],
        ['%payable%',          'liability', 'credit', 'BS', 'operating'],
        ['%accrued%',          'liability', 'credit', 'BS', 'operating'],

        // ── Balance Sheet: generic liability (fallback) ────────────────
        ['%liability%',        'liability', 'credit', 'BS', 'operating'],

        // ── Balance Sheet: Equity (Financing) ──────────────────────────
        ['%equity%',           'equity',    'credit', 'BS', 'financing'],
        ['%capital%',          'equity',    'credit', 'BS', 'financing'],
        ['%retained earnings%','equity',    'credit', 'BS', 'financing'],
    ];

    $updateStmt = $pdo->prepare("
        UPDATE account_types
           SET category = :category,
               normal_side = :normal_side,
               statement = :statement,
               cash_flow_category = :cash_flow_category
         WHERE LOWER(type_name) LIKE :pattern
           AND category IS NULL
    ");

    $matchedTotal = 0;
    foreach ($seedRules as [$pattern, $category, $normalSide, $statement, $cashFlowCategory]) {
        $updateStmt->execute([
            ':pattern'            => $pattern,
            ':category'           => $category,
            ':normal_side'        => $normalSide,
            ':statement'          => $statement,
            ':cash_flow_category' => $cashFlowCategory,
        ]);
        $rows = $updateStmt->rowCount();
        if ($rows > 0) {
            $matchedTotal += $rows;
            echo "✓ Seeded $rows account_types matching '$pattern' → category=$category, side=$normalSide, statement=$statement, cf=$cashFlowCategory\n";
        }
    }

    // ── 3. Report on any account_types that remain unclassified ────────
    $unclassified = $pdo->query("
        SELECT type_id, type_name
          FROM account_types
         WHERE category IS NULL
         ORDER BY type_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($unclassified) {
        echo "\n⚠ The following account_types could not be classified automatically:\n";
        foreach ($unclassified as $row) {
            echo "   - [{$row['type_id']}] {$row['type_name']}\n";
        }
        echo "   They will appear with a warning on financial reports until classified.\n";
    } else {
        echo "\n✓ All account_types classified ($matchedTotal rows updated total).\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
