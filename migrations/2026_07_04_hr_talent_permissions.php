<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: HR talent & engagement permissions (Tier 4)...\n";

try {
    // Full access = admin-flagged roles + whichever roles hold can_edit on
    // 'employees' — resolved at runtime, never hard-coded ids (live-system rule).
    $fullRoles = $pdo->query("
        SELECT DISTINCT r.role_id
        FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.role_id
        LEFT JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'employees'
        WHERE r.is_admin = 1 OR (p.page_key = 'employees' AND rp.can_edit = 1)
    ")->fetchAll(PDO::FETCH_COLUMN);
    $fullRoles = array_map('intval', $fullRoles);

    $hasApprove = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();
    $hasReject  = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_reject'")->fetch();
    $hasPublish = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_publish'")->fetch();
    $roles = $pdo->query("SELECT role_id FROM roles")->fetchAll(PDO::FETCH_COLUMN);

    // page_key => display name. my_hr is special: EVERY role gets can_view = 1
    // (the page shows only the session user's own data — D24 makes that safe).
    $pages = [
        'announcements'  => 'Announcements',
        'meetings'       => 'Meetings',
        'employee_trips' => 'Business Trips',
        'hr_checklists'  => 'HR Checklists',
        'recruitment'    => 'Recruitment',
        'my_hr'          => 'My HR',
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

        $everyoneViews = ($key === 'my_hr');

        foreach ($roles as $rid) {
            $rid    = (int)$rid;
            $isFull = in_array($rid, $fullRoles, true);
            // my_hr: view for all; management verbs never apply (self-service page)
            if ($everyoneViews) {
                $cols = 'role_id, permission_id, can_view, can_create, can_edit, can_delete';
                $vals = [$rid, $pid, 1, 0, 0, 0];
                $marks = '?, ?, ?, ?, ?, ?';
            } else {
                $cols = 'role_id, permission_id, can_view, can_create, can_edit, can_delete';
                $vals = [$rid, $pid, $isFull ? 1 : 0, $isFull ? 1 : 0, $isFull ? 1 : 0, $isFull ? 1 : 0];
                $marks = '?, ?, ?, ?, ?, ?';
                if ($hasApprove) { $cols .= ', can_approve'; $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }
                if ($hasReject)  { $cols .= ', can_reject';  $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }
                if ($hasPublish) { $cols .= ', can_publish'; $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }
            }
            $pdo->prepare("INSERT IGNORE INTO role_permissions ($cols) VALUES ($marks)")->execute($vals);
        }
        echo "    roles assigned for '$key'" . ($everyoneViews ? " (view for ALL roles — self-service)" : " (" . count($fullRoles) . " full, rest view-only)") . ".\n";
    }

    // notification_events row for announcements (D25) — per the 2026_06_28 catalog format
    $ev = $pdo->prepare("INSERT IGNORE INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $ev->execute(['hr_announcement', 'New announcement', 'A new company announcement was published', 'Human Resources', 'my_hr', 'view', 'medium', 0]);
    echo "  + notification_events 'hr_announcement' seeded.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
