<?php
/**
 * MM Agent/Till Access — Grant Management
 *
 * Three-column drill-down (mirrors user_projects.php style):
 *   1. Roles list  → click a role
 *   2. Users in that role → click a user
 *   3. Per-agent grant table: one row per till + one "All tills" default row.
 *      Each row carries four ability checkboxes:
 *        can_open_shift | can_record_transactions | can_close_shift | can_reconcile
 *
 * The "All tills (default)" row writes a NULL till_id grant, which
 * mmUserCanOnTill() uses as a fallback for any till of that agent that has
 * no specific row.  A specific till row takes precedence over the NULL row
 * (ORDER BY till_id DESC LIMIT 1 in the lookup returns the specific row first).
 *
 * Save strategy: full replace — DELETE all grants for the user then INSERT
 * the checked rows (identical to how user_projects.php handles warehouse access).
 *
 * Admin-only. Reached via Settings > Admin alongside User Projects & Warehouses.
 */

// scope-audit: skip — admin-only grant assignment UI; intentionally shows all agents/tills.
$page_title = 'MM Agent Access';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_user_agent_grants');

if (!isAdmin()) {
    header('Location: ' . getUrl('unauthorized'));
    exit;
}

global $pdo;

if (isset($_SESSION['user_id'])) {
    loadLanguage($_SESSION['user_lang'] ?? get_setting('user_language_' . $_SESSION['user_id'], 'en'));
}

// ── AJAX GET: current grants for a user ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_grants') {
    header('Content-Type: application/json');
    $uid = intval($_GET['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['grants' => []]); exit; }

    $stmt = $pdo->prepare("
        SELECT agent_id, till_id,
               can_open_shift, can_record_transactions, can_close_shift, can_reconcile
        FROM mm_user_agent_grants
        WHERE user_id = ?
        ORDER BY agent_id, till_id
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Normalise: cast till_id to int or null, booleans to int
    $grants = array_map(fn($r) => [
        'agent_id'               => (int)$r['agent_id'],
        'till_id'                => $r['till_id'] !== null ? (int)$r['till_id'] : null,
        'can_open_shift'         => (int)$r['can_open_shift'],
        'can_record_transactions'=> (int)$r['can_record_transactions'],
        'can_close_shift'        => (int)$r['can_close_shift'],
        'can_reconcile'          => (int)$r['can_reconcile'],
    ], $rows);
    echo json_encode(['grants' => $grants]);
    exit;
}

// ── AJAX POST: save grants ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    csrf_check();
    $userId = intval($_POST['user_id'] ?? 0);
    if (!$userId) { echo json_encode(['success' => false, 'message' => t('Invalid user.')]); exit; }

    $grants = json_decode($_POST['grants'] ?? '[]', true);
    if (!is_array($grants)) $grants = [];

    try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM mm_user_agent_grants WHERE user_id = ?")->execute([$userId]);

        if (!empty($grants)) {
            $ins = $pdo->prepare("
                INSERT INTO mm_user_agent_grants
                    (user_id, agent_id, till_id, can_open_shift, can_record_transactions,
                     can_close_shift, can_reconcile, created_at, granted_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            foreach ($grants as $g) {
                $agentId = intval($g['agent_id'] ?? 0);
                $tillId  = isset($g['till_id']) && $g['till_id'] !== null && $g['till_id'] !== ''
                           ? intval($g['till_id']) : null;
                if (!$agentId) continue;
                $ins->execute([
                    $userId, $agentId, $tillId,
                    (int)!empty($g['can_open_shift']),
                    (int)!empty($g['can_record_transactions']),
                    (int)!empty($g['can_close_shift']),
                    (int)!empty($g['can_reconcile']),
                    $_SESSION['user_id'],
                ]);
            }
        }

        $pdo->commit();

        logActivity($pdo, $_SESSION['user_id'], 'Updated MM Agent Access',
            "user_id=$userId grant_rows=" . count($grants));
        logAudit($pdo, $_SESSION['user_id'], 'mm_agent_access_updated', [
            'activity_type' => 'access_control',
            'description'   => "Updated MM agent/till grants for user_id=$userId",
            'entity_type'   => 'user', 'entity_id' => $userId,
            'new_values'    => ['grant_count' => count($grants)],
        ]);

        $uStmt = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM users WHERE user_id = ?");
        $uStmt->execute([$userId]);
        $uname = $uStmt->fetchColumn() ?: "User #$userId";

        echo json_encode([
            'success'     => true,
            'message'     => sprintf(t('Saved %d grant row(s) for %s.'), count($grants), $uname),
            'grant_count' => count($grants),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => t('Save failed:') . ' ' . $e->getMessage()]);
    }
    exit;
}

// ── Page data ─────────────────────────────────────────────────────────────────
$roles = $pdo->query("
    SELECT r.role_id, r.role_name, r.description, COUNT(u.user_id) AS user_count
    FROM roles r
    LEFT JOIN users u ON u.role_id = r.role_id AND u.is_active = 1
    GROUP BY r.role_id ORDER BY r.role_name
")->fetchAll(PDO::FETCH_ASSOC);

// Grant counts per user (number of grant rows, not distinct agents)
$grantCounts = $pdo->query("SELECT user_id, COUNT(*) AS cnt FROM mm_user_agent_grants GROUP BY user_id")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$all_users = $pdo->query("
    SELECT u.user_id, u.username,
           CONCAT(u.first_name,' ',u.last_name) AS full_name,
           u.role_id, COALESCE(u.is_admin, 0) AS is_admin
    FROM users u WHERE u.is_active = 1 ORDER BY u.first_name, u.last_name
")->fetchAll(PDO::FETCH_ASSOC);
// Embed grant count
foreach ($all_users as &$u) {
    $u['grant_count'] = (int)($grantCounts[$u['user_id']] ?? 0);
}
unset($u);

// Agents with their active tills
$agentsRaw = $pdo->query("
    SELECT a.agent_id, a.agent_name, a.agent_code, a.status,
           t.till_id, t.till_number, t.sim_msisdn, t.status AS till_status,
           n.network_name, n.color_hex
    FROM mm_agents a
    LEFT JOIN mm_tills t ON t.agent_id = a.agent_id AND t.status = 'active'
    LEFT JOIN mm_networks n ON n.network_id = t.network_id
    WHERE a.status = 'active'
    ORDER BY a.agent_name, t.till_number
")->fetchAll(PDO::FETCH_ASSOC);

// Group tills under agents
$agentsMap = [];
foreach ($agentsRaw as $row) {
    $aid = $row['agent_id'];
    if (!isset($agentsMap[$aid])) {
        $agentsMap[$aid] = [
            'agent_id'   => (int)$aid,
            'agent_name' => $row['agent_name'],
            'agent_code' => $row['agent_code'],
            'status'     => $row['status'],
            'tills'      => [],
        ];
    }
    if ($row['till_id']) {
        $agentsMap[$aid]['tills'][] = [
            'till_id'     => (int)$row['till_id'],
            'till_number' => $row['till_number'],
            'sim_msisdn'  => $row['sim_msisdn'],
            'till_status' => $row['till_status'],
            'network_name'=> $row['network_name'] ?? '—',
            'color_hex'   => $row['color_hex'] ?? '#999',
        ];
    }
}
$agents = array_values($agentsMap);

$stats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM roles) AS total_roles,
        (SELECT COUNT(*) FROM users WHERE is_active=1) AS total_users,
        (SELECT COUNT(*) FROM mm_agents WHERE status='active') AS total_agents,
        (SELECT COUNT(*) FROM mm_tills WHERE status='active') AS total_tills,
        (SELECT COUNT(*) FROM mm_user_agent_grants) AS total_grants
")->fetch(PDO::FETCH_ASSOC);

require_once 'header.php';
?>

<div class="container-fluid mt-4">

    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="bi bi-phone-vibrate"></i> <?= t('MM Agent & Till Access') ?></h2>
            <p class="text-muted"><?= t('Assign users to Mobile Money agents and tills. Control which operations each user may perform per till.') ?></p>
        </div>
    </div>

    <style>
        .custom-stat-card { background-color:#d1e7dd!important; border-color:#badbcc!important; transition:transform .2s; border-radius:12px; }
        .custom-stat-card:hover { transform:translateY(-3px); }
        .custom-stat-card h4, .custom-stat-card p, .custom-stat-card i, .custom-stat-card .small { color:#0f5132!important; }
        .ability-th { font-size:.72rem; text-align:center; padding:4px 6px!important; white-space:nowrap; }
        .ability-td { text-align:center; padding:4px 6px!important; }
        .agent-block { border:1px solid #dee2e6; border-radius:8px; margin-bottom:12px; overflow:hidden; }
        .agent-block-head { background:#f8f9fa; padding:8px 12px; font-weight:600; display:flex; align-items:center; gap:8px; }
        .till-table { font-size:.83rem; margin:0; }
        .till-table td, .till-table th { vertical-align:middle!important; }
        .null-row td { background:#fff8e1; font-style:italic; }
        .null-row td:first-child { font-weight:600; color:#856404; }
        .net-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:4px; }
    </style>

    <!-- Stat cards -->
    <div class="row mb-4">
        <?php foreach ([
            ['total_roles',  t('Roles'),       'bi-person-badge',  ''],
            ['total_users',  t('Active Users'), 'bi-people',        ''],
            ['total_agents', t('MM Agents'),    'bi-shop-window',   ''],
            ['total_tills',  t('Active Tills'), 'bi-phone',         ''],
            ['total_grants', t('Grant Rows'),   'bi-key',           ''],
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
                    <div class="p-3 border-bottom bg-light">
                        <span class="fw-bold small text-uppercase text-muted">
                            <i class="bi bi-person-badge me-1"></i><?= t('System Roles') ?>
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

                <!-- COL 2: Users -->
                <div class="col-12 col-md-3 border-end">
                    <div class="p-3 border-bottom bg-light">
                        <span class="fw-bold small text-uppercase text-muted">
                            <i class="bi bi-people me-1"></i><span id="col2-heading"><?= t('Users') ?></span>
                        </span>
                    </div>
                    <div id="userList" style="max-height:520px;overflow-y:auto;">
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-arrow-left fs-4 d-block mb-2"></i>
                            <?= t('Select a role to see its users') ?>
                        </div>
                    </div>
                </div>

                <!-- COL 3: Agent/till grant matrix -->
                <div class="col-12 col-md-6">
                    <div class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-uppercase text-muted">
                            <i class="bi bi-phone-vibrate me-1"></i><span id="col3-heading"><?= t('Agent & Till Access') ?></span>
                        </span>
                    </div>
                    <div id="grantPanel" style="max-height:460px;overflow-y:auto;">
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-phone-vibrate fs-4 d-block mb-2"></i>
                            <?= t('Select a user to manage their MM agent and till access') ?>
                        </div>
                    </div>
                    <div id="saveBar" class="d-none border-top p-3 d-flex justify-content-between align-items-center bg-white">
                        <small class="text-muted" id="saveHint"><?= t('Tick the tills and abilities this user may access.') ?></small>
                        <button type="button" class="btn btn-primary px-4" id="btnSave">
                            <i class="bi bi-check-circle me-1"></i><?= t('Save Grants') ?>
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
    const ALL_USERS  = <?= json_encode(array_values($all_users), JSON_HEX_TAG) ?>;
    const ALL_AGENTS = <?= json_encode(array_values($agents),    JSON_HEX_TAG) ?>;
    const SAVE_URL   = '<?= buildUrl('mm_user_agent_grants') ?>';
    const STR = {
        admin:             <?= json_encode(t('Admin')) ?>,
        none:              <?= json_encode(t('None')) ?>,
        loading:           <?= json_encode(t('Loading…')) ?>,
        noUsers:           <?= json_encode(t('No active users in this role.')) ?>,
        noAgents:          <?= json_encode(t('No active agents configured yet.')) ?>,
        noTills:           <?= json_encode(t('No active tills on this agent.')) ?>,
        grants:            <?= json_encode(t('grant(s)')) ?>,
        allTills:          <?= json_encode(t('All tills (default)')) ?>,
        allTillsHint:      <?= json_encode(t('Applies to all tills of this agent that have no specific row below.')) ?>,
        adminNotice:       <?= json_encode(t('is a system administrator and has full access to all MM agents and tills automatically.')) ?>,
        failedLoad:        <?= json_encode(t('Failed to load grants.')) ?>,
        accessAssignments: <?= json_encode(t('Agent & Till Access')) ?>,
        saving:            <?= json_encode(t('Saving…')) ?>,
        saved:             <?= json_encode(t('Saved!')) ?>,
        error:             <?= json_encode(t('Error')) ?>,
        serverError:       <?= json_encode(t('Server error. Please try again.')) ?>,
        selectUser:        <?= json_encode(t('Select a user to manage their MM agent and till access')) ?>,
        hintTick:          <?= json_encode(t('Tick the tills and abilities this user may access.')) ?>,
        openShift:         <?= json_encode(t('Open')) ?>,
        recordTx:          <?= json_encode(t('Record')) ?>,
        closeShift:        <?= json_encode(t('Close')) ?>,
        reconcile:         <?= json_encode(t('Recon.')) ?>,
        openShiftFull:     <?= json_encode(t('Can Open Shift')) ?>,
        recordTxFull:      <?= json_encode(t('Can Record Transactions')) ?>,
        closeShiftFull:    <?= json_encode(t('Can Close Shift')) ?>,
        reconcileFull:     <?= json_encode(t('Can Reconcile')) ?>,
    };

    const ABILITIES = [
        { key: 'can_open_shift',          label: STR.openShift,  full: STR.openShiftFull  },
        { key: 'can_record_transactions', label: STR.recordTx,   full: STR.recordTxFull   },
        { key: 'can_close_shift',         label: STR.closeShift, full: STR.closeShiftFull },
        { key: 'can_reconcile',           label: STR.reconcile,  full: STR.reconcileFull  },
    ];

    let selectedUserId   = null;
    let selectedUserName = '';

    function safeHtml(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    // ── COL 1 click ──────────────────────────────────────────────────────────
    document.querySelectorAll('.role-item').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.role-item').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('col2-heading').textContent = this.dataset.roleName;
            const roleId = parseInt(this.dataset.roleId);
            const users  = ALL_USERS.filter(u => u.role_id == roleId);
            if (!users.length) {
                document.getElementById('userList').innerHTML =
                    `<div class="p-4 text-center text-muted">${STR.noUsers}</div>`;
                resetCol3(); return;
            }
            let html = '<div class="list-group list-group-flush">';
            users.forEach(u => {
                const badge = u.is_admin == 1
                    ? `<span class="badge user-grant-badge bg-danger rounded-pill">${STR.admin}</span>`
                    : (u.grant_count > 0
                        ? `<span class="badge user-grant-badge bg-success rounded-pill">${u.grant_count} ${STR.grants}</span>`
                        : `<span class="badge user-grant-badge bg-light text-muted border rounded-pill">${STR.none}</span>`);
                html += `<button type="button"
                    class="list-group-item list-group-item-action user-item d-flex justify-content-between align-items-center py-3"
                    data-user-id="${u.user_id}" data-user-name="${safeHtml(u.full_name)}" data-is-admin="${u.is_admin}">
                    <div>
                        <div class="fw-bold">${safeHtml(u.full_name)}</div>
                        <small class="text-muted">${safeHtml(u.username)}</small>
                    </div>
                    ${badge}
                </button>`;
            });
            html += '</div>';
            document.getElementById('userList').innerHTML = html;
            document.querySelectorAll('.user-item').forEach(ub => {
                ub.addEventListener('click', function () {
                    document.querySelectorAll('.user-item').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    loadGrants(parseInt(this.dataset.userId), this.dataset.userName, this.dataset.isAdmin == '1');
                });
            });
            resetCol3();
        });
    });

    // ── COL 2 → COL 3 ────────────────────────────────────────────────────────
    function loadGrants(userId, userName, isAdmin) {
        selectedUserId   = userId;
        selectedUserName = userName;
        document.getElementById('col3-heading').textContent = userName;
        document.getElementById('saveBar').classList.remove('d-none');

        const panel = document.getElementById('grantPanel');
        panel.innerHTML = `<div class="p-4 text-center text-muted"><div class="spinner-border spinner-border-sm me-2"></div>${STR.loading}</div>`;

        if (isAdmin) {
            panel.innerHTML = `<div class="p-4"><div class="alert alert-warning mb-0">
                <i class="bi bi-shield-check me-2"></i>
                <strong>${safeHtml(userName)}</strong> ${STR.adminNotice}</div></div>`;
            document.getElementById('saveBar').classList.add('d-none');
            return;
        }

        fetch(`${SAVE_URL}?action=get_grants&user_id=${userId}`)
            .then(r => r.json())
            .then(data => renderGrants(panel, data.grants || []))
            .catch(() => { panel.innerHTML = `<div class="p-4 text-center text-danger">${STR.failedLoad}</div>`; });
    }

    function renderGrants(panel, savedGrants) {
        // Build lookup: "agentId_tillId" (tillId = "null" for the NULL row)
        const grantMap = {};
        savedGrants.forEach(g => {
            const key = g.agent_id + '_' + (g.till_id === null ? 'null' : g.till_id);
            grantMap[key] = g;
        });

        if (!ALL_AGENTS.length) {
            panel.innerHTML = `<div class="p-4 text-center text-muted">${STR.noAgents}</div>`;
            updateSaveHint(); return;
        }

        const abilityHeaders = ABILITIES.map(a =>
            `<th class="ability-th" title="${safeHtml(a.full)}">${safeHtml(a.label)}</th>`
        ).join('');

        let html = '<div class="p-3">';
        ALL_AGENTS.forEach(agent => {
            const nullKey  = agent.agent_id + '_null';
            const nullGrant = grantMap[nullKey] || null;

            html += `<div class="agent-block">
                <div class="agent-block-head">
                    <i class="bi bi-shop-window text-primary"></i>
                    <span>${safeHtml(agent.agent_name)}</span>
                    <code class="small text-muted">${safeHtml(agent.agent_code)}</code>
                </div>
                <div class="table-responsive">
                <table class="table till-table table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:160px"><?= t('Till / Scope') ?></th>
                            ${abilityHeaders}
                        </tr>
                    </thead>
                    <tbody>`;

            // NULL row — "All tills (default)"
            html += buildTillRow(agent.agent_id, null, STR.allTills, '', '#6c757d', nullGrant, grantMap);

            // Specific till rows
            if (agent.tills.length) {
                agent.tills.forEach(till => {
                    const tillKey  = agent.agent_id + '_' + till.till_id;
                    const tillGrant = grantMap[tillKey] || null;
                    html += buildTillRow(agent.agent_id, till.till_id, till.till_number, till.network_name, till.color_hex, tillGrant, grantMap);
                });
            } else {
                html += `<tr><td colspan="${ABILITIES.length + 1}" class="text-muted small fst-italic text-center py-2">${STR.noTills}</td></tr>`;
            }

            html += `</tbody></table></div></div>`;
        });
        html += '</div>';
        panel.innerHTML = html;
        attachAbilityListeners();
        updateSaveHint();
    }

    function buildTillRow(agentId, tillId, label, network, colorHex, savedGrant, grantMap) {
        const keyStr = agentId + '_' + (tillId === null ? 'null' : tillId);
        const isNull = tillId === null;
        const tdId   = 'till_' + keyStr;
        // Label cell
        let labelHtml = '';
        if (isNull) {
            labelHtml = `<td class="ps-3 py-2" title="${safeHtml(STR.allTillsHint)}">
                <span class="text-warning-emphasis fw-semibold"><i class="bi bi-star-fill me-1" style="font-size:.7rem"></i>${safeHtml(label)}</span>
                <div class="text-muted" style="font-size:.7rem">${safeHtml(STR.allTillsHint)}</div>
            </td>`;
        } else {
            labelHtml = `<td class="ps-3 py-2">
                <span class="net-dot" style="background:${safeHtml(colorHex)}"></span>
                <code style="font-size:.8rem">${safeHtml(label)}</code>
                <span class="text-muted" style="font-size:.75rem"> ${safeHtml(network)}</span>
            </td>`;
        }

        let abilityCells = ABILITIES.map(a => {
            const checked = savedGrant && savedGrant[a.key] ? 'checked' : '';
            return `<td class="ability-td">
                <input type="checkbox" class="form-check-input ability-chk"
                       data-agent="${agentId}" data-till="${tillId === null ? '' : tillId}"
                       data-ability="${a.key}" ${checked}>
            </td>`;
        }).join('');

        const rowClass = isNull ? 'null-row' : '';
        return `<tr class="${rowClass}" data-key="${safeHtml(keyStr)}">${labelHtml}${abilityCells}</tr>`;
    }

    function attachAbilityListeners() {
        document.querySelectorAll('.ability-chk').forEach(chk => {
            chk.addEventListener('change', updateSaveHint);
        });
    }

    function updateSaveHint() {
        const count = document.querySelectorAll('.ability-chk:checked').length;
        document.getElementById('saveHint').textContent =
            count > 0 ? `${count} ${STR.grants} selected.` : STR.hintTick;
    }

    function resetCol3() {
        selectedUserId = null; selectedUserName = '';
        document.getElementById('col3-heading').textContent = STR.accessAssignments;
        document.getElementById('grantPanel').innerHTML =
            `<div class="p-4 text-center text-muted"><i class="bi bi-phone-vibrate fs-4 d-block mb-2"></i>${STR.selectUser}</div>`;
        document.getElementById('saveBar').classList.add('d-none');
    }

    // ── Save ─────────────────────────────────────────────────────────────────
    document.getElementById('btnSave').addEventListener('click', function () {
        if (!selectedUserId) return;

        // Collect checked ability checkboxes, group by agent+till → one grant row per unique combo
        const grantRows = {};
        document.querySelectorAll('.ability-chk:checked').forEach(chk => {
            const agentId = chk.dataset.agent;
            const tillId  = chk.dataset.till;  // '' means NULL
            const key     = agentId + '_' + (tillId || 'null');
            if (!grantRows[key]) {
                grantRows[key] = {
                    agent_id: parseInt(agentId),
                    till_id:  tillId !== '' ? parseInt(tillId) : null,
                    can_open_shift: 0, can_record_transactions: 0,
                    can_close_shift: 0, can_reconcile: 0,
                };
            }
            grantRows[key][chk.dataset.ability] = 1;
        });

        const grants = Object.values(grantRows);
        const btn    = this;
        const orig   = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${STR.saving}`;

        const fd = new FormData();
        fd.append('_csrf', typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
        fd.append('user_id', selectedUserId);
        fd.append('grants', JSON.stringify(grants));

        fetch(SAVE_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: STR.saved, text: res.message, timer: 2000, showConfirmButton: false });
                    // Update badge on user list item
                    const uBtn = document.querySelector(`.user-item[data-user-id="${selectedUserId}"]`);
                    if (uBtn) {
                        const badge = uBtn.querySelector('.user-grant-badge');
                        if (badge) {
                            if (res.grant_count > 0) {
                                badge.className = 'badge user-grant-badge bg-success rounded-pill';
                                badge.textContent = `${res.grant_count} ${STR.grants}`;
                            } else {
                                badge.className = 'badge user-grant-badge bg-light text-muted border rounded-pill';
                                badge.textContent = STR.none;
                            }
                        }
                    }
                } else {
                    Swal.fire({ icon: 'error', title: STR.error, text: res.message });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: STR.error, text: STR.serverError }))
            .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
    });
})();
</script>
