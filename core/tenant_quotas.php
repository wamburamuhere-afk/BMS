<?php
/**
 * core/tenant_quotas.php
 * -----------------------
 * How many active staff accounts, and how much uploaded storage, one tenant
 * may use (ternant.md Phase 12).
 *
 * WHY THIS IS TWO PLAIN COLUMNS, NOT A SECOND FEATURES/TENANT_FEATURES PAIR.
 * Phase 11's entitlements are an open-ended catalogue of independent toggles —
 * that shape earned two tables. A quota is just two numbers per tenant
 * (`tenants.max_users`, `tenants.max_storage_mb`), so it gets two columns.
 * `NULL` means unlimited — never a magic `-1` to misremember.
 *
 * WHY STORAGE IS RECOMPUTED LIVE, NOT TRACKED WITH A RUNNING COUNTER.
 * An upload attempt is rare — nothing like a per-request cost — so the extra
 * query cost of summing on the spot is irrelevant. A live sum can never drift;
 * a maintained counter silently can, the day one of the 56+ upload call sites
 * (ternant.md Phase 12 finding #4) forgets to update it. Correctness over a
 * speed nobody needs here.
 *
 * WHY THE SUM IS 17 SEPARATE QUERIES, NOT ONE UNION ALL.
 * `SELECT SUM(x) FROM a UNION ALL SELECT SUM(x) FROM b` fails as ONE statement
 * the moment either table has a problem — a table dropped or renamed by a
 * future migration would take the whole total down with it. Fail-open has to
 * mean "one bad term degrades to zero", which only independent queries, each
 * in their own try/catch, actually deliver.
 *
 * WHY NO NEW QUERY IS NEEDED TO REACH max_users/max_storage_mb AT ALL.
 * `resolveTenantFromRequest()` (core/tenant_resolver.php) already runs
 * `SELECT * FROM tenants ...`, so the two new columns arrive inside
 * `bmsCurrentTenant()` for free the moment they exist in the schema.
 *
 * Public API:
 *   tenantUserLimit(): ?int
 *   tenantStorageLimitMb(): ?int
 *   tenantStorageLimitBytes(): ?int
 *   tenantActiveUserCount(PDO $pdo): int
 *   tenantStorageUsedBytes(PDO $pdo): int
 *   tenantWithinUserLimit(PDO $pdo): bool
 *   tenantWithinStorageLimit(PDO $pdo, int $incomingBytes): bool
 */

require_once __DIR__ . '/tenant_bootstrap.php';

if (!function_exists('tenantUserLimit')) {
    /** Max ACTIVE staff accounts this tenant may have, or null = unlimited. */
    function tenantUserLimit(): ?int
    {
        $t = bmsCurrentTenant();
        if ($t === null || !array_key_exists('max_users', $t) || $t['max_users'] === null) {
            return null;
        }
        return (int)$t['max_users'];
    }
}

if (!function_exists('tenantStorageLimitMb')) {
    /** Max uploaded storage in MB this tenant may use, or null = unlimited. */
    function tenantStorageLimitMb(): ?int
    {
        $t = bmsCurrentTenant();
        if ($t === null || !array_key_exists('max_storage_mb', $t) || $t['max_storage_mb'] === null) {
            return null;
        }
        return (int)$t['max_storage_mb'];
    }
}

if (!function_exists('tenantStorageLimitBytes')) {
    /** tenantStorageLimitMb() converted to bytes, or null = unlimited. */
    function tenantStorageLimitBytes(): ?int
    {
        $mb = tenantStorageLimitMb();
        return $mb === null ? null : $mb * 1024 * 1024;
    }
}

if (!function_exists('tenantActiveUserCount')) {
    /**
     * How many ACTIVE staff accounts this tenant currently has.
     *
     * Deactivated accounts (`is_active = 0`) do not count — `users.php` already
     * has an activate/deactivate toggle, so "deactivate someone to free a seat"
     * is an existing capability this quota rides on top of, the same way
     * Slack/Google Workspace/GitHub seat limits work.
     */
    function tenantActiveUserCount(PDO $pdo): int
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    }
}

if (!function_exists('TENANT_STORAGE_TABLES')) {
    /**
     * Every table confirmed to track `file_size` at upload time — confirmed by
     * parsing every CREATE TABLE in schema/tenant_schema_template.sql for the
     * column itself, not by name-matching "attachment"/"document" (ternant.md
     * Phase 12 finding #5). Reviewable in a PR diff, same discipline as
     * core/feature_registry.php's page_keys — not derived from a pattern that
     * could silently miss a table.
     *
     * Adding a new file-storing feature? Give its table a `file_size INT`
     * column at build time (`.claude/security.md` §19 already requires this
     * for every upload) and add its name here in the same PR.
     */
    function TENANT_STORAGE_TABLES(): array
    {
        return [
            'documents',
            'employee_documents',
            'purchase_order_attachments',
            'rfq_attachments',
            'do_attachments',
            'delivery_attachments',
            'sales_return_attachments',
            'credit_note_attachments',
            'debit_note_attachments',
            'customer_lpo_attachments',
            'purchase_receipt_attachments',
            'collateral_attachments',
            'compliance_documents',
            'inspection_attachments',
            'loan_documents',
            'payment_attachments',
            'project_progress_report_attachments',
            // Backfilled in migrations/tenant/2026_09_04_backfill_file_size_columns.php
            // (Phase 12 finding #6) — these five stored files but never recorded a
            // size until that migration ran.
            'customer_attachments',
            'document_templates',
            'project_scope_documents',
            'user_signatures',
            'compliance_records',
        ];
    }
}

if (!function_exists('tenantStorageUsedBytes')) {
    /**
     * Total bytes this tenant currently has stored, summed across every table
     * in TENANT_STORAGE_TABLES().
     *
     * Run on the tenant's OWN connection — because BMS is database-per-tenant,
     * that alone is what scopes this correctly to one company. No
     * `WHERE tenant_id = ?` needed, unlike a shared-database system.
     *
     * Fails open PER TABLE: a table that is later dropped, renamed, or loses
     * its `file_size` column contributes 0 rather than throwing — proven by
     * Phase 12.A's acceptance gate, which drops a table on a throwaway tenant
     * and asserts the total goes down, not that the whole call fails.
     */
    function tenantStorageUsedBytes(PDO $pdo): int
    {
        $total = 0;
        foreach (TENANT_STORAGE_TABLES() as $table) {
            try {
                // Table name comes only from the curated list above — never
                // request input — so interpolating it into the identifier
                // position is safe; PDO cannot parameterise a table name.
                $sum = $pdo->query("SELECT SUM(file_size) FROM `{$table}`")->fetchColumn();
                $total += (int)($sum ?? 0);
            } catch (Throwable $e) {
                error_log("tenantStorageUsedBytes: skipping '{$table}' — " . $e->getMessage());
            }
        }
        return $total;
    }
}

if (!function_exists('tenantWithinUserLimit')) {
    /** Is there room for one more ACTIVE user right now? Unlimited = always true. */
    function tenantWithinUserLimit(PDO $pdo): bool
    {
        $limit = tenantUserLimit();
        if ($limit === null) return true;
        return tenantActiveUserCount($pdo) < $limit;
    }
}

if (!function_exists('tenantWithinStorageLimit')) {
    /**
     * Would adding $incomingBytes more keep this tenant AT OR UNDER its limit?
     * Unlimited = always true.
     */
    function tenantWithinStorageLimit(PDO $pdo, int $incomingBytes): bool
    {
        $limitBytes = tenantStorageLimitBytes();
        if ($limitBytes === null) return true;
        return (tenantStorageUsedBytes($pdo) + $incomingBytes) <= $limitBytes;
    }
}

if (!function_exists('assertUploadWithinQuota')) {
    /**
     * The one function every upload handler calls (Phase 12.B) — sits at the
     * exact point `.claude/security.md` §19's own 5-step pattern already sits,
     * right before `move_uploaded_file()`. Refuses and ends the request when
     * accepting $incomingBytes more would push the tenant over its storage
     * limit; a no-op otherwise, including when no tenant is resolved at all
     * (single-tenant/legacy) or the tenant is unlimited.
     *
     * Response shape matches the four checks already standard in every upload
     * handler (extension, MIME, size, filename) — a plain JSON body with a
     * 422, not a bespoke shape a caller would have to special-case.
     */
    function assertUploadWithinQuota(PDO $pdo, int $incomingBytes): void
    {
        if (!function_exists('bmsCurrentTenant') || bmsCurrentTenant() === null) return;
        if (tenantWithinStorageLimit($pdo, $incomingBytes)) return;

        if (!headers_sent()) {
            http_response_code(422);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Storage limit exceeded. Please delete files or ask the platform to raise your limit.',
        ]);
        exit;
    }
}
