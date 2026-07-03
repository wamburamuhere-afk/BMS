<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: HR compliance permissions + notification events (Tier 2)...\n";

try {
    // Full access (CRUD + approve) = admin-flagged roles + whichever roles hold
    // can_edit on 'employees' on THIS server — resolved at runtime, never
    // hard-coded ids (live-system rule). Everyone else = view-only.
    $fullRoles = $pdo->query("
        SELECT DISTINCT r.role_id
        FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.role_id
        LEFT JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'employees'
        WHERE r.is_admin = 1 OR (p.page_key = 'employees' AND rp.can_edit = 1)
    ")->fetchAll(PDO::FETCH_COLUMN);
    $fullRoles = array_map('intval', $fullRoles);

    $hasReview  = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_review'")->fetch();
    $hasApprove = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();
    $roles = $pdo->query("SELECT role_id FROM roles")->fetchAll(PDO::FETCH_COLUMN);

    // page_key => [permission_name/page_name]
    $pages = [
        'employee_documents' => 'Employee Documents',
        'employee_contracts' => 'Employee Contracts',
        'org_chart'          => 'Organisation Chart',
        'hr_expiry_alerts'   => 'HR Expiry Alerts',   // RBAC recipient switch for the D13 cron
    ];

    foreach ($pages as $key => $name) {
        $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
        $existing->execute([$key]);
        if (!$existing->fetch()) {
            $pdo->prepare("INSERT IGNORE INTO permissions (page_key, permission_name, page_name, module_name) VALUES (?, ?, ?, ?)")
                ->execute([$key, $name, $name, 'Human Resources']);
            echo "  + permission '$key' inserted.\n";
        } else {
            echo "  · permission '$key' already exists.\n";
        }

        $stmt = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
        $stmt->execute([$key]);
        $pid = (int)$stmt->fetchColumn();
        if (!$pid) { echo "  ! could not resolve permission_id for '$key' — skipping roles.\n"; continue; }

        foreach ($roles as $rid) {
            $rid    = (int)$rid;
            $isFull = in_array($rid, $fullRoles, true);

            $cols  = 'role_id, permission_id, can_view, can_create, can_edit, can_delete';
            $vals  = [$rid, $pid, 1, $isFull ? 1 : 0, $isFull ? 1 : 0, $isFull ? 1 : 0];
            $marks = '?, ?, ?, ?, ?, ?';
            if ($hasReview)  { $cols .= ', can_review';  $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }
            if ($hasApprove) { $cols .= ', can_approve'; $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }

            $pdo->prepare("INSERT IGNORE INTO role_permissions ($cols) VALUES ($marks)")->execute($vals);
        }
        echo "    roles assigned for '$key' (" . count($fullRoles) . " full, rest view-only).\n";
    }

    // ── notification_events rows for the D13 HR expiry cron ─────────────────
    // Format per the 2026_06_28 catalog: key, title, desc, module, page_key, verb, severity, scope_aware
    $events = [
        ['hr_contract_expiry', 'Employee contract expiring', 'An employee contract is nearing its end date',      'Human Resources', 'hr_expiry_alerts', 'view', 'high',   0],
        ['hr_probation_end',   'Probation period ending',    'An employee probation period is nearing its end',   'Human Resources', 'hr_expiry_alerts', 'view', 'medium', 0],
    ];
    $seed = $pdo->prepare("
        INSERT IGNORE INTO notification_events
            (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $n = 0;
    foreach ($events as $e) { $seed->execute($e); $n += $seed->rowCount(); }
    echo "  + notification_events seeded ($n new of " . count($events) . ").\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
