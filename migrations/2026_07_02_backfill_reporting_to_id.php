<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: backfill employees.reporting_to_id from legacy reporting_to names (D14)...\n";

try {
    if (!$pdo->query("SHOW COLUMNS FROM employees LIKE 'reporting_to_id'")->fetch()) {
        echo "Column reporting_to_id missing — run 2026_07_02_hr_compliance_foundation first.\n";
        exit(1);
    }

    // Candidates: rows with a legacy name but no FK yet (idempotent — only fills NULLs)
    $candidates = $pdo->query("
        SELECT employee_id, reporting_to
        FROM employees
        WHERE reporting_to_id IS NULL
          AND reporting_to IS NOT NULL AND TRIM(reporting_to) != ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$candidates) {
        echo "Nothing to backfill (0 candidates).\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // Exact UNIQUE full-name match against active employees only (criteria-based,
    // no hard-coded ids). Ambiguous or no-match rows are left untouched.
    $match = $pdo->prepare("
        SELECT employee_id
        FROM employees
        WHERE TRIM(CONCAT_WS(' ', first_name, last_name)) = ?
          AND employment_status = 'active'
    ");
    $set = $pdo->prepare("UPDATE employees SET reporting_to_id = ? WHERE employee_id = ? AND reporting_to_id IS NULL");

    $matched = 0; $ambiguous = 0; $nomatch = 0; $selfref = 0;
    foreach ($candidates as $row) {
        $name = trim($row['reporting_to']);
        $match->execute([$name]);
        $ids = $match->fetchAll(PDO::FETCH_COLUMN);

        if (count($ids) === 1) {
            $manager_id = (int)$ids[0];
            if ($manager_id === (int)$row['employee_id']) { $selfref++; continue; }  // never self-manage
            $set->execute([$manager_id, (int)$row['employee_id']]);
            $matched++;
        } elseif (count($ids) > 1) {
            $ambiguous++;
        } else {
            $nomatch++;
        }
    }

    echo "Backfill done: matched=$matched, ambiguous-skipped=$ambiguous, no-match-skipped=$nomatch, self-ref-skipped=$selfref (of " . count($candidates) . " candidates).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
