<?php
/**
 * core/online_customer.php — the customer behind an online order (retail_online_shop_plan.md,
 * decision 5). Used only when STAFF accept an order, never when a buyer sends one, so spam
 * orders can never fill the customer list.
 *
 * Rule (owner): reuse an existing customer only when BOTH the phone and the name match;
 * a phone-only or name-only match is a NEW customer. Matching is tidy, not exact text:
 *   phone — digits only; 0712…, 255712…, +255 712… and 712… are the same number;
 *   name  — capital letters and extra spaces ignored.
 */

if (!function_exists('ocNormalisePhone')) {
    function ocNormalisePhone(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string)$phone) ?? '';
        if ($d === '') return '';
        if (strlen($d) === 12 && str_starts_with($d, '255')) return '0' . substr($d, 3);
        if (strlen($d) === 9 && $d[0] !== '0') return '0' . $d;
        return $d;
    }
}

if (!function_exists('ocNormaliseName')) {
    function ocNormaliseName(?string $name): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string)$name) ?? '');
        return mb_strtolower($s, 'UTF-8');
    }
}

if (!function_exists('ocFindExact')) {
    /** The customer whose phone AND name both match, or null. */
    function ocFindExact(PDO $pdo, string $name, string $phone): ?array
    {
        $p = ocNormalisePhone($phone);
        $n = ocNormaliseName($name);
        if ($p === '' || $n === '') return null;
        foreach (ocCandidatesByPhone($pdo, $p) as $c) {
            if (ocNormaliseName($c['customer_name']) === $n) return $c;
        }
        return null;
    }
}

if (!function_exists('ocCandidatesByPhone')) {
    /** Active customers whose stored phone is the same number (any format). */
    function ocCandidatesByPhone(PDO $pdo, string $normalisedPhone): array
    {
        if (strlen($normalisedPhone) < 7) return [];
        // Narrow in SQL by the last 7 digits, then compare properly in PHP.
        $tail = substr($normalisedPhone, -7);
        $st = $pdo->prepare("SELECT customer_id, customer_code, customer_name, phone FROM customers
                              WHERE status <> 'deleted' AND phone IS NOT NULL
                                AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', ''), '(', ''), ')', '') LIKE ?
                              ORDER BY customer_id LIMIT 200");
        $st->execute(['%' . $tail]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (ocNormalisePhone((string)$r['phone']) === $normalisedPhone) $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('ocPhoneHint')) {
    /**
     * Staff hint for the Accept screen: customers already using this phone under a
     * DIFFERENT name (a typo would otherwise make a duplicate). Empty when none, or when
     * an exact match exists (that one is reused automatically).
     * @return array<int, array{customer_id:int, customer_name:string, customer_code:string}>
     */
    function ocPhoneHint(PDO $pdo, string $name, string $phone): array
    {
        $p = ocNormalisePhone($phone);
        if ($p === '' || ocFindExact($pdo, $name, $phone)) return [];
        return array_map(fn($c) => ['customer_id' => (int)$c['customer_id'], 'customer_name' => (string)$c['customer_name'],
                                    'customer_code' => (string)$c['customer_code']], array_slice(ocCandidatesByPhone($pdo, $p), 0, 5));
    }
}

if (!function_exists('ocFindOrCreate')) {
    /**
     * Customer for an accepted online order. $linkTo (staff chose "link to this customer"
     * from the hint) must be one of the customers already using this phone.
     * @return array{customer_id:int, created:bool, customer_name:string}
     * @throws RuntimeException when $linkTo is not a valid choice
     */
    function ocFindOrCreate(PDO $pdo, string $name, string $phone, int $userId, int $linkTo = 0): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($linkTo > 0) {
            foreach (ocCandidatesByPhone($pdo, ocNormalisePhone($phone)) as $c) {
                if ((int)$c['customer_id'] === $linkTo) return ['customer_id' => $linkTo, 'created' => false, 'customer_name' => (string)$c['customer_name']];
            }
            throw new RuntimeException('That customer does not use this phone number.');
        }
        $hit = ocFindExact($pdo, $name, $phone);
        if ($hit) return ['customer_id' => (int)$hit['customer_id'], 'created' => false, 'customer_name' => (string)$hit['customer_name']];

        require_once __DIR__ . '/code_generator.php';
        $code = nextCode($pdo, 'CUST');
        $pdo->prepare("INSERT INTO customers (customer_code, customer_name, phone, customer_type, status, created_at, created_by)
                       VALUES (?, ?, ?, 'individual', 'active', NOW(), ?)")
            ->execute([$code, mb_substr($name, 0, 191), $phone !== '' ? mb_substr($phone, 0, 50) : null, $userId]);
        return ['customer_id' => (int)$pdo->lastInsertId(), 'created' => true, 'customer_name' => $name];
    }
}
