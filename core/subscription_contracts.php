<?php
/**
 * core/subscription_contracts.php — the subscription agreement, after payment
 * (customer_subscription_plan.md Phase 6). BJP's install only.
 *
 * When a subscription invoice is paid and applied (core/vendor_bridge.php), a
 * contract PDF is generated from the subscription service's chosen Document
 * Template (BJP writes the wording; {{tokens}} fill the customer, period, amount…),
 * on the company letterhead, as an ordinary document. Then the EXISTING signing
 * flows take over — nothing here signs anything by itself:
 *   1. BJP signs it with E-Signatures (internal signature → a signed copy),
 *   2. the customer signs that copy from My Subscription (external signature,
 *      one-time link created when they click Sign) → the final copy.
 * The contract's status is read from those signature rows, never stored twice.
 */
require_once __DIR__ . '/service_price_terms.php';

if (!function_exists('scEnsureSchema')) {
    function scEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `customer_contracts` (
                `contract_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `customer_id`   INT NOT NULL,
                `tenant_id`     INT NULL,
                `invoice_id`    INT NOT NULL,
                `invoice_ref`   VARCHAR(64) NOT NULL,
                `product_id`    INT NULL,
                `document_id`   INT NOT NULL,
                `period_label`  VARCHAR(100) NULL,
                `period_start`  DATE NULL,
                `period_end`    DATE NULL,
                `status`        ENUM('active','cancelled') NOT NULL DEFAULT 'active',
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`contract_id`),
                UNIQUE KEY `ux_cc_invoice` (`invoice_id`),
                KEY `idx_cc_customer` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        if ($pdo->query("SHOW TABLES LIKE 'vendor_subscription_services'")->fetch()
            && !$pdo->query("SHOW COLUMNS FROM `vendor_subscription_services` LIKE 'contract_template_id'")->fetch()) {
            $pdo->exec("ALTER TABLE `vendor_subscription_services` ADD COLUMN `contract_template_id` INT NULL DEFAULT NULL");
        }
    }
}

if (!function_exists('scReady')) {
    function scReady(PDO $pdo): bool
    {
        static $ok = false;
        if (!$ok) {
            try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'customer_contracts'")->fetch(); } catch (Throwable $e) { return false; }
        }
        return $ok;
    }
}

if (!function_exists('scMergeTokens')) {
    /** Extra {{tokens}} a contract template can use, on top of the Documents ones. */
    function scMergeTokens(): array
    {
        return [
            'customer_name' => 'Customer Name', 'customer_tin' => 'Customer TIN', 'customer_address' => 'Customer Address',
            'service_name' => 'Service', 'period' => 'Period (e.g. 6 Months)', 'period_start' => 'Period Start',
            'period_end' => 'Period End', 'amount' => 'Amount Paid', 'invoice_number' => 'Invoice Number',
            'web_address' => 'Company Web Address',
        ];
    }
}

if (!function_exists('scCreateForInvoice')) {
    /**
     * Generate the contract for a paid subscription invoice. Idempotent (one per
     * invoice). Does nothing when the service has no contract template. Never throws.
     *
     * @return array{created:bool, reason:string, contract_id?:int, document_id?:int}
     */
    function scCreateForInvoice(PDO $pdo, int $invoiceId, ?array $tenant, ?string $periodStart, ?string $periodEnd): array
    {
        $no = fn(string $r) => ['created' => false, 'reason' => $r];
        try {
            if (!scReady($pdo)) return $no('not_set_up');
            $have = $pdo->prepare("SELECT contract_id FROM customer_contracts WHERE invoice_id = ?");
            $have->execute([$invoiceId]);
            if ($have->fetchColumn()) return $no('exists');

            $st = $pdo->prepare("SELECT i.invoice_id, i.invoice_number, i.customer_id, i.created_by,
                                        ii.product_id, ii.product_name, ii.price_term_label, ii.line_total,
                                        v.contract_template_id, c.customer_name, c.company_name, c.tin_number, c.address, c.email
                                   FROM invoices i
                                   JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
                                   JOIN vendor_subscription_services v ON v.product_id = ii.product_id
                                   JOIN customers c ON c.customer_id = i.customer_id
                                  WHERE i.invoice_id = ? AND v.contract_template_id IS NOT NULL
                                  ORDER BY ii.invoice_item_id LIMIT 1");
            $st->execute([$invoiceId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return $no('no_template');

            $tp = $pdo->prepare("SELECT template_name, subject, content, use_letterhead, signature_align FROM document_templates
                                  WHERE id = ? AND is_active = 1 AND content IS NOT NULL");
            $tp->execute([(int)$row['contract_template_id']]);
            $tpl = $tp->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) return $no('template_missing');

            require_once __DIR__ . '/code_generator.php';
            require_once __DIR__ . '/document_merge.php';
            require_once __DIR__ . '/document_letter_pdf.php';

            $custName = $row['company_name'] ?: $row['customer_name'];
            $fmt = fn(?string $d) => $d ? date('d M Y', strtotime($d)) : '';
            $code = nextCode($pdo, 'LTR');
            $subject = trim((string)($tpl['subject'] ?? '')) ?: ('Subscription agreement — ' . $row['product_name']);
            $ctx = [
                'document_code' => $code, 'subject' => $subject, 'recipient' => $custName,
                'recipient_address' => (string)($row['address'] ?? ''), 'date' => date('d M Y'),
                'customer_name' => $custName, 'customer_tin' => (string)($row['tin_number'] ?? ''), 'customer_address' => (string)($row['address'] ?? ''),
                'service_name' => $row['product_name'], 'period' => (string)($row['price_term_label'] ?? ''),
                'period_start' => $fmt($periodStart), 'period_end' => $fmt($periodEnd),
                'amount' => number_format((float)$row['line_total'], 2) . ' TZS', 'invoice_number' => $row['invoice_number'],
                'web_address' => $tenant['subdomain'] ?? '',
            ];
            $content = resolveDocumentVariables((string)$tpl['content'], $ctx);

            $dir = __DIR__ . '/../uploads/documents/';
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) return $no('upload_dir');
            $safe = bin2hex(random_bytes(16)) . '.pdf';
            $size = generateLetterPdf($pdo, [
                'document_code' => $code, 'letter_date' => date('Y-m-d'), 'use_letterhead' => $tpl['use_letterhead'] ?? 1,
                'recipient' => $custName, 'recipient_address' => (string)($row['address'] ?? ''), 'subject' => $subject,
                'content' => $content, 'signature_align' => $tpl['signature_align'] ?: 'left',
            ], $dir . $safe);

            $uploader = (int)($row['created_by'] ?: 1);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO documents (document_name, description, content, file_path, original_filename, file_size, file_type,
                                                  document_code, version, issue_date, access_level, uploaded_by, source, use_letterhead,
                                                  recipient_address, signature_align)
                           VALUES (?, ?, ?, ?, ?, ?, 'pdf', ?, '1.0', CURDATE(), 'private', ?, 'subscription_contract', ?, ?, ?)")
                ->execute([$subject . ' — ' . $custName, 'Subscription contract for invoice ' . $row['invoice_number'], $content,
                           'uploads/documents/' . $safe, $code . '.pdf', $size, $code, $uploader, $tpl['use_letterhead'] ?? 1,
                           $row['address'] ?? null, $tpl['signature_align'] ?: 'left']);
            $docId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO customer_contracts (customer_id, tenant_id, invoice_id, invoice_ref, product_id, document_id,
                                                           period_label, period_start, period_end)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([(int)$row['customer_id'], $tenant['id'] ?? null, $invoiceId, $row['invoice_number'], (int)$row['product_id'],
                           $docId, $row['price_term_label'], $periodStart, $periodEnd]);
            $cid = (int)$pdo->lastInsertId();
            $pdo->commit();

            // Ask BJP's signers (users who can create documents) to sign it.
            try {
                require_once __DIR__ . '/notify.php';
                if (function_exists('dispatchEvent')) {
                    dispatchEvent($pdo, 'subscription.contract_ready', [
                        'title' => 'Contract to sign: ' . $custName,
                        'message' => "Invoice {$row['invoice_number']} is paid. Sign the subscription contract ($code) so the customer can sign it too.",
                        'entity_type' => 'document', 'entity_id' => $docId, 'customer_id' => (int)$row['customer_id'], 'document_id' => $docId,
                        'action_url' => (function_exists('getUrl') ? getUrl('select_document_add_esignature') : '') . '?document_id=' . $docId,
                        'dedupe_suffix' => (string)$cid,
                    ]);
                }
            } catch (Throwable $e) {
                error_log('scCreateForInvoice notify: ' . $e->getMessage());
            }
            return ['created' => true, 'reason' => 'created', 'contract_id' => $cid, 'document_id' => $docId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("scCreateForInvoice($invoiceId): " . $e->getMessage());
            return $no('error');
        }
    }
}

if (!function_exists('scState')) {
    /**
     * Where a contract stands, from the signature rows.
     * @return array{state:string, bjp_signed_document_id:?int, final_document_id:?int}
     *   state: awaiting_bjp | awaiting_customer | signed | cancelled
     */
    function scState(PDO $pdo, array $contract): array
    {
        $out = ['state' => 'awaiting_bjp', 'bjp_signed_document_id' => null, 'final_document_id' => null];
        if (($contract['status'] ?? 'active') === 'cancelled') { $out['state'] = 'cancelled'; return $out; }
        $st = $pdo->prepare("SELECT signed_document_id FROM document_signatures
                              WHERE document_id = ? AND signer_type = 'internal' AND status = 'signed' AND signed_document_id IS NOT NULL
                              ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$contract['document_id']]);
        $bjp = $st->fetchColumn();
        if (!$bjp) return $out;
        $out['bjp_signed_document_id'] = (int)$bjp;
        $out['state'] = 'awaiting_customer';
        $st = $pdo->prepare("SELECT signed_document_id FROM document_signatures
                              WHERE document_id = ? AND signer_type = 'external' AND status = 'signed' AND signed_document_id IS NOT NULL
                              ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$bjp]);
        $fin = $st->fetchColumn();
        if ($fin) { $out['final_document_id'] = (int)$fin; $out['state'] = 'signed'; }
        return $out;
    }
}

if (!function_exists('scForCustomer')) {
    /** A customer's contracts with their state, newest first. */
    function scForCustomer(PDO $pdo, int $customerId): array
    {
        if (!scReady($pdo)) return [];
        $st = $pdo->prepare("SELECT cc.*, d.document_code, p.product_name FROM customer_contracts cc
                               JOIN documents d ON d.id = cc.document_id
                               LEFT JOIN products p ON p.product_id = cc.product_id
                              WHERE cc.customer_id = ? ORDER BY cc.contract_id DESC");
        $st->execute([$customerId]);
        return array_map(fn($c) => $c + scState($pdo, $c), $st->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('scCustomerSignLink')) {
    /**
     * A fresh one-time signing link for the customer (BJP must have signed first).
     * Re-uses the contract's pending external signature request, or opens one;
     * any older unused link for it stops working. Returns the raw token or null.
     */
    function scCustomerSignLink(PDO $pdo, array $contract, string $signerName, string $signerEmail): ?string
    {
        $s = scState($pdo, $contract);
        if ($s['state'] !== 'awaiting_customer') return null;
        $doc = (int)$s['bjp_signed_document_id'];
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id FROM document_signatures WHERE document_id = ? AND signer_type = 'external' AND status = 'pending' LIMIT 1");
            $st->execute([$doc]);
            $sigId = (int)$st->fetchColumn();
            if (!$sigId) {
                $by = (int)$pdo->query("SELECT uploaded_by FROM documents WHERE id = " . (int)$contract['document_id'])->fetchColumn();
                $pdo->prepare("INSERT INTO document_signatures (document_id, requested_by, signed_by, signer_name, signer_email, signer_type, status)
                               VALUES (?, ?, NULL, ?, ?, 'external', 'pending')")
                    ->execute([$doc, $by ?: 1, mb_substr($signerName, 0, 150), mb_substr($signerEmail, 0, 255)]);
                $sigId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("UPDATE document_signature_tokens SET expires_at = NOW() WHERE signature_id = ? AND used_at IS NULL AND expires_at > NOW()")
                ->execute([$sigId]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO document_signature_tokens (signature_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 DAY))")
                ->execute([$sigId, hash('sha256', $token)]);
            $pdo->commit();
            return $token;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('scCustomerSignLink: ' . $e->getMessage());
            return null;
        }
    }
}
