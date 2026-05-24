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
