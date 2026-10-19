<?php
/**
 * core/subscription_requests.php — what a company asked for in its Subscription
 * page, kept as a REQUEST at BJP (not a quotation). A BJP staff member opens it from
 * Quotations ("Requests from systems"), and the quotation form fills in from it; the
 * quotation they save is theirs (created_by = them) and goes through the normal
 * review → approve workflow. The company sees "Waiting for BJP" until it is approved.
 *
 *   pending  → waiting for BJP staff
 *   quoted   → a quotation was saved from it (quotation_id)
 *   declined → BJP said no (decline_reason is shown to the company)
 *   replaced → the company sent a newer request before BJP handled this one
 *
 * Only BJP's own install (the platform root) ever holds rows. Prices are worked out
 * on the server when the request arrives (core/platform_gateway.php pgRequestQuote).
 */

if (!function_exists('srEnsureTable')) {
    function srEnsureTable(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `subscription_requests` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `request_number` VARCHAR(30) NULL,
                `tenant_id`      INT NOT NULL,
                `subdomain`      VARCHAR(100) NOT NULL DEFAULT '',
                `company_name`   VARCHAR(255) NOT NULL DEFAULT '',
                `phone`          VARCHAR(50) NULL,
                `email`          VARCHAR(255) NULL,
                `customer_id`    INT NULL COMMENT 'the customer the company was linked to when it asked',
                `product_id`     INT NOT NULL,
                `term_token`     VARCHAR(40) NOT NULL COMMENT 'local term id or plan token p{id}',
                `term_label`     VARCHAR(255) NOT NULL DEFAULT '',
                `extras`         TEXT NULL COMMENT 'JSON {token: qty}',
                `lines`          TEXT NOT NULL COMMENT 'JSON: the priced lines the quotation form fills in',
                `subtotal`       DECIMAL(15,2) NOT NULL DEFAULT 0,
                `tax_amount`     DECIMAL(15,2) NOT NULL DEFAULT 0,
                `total`          DECIMAL(15,2) NOT NULL DEFAULT 0,
                `status`         ENUM('pending','quoted','declined','replaced') NOT NULL DEFAULT 'pending',
                `quotation_id`   INT NULL,
                `handled_by`     INT NULL,
                `decline_reason` VARCHAR(500) NULL,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `handled_at`     DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_sr_number` (`request_number`),
                KEY `idx_sr_status` (`status`),
                KEY `idx_sr_tenant` (`tenant_id`, `status`),
                KEY `idx_sr_quotation` (`quotation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('srReady')) {
    function srReady(PDO $pdo): bool
    {
        static $ready = false;
        if (!$ready) {
            try { $ready = (bool)$pdo->query("SHOW TABLES LIKE 'subscription_requests'")->fetch(); } catch (Throwable $e) { return false; }
        }
        return $ready;
    }
}

if (!function_exists('srCreate')) {
    /**
     * Stores a request; an earlier one from the same company still pending is marked replaced.
     * @param array $lines [['product_id','name','qty','unit_price','tax_rate','unit','token','term_label','description'], …]
     * @return array{id:int, number:string}
     */
    function srCreate(PDO $pdo, array $tenant, int $productId, string $termToken, string $termLabel, array $extras,
                      array $lines, float $subtotal, float $tax): array
    {
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE subscription_requests SET status = 'replaced', handled_at = NOW() WHERE tenant_id = ? AND status = 'pending'")
                ->execute([(int)$tenant['id']]);
            $phone = trim((string)($tenant['owner_phone'] ?? ''));
            $email = trim((string)(function_exists('vlTenantContactEmail') ? vlTenantContactEmail($tenant) : ($tenant['owner_email'] ?? '')));
            $pdo->prepare("INSERT INTO subscription_requests (tenant_id, subdomain, company_name, phone, email, customer_id, product_id, term_token,
                                                              term_label, extras, `lines`, subtotal, tax_amount, total, status, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())")
                ->execute([(int)$tenant['id'], (string)$tenant['subdomain'], (string)$tenant['company_name'], $phone !== '' ? $phone : null,
                           $email !== '' ? $email : null, (int)($tenant['vendor_customer_id'] ?? 0) ?: null, $productId, $termToken,
                           $termLabel, $extras ? json_encode($extras) : null, json_encode(array_values($lines)),
                           round($subtotal, 2), round($tax, 2), round($subtotal + $tax, 2)]);
            $id = (int)$pdo->lastInsertId();
            $number = 'SR-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE subscription_requests SET request_number = ? WHERE id = ?")->execute([$number, $id]);
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'number' => $number];
    }
}

if (!function_exists('srDecode')) {
    function srDecode(array $r): array
    {
        $r['id'] = (int)$r['id'];
        $r['tenant_id'] = (int)$r['tenant_id'];
        $r['product_id'] = (int)$r['product_id'];
        $r['customer_id'] = $r['customer_id'] !== null ? (int)$r['customer_id'] : null;
        $r['quotation_id'] = $r['quotation_id'] !== null ? (int)$r['quotation_id'] : null;
        $r['total'] = (float)$r['total'];
        $r['subtotal'] = (float)$r['subtotal'];
        $r['tax_amount'] = (float)$r['tax_amount'];
        $r['lines'] = json_decode((string)$r['lines'], true) ?: [];
        $r['extras'] = json_decode((string)($r['extras'] ?? ''), true) ?: [];
        return $r;
    }
}

if (!function_exists('srGet')) {
    function srGet(PDO $pdo, int $id): ?array
    {
        if ($id <= 0 || !srReady($pdo)) return null;
        $st = $pdo->prepare("SELECT * FROM subscription_requests WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? srDecode($r) : null;
    }
}

if (!function_exists('srPendingCount')) {
    function srPendingCount(PDO $pdo): int
    {
        if (!srReady($pdo)) return 0;
        return (int)$pdo->query("SELECT COUNT(*) FROM subscription_requests WHERE status = 'pending'")->fetchColumn();
    }
}

if (!function_exists('srPending')) {
    /** Pending requests, newest first, with the customer they are linked to now (the link may have been made since). */
    function srPending(PDO $pdo, int $limit = 100): array
    {
        if (!srReady($pdo)) return [];
        $rows = $pdo->query("SELECT * FROM subscription_requests WHERE status = 'pending' ORDER BY id DESC LIMIT " . max(1, min(500, $limit)))
                    ->fetchAll(PDO::FETCH_ASSOC);
        return array_map('srDecode', $rows);
    }
}

if (!function_exists('srDecline')) {
    /** @return bool false when it was no longer pending */
    function srDecline(PDO $pdo, int $id, int $userId, string $reason): bool
    {
        $st = $pdo->prepare("UPDATE subscription_requests SET status = 'declined', handled_by = ?, decline_reason = ?, handled_at = NOW()
                              WHERE id = ? AND status = 'pending'");
        $st->execute([$userId, mb_substr(trim($reason), 0, 500) ?: null, $id]);
        return $st->rowCount() === 1;
    }
}

if (!function_exists('srMarkQuoted')) {
    /** Called inside the quotation save transaction. @return bool false when it was no longer pending */
    function srMarkQuoted(PDO $pdo, int $id, int $quotationId, int $userId): bool
    {
        $st = $pdo->prepare("UPDATE subscription_requests SET status = 'quoted', quotation_id = ?, handled_by = ?, handled_at = NOW()
                              WHERE id = ? AND status = 'pending'");
        $st->execute([$quotationId, $userId, $id]);
        return $st->rowCount() === 1;
    }
}

if (!function_exists('srForTenant')) {
    /**
     * What the company sees under "Requests waiting for BJP": pending ones, ones quoted
     * whose quotation is not approved yet, and ones declined in the last 30 days.
     */
    function srForTenant(PDO $pdo, int $tenantId): array
    {
        if (!srReady($pdo)) return [];
        $st = $pdo->prepare("SELECT r.request_number, r.created_at, r.total, r.status, r.decline_reason, r.`lines`, q.status AS q_status
                               FROM subscription_requests r
                               LEFT JOIN quotations q ON q.sales_order_id = r.quotation_id
                              WHERE r.tenant_id = ?
                                AND (r.status = 'pending'
                                     OR (r.status = 'quoted' AND q.status IN ('draft','pending','reviewed'))
                                     OR (r.status = 'declined' AND r.handled_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)))
                              ORDER BY r.id DESC LIMIT 10");
        $st->execute([$tenantId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines = array_map(fn($l) => ['name' => (string)$l['name'], 'qty' => (float)$l['qty'], 'term' => $l['term_label'] ?? null],
                               json_decode((string)$r['lines'], true) ?: []);
            $out[] = ['number' => $r['request_number'], 'date' => substr((string)$r['created_at'], 0, 10), 'total' => (float)$r['total'],
                      'status' => $r['status'] === 'declined' ? 'declined' : 'waiting',
                      'reason' => $r['status'] === 'declined' ? $r['decline_reason'] : null, 'lines' => $lines];
        }
        return $out;
    }
}
