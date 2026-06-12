<?php
/**
 * 2026_06_11_crm_permissions_seed.php
 * -------------------------------------
 * Seeds the five CRM page-keys into the permissions table and grants
 * them to every role according to the plan:
 *
 *   page_key          module     description
 *   ─────────────     ────────   ──────────────────────────────────────
 *   crm_dashboard     CRM        CRM overview dashboard + charts
 *   crm_leads         CRM        Lead list, add, edit, delete
 *   crm_pipeline      CRM        Kanban pipeline board + stage management
 *   crm_activities    CRM        Activity log per lead
 *   crm_convert       CRM        Convert a won lead to Customer + Quotation
 *
 * Role grants (roles that exist in this DB):
 *   1  Admin               — full on all 5
 *   2  Managing Director   — full on all 5
 *   5  Director            — full on all 5
 *   4  Staff               — full on leads + activities; view pipeline; no convert
 *   6  CFO                 — view only on dashboard + leads
 *   7  Accountant          — view only on dashboard + leads
 *   8  Credit Manager      — view + create + edit on leads + activities; view pipeline
 *  11  Secretary (PS)      — view only on dashboard + leads
 *
 * Idempotent: INSERT IGNORE on permissions; INSERT … ON DUPLICATE KEY UPDATE
 * on role_permissions so re-running is always safe.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: CRM permissions seed...\n";

try {

    // ── 1. Upsert permission rows ─────────────────────────────────────────
    $perms = [
        ['crm_dashboard',  'CRM Dashboard',       'CRM', 'CRM overview — KPI cards, pipeline charts, recent activity'],
        ['crm_leads',      'CRM Leads',            'CRM', 'Lead list — add, view, edit, delete, export'],
        ['crm_pipeline',   'CRM Pipeline Board',   'CRM', 'Kanban pipeline board and stage management'],
        ['crm_activities', 'CRM Lead Activities',  'CRM', 'Activity log (calls, meetings, notes) per lead'],
        ['crm_convert',    'CRM Convert Lead',     'CRM', 'Convert a won lead to a Customer record and Quotation'],
    ];

    $insStmt = $pdo->prepare("
        INSERT IGNORE INTO permissions
            (permission_name, page_key, page_name, description, module_name, is_hidden)
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    foreach ($perms as [$key, $name, $module, $desc]) {
        $insStmt->execute([$name, $key, $name, $desc, $module]);
        echo "  · permission '{$key}' ensured.\n";
    }

    // ── 2. Build permission_id map ────────────────────────────────────────
    $idMap = [];
    $rows = $pdo->query("SELECT page_key, permission_id FROM permissions WHERE page_key LIKE 'crm_%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $idMap[$r['page_key']] = (int)$r['permission_id'];
    }

    if (count($idMap) < 5) {
        throw new Exception('Could not resolve all 5 CRM permission IDs. Found: ' . implode(',', array_keys($idMap)));
    }

    echo "  · permission IDs resolved: " . implode(', ', array_map(fn($k,$v) => "$k=$v", array_keys($idMap), $idMap)) . "\n";

    // ── 3. Define role grants ─────────────────────────────────────────────
    // Format: role_id => [ page_key => [can_view, can_create, can_edit, can_delete] ]
    $grants = [
        // ─── Admin (1) — full everything ──────────────────────────────────
        1 => [
            'crm_dashboard'  => [1,1,1,1],
            'crm_leads'      => [1,1,1,1],
            'crm_pipeline'   => [1,1,1,1],
            'crm_activities' => [1,1,1,1],
            'crm_convert'    => [1,1,1,1],
        ],
        // ─── Managing Director (2) — full everything ─────────────────────
        2 => [
            'crm_dashboard'  => [1,1,1,1],
            'crm_leads'      => [1,1,1,1],
            'crm_pipeline'   => [1,1,1,1],
            'crm_activities' => [1,1,1,1],
            'crm_convert'    => [1,1,1,1],
        ],
        // ─── Director (5) — full everything ──────────────────────────────
        5 => [
            'crm_dashboard'  => [1,1,1,1],
            'crm_leads'      => [1,1,1,1],
            'crm_pipeline'   => [1,1,1,1],
            'crm_activities' => [1,1,1,1],
            'crm_convert'    => [1,1,1,1],
        ],
        // ─── Staff (4) — full leads + activities; view pipeline; no convert
        4 => [
            'crm_dashboard'  => [1,0,0,0],
            'crm_leads'      => [1,1,1,0],
            'crm_pipeline'   => [1,1,1,0],
            'crm_activities' => [1,1,1,1],
            'crm_convert'    => [0,0,0,0],
        ],
        // ─── CFO (6) — view only ─────────────────────────────────────────
        6 => [
            'crm_dashboard'  => [1,0,0,0],
            'crm_leads'      => [1,0,0,0],
            'crm_pipeline'   => [1,0,0,0],
            'crm_activities' => [1,0,0,0],
            'crm_convert'    => [0,0,0,0],
        ],
        // ─── Accountant (7) — view only ──────────────────────────────────
        7 => [
            'crm_dashboard'  => [1,0,0,0],
            'crm_leads'      => [1,0,0,0],
            'crm_pipeline'   => [1,0,0,0],
            'crm_activities' => [1,0,0,0],
            'crm_convert'    => [0,0,0,0],
        ],
        // ─── Credit Manager (8) — view+create+edit on leads+activities ───
        8 => [
            'crm_dashboard'  => [1,0,0,0],
            'crm_leads'      => [1,1,1,0],
            'crm_pipeline'   => [1,1,1,0],
            'crm_activities' => [1,1,1,1],
            'crm_convert'    => [0,0,0,0],
        ],
        // ─── Secretary / PS (11) — view only ─────────────────────────────
        11 => [
            'crm_dashboard'  => [1,0,0,0],
            'crm_leads'      => [1,0,0,0],
            'crm_pipeline'   => [1,0,0,0],
            'crm_activities' => [1,0,0,0],
            'crm_convert'    => [0,0,0,0],
        ],
    ];

    // ── 4. Upsert role_permissions rows ───────────────────────────────────
    $rpStmt = $pdo->prepare("
        INSERT INTO role_permissions
            (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
        VALUES (?, ?, ?, ?, ?, ?, 0, 0)
        ON DUPLICATE KEY UPDATE
            can_view   = VALUES(can_view),
            can_create = VALUES(can_create),
            can_edit   = VALUES(can_edit),
            can_delete = VALUES(can_delete)
    ");

    // Verify ON DUPLICATE KEY needs a unique constraint on (role_id, permission_id).
    // Check if the constraint exists; add it if missing (idempotent).
    $dup = $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'role_permissions'
          AND CONSTRAINT_TYPE = 'UNIQUE'
    ")->fetchColumn();

    if (!$dup) {
        // Try to add — will be a no-op if there is already a different unique key
        try {
            $pdo->exec("ALTER TABLE role_permissions ADD UNIQUE KEY uq_role_perm (role_id, permission_id)");
            echo "  + added UNIQUE constraint on role_permissions(role_id, permission_id).\n";
        } catch (PDOException $ex) {
            // May already exist under a different name — ignore duplicate-key error
            if (strpos($ex->getMessage(), '1061') === false) throw $ex;
        }
    }

    // Only grant to roles that ACTUALLY EXIST on this database. Production hosts
    // may have a different role set than dev, so hard-coded role_ids would break
    // the role_permissions → roles foreign key (error 1452). Criteria-based +
    // idempotent: skip any role this host doesn't have.
    $existingRoles = array_map('intval', $pdo->query("SELECT role_id FROM roles")->fetchAll(PDO::FETCH_COLUMN));

    $count = 0; $skipped = [];
    foreach ($grants as $roleId => $pageGrants) {
        if (!in_array((int)$roleId, $existingRoles, true)) {
            $skipped[] = $roleId;                    // role not present on this host
            continue;
        }
        foreach ($pageGrants as $pageKey => $flags) {
            if (!isset($idMap[$pageKey])) continue;
            $rpStmt->execute([
                $roleId,
                $idMap[$pageKey],
                $flags[0], $flags[1], $flags[2], $flags[3],
            ]);
            $count++;
        }
    }

    echo "  · {$count} role_permission rows inserted / updated.\n";
    if ($skipped) {
        echo "  ~ skipped role_id(s) not present on this host: " . implode(', ', $skipped) . "\n";
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
