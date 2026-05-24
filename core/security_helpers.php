<?php
/**
 * BMS — Security Helpers (Phase 0 of the security_implementation_plan.md rollout)
 *
 * These helpers are deliberately thin wrappers around the existing functions
 * in helpers.php (logActivity) and core/permissions.php (canX / isAdmin /
 * autoEnforcePermission). The goal is to give the rest of the codebase a
 * single, consistent vocabulary for two concerns:
 *
 *   1. "I want to log a security-relevant event without worrying about
 *       duplicate rows when multiple code paths might fire for the same
 *       request." — use logSecure().
 *
 *   2. "I want to refuse a state-changing API call early when the caller
 *       doesn't have the right permission, with a uniform JSON 403
 *       response." — use assertCanCreate/Edit/Delete().
 *
 * This file does NOT replace any existing function. It is additive only.
 * Existing logActivity() / canEdit() / autoEnforcePermission() calls
 * continue to work exactly as before; this layer simply offers a more
 * ergonomic API for new code written from Phase 1 onward.
 *
 * Loaded automatically from core/permissions.php so it is available wherever
 * the permission system is already loaded.
 */

if (!function_exists('logSecure')) {
    /**
     * Log a security-relevant event to activity_logs, **once per request**
     * for the same action/description pair. Repeated calls in the same
     * request are deduplicated in-memory so a page that loads twice from
     * server-side includes doesn't write two identical rows.
     *
     * Behaves like a no-op when:
     *   - $pdo is unavailable (cron / CLI context)
     *   - there is no $_SESSION['user_id'] (request is unauthenticated)
     *   - logActivity() does not exist (helpers.php wasn't loaded yet)
     *
     * @param string      $action      Short verb, e.g. "Granted permission",
     *                                 "Deleted invoice".
     * @param string|null $description Optional richer detail, e.g.
     *                                 "User Mary granted can_delete on
     *                                 invoices to role Manager".
     * @return void
     */
    function logSecure(string $action, ?string $description = null): void
    {
        static $seen = [];

        if (!function_exists('logActivity')) return;
        if (empty($_SESSION['user_id']))      return;
        global $pdo;
        if (!$pdo instanceof PDO)             return;

        // Dedup key — same action + description in the same request is logged once.
        $key = $action . '|' . ($description ?? '');
        if (isset($seen[$key])) return;
        $seen[$key] = true;

        try {
            logActivity($pdo, (int)$_SESSION['user_id'], $action, $description);
        } catch (Throwable $e) {
            // Logging must never break the user's request. Swallow.
            error_log('logSecure failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('enforcePageOrAdmin')) {
    /**
     * Convenience wrapper around autoEnforcePermission() with a clearer
     * error path. Use at the very top of a page (before any output).
     *
     *   - Admin: passes through (isAdmin() == true).
     *   - Permission exists in DB + role has can_view: passes through.
     *   - Permission exists in DB + role lacks can_view: 403 → /unauthorized.
     *   - Permission key MISSING from the DB: writes a clear note to
     *     error_log so admins can spot the misconfiguration, then
     *     redirects to /unauthorized (default-deny).
     *
     * Phase 0 introduces this name; existing pages continue to use
     * autoEnforcePermission() unchanged.
     *
     * @param string $pageKey The page_key as stored in the permissions table.
     */
    function enforcePageOrAdmin(string $pageKey): void
    {
        // Admin bypass — never changes, even if the DB row is missing.
        if (function_exists('isAdmin') && isAdmin()) return;

        // Forward to the existing enforcement helper. If the key is missing
        // from the permissions table, canView() returns false and the
        // existing autoEnforcePermission() / requireViewPermission() path
        // redirects to /unauthorized. We add a server-side log so a
        // misconfiguration is observable.
        global $pdo;
        $rowExists = false;
        if ($pdo instanceof PDO) {
            try {
                $st = $pdo->prepare("SELECT 1 FROM permissions WHERE page_key = ? LIMIT 1");
                $st->execute([$pageKey]);
                $rowExists = (bool)$st->fetchColumn();
            } catch (Throwable $e) {
                // Fall through — keep behaviour identical to the legacy path.
            }
        }

        if (!$rowExists) {
            error_log("enforcePageOrAdmin: permission key '$pageKey' is NOT in the permissions table — admin must seed it via user_roles.php. Non-admin denied by default.");
        }

        if (function_exists('autoEnforcePermission')) {
            autoEnforcePermission($pageKey);
            return;
        }

        // Last-resort fallback: send to unauthorized.
        if (!headers_sent()) {
            $url = function_exists('getUrl') ? getUrl('unauthorized') : '/unauthorized';
            http_response_code(403);
            header('Location: ' . $url);
        }
        exit;
    }
}

if (!function_exists('assertCanCreate')) {
    /**
     * Pre-check for a state-changing API endpoint. Sends a uniform JSON
     * 403 response and exits if the caller cannot CREATE on $pageKey.
     *
     * Pattern in an API file:
     *
     *   require_once __DIR__ . '/../roots.php';
     *   if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
     *   assertCanCreate('invoices');     // ← stops the request here for non-admins without perm
     *   // ... proceed with INSERT ...
     */
    function assertCanCreate(string $pageKey): void
    {
        if (function_exists('canCreate') && canCreate($pageKey)) return;
        _assertDeny($pageKey, 'create');
    }
}

if (!function_exists('assertCanEdit')) {
    function assertCanEdit(string $pageKey): void
    {
        if (function_exists('canEdit') && canEdit($pageKey)) return;
        _assertDeny($pageKey, 'edit');
    }
}

if (!function_exists('assertCanDelete')) {
    function assertCanDelete(string $pageKey): void
    {
        if (function_exists('canDelete') && canDelete($pageKey)) return;
        _assertDeny($pageKey, 'delete');
    }
}

if (!function_exists('_assertDeny')) {
    /**
     * Internal — uniform JSON 403 response for the assertCanX helpers above.
     * Intentionally private (underscore-prefixed) and skipped if redeclared.
     */
    function _assertDeny(string $pageKey, string $verb): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            if (function_exists('header')) header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => "Access Denied: you do not have permission to $verb on '$pageKey'.",
        ]);
        exit;
    }
}
