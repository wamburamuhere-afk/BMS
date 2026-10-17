<?php
/**
 * core/vendor_bridge.php — a fully paid BJP invoice for a subscription service
 * extends the customer's company on the platform. customer_subscription_plan.md Phase 4.
 *
 * Runs only in BJP's own install (core/vendor_context.php). Called AFTER the
 * payment has committed, by the three real-money paths: record_payment.php,
 * save_receipt.php, apply_customer_advance.php. Marking an invoice "paid" by hand
 * (update_invoice_status.php) never calls it — and even if it did, the paid total
 * is recomputed here from completed payments, so no money means no months.
 *
 * Every settled invoice goes into an outbox in BJP's database first, then is
 * processed straight away; anything that could not finish (control DB down) is
 * retried by api/cron/vendor_bridge_retry.php. A payment can never fail because
 * of this. Each invoice extends a tenant once: tenant_payments is unique on
 * (source='vendor_invoice', source_ref=invoice number).
 *
 * When it cannot decide safely it does nothing and raises an alert for the
 * superadmin instead: a subscription line without a term, a customer with no
 * linked company, or one with several.
 */
require_once __DIR__ . '/vendor_context.php';
require_once __DIR__ . '/vendor_link.php';
require_once __DIR__ . '/service_price_terms.php';
require_once __DIR__ . '/tenant_lifecycle_policy.php';

if (!function_exists('vbEnsureOutbox')) {
    function vbEnsureOutbox(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `vendor_bridge_outbox` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `invoice_id`   INT NOT NULL,
                `status`       ENUM('pending','done','skipped','alerted') NOT NULL DEFAULT 'pending',
                `attempts`     INT NOT NULL DEFAULT 0,
                `result`       VARCHAR(500) NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `processed_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_vbo_invoice` (`invoice_id`),
                KEY `idx_vbo_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('vbOutboxReady')) {
    function vbOutboxReady(PDO $pdo): bool
    {
        static $ready = false;
        if (!$ready) {
            try { $ready = (bool)$pdo->query("SHOW TABLES LIKE 'vendor_bridge_outbox'")->fetch(); } catch (Throwable $e) { return false; }
        }
        return $ready;
    }
}

if (!function_exists('vbAlertsReady')) {
    function vbAlertsReady(): bool
    {
        try { return (bool)getControlPdo()->query("SHOW TABLES LIKE 'vendor_bridge_alerts'")->fetch(); } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('vbAlert')) {
    /** One open alert per (invoice, kind) — re-running never piles up duplicates. */
    function vbAlert(?int $tenantId, ?int $customerId, string $invoiceRef, string $kind, string $message): bool
    {
        if (!vbAlertsReady()) return false;
        try {
            $c = getControlPdo();
            $st = $c->prepare("SELECT id FROM vendor_bridge_alerts WHERE invoice_ref = ? AND kind = ? AND resolved_at IS NULL LIMIT 1");
            $st->execute([$invoiceRef, $kind]);
            if ($st->fetchColumn()) return true;
            $c->prepare("INSERT INTO vendor_bridge_alerts (tenant_id, customer_id, invoice_ref, kind, message) VALUES (?, ?, ?, ?, ?)")
              ->execute([$tenantId, $customerId, mb_substr($invoiceRef, 0, 64), $kind, mb_substr($message, 0, 500)]);
            return true;
        } catch (Throwable $e) {
            error_log('vbAlert: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('vbResolveAlerts')) {
    /** Close the open alerts for an invoice once it has been applied (or a person dealt with it). */
    function vbResolveAlerts(string $invoiceRef, string $by): void
    {
        if (!vbAlertsReady()) return;
        try {
            getControlPdo()->prepare("UPDATE vendor_bridge_alerts SET resolved_at = NOW(), resolved_by = ?
                                       WHERE invoice_ref = ? AND resolved_at IS NULL AND kind <> 'payment_voided'")
                ->execute([mb_substr($by, 0, 191), $invoiceRef]);
        } catch (Throwable $e) {
            error_log('vbResolveAlerts: ' . $e->getMessage());
        }
    }
}

if (!function_exists('vbInvoicePaidTotal')) {
    /** Money actually received against the invoice — the same sum the payment endpoints use. */
    function vbInvoicePaidTotal(PDO $pdo, int $invoiceId): float
    {
        $alloc = (float)$pdo->query("SELECT COALESCE(SUM(pa.allocated_amount),0) FROM payment_allocations pa
                                       JOIN payments p ON p.payment_id = pa.payment_id
                                      WHERE pa.target_type = 'invoice' AND pa.target_id = $invoiceId AND p.status = 'completed'")->fetchColumn();
        $legacy = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments
                                       WHERE invoice_id = $invoiceId AND status = 'completed'
                                         AND payment_id NOT IN (SELECT payment_id FROM payment_allocations)")->fetchColumn();
        return round($alloc + $legacy, 2);
    }
}

if (!function_exists('vbSubscriptionLines')) {
    /** The invoice's lines for services marked as BMS subscriptions. */
    function vbSubscriptionLines(PDO $pdo, int $invoiceId): array
    {
        if (!sptReady($pdo)) return [];
        $termCols = sptLineColumnsReady($pdo, 'invoice_items')
            ? 'ii.term_duration_value, ii.term_duration_unit, ii.price_term_label'
            : 'NULL AS term_duration_value, NULL AS term_duration_unit, NULL AS price_term_label';
        $st = $pdo->prepare("SELECT ii.invoice_item_id, ii.product_name, ii.quantity, ii.line_total, $termCols
                               FROM invoice_items ii
                               JOIN vendor_subscription_services vss ON vss.product_id = ii.product_id
                              WHERE ii.invoice_id = ?");
        $st->execute([$invoiceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vbProcessInvoice')) {
    /**
     * Decide and apply. Never throws. Returns what happened:
     *   done | skipped (nothing to do) | alerted (needs a person) | retry (try again later)
     *
     * @return array{state:string, message:string, tenant_id?:int, months?:int, ends_at?:string}
     */
    function vbProcessInvoice(PDO $pdo, int $invoiceId): array
    {
        try {
            $st = $pdo->prepare("SELECT invoice_id, invoice_number, customer_id, grand_total, status FROM invoices WHERE invoice_id = ?");
            $st->execute([$invoiceId]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$inv) return ['state' => 'skipped', 'message' => 'invoice not found'];
            if ($inv['status'] === 'cancelled') return ['state' => 'skipped', 'message' => 'invoice cancelled'];

            $lines = vbSubscriptionLines($pdo, $invoiceId);
            if (!$lines) return ['state' => 'skipped', 'message' => 'no subscription service on this invoice'];

            $paid = vbInvoicePaidTotal($pdo, $invoiceId);
            if ($paid < (float)$inv['grand_total'] - 0.01) {
                return ['state' => 'skipped', 'message' => 'not fully paid yet (' . number_format($paid, 2) . ' of ' . number_format((float)$inv['grand_total'], 2) . ')'];
            }

            $ref = (string)$inv['invoice_number'];
            $cid = (int)$inv['customer_id'];

            $months = 0; $amount = 0.0;
            foreach ($lines as $l) {
                $m = ($l['term_duration_value'] !== null && $l['term_duration_unit'] !== null)
                    ? sptMonths((int)$l['term_duration_value'], (string)$l['term_duration_unit']) : null;
                $qty = (float)$l['quantity'];
                if ($m === null || abs($qty - round($qty)) > 0.0001 || $qty <= 0) {
                    vbAlert(null, $cid, $ref, 'no_term', "Invoice $ref is paid, but \"{$l['product_name']}\" has no month/year term (or a fractional quantity), so the months could not be worked out. Extend the company by hand.");
                    return ['state' => 'alerted', 'message' => 'subscription line without a usable term'];
                }
                $months += (int)round($qty) * $m;
                $amount += (float)$l['line_total'];
            }

            if (!vlLinkReady()) return ['state' => 'retry', 'message' => 'control database not upgraded yet'];
            $tenants = vlTenantsOfCustomer($cid);
            if (count($tenants) === 0) {
                vbAlert(null, $cid, $ref, 'not_linked', "Invoice $ref is paid ($months month(s)), but customer #$cid is not linked to any company on the platform. Link it, then the payment applies.");
                return ['state' => 'alerted', 'message' => 'customer not linked to a company'];
            }
            if (count($tenants) > 1) {
                $names = implode(', ', array_column($tenants, 'subdomain'));
                vbAlert(null, $cid, $ref, 'many_tenants', "Invoice $ref is paid ($months month(s)), but customer #$cid pays for several companies ($names). Record it on the right one by hand.");
                return ['state' => 'alerted', 'message' => 'customer linked to several companies'];
            }

            $t = $tenants[0];
            $r = applySubscriptionPayment((int)$t['id'], $months, (int)round($amount), 'vendor_invoice', $ref,
                "BJP invoice $ref — paid in full", null);
            if (!$r['ok']) {
                vbAlert((int)$t['id'], $cid, $ref, 'apply_failed', "Invoice $ref is paid but could not extend {$t['subdomain']}: {$r['error']}");
                return ['state' => 'alerted', 'message' => (string)$r['error']];
            }
            vbResolveAlerts($ref, 'applied');
            // Phase 6: the subscription agreement for this payment (only if the service has a contract template).
            require_once __DIR__ . '/subscription_contracts.php';
            scCreateForInvoice($pdo, $invoiceId, $t, $r['starts_at'] ?? null, $r['ends_at'] ?? null);
            return ['state' => 'done', 'message' => $r['duplicate'] ? 'already applied' : "extended {$t['subdomain']} by $months month(s) to {$r['ends_at']}",
                    'tenant_id' => (int)$t['id'], 'months' => $months, 'ends_at' => (string)$r['ends_at']];
        } catch (Throwable $e) {
            error_log("vbProcessInvoice($invoiceId): " . $e->getMessage());
            return ['state' => 'retry', 'message' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
}

if (!function_exists('vbRunOutboxItem')) {
    /** Process one queued invoice and record the outcome on its outbox row. */
    function vbRunOutboxItem(PDO $pdo, int $invoiceId): array
    {
        $res = vbProcessInvoice($pdo, $invoiceId);
        $status = $res['state'] === 'retry' ? 'pending' : $res['state'];
        if (vbOutboxReady($pdo)) {
            $pdo->prepare("UPDATE vendor_bridge_outbox SET status = ?, attempts = attempts + 1, result = ?,
                                  processed_at = IF(? = 'pending', processed_at, NOW())
                            WHERE invoice_id = ?")
                ->execute([$status, mb_substr($res['message'], 0, 500), $status, $invoiceId]);
        }
        return $res;
    }
}

if (!function_exists('vendorOnInvoicesSettled')) {
    /**
     * Hook for the payment endpoints — call AFTER commit with the invoices the
     * payment touched. No-op outside BJP's install. Never throws.
     */
    function vendorOnInvoicesSettled(PDO $pdo, array $invoiceIds): void
    {
        if (!isPlatformVendorContext()) return;
        try {
            if (!vbOutboxReady($pdo)) return;
            foreach (array_unique(array_filter(array_map('intval', $invoiceIds))) as $iid) {
                $bal = $pdo->query("SELECT balance_due FROM invoices WHERE invoice_id = $iid")->fetchColumn();
                if ($bal === false || (float)$bal > 0.01) continue;   // partial payments wait for the last one
                // Re-queue a row that ended earlier (e.g. it was alerted, then the customer got linked).
                $pdo->prepare("INSERT INTO vendor_bridge_outbox (invoice_id) VALUES (?)
                               ON DUPLICATE KEY UPDATE status = IF(status = 'done', 'done', 'pending')")->execute([$iid]);
                vbRunOutboxItem($pdo, $iid);
            }
        } catch (Throwable $e) {
            error_log('vendorOnInvoicesSettled: ' . $e->getMessage());
        }
    }
}

if (!function_exists('vendorOnPaymentVoided')) {
    /**
     * A payment on a subscription invoice was voided. Access is NOT shortened
     * automatically (a decision about money) — the superadmin is alerted instead.
     */
    function vendorOnPaymentVoided(PDO $pdo, array $invoiceIds, string $paymentNumber): void
    {
        if (!isPlatformVendorContext()) return;
        try {
            foreach (array_unique(array_filter(array_map('intval', $invoiceIds))) as $iid) {
                $ref = (string)$pdo->query("SELECT invoice_number FROM invoices WHERE invoice_id = $iid")->fetchColumn();
                if ($ref === '' || !controlColumnExists('tenant_payments', 'source_ref')) continue;
                $st = getControlPdo()->prepare("SELECT tp.tenant_id, tp.duration_months, t.subdomain FROM tenant_payments tp
                                                  JOIN tenants t ON t.id = tp.tenant_id
                                                 WHERE tp.source = 'vendor_invoice' AND tp.source_ref = ? LIMIT 1");
                $st->execute([$ref]);
                $tp = $st->fetch(PDO::FETCH_ASSOC);
                if (!$tp) continue;
                vbAlert((int)$tp['tenant_id'], null, $ref, 'payment_voided',
                    "Payment $paymentNumber on invoice $ref was voided after it had extended {$tp['subdomain']} by {$tp['duration_months']} month(s). Review that company's access.");
            }
        } catch (Throwable $e) {
            error_log('vendorOnPaymentVoided: ' . $e->getMessage());
        }
    }
}

if (!function_exists('vendorBridgeRetry')) {
    /** Cron: retry every queued invoice still pending. @return array{processed:int, done:int, pending:int} */
    function vendorBridgeRetry(PDO $pdo, int $limit = 50): array
    {
        $out = ['processed' => 0, 'done' => 0, 'pending' => 0];
        if (!vbOutboxReady($pdo)) return $out;
        $ids = $pdo->query("SELECT invoice_id FROM vendor_bridge_outbox WHERE status = 'pending' ORDER BY id LIMIT " . (int)$limit)
                   ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $iid) {
            $r = vbRunOutboxItem($pdo, (int)$iid);
            $out['processed']++;
            if ($r['state'] === 'done') $out['done']++;
            if ($r['state'] === 'retry') $out['pending']++;
        }
        return $out;
    }
}

if (!function_exists('vendorRequeueCustomer')) {
    /**
     * A customer was just linked to a company: retry their paid subscription
     * invoices that were waiting for exactly that. Never throws.
     */
    function vendorRequeueCustomer(PDO $pdo, int $customerId): void
    {
        if (!isPlatformVendorContext() || !vbOutboxReady($pdo)) return;
        try {
            $st = $pdo->prepare("SELECT o.invoice_id FROM vendor_bridge_outbox o JOIN invoices i ON i.invoice_id = o.invoice_id
                                  WHERE i.customer_id = ? AND o.status IN ('alerted','pending')");
            $st->execute([$customerId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                $pdo->prepare("UPDATE vendor_bridge_outbox SET status = 'pending' WHERE invoice_id = ?")->execute([$iid]);
                vbRunOutboxItem($pdo, (int)$iid);
            }
        } catch (Throwable $e) {
            error_log('vendorRequeueCustomer: ' . $e->getMessage());
        }
    }
}
