<?php
/**
 * core/activity_log_helpers.php
 * ------------------------------
 * Single source of truth for classifying activity_logs rows into the six
 * canonical audit verbs (View/Create/Edit/Delete/Review/Approve), and for the
 * View-streak dedup rule. Shared by app/activity_log.php (page filter + stat
 * cards) and api/user_activity_report.php (charts), so the numbers can never
 * drift between the two the way the "Viewed" stat card once did.
 */

if (!function_exists('activityTypeMap')) {
    /** Canonical verb => legacy/inconsistent action-text variants it absorbs. */
    function activityTypeMap(): array
    {
        return [
            'view'    => ['View', 'Viewed', 'page_view'],
            'create'  => ['Create', 'Created', 'Add', 'Added', 'Recorded'],
            'edit'    => ['Edit', 'Edited', 'Update', 'Updated', 'update_', 'Changed'],
            'delete'  => ['Delete', 'Deleted', 'Remove', 'Removed', 'Void', 'Voided'],
            'review'  => ['Review', 'Reviewed'],
            'approve' => ['Approve', 'Approved'],
        ];
    }
}

if (!function_exists('buildActivityTypeSql')) {
    /**
     * Build a "(action LIKE … OR description LIKE …)" fragment matching any of a
     * canonical type's verbs at the START of action OR description. '_' is
     * escaped so 'page_view' / 'update_' match literally (LIKE treats '_' as a
     * wildcard). $tag must be unique per use within the same query (bind params).
     * @return array [string $sqlFragment, array $params]
     */
    function buildActivityTypeSql(string $type, string $tag): array
    {
        $ors = []; $p = [];
        foreach ((activityTypeMap()[$type] ?? []) as $i => $verb) {
            $k = ":{$tag}{$i}";
            $ors[] = "action LIKE $k OR description LIKE $k";
            $p[$k] = str_replace('_', '\\_', $verb) . '%';
        }
        return [$ors ? '(' . implode(' OR ', $ors) . ')' : '1=0', $p];
    }
}

if (!function_exists('activityViewDedupExclusion')) {
    /**
     * SQL fragment excluding a row when it is a View-type action AND the
     * immediately preceding action (same user, chronological — via a
     * `LAG(action) OVER (PARTITION BY user_id ORDER BY created_at, id) AS
     * prev_action` column the caller must select) was ALSO View-type. Collapses
     * a consecutive same-user View streak down to the first of each run, so
     * rapid page-browsing doesn't inflate a View count far past what a human
     * would consider "distinct view events."
     */
    function activityViewDedupExclusion(): string
    {
        return "NOT (
            (action LIKE 'View %' OR action LIKE 'Viewed %' OR action = 'page_view')
            AND (prev_action LIKE 'View %' OR prev_action LIKE 'Viewed %' OR prev_action = 'page_view')
        )";
    }
}
