<?php
/**
 * core/platform_gateway.php — the platform root's side of a company asking about
 * its own BMS subscription (customer_subscription_plan.md Phase 5). Runs only in
 * BJP's install; reached through api/platform/tenant_gateway.php.
 *
 *   catalog        → the subscription services and their terms (prices from BJP's books)
 *   documents      → this company's quotations and invoices, with customer-copy links
 *   request_quote  → an APPROVED quotation for the chosen term (catalog price, no human
 *                    approval needed — the price is fixed); one open request per company
 *
 * The company is identified only by the signed tenant id, and only the customer
 * linked to it (tenants.vendor_customer_id) is ever read.
 */
require_once __DIR__ . '/platform_link.php';
require_once __DIR__ . '/vendor_context.php';
require_once __DIR__ . '/vendor_link.php';
require_once __DIR__ . '/service_price_terms.php';
require_once __DIR__ . '/subscription_contracts.php';

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
            case 'catalog':   return [200, pgCatalog($pdo) + ['linked' => $cid > 0]];
            case 'documents': return [200, $cid > 0 ? pgDocuments($pdo, $cid) : ['success' => true, 'linked' => false, 'quotations' => [], 'invoices' => []]];
            case 'contracts':
                return [200, $cid > 0 ? pgContracts($pdo, $cid) : ['success' => true, 'linked' => false, 'contracts' => []]];
            case 'contract_sign_link':
                if ($cid <= 0) return [200, ['success' => false, 'message' => 'Your company is not linked to a customer account at BJP yet.']];
                return [200, pgContractSignLink($pdo, $tenant, $cid, (int)($req['contract_id'] ?? 0))];
            case 'request_quote':
                if ($cid <= 0) {
                    return [200, ['success' => false, 'linked' => false,
                        'message' => 'Your company is not yet linked to a customer account at BJP. Please contact BJP so they can set it up.']];
                }
                return [200, pgRequestQuote($pdo, $tenant, $cid, (int)($req['product_id'] ?? 0), (int)($req['term_id'] ?? 0))];
        }
        return [422, ['success' => false, 'message' => 'Unknown action.']];
    }
}

if (!function_exists('pgCatalog')) {
    function pgCatalog(PDO $pdo): array
    {
        $out = [];
        if (sptReady($pdo)) {
            $rows = $pdo->query("SELECT p.product_id, p.product_name, p.description FROM vendor_subscription_services v
                                   JOIN products p ON p.product_id = v.product_id AND p.status = 'active'
                                  ORDER BY p.product_name")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $terms = [];
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

if (!function_exists('pgDocuments')) {
    function pgDocuments(PDO $pdo, int $cid): array
    {
        $q = $pdo->prepare("SELECT sales_order_id AS id, order_number AS number, order_date AS date, grand_total AS total, status,
                                   converted_to_so_id, quote_valid_until
                              FROM quotations WHERE customer_id = ? AND status IN ('approved','cancelled')
                             ORDER BY sales_order_id DESC LIMIT 50");
        $q->execute([$cid]);
        $quotes = array_map(fn($r) => [
            'number' => $r['number'], 'date' => $r['date'], 'total' => (float)$r['total'],
            'status' => $r['status'] === 'approved' && $r['converted_to_so_id'] ? 'accepted' : $r['status'],
            'valid_until' => $r['quote_valid_until'], 'view_token' => plDocToken('quotation', (int)$r['id'], $cid),
        ], $q->fetchAll(PDO::FETCH_ASSOC));

        $i = $pdo->prepare("SELECT invoice_id AS id, invoice_number AS number, invoice_date AS date, due_date, grand_total AS total,
                                   paid_amount, balance_due, status
                              FROM invoices WHERE customer_id = ? AND status NOT IN ('draft','cancelled')
                             ORDER BY invoice_id DESC LIMIT 50");
        $i->execute([$cid]);
        $invoices = array_map(fn($r) => [
            'number' => $r['number'], 'date' => $r['date'], 'due_date' => $r['due_date'], 'total' => (float)$r['total'],
            'paid' => (float)$r['paid_amount'], 'balance' => (float)$r['balance_due'],
            'status' => (float)$r['balance_due'] <= 0.01 ? 'paid' : ((float)$r['paid_amount'] > 0.01 ? 'partial' : 'unpaid'),
            'view_token' => plDocToken('invoice', (int)$r['id'], $cid),
        ], $i->fetchAll(PDO::FETCH_ASSOC));
        return ['success' => true, 'linked' => true, 'quotations' => $quotes, 'invoices' => $invoices];
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
    function pgRequestQuote(PDO $pdo, array $tenant, int $cid, int $productId, int $termId): array
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
        foreach (sptForProduct($pdo, $productId) as $t) if ((int)$t['term_id'] === $termId) $term = $t;
        if (!$term || sptMonths((int)$term['duration_value'], (string)$term['duration_unit']) === null) return $fail('That period is not available.');

        require_once __DIR__ . '/code_generator.php';
        $ref = 'SELF-SERVICE T' . (int)$tenant['id'];
        $price = round((float)$term['price'], 2);
        $taxRate = (float)$svc['tax_rate'];
        $tax = round($price * $taxRate / 100, 2);
        $line = sptResolveLineTerm($pdo, $productId, $termId, (string)($svc['description'] ?? ''), (string)$svc['product_name']);

        try {
            $pdo->beginTransaction();
            // One open request per company: an earlier one not yet turned into an order is replaced.
            $old = $pdo->prepare("SELECT sales_order_id, order_number FROM quotations
                                   WHERE customer_id = ? AND reference = ? AND status = 'approved' AND converted_to_so_id IS NULL");
            $old->execute([$cid, $ref]);
            foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $pdo->prepare("UPDATE quotations SET status = 'cancelled', updated_at = NOW() WHERE sales_order_id = ?")->execute([$o['sales_order_id']]);
            }

            $number = nextCode($pdo, 'QT');
            $pdo->prepare("
                INSERT INTO quotations (order_number, customer_id, order_date, salesperson_id, currency, payment_terms, reference,
                                        subtotal, tax_amount, discount_amount, shipping_cost, grand_total, total_ordered,
                                        notes, terms_conditions, status, is_quote, quote_valid_until, approved_at, created_at)
                VALUES (?, ?, CURDATE(), 0, 'TZS', '', ?, ?, ?, 0, 0, ?, 1, ?, '', 'approved', 1, DATE_ADD(CURDATE(), INTERVAL 14 DAY), NOW(), NOW())
            ")->execute([$number, $cid, $ref, $price, $tax, $price + $tax,
                'Requested by ' . $tenant['company_name'] . ' (' . $tenant['subdomain'] . ') through My Subscription. Catalogue price — approved automatically.']);
            $qid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO quotation_items (order_id, product_id, product_name, sku, quantity, unit, unit_price, tax_rate,
                                                       discount_percent, line_total, description, created_at)
                           VALUES (?, ?, ?, '', 1, ?, ?, ?, 0, ?, ?, NOW())")
                ->execute([$qid, $productId, $svc['product_name'], $svc['unit'] ?: 'job', $price, $taxRate, $price + $tax, $line['description']]);
            sptStampLine($pdo, 'quotation_items', (int)$pdo->lastInsertId(), $line);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('pgRequestQuote: ' . $e->getMessage());
            return $fail('Could not create the quotation. Please try again.');
        }

        // Tell BJP's sales/billing staff (those who can create invoices).
        try {
            require_once __DIR__ . '/notify.php';
            if (function_exists('dispatchEvent')) {
                dispatchEvent($pdo, 'subscription.request_received', [
                    'title' => 'Subscription request: ' . $tenant['company_name'],
                    'message' => $tenant['company_name'] . " ({$tenant['subdomain']}) chose {$term['term_label']} of {$svc['product_name']} — quotation $number, " . number_format($price + $tax) . ' TZS. Invoice it when they are ready to pay.',
                    'entity_type' => 'quotation', 'entity_id' => $qid, 'customer_id' => $cid,
                    'action_url' => (function_exists('getUrl') ? getUrl('quotation_view') : '') . '?id=' . $qid,
                    'dedupe_suffix' => (string)$qid,
                ]);
            }
        } catch (Throwable $e) {
            error_log('pgRequestQuote notify: ' . $e->getMessage());
        }

        return ['success' => true, 'quotation_number' => $number, 'total' => $price + $tax, 'term_label' => $term['term_label'],
                'view_token' => plDocToken('quotation', $qid, $cid),
                'message' => "Quotation $number for {$term['term_label']} is ready. Pay BJP and your access is extended automatically."];
    }
}
