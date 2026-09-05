<?php
/**
 * core/plans.php
 * ----------------
 * Reusable pricing/feature bundles a superadmin can apply to a tenant in one
 * click, instead of hand-setting features and quotas individually every time
 * — the professional-SaaS-panel piece BMS's superadmin was missing (see
 * superadmin_workdo_gap_plan.md, Phase C).
 *
 * Deliberately NOT a new enforcement mechanism. applyPlanToTenant() is a thin
 * orchestrator over setTenantFeatures() and setTenantQuotas() — Phases 11/12,
 * already shipped, tested, and enforced exactly as they are today. A tenant
 * stays fully, independently editable after a plan is applied: there is no
 * `plan_id` column tying a tenant to a plan's live definition, only
 * `tenants.plan` — set to the plan's plan_key (a stable identifier, not the
 * display name, so renaming a plan later never orphans a tenant's record of
 * which one it's on) at the moment the plan was applied.
 *
 * Public API:
 *   listPlans(bool $activeOnly = false): array
 *   getPlan(int $id): ?array
 *   getPlanByKey(string $key): ?array
 *   planFeatureKeys(int $id): array
 *   createPlan(array $in): array{ok:bool, error:?string, id:?int}
 *   updatePlan(int $id, array $in): array{ok:bool, error:?string}
 *   setPlanActive(int $id, bool $active): array{ok:bool, error:?string}
 *   applyPlanToTenant(int $tenantId, int $planId): array{ok:bool, error:?string}
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/feature_registry.php';
require_once __DIR__ . '/tenant_admin.php';

if (!function_exists('planTablesReady')) {
    /** Same pattern as featureTablesReady(): report "not set up here yet" distinctly from a real failure. */
    function planTablesReady(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            getControlPdo()->query('SELECT 1 FROM plans LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('planSlug')) {
    /** name -> a URL/identifier-safe lowercase-dash slug. Never empty. */
    function planSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('~[^a-z0-9]+~', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'plan';
    }
}

if (!function_exists('listPlans')) {
    /**
     * Every plan, with how many LIVE tenants currently show it — matched by
     * plan_key (the stable id), not by name, so a rename never breaks the count.
     */
    function listPlans(bool $activeOnly = false): array
    {
        $sql = "SELECT p.*,
                       (SELECT COUNT(*) FROM tenants t
                         WHERE t.plan = p.plan_key AND t.status != 'deleted') AS tenants_using
                FROM plans p";
        if ($activeOnly) $sql .= " WHERE p.is_active = 1";
        $sql .= " ORDER BY p.sort_order, p.name";
        return getControlPdo()->query($sql)->fetchAll();
    }
}

if (!function_exists('getPlan')) {
    function getPlan(int $id): ?array
    {
        $st = getControlPdo()->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('getPlanByKey')) {
    function getPlanByKey(string $key): ?array
    {
        if ($key === '') return null;
        $st = getControlPdo()->prepare("SELECT * FROM plans WHERE plan_key = ? LIMIT 1");
        $st->execute([$key]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('planFeatureKeys')) {
    function planFeatureKeys(int $id): array
    {
        $st = getControlPdo()->prepare("SELECT feature_key FROM plan_features WHERE plan_id = ?");
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }
}

/** Shared validation for createPlan()/updatePlan() — returns an error string, or null if valid. */
if (!function_exists('planInputError')) {
    function planInputError(string $name, ?int $maxUsers, ?int $maxStorageMb): ?string
    {
        if ($name === '' || mb_strlen($name) > 100) {
            return 'Enter a plan name (up to 100 characters).';
        }
        if ($maxUsers !== null && $maxUsers < 1) {
            return 'Max users must be at least 1, or left blank for unlimited.';
        }
        if ($maxStorageMb !== null && $maxStorageMb < 1) {
            return 'Max storage must be at least 1 MB, or left blank for unlimited.';
        }
        return null;
    }
}

if (!function_exists('createPlan')) {
    /**
     * @param array $in name, description, max_users(?int), max_storage_mb(?int),
     *                  feature_keys(string[]), sort_order(?int)
     */
    function createPlan(array $in): array
    {
        $name        = trim((string)($in['name'] ?? ''));
        $description = trim((string)($in['description'] ?? ''));
        if (mb_strlen($description) > 255) $description = mb_substr($description, 0, 255);
        $maxUsers   = $in['max_users'] ?? null;
        $maxStorage = $in['max_storage_mb'] ?? null;

        if ($err = planInputError($name, $maxUsers, $maxStorage)) {
            return ['ok' => false, 'error' => $err, 'id' => null];
        }

        $pdo = getControlPdo();

        // Unique plan_key, appending -2/-3/... on collision — same discipline
        // as tenant subdomain allocation, just simpler (no reserved-word list needed).
        $slug = planSlug($name);
        $base = $slug; $n = 2;
        while (true) {
            $st = $pdo->prepare("SELECT 1 FROM plans WHERE plan_key = ?");
            $st->execute([$slug]);
            if (!$st->fetchColumn()) break;
            $slug = $base . '-' . $n++;
        }

        $pdo->prepare("
            INSERT INTO plans (plan_key, name, description, max_users, max_storage_mb, sort_order)
            VALUES (?,?,?,?,?,?)
        ")->execute([$slug, $name, $description !== '' ? $description : null, $maxUsers, $maxStorage, (int)($in['sort_order'] ?? 0)]);
        $id = (int)$pdo->lastInsertId();

        $validKeys = array_keys(bmsFeatureRegistry());
        $features  = array_values(array_intersect((array)($in['feature_keys'] ?? []), $validKeys));
        if ($features) {
            $ins = $pdo->prepare("INSERT IGNORE INTO plan_features (plan_id, feature_key) VALUES (?,?)");
            foreach ($features as $fk) $ins->execute([$id, $fk]);
        }

        logTenantAdminAction(null, null, 'plan_create', $name . ' (' . count($features) . ' features)');
        return ['ok' => true, 'error' => null, 'id' => $id];
    }
}

if (!function_exists('updatePlan')) {
    /** Same $in shape as createPlan(). plan_key never changes once created. */
    function updatePlan(int $id, array $in): array
    {
        $plan = getPlan($id);
        if (!$plan) return ['ok' => false, 'error' => 'No such plan.'];

        $name        = trim((string)($in['name'] ?? ''));
        $description = trim((string)($in['description'] ?? ''));
        if (mb_strlen($description) > 255) $description = mb_substr($description, 0, 255);
        $maxUsers   = $in['max_users'] ?? null;
        $maxStorage = $in['max_storage_mb'] ?? null;

        if ($err = planInputError($name, $maxUsers, $maxStorage)) {
            return ['ok' => false, 'error' => $err];
        }

        $pdo = getControlPdo();
        $pdo->prepare("
            UPDATE plans SET name=?, description=?, max_users=?, max_storage_mb=?, sort_order=?
            WHERE id=?
        ")->execute([
            $name, $description !== '' ? $description : null, $maxUsers, $maxStorage,
            (int)($in['sort_order'] ?? $plan['sort_order']), $id,
        ]);

        $validKeys = array_keys(bmsFeatureRegistry());
        $features  = array_values(array_intersect((array)($in['feature_keys'] ?? []), $validKeys));
        $pdo->prepare("DELETE FROM plan_features WHERE plan_id = ?")->execute([$id]);
        if ($features) {
            $ins = $pdo->prepare("INSERT IGNORE INTO plan_features (plan_id, feature_key) VALUES (?,?)");
            foreach ($features as $fk) $ins->execute([$id, $fk]);
        }

        logTenantAdminAction(null, null, 'plan_update', $name . ' (' . count($features) . ' features)');
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('setPlanActive')) {
    /**
     * Retire/restore a plan. Never a hard DELETE — same convention as
     * features.is_available: tenants already showing this plan_key must keep
     * resolving to a real row, and the audit trail must keep something to
     * point at.
     */
    function setPlanActive(int $id, bool $active): array
    {
        $plan = getPlan($id);
        if (!$plan) return ['ok' => false, 'error' => 'No such plan.'];
        getControlPdo()->prepare("UPDATE plans SET is_active = ? WHERE id = ?")->execute([$active ? 1 : 0, $id]);
        logTenantAdminAction(null, null, $active ? 'plan_activate' : 'plan_deactivate', $plan['name']);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('applyPlanToTenant')) {
    /**
     * Apply one plan's feature set and quotas to one tenant, right now.
     *
     * A COMPLETE feature-set replacement, not incremental: every registered
     * feature not in the plan is explicitly turned off, same semantics as a
     * tenant_features override row. Reuses setTenantFeatures()/
     * setTenantQuotas() verbatim — no new write path, no new enforcement.
     *
     * @return array{ok:bool, error:?string, features_changed:int, quotas_changed:bool}
     */
    function applyPlanToTenant(int $tenantId, int $planId): array
    {
        $plan = getPlan($planId);
        if (!$plan) return ['ok' => false, 'error' => 'No such plan.', 'features_changed' => 0, 'quotas_changed' => false];
        if (!$plan['is_active']) {
            return ['ok' => false, 'error' => 'This plan is retired and can no longer be applied.', 'features_changed' => 0, 'quotas_changed' => false];
        }

        $t = getTenant($tenantId);
        if (!$t) return ['ok' => false, 'error' => 'Tenant not found.', 'features_changed' => 0, 'quotas_changed' => false];
        if ($t['status'] === 'deleted') {
            return ['ok' => false, 'error' => 'This tenant has been deleted.', 'features_changed' => 0, 'quotas_changed' => false];
        }

        $planFeatures = planFeatureKeys($planId);
        $desired = [];
        foreach (array_keys(bmsFeatureRegistry()) as $key) {
            $desired[$key] = in_array($key, $planFeatures, true);
        }

        $fr = setTenantFeatures($tenantId, $desired);
        $qr = setTenantQuotas(
            $tenantId,
            $plan['max_users'] !== null ? (int)$plan['max_users'] : null,
            $plan['max_storage_mb'] !== null ? (int)$plan['max_storage_mb'] : null
        );

        if (!$fr['ok'])  return ['ok' => false, 'error' => $fr['error'], 'features_changed' => 0, 'quotas_changed' => false];
        if (!$qr['ok'])  return ['ok' => false, 'error' => $qr['error'], 'features_changed' => $fr['changed'], 'quotas_changed' => false];

        getControlPdo()->prepare("UPDATE tenants SET plan = ? WHERE id = ?")->execute([$plan['plan_key'], $tenantId]);

        logTenantAdminAction($tenantId, $t['subdomain'], 'apply_plan',
            $plan['name'] . ' — ' . $fr['changed'] . ' feature change(s), quotas '
            . ($qr['changed'] ? 'updated' : 'unchanged'));

        return ['ok' => true, 'error' => null, 'features_changed' => $fr['changed'], 'quotas_changed' => $qr['changed']];
    }
}
