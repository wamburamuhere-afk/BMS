<?php
/**
 * core/tenant_activity_alerts.php — superadmin bell for company activity
 * (mobile_api_upgrade_plan.md Phase 2).
 *
 *   first_login    the company's first sign-in (web or Flutter app) — instant,
 *                  from taaMarkActive() at sign-in / app use
 *   first_product  the company's first product          — runTenantActivityAlerts()
 *   first_sale     the company's first sale (POS or invoice) — same scan
 *   inactive       no activity for 7, then 14, then 30 days — same scan; re-arms
 *                  when the company is active again
 *
 * Each alert is sent once per company (tenant_activity_alerts remembers it).
 * Only companies created after the feature went live get "first" alerts, and
 * only companies active after it get "inactive" alerts, so the first run does
 * not flood the bell with history (activity_alerts_since, set on first use).
 * Bell only — no email. Best-effort throughout: never blocks a sign-in.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_crypto.php';
require_once __DIR__ . '/superadmin_notifications.php';
require_once __DIR__ . '/platform_settings.php';

if (!function_exists('taaSince')) {
    /** When the alerts went live (Y-m-d H:i:s), set on first use. */
    function taaSince(): string
    {
        $since = getPlatformSetting('activity_alerts_since', '');
        if ($since === '') {
            $since = date('Y-m-d H:i:s');
            setPlatformSetting('activity_alerts_since', $since);
        }
        return $since;
    }
}

if (!function_exists('taaSendOnce')) {
    /**
     * Ring the bell once for this company + alert key. Returns true when sent now.
     * $key is the dedupe key (first_login, first_product, first_sale, inactive_7…).
     */
    function taaSendOnce(array $t, string $key, string $type, string $detail): bool
    {
        try {
            $ins = getControlPdo()->prepare("INSERT IGNORE INTO tenant_activity_alerts (tenant_id, alert, sent_at) VALUES (?, ?, NOW())");
            $ins->execute([(int)$t['id'], $key]);
            if ($ins->rowCount() === 0) return false;   // already sent
            insertSaNotification((int)$t['id'], $type, (string)$t['company_name'], (string)$t['subdomain'], $detail);
            return true;
        } catch (Throwable $e) {
            error_log('taaSendOnce: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('taaMarkActive')) {
    /**
     * Stamp the superadmin "Last Active" for a company (web sign-in, app sign-in,
     * app use) and ring "first sign-in" when this is the first activity ever.
     */
    function taaMarkActive(int $tenantId, string $via = 'web'): void
    {
        try {
            $c = getControlPdo();
            $st = $c->prepare("SELECT id, company_name, subdomain, created_at, last_active_at FROM tenants WHERE id = ?");
            $st->execute([$tenantId]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) return;
            $c->prepare("UPDATE tenants SET last_active_at = NOW() WHERE id = ?")->execute([$tenantId]);
            if ($t['last_active_at'] === null && (string)$t['created_at'] >= taaSince()) {
                taaSendOnce($t, 'first_login', 'first_login', $via === 'app' ? 'signed in for the first time, on the mobile app.' : 'signed in for the first time.');
            }
        } catch (Throwable $e) {
            error_log('taaMarkActive: ' . $e->getMessage());
        }
    }
}

if (!function_exists('runTenantActivityAlerts')) {
    /**
     * Scan live companies and ring first-product / first-sale / inactive alerts.
     * Run by api/cron/trial_enforcement.php (daily) and, at most every 10 minutes,
     * when the superadmin panel is open (actions/superadmin_activity_scan.php).
     *
     * @return array{scanned:int, sent:array<int,string>}
     */
    function runTenantActivityAlerts(): array
    {
        $out = ['scanned' => 0, 'sent' => []];
        $since = taaSince();
        $c = getControlPdo();
        $sent = function (int $tid) use ($c): array {
            $s = $c->prepare("SELECT alert, sent_at FROM tenant_activity_alerts WHERE tenant_id = ?");
            $s->execute([$tid]);
            return $s->fetchAll(PDO::FETCH_KEY_PAIR);
        };

        $rows = $c->query("SELECT * FROM tenants WHERE status = 'active' AND db_name IS NOT NULL AND db_name <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $t) {
            $tid  = (int)$t['id'];
            $done = $sent($tid);
            $isNew = (string)$t['created_at'] >= $since;

            // ── Inactive 7 → 14 → 30 days (control DB only) ──────────────────
            $last = $t['last_active_at'];
            if ($last !== null && (string)$last >= $since) {
                // Active again since the last inactive alert → re-arm all stages.
                foreach (['inactive_7', 'inactive_14', 'inactive_30'] as $k) {
                    if (isset($done[$k]) && (string)$done[$k] < (string)$last) {
                        $c->prepare("DELETE FROM tenant_activity_alerts WHERE tenant_id = ? AND alert = ?")->execute([$tid, $k]);
                        unset($done[$k]);
                    }
                }
                $idleDays = (int)floor((time() - strtotime((string)$last)) / 86400);
                foreach ([30, 14, 7] as $d) {
                    if ($idleDays >= $d) {
                        if (!isset($done["inactive_$d"])
                            && taaSendOnce($t, "inactive_$d", 'inactive', "no activity for $d days (last active " . substr((string)$last, 0, 10) . ').')) {
                            $out['sent'][] = "$tid:inactive_$d";
                            // Lower stages count as passed, so they never ring after a higher one.
                            foreach ([7, 14] as $lower) {
                                if ($lower < $d) $c->prepare("INSERT IGNORE INTO tenant_activity_alerts (tenant_id, alert, sent_at) VALUES (?, ?, NOW())")
                                                  ->execute([$tid, "inactive_$lower"]);
                            }
                        }
                        break;
                    }
                }
            }

            // ── First product / first sale (needs the company's own database) ─
            if (!$isNew || (isset($done['first_product']) && isset($done['first_sale']))) continue;
            $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
            if ($pw === null) continue;
            try {
                $tp = new PDO('mysql:host=' . $t['db_host'] . ';dbname=' . $t['db_name'] . ';charset=utf8mb4',
                    $t['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $out['scanned']++;
                if (!isset($done['first_product'])
                    && (int)$tp->query("SELECT COUNT(*) FROM products")->fetchColumn() > 0
                    && taaSendOnce($t, 'first_product', 'first_product', 'added its first product.')) {
                    $out['sent'][] = "$tid:first_product";
                }
                if (!isset($done['first_sale'])) {
                    $sales = (int)$tp->query("SELECT COUNT(*) FROM pos_sales WHERE sale_status IN ('completed','partially_refunded','refunded')")->fetchColumn();
                    if ($sales === 0) {
                        try { $sales = (int)$tp->query("SELECT COUNT(*) FROM invoices WHERE status NOT IN ('draft','cancelled')")->fetchColumn(); }
                        catch (Throwable $e) { $sales = 0; }
                    }
                    if ($sales > 0 && taaSendOnce($t, 'first_sale', 'first_sale', 'made its first sale.')) {
                        $out['sent'][] = "$tid:first_sale";
                    }
                }
            } catch (Throwable $e) {
                error_log('runTenantActivityAlerts(' . $tid . '): ' . $e->getMessage());
            }
        }
        return $out;
    }
}
