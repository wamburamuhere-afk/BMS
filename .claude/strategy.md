# BMS — Strategy: What NOT to Add & Roadmap

## §23. Hard "Do Not Add" List

The whole point of the current stack is fast iteration without a build step. The following sound modern but would slow the project down or trigger a rewrite — avoid unless explicitly requested.

| Do NOT add | Why |
|---|---|
| **Frameworks** (Laravel, Symfony, CodeIgniter, Slim) | Would require rewriting every page. `roots.php` + `header.php` is the framework. |
| **ORMs** (Eloquent, Doctrine, RedBean) | PDO prepared statements are already standardised. An ORM creates two ways to do the same thing. |
| **SPA frontends** (React, Vue, Angular, Svelte) | Requires an API layer, a build step, and a totally different mental model. |
| **Build tools** (webpack, vite, parcel, gulp) | Direct `<script src>` / `<link href>` works. A build step breaks the "edit → refresh" workflow. |
| **TypeScript** | Vanilla JS + jQuery is the project standard. |
| **CSS preprocessors** (Sass, Less) | Plain CSS + Bootstrap utility classes cover every need. |
| **Microservices** | A single PHP app on shared hosting is the correct architecture for this scale. |
| **GraphQL** | REST endpoints in `api/` are simpler and already in use. |
| **NoSQL** (MongoDB, DynamoDB) | MySQL serves the relational ERP model well. |
| **Composer dependency sprawl** | Only add a package when there is no reasonable PHP-native alternative (e.g. PHPMailer, google2fa, TCPDF). |
| **Docker / Kubernetes for local dev** | WAMP works. Containerising adds overhead with no productivity gain. |
| **PHPUnit for UI pages** | Manual smoke testing per §1 is the project's chosen QA. Use PHPUnit only for shared libraries. |
| **Real-time WebSockets** (Ratchet, Socket.IO) | Use polling (`setInterval` every 30s) or Server-Sent Events when needed. |
| **JWT for browser sessions** | Sessions work. JWT is for stateless APIs — add only for the mobile-app REST API. |
| **Queue workers** (Redis, RabbitMQ) | Use MySQL-backed `job_queue` table + cron-driven PHP runner if background jobs are needed. |
| **Cloud-only services** (S3, Lambda) before justified | System runs on shared VPS — keep storage local until scale demands otherwise. |
| **Multiple CSS frameworks** | Bootstrap 5 only. Do not add Tailwind, Bulma, Materialize. |
| **Multiple icon libraries** | Bootstrap Icons only. Do not add Font Awesome to new pages. |
| **Multiple chart libraries** | Chart.js is the project standard. Do not add ApexCharts, Highcharts, Plotly. |

---

## §24. High-Impact Features to Build (in order)

### Phase 1 — Security hardening (do first)
1. Add `.htaccess` to every `uploads/` subfolder (§19)
2. Add `finfo` MIME-byte validation to every upload handler (§19)
3. Add CSRF tokens to every form (§21)
4. Add `session_regenerate_id()` + cookie flags to login (§20)
5. Add failed-login tracking + 15-minute lockout after 5 failures (§20)
6. Add admin-only `audit_logs.php` dashboard

### Phase 2 — Tanzania-specific revenue features
1. **TRA EFD integration** — fiscal receipts via TRA VFD API (legally required for VAT businesses)
2. **M-Pesa / Tigo Pesa / Airtel Money** — Selcom or DPO aggregator webhook + reconciliation
3. **WhatsApp Business API** — invoices, reminders, delivery confirmations via Meta Cloud API
4. **Swahili language toggle** — `lang/en.php` + `lang/sw.php`, `__('key')` helper
5. **Bulk SMS** — Africa's Talking or Beem Africa for reminders and OTPs

### Phase 3 — Productivity multipliers
1. **Barcode / QR scanning** — `html5-qrcode` JS library in stock module
2. **Two-Factor Authentication** — TOTP via `pragmarx/google2fa`
3. **PWA (offline support)** — `manifest.json` + service worker for field officers
4. **Public REST API** — token-based auth (`api/v1/`) for mobile app
5. **Webhook outbound** — emit `invoice.created`, `payment.received`, `stock.low` events

### Phase 4 — Reporting & intelligence
1. **Dashboard widgets** — configurable per-role (revenue MTD, overdue invoices, stock alerts)
2. **OCR receipt scanning** — Tesseract.js or Google Vision for expense entry
3. **AI assist** — auto-categorise expenses, draft follow-up emails, summarise audit logs
4. **Predictive stock reorder** — moving-average + lead-time
5. **"Export all my data"** — one-click ZIP for GDPR-style compliance

### Phase 5 — Polish
1. **Dark mode** — CSS variables + `data-bs-theme` (Bootstrap 5.3 native)
2. **E-signature** — canvas pad, embed PNG into PDF
3. **Activity timeline per entity** — chronological feed of invoices, payments, messages, files
4. **Saved filter views** — per user, on every list page
5. **Keyboard shortcuts** — `/` to focus search, `n` for new, `Esc` to close modal

---

## §25. Operational Gaps to Close (Currently Missing)

Treat each as a backlog item — never ship a feature that makes one harder to add later.

**1. Content-Security-Policy headers — not set**
Add to `.htaccess` at project root:
```apache
Header set Content-Security-Policy "default-src 'self'; \
    script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com https://cdn.datatables.net; \
    style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net; \
    img-src 'self' data: https:; \
    font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; \
    frame-ancestors 'none'"
Header set X-Frame-Options "DENY"
Header set X-Content-Type-Options "nosniff"
Header set Referrer-Policy "strict-origin-when-cross-origin"
Header set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

**2. Rate limiting — none on login or APIs**
MySQL-backed limiter (no Redis needed):
```php
function rateLimitCheck($key, $max, $windowSeconds) {
    global $pdo;
    $pdo->prepare("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)")
        ->execute([$windowSeconds]);
    $count = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE rate_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $count->execute([$key, $windowSeconds]);
    if ($count->fetchColumn() >= $max) return false;
    $pdo->prepare("INSERT INTO rate_limits (rate_key, ip, created_at) VALUES (?, ?, NOW())")
        ->execute([$key, $_SERVER['REMOTE_ADDR'] ?? '']);
    return true;
}
```
Limits: login 5/IP/15min, password reset 3/email/hour, API write 60/user/min.

**3. Database backup automation — only manual today**
Add a cron job on the production server:
```bash
0 2 * * * www-data /usr/bin/mysqldump -u bms_backup --single-transaction --quick bms | gzip > /var/backups/bms/bms_$(date +\%F).sql.gz
```
Retention: 7 daily + 4 weekly + 12 monthly. Monthly restore test — a backup you have not restored is not a backup.

**4. Error monitoring — no central capture**
- **Free option**: `set_exception_handler()` + `set_error_handler()` writing to an `error_log` table; admin-only dashboard.
- **External option**: Sentry free tier — `Sentry\init(['dsn'=>…])` in `roots.php`.

**5. Staging environment & rollback strategy — neither exists**
- `develop` branch → staging server auto-deploys on push to `develop`
- Promote `develop` → `main` via PR after staging is verified
- Keep migrations reversible — add a `--down` comment block to every migration

**6. Health-check endpoint — none**
```php
<?php
header('Content-Type: application/json');
try {
    require_once __DIR__ . '/roots.php';
    $pdo->query("SELECT 1");
    echo json_encode(['status' => 'ok', 'time' => date('c')]);
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['status' => 'down', 'error' => $e->getMessage()]);
}
```
Register at UptimeRobot / Better Stack for phone/email alerts on outage.

**7. Log rotation — not configured**
Configure `logrotate` on the server. Also prune DB-stored logs: `activity_logs` > 1 year, `audit_logs` > 7 years (compliance), `rate_limits` > 1 day.
