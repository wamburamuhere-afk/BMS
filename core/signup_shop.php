<?php
/**
 * core/signup_shop.php — the first shop a Smart POS company gets at sign-up (2026-11-11).
 *
 * A Smart POS sign-up (retail or restaurant) used to start with no shop at all: the owner had to find
 * Shops and add one before the till would sell. Now provisioning (core/tenant_provisioner.php, step 9.8)
 * creates it from what the owner typed on the sign-up form — company name, phone, email, physical
 * address, country — as the main shop, active, capacity 999999, in Retail or Restaurant mode.
 * It is an ordinary shop: the owner edits or renames it from Shops like any other.
 *
 * A restaurant also gets the ready-made Waiter / Cashier / Cook / Restaurant Manager roles here, the
 * same ones restaurantReadyRolesSync() makes when a shop is saved in Restaurant mode (that function
 * needs a signed-in request; provisioning has none, so the pure DB step rofsEnsureReadyRoles() is used).
 *
 * Smart BMS sign-ups never call this: their logic is unchanged (held for approval, no shop).
 */

if (!function_exists('signupPosBusiness')) {
    /** The kind of Smart POS business picked at sign-up: 'restaurant', else 'retail' (also for anything unknown). */
    function signupPosBusiness($value): string
    {
        return strtolower(trim((string)$value)) === 'restaurant' ? 'restaurant' : 'retail';
    }
}

if (!function_exists('signupShopCapacity')) {
    /** The capacity a sign-up shop starts with (owner's rule): effectively unlimited, editable later. */
    function signupShopCapacity(): int
    {
        return 999999;
    }
}

if (!function_exists('seedSignupShop')) {
    /**
     * Create the company's first shop (+ its default storage location) in the NEW company's database.
     * Does nothing when the company already has a shop (idempotent: safe to run twice).
     *
     * @param PDO   $tpdo the new company's own database
     * @param array $d    name, pos_mode ('retail'|'restaurant'), phone, email, address, country,
     *                    contact_person, created_by (owner user id or 0)
     * @return array{created:bool, warehouse_id:?int, warehouse_code:?string, pos_mode:string, roles:string[]}
     */
    function seedSignupShop(PDO $tpdo, array $d): array
    {
        $mode = signupPosBusiness($d['pos_mode'] ?? 'retail');
        $out = ['created' => false, 'warehouse_id' => null, 'warehouse_code' => null, 'pos_mode' => $mode, 'roles' => []];

        $existing = (int)$tpdo->query("SELECT COUNT(*) FROM warehouses WHERE status <> 'deleted'")->fetchColumn();
        if ($existing > 0) return $out;

        $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 255);
        if ($name === '') throw new InvalidArgumentException('A shop name is required.');

        $hasPosMode = (bool)$tpdo->query("SHOW COLUMNS FROM warehouses LIKE 'pos_mode'")->fetch();
        if (!$hasPosMode) $mode = 'retail';   // a company DB without the restaurant schema can only hold retail shops
        $out['pos_mode'] = $mode;

        require_once __DIR__ . '/code_generator.php';
        $dup = $tpdo->prepare("SELECT 1 FROM warehouses WHERE warehouse_code = ?");
        $code = '';
        for ($i = 0; $i < 20; $i++) {
            $code = nextCode($tpdo, 'WH');
            $dup->execute([$code]);
            if (!$dup->fetchColumn()) break;
        }

        $country = mb_substr(trim((string)($d['country'] ?? '')), 0, 100);
        $createdBy = (int)($d['created_by'] ?? 0) ?: null;
        $cols = ['warehouse_name', 'warehouse_code', 'location', 'address', 'country', 'contact_person', 'phone', 'email',
                 'capacity', 'status', 'is_primary', 'notes', 'created_by'];
        $vals = [$name, $code, $country !== '' ? $country : null, trim((string)($d['address'] ?? '')) ?: null,
                 $country !== '' ? $country : null,
                 mb_substr(trim((string)($d['contact_person'] ?? '')), 0, 255) ?: null,
                 mb_substr(trim((string)($d['phone'] ?? '')), 0, 50) ?: null,
                 mb_substr(trim((string)($d['email'] ?? '')), 0, 255) ?: null,
                 signupShopCapacity(), 'active', 1, 'Created at sign-up', $createdBy];
        if ($hasPosMode) { $cols[] = 'pos_mode'; $vals[] = $mode; }

        $own = !$tpdo->inTransaction();
        if ($own) $tpdo->beginTransaction();
        try {
            $tpdo->prepare("INSERT INTO warehouses (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")
                 ->execute($vals);
            $wid = (int)$tpdo->lastInsertId();
            // Same default location the Shops page creates with every new shop.
            $tpdo->prepare("INSERT INTO locations (warehouse_id, location_name, location_code, location_type, capacity, status, created_by)
                            VALUES (?, 'Main Storage Area', 'MAIN', 'storage', ?, 'active', ?)")
                 ->execute([$wid, signupShopCapacity(), $createdBy]);
            if ($own) $tpdo->commit();
        } catch (Throwable $e) {
            if ($own && $tpdo->inTransaction()) $tpdo->rollBack();
            throw $e;
        }
        $out['created'] = true;
        $out['warehouse_id'] = $wid;
        $out['warehouse_code'] = $code;

        if ($mode === 'restaurant') {
            require_once __DIR__ . '/restaurant_order_flow_schema.php';
            $out['roles'] = rofsEnsureReadyRoles($tpdo, function ($m) {});
            rofsUpgradeReadyRoles($tpdo, function ($m) {});
        }
        return $out;
    }
}
