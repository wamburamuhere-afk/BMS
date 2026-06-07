<?php
/**
 * 2026_06_07_classify_standard_chart.php
 * --------------------------------------
 * scope-audit: skip — classification backfill on the standard chart (accounts only).
 *
 * Completes the classification of the seeded standard chart of accounts so every
 * part of the system communicates with it correctly:
 *   - cash_flow_category : routes accounts on the Cash Flow Statement AND decides
 *                          which accounts are offered as cash/bank payment sources
 *                          (cashBankAccounts() needs cash_flow_category = 'cash').
 *   - is_current         : Balance Sheet current vs non-current split (IAS 1).
 *
 * Mapping (by the standard 1-/2-/3-/4-/6- code scheme):
 *   Cash on hand leaves (1-11xx)            → cash / current
 *   Other current assets (1-12xx,1-13xx,
 *     1-19xx withholding, 1-2xxx other)     → operating / current
 *   Fixed assets (1-3xxx)                   → investing / non-current
 *   Current liabilities (2-1xxx)            → operating / current
 *   Long-term liabilities (2-2xxx)          → financing / non-current
 *   Equity (3-xxxx)                         → financing
 *   Income (4-xxxx)                         → operating
 *   Expenses (6-xxxx)                       → operating
 *
 * Idempotent: pure UPDATEs by code pattern; safe to re-run. Only touches rows
 * whose code matches the standard chart (won't disturb your other accounts).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: classify standard chart (cash_flow_category + is_current)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  accounts table missing — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // [ description, REGEXP on account_code, cash_flow_category, is_current|null ]
    $rules = [
        ['current assets header (1-1000)','^1-1000$',         'operating', 1],
        ['cash on hand (1-11xx)',        '^1-11[0-9][0-9]$', 'cash',      1],
        ['other current assets (1-12xx)','^1-12[0-9][0-9]$', 'operating', 1],
        ['inventory (1-13xx)',           '^1-13[0-9][0-9]$', 'operating', 1],
        ['withholding credits (1-19xx)', '^1-19[0-9][0-9]$', 'operating', 1],
        ['other assets (1-2xxx)',        '^1-2[0-9][0-9][0-9]$', 'operating', 1],
        ['fixed assets (1-3xxx)',        '^1-3[0-9][0-9][0-9]$', 'investing', 0],
        ['current liabilities (2-1xxx)', '^2-1[0-9][0-9][0-9]$', 'operating', 1],
        ['long-term liabilities (2-2xxx)','^2-2[0-9][0-9][0-9]$','financing', 0],
        ['equity (3-xxxx)',              '^3-[0-9][0-9][0-9][0-9]$', 'financing', null],
        ['income (4-xxxx)',              '^4-[0-9][0-9][0-9][0-9]$', 'operating', null],
        ['expenses (6-xxxx)',            '^6-[0-9][0-9][0-9][0-9]$', 'operating', null],
        // the section roots (1-0000 … 6-0000) — group headers; classify by first digit
        ['asset root (1-0000)',          '^1-0000$', 'operating', 1],
        ['liability root (2-0000)',      '^2-0000$', 'operating', 1],
        ['equity root (3-0000)',         '^3-0000$', 'financing', null],
        ['income root (4-0000)',         '^4-0000$', 'operating', null],
        ['expense root (6-0000)',        '^6-0000$', 'operating', null],
    ];

    $total = 0;
    foreach ($rules as [$label, $rx, $cf, $cur]) {
        if ($cur === null) {
            $st = $pdo->prepare("UPDATE accounts SET cash_flow_category = ? WHERE account_code REGEXP ?");
            $st->execute([$cf, $rx]);
        } else {
            $st = $pdo->prepare("UPDATE accounts SET cash_flow_category = ?, is_current = ? WHERE account_code REGEXP ?");
            $st->execute([$cf, $cur, $rx]);
        }
        $n = $st->rowCount();
        if ($n > 0) { $total += $n; echo "  + $label → cf=$cf" . ($cur !== null ? ", is_current=$cur" : "") . " ({$n})\n"; }
    }

    echo "\n  Classified $total standard-chart account(s).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
