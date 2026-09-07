<?php
/**
 * core/module_requests.php
 * -------------------------
 * Self-service module requests (tenant_module_control_plan.md, Phase C): a
 * tenant's own admin asks for a module their plan doesn't include; a
 * superadmin approves or declines it.
 *
 * Deliberately NOT a new enforcement mechanism — same discipline as
 * core/plans.php. Approving a request just calls the existing
 * setTenantFeatures() (Phase 11/12) with the requested feature's full
 * dependency closure; nothing here can grant a module some other code path
 * cannot equally grant. This file is the request/decision WORKFLOW around
 * that, plus the two notification directions.
 *
 * `feature_upgrade_requests` lives in the control database (see
 * scripts/setup_control_db.php) — a request FROM a tenant and a DECISION
 * about it must both be readable by every superadmin across every tenant in
 * one place, which only the control database can do.
 *
 * Public API:
 *   listAvailableModulesForTenant(int $tenantId): array
 *   createModuleRequest(int $tenantId, string $featureKey, int $requestedBy, ?string $note): array
 *   listPendingModuleRequests(): array
 *   listModuleRequestsForTenant(int $tenantId, int $limit = 20): array
 *   decideModuleRequest(int $requestId, int $superadminId, bool $approve, ?string $decisionNote): array
 *   remindStalePendingRequests(int $staleHours = 48): array
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/feature_registry.php';
require_once __DIR__ . '/tenant_admin.php';

if (!function_exists('moduleRequestsTableReady')) {
    /** Same pattern as featureTablesReady()/planTablesReady(). */
    function moduleRequestsTableReady(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            getControlPdo()->query('SELECT 1 FROM feature_upgrade_requests LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('listAvailableModulesForTenant')) {
    /**
     * Every registered module, from ONE tenant's point of view: do they have
     * it, what would they also get (dependency closure of anything missing),
     * and is a request for it already sitting pending.
     *
     * Returns one row per feature_key:
     *   ['key','label','description','active'=>bool,
     *    'requires'=>[['key','label'], ...]   (missing dependencies, empty if none)
     *    'pending'=>bool]
     */
    function listAvailableModulesForTenant(?int $tenantId): array
    {
        bmsPrimeTenantFeatures($tenantId);
        $registry = bmsFeatureRegistry();

        // No tenant resolved (single-tenant install, or CLI) -> there is no
        // tenant_id to have a pending request against, and every module
        // already reads as active via tenantFeatureEnabled()'s own "no
        // tenant -> everything on" rule below.
        $pending = [];
        if ($tenantId !== null && moduleRequestsTableReady()) {
            $st = getControlPdo()->prepare(
                "SELECT feature_key FROM feature_upgrade_requests WHERE tenant_id = ? AND status = 'pending'"
            );
            $st->execute([$tenantId]);
            $pending = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        }

        $out = [];
        foreach ($registry as $key => $def) {
            $active = tenantFeatureEnabled($key);
            $requires = [];
            if (!$active) {
                foreach (featureDependencyClosure([$key]) as $dep) {
                    if ($dep !== $key && !tenantFeatureEnabled($dep)) {
                        $requires[] = ['key' => $dep, 'label' => $registry[$dep]['label'] ?? $dep];
                    }
                }
            }
            $out[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'description' => $def['description'] ?? '',
                'active'      => $active,
                'requires'    => $requires,
                'pending'     => isset($pending[$key]),
            ];
        }
        usort($out, fn($a, $b) => ($registry[$a['key']]['sort_order'] ?? 0) <=> ($registry[$b['key']]['sort_order'] ?? 0));
        return $out;
    }
}

if (!function_exists('createModuleRequest')) {
    /**
     * Record a tenant's request for one module. $featureKey is the module the
     * tenant actually clicked "Request" on — its dependency closure is
     * resolved fresh at DECISION time (decideModuleRequest()), never stored,
     * so it can never go stale between request and approval.
     *
     * A second request for a module that already has an open (pending) one
     * updates that row's note instead of creating a duplicate — the "nothing
     * invented, nothing duplicated" discipline setTenantFeatures() already
     * uses for overrides.
     *
     * @return array{ok:bool, error:?string, request_id:?int, already_updated:bool}
     */
    function createModuleRequest(int $tenantId, string $featureKey, int $requestedBy, ?string $note = null): array
    {
        $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'request_id' => null, 'already_updated' => false];

        $registry = bmsFeatureRegistry();
        if (!isset($registry[$featureKey])) return $fail('Unknown module.');

        $t = getTenant($tenantId);
        if (!$t) return $fail('Tenant not found.');
        if ($t['status'] === 'deleted') return $fail('This tenant has been deleted.');

        bmsPrimeTenantFeatures($tenantId);
        if (tenantFeatureEnabled($featureKey)) {
            return $fail('This module is already part of your plan.');
        }

        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

        $pdo = getControlPdo();
        $existing = $pdo->prepare(
            "SELECT id FROM feature_upgrade_requests WHERE tenant_id = ? AND feature_key = ? AND status = 'pending' LIMIT 1"
        );
        $existing->execute([$tenantId, $featureKey]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $pdo->prepare("UPDATE feature_upgrade_requests SET note = ?, requested_by = ? WHERE id = ?")
                ->execute([$note, $requestedBy, $existingId]);
            return ['ok' => true, 'error' => null, 'request_id' => (int)$existingId, 'already_updated' => true];
        }

        $pdo->prepare(
            "INSERT INTO feature_upgrade_requests (tenant_id, feature_key, requested_by, note) VALUES (?,?,?,?)"
        )->execute([$tenantId, $featureKey, $requestedBy, $note]);
        $requestId = (int)$pdo->lastInsertId();

        try {
            notifySuperadminsOfModuleRequest($tenantId, $featureKey, $requestId);
        } catch (Throwable $e) {
            error_log('createModuleRequest/notifySuperadminsOfModuleRequest: ' . $e->getMessage());
        }

        return ['ok' => true, 'error' => null, 'request_id' => $requestId, 'already_updated' => false];
    }
}

if (!function_exists('listPendingModuleRequests')) {
    /** The superadmin inbox: every pending request, oldest first, with tenant context. */
    function listPendingModuleRequests(): array
    {
        return getControlPdo()->query("
            SELECT r.*, t.company_name, t.subdomain, t.status AS tenant_status
            FROM feature_upgrade_requests r
            JOIN tenants t ON t.id = r.tenant_id
            WHERE r.status = 'pending'
            ORDER BY r.created_at ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('listModuleRequestsForTenant')) {
    /** One tenant's request history (pending + resolved), newest first. */
    function listModuleRequestsForTenant(int $tenantId, int $limit = 20): array
    {
        $st = getControlPdo()->prepare("
            SELECT * FROM feature_upgrade_requests
            WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ?
        ");
        $st->bindValue(1, $tenantId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('decideModuleRequest')) {
    /**
     * Approve or decline one pending request.
     *
     * Approving calls the exact same setTenantFeatures() every other
     * entitlement change in this system goes through — enabling
     * $featureKey's full dependency closure (recomputed now, not from
     * whatever it was when the request was made) in one call, so an
     * approval can never itself land the tenant in a dependency-broken
     * state. Declining changes no entitlement at all.
     *
     * @return array{ok:bool, error:?string, features_changed:int}
     */
    function decideModuleRequest(int $requestId, int $superadminId, bool $approve, ?string $decisionNote = null): array
    {
        $pdo = getControlPdo();
        $st = $pdo->prepare("SELECT * FROM feature_upgrade_requests WHERE id = ?");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['ok' => false, 'error' => 'Request not found.', 'features_changed' => 0];
        if ($req['status'] !== 'pending') return ['ok' => false, 'error' => 'This request has already been decided.', 'features_changed' => 0];

        $decisionNote = $decisionNote !== null ? trim($decisionNote) : null;
        if ($decisionNote !== null && mb_strlen($decisionNote) > 500) $decisionNote = mb_substr($decisionNote, 0, 500);

        $featuresChanged = 0;
        if ($approve) {
            $desired = array_fill_keys(featureDependencyClosure([$req['feature_key']]), true);
            $fr = setTenantFeatures((int)$req['tenant_id'], $desired);
            if (!$fr['ok']) return ['ok' => false, 'error' => $fr['error'], 'features_changed' => 0];
            $featuresChanged = $fr['changed'];
        }

        $pdo->prepare("
            UPDATE feature_upgrade_requests
               SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?
             WHERE id = ?
        ")->execute([$approve ? 'approved' : 'declined', $superadminId, $decisionNote, $requestId]);

        $t = getTenant((int)$req['tenant_id']);
        logTenantAdminAction((int)$req['tenant_id'], $t['subdomain'] ?? null,
            $approve ? 'module_request_approved' : 'module_request_declined',
            $req['feature_key'] . ($decisionNote ? " — $decisionNote" : ''));

        try {
            notifyTenantOfModuleRequestDecision((int)$req['tenant_id'], $req['feature_key'], $approve, $decisionNote);
        } catch (Throwable $e) {
            error_log('decideModuleRequest/notifyTenantOfModuleRequestDecision: ' . $e->getMessage());
        }

        return ['ok' => true, 'error' => null, 'features_changed' => $featuresChanged];
    }
}

if (!function_exists('notifySuperadminsOfModuleRequest')) {
    /**
     * Email every superadmin that a tenant is waiting on a decision. Platform
     * mail (core/platform_settings.php), same as tenant welcome emails —
     * superadmins have no in-app notification center (they are platform
     * operators, not tenant users), so email plus the inbox page itself
     * (app/superadmin/module_requests.php) is the complete signal, matching
     * how the rest of this codebase treats "genuinely needs a human" events
     * (severity-high, never silent).
     */
    function notifySuperadminsOfModuleRequest(int $tenantId, string $featureKey, int $requestId): void
    {
        require_once __DIR__ . '/platform_settings.php';
        require_once __DIR__ . '/mailer.php';

        $mailer = platformMailerOpts();
        if (!$mailer['configured']) return;   // nothing configured yet — not an error

        $t = getTenant($tenantId);
        if (!$t) return;
        $label = bmsFeatureRegistry()[$featureKey]['label'] ?? $featureKey;
        $company = htmlspecialchars((string)$t['company_name'], ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        $subject = "Module request: {$t['company_name']} wants {$label}";
        $body = "<p><strong>{$company}</strong> has requested the <strong>{$safeLabel}</strong> module.</p>"
              . "<p>Review it in the Module Requests inbox in the platform admin panel.</p>";

        $emails = getControlPdo()->query("SELECT email FROM superadmins")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($emails as $email) {
            sendEmail($email, $subject, $body, $mailer['opts']);
        }
    }
}

if (!function_exists('notifyTenantOfModuleRequestDecision')) {
    /**
     * Tell the tenant a decision was made — broadcast to everyone in that
     * company who can view Settings, via the existing dispatchEvent() engine
     * (core/notify.php), the SAME broadcast-to-permission model every other
     * event in this system already uses (there is no point-to-point "notify
     * this one user" primitive here, and a module grant/decline affects the
     * whole company, not just whoever happened to click Request).
     *
     * THE ONE NARROW, DOCUMENTED EXCEPTION this function makes: opening a
     * tenant's own database directly from the superadmin/control-plane side.
     * The rest of this codebase deliberately never does that (Phase 9's
     * isolation guarantee) — the two prior exceptions are tenantUserDirectory()
     * (read-only) and this one, a single narrow write (one in-app
     * notification row) that only ever fires in direct response to an action
     * that tenant itself initiated (their own module request). Same
     * credential-decrypt-and-connect pattern as tenantUserDirectory().
     */
    function notifyTenantOfModuleRequestDecision(int $tenantId, string $featureKey, bool $approved, ?string $decisionNote): void
    {
        // getTenant() deliberately never returns db_password_encrypted (most
        // callers should never see it) — a direct query for exactly this one
        // field, same precedent as tenantUserDirectory().
        $st = getControlPdo()->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $st->execute([$tenantId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t || $t['status'] === 'deleted' || empty($t['db_host']) || empty($t['db_name'])) return;

        require_once __DIR__ . '/tenant_crypto.php';
        require_once __DIR__ . '/notify.php';

        $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
        if ($pw === null) return;
        $tPdo = new PDO(
            'mysql:host=' . $t['db_host'] . ';dbname=' . $t['db_name'] . ';charset=utf8mb4',
            $t['db_username'], $pw,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );

        $label = bmsFeatureRegistry()[$featureKey]['label'] ?? $featureKey;
        $verb  = $approved ? 'approved' : 'declined';

        dispatchEvent($tPdo, 'module_request_decided', [
            'title'    => "Module request $verb: $label",
            'message'  => $approved
                ? "Your request for the $label module was approved — it's available now."
                : "Your request for the $label module was declined." . ($decisionNote ? " Reason: $decisionNote" : ''),
            'severity' => 'high',
            'action_url'  => 'available_modules',
            'entity_type' => 'feature_request',
            'entity_id'   => 0,
            // Once per decision, not once per day — each decision is its own event.
            'dedupe_suffix' => $featureKey . '|' . $verb . '|' . date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('remindStalePendingRequests')) {
    /**
     * Re-notify superadmins once for any request that has sat pending longer
     * than $staleHours — the same "don't let it go silent" discipline
     * expireIdleSessions() and the daily HR/document-expiry checks already
     * apply elsewhere. Fired from api/run_background_jobs.php, throttled the
     * same way those checks are (once per day is plenty; this is not urgent
     * enough to check every request cycle).
     *
     * @return array{reminded:int}
     */
    function remindStalePendingRequests(int $staleHours = 48): array
    {
        if (!moduleRequestsTableReady()) return ['reminded' => 0];

        $pdo = getControlPdo();
        $stale = $pdo->prepare("
            SELECT * FROM feature_upgrade_requests
             WHERE status = 'pending'
               AND created_at < (NOW() - INTERVAL ? HOUR)
               AND reminded_at IS NULL
        ");
        $stale->execute([$staleHours]);
        $rows = $stale->fetchAll(PDO::FETCH_ASSOC);

        $reminded = 0;
        foreach ($rows as $row) {
            try {
                notifySuperadminsOfModuleRequest((int)$row['tenant_id'], $row['feature_key'], (int)$row['id']);
                $pdo->prepare("UPDATE feature_upgrade_requests SET reminded_at = NOW() WHERE id = ?")
                    ->execute([$row['id']]);
                $reminded++;
            } catch (Throwable $e) {
                error_log('remindStalePendingRequests: ' . $e->getMessage());
            }
        }
        return ['reminded' => $reminded];
    }
}
