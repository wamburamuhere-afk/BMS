<?php
// scope-audit: skip — BJP's side of a company's Billing page: every read is pinned to the one customer linked to the signed tenant, never a staff scope
/**
 * core/customer_documents.php — what a company sees of its BJP documents on its own
 * Billing page (Subscription → Billing details). Runs on BJP's install (vendor context),
 * reached only through the signed gateway (core/platform_gateway.php).
 *
 *   - Invoice / quotation: BJP's real print template (the one chosen under Color Setting,
 *     "Default" unless changed), rendered here; images are embedded so the page shows
 *     whole on the company's own address (/subscription/invoice/231).
 *   - Payments: completed payments on its visible invoices, with their proof files.
 *   - EFD/VFD receipts: uploaded by BJP staff on the invoice, shown under Receipts.
 *   - Emails: the company hears when an invoice / quotation is approved or a receipt is added.
 *
 * Every document is checked against the customer AND BJP's workflow (approved or later)
 * before anything is returned.
 */
require_once __DIR__ . '/vendor_link.php';
require_once __DIR__ . '/vendor_context.php';

if (!function_exists('cdTemplates')) {
    /** Template key => [label, file]. 'default' is what a company sees unless BJP picks another. */
    function cdTemplates(string $type): array
    {
        $root = dirname(__DIR__);
        $all = [
            'invoice' => [
                'default' => ['Default', "$root/app/bms/invoice/invoice_print.php"],
                'summit'  => ['Summit',  "$root/app/bms/invoice/invoice_print_summit.php"],
                'wave'    => ['Wave',    "$root/app/bms/invoice/invoice_print_wave.php"],
                'onyx'    => ['Onyx',    "$root/app/bms/invoice/invoice_print_onyx.php"],
            ],
            'quotation' => [
                'default' => ['Default', "$root/app/bms/sales/quotations/print_quotation.php"],
                'noir'    => ['Noir',    "$root/app/bms/sales/quotations/print_quotation_noir.php"],
                'meadow'  => ['Meadow',  "$root/app/bms/sales/quotations/print_quotation_meadow.php"],
                'terra'   => ['Terra',   "$root/app/bms/sales/quotations/print_quotation_terra.php"],
            ],
        ];
        return $all[$type] ?? [];
    }

    function cdTemplateKey(string $type): string
    {
        $k = function_exists('get_setting') ? (string)get_setting('customer_copy_template_' . $type, 'default') : 'default';
        return isset(cdTemplates($type)[$k]) ? $k : 'default';
    }

    /** Statuses a company may see: BJP's workflow has finished with it (service_plans_plan.md D7). */
    function cdVisibleStatuses(string $type): array
    {
        return $type === 'invoice' ? ['approved', 'sent', 'partial', 'paid', 'overdue']
                                   : ['approved', 'processing', 'shipped', 'delivered', 'completed'];
    }

    /** The document row if it is this customer's and visible to them, else null. */
    function cdFindDocument(PDO $pdo, string $type, int $id, int $customerId): ?array
    {
        if ($id <= 0 || $customerId <= 0) return null;
        if ($type === 'invoice') {
            $st = $pdo->prepare("SELECT invoice_id AS id, invoice_number AS number, customer_id, status, approved_at FROM invoices WHERE invoice_id = ?");
        } elseif ($type === 'quotation') {
            $st = $pdo->prepare("SELECT sales_order_id AS id, order_number AS number, customer_id, status, approved_at FROM quotations WHERE sales_order_id = ?");
        } else {
            return null;
        }
        $st->execute([$id]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d || (int)$d['customer_id'] !== $customerId) return null;
        $visible = in_array((string)$d['status'], cdVisibleStatuses($type), true)
                || ((string)$d['status'] === 'cancelled' && !empty($d['approved_at']));
        return $visible ? $d : null;
    }

    /**
     * BJP's print template for one document, as a complete HTML page with its images
     * embedded. The caller has already checked the document with cdFindDocument().
     */
    function cdRenderDocument(PDO $pdo, string $type, int $id): string
    {
        $tpl = cdTemplates($type)[cdTemplateKey($type)][1] ?? null;
        if ($tpl === null || !is_file($tpl)) throw new RuntimeException('Template missing.');
        if (!defined('BMS_CUSTOMER_COPY')) define('BMS_CUSTOMER_COPY', true);   // templates skip the staff login/scope checks
        $savedGet = $_GET; $savedReq = $_REQUEST; $level = error_reporting();
        $_GET['id'] = $_REQUEST['id'] = (string)$id;
        ob_start();
        try {
            (static function (string $__file) { global $pdo; include $__file; })($tpl);
            $html = (string)ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        } finally {
            $_GET = $savedGet; $_REQUEST = $savedReq; error_reporting($level);
        }
        // A customer opens the document to read it: no print dialog on load (staff prints keep it);
        // the Print button still fits the page first.
        $html = preg_replace('/(<body\b[^>]*?)\s+onload\s*=\s*"[^"]*"/i', '$1', $html) ?? $html;
        $html = str_replace('onclick="window.print()"', 'onclick="(window.bmsAutoFitPrint || window.print)()"', $html);
        return cdInlineImages($html, dirname($tpl));
    }

    /**
     * Every <img src> pointing at a file of BJP's becomes a data: URI (only images under
     * uploads/ or assets/, at most 2 MB each); any other local address is dropped so the
     * page never reaches back to BJP's server.
     */
    function cdInlineImages(string $html, string $templateDir): string
    {
        $root = realpath(dirname(__DIR__));
        $allowedDirs = array_filter([realpath("$root/uploads"), realpath("$root/assets")]);
        $base = function_exists('getBasePath') ? rtrim((string)getBasePath(), '/') : '';
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return preg_replace_callback('/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2/i', function ($m) use ($root, $allowedDirs, $base, $finfo, $templateDir) {
            $src = html_entity_decode($m[3], ENT_QUOTES);
            if ($src === '' || str_starts_with($src, 'data:')) return $m[0];
            $path = strtok($src, '?#');
            if (preg_match('#^https?://#i', $path)) {
                $path = (string)parse_url($path, PHP_URL_PATH);
            }
            if (str_starts_with($path, '/')) {
                if ($base !== '' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base));
                $file = realpath($root . $path);
            } else {
                $file = realpath($templateDir . '/' . $path);
            }
            $ok = false;
            if ($file && is_file($file) && filesize($file) <= 2 * 1024 * 1024) {
                foreach ($allowedDirs as $d) if (str_starts_with($file, $d . DIRECTORY_SEPARATOR)) $ok = true;
            }
            $mime = $ok ? (string)$finfo->file($file) : '';
            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                return $m[1] . $m[2] . $m[2];
            }
            [$mime, $bytes] = cdImageForPage($file, $mime);
            return $m[1] . $m[2] . 'data:' . $mime . ';base64,' . base64_encode($bytes) . $m[2];
        }, $html) ?? $html;
    }

    /**
     * A large logo or signature is fitted inside 360×360 px (they print small) so the page
     * stays light; the same file used twice is worked out once. [mime, bytes]
     */
    function cdImageForPage(string $file, string $mime): array
    {
        static $done = [];
        if (isset($done[$file])) return $done[$file];
        $bytes = (string)file_get_contents($file);
        if (strlen($bytes) <= 150 * 1024 || !function_exists('imagecreatefromstring') || !in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return $done[$file] = [$mime, $bytes];
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) return $done[$file] = [$mime, $bytes];
        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1, 360 / max($w, $h));
        if ($scale < 1) {
            $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
            $small = imagecreatetruecolor($nw, $nh);
            if ($mime === 'image/png') { imagealphablending($small, false); imagesavealpha($small, true); }
            imagecopyresampled($small, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $small;
        }
        ob_start();
        $mime === 'image/png' ? imagepng($img, null, 9) : imagejpeg($img, null, 82);
        $out = (string)ob_get_clean();
        imagedestroy($img);
        return $done[$file] = ($out !== '' && strlen($out) < strlen($bytes) ? [$mime, $out] : [$mime, $bytes]);
    }

    /** Completed payments on this customer's visible invoices, each with its proof files (newest first). */
    function cdPayments(PDO $pdo, int $customerId): array
    {
        $vis = "'" . implode("','", cdVisibleStatuses('invoice')) . "'";
        $st = $pdo->prepare("SELECT p.payment_id, p.payment_number, p.payment_date, p.amount, p.payment_method, p.reference_number,
                                    i.invoice_id, i.invoice_number
                               FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id
                              WHERE i.customer_id = ? AND p.status = 'completed'
                                AND (i.status IN ($vis) OR (i.status = 'cancelled' AND i.approved_at IS NOT NULL))
                              ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 200");
        $st->execute([$customerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $files = [];
        if ($rows) {
            $in = implode(',', array_map('intval', array_column($rows, 'payment_id')));
            foreach ($pdo->query("SELECT attachment_id, payment_id, file_name FROM payment_attachments WHERE payment_id IN ($in) ORDER BY attachment_id") as $a) {
                $files[(int)$a['payment_id']][] = ['id' => (int)$a['attachment_id'], 'name' => (string)$a['file_name']];
            }
        }
        return array_map(fn($r) => [
            'number' => $r['payment_number'], 'date' => $r['payment_date'], 'amount' => (float)$r['amount'],
            'method' => $r['payment_method'], 'reference' => $r['reference_number'],
            'invoice_id' => (int)$r['invoice_id'], 'invoice_number' => $r['invoice_number'],
            'proofs' => $files[(int)$r['payment_id']] ?? [],
        ], $rows);
    }

    // ── EFD / VFD receipts ──────────────────────────────────────────────────

    function cdEfdEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_efd_receipts (
            receipt_id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            receipt_number VARCHAR(100) NULL,
            receipt_date DATE NULL,
            file_name VARCHAR(200) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(50) NOT NULL,
            file_size INT NOT NULL DEFAULT 0,
            status ENUM('active','deleted') NOT NULL DEFAULT 'active',
            uploaded_by INT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_by INT NULL,
            deleted_at DATETIME NULL,
            KEY idx_efd_invoice (invoice_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    function cdEfdReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready === null) {
            try { $ready = (bool)$pdo->query("SHOW TABLES LIKE 'invoice_efd_receipts'")->fetchColumn(); } catch (Throwable $e) { $ready = false; }
        }
        return $ready;
    }

    /** Active receipts of one invoice (staff view). */
    function cdEfdForInvoice(PDO $pdo, int $invoiceId): array
    {
        if (!cdEfdReady($pdo)) return [];
        $st = $pdo->prepare("SELECT r.*, CONCAT_WS(' ', u.first_name, u.last_name) AS uploaded_by_name FROM invoice_efd_receipts r
                               LEFT JOIN users u ON u.user_id = r.uploaded_by
                              WHERE r.invoice_id = ? AND r.status = 'active' ORDER BY r.uploaded_at DESC");
        $st->execute([$invoiceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Active receipts on this customer's visible invoices (the company's Receipts tab). */
    function cdEfdForCustomer(PDO $pdo, int $customerId): array
    {
        if (!cdEfdReady($pdo)) return [];
        $vis = "'" . implode("','", cdVisibleStatuses('invoice')) . "'";
        $st = $pdo->prepare("SELECT r.receipt_id, r.receipt_number, r.receipt_date, r.file_name, r.uploaded_at, i.invoice_id, i.invoice_number, i.grand_total
                               FROM invoice_efd_receipts r JOIN invoices i ON i.invoice_id = r.invoice_id
                              WHERE i.customer_id = ? AND r.status = 'active'
                                AND (i.status IN ($vis) OR (i.status = 'cancelled' AND i.approved_at IS NOT NULL))
                              ORDER BY r.uploaded_at DESC LIMIT 200");
        $st->execute([$customerId]);
        return array_map(fn($r) => [
            'id' => (int)$r['receipt_id'], 'number' => $r['receipt_number'], 'date' => $r['receipt_date'], 'name' => $r['file_name'],
            'uploaded_at' => $r['uploaded_at'], 'invoice_id' => (int)$r['invoice_id'], 'invoice_number' => $r['invoice_number'],
            'invoice_total' => (float)$r['grand_total'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Files (proof of payment, EFD receipt, contract) ─────────────────────

    /**
     * One file of this customer's, or null. Returns ['path', 'name', 'mime'] with a real
     * path inside uploads/ and a PDF/JPG/PNG type checked by content.
     */
    function cdCustomerFile(PDO $pdo, string $kind, int $id, int $customerId): ?array
    {
        if ($id <= 0 || $customerId <= 0) return null;
        $vis = "'" . implode("','", cdVisibleStatuses('invoice')) . "'";
        $visible = "(i.status IN ($vis) OR (i.status = 'cancelled' AND i.approved_at IS NOT NULL))";
        $row = null;
        if ($kind === 'payment-proof') {
            $st = $pdo->prepare("SELECT a.file_path, a.file_name FROM payment_attachments a
                                   JOIN payments p ON p.payment_id = a.payment_id AND p.status = 'completed'
                                   JOIN invoices i ON i.invoice_id = p.invoice_id
                                  WHERE a.attachment_id = ? AND i.customer_id = ? AND $visible");
            $st->execute([$id, $customerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif ($kind === 'receipt') {
            if (!cdEfdReady($pdo)) return null;
            $st = $pdo->prepare("SELECT r.file_path, r.file_name FROM invoice_efd_receipts r JOIN invoices i ON i.invoice_id = r.invoice_id
                                  WHERE r.receipt_id = ? AND r.status = 'active' AND i.customer_id = ? AND $visible");
            $st->execute([$id, $customerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif ($kind === 'contract') {
            require_once __DIR__ . '/subscription_contracts.php';
            $st = $pdo->prepare("SELECT * FROM customer_contracts WHERE contract_id = ? AND customer_id = ?");
            $st->execute([$id, $customerId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if ($c) {
                $s = scState($pdo, $c);
                $docId = $s['state'] === 'signed' ? ($s['final_document_id'] ?? null) : ($s['state'] === 'awaiting_customer' ? ($s['bjp_signed_document_id'] ?? null) : null);
                if ($docId) {
                    $d = $pdo->prepare("SELECT file_path, CONCAT(document_code, '.pdf') AS file_name FROM documents WHERE id = ?");
                    $d->execute([$docId]);
                    $row = $d->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
        }
        if (!$row) return null;
        $root = realpath(dirname(__DIR__));
        $uploads = realpath("$root/uploads");
        $full = realpath($root . '/' . ltrim((string)$row['file_path'], '/'));
        if (!$full || !$uploads || !str_starts_with($full, $uploads . DIRECTORY_SEPARATOR) || !is_file($full)) return null;
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($full);
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) return null;
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime];
        $name = preg_replace('/[^A-Za-z0-9 _.-]/', '_', pathinfo((string)$row['file_name'], PATHINFO_FILENAME)) ?: 'document';
        return ['path' => $full, 'name' => mb_substr($name, 0, 120) . '.' . $ext, 'mime' => $mime];
    }

    // ── Emails to the company ───────────────────────────────────────────────

    /**
     * Tell the company that a document is waiting on its Billing page. Only for a customer
     * linked to exactly one company (no guessing), sent to the customer's email or the
     * company owner's. Best effort: never stops the staff action that called it.
     * $event = invoice | quotation | receipt.
     */
    function cdNotifyCustomer(PDO $pdo, int $customerId, string $event, string $number): bool
    {
        try {
            $tenants = vlTenantsOfCustomer($customerId);
            if (count($tenants) !== 1) return false;
            $t = $tenants[0];
            $st = $pdo->prepare("SELECT customer_name, company_name, email, company_email FROM customers WHERE customer_id = ?");
            $st->execute([$customerId]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $to = '';
            foreach ([$c['email'] ?? '', $c['company_email'] ?? ''] as $e) if ($to === '' && filter_var(trim((string)$e), FILTER_VALIDATE_EMAIL)) $to = trim((string)$e);
            if ($to === '') {
                $o = getControlPdo()->prepare("SELECT " . (controlColumnExists('tenants', 'owner_contact_email') ? 'owner_contact_email' : "''") . " AS e1, owner_email AS e2 FROM tenants WHERE id = ?");
                $o->execute([(int)$t['id']]);
                $own = $o->fetch(PDO::FETCH_ASSOC) ?: [];
                foreach ([$own['e1'] ?? '', $own['e2'] ?? ''] as $e) if ($to === '' && filter_var(trim((string)$e), FILTER_VALIDATE_EMAIL)) $to = trim((string)$e);
            }
            if ($to === '') return false;

            $tab = ['invoice' => 'invoices', 'quotation' => 'quotations', 'receipt' => 'receipts'][$event] ?? 'invoices';
            $base = function_exists('tenantBaseDomain') ? (string)tenantBaseDomain() : '';
            $link = $base !== '' ? 'https://' . $t['subdomain'] . '.' . $base . '/subscription#billing-' . $tab : null;
            $what = ['invoice' => 'a new invoice', 'quotation' => 'a new quotation', 'receipt' => 'an EFD receipt'][$event] ?? 'a new document';
            $subject = ['invoice' => "Invoice $number from BJP", 'quotation' => "Quotation $number from BJP", 'receipt' => "EFD receipt for invoice $number"][$event] ?? "Document $number from BJP";
            $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $name = trim((string)(($c['company_name'] ?? '') ?: ($c['customer_name'] ?? '') ?: $t['company_name']));
            $html = '<p>Hello ' . $e($name) . ',</p>'
                  . '<p>BJP Technologies has added ' . $e($what) . ' (<b>' . $e($number) . '</b>) to your account.</p>'
                  . '<p>Open it any time under <b>Subscription → Billing details</b> in your system' . ($link ? ':' : '.') . '</p>'
                  . ($link ? '<p><a href="' . $e($link) . '" style="background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Open Billing details</a></p>' : '')
                  . '<p style="color:#64748b;font-size:12px">You do not need to keep this email — the document stays on your Billing page.</p>';

            if (defined('CUSTOMER_DOC_MAIL_CAPTURE_FILE')) {
                return (bool)file_put_contents(CUSTOMER_DOC_MAIL_CAPTURE_FILE, json_encode(['to' => $to, 'subject' => $subject, 'html' => $html, 'event' => $event]) . "\n", FILE_APPEND);
            }
            require_once __DIR__ . '/mailer.php';
            return sendEmail($to, $subject, $html);
        } catch (Throwable $e) {
            error_log('cdNotifyCustomer: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * After an approval or a receipt upload on BJP's install: email the company if the
     * customer has one. $event = invoice | quotation (id of that document) | receipt (invoice id).
     */
    function cdNotifyIfLinked(PDO $pdo, string $event, int $id): void
    {
        if (!isPlatformVendorContext() || !vlLinkReady()) return;
        $sql = $event === 'quotation' ? "SELECT customer_id, order_number AS number FROM quotations WHERE sales_order_id = ?"
                                      : "SELECT customer_id, invoice_number AS number FROM invoices WHERE invoice_id = ?";
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if ($d && (int)$d['customer_id'] > 0) cdNotifyCustomer($pdo, (int)$d['customer_id'], $event, (string)$d['number']);
    }
}
