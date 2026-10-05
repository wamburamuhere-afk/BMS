<?php
/**
 * core/account_recovery_schema.php — everything account recovery needs inside a
 * TENANT database. Shared by the tenant migration and by the runtime so both
 * agree on exactly one definition. Idempotent (no DDL inside a transaction).
 *
 * WHY THIS EXISTS AT ALL. Before this file the system had a "Forgot password?"
 * link on every tenant's login page (login.php) pointing at a forgot-password.php
 * that was never written — a 404 behind a visible button. There was no reset
 * token anywhere, and app/constant/profile/profile.php can only change a password
 * for someone who still knows the current one. An admin who forgot theirs was
 * locked out for good, and the panel could not help: tenantUserDirectory() is
 * read-only and does not even return the username.
 *
 * THE DATA PROBLEM THIS HAS TO CLEAN UP FIRST. provisionTenant() wrote the owner
 * row as:
 *
 *     INSERT INTO users (username, password, email, ...) VALUES (?,?,'', ...)
 *                        ^^^^^^^^                        ^^
 *                        the contact                     ALWAYS EMPTY
 *
 * so the address a reset would have to be sent to was never in `users.email`. It
 * landed in `users.username` instead — and which KIND of contact that is depends
 * on who created the tenant:
 *
 *   - public self-registration (core/tenant_registration.php) passes the PHONE
 *     as provisionTenant()'s $ownerEmail argument, so username = a phone number;
 *   - the superadmin panel (createTenantAsOperator) passes the real email, so
 *     username = an email address.
 *
 * backfillOwnerContacts() below therefore sorts each username by what it
 * actually IS, never by how the row was created, and writes it to the matching
 * column. What it will NOT do is invent an address: a tenant whose admin has no
 * email on file stays without one, and is surfaced in the superadmin panel so an
 * operator sets it deliberately. Quietly promoting settings.company_email into a
 * password-reset destination would hand account recovery to whoever owns that
 * address — often the company's accountant, not the admin.
 */

if (!function_exists('accountRecoveryEnsureSchema')) {
    /**
     * Create/patch the recovery schema in one tenant database.
     *
     * @return array<int,string> Human-readable notes about what changed, plus any
     *                           condition an operator has to resolve by hand.
     */
    function accountRecoveryEnsureSchema(PDO $pdo): array
    {
        $notes = [];

        // ── Reset tokens ─────────────────────────────────────────────────────
        // token_hash, never the token. A reset link is a bearer credential: if
        // this table leaked with raw tokens in it, every unexpired row would be
        // a live login. We store SHA-256 and compare hashes, so a leaked dump is
        // worthless. UNIQUE on it also makes "use exactly once" enforceable.
        //
        // `channel` exists from day one although only 'email' can be sent today
        // (there is no SMS gateway in this codebase — api/test_sms_config.php is
        // a stub with no HTTP call in it). In Tanzania a phone reaches an SME
        // owner more reliably than email does, so the column is here to let SMS
        // be added later without a second table and a migration of live tokens.
        //
        // `kind` carries both flows on one table: 'self_service' (the tenant
        // asked) and 'operator_otp' (a superadmin issued a one-time code). They
        // share issue/verify/expire/single-use logic exactly; splitting them
        // would mean maintaining that logic twice and having it drift.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_resets` (
                `reset_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     INT NOT NULL,
                `token_hash`  CHAR(64) NOT NULL,
                `channel`     ENUM('email','sms') NOT NULL DEFAULT 'email',
                `destination` VARCHAR(191) NOT NULL DEFAULT '',
                `kind`        ENUM('self_service','operator_otp') NOT NULL DEFAULT 'self_service',
                `issued_by`   INT NULL DEFAULT NULL,
                `expires_at`  DATETIME NOT NULL,
                `used_at`     DATETIME NULL DEFAULT NULL,
                `request_ip`  VARCHAR(45) NOT NULL DEFAULT '',
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`reset_id`),
                UNIQUE KEY `uq_pr_token` (`token_hash`),
                KEY `idx_pr_user` (`user_id`, `created_at`),
                KEY `idx_pr_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── Throttle ledger ──────────────────────────────────────────────────
        // One row per attempt, by account and by IP. Rate limiting cannot live
        // in the session: the whole point is to stop someone who is not signed
        // in and who throws away their cookie between tries.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_reset_attempts` (
                `attempt_id`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `identifier`  VARCHAR(191) NOT NULL DEFAULT '',
                `request_ip`  VARCHAR(45) NOT NULL DEFAULT '',
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`attempt_id`),
                KEY `idx_pra_identifier` (`identifier`, `created_at`),
                KEY `idx_pra_ip` (`request_ip`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── users columns ────────────────────────────────────────────────────
        $have = array_flip($pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN));

        foreach ([
            // Set when a superadmin issues a one-time code, so the first thing
            // the admin does after using it is choose a password only they know.
            // Without this the operator's temporary secret stays valid forever.
            'must_change_password' => "ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0",
            // NULL = the address is on file but nobody has proved they can read
            // it. Changing an address re-sets this to NULL; see §Phase 1.
            'email_verified_at'    => "ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT NULL",
        ] as $col => $ddl) {
            if (!isset($have[$col])) {
                $pdo->exec("ALTER TABLE `users` $ddl");
                $notes[] = "users.$col added";
            }
        }

        // Recovery looks accounts up by email and by phone on every request;
        // without these it is a full scan of the users table each time.
        foreach (['idx_users_email' => '`email`', 'idx_users_phone' => '`phone`'] as $name => $cols) {
            $exists = $pdo->query("SHOW INDEX FROM `users` WHERE Key_name = " . $pdo->quote($name))->fetch();
            if (!$exists) {
                $pdo->exec("ALTER TABLE `users` ADD KEY `$name` ($cols)");
                $notes[] = "users.$name index added";
            }
        }

        $notes = array_merge($notes, backfillOwnerContacts($pdo));
        $notes = array_merge($notes, ensureUsernameUnique($pdo));

        return $notes;
    }
}

if (!function_exists('backfillOwnerContacts')) {
    /**
     * Recover each account's real contact details out of `username`.
     *
     * Only ever fills a column that is empty — it never overwrites a contact
     * somebody has already set, so re-running is safe and an operator's manual
     * correction always wins over this guess.
     *
     * @return array<int,string>
     */
    function backfillOwnerContacts(PDO $pdo): array
    {
        $notes = [];

        // username that IS an email → that is the account's email.
        $st = $pdo->query("
            SELECT `user_id`, `username` FROM `users`
             WHERE (`email` IS NULL OR `email` = '')
               AND `username` IS NOT NULL AND `username` <> ''
        ");
        $emailFixed = 0;
        $phoneFixed = 0;
        $upEmail = $pdo->prepare("UPDATE `users` SET `email` = ? WHERE `user_id` = ?");
        $upPhone = $pdo->prepare("
            UPDATE `users` SET `phone` = ?
             WHERE `user_id` = ? AND (`phone` IS NULL OR `phone` = '')
        ");

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $u = trim((string)$row['username']);
            if (filter_var($u, FILTER_VALIDATE_EMAIL)) {
                $upEmail->execute([$u, (int)$row['user_id']]);
                $emailFixed++;
            } elseif (preg_match('/^\+?[0-9]{7,15}$/', $u)) {
                // A self-registered owner: username is the phone they signed up
                // with. Recover it as a phone — NOT as an email. Leaving email
                // empty here is the correct outcome: this admin genuinely has no
                // reset address yet, and the panel will say so.
                $upPhone->execute([$u, (int)$row['user_id']]);
                $phoneFixed++;
            }
        }

        if ($emailFixed) $notes[] = "recovered email for {$emailFixed} account(s) from username";
        if ($phoneFixed) $notes[] = "recovered phone for {$phoneFixed} account(s) from username";

        // Report, do not fix: an admin with no address cannot be given one
        // safely from here. accountsWithoutRecoveryContact() is what the
        // superadmin panel calls to show the same warning.
        $stranded = (int)$pdo->query("
            SELECT COUNT(*) FROM `users`
             WHERE `is_admin` = 1 AND `is_active` = 1
               AND (`email` IS NULL OR `email` = '')
        ")->fetchColumn();
        if ($stranded > 0) {
            $notes[] = "WARNING: {$stranded} active admin account(s) have no email — "
                     . "they cannot reset their own password until an operator sets one";
        }

        return $notes;
    }
}

if (!function_exists('ensureUsernameUnique')) {
    /**
     * Add the UNIQUE key `users.username` never had.
     *
     * The table shipped with `PRIMARY KEY (user_id)` as its ONLY key, so two
     * accounts could share a username — and the login query takes the first
     * match, meaning one of them could never sign in and a password reset could
     * not tell which account was meant.
     *
     * If duplicates already exist this REPORTS them and leaves the index off.
     * It does not rename or deactivate anybody: a migration that silently
     * rewrites a live account's login name to satisfy a constraint is worse
     * than the constraint being absent, and deploy runs with script_stop: true,
     * so throwing here would halt an otherwise good deploy over data that needs
     * a human decision.
     *
     * @return array<int,string>
     */
    function ensureUsernameUnique(PDO $pdo): array
    {
        $exists = $pdo->query("SHOW INDEX FROM `users` WHERE Key_name = 'uq_users_username'")->fetch();
        if ($exists) return [];

        $dupes = $pdo->query("
            SELECT `username`, COUNT(*) AS c
              FROM `users`
             WHERE `username` IS NOT NULL AND `username` <> ''
             GROUP BY `username` HAVING c > 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        if ($dupes) {
            $list = implode(', ', array_map(
                static fn($d) => $d['username'] . ' ×' . $d['c'],
                array_slice($dupes, 0, 10)
            ));
            return ["WARNING: username is not UNIQUE — duplicates must be resolved by hand: {$list}"];
        }

        $pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `uq_users_username` (`username`)");
        return ['users.username is now UNIQUE'];
    }
}

if (!function_exists('accountsWithoutRecoveryContact')) {
    /**
     * Active admin accounts that have no email to send a reset to.
     *
     * The superadmin panel shows this so an operator can fix it BEFORE the
     * admin is locked out, rather than discovering it during the emergency.
     *
     * @return array<int,array{user_id:int,username:string,phone:string}>
     */
    function accountsWithoutRecoveryContact(PDO $pdo): array
    {
        $rows = $pdo->query("
            SELECT `user_id`, COALESCE(`username`,'') AS username, COALESCE(`phone`,'') AS phone
              FROM `users`
             WHERE `is_admin` = 1 AND `is_active` = 1
               AND (`email` IS NULL OR `email` = '')
             ORDER BY `user_id`
        ")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn($r) => [
            'user_id'  => (int)$r['user_id'],
            'username' => (string)$r['username'],
            'phone'    => (string)$r['phone'],
        ], $rows);
    }
}
