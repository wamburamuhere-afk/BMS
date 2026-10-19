<?php
/**
 * core/platform_gateway.php — the platform root's side of a company asking about
 * its own BMS subscription (customer_subscription_plan.md Phase 5). Runs only in
 * BJP's install; reached through api/platform/tenant_gateway.php.
 *
 *   catalog        → the subscription services and their terms (prices from BJP's books)
 *   documents      → this company's quotations and invoices, with customer-copy links —
 *                    only once BJP's own workflow has APPROVED them (service_plans_plan.md D7)
 *   request_quote  → a REQUEST for the chosen term (+ extras), catalogue price
 *                    (core/subscription_requests.php). A BJP staff member turns it into a
 *                    quotation from Quotations, which is then reviewed and approved as usual.
 *                    One pending request per company.
 *
 * The company is identified only by the signed tenant id, and only the customer
 * linked to it (tenants.vendor_customer_id) is ever read.
 */
require_once __DIR__ . '/platform_link.php';
require_once __DIR__ . '/vendor_context.php';
require_once __DIR__ . '/vendor_link.php';
require_once __DIR__ . '/service_price_terms.php';
require_once __DIR__ . '/subscription_contracts.php';
require_once __DIR__ . '/subscription_requests.php';

if (!function_exists('pgHandle')) {
    /** @param array $headers X-Platform-Tenant / -Ts / -Sig @return array{0:int,1:array} [http code, body] */
    function pgHandle(PDO $pdo, string $body, array $headers): array
    {
        if (!isPlatformVendorContext()) return [404, ['success' => false, 'message' => 'Not available here.']];
        $tid = (int)($headers['X-Platform-Tenant'] ?? 0);
        if (!plVerify($tid, (int)($headers['X-Platform-Ts'] ?? 0), $body, (string)($headers['X-Platform-Sig'] ?? ''))) {
            return [401, ['success' => false, 'message' => 'Unauthorized']];
        }
        $tenant = vlFindTenant(null, $tid);
        if (!$tenant) return [404, ['success' => false, 'message' => 'Company not found.']];
        $req = json_decode($body, true) ?: [];
        $cid = (int)($tenant['vendor_customer_id'] ?? 0);

        switch ((string)($req['action'] ?? '')) {
            case 'catalog':   return [200, pgCatalog($pdo, $tenant) + ['linked' => $cid > 0]];
            case 'documents': return [200, $cid > 0 ? pgDocuments($pdo, $cid, (int)$tenant['id'])
                                  : ['success' => true, 'linked' => false, 'quotations' => [], 'invoices' => [], 'requests' => srForTenant($pdo, (int)$tenant['id']), 'latest' => null]];
            case 'contracts':
                return [200, $cid > 0 ? pgContracts($pdo, $cid) : ['success' => true, 'linked' => false, 'contracts' => []]];
            case 'contract_sign_link':
                if ($cid <= 0) return [200, ['success' => false, 'message' => 'Your company is not linked to a customer account at BJP yet.']];
                return [200, pgContractSignLink($pdo, $tenant, $cid, (int)($req['contract_id'] ?? 0))];
            case 'request_quote':
                // A company not linked to a customer yet may still ask: BJP picks (and links) the customer when quoting.
                // term_id: a local term id, or a plan token "p12" (validated against the control DB).
                $termReq = $req['term_id'] ?? 0;
                $termReq = is_string($termReq) && sptParseLineToken($termReq) !== null ? $termReq : (int)$termReq;
                // extras: {"a3-12": 2, …} — extra shops/users for the chosen plan and term (mapped services only).
                $extras = is_array($req['extras'] ?? null) ? $req['extras'] : [];
                return [200, pgRequestQuote($pdo, $tenant, $cid, (int)($req['product_id'] ?? 0), $termReq, $extras)];
        }
        return [422, ['success' => false, 'message' => 'Unknown action.']];
    }
}

if (!function_exists('pgCatalog')) {
    function pgCatalog(PDO $pdo, ?array $tenant = null): array
    {
        $out = [];
        if (sptReady($pdo)) {
            $rows = $pdo->query("SELECT p.product_id, p.product_name, p.description FROM vendor_subscription_services v
                                   JOIN products p ON p.product_id = v.product_id AND p.status = 'active'
                                  ORDER BY p.product_name")->fetchAll(PDO::FETCH_ASSOC);
            $mapped = vssPlatformProductsFor($pdo, array_column($rows, 'product_id'));
            foreach ($rows as $r) {
                $terms = [];
                if (isset($mapped[(int)$r['product_id']])) {
                    // A mapped service sells Superadmin's plans: public ones, plus the plan the company is on (to renew it).
                    $extras = [];
                    foreach (pgPlanOptions($mapped[(int)$r['product_id']], $tenant, true) as $o) {
                        if ($o['kind'] === 'addon') {
                            // Offered under the plan + term it belongs to (plan_price_id).
                            $extras[] = ['term_id' => $o['term_id'], 'plan_price_id' => $o['plan_price_id'], 'name' => $o['addon_name'],
                                         'quota' => $o['quota'], 'units' => $o['units'], 'price' => $o['price']];
                            continue;
                        }
                        $terms[] = ['term_id' => $o['term_id'], 'term_label' => $o['display_label'], 'months' => $o['months'],
                                    'price' => $o['price'], 'description' => null, 'is_default' => $o['is_default'],
                                    'plan_key' => $o['plan_key'], 'plan_name' => $o['plan_name'], 'group' => $o['group'],
                                    'plan_price_id' => $o['plan_price_id']];
                    }
                    if ($terms) $out[] = ['product_id' => (int)$r['product_id'], 'name' => $r['product_name'], 'description' => $r['description'],
                                          'terms' => $terms, 'extras' => $extras, 'plans' => pgPlanSummaries($mapped[(int)$r['product_id']], $tenant)];
                    continue;
                }
                foreach (sptForProduct($pdo, (int)$r['product_id']) as $t) {
                    $m = sptMonths((int)$t['duration_value'], (string)$t['duration_unit']);
                    if ($m === null) continue;
                    $terms[] = ['term_id' => (int)$t['term_id'], 'term_label' => $t['display_label'] ?? $t['term_label'], 'months' => $m,
                                'price' => (float)$t['price'], 'description' => $t['description'], 'is_default' => (int)$t['is_default'] === 1];
                }
                if ($terms) $out[] = ['product_id' => (int)$r['product_id'], 'name' => $r['product_name'], 'description' => $r['description'], 'terms' => $terms];
            }
        }
        return ['success' => true, 'services' => $out];
    }
}

if (!function_exists('pgPlanOptions')) {
    /** The plan options (and, with $withExtras, their extras) a company may request for a mapped service. */
    function pgPlanOptions(string $productKey, ?array $tenant, bool $withExtras = false): array
    {
        require_once __DIR__ . '/service_plans.php';
        $cur = $tenant !== null && !empty($tenant['plan']) ? (string)$tenant['plan'] : null;
        return array_values(array_filter(spPlatformLineOptions($productKey, true, $cur), fn($o) => $withExtras || $o['kind'] === 'plan'));
    }
}

if (!function_exists('pgPlanSummaries')) {
    /** What each offered plan includes (shown on the company's Billing page): limits + the bullet list. */
    function pgPlanSummaries(string $productKey, ?array $tenant): array
    {
        $out = [];
        try {
            require_once __DIR__ . '/plans.php';
            $cur = $tenant !== null && !empty($tenant['plan']) ? (string)$tenant['plan'] : '';
            $st = getControlPdo()->prepare("SELECT id, plan_key, name, tagline, product_key, max_users, max_shops, max_storage_mb, is_popular FROM plans
                                             WHERE product_key = ? AND is_active = 1 AND plan_key <> 'blank' AND (is_public = 1 OR plan_key = ?)
                                             ORDER BY tier_rank, sort_order, name");
            $st->execute([$productKey, $cur]);
            $registry = bmsFeatureRegistry();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $items = getControlPdo()->prepare("SELECT label, value FROM plan_items WHERE plan_id = ? ORDER BY sort_order, id");
                $items->execute([(int)$p['id']]);
                // Same module list registration shows and applyPlanToTenant() turns on.
                $modules = [];
                foreach (planNormalizeFeaturesForProduct(planFeatureKeys((int)$p['id']), $p['product_key']) as $fk) {
                    if (isset($registry[$fk])) $modules[] = (string)$registry[$fk]['label'];
                }
                sort($modules);
                $out[] = ['plan_key' => $p['plan_key'], 'name' => $p['name'], 'tagline' => $p['tagline'] !== null ? (string)$p['tagline'] : null,
                          'max_users' => $p['max_users'] !== null ? (int)$p['max_users'] : null,
                          'max_shops' => $p['max_shops'] !== null ? (int)$p['max_shops'] : null, 'is_popular' => (int)$p['is_popular'] === 1,
                          'modules' => $modules, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)];
            }
        } catch (Throwable $e) {
            error_log('pgPlanSummaries: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('pgDocuments')) {
    /**
     * The company sees a document only once BJP's workflow has finished with it (D7):
     * a quotation once approved (converted → "accepted"), an invoice once approved
     * (unpaid / partial / paid). Cancelled ones only if they had been approved (= shown
     * before). Draft / pending / reviewed are never shown. Its own requests still waiting
     * for BJP (or declined lately) come back as `requests` (no link, no document).
     * `latest` = when the newest visible document became visible (the Billing badge).
     */
    function pgDocuments(PDO $pdo, int $cid, ?int $tenantId = null): array
    {
        $q = $pdo->prepare("SELECT sales_order_id AS id, order_number AS number, order_date AS date, grand_total AS total, status,
                                   converted_to_so_id, quote_valid_until, COALESCE(approved_at, created_at) AS visible_at
                              FROM quotations
                             WHERE customer_id = ? AND (status IN ('approved','processing','shipped','delivered','completed')
                                                        OR (status = 'cancelled' AND approved_at IS NOT NULL))
                             ORDER BY sales_order_id DESC LIMIT 50");
        $q->execute([$cid]);
        $qRows = $q->fetchAll(PDO::FETCH_ASSOC);
        $qLines = pgLineSummaries($pdo, 'quotation_items', 'order_id', array_column($qRows, 'id'));
        $quotes = array_map(fn($r) => [
            'number' => $r['number'], 'date' => $r['date'], 'total' => (float)$r['total'],
            'status' => $r['status'] === 'cancelled' ? 'cancelled' : ($r['converted_to_so_id'] || $r['status'] !== 'approved' ? 'accepted' : 'approved'),
            'valid_until' => $r['quote_valid_until'], 'visible_at' => $r['visible_at'], 'lines' => $qLines[(int)$r['id']] ?? [],
            'view_token' => plDocToken('quotation', (int)$r['id'], $cid),
        ], $qRows);

        $i = $pdo->prepare("SELECT invoice_id AS id, invoice_number AS number, invoice_date AS date, due_date, grand_total AS total,
                                   paid_amount, balance_due, status, COALESCE(approved_at, created_at) AS visible_at
                              FROM invoices
                             WHERE customer_id = ? AND (status IN ('approved','sent','partial','paid','overdue')
                                                        OR (status = 'cancelled' AND approved_at IS NOT NULL))
                             ORDER BY invoice_id DESC LIMIT 50");
        $i->execute([$cid]);
        $iRows = $i->fetchAll(PDO::FETCH_ASSOC);
        $iLines = pgLineSummaries($pdo, 'invoice_items', 'invoice_id', array_column($iRows, 'id'));
        $invoices = array_map(fn($r) => [
            'number' => $r['number'], 'date' => $r['date'], 'due_date' => $r['due_date'], 'total' => (float)$r['total'],
            'paid' => (float)$r['paid_amount'], 'balance' => (float)$r['balance_due'],
            'status' => $r['status'] === 'cancelled' ? 'cancelled'
                      : ((float)$r['balance_due'] <= 0.01 ? 'paid' : ((float)$r['paid_amount'] > 0.01 ? 'partial' : 'unpaid')),
            'visible_at' => $r['visible_at'], 'lines' => $iLines[(int)$r['id']] ?? [],
            'view_token' => plDocToken('invoice', (int)$r['id'], $cid),
        ], $iRows);

        $requests = [];
        if ($tenantId !== null) {
            $requests = srForTenant($pdo, $tenantId);
            // Older requests were saved straight as quotations (reference SELF-SERVICE T{id}).
            $rq = $pdo->prepare("SELECT sales_order_id AS id, order_number AS number, order_date AS date, grand_total AS total FROM quotations
                                  WHERE customer_id = ? AND reference = ? AND status IN ('draft','pending','reviewed')
                                  ORDER BY sales_order_id DESC LIMIT 10");
            $rq->execute([$cid, 'SELF-SERVICE T' . $tenantId]);
            $rRows = $rq->fetchAll(PDO::FETCH_ASSOC);
            $rLines = pgLineSummaries($pdo, 'quotation_items', 'order_id', array_column($rRows, 'id'));
            foreach ($rRows as $r) {
                $requests[] = ['number' => $r['number'], 'date' => $r['date'], 'total' => (float)$r['total'], 'status' => 'waiting', 'lines' => $rLines[(int)$r['id']] ?? []];
            }
        }
        $latest = null;
        foreach (array_merge($quotes, $invoices) as $d) if ($d['visible_at'] !== null && ($latest === null || $d['visible_at'] > $latest)) $latest = $d['visible_at'];
        return ['success' => true, 'linked' => true, 'quotations' => $quotes, 'invoices' => $invoices, 'requests' => $requests, 'latest' => $latest];
    }
}

if (!function_exists('pgLineSummaries')) {
    /** [doc id => [[name, qty, term], …]] — what each document is for (subscription lines show their plan + term). */
    function pgLineSummaries(PDO $pdo, string $table, string $fk, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !in_array($table, ['quotation_items', 'invoice_items'], true)) return [];
        $hasTerm = sptLineColumnsReady($pdo, $table);
        $st = $pdo->query("SELECT $fk AS doc, product_name, quantity" . ($hasTerm ? ', price_term_label' : ', NULL AS price_term_label') .
                          " FROM $table WHERE $fk IN (" . implode(',', $ids) . ")");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $out[(int)$l['doc']][] = ['name' => (string)$l['product_name'], 'qty' => (float)$l['quantity'], 'term' => $l['price_term_label']];
        }
        return $out;
    }
}

if (!function_exists('pgContracts')) {
    function pgContracts(PDO $pdo, int $cid): array
    {
        $out = [];
        foreach (scForCustomer($pdo, $cid) as $c) {
            if ($c['state'] === 'cancelled') continue;
            // The customer sees a contract once BJP has signed it (and the final copy once they signed too).
            $viewDoc = $c['state'] === 'signed' ? $c['final_document_id'] : ($c['state'] === 'awaiting_customer' ? $c['bjp_signed_document_id'] : null);
            $out[] = [
                'contract_id' => (int)$c['contract_id'], 'code' => $c['document_code'], 'service' => $c['product_name'],
                'period' => $c['period_label'], 'period_start' => $c['period_start'], 'period_end' => $c['period_end'],
                'invoice' => $c['invoice_ref'], 'state' => $c['state'], 'date' => substr((string)$c['created_at'], 0, 10),
                'view_token' => $viewDoc ? plDocToken('contract', (int)$c['contract_id'], $cid) : null,
            ];
        }
        return ['success' => true, 'linked' => true, 'contracts' => $out];
    }
}

if (!function_exists('pgContractSignLink')) {
    function pgContractSignLink(PDO $pdo, array $tenant, int $cid, int $contractId): array
    {
        $st = $pdo->prepare("SELECT cc.*, c.customer_name, c.company_name, c.email FROM customer_contracts cc
                               JOIN customers c ON c.customer_id = cc.customer_id
                              WHERE cc.contract_id = ? AND cc.customer_id = ?");
        $st->execute([$contractId, $cid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['success' => false, 'message' => 'Contract not found.'];
        $state = scState($pdo, $c)['state'];
        if ($state === 'awaiting_bjp') return ['success' => false, 'message' => 'BJP has not signed this contract yet. It will be ready for you shortly.'];
        if ($state !== 'awaiting_customer') return ['success' => false, 'message' => 'This contract is already signed.'];
        $email = trim((string)($c['email'] ?: ($tenant['owner_email'] ?? '')));
        $token = scCustomerSignLink($pdo, $c, (string)($c['company_name'] ?: $c['customer_name']), $email);
        if ($token === null) return ['success' => false, 'message' => 'Could not prepare the signing page. Please try again.'];
        $url = (function_exists('buildUrl') ? buildUrl('sign-document') : '/sign-document') . '?token=' . $token;
        return ['success' => true, 'sign_url' => $url];
    }
}

if (!function_exists('pgRequestQuote')) {
    /**
     * @param int|string $termId a local term id, or a plan token "p{plan_price_id}" for a mapped service
     * @param array $extras {"a{addon}-{plan_price_id}": qty} — extras for that same plan + term only
     */
    function pgRequestQuote(PDO $pdo, array $tenant, int $cid, int $productId, int|string $termId, array $extras = []): array
    {
        $fail = fn(string $m) => ['success' => false, 'message' => $m];
        if (!sptReady($pdo)) return $fail('Subscriptions are not set up at BJP yet.');
        $st = $pdo->prepare("SELECT p.product_id, p.product_name, p.description, p.unit, COALESCE(tr.rate_percentage, 0) AS tax_rate
                               FROM vendor_subscription_services v
                               JOIN products p ON p.product_id = v.product_id AND p.status = 'active'
                               LEFT JOIN tax_rates tr ON tr.rate_id = p.tax_id
                              WHERE v.product_id = ?");
        $st->execute([$productId]);
        $svc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$svc) return $fail('That subscription is not available.');
        $term = null;
        $extraLines = [];   // [[name, qty, price, token], …]
        $platformKey = vssPlatformProduct($pdo, $productId);
        if ($platformKey !== null) {
            // Only a plan the company may pick (public, or its own) — the price comes from the control DB.
            if (!is_string($termId)) return $fail('That plan is not available.');
            $options = pgPlanOptions($platformKey, $tenant, true);
            foreach ($options as $o) {
                if ($o['kind'] === 'plan' && $o['term_id'] === $termId) $term = ['term_label' => $o['display_label'], 'price' => $o['price'], 'plan_price_id' => $o['plan_price_id']];
            }
            if (!$term) return $fail('That plan is not available.');
            foreach ($extras as $tok => $qty) {
                if (!is_numeric($qty) || (float)$qty != (int)$qty || (int)$qty < 0 || (int)$qty > 99) return $fail('Choose a whole number of extras (0 to 99).');
                if ((int)$qty === 0) continue;
                $hit = null;
                foreach ($options as $o) if ($o['kind'] === 'addon' && $o['term_id'] === (string)$tok && $o['plan_price_id'] === $term['plan_price_id']) $hit = $o;
                if (!$hit) return $fail('That extra is not sold with the plan and period you chose.');
                $extraLines[] = ['name' => $hit['addon_name'], 'qty' => (int)$qty, 'price' => (float)$hit['price'], 'token' => (string)$tok];
            }
        } else {
            if (is_string($termId)) return $fail('That period is not available.');
            if (array_filter($extras)) return $fail('Extras are sold only with a plan.');
            foreach (sptForProduct($pdo, $productId) as $t) if ((int)$t['term_id'] === $termId) $term = $t;
            if (!$term || sptMonths((int)$term['duration_value'], (string)$term['duration_unit']) === null) return $fail('That period is not available.');
        }

        $taxRate = (float)$svc['tax_rate'];
        // Every line priced on the server: [qty, unit price, resolved term, name, token]
        $lines = [];
        try {
            $lines[] = [1, round((float)$term['price'], 2), sptResolveLineTerm($pdo, $productId, $termId, (string)($svc['description'] ?? ''), (string)$svc['product_name']), (string)$svc['product_name'], (string)$termId];
            foreach ($extraLines as $x) {
                $lines[] = [$x['qty'], round($x['price'], 2), sptResolveLineTerm($pdo, $productId, $x['token'], '', $x['name']), $x['name'], $x['token']];
            }
            sptAssertPlanLines(array_map(fn($l) => ['term' => $l[2], 'qty' => $l[0], 'name' => $l[3]], $lines));
        } catch (InvalidArgumentException $e) {
            return $fail('That plan is not available.');
        }
        $price = 0.0; $tax = 0.0;
        foreach ($lines as [$qty, $unit]) { $net = round($qty * $unit, 2); $price += $net; $tax += round($net * $taxRate / 100, 2); }

        // A REQUEST, not a quotation: BJP staff open it from Quotations and save the quotation themselves.
        $stored = array_map(fn($l) => [
            'product_id' => $productId, 'name' => $l[3], 'qty' => $l[0], 'unit_price' => $l[1], 'tax_rate' => $taxRate,
            'unit' => $svc['unit'] ?: 'job', 'token' => $l[4], 'term_label' => $l[2]['price_term_label'], 'description' => $l[2]['description'],
        ], $lines);
        try {
            if (!srReady($pdo)) return $fail('Requests are not set up at BJP yet. Please try again later.');
            $req = srCreate($pdo, $tenant, $productId, (string)$termId, (string)$term['term_label'],
                            array_filter(array_map('intval', $extras)), $stored, $price, $tax);
        } catch (Throwable $e) {
            error_log('pgRequestQuote: ' . $e->getMessage());
            return $fail('Could not send the request. Please try again.');
        }

        // Tell BJP's sales staff (those who can create quotations); email only if BJP turned it on.
        try {
            require_once __DIR__ . '/notify.php';
            if (function_exists('dispatchEvent')) {
                dispatchEvent($pdo, 'subscription.request_received', [
                    'title' => 'Subscription request: ' . $tenant['company_name'],
                    'message' => $tenant['company_name'] . " ({$tenant['subdomain']}) chose {$term['term_label']}"
                        . ($extraLines ? ' + ' . implode(', ', array_map(fn($x) => "{$x['qty']} × {$x['name']}", $extraLines)) : '')
                        . " — request {$req['number']}, " . number_format($price + $tax) . ' TZS. Open Quotations → Requests from systems to prepare the quotation.',
                    'entity_type' => 'subscription_request', 'entity_id' => $req['id'], 'customer_id' => $cid > 0 ? $cid : null,
                    'action_url' => (function_exists('getUrl') ? getUrl('quotations') : '') . '?requests=1',
                    'dedupe_suffix' => (string)$req['id'],
                ]);
            }
        } catch (Throwable $e) {
            error_log('pgRequestQuote notify: ' . $e->getMessage());
        }

        return ['success' => true, 'request_number' => $req['number'], 'total' => $price + $tax, 'term_label' => $term['term_label'], 'status' => 'waiting',
                'message' => "Request sent for {$term['term_label']}. BJP will prepare your quotation — it appears under Subscription as soon as it is approved."];
    }
}
