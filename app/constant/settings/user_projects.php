<?php
/**
 * Project-Scope Assignment UI
 *
 * Three-level drill-down (mirrors user_roles.php style):
 *   1. Roles list  →  click a role
 *   2. Users in that role  →  click a user
 *   3. Project checkboxes for that user  →  save
 *
 * No resource overrides. Strict project-based scope only.
 */

$page_title = "Project Assignments";
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('user_projects');
global $pdo;

// ── AJAX: return user's current assignments as JSON ───────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_assignments') {
    header('Content-Type: application/json');
    $uid = intval($_GET['user_id'] ?? 0);
    if (!$uid) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT project_id FROM user_projects WHERE user_id = ?");
    $stmt->execute([$uid]);
    echo json_encode(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    exit;
}

// ── POST: save assignments for a user ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $user_id     = intval($_POST['user_id'] ?? 0);
    $project_ids = isset($_POST['projects']) && is_array($_POST['projects'])
        ? array_map('intval', $_POST['projects'])
        : [];

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
        $pdo->commit();

        logActivity($pdo, $_SESSION['user_id'], 'Updated Project Scope',
            "user_id=$user_id projects=" . implode(',', $project_ids));
        if (function_exists('refreshScopeCache')) refreshScopeCache($user_id);

        // Fetch user name for response
        $uStmt = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM users WHERE user_id = ?");
        $uStmt->execute([$user_id]);
        $uname = $uStmt->fetchColumn() ?: "User #$user_id";

        echo json_encode([
            'success' => true,
            'message' => "Saved " . count($project_ids) . " project(s) for {$uname}.",
            'count'   => count($project_ids),
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

// All active users with their role — embedded as JS data to avoid extra AJAX
$all_users = $pdo->query("
    SELECT u.user_id, u.username,
           CONCAT(u.first_name,' ',u.last_name) AS full_name,
           u.role_id,
           COALESCE(u.is_admin, 0) AS is_admin,
           (SELECT COUNT(*) FROM user_projects up WHERE up.user_id = u.user_id) AS assignment_count
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
        (SELECT COUNT(*) FROM user_projects) AS total_assignments
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

                <!-- COL 3: Project assignment for selected user -->
                <div class="col-12 col-md-6">
                    <div class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-uppercase text-muted">
                            <i class="bi bi-briefcase me-1"></i><span id="col3-heading">Project Assignments</span>
                        </span>
                        <div id="col3-actions" class="d-none">
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1" id="btnSelectAll">All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearAll">None</button>
                        </div>
                    </div>
                    <div id="projectPanel" style="max-height:460px;overflow-y:auto;">
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-diagram-3 fs-4 d-block mb-2"></i>
                            Select a user to manage their project access
                        </div>
                    </div>
                    <!-- Save bar — hidden until a user is selected -->
                    <div id="saveBar" class="d-none border-top p-3 d-flex justify-content-between align-items-center bg-white">
                        <small class="text-muted" id="saveHint">Tick the projects this user may access.</small>
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
    const ALL_USERS    = <?= json_encode(array_values($all_users), JSON_HEX_TAG) ?>;
    const ALL_PROJECTS = <?= json_encode(array_values($projects),  JSON_HEX_TAG) ?>;
    const SAVE_URL     = '<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>';

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
                    ? `<span class="badge bg-danger rounded-pill">Admin</span>`
                    : (u.assignment_count > 0
                        ? `<span class="badge bg-success rounded-pill">${u.assignment_count} project${u.assignment_count > 1 ? 's' : ''}</span>`
                        : `<span class="badge bg-light text-muted border rounded-pill">None</span>`);
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
                    ${badge}
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

    // ── COL 2 → COL 3: load project checkboxes ───────────────────────────
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
                        full access to <strong>all projects</strong> automatically.
                        Project assignments are ignored for admin accounts.
                    </div>
                </div>`;
            document.getElementById('saveBar').classList.add('d-none');
            document.getElementById('col3-actions').classList.add('d-none');
            return;
        }

        // Fetch current assignments
        fetch(`${SAVE_URL}?action=get_assignments&user_id=${userId}`)
            .then(r => r.json())
            .then(assigned => {
                const assignedSet = new Set(assigned);
                renderProjects(panel, assignedSet);
            })
            .catch(() => {
                panel.innerHTML = `<div class="p-4 text-center text-danger">Failed to load assignments.</div>`;
            });
    }

    function renderProjects(panel, assignedSet) {
        if (!ALL_PROJECTS.length) {
            panel.innerHTML = `<div class="p-4 text-center text-muted">No projects in the system yet.</div>`;
            return;
        }

        let html = '<div class="p-3"><div class="row g-2">';
        ALL_PROJECTS.forEach(p => {
            const checked = assignedSet.has(p.project_id) ? 'checked' : '';
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
        html += '</div></div>';
        panel.innerHTML = html;

        // Highlight on check/uncheck
        panel.querySelectorAll('.project-chk').forEach(chk => {
            chk.addEventListener('change', function () {
                const card = this.closest('.project-card');
                if (this.checked) {
                    card.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
                } else {
                    card.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
                }
                updateSaveHint();
            });
        });

        updateSaveHint();
    }

    function updateSaveHint() {
        const checked = document.querySelectorAll('.project-chk:checked').length;
        const total   = document.querySelectorAll('.project-chk').length;
        document.getElementById('saveHint').textContent =
            checked === 0
                ? `No projects selected — this user will see nothing.`
                : `${checked} of ${total} project(s) selected.`;
    }

    function resetCol3() {
        selectedUserId = null;
        selectedUserName = '';
        document.getElementById('col3-heading').textContent = 'Project Assignments';
        document.getElementById('col3-actions').classList.add('d-none');
        document.getElementById('projectPanel').innerHTML =
            `<div class="p-4 text-center text-muted">
                <i class="bi bi-diagram-3 fs-4 d-block mb-2"></i>
                Select a user to manage their project access
             </div>`;
        document.getElementById('saveBar').classList.add('d-none');
    }

    // ── Select All / Clear All ────────────────────────────────────────────
    document.getElementById('btnSelectAll').addEventListener('click', function () {
        document.querySelectorAll('.project-chk').forEach(c => {
            c.checked = true;
            c.closest('.project-card').classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
        });
        updateSaveHint();
    });

    document.getElementById('btnClearAll').addEventListener('click', function () {
        document.querySelectorAll('.project-chk').forEach(c => {
            c.checked = false;
            c.closest('.project-card').classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
        });
        updateSaveHint();
    });

    // ── Save ─────────────────────────────────────────────────────────────
    document.getElementById('btnSave').addEventListener('click', function () {
        if (!selectedUserId) return;

        const checked = [...document.querySelectorAll('.project-chk:checked')].map(c => c.value);
        const btn     = this;
        const orig    = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

        const fd = new FormData();
        fd.append('user_id', selectedUserId);
        checked.forEach(pid => fd.append('projects[]', pid));

        fetch(SAVE_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Saved!', text: res.message,
                                timer: 2000, showConfirmButton: false });
                    // Refresh the badge on the user item
                    const uBtn = document.querySelector(`.user-item[data-user-id="${selectedUserId}"]`);
                    if (uBtn) {
                        const badge = uBtn.querySelector('.badge');
                        if (badge) {
                            if (res.count > 0) {
                                badge.className = 'badge bg-success rounded-pill';
                                badge.textContent = `${res.count} project${res.count > 1 ? 's' : ''}`;
                            } else {
                                badge.className = 'badge bg-light text-muted border rounded-pill';
                                badge.textContent = 'None';
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
