<?php
/**
 * core/vendor_link.php — which customer (in BJP's own books) pays for which
 * tenant (company on the platform). customer_subscription_plan.md Phase 3.
 *
 * The link lives in ONE place: tenants.vendor_customer_id in the control DB.
 * Customer Visits fills it ("joined" + subdomain → "Make real customer"), and
 * superadmin can set it for companies that registered without a visit. The
 * paid-invoice bridge (Phase 4) reads it. A visited client is never turned into
 * a customer automatically — a person decides.
 */
require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_resolver.php';           // tenantBaseDomain
require_once __DIR__ . '/tenant_lifecycle_policy.php';   // controlColumnExists

if (!function_exists('vlLinkReady')) {
    /** The control DB has tenants.vendor_customer_id (operator ran scripts/setup_control_db.php). */
    function vlLinkReady(): bool
    {
        return controlColumnExists('tenants', 'vendor_customer_id');
    }
}

if (!function_exists('vlNormalizeSubdomain')) {
    /** Accepts "duka", "duka.bms.bjptechnologies.co.tz" or a pasted URL; returns the label or ''. */
    function vlNormalizeSubdomain(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('#^https?://#', '', $s);
        $s = explode('/', $s)[0];
        $base = tenantBaseDomain();
        if ($base && substr($s, -strlen('.' . $base)) === '.' . $base) $s = substr($s, 0, -strlen('.' . $base));
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $s) ? $s : '';
    }
}

if (!function_exists('vlFindTenant')) {
    /** A live (not deleted) tenant by subdomain or id. */
    function vlFindTenant(?string $subdomain = null, ?int $id = null): ?array
    {
        $cols = "id, company_name, subdomain, status, owner_email, owner_phone, owner_first_name, owner_last_name, country"
              . (vlLinkReady() ? ", vendor_customer_id" : ", NULL AS vendor_customer_id")
              . (controlColumnExists('tenants', 'owner_contact_email') ? ", owner_contact_email" : "");
        try {
            if ($id !== null) {
                $st = getControlPdo()->prepare("SELECT $cols FROM tenants WHERE id = ? AND status <> 'deleted' LIMIT 1");
                $st->execute([$id]);
            } else {
                $st = getControlPdo()->prepare("SELECT $cols FROM tenants WHERE subdomain = ? AND status <> 'deleted' LIMIT 1");
                $st->execute([(string)$subdomain]);
            }
            $t = $st->fetch(PDO::FETCH_ASSOC);
            return $t ?: null;
        } catch (Throwable $e) {
            // Older control DBs lack some optional columns: retry with the essentials.
            try {
                $sql = "SELECT id, company_name, subdomain, status, owner_email FROM tenants WHERE "
                     . ($id !== null ? "id = ?" : "subdomain = ?") . " AND status <> 'deleted' LIMIT 1";
                $st = getControlPdo()->prepare($sql);
                $st->execute([$id ?? (string)$subdomain]);
                $t = $st->fetch(PDO::FETCH_ASSOC);
                return $t ? $t + ['vendor_customer_id' => null] : null;
            } catch (Throwable $e2) {
                error_log('vlFindTenant: ' . $e2->getMessage());
                return null;
            }
        }
    }
}

if (!function_exists('vlSetTenantCustomer')) {
    /**
     * Link (or with $customerId = null, unlink) a tenant to the customer who pays for it.
     * @return array{ok:bool, error:?string}
     */
    function vlSetTenantCustomer(int $tenantId, ?int $customerId, string $who, ?string $label = null): array
    {
        if (!vlLinkReady()) {
            return ['ok' => false, 'error' => 'The platform database has not been upgraded yet (run scripts/setup_control_db.php).'];
        }
        $t = vlFindTenant(null, $tenantId);
        if (!$t) return ['ok' => false, 'error' => 'Company not found on the platform.'];
        if ($customerId !== null && !empty($t['vendor_customer_id']) && (int)$t['vendor_customer_id'] !== $customerId) {
            return ['ok' => false, 'error' => 'This company is already linked to another customer. Remove that link first.'];
        }
        if (controlColumnExists('tenants', 'vendor_customer_label')) {
            getControlPdo()->prepare("UPDATE tenants SET vendor_customer_id = ?, vendor_customer_label = ? WHERE id = ?")
                ->execute([$customerId, $customerId ? mb_substr((string)$label, 0, 255) : null, $tenantId]);
        } else {
            getControlPdo()->prepare("UPDATE tenants SET vendor_customer_id = ? WHERE id = ?")->execute([$customerId, $tenantId]);
        }
        tenantLifecycleLog($tenantId, (string)$t['subdomain'], $customerId ? 'vendor_customer_linked' : 'vendor_customer_unlinked',
            ($customerId ? 'BJP customer ' . ($label ?: "#$customerId") : 'link removed') . " — by $who", $who);
        if ($customerId) vlLinkSettled($tenantId, $who);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('vlTenantsOfCustomer')) {
    /** @return array<int, array{id:int, subdomain:string, company_name:string, status:string}> */
    function vlTenantsOfCustomer(int $customerId): array
    {
        if ($customerId <= 0 || !vlLinkReady()) return [];
        $st = getControlPdo()->prepare("SELECT id, subdomain, company_name, status FROM tenants
                                         WHERE vendor_customer_id = ? AND status <> 'deleted' ORDER BY id");
        $st->execute([$customerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vlCustomerMatches')) {
    /** Existing customers that look like the same client (same phone or email) — offered before creating a new one. */
    function vlCustomerMatches(PDO $pdo, string $phone, string $email): array
    {
        $norm = function_exists('frNormalizePhone') ? frNormalizePhone($phone) : preg_replace('/\D+/', '', $phone);
        $tail = strlen($norm) >= 9 ? substr($norm, -9) : '';
        $where = []; $params = [];
        if ($tail !== '') {
            $where[] = "RIGHT(REGEXP_REPLACE(COALESCE(phone,''), '[^0-9]', ''), 9) = ?"; $params[] = $tail;
            $where[] = "RIGHT(REGEXP_REPLACE(COALESCE(mobile,''), '[^0-9]', ''), 9) = ?"; $params[] = $tail;
        }
        $email = strtolower(trim($email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $where[] = "LOWER(email) = ?"; $params[] = $email;
            $where[] = "LOWER(company_email) = ?"; $params[] = $email;
        }
        if (!$where) return [];
        $st = $pdo->prepare("SELECT customer_id, customer_code, customer_name, company_name, phone, email
                               FROM customers WHERE status <> 'deleted' AND (" . implode(' OR ', $where) . ")
                              ORDER BY customer_id LIMIT 5");
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vlCreateCustomer')) {
    /**
     * Create a customer the same way Customers → Add does: company-prefixed code,
     * §18 defaults, and the customer's ledger sub-account. Joins the caller's transaction.
     */
    function vlCreateCustomer(PDO $pdo, array $in, ?int $userId): int
    {
        require_once __DIR__ . '/code_generator.php';
        require_once __DIR__ . '/actor_account.php';
        $name = trim((string)($in['customer_name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('The customer needs a name.');
        $email = trim((string)($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('That email address is not valid.');

        $code = nextCode($pdo, 'CUST');
        $pdo->prepare("
            INSERT INTO customers
                (customer_code, customer_name, company_name, contact_person, customer_type, occupation_business,
                 email, phone, address, postal_address, tin_number, country, currency, status, notes,
                 created_by, created_at, year, category_id)
            VALUES (?, ?, ?, ?, 'business', ?, ?, ?, ?, ?, ?, ?, 'TZS', 'active', ?, ?, NOW(), ?, 0)
        ")->execute([
            $code, $name,
            trim((string)($in['company_name'] ?? '')) ?: null,
            trim((string)($in['contact_person'] ?? '')) ?: null,
            trim((string)($in['business'] ?? '')) ?: null,
            $email ?: null,
            trim((string)($in['phone'] ?? '')) ?: null,
            trim((string)($in['address'] ?? '')) ?: null,
            trim((string)($in['postal_address'] ?? '')) ?: null,
            trim((string)($in['tin_number'] ?? '')) ?: null,
            trim((string)($in['country'] ?? '')) ?: 'Tanzania',
            trim((string)($in['notes'] ?? '')) ?: null,
            $userId, date('Y'),
        ]);
        $id = (int)$pdo->lastInsertId();
        ensureActorLedgerAccount($pdo, 'customer', $id, $name);
        return $id;
    }
}

// ── One customer per company (service_plans_plan.md Phase 4) ─────────────────
// vlEnsureCustomerForTenant() is the only place a customer is created FOR a
// platform company: self-registration (through vendor_link_queue) and Customer
// Visits both go through it, so a company can never end up with two.

if (!function_exists('vlQueueReady')) {
    function vlQueueReady(): bool
    {
        try { return (bool)getControlPdo()->query("SHOW TABLES LIKE 'vendor_link_queue'")->fetch(); } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('vlTenantAlertRef')) {
    /** vendor_bridge_alerts.invoice_ref for alerts about a company rather than an invoice. */
    function vlTenantAlertRef(int $tenantId): string
    {
        return 'tenant:' . $tenantId;
    }
}

if (!function_exists('vlQueueTenantLink')) {
    /** Registration → "make this company a BJP customer" (drained on BJP's install). Never throws. */
    function vlQueueTenantLink(int $tenantId): bool
    {
        if ($tenantId <= 0 || !vlQueueReady()) return false;
        try {
            getControlPdo()->prepare("INSERT INTO vendor_link_queue (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE tenant_id = tenant_id")
                ->execute([$tenantId]);
            return true;
        } catch (Throwable $e) {
            error_log('vlQueueTenantLink: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('vlLinkSettled')) {
    /** The company has its customer now: close its "possible match" alert and its queue row. Never throws. */
    function vlLinkSettled(int $tenantId, string $by): void
    {
        try {
            $c = getControlPdo();
            if ((bool)$c->query("SHOW TABLES LIKE 'vendor_bridge_alerts'")->fetch()) {
                $c->prepare("UPDATE vendor_bridge_alerts SET resolved_at = NOW(), resolved_by = ?
                              WHERE kind = 'possible_match' AND tenant_id = ? AND resolved_at IS NULL")
                  ->execute([mb_substr($by, 0, 191), $tenantId]);
            }
            if (vlQueueReady()) {
                $c->prepare("UPDATE vendor_link_queue SET state = 'done', message = ? WHERE tenant_id = ? AND state <> 'done'")
                  ->execute([mb_substr('linked — ' . $by, 0, 500), $tenantId]);
            }
        } catch (Throwable $e) {
            error_log('vlLinkSettled: ' . $e->getMessage());
        }
    }
}

if (!function_exists('vlRaisePossibleMatch')) {
    /** A person must decide (D4): one open alert per company, naming the look-alike customers. */
    function vlRaisePossibleMatch(array $tenant, array $matches): void
    {
        try {
            $c = getControlPdo();
            $ref = vlTenantAlertRef((int)$tenant['id']);
            $st = $c->prepare("SELECT id FROM vendor_bridge_alerts WHERE invoice_ref = ? AND kind = 'possible_match' AND resolved_at IS NULL LIMIT 1");
            $st->execute([$ref]);
            if ($st->fetchColumn()) return;
            $names = implode(', ', array_map(fn($m) => trim($m['customer_code'] . ' — ' . $m['customer_name']), $matches));
            $msg = "{$tenant['company_name']} ({$tenant['subdomain']}) registered with the same phone or email as: $names. "
                 . "Link it to the right customer, or create a new one.";
            $c->prepare("INSERT INTO vendor_bridge_alerts (tenant_id, customer_id, invoice_ref, kind, message) VALUES (?, ?, ?, 'possible_match', ?)")
              ->execute([(int)$tenant['id'], (int)$matches[0]['customer_id'], $ref, mb_substr($msg, 0, 500)]);
        } catch (Throwable $e) {
            error_log('vlRaisePossibleMatch: ' . $e->getMessage());
        }
    }
}

if (!function_exists('vlTenantCustomerFields')) {
    /** A new customer's details, taken from the company's own registration. */
    function vlTenantCustomerFields(array $t): array
    {
        $owner = trim(($t['owner_first_name'] ?? '') . ' ' . ($t['owner_last_name'] ?? ''));
        return [
            'customer_name'  => (string)$t['company_name'],
            'company_name'   => (string)$t['company_name'],
            'contact_person' => $owner,
            'phone'          => (string)($t['owner_phone'] ?? ''),
            'email'          => vlTenantContactEmail($t),
            'country'        => (string)($t['country'] ?? ''),
            'notes'          => 'Registered on the platform — ' . $t['subdomain'],
        ];
    }
}

if (!function_exists('vlTenantContactEmail')) {
    /** The owner's real email: owner_contact_email, else owner_email when it is one (it often holds the login phone). */
    function vlTenantContactEmail(array $t): string
    {
        foreach (['owner_contact_email', 'owner_email'] as $k) {
            $e = trim((string)($t[$k] ?? ''));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
        }
        return '';
    }
}

if (!function_exists('vlEnsureCustomerForTenant')) {
    /**
     * Make sure the company has exactly one BJP customer.
     *   1. lock the company row (control DB, FOR UPDATE) — parallel calls queue here;
     *   2. already linked to a live customer → return it, create nothing;
     *   3. same phone/email as an older customer → create nothing, raise a
     *      possible_match alert (unless $opts['force']: a person already decided);
     *   4. else create the customer and link with a conditional UPDATE; if someone
     *      linked meanwhile, the new customer is rolled back.
     *
     * $opts: force (bool), fields (customer fields, default from the registration),
     *        user_id (?int, created_by), who (string, for the logs).
     *
     * @return array{state:string, message:string, customer_id:?int, customer_code:?string,
     *               customer_name:?string, matches:array}
     *         state: created | linked | needs_review | gone | retry
     */
    function vlEnsureCustomerForTenant(PDO $bjp, int $tenantId, string $origin, array $opts = []): array
    {
        $res = fn(string $state, string $msg, ?array $cu = null, array $matches = []) => [
            'state' => $state, 'message' => $msg,
            'customer_id' => $cu ? (int)$cu['customer_id'] : null,
            'customer_code' => $cu['customer_code'] ?? null, 'customer_name' => $cu['customer_name'] ?? null,
            'matches' => $matches,
        ];
        if (!vlLinkReady()) return $res('retry', 'the platform database has not been upgraded yet');
        if ($bjp->inTransaction()) throw new LogicException('vlEnsureCustomerForTenant() manages its own transactions');

        $live = function (int $cid) use ($bjp): ?array {
            $s = $bjp->prepare("SELECT customer_id, customer_code, customer_name FROM customers WHERE customer_id = ? AND status <> 'deleted'");
            $s->execute([$cid]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $who = (string)($opts['who'] ?? $origin);
        $c = getControlPdo();
        $newId = null;
        try {
            $c->beginTransaction();
            $st = $c->prepare("SELECT * FROM tenants WHERE id = ? AND status <> 'deleted' FOR UPDATE");
            $st->execute([$tenantId]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) { $c->rollBack(); return $res('gone', 'company not found on the platform'); }

            $old = $t['vendor_customer_id'] !== null ? (int)$t['vendor_customer_id'] : null;
            if ($old && ($cu = $live($old))) {
                $c->commit();
                vlLinkSettled($tenantId, $who);
                return $res('linked', 'already a customer', $cu);
            }

            if (empty($opts['force'])) {
                $matches = vlCustomerMatches($bjp, (string)($t['owner_phone'] ?? ''), vlTenantContactEmail($t));
                if ($matches) {
                    $c->commit();
                    vlRaisePossibleMatch($t, $matches);
                    return $res('needs_review', 'looks like an existing customer — waiting for a person to decide', null, $matches);
                }
            }

            $bjp->beginTransaction();
            $newId = vlCreateCustomer($bjp, $opts['fields'] ?? vlTenantCustomerFields($t), $opts['user_id'] ?? null);
            $cu = $live($newId);
            $label = $cu['customer_code'] . ' — ' . $cu['customer_name'];
            $u = controlColumnExists('tenants', 'vendor_customer_label')
                ? $c->prepare("UPDATE tenants SET vendor_customer_id = ?, vendor_customer_label = ? WHERE id = ? AND vendor_customer_id <=> ?")
                : null;
            if ($u) $u->execute([$newId, mb_substr($label, 0, 255), $tenantId, $old]);
            else {
                $u = $c->prepare("UPDATE tenants SET vendor_customer_id = ? WHERE id = ? AND vendor_customer_id <=> ?");
                $u->execute([$newId, $tenantId, $old]);
            }
            if ($u->rowCount() === 0) {
                // Linked by someone else after our lock (a path that does not lock): theirs wins.
                $bjp->rollBack(); $c->rollBack(); $newId = null;
                $now = vlFindTenant(null, $tenantId);
                $cu = !empty($now['vendor_customer_id']) ? $live((int)$now['vendor_customer_id']) : null;
                return $cu ? $res('linked', 'already a customer', $cu) : $res('retry', 'the company changed while linking — try again');
            }
            $bjp->commit();
            try {
                $c->commit();
            } catch (Throwable $e) {
                // The customer exists but the link did not save: retire it so a retry cannot leave two.
                $bjp->prepare("UPDATE customers SET status = 'deleted' WHERE customer_id = ?")->execute([$newId]);
                throw $e;
            }
            tenantLifecycleLog($tenantId, (string)$t['subdomain'], 'vendor_customer_linked',
                "BJP customer $label — created automatically ($origin) — by $who", $who);
            vlLinkSettled($tenantId, $who);
            return $res('created', "customer $label created", $cu);
        } catch (Throwable $e) {
            if ($bjp->inTransaction()) $bjp->rollBack();
            if ($c->inTransaction()) $c->rollBack();
            if ($e instanceof InvalidArgumentException) throw $e;   // bad form input: the caller shows it
            error_log("vlEnsureCustomerForTenant($tenantId, $origin): " . $e->getMessage());
            return $res('retry', mb_substr($e->getMessage(), 0, 300));
        }
    }
}

if (!function_exists('vlDrainLinkQueue')) {
    /**
     * BJP's install: turn queued registrations into customers. Safe to run as often
     * as wanted, from anywhere (cron, background jobs, the registration beacon).
     * @return array{processed:int, done:int, needs_review:int, pending:int, failed:int}
     */
    function vlDrainLinkQueue(PDO $bjp, int $limit = 20): array
    {
        $out = ['processed' => 0, 'done' => 0, 'needs_review' => 0, 'pending' => 0, 'failed' => 0];
        require_once __DIR__ . '/vendor_context.php';
        if (!isPlatformVendorContext() || !vlLinkReady() || !vlQueueReady()) return $out;
        try {
            $ids = getControlPdo()->query("SELECT tenant_id, attempts FROM vendor_link_queue WHERE state = 'pending' ORDER BY id LIMIT " . max(1, $limit))
                                  ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            error_log('vlDrainLinkQueue: ' . $e->getMessage());
            return $out;
        }
        foreach ($ids as $tid => $attempts) {
            $r = vlEnsureCustomerForTenant($bjp, (int)$tid, 'registration', ['who' => 'automatic (registration)']);
            $state = ['created' => 'done', 'linked' => 'done', 'needs_review' => 'needs_review', 'gone' => 'failed'][$r['state']]
                  ?? ((int)$attempts + 1 >= 10 ? 'failed' : 'pending');
            try {
                getControlPdo()->prepare("UPDATE vendor_link_queue SET state = ?, attempts = attempts + 1, message = ? WHERE tenant_id = ?")
                    ->execute([$state, mb_substr($r['message'], 0, 500), (int)$tid]);
            } catch (Throwable $e) {
                error_log('vlDrainLinkQueue update: ' . $e->getMessage());
            }
            $out['processed']++;
            $out[$state]++;
        }
        return $out;
    }
}
