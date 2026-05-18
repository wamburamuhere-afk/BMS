# BMS — Security Standards (mandatory on every page & API)

## §18. Constant Conventions (Codes, Currency, Country, Helpers)

**Entity code prefixes** (auto-generated, zero-padded to 5 digits):
| Entity | Prefix | Example |
|---|---|---|
| Customer | `CUST-` | `CUST-00001` |
| Supplier | `SUP-` | `SUP-00042` |
| Sub-contractor | `SUB-` | `SUB-00007` |
| Product | `PRD-` | `PRD-00128` |
| Invoice | `INV-` | `INV-2026-0001` |
| Purchase Order | `PO-` | `PO-2026-0001` |
| Delivery Note | `DN-` | `DN-2026-0001` |
| GRN | `GRN-` | `GRN-2026-0001` |
| Quotation | `QUO-` | `QUO-2026-0001` |

Generation pattern:
```php
$stmt = $pdo->query("SELECT MAX(customer_id) FROM customers");
$nextId = $stmt->fetchColumn() + 1;
$code = 'CUST-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);
```

**Defaults on every new record:** `country='Tanzania'`, `currency='TZS'`, `year=date('Y')`, `status='active'`, `created_by=$_SESSION['user_id']`, `created_at=NOW()`

**Shared helpers — use these, never reimplement:**
| Helper | Purpose |
|---|---|
| `getUrl($path)` | Root-relative URL for `href`, `src`, `action` |
| `buildUrl($path)` | Full URL for JS AJAX `url:` |
| `safe_output($val, $default='N/A')` | Escape value for HTML |
| `safeOutput(val)` (JS) | Same, for template literals |
| `getSetting($key, $default='')` | Read from `system_settings` table |
| `logActivity($pdo, $uid, $msg)` | Activity log (every write) |
| `logAudit($pdo, $uid, $action, $data)` | Compliance audit trail (sensitive ops) |
| `registerFileInLibrary(...)` | Track uploaded files in the central document library |
| `canView/canCreate/canEdit/canDelete($pageKey)` | Permission checks |
| `isAuthenticated()` | Session check (in APIs) |
| `isAdmin()` | True for `role_id = 1` |
| `getCurrentUserId()` | Returns `$_SESSION['user_id']` or null |

---

## §19. File Upload Security — CRITICAL

Extension-only checks are **NOT sufficient**. Every upload handler must do all five:

```php
// 1. Whitelist by extension
$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
$allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
if (!in_array($ext, $allowed_ext, true)) {
    throw new Exception('File type not allowed');
}

// 2. Whitelist by REAL MIME (magic bytes — never trust $_FILES['type'])
$finfo = new finfo(FILEINFO_MIME_TYPE);
$real_mime = $finfo->file($_FILES['file']['tmp_name']);
$allowed_mime = [
    'application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg', 'image/png', 'image/gif'
];
if (!in_array($real_mime, $allowed_mime, true)) {
    throw new Exception('File content does not match allowed types');
}

// 3. Size limit
$max_size = 10 * 1024 * 1024;  // 10MB
if ($_FILES['file']['size'] > $max_size) {
    throw new Exception('File exceeds size limit');
}

// 4. Sanitised, non-guessable filename
$safe_name = bin2hex(random_bytes(16)) . '.' . $ext;

// 5. Store under uploads/ with .htaccess protection
$target = __DIR__ . '/../uploads/<entity>/' . $safe_name;
if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
    throw new Exception('Upload failed');
}

registerFileInLibrary($pdo, 'uploads/<entity>/' . $safe_name, $_FILES['file']['name'],
    $_FILES['file']['size'], 'Description', 'tags,here', $_SESSION['user_id']);
logActivity($pdo, $_SESSION['user_id'], "Uploaded file: $safe_name");
```

**Required `.htaccess` inside every `uploads/` subfolder:**
```apache
<FilesMatch "\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$">
    Require all denied
</FilesMatch>
Options -ExecCGI
RemoveHandler .php .phtml .php5
RemoveType .php .phtml .php5
```

**For sensitive documents** (contracts, IDs, payslips) — serve via a PHP gatekeeper, never link directly:
```php
// api/download_document.php
if (!isAuthenticated() || !canView('documents')) exit('Unauthorized');
$id = intval($_GET['id'] ?? 0);
// ... fetch row, check user has access to THIS specific document ...
header('Content-Type: ' . $row['mime']);
header('Content-Disposition: attachment; filename="' . $row['original_name'] . '"');
readfile(ROOT_DIR . '/' . $row['file_path']);
```

---

## §20. Authentication & Session Security

**Login flow rules:**
```php
if (password_verify($password, $user['password_hash'])) {
    session_regenerate_id(true);  // prevent session fixation
    $pdo->prepare("UPDATE users SET failed_attempts = 0, last_login = NOW() WHERE user_id = ?")
        ->execute([$user['user_id']]);
    $_SESSION['user_id'] = $user['user_id'];
} else {
    $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1,
                   locked_until = IF(failed_attempts >= 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)
                   WHERE username = ?")->execute([$username]);
    logActivity($pdo, $user['user_id'] ?? 0, "Failed login for: $username");
}
```

**Required session cookie flags** (set in `roots.php` BEFORE `session_start()`):
```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);
ini_set('session.use_strict_mode', 1);
session_start();
```

**Password rules:**
- Minimum 8 characters, at least one letter and one digit
- Always hash with `password_hash($plain, PASSWORD_DEFAULT)`
- Always verify with `password_verify($plain, $hash)` — never `==` comparison
- Never log, echo, or email plaintext passwords

**Password reset:** token = `bin2hex(random_bytes(32))`, store hash only, expire after 30 minutes, single-use, always return the same generic message.

---

## §21. CSRF Protection — Required on All State-Changing Forms

**Helper** (add once to `helpers.php`):
```php
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check() {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}
```

**In every form:**
```html
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
```

**In every state-changing API:**
```php
if ($_SERVER['REQUEST_METHOD'] !== 'GET') csrf_check();
```

**In header.php** (exposes token to JS for AJAX without forms):
```php
const CSRF_TOKEN = '<?= csrf_token() ?>';
$.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });
```

---

## §22. Access Control Depth (RBAC)

**Standard role matrix:**
| Role | Typical access |
|---|---|
| **Super Admin** (role_id=1) | All permissions (bypasses checks via `isAdmin()`) |
| **Manager** | Full CRUD on operational modules; approve/review; no user/settings management |
| **Accountant** | Full CRUD on accounts, invoices, expenses; post/void; read-only on customers/suppliers |
| **Sales** | CRUD on quotations, sales orders, customers; read-only on stock |
| **Procurement** | CRUD on PO, GRN, suppliers; read-only on accounts |
| **Storekeeper** | Stock movements, GRN, DN; read-only on PO |
| **HR** | Employees, leaves, payroll; no access to accounts |
| **Auditor** | Read-only across the whole system; full access to audit logs |
| **Field Officer** | Operations module only (projects, progress reports, inspections) |

**Row-level access:** append `AND created_by = ?` when role scope is `'own'`.

**Two-factor authentication (2FA)** for Admin/Accountant/Auditor:
- TOTP via `pragmarx/google2fa` (acceptable Composer package)
- Store `totp_secret` + `totp_enabled` per user
- On login: if `totp_enabled`, redirect to second-step page after password verification
