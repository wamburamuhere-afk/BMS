<?php
/**
 * Project-Scope Admin UI — Phase A of project_scope_implementation_plan.md
 *
 * Lets an admin:
 *   1. Pick a user from the left-hand list
 *   2. Tick the projects they are allowed to interact with
 *   3. (Optional tab) add resource-type overrides (warehouse/supplier/customer/employee)
 *
 * Saves to user_projects + user_scope_overrides. After every save the
 * affected user's scope cache is invalidated via refreshScopeCache()
 * (only refreshes if they happen to be the current session user).
 */

$page_title = "User Project Assignments";
require_once __DIR__ . '/../../../roots.php';

// Permission gate (key seeded in 2026_05_24_project_scope_perm_seed.php).
autoEnforcePermission('user_projects');

global $pdo;

// ── Handle POST: save assignments for a specific user ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($user_id <= 0) {
        $_SESSION['scope_error'] = 'Invalid user_id submitted.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Project assignments — replace whole set for this user.
        $project_ids = isset($_POST['projects']) && is_array($_POST['projects'])
            ? array_map('intval', $_POST['projects'])
            : [];

        $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([$user_id]);

        if (!empty($project_ids)) {
            $ins = $pdo->prepare("
                INSERT IGNORE INTO user_projects (user_id, project_id, assigned_by)
                VALUES (?, ?, ?)
            ");
            foreach ($project_ids as $pid) {
                if ($pid > 0) $ins->execute([$user_id, $pid, $_SESSION['user_id']]);
            }
        }

        // Overrides — replace whole set for this user.
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$user_id]);

        if (!empty($_POST['overrides']) && is_array($_POST['overrides'])) {
            $insOv = $pdo->prepare("
                INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by)
                VALUES (?, ?, ?, ?)
            ");
            // Each $_POST['overrides'][resource_type] = 'all' | comma-separated IDs
            $allowed_types = ['warehouse', 'supplier', 'customer', 'employee'];
            foreach ($_POST['overrides'] as $type => $val) {
                if (!in_array($type, $allowed_types, true)) continue;
                $val = trim((string)$val);
                if ($val === '') continue;
                if ($val === 'all') {
                    $insOv->execute([$user_id, $type, null, $_SESSION['user_id']]);
                } else {
                    foreach (explode(',', $val) as $idRaw) {
                        $id = (int)trim($idRaw);
                        if ($id > 0) $insOv->execute([$user_id, $type, $id, $_SESSION['user_id']]);
                    }
                }
            }
        }

        $pdo->commit();

        if (function_exists('logActivity')) {
            logActivity($pdo, $_SESSION['user_id'], "Updated Project Scope",
                "user_id=$user_id projects=" . implode(',', $project_ids));
        }
        if (function_exists('refreshScopeCache')) {
            refreshScopeCache($user_id);
        }

        $_SESSION['scope_success'] = "Saved scope for user #$user_id.";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['scope_error'] = 'Save failed: ' . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?user=' . $user_id);
    exit;
}

require_once 'header.php';

// ── Load reference data ──────────────────────────────────────────────
$users    = $pdo->query("SELECT user_id, username, first_name, last_name, role_id, is_active
                         FROM users WHERE is_active = 1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT project_id, project_name, contract_number, status
                         FROM projects ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

$selected_user_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$selected_user    = null;
$assigned_ids     = [];
$overrides_by_type= ['warehouse' => '', 'supplier' => '', 'customer' => '', 'employee' => ''];

if ($selected_user_id > 0) {
    $stmt = $pdo->prepare("SELECT user_id, username, first_name, last_name, role_id, is_admin
                           FROM users WHERE user_id = ?");
    $stmt->execute([$selected_user_id]);
    $selected_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_user) {
        $stmt = $pdo->prepare("SELECT project_id FROM user_projects WHERE user_id = ?");
        $stmt->execute([$selected_user_id]);
        $assigned_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt = $pdo->prepare("SELECT resource_type, resource_id
                               FROM user_scope_overrides WHERE user_id = ?");
        $stmt->execute([$selected_user_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = $r['resource_type'];
            if (!isset($overrides_by_type[$t])) continue;
            if ($r['resource_id'] === null) {
                $overrides_by_type[$t] = 'all';
            } else {
                $cur = $overrides_by_type[$t];
                $overrides_by_type[$t] = $cur === '' ? (string)$r['resource_id'] : "$cur,{$r['resource_id']}";
            }
        }
    }
}

$success = $_SESSION['scope_success'] ?? null;
$error   = $_SESSION['scope_error']   ?? null;
unset($_SESSION['scope_success'], $_SESSION['scope_error']);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>User Project Assignments</h4>
        <small class="text-muted">Project-scope access control — admin assigns each user to one or more projects.</small>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success py-2"><?= safe_output($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= safe_output($error) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- LEFT: user list -->
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-bold"><i class="bi bi-people me-1"></i> Users</div>
                <div class="list-group list-group-flush" style="max-height: 600px; overflow-y: auto;">
                    <?php foreach ($users as $u): ?>
                        <a href="?user=<?= (int)$u['user_id'] ?>"
                           class="list-group-item list-group-item-action
                                  <?= $selected_user_id === (int)$u['user_id'] ? 'active' : '' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold"><?= safe_output($u['username']) ?></div>
                                    <small class="<?= $selected_user_id === (int)$u['user_id'] ? 'text-white-50' : 'text-muted' ?>">
                                        <?= safe_output(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?>
                                    </small>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: project + overrides form -->
        <div class="col-12 col-md-8">
            <?php if (!$selected_user): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-1"></i>
                    Pick a user from the left to manage their project assignments.
                </div>
            <?php else: ?>
                <?php if (!empty($selected_user['is_admin'])): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-shield-check me-1"></i>
                        <strong><?= safe_output($selected_user['username']) ?></strong> is an administrator
                        and automatically has access to <strong>all projects and resources</strong>.
                        Assignments below are ignored for admin users.
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$selected_user['user_id'] ?>">

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <span class="fw-bold">
                                <i class="bi bi-list-check me-1"></i>
                                Projects for <?= safe_output($selected_user['username']) ?>
                            </span>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        onclick="document.querySelectorAll('input[name=\'projects[]\']').forEach(c=>c.checked=true)">
                                    Select all
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        onclick="document.querySelectorAll('input[name=\'projects[]\']').forEach(c=>c.checked=false)">
                                    Clear
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($projects)): ?>
                                <p class="text-muted mb-0">No projects in the system yet.</p>
                            <?php else: ?>
                                <div class="row g-2">
                                    <?php foreach ($projects as $p): ?>
                                        <?php $checked = in_array((int)$p['project_id'], $assigned_ids, true); ?>
                                        <div class="col-12 col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                       name="projects[]"
                                                       value="<?= (int)$p['project_id'] ?>"
                                                       id="proj_<?= (int)$p['project_id'] ?>"
                                                       <?= $checked ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="proj_<?= (int)$p['project_id'] ?>">
                                                    <?= safe_output($p['project_name']) ?>
                                                    <small class="text-muted">
                                                        (<?= safe_output($p['contract_number'] ?: '—') ?> · <?= safe_output($p['status']) ?>)
                                                    </small>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-light fw-bold">
                            <i class="bi bi-shield-plus me-1"></i>
                            Resource overrides
                            <small class="text-muted ms-2">
                                (rare — grant cross-project visibility on a specific resource type)
                            </small>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Leave a field empty to derive from projects above.
                                Type <code>all</code> to grant every record of that type.
                                Otherwise enter comma-separated IDs (e.g., <code>3,7,12</code>).
                            </p>
                            <?php foreach (['warehouse','supplier','customer','employee'] as $type): ?>
                                <div class="row align-items-center mb-2">
                                    <label class="col-sm-3 col-form-label-sm text-capitalize">
                                        <?= $type ?>s
                                    </label>
                                    <div class="col-sm-9">
                                        <input type="text" class="form-control form-control-sm"
                                               name="overrides[<?= $type ?>]"
                                               placeholder="empty / all / 3,7,12"
                                               value="<?= safe_output($overrides_by_type[$type]) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Save Assignments
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
