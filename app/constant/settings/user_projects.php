<?php
/**
 * Project-Scope + Warehouse-Access Assignment UI
 *
 * Three-level drill-down (mirrors user_roles.php style):
 *   1. Roles list  →  click a role
 *   2. Users in that role  →  click a user
 *   3. Project checkboxes (writes user_projects) + warehouse access
 *      (writes user_scope_overrides, resource_type='warehouse') → one save
 *
 * Warehouse access has two independent panels, both feeding the same save:
 *   - Project-linked warehouses: ticking a project reveals that project's own
 *     warehouses (warehouses.project_id = <project>) as sub-checkboxes.
 *   - External warehouses: warehouses.project_id IS NULL, always shown.
 *   - "Grant access to ALL warehouses" writes a single resource_id=NULL
 *     override row (loadUserScope() in core/project_scope.php already
 *     interprets that as unrestricted) and supersedes both checklists.
 */

// scope-audit: skip — admin-only project assignment UI; intentionally shows all projects for assignment configuration
$page_title = "Project Assignments";
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('user_projects');
global $pdo;

// ── AJAX: return user's current assignments as JSON ───────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_assignments') {
    header('Content-Type: application/json');
    $uid = intval($_GET['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['projects' => [], 'warehouses' => [], 'grant_all_warehouses' => false]); exit; }

    $stmt = $pdo->prepare("SELECT project_id FROM user_projects WHERE user_id = ?");
    $stmt->execute([$uid]);
    $projectIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmtW = $pdo->prepare("SELECT resource_id FROM user_scope_overrides WHERE user_id = ? AND resource_type = 'warehouse'");
    $stmtW->execute([$uid]);
    $rawWarehouseRows = $stmtW->fetchAll(PDO::FETCH_COLUMN);
    $grantAllWarehouses = in_array(null, $rawWarehouseRows, true);
    $warehouseIds = array_map('intval', array_filter($rawWarehouseRows, fn($v) => $v !== null));

    echo json_encode([
        'projects' => $projectIds,
        'warehouses' => $warehouseIds,
        'grant_all_warehouses' => $grantAllWarehouses,
    ]);
    exit;
}

// ── POST: save assignments for a user ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $user_id     = intval($_POST['user_id'] ?? 0);
    $project_ids = isset($_POST['projects']) && is_array($_POST['projects'])
        ? array_map('intval', $_POST['projects'])
        : [];
    $warehouse_ids = isset($_POST['warehouses']) && is_array($_POST['warehouses'])
        ? array_values(array_unique(array_filter(array_map('intval', $_POST['warehouses']), fn($id) => $id > 0)))
        : [];
    $grant_all_warehouses = !empty($_POST['grant_all_warehouses']);

    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid user.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([$user_id]);
        if (!empty($project_ids)) {
            $ins = $pdo->prepare("INSERT IGNORE INTO user_projects (user_id, project_id, assigned_by) VALUES (?, ?, ?)");
            foreach ($project_ids as $pid) {
                if ($pid > 0) $ins->execute([$user_id, $pid, $_SESSION['user_id']]);
            }
        }

        // Warehouse access — same full-replace pattern, one combined save so
        // the "project-linked" and "external" panels can never clobber each
        // other (both panels feed this single desired set).
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ? AND resource_type = 'warehouse'")->execute([$user_id]);
        if ($grant_all_warehouses) {
            $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', NULL, ?)")
                ->execute([$user_id, $_SESSION['user_id']]);
        } elseif (!empty($warehouse_ids)) {
            $insW = $pdo->prepare("INSERT IGNORE INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, ?)");
            foreach ($warehouse_ids as $wid) {
                $insW->execute([$user_id, $wid, $_SESSION['user_id']]);
            }
        }

        $pdo->commit();

        logActivity($pdo, $_SESSION['user_id'], 'Updated Project Scope',
            "user_id=$user_id projects=" . implode(',', $project_ids));
        $warehouseLogDetail = $grant_all_warehouses ? 'ALL' : implode(',', $warehouse_ids);
        logActivity($pdo, $_SESSION['user_id'], 'Updated Warehouse Access',
            "user_id=$user_id warehouses=$warehouseLogDetail");
        logAudit($pdo, $_SESSION['user_id'], 'user_warehouse_access_updated', [
            'activity_type' => 'access_control',
            'description'   => "Updated warehouse access for user_id=$user_id",
            'entity_type'   => 'user',
            'entity_id'     => $user_id,
            'new_values'    => ['grant_all_warehouses' => $grant_all_warehouses, 'warehouse_ids' => $warehouse_ids],
        ]);
        if (function_exists('refreshScopeCache')) refreshScopeCache($user_id);

        // Fetch user name for response
        $uStmt = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM users WHERE user_id = ?");
        $uStmt->execute([$user_id]);
        $uname = $uStmt->fetchColumn() ?: "User #$user_id";

        $warehouseSummary = $grant_all_warehouses ? 'ALL warehouses' : (count($warehouse_ids) . ' warehouse(s)');
        echo json_encode([
            'success' => true,
            'message' => "Saved " . count($project_ids) . " project(s) and {$warehouseSummary} for {$uname}.",
            'count'   => count($project_ids),
            'warehouse_count' => $grant_all_warehouses ? -1 : count($warehouse_ids),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
    }
    exit;
}

// ── Load page data ─────────────────────────────────────────────────────────
$roles = $pdo->query("
    SELECT r.role_id, r.role_name, r.description,
           COUNT(u.user_id) AS user_count
    FROM roles r
    LEFT JOIN users u ON u.role_id = r.role_id AND u.is_active = 1
    GROUP BY r.role_id
    ORDER BY r.role_name
")->fetchAll(PDO::FETCH_ASSOC);

$projects = $pdo->query("
    SELECT project_id, project_name, contract_number, status
    FROM projects
    ORDER BY project_name
")->fetchAll(PDO::FETCH_ASSOC);

// All active warehouses — embedded as JS data (project-linked + external
// panels are both filtered client-side from this one list, same convention
// as $all_users / $projects below).
$warehouses = $pdo->query("
    SELECT warehouse_id, warehouse_name, warehouse_code, project_id
    FROM warehouses
    WHERE status = 'active'
    ORDER BY warehouse_name
")->fetchAll(PDO::FETCH_ASSOC);

// All active users with their role — embedded as JS data to avoid extra AJAX
$all_users = $pdo->query("
    SELECT u.user_id, u.username,
           CONCAT(u.first_name,' ',u.last_name) AS full_name,
           u.role_id,
           COALESCE(u.is_admin, 0) AS is_admin,
           (SELECT COUNT(*) FROM user_projects up WHERE up.user_id = u.user_id) AS assignment_count,
           (SELECT COUNT(*) FROM user_scope_overrides uso WHERE uso.user_id = u.user_id AND uso.resource_type = 'warehouse') AS warehouse_grant_count
    FROM users u
    WHERE u.is_active = 1
    ORDER BY u.first_name, u.last_name
")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM roles)        AS total_roles,
        (SELECT COUNT(*) FROM users WHERE is_active=1) AS total_users,
        (SELECT COUNT(*) FROM projects)     AS total_projects,
        (SELECT COUNT(*) FROM user_projects) AS total_assignments,
        (SELECT COUNT(*) FROM warehouses WHERE status='active') AS total_warehouses,
        (SELECT COUNT(*) FROM user_scope_overrides WHERE resource_type='warehouse') AS total_warehouse_grants
")->fetch(PDO::FETCH_ASSOC);

require_once 'header.php';
?>

<div class="container-fluid mt-4">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-diagram-3"></i> Project Assignments</h2>
            <p class="text-muted">Assign users to projects. Each user only sees data that belongs to their assigned projects.</p>
        </div>
    </div>

    <!-- Stat Cards -->
    <style>
        .custom-stat-card {
            background-color: #d1e7dd !important;
            border-color: #badbcc !important;
            transition: transform 0.2s;
            border-radius: 12px;
        }
        .custom-stat-card:hover { transform: translateY(-3px); }
        .custom-stat-card h4, .custom-stat-card p,
        .custom-stat-card i, .custom-stat-card .small { color: #0f5132 !important; }
    </style>
    <div class="row mb-4">
        <?php foreach ([
            ['total_roles',       'Roles',              'bi-person-badge'],
            ['total_users',       'Active Users',        'bi-people'],
            ['total_projects',    'Projects',            'bi-briefcase'],
            ['total_assignments', 'Scope Assignments',   'bi-diagram-3'],
            ['total_warehouses',       'Warehouses',         'bi-building'],
            ['total_warehouse_grants', 'Warehouse Grants',   'bi-key'],
        ] as [$key, $label, $icon]): ?>
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= number_format((int)$stats[$key]) ?></h4>
                            <p class="small mb-0 opacity-75 text-uppercase"><?= $label ?></p>
                        </div>
                        <i class="bi <?= $icon ?> opacity-50 fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 3-Column drill-down -->
    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="row g-0" style="min-height:520px;">

                <!-- COL 1: Roles -->
                <div class="col-12 col-md-3 border-end">
                    <div class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-uppercase text-muted">
                            <i class="bi bi-person-badge me-1"></i>System Roles
                        </span>
                    </div>
                    <div class="list-group list-group-flush" id="roleList" style="max-height:520px;overflow-y:auto;">
                        <?php foreach ($roles as $r): ?>
                        <button type="button"
                                class="list-group-item list-group-item-action role-item d-flex justify-content-between align-items-center py-3"
                                data-role-id="<?= (int)$r['role_id'] ?>"
                                data-role-name="<?= htmlspecialchars($r['role_name']) ?>">
                            <div>
                                <div class="fw-bold"><?= safe_output($r['role_name']) ?></div>
                                <small class="text-muted"><?= safe_output($r['description'] ?: '—') ?></small>
                            </div>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$r['user_count'] ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- COL 2: Users in selected role -->
                <div class="col-12 col-md-3 border-end">
                    <div class="p-3 border-bottom bg-light">
                        <span class="fw-bold small text-uppercase text-muted">
                            <i class="bi bi-people me-1"></i><span id="col2-heading">Users</span>
                        </span>
                    </div>
                    <div id="userList" style="max-height:520px;overflow-y:auto;">
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-arrow-left fs-4 d-block mb-2"></i>
                            Select a role to see its users
                        </div>
                    </div>
                </div>

                <!-- COL 3: Project + warehouse access for selected user -->
                <div class="col-12 col-md-6">
                    <div class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-uppercase text-muted">
                            <i class="bi bi-briefcase me-1"></i><span id="col3-heading">Access Assignments</span>
                        </span>
                        <div id="col3-actions" class="d-none">
                            <span class="text-muted small me-2 d-none d-lg-inline">Projects:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1" id="btnSelectAll">All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearAll">None</button>
                        </div>
                    </div>
                    <div id="projectPanel" style="max-height:460px;overflow-y:auto;">
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-diagram-3 fs-4 d-block mb-2"></i>
                            Select a user to manage their project and warehouse access
                        </div>
                    </div>
                    <!-- Save bar — hidden until a user is selected -->
                    <div id="saveBar" class="d-none border-top p-3 d-flex justify-content-between align-items-center bg-white">
                        <small class="text-muted" id="saveHint">Tick the projects and warehouses this user may access.</small>
                        <button type="button" class="btn btn-primary px-4" id="btnSave">
                            <i class="bi bi-check-circle me-1"></i> Save Assignments
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
(function () {
    // ── Data embedded from PHP ────────────────────────────────────────────
    const ALL_USERS      = <?= json_encode(array_values($all_users), JSON_HEX_TAG) ?>;
    const ALL_PROJECTS   = <?= json_encode(array_values($projects),  JSON_HEX_TAG) ?>;
    const ALL_WAREHOUSES = <?= json_encode(array_values($warehouses), JSON_HEX_TAG) ?>;
    const SAVE_URL     = '<?= buildUrl('user_projects') ?>';

    let selectedUserId   = null;
    let selectedUserName = '';

    // ── Helpers ───────────────────────────────────────────────────────────
    function statusBadge(status) {
        const map = { active: 'success', completed: 'info', on_hold: 'warning', cancelled: 'secondary' };
        return `<span class="badge bg-${map[status] || 'secondary'}">${status}</span>`;
    }

    function safeHtml(s) {
        const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML;
    }

    // ── COL 1 → COL 2: load users for a role ─────────────────────────────
    document.querySelectorAll('.role-item').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.role-item').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const roleId   = parseInt(this.dataset.roleId);
            const roleName = this.dataset.roleName;

            document.getElementById('col2-heading').textContent = roleName;

            const users = ALL_USERS.filter(u => u.role_id == roleId);

            if (!users.length) {
                document.getElementById('userList').innerHTML =
                    `<div class="p-4 text-center text-muted">No active users in this role.</div>`;
                return;
            }

            let html = '';
            users.forEach(u => {
                const badge = u.is_admin == 1
                    ? `<span class="badge project-badge bg-danger rounded-pill">Admin</span>`
                    : (u.assignment_count > 0
                        ? `<span class="badge project-badge bg-success rounded-pill">${u.assignment_count} project${u.assignment_count > 1 ? 's' : ''}</span>`
                        : `<span class="badge project-badge bg-light text-muted border rounded-pill">None</span>`);
                const whBadge = u.is_admin != 1
                    ? (u.warehouse_grant_count > 0
                        ? `<span class="badge warehouse-badge bg-info-subtle text-info border border-info-subtle rounded-pill ms-1"><i class="bi bi-building"></i> ${u.warehouse_grant_count}</span>`
                        : `<span class="badge warehouse-badge d-none"></span>`)
                    : '';
                html += `
                <button type="button"
                        class="list-group-item list-group-item-action user-item d-flex justify-content-between align-items-center py-3"
                        data-user-id="${u.user_id}"
                        data-user-name="${safeHtml(u.full_name)}"
                        data-is-admin="${u.is_admin}">
                    <div>
                        <div class="fw-bold">${safeHtml(u.full_name)}</div>
                        <small class="text-muted">${safeHtml(u.username)}</small>
                    </div>
                    <div>${badge}${whBadge}</div>
                </button>`;
            });

            document.getElementById('userList').innerHTML =
                `<div class="list-group list-group-flush">${html}</div>`;

            // Attach user-click handlers
            document.querySelectorAll('.user-item').forEach(ub => {
                ub.addEventListener('click', function () {
                    document.querySelectorAll('.user-item').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    loadUserProjects(
                        parseInt(this.dataset.userId),
                        this.dataset.userName,
                        this.dataset.isAdmin == '1'
                    );
                });
            });

            // Reset col 3 if role changes
            resetCol3();
        });
    });

    // ── COL 2 → COL 3: load project + warehouse checkboxes ───────────────
    let assignedWarehouseSet = new Set();

    function loadUserProjects(userId, userName, isAdmin) {
        selectedUserId   = userId;
        selectedUserName = userName;

        document.getElementById('col3-heading').textContent = userName;
        document.getElementById('col3-actions').classList.remove('d-none');
        document.getElementById('saveBar').classList.remove('d-none');
        document.getElementById('saveBar').style.display = 'flex';

        const panel = document.getElementById('projectPanel');
        panel.innerHTML = `<div class="p-4 text-center text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</div>`;

        if (isAdmin) {
            panel.innerHTML = `
                <div class="p-4">
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-shield-check me-2"></i>
                        <strong>${safeHtml(userName)}</strong> is a system administrator and has
                        full access to <strong>all projects and all warehouses</strong> automatically.
                        Assignments are ignored for admin accounts.
                    </div>
                </div>`;
            document.getElementById('saveBar').classList.add('d-none');
            document.getElementById('col3-actions').classList.add('d-none');
            return;
        }

        // Fetch current assignments
        fetch(`${SAVE_URL}?action=get_assignments&user_id=${userId}`)
            .then(r => r.json())
            .then(data => {
                const assignedProjectSet = new Set((data.projects || []).map(String));
                assignedWarehouseSet = new Set((data.warehouses || []).map(String));
                renderProjects(panel, assignedProjectSet, !!data.grant_all_warehouses);
            })
            .catch(() => {
                panel.innerHTML = `<div class="p-4 text-center text-danger">Failed to load assignments.</div>`;
            });
    }

    function renderProjects(panel, assignedProjectSet, grantAllWarehouses) {
        let html = '<div class="p-3">';

        if (!ALL_PROJECTS.length) {
            html += `<div class="text-center text-muted py-3">No projects in the system yet.</div>`;
        } else {
            html += '<div class="row g-2">';
            ALL_PROJECTS.forEach(p => {
                const checked = assignedProjectSet.has(String(p.project_id)) ? 'checked' : '';
                html += `
                <div class="col-12 col-lg-6">
                    <label class="d-flex align-items-start gap-2 p-2 rounded border cursor-pointer project-card ${checked ? 'border-primary bg-primary bg-opacity-10' : ''}"
                           style="cursor:pointer;" for="proj_${p.project_id}">
                        <input class="form-check-input mt-1 flex-shrink-0 project-chk" type="checkbox"
                               id="proj_${p.project_id}" value="${p.project_id}" ${checked}>
                        <div class="small lh-sm">
                            <div class="fw-bold">${safeHtml(p.project_name)}</div>
                            <div class="text-muted">${safeHtml(p.contract_number || '—')} &nbsp;${statusBadge(p.status)}</div>
                        </div>
                    </label>
                </div>`;
            });
            html += '</div>';
        }
        html += '</div>';

        // ── Warehouse access — separate from project scope, feeds the same save ──
        html += `
        <hr class="my-0">
        <div class="p-3">
            <h6 class="text-uppercase small text-muted fw-bold mb-2"><i class="bi bi-building me-1"></i> Warehouse Access</h6>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="grantAllWarehousesChk" ${grantAllWarehouses ? 'checked' : ''}>
                <label class="form-check-label fw-bold" for="grantAllWarehousesChk">Grant access to ALL warehouses</label>
                <div class="form-text">Overrides the two lists below — use for roles that need to see every warehouse (e.g. Managing Director).</div>
            </div>
            <div id="warehouseListsWrap">
                <div class="mb-1"><small class="text-muted fw-bold text-uppercase">Assign Project &amp; its Warehouses</small></div>
                <p class="text-muted small mb-2">Tick a project above to reveal its own warehouses here.</p>
                <div id="projectWarehousesPanel" class="row g-2 mb-3"></div>
                <div class="mb-1"><small class="text-muted fw-bold text-uppercase">Assign External Warehouse</small></div>
                <p class="text-muted small mb-2">Warehouses not tied to any project.</p>
                <div id="externalWarehousesPanel" class="row g-2"></div>
            </div>
        </div>`;

        panel.innerHTML = html;

        // Highlight project cards on check/uncheck, and refresh their warehouse sub-list
        panel.querySelectorAll('.project-chk').forEach(chk => {
            chk.addEventListener('change', function () {
                const card = this.closest('.project-card');
                if (this.checked) {
                    card.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
                } else {
                    card.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
                }
                refreshProjectWarehouses();
                updateSaveHint();
            });
        });

        document.getElementById('grantAllWarehousesChk').addEventListener('change', function () {
            document.getElementById('warehouseListsWrap').classList.toggle('opacity-50', this.checked);
            document.querySelectorAll('.warehouse-chk').forEach(c => c.disabled = this.checked);
            updateSaveHint();
        });

        refreshProjectWarehouses();
        renderExternalWarehouses();
        document.getElementById('grantAllWarehousesChk').dispatchEvent(new Event('change'));
        updateSaveHint();
    }

    // Rebuilds the "warehouses under a ticked project" list from whichever
    // project checkboxes are currently checked. Simple by design: re-derives
    // from ALL_WAREHOUSES + the originally-loaded assignedWarehouseSet every
    // time, rather than tracking a separate mutable selection — toggling a
    // project off then back on re-reads the saved state, it doesn't remember
    // an unsaved mid-edit tick for that project.
    function refreshProjectWarehouses() {
        const checkedProjectIds = new Set([...document.querySelectorAll('.project-chk:checked')].map(c => c.value));
        const list = ALL_WAREHOUSES.filter(w => w.project_id && checkedProjectIds.has(String(w.project_id)));
        const container = document.getElementById('projectWarehousesPanel');
        if (!container) return;

        if (!checkedProjectIds.size) {
            container.innerHTML = `<div class="col-12 text-muted small fst-italic">No project ticked yet.</div>`;
            return;
        }
        if (!list.length) {
            container.innerHTML = `<div class="col-12 text-muted small fst-italic">The ticked project(s) have no warehouses of their own.</div>`;
            return;
        }
        container.innerHTML = list.map(w => warehouseCheckboxHtml(w, 'pwh')).join('');
        wireWarehouseCheckbox(container);
    }

    function renderExternalWarehouses() {
        const container = document.getElementById('externalWarehousesPanel');
        if (!container) return;
        const list = ALL_WAREHOUSES.filter(w => !w.project_id);
        if (!list.length) {
            container.innerHTML = `<div class="col-12 text-muted small fst-italic">No external (unassigned-to-project) warehouses exist.</div>`;
            return;
        }
        container.innerHTML = list.map(w => warehouseCheckboxHtml(w, 'ewh')).join('');
        wireWarehouseCheckbox(container);
    }

    function warehouseCheckboxHtml(w, prefix) {
        const checked = assignedWarehouseSet.has(String(w.warehouse_id)) ? 'checked' : '';
        return `
        <div class="col-12 col-lg-6">
            <label class="d-flex align-items-start gap-2 p-2 rounded border cursor-pointer warehouse-card ${checked ? 'border-info bg-info bg-opacity-10' : ''}"
                   style="cursor:pointer;" for="${prefix}_${w.warehouse_id}">
                <input class="form-check-input mt-1 flex-shrink-0 warehouse-chk" type="checkbox"
                       id="${prefix}_${w.warehouse_id}" value="${w.warehouse_id}" ${checked}>
                <div class="small lh-sm">
                    <div class="fw-bold">${safeHtml(w.warehouse_name)}</div>
                    <div class="text-muted">${safeHtml(w.warehouse_code || '—')}</div>
                </div>
            </label>
        </div>`;
    }

    function wireWarehouseCheckbox(container) {
        container.querySelectorAll('.warehouse-chk').forEach(chk => {
            chk.addEventListener('change', function () {
                const card = this.closest('.warehouse-card');
                if (this.checked) {
                    card.classList.add('border-info', 'bg-info', 'bg-opacity-10');
                } else {
                    card.classList.remove('border-info', 'bg-info', 'bg-opacity-10');
                }
                updateSaveHint();
            });
        });
    }

    function updateSaveHint() {
        const pChecked = document.querySelectorAll('.project-chk:checked').length;
        const pTotal   = document.querySelectorAll('.project-chk').length;
        const grantAll = document.getElementById('grantAllWarehousesChk')?.checked;
        const wChecked = document.querySelectorAll('.warehouse-chk:checked').length;
        const warehouseText = grantAll ? 'ALL warehouses' : `${wChecked} warehouse(s)`;
        document.getElementById('saveHint').textContent =
            `${pChecked} of ${pTotal} project(s) selected · ${warehouseText} selected.`;
    }

    function resetCol3() {
        selectedUserId = null;
        selectedUserName = '';
        assignedWarehouseSet = new Set();
        document.getElementById('col3-heading').textContent = 'Access Assignments';
        document.getElementById('col3-actions').classList.add('d-none');
        document.getElementById('projectPanel').innerHTML =
            `<div class="p-4 text-center text-muted">
                <i class="bi bi-diagram-3 fs-4 d-block mb-2"></i>
                Select a user to manage their project and warehouse access
             </div>`;
        document.getElementById('saveBar').classList.add('d-none');
    }

    // ── Select All / Clear All (projects only) ─────────────────────────────
    document.getElementById('btnSelectAll').addEventListener('click', function () {
        document.querySelectorAll('.project-chk').forEach(c => {
            c.checked = true;
            c.closest('.project-card').classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
        });
        refreshProjectWarehouses();
        updateSaveHint();
    });

    document.getElementById('btnClearAll').addEventListener('click', function () {
        document.querySelectorAll('.project-chk').forEach(c => {
            c.checked = false;
            c.closest('.project-card').classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
        });
        refreshProjectWarehouses();
        updateSaveHint();
    });

    // ── Save ─────────────────────────────────────────────────────────────
    document.getElementById('btnSave').addEventListener('click', function () {
        if (!selectedUserId) return;

        const checked = [...document.querySelectorAll('.project-chk:checked')].map(c => c.value);
        // Union of both warehouse panels — one combined save, never two independent ones.
        const warehouseChecked = [...document.querySelectorAll('.warehouse-chk:checked')].map(c => c.value);
        const grantAllWarehouses = document.getElementById('grantAllWarehousesChk')?.checked || false;
        const btn     = this;
        const orig    = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

        const fd = new FormData();
        fd.append('user_id', selectedUserId);
        checked.forEach(pid => fd.append('projects[]', pid));
        warehouseChecked.forEach(wid => fd.append('warehouses[]', wid));
        fd.append('grant_all_warehouses', grantAllWarehouses ? '1' : '0');

        fetch(SAVE_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Saved!', text: res.message,
                                timer: 2000, showConfirmButton: false });
                    // Refresh both badges on the user item
                    const uBtn = document.querySelector(`.user-item[data-user-id="${selectedUserId}"]`);
                    if (uBtn) {
                        const pBadge = uBtn.querySelector('.project-badge');
                        if (pBadge) {
                            if (res.count > 0) {
                                pBadge.className = 'badge project-badge bg-success rounded-pill';
                                pBadge.textContent = `${res.count} project${res.count > 1 ? 's' : ''}`;
                            } else {
                                pBadge.className = 'badge project-badge bg-light text-muted border rounded-pill';
                                pBadge.textContent = 'None';
                            }
                        }
                        const wBadge = uBtn.querySelector('.warehouse-badge');
                        if (wBadge) {
                            if (res.warehouse_count === -1) {
                                wBadge.className = 'badge warehouse-badge bg-info-subtle text-info border border-info-subtle rounded-pill ms-1';
                                wBadge.innerHTML = '<i class="bi bi-building"></i> ALL';
                            } else if (res.warehouse_count > 0) {
                                wBadge.className = 'badge warehouse-badge bg-info-subtle text-info border border-info-subtle rounded-pill ms-1';
                                wBadge.innerHTML = `<i class="bi bi-building"></i> ${res.warehouse_count}`;
                            } else {
                                wBadge.className = 'badge warehouse-badge d-none';
                                wBadge.innerHTML = '';
                            }
                        }
                    }
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' }))
            .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
    });
})();
</script>
