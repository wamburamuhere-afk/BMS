<?php
/**
 * core/totp.php — RFC 6238 time-based one-time passwords.
 *
 * Written here rather than pulled in: this project has no composer vendor
 * directory, and the whole algorithm is an HMAC, a truncation and some base32.
 * Adding a dependency manager to the deploy for sixty lines of arithmetic
 * would be the larger change, and a riskier one.
 *
 * Interoperable with Google Authenticator, Authy, 1Password, Microsoft
 * Authenticator and anything else that reads an `otpauth://totp/` URI: SHA-1,
 * 6 digits, 30-second period. Those defaults are not a security choice, they
 * are a compatibility one — several popular apps silently ignore the algorithm
 * and digits parameters and assume exactly this.
 */

if (!function_exists('totpBase32Encode')) {
    /** RFC 4648 base32, no padding — the form authenticator apps expect. */
    function totpBase32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $bits = '';
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }
}

if (!function_exists('totpBase32Decode')) {
    /** @return string|null Raw bytes, or null if the input is not valid base32. */
    function totpBase32Decode(string $b32): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        // People type secrets with spaces and lower case; padding is optional.
        $b32 = strtoupper(preg_replace('/[\s=]/', '', $b32) ?? '');
        if ($b32 === '' || preg_match('/[^A-Z2-7]/', $b32)) return null;

        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $v = strpos($alphabet, $b32[$i]);
            if ($v === false) return null;
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }
}

if (!function_exists('totpGenerateSecret')) {
    /**
     * A fresh shared secret. 20 bytes (160 bits) is what RFC 4226 recommends
     * for SHA-1 HMAC and what authenticator apps are built around.
     */
    function totpGenerateSecret(): string
    {
        return totpBase32Encode(random_bytes(20));
    }
}

if (!function_exists('totpCodeAt')) {
    /**
     * The 6-digit code for one 30-second counter step.
     *
     * @return string|null null when the secret is not valid base32.
     */
    function totpCodeAt(string $base32Secret, int $counter, int $digits = 6): ?string
    {
        $key = totpBase32Decode($base32Secret);
        if ($key === null || $key === '') return null;

        // 8-byte big-endian counter. pack('J') would need 64-bit PHP; building
        // it by hand works everywhere and is just as clear.
        $bin = '';
        for ($i = 7; $i >= 0; $i--) {
            $bin = chr($counter & 0xFF) . $bin;
            $counter >>= 8;
        }

        $hash   = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $part   = ((ord($hash[$offset])     & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                |  (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string)($part % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('totpCounterNow')) {
    function totpCounterNow(?int $timestamp = null, int $period = 30): int
    {
        return (int)floor((($timestamp ?? time())) / $period);
    }
}

if (!function_exists('totpVerify')) {
    /**
     * Check a typed code, allowing for clocks that disagree.
     *
     * Returns the COUNTER the code matched, not just true. The caller stores
     * it and refuses anything at or below it next time, so a code shoulder-
     * surfed or read off a screen cannot be replayed inside its own 30-second
     * window — which plain true/false verification would happily allow.
     *
     * $window = 1 accepts the previous, current and next step: 90 seconds of
     * tolerance, the usual trade-off between phone clock drift and exposure.
     *
     * hash_equals, not ===, because the comparison is against attacker-supplied
     * input and should not leak where it diverged through timing.
     *
     * @return int|null Matched counter, or null if nothing matched.
     */
    function totpVerify(string $base32Secret, string $code, int $window = 1, ?int $timestamp = null, int $period = 30): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) return null;

        $now = totpCounterNow($timestamp, $period);
        for ($i = -$window; $i <= $window; $i++) {
            $expected = totpCodeAt($base32Secret, $now + $i);
            if ($expected !== null && hash_equals($expected, $code)) {
                return $now + $i;
            }
        }
        return null;
    }
}

if (!function_exists('totpUri')) {
    /**
     * The `otpauth://totp/...` URI an authenticator app scans.
     *
     * The label is "Issuer:account" and the issuer is ALSO repeated as a query
     * parameter — apps disagree about which one they read, and getting it wrong
     * leaves the person with an unlabelled code they cannot tell apart from
     * their others.
     */
    function totpUri(string $base32Secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret'    => $base32Secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => 6,
            'period'    => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('totpFormatSecret')) {
    /** Grouped in fours, for someone typing it in by hand. */
    function totpFormatSecret(string $base32Secret): string
    {
        return trim(chunk_split($base32Secret, 4, ' '));
    }
}
