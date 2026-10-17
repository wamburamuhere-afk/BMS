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
        $base = function_exists('tenantBaseDomain') ? tenantBaseDomain() : null;
        if ($base && substr($s, -strlen('.' . $base)) === '.' . $base) $s = substr($s, 0, -strlen('.' . $base));
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $s) ? $s : '';
    }
}

if (!function_exists('vlFindTenant')) {
    /** A live (not deleted) tenant by subdomain or id. */
    function vlFindTenant(?string $subdomain = null, ?int $id = null): ?array
    {
        $cols = "id, company_name, subdomain, status, owner_email, owner_phone, owner_first_name, owner_last_name, country"
              . (vlLinkReady() ? ", vendor_customer_id" : ", NULL AS vendor_customer_id");
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
    function vlCreateCustomer(PDO $pdo, array $in, int $userId): int
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
