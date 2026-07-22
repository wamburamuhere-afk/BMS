<?php
// File: app/activity_log.php
require_once __DIR__ . '/../roots.php';

// Phase 2 of security_implementation_plan.md — anyone logged in could open
// this page before; now only admins or roles explicitly granted 'audit_logs'
// can see the system-wide activity log.
autoEnforcePermission('audit_logs');
$is_admin = isAdmin();

// Pagination setup
$limit = isset($_GET['limit']) ? ($_GET['limit'] === 'all' ? -1 : (int)$_GET['limit']) : 10;
if (!in_array($limit, [10, 25, 50, 100, -1])) $limit = 10; // Validate limit
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($limit === -1) ? 0 : ($page - 1) * $limit;

// ── Row formatter (audit_log.md §2/§3): turn a raw activity_logs row into the
//    smart Type + clear Description + Reference used by both the table and the
//    server-side DataTables endpoint. Single source of truth.
if (!function_exists('acFormatActivity')) {
    function acFormatActivity(array $activity): array {
        $canon = function (string $v): string {
            $v = strtolower(trim($v));
            if (strpos($v, 'update') === 0) return 'Edit';
            if (strpos($v, 'page_view') === 0) return 'View';
            static $m = [
                'view'=>'View','viewed'=>'View',
                'create'=>'Create','created'=>'Create','add'=>'Create','added'=>'Create','recorded'=>'Create',
                'edit'=>'Edit','edited'=>'Edit','update'=>'Edit','updated'=>'Edit','changed'=>'Edit',
                'delete'=>'Delete','deleted'=>'Delete','remove'=>'Delete','removed'=>'Delete','void'=>'Delete','voided'=>'Delete',
                'review'=>'Review','reviewed'=>'Review',
                'approve'=>'Approve','approved'=>'Approve',
            ];
            return $m[$v] ?? ucfirst($v);
        };
        $raw_action = trim($activity['raw_action'] ?? '');
        $raw_desc   = !empty($activity['raw_description']) ? trim($activity['raw_description']) : $raw_action;

        $verbSource = $raw_action !== '' ? $raw_action : $raw_desc;
        $awords     = preg_split('/\s+/', trim($verbSource));
        $firstWord  = $awords[0] ?? $verbSource;
        $verb       = $canon($firstWord);
        $isCanonical = in_array($verb, ['View','Create','Edit','Delete','Review','Approve'], true);

        $entity = '';
        // If the action is already a SHORT, clean "<verb> <entity>" (our standard,
        // e.g. "Delete sub-contractor payment"), take the entity straight from it so
        // multi-word entities show in full. Otherwise detect the entity by keyword.
        if ($isCanonical && count($awords) >= 2 && count($awords) <= 4) {
            $entity = ucwords(strtolower(trim(implode(' ', array_slice($awords, 1)))));
        } elseif (preg_match('/\b(sub-?contractor|purchase order|sales order|sales return|purchase return|credit note|debit note|bank transfer|payment voucher|customer|supplier|product|expense|invoice|payment|employee|payroll|loan|quotation|voucher|asset|budget|project|warehouse|stock|document template|document|user|role|report|category|tax|transaction|journal|grn|attendance|backup|held sale)\b/i', $raw_action . ' ' . $raw_desc, $em)) {
            $entity = strtolower($em[1]);
        }
        $type = trim($verb . ($entity !== '' ? ' ' . $entity : '')); if ($type === '') $type = 'Activity';
        $description = $raw_desc !== '' ? $raw_desc : '-';

        $reference = '-';
        if (preg_match('/([A-Z]{2,}-?[A-Z0-9-]*\d[A-Z0-9-]*)/i', $raw_desc, $rm) && preg_match('/\d/', strtoupper($rm[1]))) {
            $reference = strtoupper($rm[1]);
        }
        if ($reference === '-' && preg_match('/(?:#|ID:?\s*)(\d+)/i', $raw_desc, $rm)) $reference = '#' . $rm[1];
        if ($reference === '-') $reference = 'REF-' . str_pad((string)($activity['id'] ?? 0), 5, '0', STR_PAD_LEFT);

        return [
            'id' => $activity['id'] ?? 0, 'type' => $type, 'description' => $description,
            'timestamp' => $activity['timestamp'] ?? null, 'reference' => $reference,
            'user_name' => $activity['user_name'] ?? 'System',
        ];
    }
}
// Bootstrap class for a Type badge.
if (!function_exists('acBadgeClass')) {
    function acBadgeClass(string $type): string {
        $t = strtolower($type);
        if (strpos($t, 'delete') !== false) return 'danger';
        if (strpos($t, 'payment') !== false) return 'success';
        if (strpos($t, 'sale') !== false || strpos($t, 'pos') !== false) return 'info';
        if (strpos($t, 'customer') !== false) return 'warning';
        return 'primary';
    }
}

try {
// Get filter parameters
$type_filter = $_GET['type'] ?? '';
$user_id_filter = $_GET['user_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// ── Period is the authoritative date filter. It drives BOTH the activity table
//    and the summary-card scope + label. Default = today. 'custom' uses the
//    From/To inputs; everything else is computed server-side so client/server
//    can never drift. (audit_log.md §6)
$period = $_GET['period'] ?? 'today';
$period_labels = [
    'today' => 'Today', 'week' => 'This Week', 'month' => 'This Month',
    'year'  => 'This Year', 'all' => 'All Time', 'custom' => 'Selected Range',
];
if (!isset($period_labels[$period])) $period = 'today';
$today = date('Y-m-d');
switch ($period) {
    case 'today':  $date_from = $today; $date_to = $today; break;
    case 'week':   $date_from = date('Y-m-d', strtotime('monday this week')); $date_to = $today; break;
    case 'month':  $date_from = date('Y-m-01'); $date_to = $today; break;
    case 'year':   $date_from = date('Y-01-01'); $date_to = $today; break;
    case 'all':    $date_from = ''; $date_to = ''; break;
    case 'custom': /* keep the From/To inputs as submitted */ break;
}
$period_label = $period_labels[$period];

// Get users for filter dropdown
$users = $pdo->query("SELECT user_id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Get company info for print header
$company_name = get_setting('company_name', 'BMS Business Management');
$company_logo = get_setting('company_logo');
$company_address = get_setting('company_address', '');
$company_phone = get_setting('company_phone', '');
$company_email = get_setting('company_email', '');
$company_tin = get_setting('company_tin', '');
$company_vrn = get_setting('company_vrn', '');

    // Build filter conditions
    $conditions = [];
    $params = [];

    // ── Canonical audit activities (see audit_log.md). ONE shared map drives the
    //    filter, the summary cards AND the Type column, so they always agree.
    //    Each canonical verb absorbs the legacy/inconsistent variants in the data
    //    (e.g. page_view → View, update_* → Edit, Recorded → Create).
    $activity_type_map = [
        'view'    => ['View', 'Viewed', 'page_view'],
        'create'  => ['Create', 'Created', 'Add', 'Added', 'Recorded'],
        'edit'    => ['Edit', 'Edited', 'Update', 'Updated', 'update_', 'Changed'],
        'delete'  => ['Delete', 'Deleted', 'Remove', 'Removed', 'Void', 'Voided'],
        'review'  => ['Review', 'Reviewed'],
        'approve' => ['Approve', 'Approved'],
    ];

    // Build a "(action LIKE … OR description LIKE …)" fragment that matches any of a
    // canonical type's verbs at the START of action OR description. '_' is escaped
    // so 'page_view' / 'update_' match literally (LIKE treats '_' as a wildcard).
    $buildTypeSql = function (string $type, string $tag) use ($activity_type_map) {
        $ors = []; $p = [];
        foreach (($activity_type_map[$type] ?? []) as $i => $verb) {
            $k = ":{$tag}{$i}";
            $ors[] = "action LIKE $k OR description LIKE $k";
            $p[$k] = str_replace('_', '\\_', $verb) . '%';
        }
        return [$ors ? '(' . implode(' OR ', $ors) . ')' : '1=0', $p];
    };

    // Apply the Activity Type filter via the same map.
    if ($type_filter && isset($activity_type_map[$type_filter])) {
        [$frag, $fp] = $buildTypeSql($type_filter, 'ft_');
        $conditions[] = $frag;
        $params = array_merge($params, $fp);
    }
    if ($user_id_filter) {
        $conditions[] = "activity_logs.user_id = :user_id";
        $params[':user_id'] = $user_id_filter;
    }
    if ($date_from) {
        $conditions[] = "activity_logs.created_at >= :date_from";
        $params[':date_from'] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $conditions[] = "activity_logs.created_at <= :date_to";
        $params[':date_to'] = $date_to . ' 23:59:59';
    }

    // ══ Server-side DataTables endpoint ═══════════════════════════════════════
    // Uses a LAG-based dedup subquery so consecutive View events (same user) are
    // collapsed: only the first of a back-to-back run appears. Both the count and
    // the data query run over the same deduped subquery so pagination is accurate.
    if (isset($_GET['draw'])) {
        // ── Inner scope: user + date filters go INSIDE the subquery so LAG()
        //    sees prev_action correctly within the filtered window.
        $innerConds = []; $innerP = [];
        if ($user_id_filter) {
            $innerConds[] = 'al.user_id = :iuid';
            $innerP[':iuid'] = $user_id_filter;
        }
        if ($date_from) {
            $innerConds[] = 'al.created_at >= :idf';
            $innerP[':idf'] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $innerConds[] = 'al.created_at <= :idt';
            $innerP[':idt'] = $date_to . ' 23:59:59';
        }
        $innerWhere = $innerConds ? 'WHERE ' . implode(' AND ', $innerConds) : '';

        // Dedup subquery — LAG gives each row the previous action for that user.
        // Handles both old-style 'page_view' entries and new 'View X' entries.
        $dt_base = "FROM (
            SELECT al.id, al.action, al.description, al.created_at,
                   al.ip_address, u.username,
                   LAG(al.action) OVER (PARTITION BY al.user_id ORDER BY al.created_at, al.id) AS prev_action
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            $innerWhere
        ) _log";

        $dedup_cond = "NOT (
            (action LIKE 'View %' OR action LIKE 'Viewed %' OR action = 'page_view')
            AND (prev_action LIKE 'View %' OR prev_action LIKE 'Viewed %' OR prev_action = 'page_view')
        )";

        // ── Outer conditions: dedup + type filter (type goes outside so LAG is
        //    still computed over ALL types, not just the filtered one).
        $outerConds = [$dedup_cond]; $outerP = $innerP;
        if ($type_filter && isset($activity_type_map[$type_filter])) {
            [$frag, $fp] = $buildTypeSql($type_filter, 'dt_ft_');
            $outerConds[] = $frag;
            $outerP = array_merge($outerP, $fp);
        }
        $whereOuter = 'WHERE ' . implode(' AND ', $outerConds);

        // recordsTotal — deduped + type filter, no DT search.
        $rt = $pdo->prepare("SELECT COUNT(*) $dt_base $whereOuter");
        foreach ($outerP as $k => $v) $rt->bindValue($k, $v);
        $rt->execute();
        $recordsTotal = (int) $rt->fetchColumn();

        // recordsFiltered — add the DataTables search-box term.
        $searchVal = trim($_GET['search']['value'] ?? '');
        $dtConds = $outerConds; $dtP = $outerP;
        if ($searchVal !== '') {
            $dtConds[] = "(action LIKE :dts OR description LIKE :dts OR username LIKE :dts)";
            $dtP[':dts'] = '%' . $searchVal . '%';
        }
        $whereDt = 'WHERE ' . implode(' AND ', $dtConds);
        $rf = $pdo->prepare("SELECT COUNT(*) $dt_base $whereDt");
        foreach ($dtP as $k => $v) $rf->bindValue($k, $v);
        $rf->execute();
        $recordsFiltered = (int) $rf->fetchColumn();

        // Ordering — plain column names from the subquery (no table prefix).
        $dt_cols = [0 => 'id', 1 => 'created_at', 2 => 'action',
                    3 => 'description', 4 => 'ip_address', 5 => 'username'];
        $ocol = isset($_GET['order'][0]['column']) ? (int) $_GET['order'][0]['column'] : 1;
        $odir = (strtolower($_GET['order'][0]['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
        $orderBy = ($dt_cols[$ocol] ?? 'created_at') . ' ' . $odir;

        $dstart  = max(0, (int) ($_GET['start'] ?? 0));
        $dlength = (int) ($_GET['length'] ?? 10);
        if ($dlength < 0) $dlength = 100000000;

        $dsql = "SELECT id, action AS raw_action, description AS raw_description,
                        created_at AS timestamp, ip_address AS reference, username AS user_name
                 $dt_base $whereDt ORDER BY $orderBy LIMIT :dlen OFFSET :dstart";
        $ds = $pdo->prepare($dsql);
        foreach ($dtP as $k => $v) $ds->bindValue($k, $v);
        $ds->bindValue(':dlen', $dlength, PDO::PARAM_INT);
        $ds->bindValue(':dstart', $dstart, PDO::PARAM_INT);
        $ds->execute();

        $data = []; $sn = $dstart + 1;
        foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $f = acFormatActivity($r);
            $badge = acBadgeClass($f['type']);
            $ref = (string) $f['reference'];
            $refHtml = ($ref !== '' && strpos($ref, '.') === false && strpos($ref, ':') === false)
                ? '<code class="text-dark bg-light px-2 py-1 rounded">' . htmlspecialchars($ref) . '</code>'
                : '<span class="text-muted">-</span>';
            $data[] = [
                '<span class="text-muted">' . ($sn++) . '</span>',
                '<small class="text-muted text-nowrap">' . date('d/m/y, H:i', strtotime($f['timestamp'])) . '</small>',
                '<span class="badge bg-' . $badge . ' rounded-pill">' . htmlspecialchars($f['type']) . '</span>',
                htmlspecialchars($f['description']),
                $refHtml,
                htmlspecialchars($f['user_name']),
            ];
        }
        header('Content-Type: application/json');
        echo json_encode([
            'draw'            => (int) $_GET['draw'],
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
        exit;
    }

    // ── Summary cards — now reflect the ACTIVE filters (user + date range), NOT a
    //    fixed "today". The Type filter is intentionally excluded: the cards ARE
    //    the per-type breakdown. When no date is chosen, default to today.
    $scope_where = []; $scope_params = [];
    if ($user_id_filter) { $scope_where[] = "user_id = :su"; $scope_params[':su'] = $user_id_filter; }
    if ($date_from)      { $scope_where[] = "created_at >= :sdf"; $scope_params[':sdf'] = $date_from . ' 00:00:00'; }
    if ($date_to)        { $scope_where[] = "created_at <= :sdt"; $scope_params[':sdt'] = $date_to . ' 23:59:59'; }

    // 'viewed' is computed separately below (deduped) — created/edit/delete never
    // run in consecutive same-user streaks the way View does, so a plain count
    // already matches what the list shows for those three.
    $stat_cols = ['created' => 'create', 'updated' => 'edit', 'deleted' => 'delete'];
    $statSelects = []; $statParams = $scope_params;
    foreach ($stat_cols as $col => $type) {
        [$frag, $fp] = $buildTypeSql($type, "s_{$col}_");
        $statSelects[] = "COUNT(CASE WHEN $frag THEN 1 END) AS $col";
        $statParams = array_merge($statParams, $fp);
    }
    $stats_sql = "SELECT " . implode(", ", $statSelects) . " FROM activity_logs"
               . (!empty($scope_where) ? " WHERE " . implode(" AND ", $scope_where) : "");
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute($statParams);
    $today_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // 'viewed' card — MUST match what filtering the list to Type=View actually
    // shows. The list (server-side DataTables below) collapses consecutive
    // View-type entries by the same user down to the first of each run, via a
    // LAG() dedup, so a user rapidly browsing pages doesn't flood the log with
    // near-duplicate "Viewed X" rows. The card used to count every raw row with
    // no dedup at all, so it could read e.g. 85 while the filtered list showed
    // barely a dozen — same data, two different counting rules. This runs the
    // identical dedup the DataTables endpoint uses (same scope: user + date
    // range only, no type filter, so LAG sees the true previous action).
    [$viewFrag, $viewFragParams] = $buildTypeSql('view', 's_viewdedup_');
    $viewedWhere = $scope_where ? ('WHERE ' . implode(' AND ', $scope_where)) : '';
    $viewed_sql = "
        SELECT COUNT(*) FROM (
            SELECT action, description,
                   LAG(action) OVER (PARTITION BY user_id ORDER BY created_at, id) AS prev_action
            FROM activity_logs
            $viewedWhere
        ) v
        WHERE $viewFrag
          AND NOT (
              (action LIKE 'View %' OR action LIKE 'Viewed %' OR action = 'page_view')
              AND (prev_action LIKE 'View %' OR prev_action LIKE 'Viewed %' OR prev_action = 'page_view')
          )
    ";
    $viewed_stmt = $pdo->prepare($viewed_sql);
    $viewed_stmt->execute(array_merge($scope_params, $viewFragParams));
    $today_stats['viewed'] = (int) $viewed_stmt->fetchColumn();
    // Card label follows the chosen Period (Today / This Week / This Month / …).
    $stats_scope_label = $period_label;

    $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    // Use activity_logs table for comprehensive tracking
    $sql_base = "FROM activity_logs 
                 LEFT JOIN users u ON activity_logs.user_id = u.user_id";

    // Get total count with filters
    $count_stmt = $pdo->prepare("SELECT COUNT(*) $sql_base $where_clause");
    foreach ($params as $key => $val) { $count_stmt->bindValue($key, $val); }
    $count_stmt->execute();
    $total_items = $count_stmt->fetchColumn();
    $total_pages = ($limit === -1) ? 1 : ceil($total_items / $limit);

    // Get activities with filters
    $limit_sql = ($limit === -1) ? "" : " LIMIT :limit OFFSET :offset";
    $sql = "SELECT 
                activity_logs.id,
                activity_logs.action as raw_action,
                activity_logs.description as raw_description,
                activity_logs.created_at as timestamp,
                activity_logs.ip_address as reference,
                u.username as user_name,
                u.user_id as user_id,
                0 as amount
            $sql_base 
            $where_clause 
            ORDER BY activity_logs.created_at DESC" . $limit_sql;
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
    if ($limit !== -1) {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $raw_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalise any legacy/inconsistent verb to one of the six canonical audit
    // verbs (audit_log.md §1/§4) so the Type column reads short & smart, e.g.
    // "Delete invoice", "View customers". Non-core verbs (Login, Logout, …) pass
    // through unchanged so nothing is mislabelled.
    $canonVerb = function (string $v): string {
        $v = strtolower(trim($v));
        if (strpos($v, 'update') === 0) return 'Edit';
        if (strpos($v, 'page_view') === 0) return 'View';
        static $m = [
            'view'=>'View','viewed'=>'View',
            'create'=>'Create','created'=>'Create','add'=>'Create','added'=>'Create','recorded'=>'Create',
            'edit'=>'Edit','edited'=>'Edit','update'=>'Edit','updated'=>'Edit','changed'=>'Edit',
            'delete'=>'Delete','deleted'=>'Delete','remove'=>'Delete','removed'=>'Delete','void'=>'Delete','voided'=>'Delete',
            'review'=>'Review','reviewed'=>'Review',
            'approve'=>'Approve','approved'=>'Approve',
        ];
        return $m[$v] ?? ucfirst($v);
    };

    // Process activities to separate type and description properly
    $activities = [];
    foreach ($raw_activities as $activity) {
        $type = '';
        $description = '';

        // Get raw data - prefer description field, fallback to action if description is empty or null
        $raw_action = trim($activity['raw_action'] ?? '');
        $raw_desc   = !empty($activity['raw_description']) ? trim($activity['raw_description']) : $raw_action;

        // ── Smart short Type = "<Canonical verb> <entity>" (audit_log.md §2). ────
        $verbSource = $raw_action !== '' ? $raw_action : $raw_desc;
        $awords     = preg_split('/\s+/', trim($verbSource));
        $firstWord  = $awords[0] ?? $verbSource;
        $verb       = $canonVerb($firstWord);
        $isCanon    = in_array($verb, ['View','Create','Edit','Delete','Review','Approve'], true);

        $entity = '';
        // If the action is already a short "Verb Entity" phrase, use it directly.
        if ($isCanon && count($awords) >= 2 && count($awords) <= 4) {
            $entity = ucwords(strtolower(trim(implode(' ', array_slice($awords, 1)))));
        } elseif (preg_match('/\b(sub-?contractor|purchase order|sales order|sales return|purchase return|credit note|debit note|bank transfer|payment voucher|customer|supplier|product|expense|invoice|payment|employee|payroll|loan|quotation|voucher|asset|budget|project|warehouse|stock|document|user|role|report|category|tax|transaction|journal)\b/i', $raw_action . ' ' . $raw_desc, $em)) {
            $entity = ucwords(strtolower($em[1]));
        }

        $type = trim($verb . ($entity !== '' ? ' ' . $entity : ''));
        if ($type === '') $type = 'Activity';
        // Description column shows the full, human detail exactly as logged.
        $description = $raw_desc !== '' ? $raw_desc : '-';

        // --- STRICT REFERENCE LOGIC ---
        $reference = '-';
        // 1. Look for patterns that MUST contain at least one digit (e.g., INV-001, CUST-23)
        // This prevents capturing words like 'CREATED' or 'UPDATED'
        if (preg_match('/([A-Z]{2,}-?[A-Z0-9-]*\d[A-Z0-9-]*)/i', $raw_desc, $refMatches)) {
            $candidate = strtoupper($refMatches[1]);
            // Double check it's not just a word
            if (preg_match('/\d/', $candidate)) {
                $reference = $candidate;
            }
        } 
        
        // 2. If no code found, look for hashtag IDs or purely numeric IDs associated with the action
        if ($reference == '-' && preg_match('/(?:#|ID:?\s*)(\d+)/i', $raw_desc, $refMatches)) {
            $reference = '#' . $refMatches[1];
        }
        
        // 3. Fallback: Use a unique activity reference based on the log ID to ensure formality
        if ($reference == '-') {
            $reference = 'REF-' . str_pad($activity['id'], 5, '0', STR_PAD_LEFT);
        }
        
        $activities[] = [
            'id' => $activity['id'],
            'type' => $type,
            'description' => $description,
            'timestamp' => $activity['timestamp'],
            'reference' => $reference,
            'user_name' => $activity['user_name'],
            'user_id' => $activity['user_id'],
            'amount' => $activity['amount']
        ];
    }

    // The filter exposes only the six canonical activity types (keys of the map
    // defined above). value = key the WHERE understands; label = display text.
    $activity_types = [
        'view'    => 'View',
        'create'  => 'Create',
        'edit'    => 'Edit',
        'delete'  => 'Delete',
        'review'  => 'Review',
        'approve' => 'Approve',
    ];

    // ── "Time in system" summary — only when a single user is filtered. Honours
    //    the same date range as the feed. Powers the session panel below. ──────
    $session_summary = null;
    $session_rows = [];
    if ($user_id_filter && function_exists('userSessionSummary')) {
        require_once __DIR__ . '/../core/session_tracker.php';
        $sumFrom = $date_from ? $date_from . ' 00:00:00' : null;
        $sumTo   = $date_to   ? $date_to   . ' 23:59:59' : null;
        $session_summary = userSessionSummary($pdo, (int)$user_id_filter, $sumFrom, $sumTo);
        // Recent sessions for this user (newest first) for the audit detail list.
        try {
            $srWhere = "user_id = ?"; $srParams = [(int)$user_id_filter];
            if ($sumFrom) { $srWhere .= " AND login_at >= ?"; $srParams[] = $sumFrom; }
            if ($sumTo)   { $srWhere .= " AND login_at <= ?"; $srParams[] = $sumTo; }
            $srStmt = $pdo->prepare("SELECT login_at, logout_at, duration_seconds, logout_type, ip_address
                                       FROM user_sessions WHERE $srWhere ORDER BY login_at DESC LIMIT 15");
            $srStmt->execute($srParams);
            $session_rows = $srStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $session_rows = []; }
    }



} catch (PDOException $e) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $error = "Failed to load activities: " . $e->getMessage();
}

// Smart Pagination Helper
function renderSmartPagination($current, $total, $mode = 'html') {
    $html = '';
    $window = 2; // Pages around current to show

    // Prev button
    $prevDisabled = $current <= 1 ? ' disabled' : '';
    $prevClick    = $current <= 1 ? '' : "onclick=\"loadPage(" . ($current - 1) . ")\"";
    $html .= "<li class=\"page-item{$prevDisabled}\"><a class=\"page-link\" href=\"javascript:void(0)\" {$prevClick}><i class=\"bi bi-chevron-left\"></i></a></li>";

    $pagesToShow = [1];
    $rangeStart = max(2, $current - $window);
    $rangeEnd   = min($total - 1, $current + $window);
    for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
        $pagesToShow[] = $i;
    }
    if ($total > 1) $pagesToShow[] = $total;
    $pagesToShow = array_unique($pagesToShow);
    sort($pagesToShow);

    $prev = null;
    foreach ($pagesToShow as $p) {
        if ($prev !== null && $p - $prev > 1) {
            $html .= '<li class="page-item disabled"><span class="page-link px-2">…</span></li>';
        }
        $activeClass = ($p === $current) ? ' active' : '';
        $html .= "<li class=\"page-item{$activeClass}\"><a class=\"page-link\" href=\"javascript:void(0)\" onclick=\"loadPage({$p})\">{$p}</a></li>";
        $prev = $p;
    }

    // Next button
    $nextDisabled = $current >= $total ? ' disabled' : '';
    $nextClick    = $current >= $total ? '' : "onclick=\"loadPage(" . ($current + 1) . ")\"";
    $html .= "<li class=\"page-item{$nextDisabled}\"><a class=\"page-link\" href=\"javascript:void(0)\" {$nextClick}><i class=\"bi bi-chevron-right\"></i></a></li>";

    return $html;
}

// Handle AJAX Request - MUST be before header.php
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    ob_start();
    ?>
    <?php $si = $offset + 1; foreach ($activities as $activity): ?>
    <tr>
        <td class="text-center" data-label="S/NO"><?= $si++ ?></td>
        <td class="ps-3 text-muted" data-label="Time">
            <small><?= date('d/m/y, H:i', strtotime($activity['timestamp'])) ?></small>
        </td>
        <td class="col-type" data-label="Type">
            <?php 
            $badge_class = 'primary';
            $t = strtolower($activity['type']);
            if (strpos($t, 'payment') !== false) { $badge_class = 'success'; }
            elseif (strpos($t, 'sale') !== false || strpos($t, 'pos') !== false) { $badge_class = 'info'; }
            elseif (strpos($t, 'delete') !== false) { $badge_class = 'danger'; }
            elseif (strpos($t, 'customer') !== false) { $badge_class = 'warning'; }
            ?>
            <span class="badge bg-<?= $badge_class ?> rounded-pill">
                <?= ucfirst(str_replace('_', ' ', $activity['type'] ?? 'Unknown')) ?>
            </span>
        </td>
        <td data-label="Description"><?= htmlspecialchars($activity['description'] ?? '-') ?></td>
        <td data-label="Reference">
            <?php 
            $ref = (string)$activity['reference'];
            if (!empty($ref) && strpos($ref, '.') === false && strpos($ref, ':') === false) {
                echo '<code class="text-dark bg-light px-2 py-1 rounded">' . htmlspecialchars($ref) . '</code>';
            } else {
                echo '<span class="text-muted">-</span>';
            }
            ?>
        </td>
        <td class="pe-3" data-label="User"><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php
    $rows = ob_get_clean();
    
    ob_start();
    ?>
    <?php if ($total_pages > 1): ?>
    <ul class="pagination justify-content-center mb-0">
        <?php echo renderSmartPagination($page, $total_pages, 'ajax'); ?>
    </ul>
    <?php endif; ?>
    <?php
    $pagination = ob_get_clean();
    
    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'pagination' => $pagination,
        'info' => "Showing " . ($total_items > 0 ? $offset + 1 : 0) . " to " . ($limit === -1 ? $total_items : min($offset + $limit, $total_items)) . " of $total_items entries",
        'page' => $page,
        'total_pages' => $total_pages,
        // Summary cards reflect the active filter scope (audit_log.md §6).
        'stats' => [
            'created' => (int)($today_stats['created'] ?? 0),
            'viewed'  => (int)($today_stats['viewed'] ?? 0),
            'updated' => (int)($today_stats['updated'] ?? 0),
            'deleted' => (int)($today_stats['deleted'] ?? 0),
            'label'   => $stats_scope_label,
        ],
    ]);
    exit;
}

// Now include header after AJAX check
require_once ROOT_DIR . '/header.php';

$page_title = "Activity Log";
?>

<style>
/* Base Styles and Print Overrides */
.print-header { display: none; }

@media print {
    @page {
        margin: 10mm 8mm 16mm 8mm; /* canonical: top right bottom left */
    }

    /* AI print mode: show only the AI section, hide everything else in main-content */
    body.ai-printing .main-content > *:not(#aiPrintSection) { display: none !important; }
    body.ai-printing #aiPrintSection { display: block !important; }
    body.ai-printing #aiPrintSection .bms-print-header { display: block !important; }
    
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        padding-top: 0 !important;
        margin-top: 0 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .main-content {
        padding: 1.5cm !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .navbar, footer, .no-print, .pagination, .btn, .navbar-toggler, .dropdown-menu, .breadcrumb {
        display: none !important;
    }

    .print-header {
        display: block !important;
        margin-bottom: 25px;
        text-align: center;
        border-bottom: 4px double #0d6efd;
        padding-bottom: 15px;
    }

    .card {
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
    }

    .table {
        font-size: 9pt !important;
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        page-break-inside: auto;
    }

    .table thead {
        display: table-header-group !important;
    }

    .table tr {
        page-break-inside: avoid !important;
        page-break-after: auto !important;
    }

    .table th {
        background-color: #f8f9fa !important;
        color: #000 !important;
        border: 1px solid #dee2e6 !important;
        padding: 6px !important;
        text-align: center !important;
        -webkit-print-color-adjust: exact;
    }

    .table td {
        border: 1px solid #eee !important;
        padding: 6px !important;
        word-wrap: break-word !important;
        word-break: break-all !important;
        white-space: normal !important;
        overflow: visible !important; /* Ensure all text is seen */
        vertical-align: top !important;
    }

    .print-only-column {
        display: table-cell !important;
        width: 60px !important;
        text-align: center !important;
        white-space: nowrap !important; /* Keep S/NO in one row */
    }

    .col-type {
        white-space: normal !important;
        width: 120px !important;
        word-break: break-word !important; /* Wrap properly within column */
        overflow: hidden !important;
        text-align: center !important;
    }
}

/* UI Premium Styling */
.activity-card {
    border: none;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
    background: #fff;
    overflow: hidden;
}

.table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    padding: 1rem;
    border: none;
    text-align: center; /* Center aligned headings */
}

.table tbody td {
    padding: 1rem;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    white-space: normal;
    word-break: break-word;
    overflow-wrap: break-word;
}

.pagination {
    gap: 5px;
}

.pagination .page-link {
    border: none;
    background: #f1f5f9;
    color: #64748b;
    border-radius: 10px;
    padding: 8px 16px;
    font-weight: 600;
    transition: all 0.2s;
}

.pagination .page-item.active .page-link {
    background: #0d6efd;
    color: #fff;
    box-shadow: 0 4px 10px rgba(13, 110, 253, 0.25);
}

.pagination .page-link:hover:not(.active) {
    background: #e2e8f0;
    color: #1e293b;
}

#paginationInfo {
    background: #f8fafc;
    padding: 8px 16px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}

/* Mobile Responsive Card View */
@media screen and (max-width: 768px) {
    .main-content {
        padding-left: 10px !important;
        padding-right: 10px !important;
        overflow-x: hidden;
    }
    
    #activityTable thead {
        display: none;
    }
    
    #activityTable, #activityTable tbody, #activityTable tr, #activityTable td {
        display: block;
        width: 100% !important;
    }
    
    #activityTable tr {
        margin-bottom: 1.5rem;
        background: #fff;
        border-radius: 15px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        border: 1px solid #f1f5f9;
        padding: 10px;
    }
    
    #activityTable td {
        text-align: right !important;
        padding: 12px 15px !important;
        border-bottom: 1px solid #f1f5f9;
        position: relative;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    #activityTable td:last-child {
        border-bottom: none;
    }
    
    #activityTable td::before {
        content: attr(data-label);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.7rem;
        color: #64748b;
        margin-right: 15px;
        text-align: left;
    }

    .card-body {
        padding: 1rem !important;
    }

    .btn-group {
        width: auto;
        margin-top: 10px;
    }
}
</style>

<div class="container-fluid mt-4 main-content">
    <!-- Print-only Header -->
    <div class="print-header">
        <div class="py-2 mb-3" style="border-top: 2px solid #000; border-bottom: 2px solid #000; text-align: center;">
            <h3 class="mb-0 fw-bold" style="color: #000; text-transform: uppercase; letter-spacing: 2px;">System Activity Report</h3>
        </div>
        <div class="d-flex justify-content-between small text-muted mb-4">
            <span>Generated on: <?= date('d M Y, H:i') ?></span>
            <span>Ref: LOG-<?= date('Ymd-His') ?></span>
        </div>
    </div>

    <div class="mb-4 no-print">
        <h2 class="fw-bold text-primary" style="text-transform: uppercase;"><i class="bi bi-clock-history"></i> ACTIVITY LOG</h2>
        <p class="text-muted mb-0">Track and analyze all system transactions</p>
    </div>

    <!-- Activity Stats Cards -->
    <div class="row mb-4 no-print g-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 15px;">
                <div class="card-body p-3 text-center">
                    <h6 class="text-success text-uppercase small fw-bold mb-1" style="font-size: 0.65rem;">Created <span class="stat-scope-label"><?= htmlspecialchars($stats_scope_label) ?></span></h6>
                    <h3 class="fw-bold mb-0 text-success" id="stat-created"><?= number_format($today_stats['created'] ?? 0) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 15px;">
                <div class="card-body p-3 text-center">
                    <h6 class="text-success text-uppercase small fw-bold mb-1" style="font-size: 0.65rem;">Viewed <span class="stat-scope-label"><?= htmlspecialchars($stats_scope_label) ?></span></h6>
                    <h3 class="fw-bold mb-0 text-success" id="stat-viewed"><?= number_format($today_stats['viewed'] ?? 0) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 15px;">
                <div class="card-body p-3 text-center">
                    <h6 class="text-success text-uppercase small fw-bold mb-1" style="font-size: 0.65rem;">Updated <span class="stat-scope-label"><?= htmlspecialchars($stats_scope_label) ?></span></h6>
                    <h3 class="fw-bold mb-0 text-success" id="stat-updated"><?= number_format($today_stats['updated'] ?? 0) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 15px;">
                <div class="card-body p-3 text-center">
                    <h6 class="text-success text-uppercase small fw-bold mb-1" style="font-size: 0.65rem;">Deleted <span class="stat-scope-label"><?= htmlspecialchars($stats_scope_label) ?></span></h6>
                    <h3 class="fw-bold mb-0 text-success" id="stat-deleted"><?= number_format($today_stats['deleted'] ?? 0) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_admin): require_once ROOT_DIR . '/core/ai_service.php'; ?>
    <!-- ══ AI Audit Intelligence — admin-only ══════════════════════════════════ -->
    <div class="card mb-4 shadow-sm border-0 no-print" id="aiAuditCard"
         style="border-radius: 15px; border-left: 5px solid #7c3aed !important;">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center px-4 py-3"
             style="border-radius: 15px 15px 0 0; cursor:pointer;" onclick="toggleAiPanel()">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span style="font-size:1.3rem;">🤖</span>
                <span class="fw-bold text-dark fs-6">AI Audit Intelligence</span>
                <span class="badge rounded-pill text-white ms-1" style="background:#7c3aed; font-size:0.6rem; letter-spacing:.05em;">ADMIN ONLY</span>
                <?php if (!aiConfigured()): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:0.6rem;">Not configured —
                    <a href="<?= getUrl('ai_settings') ?>" class="text-dark" onclick="event.stopPropagation()">Set up AI</a>
                </span>
                <?php endif; ?>
            </div>
            <i class="bi bi-chevron-down text-muted" id="aiPanelChevron" style="transition:transform .25s;"></i>
        </div>

        <div id="aiPanelBody" class="d-none">
            <?php if (aiConfigured()): ?>
            <!-- Mode selector tabs -->
            <div class="d-flex border-bottom flex-wrap gap-0 px-3 pt-2" style="background:#f8fafc; border-radius:0;">
                <?php
                $modes = [
                    'briefing'  => ['icon' => 'bi-file-text',            'label' => 'Daily Briefing',    'color' => '#7c3aed'],
                    'anomalies' => ['icon' => 'bi-exclamation-triangle',  'label' => 'Anomaly Scanner',   'color' => '#dc2626'],
                    'ask'       => ['icon' => 'bi-chat-dots',             'label' => 'Ask the Log',       'color' => '#0d6efd'],
                    'report'    => ['icon' => 'bi-journal-bookmark-fill', 'label' => 'Audit Report',      'color' => '#059669'],
                ];
                foreach ($modes as $mk => $mv): ?>
                <button type="button"
                        class="btn btn-sm ai-mode-tab px-4 py-2 border-0 border-bottom border-3 rounded-0 fw-semibold"
                        style="font-size:.82rem; color:#64748b; border-color:transparent !important;"
                        data-mode="<?= $mk ?>"
                        data-color="<?= $mv['color'] ?>"
                        onclick="selectAiMode('<?= $mk ?>', this)">
                    <i class="bi <?= $mv['icon'] ?> me-1"></i><?= $mv['label'] ?>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Mode content panes -->
            <div class="px-4 py-3">

                <!-- BRIEFING -->
                <div class="ai-pane" id="ai-pane-briefing">
                    <p class="text-muted small mb-3">
                        AI reads the current period's activity and writes a plain-English narrative — who did what, what modules were used, and what deserves attention. Risk level included.
                    </p>
                    <button class="btn btn-sm fw-semibold text-white" style="background:#7c3aed; border-radius:8px;"
                            onclick="runAiAnalysis('briefing')">
                        <i class="bi bi-stars me-1"></i> Generate Briefing
                    </button>
                </div>

                <!-- ANOMALIES -->
                <div class="ai-pane d-none" id="ai-pane-anomalies">
                    <p class="text-muted small mb-3">
                        AI compares each user's activity against their 30-day baseline, checks for off-hours access, bulk deletions, and sensitive module access by unexpected users. Returns a structured findings list with severity ratings.
                    </p>
                    <button class="btn btn-sm fw-semibold text-white" style="background:#dc2626; border-radius:8px;"
                            onclick="runAiAnalysis('anomalies')">
                        <i class="bi bi-shield-exclamation me-1"></i> Scan for Anomalies
                    </button>
                </div>

                <!-- ASK -->
                <div class="ai-pane d-none" id="ai-pane-ask">
                    <p class="text-muted small mb-3">
                        Ask any question about the activity log in plain language. AI answers from aggregated data within the current period filter.
                    </p>
                    <div class="d-flex gap-2 mb-2" style="max-width:620px;">
                        <input type="text" id="aiAskInput" class="form-control form-control-sm"
                               placeholder="e.g. Who made deletions this week?  |  Did anyone access payroll?"
                               onkeydown="if(event.key==='Enter') runAiAnalysis('ask')">
                        <button class="btn btn-sm fw-semibold text-white px-3" style="background:#0d6efd; border-radius:8px; white-space:nowrap;"
                                onclick="runAiAnalysis('ask')">
                            <i class="bi bi-send"></i>
                        </button>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-1">
                        <button class="btn btn-xs btn-outline-secondary" style="font-size:.75rem;"
                                onclick="aiQuick('Who made the most deletions this period?')">Most deletions</button>
                        <button class="btn btn-xs btn-outline-secondary" style="font-size:.75rem;"
                                onclick="aiQuick('Were there any off-hours logins or access?')">Off-hours access</button>
                        <button class="btn btn-xs btn-outline-secondary" style="font-size:.75rem;"
                                onclick="aiQuick('Who accessed payroll or financial modules?')">Financial access</button>
                        <button class="btn btn-xs btn-outline-secondary" style="font-size:.75rem;"
                                onclick="aiQuick('Summarise what each user did and how busy they were')">User summary</button>
                        <button class="btn btn-xs btn-outline-secondary" style="font-size:.75rem;"
                                onclick="aiQuick('Are there any suspicious patterns I should investigate?')">Suspicious patterns</button>
                    </div>
                </div>

                <!-- REPORT -->
                <div class="ai-pane d-none" id="ai-pane-report">
                    <p class="text-muted small mb-3">
                        Generate a formal audit narrative for management or compliance. Optionally scope to one user and a custom date range independent of the page filter.
                    </p>
                    <div class="row g-2 mb-3" style="max-width:600px;">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted text-uppercase" style="font-size:.68rem;">User (optional)</label>
                            <select class="form-select form-select-sm" id="rptUser">
                                <option value="">All Users</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted text-uppercase" style="font-size:.68rem;">From</label>
                            <input type="date" class="form-control form-control-sm" id="rptFrom"
                                   value="<?= htmlspecialchars($date_from ?: date('Y-m-01')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted text-uppercase" style="font-size:.68rem;">To</label>
                            <input type="date" class="form-control form-control-sm" id="rptTo"
                                   value="<?= htmlspecialchars($date_to ?: date('Y-m-d')) ?>">
                        </div>
                    </div>
                    <button class="btn btn-sm fw-semibold text-white" style="background:#059669; border-radius:8px;"
                            onclick="runAiAnalysis('report')">
                        <i class="bi bi-journal-bookmark-fill me-1"></i> Generate Audit Report
                    </button>
                </div>

                <!-- Result area — shared across all modes -->
                <div id="aiResultArea" class="mt-4 d-none">
                    <!-- Loading -->
                    <div id="aiLoading" class="text-center py-4 d-none">
                        <div class="spinner-border" style="width:1.8rem;height:1.8rem;color:#7c3aed;"></div>
                        <p class="text-muted small mt-2 mb-0">AI is analysing the activity log…</p>
                    </div>
                    <!-- Output -->
                    <div id="aiOutput" class="d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <span class="text-muted small" id="aiResultMeta"></span>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;" onclick="printAiResult()">
                                    <i class="bi bi-printer me-1"></i>Print
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;" onclick="copyAiResult()">
                                    <i class="bi bi-clipboard me-1"></i>Copy
                                </button>
                            </div>
                        </div>
                        <div id="aiText"
                             style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1.2rem; font-size:.88rem; line-height:1.8; color:#1e293b;">
                        </div>
                    </div>
                    <!-- Error -->
                    <div id="aiError" class="d-none">
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            <span id="aiErrorMsg"></span>
                        </div>
                    </div>
                </div>

            </div><!-- /px-4 py-3 -->
            <?php else: ?>
            <div class="px-4 py-3 text-muted small">
                AI is not configured yet. Go to
                <a href="<?= getUrl('ai_settings') ?>">AI Settings</a> to connect a provider (OpenAI, Claude, Gemini, or any OpenAI-compatible API).
            </div>
            <?php endif; ?>
        </div><!-- /aiPanelBody -->
    </div>
    <!-- ══ end AI Audit Intelligence ══════════════════════════════════════════ -->
    <?php endif; // $is_admin ?>

    <?php if ($session_summary !== null):
        // Resolve the filtered user's display name for the panel header.
        $sel_user_name = '';
        foreach ($users as $_u) { if ((int)$_u['user_id'] === (int)$user_id_filter) { $sel_user_name = $_u['username']; break; } }
    ?>
    <!-- Time-in-System panel — shows only when a single user is filtered -->
    <div class="card mb-4 shadow-sm border-0" style="border-radius: 15px; border-left: 4px solid #0d6efd !important;">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Time in System — <?= htmlspecialchars($sel_user_name ?: ('User #' . (int)$user_id_filter)) ?></h5>
                <span class="text-muted small"><?= $date_from || $date_to ? 'For selected date range' : 'All time' ?></span>
            </div>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="text-muted text-uppercase fw-bold mb-1" style="font-size:0.62rem;">Total Time in System</div>
                        <div class="fs-5 fw-bold text-primary"><?= formatDuration($session_summary['total_seconds']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="text-muted text-uppercase fw-bold mb-1" style="font-size:0.62rem;">Sessions</div>
                        <div class="fs-5 fw-bold"><?= (int)$session_summary['sessions'] ?>
                            <?php if ($session_summary['open'] > 0): ?><span class="badge bg-warning text-dark ms-1" style="font-size:0.55rem;" title="Sessions with no recorded logout (browser closed / timed out)"><?= (int)$session_summary['open'] ?> open</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="text-muted text-uppercase fw-bold mb-1" style="font-size:0.62rem;">Avg / Session</div>
                        <div class="fs-5 fw-bold"><?= formatDuration($session_summary['avg_seconds']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="text-muted text-uppercase fw-bold mb-1" style="font-size:0.62rem;">Last Login</div>
                        <div class="fw-bold small"><?= $session_summary['last_login'] ? date('d M Y, H:i', strtotime($session_summary['last_login'])) : '—' ?></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($session_rows)): ?>
            <div class="table-responsive mt-3">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">Login</th>
                            <th>Logout</th>
                            <th>Duration</th>
                            <th>How it ended</th>
                            <th class="pe-3">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($session_rows as $sr): ?>
                        <tr>
                            <td class="ps-3"><?= date('d M Y, H:i:s', strtotime($sr['login_at'])) ?></td>
                            <td><?= $sr['logout_at'] ? date('d M Y, H:i:s', strtotime($sr['logout_at'])) : '<span class="text-muted">—</span>' ?></td>
                            <td class="fw-semibold"><?= formatDuration($sr['duration_seconds'] !== null ? (int)$sr['duration_seconds'] : null) ?></td>
                            <td>
                                <?php if ($sr['logout_type'] === 'manual'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Logged out</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="No logout recorded — browser closed or session timed out">Open / timed out</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-3 text-muted small"><?= htmlspecialchars($sr['ip_address'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted small mb-0 mt-2">No login sessions recorded for this user in the selected range. (Sessions are tracked from the time this feature went live.)</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="card mb-4 shadow-sm border-0 no-print" style="border-radius: 15px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Activity Type</label>
                    <select class="form-select border-0 bg-light" name="type" id="filterType">
                        <option value="">All Types</option>
                        <?php foreach ($activity_types as $type_key => $type_label): ?>
                            <option value="<?= htmlspecialchars($type_key) ?>" <?= $type_filter === $type_key ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type_label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">User</label>
                    <select class="form-select border-0 bg-light" name="user_id" id="filterUser">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $user_id_filter == $u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Period</label>
                    <select class="form-select border-0 bg-light" id="filterPeriod">
                        <option value="today"  <?= $period === 'today'  ? 'selected' : '' ?>>Today</option>
                        <option value="week"   <?= $period === 'week'   ? 'selected' : '' ?>>This Week</option>
                        <option value="month"  <?= $period === 'month'  ? 'selected' : '' ?>>This Month</option>
                        <option value="year"   <?= $period === 'year'   ? 'selected' : '' ?>>This Year</option>
                        <option value="all"    <?= $period === 'all'    ? 'selected' : '' ?>>All Time</option>
                        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>➕ Custom (specify)…</option>
                    </select>
                </div>
                <!-- Custom date range — revealed only when Period = Custom (like the
                     "Other → specify" pattern). Hidden otherwise. -->
                <div class="col-md-2 custom-date-field" id="customDateFrom" style="<?= $period === 'custom' ? '' : 'display:none;' ?>">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" class="form-control border-0 bg-light" name="date_from" id="filterDateFrom" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-2 custom-date-field" id="customDateTo" style="<?= $period === 'custom' ? '' : 'display:none;' ?>">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" class="form-control border-0 bg-light" name="date_to" id="filterDateTo" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Limit</label>
                    <select class="form-select border-0 bg-light" name="limit" id="filterLimit">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10 entries</option>
                        <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25 entries</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 entries</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100 entries</option>
                        <option value="all" <?= $limit == -1 ? 'selected' : '' ?>>All entries</option>
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2 pt-1">
                    <button type="submit" class="btn btn-primary fw-bold" style="border-radius: 10px; padding: 10px 24px;">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                    <?php if ($is_admin): ?>
                    <button type="button" class="btn btn-outline-danger fw-bold" style="border-radius: 10px; padding: 10px 24px;" onclick="initiatePurge()">
                        <i class="bi bi-trash3"></i> Purge Matching Logs
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Export Buttons -->
    <div class="mb-4 no-print d-flex justify-content-start">
        <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 10px; overflow: hidden; background: #fff;">
            <button type="button" class="btn btn-white fw-medium px-3 border-0 bg-white" onclick="copyLog()" style="color: #444;">
                <i class="bi bi-clipboard text-info me-1"></i> Copy
            </button>
            <div style="width: 1px; background: #eee; height: 24px; margin-top: 8px;"></div>
            <button type="button" class="btn btn-white fw-medium px-3 border-0 bg-white" onclick="exportCSV()" style="color: #444;">
                <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> CSV
            </button>
            <div style="width: 1px; background: #eee; height: 24px; margin-top: 8px;"></div>
            <button type="button" class="btn btn-white fw-medium px-3 border-0 bg-white" onclick="printLog()" style="color: #444;">
                <i class="bi bi-printer text-primary me-1"></i> Print
            </button>
        </div>
    </div>

    <div class="card mb-4 shadow-sm border-0 report-card">
        <div class="card-body p-0">
            <div style="overflow-x: hidden;">
                <table class="table table-hover align-middle mb-0" id="activityTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="text-center" style="width: 5%;">S/NO</th>
                            <th class="ps-3" style="width: 15%;">Time</th>
                            <th class="col-type" style="width: 15%;">Type</th>
                            <th style="width: 35%;">Description</th>
                            <th style="width: 15%;">Reference</th>
                            <th class="pe-3" style="width: 15%;">User</th>
                        </tr>
                    </thead>
                    <!-- Rows are loaded server-side by DataTables (sort / search /
                         paginate over 65k+ rows). Filters drive a full reload. -->
                    <tbody id="activityRows"></tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- AI Audit Intelligence — print-only section (revealed by body.ai-printing CSS class) -->
    <!-- Company header comes from the global renderPrintHeader() in header.php — no duplication needed -->
    <div id="aiPrintSection" style="display:none;">
        <div class="text-center pb-3 mb-3" style="border-bottom: 2px solid #0d6efd; margin-top: 8px;">
            <h5 id="aiPrintDocLabel" style="text-transform:uppercase; letter-spacing:1px; color:#333; margin:6px 0 2px; font-size:13pt;"></h5>
            <p id="aiPrintMetaLine" style="color:#64748b; font-size:10pt; margin:0;"></p>
        </div>
        <div id="aiPrintBody" style="font-size:12pt; line-height:1.7; color:#1e293b; padding-bottom:12mm;"></div>
    </div>
    <?php endif; ?>

</div>

<script>
let acTable = null;
$(function () {
    // ── Server-side DataTables: sort / search / paginate over 65k+ rows. The
    //    activity FILTERS (Type / User / Period / Custom range) drive a full page
    //    reload so the summary cards + Time-in-System panel re-render correctly;
    //    DataTables sends the current filter values with every request. ──────────
    acTable = $('#activityTable').DataTable({
        serverSide: true,
        processing: true,
        ordering: true,
        autoWidth: false,
        order: [[1, 'desc']],                 // Time, newest first
        pageLength: <?= $limit === -1 ? -1 : (int)$limit ?>,
        lengthChange: false,                  // page size is the existing "Limit" dropdown
        dom: '<"d-flex justify-content-end mb-2"f>rt<"d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 no-print"ip>',
        ajax: {
            url: '<?= getUrl('activity_log') ?>',
            type: 'GET',
            data: function (d) {
                d.type      = $('#filterType').val();
                d.user_id   = $('#filterUser').val();
                d.period    = $('#filterPeriod').val();
                d.date_from = $('#filterDateFrom').val();
                d.date_to   = $('#filterDateTo').val();
            },
            error: function (xhr) {
                console.error('Activity DataTables error:', xhr.status, xhr.statusText);
            }
        },
        columns: [
            { orderable: false, className: 'text-center', width: '5%'  }, // S/NO
            { width: '15%' },                                            // Time
            { width: '15%' },                                            // Type
            { width: '35%' },                                            // Description (widest)
            { orderable: false, width: '15%' },                          // Reference
            { width: '15%' }                                             // User
        ],
        language: {
            search: 'Search:',
            processing: 'Loading…',
            emptyTable: 'No activities found for this filter.',
            zeroRecords: 'No matching activities found.',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            infoEmpty: 'Showing 0 entries',
            infoFiltered: '(filtered from _MAX_ total)'
        }
    });

    // Filters → full reload (so cards + session panel stay correct server-side).
    function acApplyFilters() {
        const p = new URLSearchParams();
        const t = $('#filterType').val();   if (t) p.set('type', t);
        const u = $('#filterUser').val();   if (u) p.set('user_id', u);
        p.set('period', $('#filterPeriod').val() || 'today');
        const df = $('#filterDateFrom').val(); if (df) p.set('date_from', df);
        const dt = $('#filterDateTo').val();   if (dt) p.set('date_to', dt);
        const lim = $('#filterLimit').val();   if (lim) p.set('limit', lim);
        window.location = '<?= getUrl('activity_log') ?>?' + p.toString();
    }

    $('#filterForm').on('submit', function (e) { e.preventDefault(); acApplyFilters(); });
    $('#filterType, #filterUser').on('change', acApplyFilters);

    // Limit = DataTables page length (no reload needed).
    $('#filterLimit').on('change', function () {
        acTable.page.len(this.value === 'all' ? -1 : parseInt(this.value, 10)).draw();
    });

    // Period: reveal Custom From/To only for "Custom (specify)"; non-custom reloads
    // immediately, custom waits for a date to be picked.
    $('#filterPeriod').on('change', function () {
        const isCustom = $(this).val() === 'custom';
        $('.custom-date-field').toggle(isCustom);
        if (isCustom) { $('#filterDateFrom').focus(); } else { acApplyFilters(); }
    });
    $('#filterDateFrom, #filterDateTo').on('change', function () {
        if ($('#filterPeriod').val() === 'custom') acApplyFilters();
    });
});

function printLog() {
    logReportAction('Printed Activity Log', 'Generated a printed report of the system activity logs');
    window.print();
}

function copyLog() {
    // Initialize a temporary DataTable if not exists to handle the copy
    if (!$.fn.DataTable.isDataTable('#activityTable')) {
        $('#activityTable').DataTable({
            paging: false,
            searching: false,
            info: false,
            dom: 'B',
            buttons: ['copyHtml5']
        }).button('.buttons-copy').trigger();
        $('#activityTable').DataTable().destroy();
    } else {
        $('#activityTable').DataTable().button('.buttons-copy').trigger();
    }
    logReportAction('Copied Activity Log', 'Copied activity log records to clipboard');
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: 'Activity records copied to clipboard.',
        confirmButtonText: 'OK',
        confirmButtonColor: '#28a745'
    });
}

function exportCSV() {
    if (!$.fn.DataTable.isDataTable('#activityTable')) {
        $('#activityTable').DataTable({
            paging: false,
            searching: false,
            info: false,
            dom: 'B',
            buttons: [{
                extend: 'csvHtml5',
                filename: 'Activity_Log_' + new Date().toISOString().slice(0,10)
            }]
        }).button('.buttons-csv').trigger();
        $('#activityTable').DataTable().destroy();
    } else {
        $('#activityTable').DataTable().button('.buttons-csv').trigger();
    }
    logReportAction('Exported Activity Log', 'Exported activity log records to CSV file');
}

// (Period is now server-authoritative — see the #filterPeriod change handler
//  above. The old client-side date-preset logic was removed.)

// ── Purge matching logs ──────────────────────────────────────────────────────
async function initiatePurge() {
    const payload = {
        action  : 'count',
        _csrf   : CSRF_TOKEN,
        type    : $('#filterType').val(),
        user_id : $('#filterUser').val(),
        date_from: $('#filterDateFrom').val(),
        date_to  : $('#filterDateTo').val()
    };

    Swal.fire({
        title: 'Counting…',
        text: 'Checking matching log entries',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    let countData;
    try {
        countData = await $.ajax({
            url     : '<?= getUrl("api/activity_log_delete.php") ?>',
            type    : 'POST',
            data    : payload,
            dataType: 'json'
        });
    } catch (e) {
        Swal.fire('Error', 'Connection failed. Please try again.', 'error');
        return;
    }

    if (!countData.success) {
        Swal.fire('Error', countData.error || 'Could not get count', 'error');
        return;
    }

    const count = countData.count;
    if (count === 0) {
        Swal.fire({ icon: 'info', title: 'Nothing to Delete', text: 'No log entries match the current filters.' });
        return;
    }

    const allWarn = countData.all_records
        ? `<div class="alert alert-danger small mb-3 text-start">
               <i class="bi bi-exclamation-triangle-fill me-1"></i>
               <strong>No filters applied.</strong> This will erase the <em>entire</em> activity log history.
           </div>`
        : '';

    const confirm = await Swal.fire({
        icon : 'warning',
        title: 'Purge Log Entries?',
        html : `${allWarn}
                <p class="mb-1">You are about to permanently delete</p>
                <h2 class="fw-bold text-danger my-2">${count.toLocaleString()} entries</h2>
                <p class="text-muted small mb-0">This action <strong>cannot be undone</strong>.</p>`,
        showCancelButton   : true,
        confirmButtonColor : '#dc3545',
        cancelButtonColor  : '#6c757d',
        confirmButtonText  : '<i class="bi bi-trash3 me-1"></i> Yes, Delete Permanently',
        cancelButtonText   : 'Cancel',
        reverseButtons     : true
    });

    if (!confirm.isConfirmed) return;

    Swal.fire({
        title: 'Purging…',
        html : `Deleting <strong>${count.toLocaleString()}</strong> log entries`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    let purgeData;
    try {
        purgeData = await $.ajax({
            url     : '<?= getUrl("api/activity_log_delete.php") ?>',
            type    : 'POST',
            data    : { ...payload, action: 'purge' },
            dataType: 'json'
        });
    } catch (e) {
        Swal.fire('Error', 'Connection failed during purge. Check server logs.', 'error');
        return;
    }

    if (purgeData.success) {
        await Swal.fire({
            icon : 'success',
            title: 'Purged Successfully',
            html : `<strong>${purgeData.count.toLocaleString()}</strong> log entries have been permanently deleted.`,
            confirmButtonColor: '#28a745'
        });
        if (acTable) acTable.ajax.reload(null, false);
        location.reload();
    } else {
        Swal.fire('Error', purgeData.error || 'Purge failed', 'error');
    }
}

// ── AI Audit Intelligence ─────────────────────────────────────────────────────
let _aiCurrentMode = 'briefing';

function toggleAiPanel() {
    const body    = document.getElementById('aiPanelBody');
    const chevron = document.getElementById('aiPanelChevron');
    if (!body) return;
    const opening = body.classList.contains('d-none');
    body.classList.toggle('d-none', !opening);
    chevron.style.transform = opening ? 'rotate(180deg)' : '';
    // Auto-select first tab on first open
    if (opening && !body.dataset.initialized) {
        body.dataset.initialized = '1';
        const firstTab = body.querySelector('.ai-mode-tab');
        if (firstTab) selectAiMode(firstTab.dataset.mode, firstTab);
    }
}

function selectAiMode(mode, btn) {
    _aiCurrentMode = mode;
    // Update tab styles
    document.querySelectorAll('.ai-mode-tab').forEach(b => {
        b.style.color       = '#64748b';
        b.style.borderColor = 'transparent';
        b.style.fontWeight  = '500';
    });
    if (btn) {
        btn.style.color       = btn.dataset.color || '#7c3aed';
        btn.style.borderColor = btn.dataset.color || '#7c3aed';
        btn.style.fontWeight  = '700';
    }
    // Show the right pane
    document.querySelectorAll('.ai-pane').forEach(p => p.classList.add('d-none'));
    const pane = document.getElementById('ai-pane-' + mode);
    if (pane) pane.classList.remove('d-none');
    // Clear previous result
    document.getElementById('aiResultArea').classList.add('d-none');
    document.getElementById('aiOutput').classList.add('d-none');
    document.getElementById('aiError').classList.add('d-none');
    document.getElementById('aiLoading').classList.add('d-none');
}

function aiQuick(question) {
    const inp = document.getElementById('aiAskInput');
    if (inp) { inp.value = question; inp.focus(); }
    runAiAnalysis('ask');
}

async function runAiAnalysis(mode) {
    // Build payload from current page filter + mode-specific inputs
    const dateFrom = $('#filterDateFrom').val() || '<?= $date_from ?>';
    const dateTo   = $('#filterDateTo').val()   || '<?= $date_to ?>';
    const userId   = $('#filterUser').val()     || '';

    const payload = {
        _csrf    : CSRF_TOKEN,
        mode     : mode,
        date_from: dateFrom,
        date_to  : dateTo,
        user_id  : userId,
    };

    if (mode === 'ask') {
        const q = (document.getElementById('aiAskInput')?.value || '').trim();
        if (!q) { Swal.fire({ icon:'warning', title:'Please enter a question first.' }); return; }
        payload.query = q;
    }
    if (mode === 'report') {
        payload.report_user_id = document.getElementById('rptUser')?.value  || '';
        payload.report_from    = document.getElementById('rptFrom')?.value  || dateFrom;
        payload.report_to      = document.getElementById('rptTo')?.value    || dateTo;
    }

    // Show loading
    const area = document.getElementById('aiResultArea');
    area.classList.remove('d-none');
    document.getElementById('aiLoading').classList.remove('d-none');
    document.getElementById('aiOutput').classList.add('d-none');
    document.getElementById('aiError').classList.add('d-none');

    try {
        const res = await $.ajax({
            url     : '<?= buildUrl('api/ai_audit_analysis.php') ?>',
            type    : 'POST',
            data    : payload,
            dataType: 'json',
        });
        document.getElementById('aiLoading').classList.add('d-none');

        if (res.success) {
            const modeLabels = {
                briefing : '📋 Daily Briefing',
                anomalies: '🔍 Anomaly Scan',
                ask      : '💬 Ask the Log',
                report   : '📄 Audit Report',
            };
            const usage  = res.usage || {};
            const tokens = (usage.prompt || 0) + (usage.completion || 0);
            document.getElementById('aiResultMeta').textContent =
                (modeLabels[res.mode] || res.mode) +
                (tokens ? '  ·  ' + tokens.toLocaleString() + ' tokens' : '') +
                '  ·  ' + new Date().toLocaleTimeString();
            document.getElementById('aiText').innerHTML = renderAiMarkdown(res.text || '');
            document.getElementById('aiOutput').classList.remove('d-none');
        } else {
            document.getElementById('aiErrorMsg').textContent = res.message || 'Unknown error.';
            document.getElementById('aiError').classList.remove('d-none');
        }
    } catch (e) {
        document.getElementById('aiLoading').classList.add('d-none');
        document.getElementById('aiErrorMsg').textContent = 'Request failed. Check your connection.';
        document.getElementById('aiError').classList.remove('d-none');
    }
}

function renderAiMarkdown(text) {
    if (!text) return '';
    return text
        // Severity badges
        .replace(/🔴\s*(High|CRITICAL)/gi,  '<span class="badge text-white me-1" style="background:#dc2626;">🔴 High</span>')
        .replace(/🟡\s*(Medium|MODERATE)/gi, '<span class="badge text-dark me-1" style="background:#fbbf24;">🟡 Medium</span>')
        .replace(/🟢\s*(Low|CLEAN)/gi,       '<span class="badge text-white me-1" style="background:#16a34a;">🟢 Low</span>')
        // Risk level lines
        .replace(/RISK LEVEL\s*:\s*/gi, '<strong class="text-danger">RISK LEVEL: </strong>')
        // Bold
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        // Section headers (## or ALL CAPS line followed by ---)
        .replace(/^#{1,3}\s+(.+)$/gm, '<div class="fw-bold text-primary mt-3 mb-1" style="font-size:.9rem;border-bottom:1px solid #e2e8f0;padding-bottom:3px;">$1</div>')
        .replace(/^([A-Z][A-Z\s\/&]{4,}):?\s*$/gm, '<div class="fw-bold text-uppercase mt-3 mb-1" style="font-size:.78rem;color:#7c3aed;letter-spacing:.07em;">$1</div>')
        // Bullet lines starting with - or •
        .replace(/^[\-•]\s+(.+)$/gm, '<div class="ms-3 mb-1">• $1</div>')
        // FINDING / DETAIL / ACTION / SEVERITY labels
        .replace(/\b(SEVERITY|FINDING|DETAIL|ACTION|RECOMMENDATION[S]?|SCOPE|METHODOLOGY)\s*:/g,
                 '<span class="fw-bold text-dark" style="font-size:.8rem;">$1:</span>')
        // Checkmark
        .replace(/✅/g, '<span class="text-success">✅</span>')
        .replace(/⚠️/g, '<span class="text-warning">⚠️</span>')
        // Double newline → paragraph break
        .replace(/\n\n+/g, '<br><br>')
        .replace(/\n/g, '<br>');
}

function printAiResult() {
    const content = document.getElementById('aiText')?.innerHTML || '';
    const meta    = document.getElementById('aiResultMeta')?.textContent || '';
    const modeLabels = {
        briefing : 'Daily Briefing',
        anomalies: 'Anomaly Scanner',
        ask      : 'Ask the Log',
        report   : 'Audit Report',
    };

    document.getElementById('aiPrintDocLabel').textContent =
        'AI Audit Intelligence — ' + (modeLabels[_aiCurrentMode] || _aiCurrentMode);
    document.getElementById('aiPrintMetaLine').textContent = meta;
    document.getElementById('aiPrintBody').innerHTML = content;

    document.body.classList.add('ai-printing');

    const cleanup = () => {
        document.body.classList.remove('ai-printing');
        window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);

    window.print();
}

function copyAiResult() {
    const text = document.getElementById('aiText')?.innerText || '';
    navigator.clipboard.writeText(text).then(() => {
        Swal.fire({ icon:'success', title:'Copied!', text:'AI analysis copied to clipboard.', timer:1500, showConfirmButton:false });
    }).catch(() => {
        Swal.fire({ icon:'error', title:'Copy failed', text:'Please select and copy manually.' });
    });
}
</script>

<style>
/* Utilities */
.tracking-wider { letter-spacing: 0.1em; }
</style>

<?php require_once ROOT_DIR . '/footer.php'; ?>
