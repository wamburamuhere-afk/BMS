<?php
/**
 * Three-Approval Workflow Helper
 * ------------------------------
 * Shared guards + helpers for the pending → reviewed → approved chain.
 * See three_approval.md for the full spec.
 */

if (!function_exists('canEditDocument')) {
    /**
     * Edit/Delete allowed when:
     *   - status is NOT 'approved', OR
     *   - the current user is admin
     */
    function canEditDocument($status, $isAdmin)
    {
        if ($isAdmin) return true;
        return $status !== 'approved';
    }
}

if (!function_exists('assertReviewable')) {
    /** Throws unless current status is 'pending' (or 'draft' as a pre-step). */
    function assertReviewable($currentStatus)
    {
        $allowed = ['pending', 'draft'];
        if (!in_array($currentStatus, $allowed, true)) {
            throw new Exception("Only a pending or draft document can be sent for review (current: " . $currentStatus . ").");
        }
    }
}

if (!function_exists('assertApprovable')) {
    /** Throws unless current status is 'reviewed'. */
    function assertApprovable($currentStatus)
    {
        if ($currentStatus !== 'reviewed') {
            throw new Exception("Only a reviewed document can be approved (current: " . $currentStatus . ").");
        }
    }
}

if (!function_exists('assertConvertible')) {
    /** Throws unless current status is 'approved' — used by every convert/refer endpoint. */
    function assertConvertible($currentStatus)
    {
        if ($currentStatus !== 'approved') {
            throw new Exception("Only an approved document can be referred or converted (current: " . $currentStatus . ").");
        }
    }
}

if (!function_exists('workflowActorSnapshot')) {
    /**
     * Snapshot of the current logged-in user for *_by_name / *_by_role columns.
     * Returns ['name' => ..., 'role' => ...].
     */
    function workflowActorSnapshot()
    {
        $name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        if ($name === '') $name = $_SESSION['username'] ?? 'System';
        $role = $_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'Staff');
        return ['name' => $name, 'role' => $role];
    }
}

if (!function_exists('workflowCaptureSignature')) {
    /**
     * Record the current user's e-signature against a workflow action.
     *
     * Inserts (or updates on duplicate) a row in workflow_signatures.
     * If the user has no active signature, sig_path is stored as NULL —
     * the action still succeeds; only the visual is missing.
     *
     * Returns ['sig_path' => string|null, 'has_signature' => bool].
     */
    function workflowCaptureSignature(
        PDO    $pdo,
        string $entityType,
        int    $entityId,
        string $action,   // 'created' | 'reviewed' | 'approved'
        int    $userId,
        string $userName,
        string $userRole
    ): array {
        // Fetch newest active signature for this user
        $sig = $pdo->prepare(
            "SELECT file_path FROM user_signatures
             WHERE user_id = ?
             ORDER BY updated_at DESC, id DESC LIMIT 1"
        );
        $sig->execute([$userId]);
        $sigPath = $sig->fetchColumn() ?: null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        // INSERT … ON DUPLICATE KEY UPDATE so re-runs (e.g. status reset) overwrite cleanly
        $pdo->prepare(
            "INSERT INTO workflow_signatures
                (entity_type, entity_id, action, user_id, user_name, user_role, sig_path, ip_address, consent_accepted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                user_id          = VALUES(user_id),
                user_name        = VALUES(user_name),
                user_role        = VALUES(user_role),
                sig_path         = VALUES(sig_path),
                signed_at        = CURRENT_TIMESTAMP,
                ip_address       = VALUES(ip_address)"
        )->execute([$entityType, $entityId, $action, $userId, $userName, $userRole, $sigPath, $ip]);

        return ['sig_path' => $sigPath, 'has_signature' => ($sigPath !== null)];
    }
}

if (!function_exists('getWorkflowSignatures')) {
    /**
     * Return the captured signature rows for a given document.
     * Keyed by action: ['created' => [...], 'reviewed' => [...], 'approved' => [...]].
     * Missing actions return an empty array with null sig_path.
     */
    function getWorkflowSignatures(PDO $pdo, string $entityType, int $entityId): array
    {
        $blank = ['user_name' => '', 'user_role' => '', 'sig_path' => null, 'signed_at' => null];
        $result = ['created' => $blank, 'reviewed' => $blank, 'approved' => $blank];

        $stmt = $pdo->prepare(
            "SELECT action, user_name, user_role, sig_path, signed_at
             FROM workflow_signatures
             WHERE entity_type = ? AND entity_id = ?"
        );
        $stmt->execute([$entityType, $entityId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['action']] = $row;
        }
        return $result;
    }
}

if (!function_exists('statusBadgeClass')) {
    /** Returns the CSS class used by the existing status-badge styles in quotations.php / etc. */
    function statusBadgeClass($status)
    {
        $map = [
            'pending'   => 'status-pending',
            'draft'     => 'status-pending',
            'reviewed'  => 'status-reviewed',
            'approved'  => 'status-approved',
            'rejected'  => 'status-rejected',
            'cancelled' => 'status-cancelled',
        ];
        return $map[$status] ?? 'status-secondary';
    }
}
