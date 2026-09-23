# BMS Simple POS — Flutter Native App Plan

**Version: V2** · Last updated: 2026-09-23

> Implementation reference. Every section here is derived from reading the actual
> BMS source. Follow this document top-to-bottom when building. Do not skip steps.

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                   Flutter App (Dart)                    │
│  ┌──────────┐  ┌──────────┐  ┌───────────────────────┐ │
│  │  Screens │  │Providers │  │   Local SQLite DB      │ │
│  │ (UI/UX)  │→ │(Riverpod)│→ │  offline_sales         │ │
│  └──────────┘  └──────────┘  │  cached_products       │ │
│                    ↓          │  pending_sync          │ │
│              ┌──────────┐    └───────────────────────┘ │
│              │ApiClient │←── Bearer token (SecureStore) │
│              └────┬─────┘                               │
└───────────────────┼─────────────────────────────────────┘
                    │  HTTPS
         ┌──────────▼──────────┐
         │   BMS PHP Backend   │
         │  (existing APIs +   │
         │   3 new auth files) │
         └─────────────────────┘
```

**State management:** Riverpod (flutter_riverpod)  
**HTTP client:** Dio with Bearer interceptor  
**Offline storage:** sqflite (SQLite)  
**Secure token storage:** flutter_secure_storage  
**Navigation:** go_router  

---

## 2. Backend Changes Required (BMS PHP)

### 2.1 New database table — `mobile_tokens`

Create migration file: `migrations/tenant/2026_09_21_mobile_tokens.php`

```sql
CREATE TABLE IF NOT EXISTS mobile_tokens (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    token        VARCHAR(64) NOT NULL UNIQUE,
    device_name  VARCHAR(100),
    last_used    DATETIME,
    created_at   DATETIME DEFAULT NOW(),
    expires_at   DATETIME NOT NULL,
    INDEX idx_token  (token),
    INDEX idx_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2.2 New file: `core/mobile_auth.php`

Reads `Authorization: Bearer <token>` header, looks up the token in
`mobile_tokens`, refreshes `last_used`, and populates `$_SESSION` so every
existing API works without modification.

```php
<?php
function mobileAuthFromBearer(): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? getallheaders()['Authorization']
           ?? '';
    if (!str_starts_with($header, 'Bearer ')) return false;
    $token = trim(substr($header, 7));
    if (strlen($token) !== 64) return false;

    global $pdo;
    $st = $pdo->prepare("
        SELECT mt.user_id, u.username, u.first_name, u.last_name, u.role,
               u.language, u.status
          FROM mobile_tokens mt
          JOIN users u ON u.user_id = mt.user_id
         WHERE mt.token = ? AND mt.expires_at > NOW()
         LIMIT 1
    ");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'active') return false;

    $_SESSION['user_id']   = (int)$row['user_id'];
    $_SESSION['username']  = $row['username'];
    $_SESSION['user_lang'] = $row['language'] ?? 'en';
    $_SESSION['role']      = $row['role'];

    $pdo->prepare("UPDATE mobile_tokens SET last_used = NOW() WHERE token = ?")
        ->execute([$token]);
    return true;
}
```

### 2.3 New file: `api/mobile/login.php`

```
POST /api/mobile/login.php
Content-Type: application/json
Body: { "email": "...", "password": "...", "device_name": "Samsung Galaxy S24" }

Response 200:
{
  "success": true,
  "token": "<64-char hex>",
  "expires_at": "2026-10-21T10:00:00Z",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@shop.com",
    "role": "admin",
    "warehouse_id": 2,
    "warehouse_name": "Main Shop",
    "currency": "TZS",
    "language": "en"
  },
  "company": {
    "name": "Mama Pima Shop",
    "logo_url": "https://mamapima.bms.bjptechnologies.co.tz/uploads/logo.png",
    "currency": "TZS",
    "address": "Dar es Salaam, Tanzania"
  }
}

Response 401: { "success": false, "message": "Invalid email or password." }
```

Logic:
1. Validate email + password against `users` table (`password_hash` / `bcrypt`)
2. Check `users.status = 'active'`
3. Check user has `pos` permission via existing `hasPermission()` helper
4. Generate token: `bin2hex(random_bytes(32))`
5. Insert into `mobile_tokens` with `expires_at = NOW() + INTERVAL 30 DAY`
6. Fetch user's default warehouse (first warehouse they have access to)
7. Return token + user context

### 2.4 New file: `api/mobile/logout.php`

```
POST /api/mobile/logout.php
Header: Authorization: Bearer <token>
Response: { "success": true }
```

Deletes the token row from `mobile_tokens`.

### 2.5 New file: `api/mobile/me.php`

```
GET /api/mobile/me.php
Header: Authorization: Bearer <token>

Response 200:
{
  "success": true,
  "user": { ... same shape as login response user object ... },
  "company": {
    "name": "Mama Pima Shop",
    "logo_url": "https://mamapima.bms.bjptechnologies.co.tz/uploads/logo.png",
    "currency": "TZS",
    "address": "Dar es Salaam, Tanzania"
  },
  "warehouses": [
    { "warehouse_id": 2, "warehouse_name": "Main Shop" }
  ],
  "shift": {
    "shift_id": 45,
    "shift_code": "SHIFT-20260921-...",
    "starting_cash": 50000,
    "start_time": "2026-09-21T08:00:00",
    "register_name": "Main Register"
  },
  "pos_settings": {
    "discount_type": "percentage",
    "receipt_width": "80",
    "auto_print_receipt": false,
    "simple_mode": true
  },
  "tax_rates": [
    { "rate_id": 1, "rate_name": "VAT 18%", "rate": 18 }
  ],
  "permissions": {
    "can_discount": true,
    "can_restock": true,
    "can_void": false,
    "can_return": true,
    "can_pos_config_settings": false
  }
}
```

Called once after login and once on app resume. Returns the current active
shift (if any) so the app knows whether to show the Open Shift screen or
go straight to the POS terminal.

### 2.6 Two-line patch to each existing API

Add these two lines at the top of every POS API file Flutter calls, before
the `isAuthenticated()` check:

```php
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileAuthFromBearer();
```

Files to patch (13 total):

| File | Used by |
|---|---|
| `api/pos/simple_products.php` | Product grid |
| `api/pos/get_products.php` | Categories |
| `api/pos/process_sale.php` | Checkout |
| `api/pos/open_shift.php` | Start shift |
| `api/pos/close_shift.php` | End shift |
| `api/pos/hold_sale.php` | Hold cart |
| `api/pos/get_held_sales.php` | Retrieve held carts |
| `api/pos/delete_held_sale.php` | Delete held cart |
| `api/pos/search_customers.php` | Customer picker |
| `api/pos/get_dashboard.php` | Dashboard stats |
| `api/pos/get_sales.php` | Sales history |
| `api/pos/get_sale_items.php` | Sale detail / return |
| `api/pos/create_return.php` | Process return |
| `api/pos/quick_restock.php` | Restock |
| `api/pos/void_sale.php` | Void sale |
| `api/pos/generate_receipt_number.php` | Receipt number |

Also remove the `csrf_check()` call from each of these files — CSRF tokens
are browser-session-tied and do not apply to API token auth. Replace with a
simple check that `$_SERVER['REQUEST_METHOD'] === 'POST'`.

### 2.7 CORS — `api/mobile/.htaccess`

```apache
Header set Access-Control-Allow-Origin "*"
Header set Access-Control-Allow-Headers "Authorization, Content-Type"
Header set Access-Control-Allow-Methods "GET, POST, OPTIONS"
```

---

## 3. Flutter Project Setup

### 3.1 Create project

```bash
flutter create bms_pos --org tz.bms --platforms android,ios
cd bms_pos
```

### 3.2 `pubspec.yaml`

```yaml
name: bms_pos
description: BMS Simple POS — Mobile Terminal

environment:
  sdk: '>=3.3.0 <4.0.0'

dependencies:
  flutter:
    sdk: flutter

  # State
  flutter_riverpod: ^2.5.1
  riverpod_annotation: ^2.3.5

  # HTTP
  dio: ^5.4.3
  pretty_dio_logger: ^1.3.1   # dev logging only — remove for production

  # Secure storage (token)
  flutter_secure_storage: ^9.2.2

  # Local database
  sqflite: ^2.3.3
  path: ^1.9.0

  # Navigation
  go_router: ^13.2.0

  # Printer
  flutter_blue_plus: ^1.32.12
  esc_pos_utils_plus: ^2.0.0

  # Barcode scanner (camera)
  mobile_scanner: ^5.1.1

  # Images
  cached_network_image: ^3.3.1

  # Offline / connectivity
  connectivity_plus: ^6.0.3

  # Utilities
  intl: ^0.19.0
  uuid: ^4.4.0
  shared_preferences: ^2.2.3

dev_dependencies:
  flutter_test:
    sdk: flutter
  build_runner: ^2.4.9
  riverpod_generator: ^2.4.0
  flutter_lints: ^3.0.0
```

### 3.3 Android permissions — `android/app/src/main/AndroidManifest.xml`

Add inside `<manifest>`:

```xml
<uses-permission android:name="android.permission.INTERNET"/>
<uses-permission android:name="android.permission.BLUETOOTH" android:maxSdkVersion="30"/>
<uses-permission android:name="android.permission.BLUETOOTH_ADMIN" android:maxSdkVersion="30"/>
<uses-permission android:name="android.permission.BLUETOOTH_CONNECT"/>
<uses-permission android:name="android.permission.BLUETOOTH_SCAN"/>
<uses-permission android:name="android.permission.CAMERA"/>
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>
```

For plain HTTP (local network dev only), add inside `<application>`:
```xml
android:usesCleartextTraffic="true"
```
Remove this for production — BMS must serve HTTPS.

---

## 4. Project Structure

```
lib/
├── main.dart
│
├── core/
│   ├── api_client.dart          # Dio setup, base URL, token interceptor, retry
│   ├── api_endpoints.dart       # All URL constants
│   ├── database.dart            # SQLite: open, migrate, singleton
│   ├── secure_storage.dart      # Read/write token, base URL, user JSON
│   ├── connectivity.dart        # Stream<bool> isOnline
│   └── exceptions.dart          # ApiException, OfflineException, AuthException
│
├── models/
│   ├── user.dart
│   ├── product.dart
│   ├── category.dart
│   ├── cart_item.dart
│   ├── sale.dart
│   ├── sale_item.dart
│   ├── held_sale.dart
│   ├── shift.dart
│   ├── customer.dart
│   ├── warehouse.dart
│   └── pending_sale.dart        # Offline queue row
│
├── providers/
│   ├── auth_provider.dart       # login, logout, current user
│   ├── connectivity_provider.dart
│   ├── shift_provider.dart      # open/close shift, current shift state
│   ├── warehouse_provider.dart  # user's warehouses
│   ├── product_provider.dart    # products, categories, search
│   ├── cart_provider.dart       # cart state (pure local)
│   ├── customer_provider.dart   # customer search
│   ├── sale_provider.dart       # process sale, offline queue
│   ├── held_sale_provider.dart  # hold / retrieve
│   └── dashboard_provider.dart  # today stats
│
├── screens/
│   ├── login/
│   │   └── login_screen.dart
│   ├── shift/
│   │   ├── open_shift_screen.dart
│   │   └── close_shift_screen.dart
│   ├── pos/
│   │   ├── pos_screen.dart           # root: product grid + cart side by side (tablet)
│   │   │                             # or tabbed (phone)
│   │   ├── product_grid_screen.dart
│   │   ├── cart_screen.dart
│   │   └── checkout_screen.dart
│   ├── dashboard/
│   │   └── dashboard_screen.dart
│   ├── sales/
│   │   ├── sales_history_screen.dart
│   │   └── sale_detail_screen.dart
│   ├── returns/
│   │   └── return_screen.dart
│   ├── restock/
│   │   └── restock_screen.dart
│   └── settings/
│       └── settings_screen.dart      # base URL, printer, language
│
└── widgets/
    ├── product_card.dart
    ├── cart_item_tile.dart
    ├── customer_picker.dart
    ├── payment_method_selector.dart
    ├── numpad.dart                   # big-button numeric pad for cash entry
    ├── receipt_dialog.dart
    ├── held_sales_sheet.dart
    ├── barcode_button.dart
    └── offline_banner.dart           # red top banner when device is offline
```

---

## 5. Screen-by-Screen Specification

> **No registration in the mobile app.**  
> Registration is web-only at `https://bms.bjptechnologies.co.tz`. A company must be created
> there first. Once the company exists and has its subdomain (e.g. `mamapima`), users open
> the mobile app and log in with Company ID + email + password. There is no "Create Account"
> or "Register" button anywhere in the app.

### 5.1 Login Screen

**Route:** `/login`

**UI:**
```
App logo at top
[ Company ID       ]  e.g. mamapima
  https://mamapima.bms.bjptechnologies.co.tz   ← live preview (greyed, non-editable)
[ Email            ]
[ Password  👁     ]
[ Sign In          ]  (full-width button)
```

The base domain `bms.bjptechnologies.co.tz` is hardcoded in the app.
The live preview updates character-by-character as the user types their Company ID.

**Logic:**
1. Validate company ID, email, password are non-empty
2. Build base URL: `https://{companyId}.bms.bjptechnologies.co.tz`
3. POST to `{baseUrl}/api/mobile/login.php`
4. On success: save token + baseUrl + companyId + email to `flutter_secure_storage`, navigate to `/`
5. On 401: show "Invalid email or password"
6. On network error: show "Cannot reach {companyId}.bms.bjptechnologies.co.tz — check your Company ID and internet"

**Returning user (remembered login):**  
On second open: Company ID and email are pre-filled from `flutter_secure_storage`.  
User only types their password.  
A **"Not your company? Switch company"** link clears the stored Company ID + email and resets the form to blank.

**Offline behaviour:** Login requires a connection. If offline, show explanation and disable button.

---

### 5.2 Open Shift Screen

**Route:** `/shift/open`  
**Shown when:** `me.php` returns no active shift for this user.

**UI:**
```
Title: Start Your Shift
[ Opening Cash Amount  ]  (numeric input — shows numpad)
[ Start Shift ]
```

**API call:**
```
POST /api/pos/open_shift.php
Form: opening_cash=50000
Response: { success, shift_id, shift_code, starting_cash, register_name }
```

**Denomination counting (optional):**  
A "Count by denomination" toggle expands a grid of bill/coin denominations;
tapping denomination rows auto-sums into the Opening Cash field. Same widget
reused on Close Shift. Not required — cashier can type the amount directly.

**After success:** Save shift to local state, navigate to `/pos`

**Offline behaviour:** Cannot open a shift without a connection — server must
create the shift record. Show error and retry button.

---

### 5.3 POS Terminal Screen

**Route:** `/pos`  
**This is the main screen. All child functionality lives here.**

#### Layout — Phone (< 600 px wide)

```
┌──────────────────────────────────┐
│ 🛒 POS  | SHOP NAME | SHIFT INFO │  ← App bar
├──────────────────────────────────┤
│ [Search...🔍] [📷 Scanner]        │
│ [All] [Food] [Drinks] [Other] →  │  ← Category chips (horizontal scroll)
├──────────────────────────────────┤
│  Product  │  Product  │ Product  │
│  Card     │  Card     │ Card     │  ← 3-column product grid
│  ...                             │
├──────────────────────────────────┤
│  🛒 Cart (3 items)  TZS 15,000 ▲ │  ← Floating cart bar (tap to expand)
└──────────────────────────────────┘
```

Cart expanded → bottom sheet with cart items, totals, payment.

#### Layout — Tablet (≥ 600 px wide)

```
┌─────────────────────┬────────────────────┐
│  Search + Categories│  🛒 Current Sale    │
│  Product Grid       │  Cart items        │
│  (scrollable)       │  Totals            │
│                     │  Payment section   │
│                     │  [PROCESS PAYMENT] │
└─────────────────────┴────────────────────┘
```

#### Product Card

```
┌──────────────┐
│  [image/icon]│
│  Product Name│
│  TZS 5,000   │
│  Stock: 24   │
└──────────────┘
```
Tap → add to cart (quantity 1, or opens qty dialog if already in cart).  
Long-press → add specific quantity dialog.

Fields from API: `product_id`, `product_name`, `selling_price`, `effective_price`,
`stock_quantity`, `is_service`, `image_url`, `is_taxable`, `tax_rate`, `barcode`, `sku`

#### Cart State (local only, in `CartNotifier`)

```dart
class CartItem {
  final int productId;
  final String name;
  final double price;       // effective_price at time of adding
  double quantity;
  final double taxRate;
  final bool isTaxable;
  double? discountPercent;  // per-line discount
}
```

Cart totals recalculated on every change:
```
subtotal = sum(item.price × item.quantity)
discount = subtotal × (discount_percentage / 100)
taxable_amount = sum of items where is_taxable = true
tax = taxable_amount × (vat_rate / 100)    ← vat_rate from checkout picker (0 or 18)
total = subtotal - discount + tax
change = amount_tendered - total            ← cash payments only
```

#### Product Variants

When a product tile has `variant_count > 0`, tapping it opens a **Variant Picker**
bottom sheet listing all child variants (size, colour, etc.). Selecting one
passes the child's `product_id` into the normal `addToCart()` flow — no other
change needed.

#### Cart Actions

- **Clear cart** — confirm dialog, then wipe CartNotifier state
- **Hold sale** — POST to `hold_sale.php`, clear cart, show confirmation
- **View held sales** — bottom sheet listing held sales (GET `get_held_sales.php`), tap to restore
- **Delete held sale** — swipe-to-delete, POST `delete_held_sale.php`
- **Discount** — two levels:
  - *Cart-level*: opens Discount modal (% or fixed), applied to whole sale
  - *Per-product*: long-press a cart item → set per-line discount percentage
- **Open Cash Drawer** — button in POS app bar; sends the ESC/POS cash-drawer
  pulse sequence to the connected Bluetooth printer. Only shown if a printer
  is configured in Settings.

#### Search & Barcode

- Typing in search box: debounce 300ms, call `simple_products.php?search=...&warehouse_id=...`
- Camera scanner button: open `mobile_scanner` overlay, on detection pass barcode to search
- Result: product found → add to cart. Not found → show "Product not found" snackbar

#### Category Chips

Call `GET /api/pos/get_products.php` (or a dedicated categories endpoint) on
shift start. Cache in SQLite. Chips are horizontal-scroll row; tapping filters
the product grid (`simple_products.php?category=ID`).

---

### 5.4 Checkout Screen / Bottom Sheet

Opens when user taps "Process Payment" from cart.

**UI sections (top to bottom):**

**1. Customer picker**
- Default: "Walk-in Customer"
- Type name/phone → calls `search_customers.php?q=...`, shows dropdown
- Selecting a customer auto-fills credit limit info
- **"+ New Customer" button** — opens a small form: Name (required) + Phone
  (optional). Saves via `save_customer_quick.php`, auto-selects the new customer.
  This is NOT a full customer form — just the minimum fields needed to record
  the buyer.

**2. VAT selector**  
Loads from `me.php` tax_rates list (fetched at login). Shows all active tax rates.
```
○ No Tax (0%)
○ VAT 18%
```

**3. Discount** (if user has discount permission)  
Cart-level discount — two modes depending on `pos_discount_type` setting:
```
Discount Type: [% Percentage ▼]
Discount:      [ 10          ]
```
Per-product discounts are set separately by long-pressing a cart item (see §5.3).

**4. Totals recap**
```
Subtotal:    TZS 50,000
Discount:   -TZS  5,000
VAT:         TZS  8,100
─────────────────────────
TOTAL:       TZS 53,100
```

**5. Payment method tabs**
```
[Cash] [Card] [Mobile] [Bank] [Credit] [Split]
```

**6. Cash section (shown only for Cash payment)**
```
Amount Tendered: [ TZS 60,000  ]   ← numpad
Change Due:        TZS  6,900   ← auto-calculated
```

**7. Credit section (shown only for Credit payment)**
```
Due Date: [ 2026-10-21 ]   ← date picker
```
Customer must be selected (not walk-in) for credit payment.

**8. Split payment section (shown only for Split)**
```
Cash:          [ TZS 20,000 ]
Mobile Money:  [ TZS 15,000 ]
Bank Transfer: [ TZS 10,000 ]
Card / Other:  [ TZS  8,100 ]
─────────────────────────────
Remaining:       TZS  0.00   ← must reach 0 before confirming
```
Remaining recalculates live as amounts are entered.

**9. Action button**
```
[ PROCESS PAYMENT ]
```

**Process Payment logic:**
1. Validate:
   - Cash: amount_tendered ≥ total
   - Credit: due_date set AND customer selected
   - Split: remaining balance = 0
2. Build JSON payload (see §6.1)
3. If ONLINE → POST to `process_sale.php` → show Receipt dialog
4. If OFFLINE → save to `pending_sales` SQLite table → show "Saved offline — will sync when connected" → Receipt dialog with offline indicator

---

### 5.5 Receipt Dialog

```
╔══════════════════════════════╗
║        COMPANY NAME          ║
║    Receipt: RCP-20260921-001  ║
║    Date: 21 Sep 2026 10:30   ║
╠══════════════════════════════╣
║ Product A       2 × 5,000    ║
║                   10,000     ║
║ Product B       1 × 8,000    ║
║                    8,000     ║
╠══════════════════════════════╣
║ Subtotal:          18,000    ║
║ VAT (18%):          3,240    ║
║ TOTAL:             21,240    ║
║ Cash:              25,000    ║
║ Change:             3,760    ║
╚══════════════════════════════╝

[ 🖨 Print Receipt ]  [ New Sale ]
```

"Print Receipt" → connects to saved Bluetooth printer, sends ESC/POS bytes.  
"New Sale" → clears cart, generates new receipt number, returns to POS.

---

### 5.6 Close Shift Screen

**Route:** `/shift/close`  
**Accessed from:** app drawer → "End Shift"

**UI:**
```
Shift Summary
─────────────────────────────────
Shift Code:      SHIFT-20260921-...
Started:         08:00
Register:        Main Register
─────────────────────────────────
Sales Today:     TZS 450,000
Cash Sales:      TZS 200,000
Card Sales:      TZS 150,000
Mobile Sales:    TZS 100,000
Returns:         TZS  10,000
─────────────────────────────────
Expected Cash:   TZS 250,000
Ending Cash:    [ 248,000 ]  ← editable
Difference:      TZS  -2,000
─────────────────────────────────
Notes: [ optional ]

[ Close Shift ]
```

**API call:**
```
POST /api/pos/close_shift.php
Form: ending_cash=248000&notes=...
Response: { success, total_sales, expected_cash, cash_difference, ... }
```

**Denomination counting (optional):**  
Same toggle as Open Shift — expand grid of bills/coins, auto-sum into Ending Cash.

**After close:** clear shift from local state, navigate to `/shift/open`.

After closing, show a **"View Z-Report"** button that navigates to `/shift/zreport/:shiftId`.

---

### 5.7 Dashboard Screen

**Route:** `/dashboard`  
**Accessed from:** app drawer

**UI: summary cards in a 2×2 grid**
```
┌─────────────┬─────────────┐
│ Today Sales │ Items Sold  │
│ TZS 45,000  │    23       │
├─────────────┼─────────────┤
│ Month Sales │  Avg Order  │
│ TZS 890,000 │ TZS 1,956   │
└─────────────┴─────────────┘
+ 14-day sales trend line chart
```

**API:** `GET /api/pos/get_dashboard.php`

---

### 5.8 Sales History Screen

**Route:** `/sales`  
**Accessed from:** app drawer

**UI:**
- Date range picker (default: current month)
- List of sales (receipt no, date, customer, total, status badge)
- Tap → Sale Detail sheet

**Sale Detail sheet:**
- Receipt info + line items
- Buttons: [Print] [Return Items] [Void] (if can_void)

**API:**
- List: `GET /api/pos/get_sales.php?start_date=...&end_date=...`
- Detail: `GET /api/pos/get_sale_items.php?sale_id=...`

---

### 5.9 Return Screen

**Route:** `/returns/{saleId}`  
**Accessed from:** Sale Detail

**UI:**
- Lists original sale items with qty selectors
- Refund method picker
- Reason text field
- [Process Return]

**API:**
```
POST /api/pos/create_return.php
Form: original_sale_id=X&reason=...&refund_method=cash&items=[{"sale_item_id":1,"return_qty":2}]
```

---

### 5.10 Restock Screen

**Route:** `/restock`  
**Accessed from:** app drawer (only shown if user has pos_restock permission)

**UI:**
- Product search (calls `search_products_for_restock.php`)
- Fields: Buying price, Selling price, Wholesale price, Quantity, Date
- [Restock]

**API:**
```
POST /api/pos/quick_restock.php
Form: product_id, warehouse_id, quantity, buying_price, selling_price,
      wholesale_price, date, paid_from_account_id (default = cash account)
```

---

### 5.11 Settings Screen

**Route:** `/settings`

Split into two groups:

**App Settings** (stored locally on device):
- **Company ID** — editable (clears saved credentials, triggers re-login)
- **Bluetooth Printer** — scan nearby BLE printers, tap to pair, save MAC
- **Print Test** — sends a test ESC/POS receipt to the saved printer
- **Receipt Paper Width** — 58mm or 80mm (synced to server via `save_pos_setting.php`)
- **Language** — EN / SW picker (stored locally)
- **App version**
- **Sign Out**

**POS Settings** (stored on server, fetched from `me.php` → `pos_settings` block):
- **Discount Type** — Percentage (%) or Fixed Amount. Controls which input
  appears in Checkout. Saved via `save_pos_setting.php`.
- **Auto-print Receipt** — when ON, receipt prints automatically after every
  sale without tapping "Print Receipt". Saved via `save_pos_setting.php`.

> All POS Settings changes are saved to the server so the web POS and mobile
> app stay in sync. Only users with `pos_config_settings` permission see the
> POS Settings group; others see it grayed out.

---

### 5.12 Who Owes Me Screen *(Simple POS only)*

**Route:** `/credit-customers`  
**Accessed from:** app drawer  
**Simple POS only** — hidden for non-simple tenants.

**UI:**
```
┌───────────────────────────────────┐
│ Who Owes Me                       │
│ Total Outstanding: TZS 340,000    │
├───────────────────────────────────┤
│ John Mushi                        │
│ TZS 80,000 owed · Due: 25 Oct    │  ← green if upcoming, red if overdue
│                           [Pay]   │
├───────────────────────────────────┤
│ Amina Hassan                      │
│ TZS 120,000 owed · OVERDUE 5 days │
│                           [Pay]   │
└───────────────────────────────────┘
```

Tapping a row expands it to show individual credit sales (date, amount, balance).

**Record Payment:**  
Tapping [Pay] opens a bottom sheet:
```
Customer: John Mushi
Outstanding: TZS 80,000
Amount Paid: [ TZS 40,000  ]  ← numpad
Method:      [Cash ▼]
[ Record Payment ]
```
Calls `POST /api/pos/receive_payment.php`.

**Update Due Date:**  
Long-press a credit sale row → "Change Due Date" → date picker →
`POST /api/pos/update_credit_due_date.php`.

**API:**
```
GET  /api/pos/get_credit_aging.php          → list of debtors
GET  /api/pos/get_credit_sale_detail.php?customer_id=X  → sale breakdown
POST /api/pos/receive_payment.php           → record a repayment
POST /api/pos/update_credit_due_date.php    → update due date
```

---

### 5.13 Shift History Screen

**Route:** `/shifts`  
**Accessed from:** app drawer → "Shift History"

**UI:**
```
Shift History
[ All shifts ▼ ]  [ This Month ▼ ]

SHIFT-20260921-001  ·  21 Sep 2026
  Cashier: John  ·  08:00 – 17:30
  Sales: TZS 450,000  ·  Cash: TZS 300,000
  Status: CLOSED                  [Z-Report]

SHIFT-20260920-001  ·  20 Sep 2026
  Cashier: John  ·  08:00 – 17:00
  Sales: TZS 380,000              [Z-Report]
```

Tapping [Z-Report] navigates to `/shift/zreport/:shiftId`.

**API:** `GET /api/pos/get_shifts.php?start_date=...&end_date=...`

---

### 5.14 Z-Report Screen

**Route:** `/shift/zreport/:shiftId`  
**Accessed from:** Shift History or "View Z-Report" after closing a shift.

**UI — printable summary:**
```
╔══════════════════════════════════╗
║         COMPANY NAME             ║
║        Z-REPORT / SHIFT SUMMARY  ║
╠══════════════════════════════════╣
║ Shift:    SHIFT-20260921-001     ║
║ Register: Main Register          ║
║ Cashier:  John Doe               ║
║ Started:  21 Sep 2026  08:00     ║
║ Closed:   21 Sep 2026  17:30     ║
╠══════════════════════════════════╣
║ SALES BY TENDER                  ║
║ Cash:          TZS 200,000       ║
║ Card:          TZS 150,000       ║
║ Mobile Money:  TZS 100,000       ║
║ Credit:        TZS  50,000       ║
║ ─────────────────────────────── ║
║ Total Sales:   TZS 500,000       ║
╠══════════════════════════════════╣
║ CASH RECONCILIATION              ║
║ Starting Cash: TZS  50,000       ║
║ Cash Sales:    TZS 200,000       ║
║ Refunds (cash):TZS -10,000       ║
║ Expected Cash: TZS 240,000       ║
║ Counted Cash:  TZS 238,000       ║
║ Difference:    TZS  -2,000       ║
╠══════════════════════════════════╣
║ Returns:       TZS  10,000       ║
║ Voids:         0                 ║
╚══════════════════════════════════╝

[ 🖨 Print Z-Report ]
```

Sends the full Z-Report as ESC/POS to the saved Bluetooth printer.

**API:** `GET /api/pos/get_shift_report.php?shift_id=X`

---

## 6. API Request/Response Reference

### 6.1 Process Sale — full payload

```json
{
  "customer_id": null,
  "warehouse_id": 2,
  "project_id": null,
  "price_group_id": 0,
  "payment_method": "cash",
  "amount_tendered": 60000,
  "subtotal": 50000,
  "discount_percentage": 0,
  "discount_amount": 0,
  "tax": 9000,
  "total": 59000,
  "receipt_number": "RCP-20260921-001",
  "due_date": null,
  "split_details": null,
  "items": [
    {
      "product_id": 14,
      "name": "Bread",
      "quantity": 2,
      "price": 5000,
      "discount_percentage": 0,
      "unit_id": null
    }
  ]
}
```

Response:
```json
{
  "success": true,
  "receipt_number": "RCP-20260921-001",
  "sale_id": 1042,
  "change_due": 1000
}
```

### 6.2 Products

```
GET /api/pos/simple_products.php
    ?warehouse_id=2
    &category=0          (0 = all)
    &search=bread
    &price_group_id=0

Response:
{
  "success": true,
  "data": [
    {
      "product_id": 14,
      "product_name": "Bread",
      "sku": "BRD-001",
      "barcode": "123456789",
      "selling_price": 5000,
      "effective_price": 5000,
      "stock_quantity": 48,
      "is_service": false,
      "is_taxable": true,
      "tax_rate": 18,
      "image_url": null,
      "category_id": 3
    }
  ],
  "count": 1
}
```

### 6.3 Held Sales

```
GET /api/pos/get_held_sales.php

Response:
{
  "success": true,
  "data": [
    {
      "hold_id": 5,
      "hold_reference": "HOLD-20260921-103022",
      "held_at": "2026-09-21T10:30:22",
      "total_amount": 25000,
      "items_data": "[{...}]"   ← JSON string, parse it
    }
  ]
}
```

---

## 7. Offline Support — Complete Strategy

### 7.1 What works offline

| Feature | Offline behaviour |
|---|---|
| Product browsing | Uses cached products (SQLite) |
| Cart management | Fully local — no API needed |
| Process sale | Queued in `pending_sales` (SQLite), synced on reconnect |
| Barcode scan | Works (camera) |
| Receipt display | Shows immediately with locally-generated receipt number |
| Print receipt | Works (Bluetooth — no internet needed) |

### 7.2 What requires a connection

| Feature | Reason |
|---|---|
| Login | Token must be issued by server |
| Open / Close shift | Server creates shift record |
| Sales history | Reads from server DB |
| Dashboard stats | Server aggregations |
| Customer search | Server query |
| Restock | Server writes stock |

### 7.3 Local SQLite schema

```sql
-- Products cache (refreshed on every shift open or app resume)
CREATE TABLE IF NOT EXISTS cached_products (
  product_id    INTEGER PRIMARY KEY,
  product_name  TEXT NOT NULL,
  sku           TEXT,
  barcode       TEXT,
  selling_price REAL,
  stock_qty     REAL,
  category_id   INTEGER,
  is_taxable    INTEGER,
  tax_rate      REAL,
  image_url     TEXT,
  cached_at     TEXT  -- ISO-8601 timestamp
);

-- Categories cache
CREATE TABLE IF NOT EXISTS cached_categories (
  category_id   INTEGER PRIMARY KEY,
  category_name TEXT NOT NULL
);

-- Pending (offline) sales waiting to sync
CREATE TABLE IF NOT EXISTS pending_sales (
  id            TEXT PRIMARY KEY,   -- UUID v4, generated offline
  payload       TEXT NOT NULL,      -- JSON of the process_sale POST body
  receipt_number TEXT NOT NULL,
  cart_snapshot TEXT NOT NULL,      -- JSON of cart items (for receipt display)
  created_at    TEXT NOT NULL,      -- ISO-8601
  synced        INTEGER DEFAULT 0,
  sync_error    TEXT                -- last error message if sync failed
);
```

### 7.4 Offline receipt number generation

When offline, generate the receipt number locally:
```dart
String generateOfflineReceiptNumber() {
  final now = DateTime.now();
  final date = '${now.year}${now.month.toString().padLeft(2,'0')}${now.day.toString().padLeft(2,'0')}';
  final rand = (1000 + Random().nextInt(8999)).toString();
  return 'RCP-$date-OFF-$rand';
}
```

Mark the receipt with an "OFFLINE — will sync" note.

### 7.5 Sync service

```dart
class SyncService {
  // Called by:
  // 1. ConnectivityProvider when isOnline flips true
  // 2. App resume (AppLifecycleState.resumed)
  // 3. Background timer every 30s while online

  Future<void> syncPendingSales() async {
    final pending = await db.query('pending_sales', where: 'synced = 0');
    for (final row in pending) {
      try {
        final payload = jsonDecode(row['payload'] as String);
        final res = await apiClient.post('/api/pos/process_sale.php', data: payload);
        if (res['success']) {
          await db.update('pending_sales', {'synced': 1}, where: 'id = ?', whereArgs: [row['id']]);
        }
      } catch (e) {
        await db.update('pending_sales', {'sync_error': e.toString()}, where: 'id = ?', whereArgs: [row['id']]);
      }
    }
  }
}
```

### 7.6 Product cache refresh strategy

- On shift open: fetch and store all products for the selected warehouse
- On app resume (if shift is open): refresh if cache is older than 5 minutes
- Cache stores up to 500 products; if count > 500, search always hits the server

---

## 8. Bluetooth Receipt Printing

### 8.1 Dependencies

```yaml
flutter_blue_plus: ^1.32.12    # BLE device scan + connection
esc_pos_utils_plus: ^2.0.0     # ESC/POS command builder
```

### 8.2 Printer discovery (Settings screen)

```dart
FlutterBluePlus.startScan(timeout: const Duration(seconds: 5));
FlutterBluePlus.scanResults.listen((results) {
  // Show list of discovered devices; user taps to pair and save MAC address
});
```

Save chosen printer MAC address in `shared_preferences`.

### 8.3 ESC/POS receipt builder

```dart
Future<List<int>> buildReceipt({
  required String companyName,
  required String receiptNumber,
  required String date,
  required List<CartItem> items,
  required double subtotal,
  required double tax,
  required double total,
  required String paymentMethod,
  double? amountTendered,
  double? changeDue,
}) async {
  final profile = await CapabilityProfile.load();
  final generator = Generator(PaperSize.mm58, profile);
  final bytes = <int>[];

  bytes.addAll(generator.reset());
  bytes.addAll(generator.text(companyName,
      styles: const PosStyles(align: PosAlign.center, bold: true, height: PosTextSize.size2)));
  bytes.addAll(generator.text('Receipt: $receiptNumber',
      styles: const PosStyles(align: PosAlign.center)));
  bytes.addAll(generator.text(date,
      styles: const PosStyles(align: PosAlign.center)));
  bytes.addAll(generator.hr());

  for (final item in items) {
    bytes.addAll(generator.row([
      PosColumn(text: item.name, width: 7),
      PosColumn(text: item.quantity.toString(), width: 1, styles: const PosStyles(align: PosAlign.center)),
      PosColumn(text: formatCurrency(item.totalPrice), width: 4, styles: const PosStyles(align: PosAlign.right)),
    ]));
  }

  bytes.addAll(generator.hr());
  bytes.addAll(generator.row([
    PosColumn(text: 'Subtotal', width: 8),
    PosColumn(text: formatCurrency(subtotal), width: 4, styles: const PosStyles(align: PosAlign.right)),
  ]));
  if (tax > 0) {
    bytes.addAll(generator.row([
      PosColumn(text: 'VAT', width: 8),
      PosColumn(text: formatCurrency(tax), width: 4, styles: const PosStyles(align: PosAlign.right)),
    ]));
  }
  bytes.addAll(generator.row([
    PosColumn(text: 'TOTAL', width: 8, styles: const PosStyles(bold: true)),
    PosColumn(text: formatCurrency(total), width: 4,
        styles: const PosStyles(align: PosAlign.right, bold: true)),
  ]));
  if (paymentMethod == 'cash' && amountTendered != null) {
    bytes.addAll(generator.row([
      PosColumn(text: 'Cash', width: 8),
      PosColumn(text: formatCurrency(amountTendered), width: 4, styles: const PosStyles(align: PosAlign.right)),
    ]));
    bytes.addAll(generator.row([
      PosColumn(text: 'Change', width: 8),
      PosColumn(text: formatCurrency(changeDue ?? 0), width: 4, styles: const PosStyles(align: PosAlign.right)),
    ]));
  }
  bytes.addAll(generator.hr());
  bytes.addAll(generator.text('Thank you!', styles: const PosStyles(align: PosAlign.center)));
  bytes.addAll(generator.feed(3));
  bytes.addAll(generator.cut());
  return bytes;
}
```

### 8.3 Sending to printer

```dart
Future<void> printReceipt(List<int> bytes) async {
  final macAddress = prefs.getString('printer_mac');
  if (macAddress == null) throw Exception('No printer configured');

  final device = BluetoothDevice.fromId(macAddress);
  await device.connect(timeout: const Duration(seconds: 5));

  final services = await device.discoverServices();
  for (final service in services) {
    for (final char in service.characteristics) {
      if (char.properties.write || char.properties.writeWithoutResponse) {
        // Send in chunks of 512 bytes
        for (var i = 0; i < bytes.length; i += 512) {
          final chunk = bytes.sublist(i, min(i + 512, bytes.length));
          await char.write(chunk, withoutResponse: char.properties.writeWithoutResponse);
        }
        break;
      }
    }
  }
  await device.disconnect();
}
```

---

## 9. Navigation Map

```
/                          → redirect: shift open? → /pos  else → /shift/open
/login                     → LoginScreen
/shift/open                → OpenShiftScreen
/pos                       → PosScreen (main terminal)
/pos/checkout              → CheckoutScreen (or bottom sheet)
/shift/close               → CloseShiftScreen
/shift/zreport/:shiftId    → ZReportScreen
/dashboard                 → DashboardScreen
/sales                     → SalesHistoryScreen
/sales/:saleId             → SaleDetailScreen
/returns/:saleId           → ReturnScreen
/restock                   → RestockScreen
/shifts                    → ShiftHistoryScreen
/credit-customers          → WhoOwesMeScreen (Simple POS only)
/settings                  → SettingsScreen
```

App drawer (hamburger) available from any screen except login:
- POS Terminal
- Dashboard
- Sales History
- Shift History
- Who Owes Me (Simple POS only)
- Restock (if `pos_restock` permission)
- Settings
- End Shift
- Sign Out

---

## 10. Build Order

Build in this exact order. Each phase is independently testable.

### Phase 1 — Foundation (days 1–2)
- [ ] Flutter project created with all dependencies in pubspec.yaml
- [ ] `core/api_client.dart` — Dio + Bearer interceptor
- [ ] `core/secure_storage.dart` — token + baseUrl read/write
- [ ] `core/database.dart` — SQLite open, run schema migrations
- [ ] `core/connectivity.dart` — stream of online/offline status
- [ ] All model classes (user, product, cart_item, sale, shift, etc.)
- [ ] **Backend: write mobile_tokens migration, mobile_auth.php, login.php, me.php, logout.php**

### Phase 2 — Auth (day 3)
- [ ] `LoginScreen` with Company ID + email + password fields, live URL preview
- [ ] `AuthProvider` — calls login.php, saves token
- [ ] App starts on `/login`, redirects to `/` after login
- [ ] `/` reads `me.php` → routes to `/shift/open` or `/pos`
- [ ] **Test: can log in, get token, me.php returns user**

### Phase 3 — Shift (day 4)
- [ ] `OpenShiftScreen` — opening cash input + numpad
- [ ] `CloseShiftScreen` — summary display + ending cash input
- [ ] `ShiftProvider` — holds current shift state
- [ ] **Backend: patch open_shift.php + close_shift.php with mobileAuthFromBearer()**
- [ ] **Test: open a shift, verify it appears in BMS web POS**

### Phase 4 — Product Grid (days 5–6)
- [ ] `ProductProvider` — fetches from simple_products.php, caches to SQLite
- [ ] Category chips from get_products.php (cached)
- [ ] `ProductCard` widget
- [ ] Search bar (debounced API call)
- [ ] **Backend: patch simple_products.php + get_products.php**
- [ ] **Test: products appear, categories filter correctly**

### Phase 5 — Cart (day 7)
- [ ] `CartNotifier` — add, remove, change qty, clear
- [ ] `CartScreen` — list with swipe-to-remove
- [ ] Subtotal / VAT / total calculation
- [ ] Hold sale — POST hold_sale.php, show confirmation
- [ ] View held sales — bottom sheet, tap to restore, swipe to delete
- [ ] **Backend: patch hold_sale.php, get_held_sales.php, delete_held_sale.php**
- [ ] **Test: cart persists through hold/retrieve cycle**

### Phase 6 — Checkout + Sale (days 8–9)
- [ ] `CheckoutScreen` — customer picker, VAT, payment method, numpad
- [ ] `CustomerProvider` — search_customers.php (debounced)
- [ ] `SaleProvider.processSale()` — online path → process_sale.php
- [ ] `ReceiptDialog` — display and print
- [ ] **Backend: patch process_sale.php, search_customers.php, remove csrf_check()**
- [ ] **Test: complete end-to-end sale, verify receipt number in BMS web**

### Phase 7 — Offline sales (day 10)
- [ ] Offline receipt number generation
- [ ] `SaleProvider.processSale()` — offline path → insert to pending_sales SQLite
- [ ] `SyncService.syncPendingSales()` triggered on reconnect
- [ ] `OfflineBanner` widget shown when device has no connection
- [ ] **Test: disable WiFi, process sale, re-enable WiFi, verify sale syncs to BMS**

### Phase 8 — Product cache (day 11)
- [ ] `ProductProvider` loads from SQLite when offline
- [ ] Cache refresh on shift open and app resume
- [ ] Show "Showing cached products — data may be outdated" banner when offline
- [ ] **Test: disable WiFi after loading products, verify browsing still works**

### Phase 9 — Bluetooth Printing (days 12–13)
- [ ] `SettingsScreen` — printer scan, save MAC, test print
- [ ] `EscPosBuilder.buildReceipt()` — full receipt bytes
- [ ] `PrinterService.print()` — connect BLE, write chunks, disconnect
- [ ] Wire print button in `ReceiptDialog`
- [ ] **Test: print receipt on a physical 58mm thermal printer**

### Phase 10 — Barcode Scanner (day 14)
- [ ] `BarcodeScannerButton` widget — opens camera overlay via mobile_scanner
- [ ] On scan: search products by barcode, add to cart if exactly one match
- [ ] **Test: scan a product barcode, verify it adds to cart**

### Phase 11 — Dashboard + Sales History (days 15–16)
- [ ] `DashboardScreen` — stats cards + trend chart
- [ ] `SalesHistoryScreen` — date picker + list
- [ ] `SaleDetailScreen` — line items + actions
- [ ] **Backend: patch get_dashboard.php, get_sales.php, get_sale_items.php**

### Phase 12 — Returns + Restock (days 17–18)
- [ ] `ReturnScreen` — item selector, reason, refund method
- [ ] `RestockScreen` — product search, prices, qty, mfg/expiry dates
- [ ] **Backend: patch create_return.php, void_sale.php, quick_restock.php, search_products_for_restock.php**

### Phase 13 — Who Owes Me + Shift History + Z-Report (days 19–21)
- [ ] `WhoOwesMeScreen` — credit aging list, expandable per-customer sales
- [ ] `RecordPaymentSheet` — amount + method → `receive_payment.php`
- [ ] Update due date → `update_credit_due_date.php`
- [ ] `ShiftHistoryScreen` — paginated shift list with date filter
- [ ] `ZReportScreen` — shift summary card + print button
- [ ] `EscPosBuilder.buildZReport()` — ESC/POS Z-report bytes
- [ ] **Backend: get_credit_aging.php, get_credit_sale_detail.php, receive_payment.php, update_credit_due_date.php, get_shifts.php, get_shift_report.php — all need mobileAuthFromBearer() patch**

### Phase 14 — Settings sync + Split payment (days 22–23)
- [ ] `SettingsScreen` split into App Settings + POS Settings sections
- [ ] Fetch `pos_settings` from `me.php` response (discount_type, receipt_width, auto_print)
- [ ] `save_pos_setting.php` API call on each POS setting change
- [ ] Split payment: `SplitPaymentWidget` in CheckoutScreen
- [ ] Per-product discount: long-press cart item → discount input
- [ ] Quick Add Customer: mini form in Checkout → `save_customer_quick.php`
- [ ] Cash drawer pulse via ESC/POS when "Open Drawer" tapped
- [ ] **Backend: save_pos_setting.php (new file), save_customer_quick.php (new file)**

### Phase 15 — Polish (days 24–25)
- [ ] App icon and splash screen
- [ ] Error handling: expired token → redirect to login
- [ ] Error handling: server unreachable → show retry button
- [ ] Loading states on all async operations
- [ ] Haptic feedback on cart add / payment success
- [ ] Light/dark theme support
- [ ] Release build + APK generation

---

## 11. Key Implementation Rules

1. **Never store the token in SharedPreferences** — use `flutter_secure_storage` only.
   SharedPreferences on Android is world-readable by rooted devices.

2. **Cart state is never persisted to SQLite** — it lives only in `CartNotifier`
   (in-memory Riverpod state). The cart is intentionally lost on app kill; the
   user uses Hold Sale to save it explicitly.

3. **Every API call must handle a 401 response** — expired token → clear storage
   → redirect to `/login`. The Dio interceptor handles this globally.

4. **Sync errors are non-fatal** — a sale that fails to sync stays in
   `pending_sales` with the error message recorded. The user sees a "sync failed"
   indicator in settings. Never silently discard a pending sale.

5. **Print failure is non-fatal** — if Bluetooth printing fails, the receipt is
   still shown on screen. The cashier can retry. Do not block the "New Sale" flow
   on a printer error.

6. **Never call process_sale.php twice for the same receipt number** — generate
   the receipt number locally before the API call. The API uses it as-is. If the
   API call times out and you retry, use the same receipt number — the server's
   UNIQUE constraint will reject the duplicate and return an appropriate error.

7. **Product images are optional** — if `image_url` is null or the load fails,
   show a category icon (BoxFill icon). `cached_network_image` handles fallbacks.

8. **The warehouse_id is locked once a shift is opened** — after `open_shift.php`
   returns a `warehouse_id` (via the register's own assignment), that value is
   used for all products and sales in this shift. Do not show the warehouse picker
   during the shift; show only the shift's warehouse name as read-only text.

---

## 12. Recommended Hardware

| Item | Spec | Approx. cost |
|---|---|---|
| Receipt printer | 58mm Bluetooth ESC/POS thermal (e.g. Xprinter XP-P300, RPP02N) | $25–40 |
| Android device | Android 8+ (API 26+), any mid-range phone | — |
| Barcode scanner | Built-in phone camera (mobile_scanner) or Bluetooth HID scanner | $0 / $15 |

---

## 13. File Delivery Summary

**BMS backend (write these first):**
- `migrations/tenant/2026_09_21_mobile_tokens.php`
- `core/mobile_auth.php`
- `api/mobile/login.php`
- `api/mobile/me.php`
- `api/mobile/logout.php`
- Patch 16 existing API files (add 2 lines each, remove csrf_check)

**Flutter project (create this):**
- Full project as described in §4
- 13 screens, ~10 providers, 10 models, ~8 shared widgets
- Target: ~20 working days for one developer

---

*This document was generated from direct analysis of the BMS Simple POS source:
`app/bms/pos/pos.php`, `pos_modals_new.php`, `pos_scripts_new.php`,
`api/pos/simple_products.php`, `process_sale.php`, `open_shift.php`,
`close_shift.php`, `hold_sale.php`, `get_held_sales.php`, `search_customers.php`,
`get_dashboard.php`, `get_sales.php`, `create_return.php`, `quick_restock.php`.*
