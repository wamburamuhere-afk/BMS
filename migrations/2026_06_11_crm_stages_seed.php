<?php
/**
 * 2026_06_11_crm_stages_seed.php
 * --------------------------------
 * Seeds the seven default pipeline stages into crm_pipeline_stages.
 * Skipped entirely if the table already contains rows (idempotent).
 *
 * Default stages:
 *   1  New Lead        #0d6efd  (blue)
 *   2  Contacted       #0dcaf0  (cyan)
 *   3  Qualified       #ffc107  (amber)
 *   4  Proposal Sent   #fd7e14  (orange)
 *   5  Negotiation     #6f42c1  (purple)
 *   6  Won             #198754  (green)  is_won = 1
 *   7  Lost            #dc3545  (red)    is_lost = 1
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: CRM pipeline stages seed...\n";

try {

    // Only seed if table is empty
    $existing = (int) $pdo->query("SELECT COUNT(*) FROM crm_pipeline_stages")->fetchColumn();

    if ($existing > 0) {
        echo "  · crm_pipeline_stages already has {$existing} row(s) — skipping seed.\n";
        echo "\nMigration complete.\n";
        exit(0);
    }

    $stages = [
        [1, 'New Lead',       '#0d6efd', 0, 0],
        [2, 'Contacted',      '#0dcaf0', 0, 0],
        [3, 'Qualified',      '#ffc107', 0, 0],
        [4, 'Proposal Sent',  '#fd7e14', 0, 0],
        [5, 'Negotiation',    '#6f42c1', 0, 0],
        [6, 'Won',            '#198754', 1, 0],
        [7, 'Lost',           '#dc3545', 0, 1],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO crm_pipeline_stages
            (stage_order, stage_name, color, is_won, is_lost, status)
        VALUES (?, ?, ?, ?, ?, 'active')
    ");

    foreach ($stages as [$order, $name, $color, $isWon, $isLost]) {
        $stmt->execute([$order, $name, $color, $isWon, $isLost]);
        echo "  + stage '{$name}' seeded.\n";
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
