<?php
// helpers.php

function calculateTotalInterest($amount, $rate, $term, $formula) {
    switch ($formula) {
        case 'Flat Rate':
            return $amount * ($rate / 100) * ($term / 12);

        case 'Reducing Balance':
        case 'EMI':
        default:
            $monthlyRate = $rate / 100 / 12;
            if ($monthlyRate == 0) return 0; // no interest case
            $emi = ($amount * $monthlyRate) / (1 - pow(1 + $monthlyRate, -$term));
            $totalPayment = $emi * $term;
            return $totalPayment - $amount;
    }
}

function addMonthsWithAnchor(DateTime $date, int $months, int $anchorDay): DateTime
{
    // Always move from first of month to avoid overflow
    $base = new DateTime($date->format('Y-m-01'));
    $base->add(new DateInterval("P{$months}M"));

    $lastDay = (int)$base->format('t');

    // Use anchor day if possible, otherwise last day of month
    $day = min($anchorDay, $lastDay);

    $base->setDate(
        (int)$base->format('Y'),
        (int)$base->format('m'),
        $day
    );

    return $base;
}

function createRepaymentSchedule(
    $pdo,
    $loan_id,
    $amount,
    $rate,
    $term, // months
    $repayment_cycle_id,
    $start_date,
    $formula,
    $grace_period = 0
) {
    if (!is_numeric($amount) || $amount <= 0) {
        throw new Exception("Invalid loan amount");
    }

    // Normalize rate
    $rate = str_replace(['%', 'M', 'P'], '', (string)$rate);
    if (!is_numeric($rate)) {
        throw new Exception("Invalid interest rate");
    }
    $rate = (float)$rate;
    if ($rate > 1) $rate /= 100;

    if (!is_numeric($term) || $term <= 0) {
        throw new Exception("Invalid loan term");
    }

    // Get repayment cycle
    $stmt = $pdo->prepare("SELECT cycle FROM repayment_cycles WHERE id = ?");
    $stmt->execute([$repayment_cycle_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Invalid repayment cycle");
    }

    $cycle = strtolower($row['cycle']);

    // Anchor date
    $start = new DateTime($start_date);
    $anchorDay = (int)$start->format('d'); // NEVER changes
    $balance = (float)$amount;

    /**
     * SAFE MONTH ADDER WITH ANCHOR DAY
     */
    $addMonthsWithAnchor = function (DateTime $date, int $months) use ($anchorDay) {
        $base = new DateTime($date->format('Y-m-01'));
        $base->add(new DateInterval("P{$months}M"));

        $lastDay = (int)$base->format('t');
        $day = min($anchorDay, $lastDay);

        $base->setDate(
            (int)$base->format('Y'),
            (int)$base->format('m'),
            $day
        );

        return $base;
    };

    /**
     * NUMBER OF PAYMENTS (FINANCIAL LOGIC)
     */
    switch ($cycle) {
        case 'weekly':
            $num_payments = $term * 4;
            $stepDays = 7;
            $stepMonths = 0;
            break;

        case 'bi-weekly':
            $num_payments = $term * 2;
            $stepDays = 14;
            $stepMonths = 0;
            break;

        case 'monthly':
            $num_payments = $term;
            $stepMonths = 1;
            $stepDays = 0;
            break;

        case 'quarterly':
            $num_payments = ceil($term / 3);
            $stepMonths = 3;
            $stepDays = 0;
            break;

        case 'semi-annual':
            $num_payments = ceil($term / 6);
            $stepMonths = 6;
            $stepDays = 0;
            break;

        case 'annual':
            $num_payments = ceil($term / 12);
            $stepMonths = 12;
            $stepDays = 0;
            break;

        default:
            throw new Exception("Unsupported repayment cycle");
    }

    /**
     * INTEREST RATE PER PERIOD
     */
    $monthlyRate = $rate / 12;

    if ($stepDays > 0) {
        // Financial weeks
        $periodicRate = $monthlyRate * ($stepDays / 28); // 4 weeks = 28 days
    } else {
        $periodicRate = $monthlyRate * $stepMonths;
    }

    /**
     * FIRST PAYMENT DATE
     */
    if ($stepDays > 0) {
        $payment_date = clone $start;
        $payment_date->add(new DateInterval("P{$stepDays}D"));
    } else {
        $payment_date = $addMonthsWithAnchor($start, $stepMonths);
    }

    if ($grace_period > 0) {
        $payment_date->add(new DateInterval("P{$grace_period}D"));
    }

    /**
     * PAYMENT AMOUNT
     */
    if ($formula === 'Flat Rate') {
        $principal = $amount / $num_payments;
        $flat_interest = ($amount * $rate * ($term / 12)) / $num_payments;
        $payment_amount = $principal + $flat_interest;
    } else {
        $payment_amount = ($amount * $periodicRate) /
            (1 - pow(1 + $periodicRate, -$num_payments));
    }

    /**
     * INSERT STATEMENT
     */
    $stmt = $pdo->prepare("
        INSERT INTO loan_repayment_schedule (
            loan_id,
            payment_number,
            due_date,
            principal_amount,
            interest_amount,
            total_amount,
            remaining_balance,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");

    /**
     * GENERATE SCHEDULE
     */
    for ($i = 1; $i <= $num_payments; $i++) {

        if ($formula === 'Flat Rate') {
            $interest = $flat_interest;
            $principal_payment = $principal;
        } else {
            $interest = $balance * $periodicRate;
            $principal_payment = $payment_amount - $interest;
        }

        if ($i === $num_payments) {
            $principal_payment = $balance;
            $payment_amount = $principal_payment + $interest;
        }

        $remaining_balance = $balance - $principal_payment;

        $stmt->execute([
            $loan_id,
            $i,
            $payment_date->format('Y-m-d'),
            round($principal_payment, 2),
            round($interest, 2),
            round($payment_amount, 2),
            round($remaining_balance, 2)
        ]);

        // Next due date
        if ($stepDays > 0) {
            $payment_date->add(new DateInterval("P{$stepDays}D"));
        } else {
            $payment_date = $addMonthsWithAnchor($payment_date, $stepMonths);
        }

        $balance = $remaining_balance;
    }

    return true;
}

function logActivity($pdo, $user_id, $action, $description = null) {
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, ip_address, user_agent, description, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $stmt->execute([$user_id, $action, $ip, $agent, $description]);
}

/**
 * Log audit trail
 * @param PDO $pdo Database connection
 * @param int $user_id User ID
 * @param string $action Action performed
 * @param array $data Additional data (entity_type, entity_id, old_values, new_values)
 * @return bool Success status
 */
function logAudit($pdo, $user_id, $action, $data = []) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id, action, activity_type, entity_type, entity_id, 
                description, old_values, new_values, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        return $stmt->execute([
            $user_id,
            $action,
            $data['activity_type'] ?? $action, // Fallback to action if activity_type not provided
            $data['entity_type'] ?? null,
            $data['entity_id'] ?? null,
            $data['description'] ?? null,
            isset($data['old_values']) ? json_encode($data['old_values']) : null,
            isset($data['new_values']) ? json_encode($data['new_values']) : null,
            $ip_address,
            $user_agent
        ]);
        
    } catch (PDOException $e) {
        // Fallback to error log if audit logging fails
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

function generateReceipt($payment_id) {
    return "receipts/receipt_$payment_id.pdf";
}

function get_status_badge($status) {
    switch (strtolower($status ?? '')) {
        case 'awarded':
        case 'opening':
        case 'evaluation':
        case 'active':
        case 'approved':
        case 'completed':
        case 'success':
        case 'present':
            return 'success';
        case 'pending':
        case 'waiting':
        case 'invitation':
        case 'submission':
        case 'post-qualification':
        case 'negotiation':
        case 'suspended':
        case 'probation':
            return 'info';
        case 'loss':
        case 'end tender':
        case 'rejected':
        case 'cancelled':
        case 'absent':
            return 'danger';
        case 'inactive':
        case 'draft':
        case 'closed':
        case 'resigned':
        case 'contract':
        case 'holiday':
        case 'other':
            return 'secondary';
        case 'void':
        case 'disputed':
            return 'danger';
        case 'paid':
        case 'info':
        case 'on_leave':
        case 'taken':
        case 'half_day':
        case 'reversed':
            return 'info';
        case 'partial':
        case 'partially_paid':
        case 'partially_delivered':
        case 'processing':
        case 'received':
        case 'leave':
            return 'primary';
        case 'ordered':
            return 'info';
        case 'maintenance':
        case 'late':
            return 'warning';
        case 'disposed':
        case 'written_off':
            return 'danger';
        case 'delivered':
        case 'shipped':
        case 'posted':
        case 'reconciled':
            return 'success';
        case 'weekend':
            return 'dark';
        default:
            return 'secondary';
    }
}

function get_attendance_badge($status) {
    switch ($status) {
        case 'present': return 'success';
        case 'absent': return 'danger';
        case 'late': return 'warning';
        case 'half_day': return 'info';
        case 'leave': return 'primary';
        case 'holiday': return 'secondary';
        case 'weekend': return 'dark';
        default: return 'secondary';
    }
}

function get_type_badge($type) {
    switch (strtolower($type ?? '')) {
        case 'annual': return 'primary';
        case 'sick': return 'info';
        case 'maternity': return 'success';
        case 'paternity': return 'primary';
        case 'casual': return 'warning';
        case 'emergency': return 'danger';
        case 'study': return 'dark';
        case 'unpaid': return 'secondary';
        default: return 'secondary';
    }
}

function calculate_leave_days($start_date, $end_date) {
    if (empty($start_date) || empty($end_date)) return 0;
    
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day'); // Include end date
    
    $interval = $start->diff($end);
    return $interval->days;
}

function calculate_age($birth_date) {
    if (empty($birth_date)) return 'N/A';
    $birth = new DateTime($birth_date);
    $today = new DateTime();
    $age = $today->diff($birth);
    return $age->y;
}

function format_phone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 9) {
        return '255' . $phone;
    }
    return $phone;
}

function get_variance_color($variance) {
    if ($variance > 0) return 'success'; // Under budget
    if ($variance < 0) return 'danger';  // Over budget
    return 'info'; // On budget
}

function format_currency($amount, $currency = 'TZS') {
    $symbols = [
        'TZS' => 'TSh ',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'KES' => 'KSh '
    ];
    $symbol = $symbols[$currency] ?? 'TSh ';
    return $symbol . number_format((float)$amount, 2);
}

function safe_output($value, $default = 'N/A') {
    return ($value !== null && $value !== '') ? htmlspecialchars((string)$value) : $default;
}

function format_date($date, $format = 'd M Y') {
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

if (!function_exists('generate_receipt_number')) {
    function generate_receipt_number() {
        return 'RCP-' . date('Ymd') . '-' . rand(1000, 9999);
    }
}

if (!function_exists('format_number')) {
    function format_number($number, $decimals = 2) {
        return number_format((float)$number, $decimals);
    }
}

if (!function_exists('get_setting')) {
    function get_setting($key, $default = '') {
        global $pdo;
        static $settings_cache = null;
        
        if ($settings_cache === null) {
            $settings_cache = [];
            try {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings_cache[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                // Silently fail if table doesn't exist yet
            }
        }
        
        return $settings_cache[$key] ?? $default;
    }
}

if (!function_exists('save_setting')) {
    function save_setting($key, $value) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            return $stmt->execute([$key, $value, $value]);
        } catch (Exception $e) {
            error_log("Error saving setting $key: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') {
        return get_setting($key, $default);
    }
}

/**
 * Register any uploaded file into the Docs > Library (documents table).
 *
 * @param PDO    $pdo           Active database connection
 * @param string $file_path     Relative path where the file was saved (e.g. 'uploads/tenders/file.pdf')
 * @param string $original_name Original file name as uploaded by user
 * @param int    $file_size     File size in bytes
 * @param string $document_name Human-readable title for the document
 * @param string $tags          Comma-separated tags (e.g. 'tender,submission')
 * @param int    $user_id       ID of the user performing the upload
 * @return int|null             Inserted document ID, or null on failure
 */
if (!function_exists('registerFileInLibrary')) {
    function registerFileInLibrary(PDO $pdo, string $file_path, string $original_name, int $file_size, string $document_name, string $tags = '', int $user_id = 0, ?int $project_id = null, string $access_level = 'private'): ?int {
        try {
            $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $stmt = $pdo->prepare("
                INSERT INTO documents
                    (document_name, description, file_path, original_filename,
                     file_size, file_type, category_id, version, tags, access_level, project_id, uploaded_by, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, NULL, '1.0', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $document_name,
                '',               // description — blank, editable later from Library
                $file_path,
                $original_name,
                $file_size,
                $file_ext,
                $tags,
                $access_level,    // Dynamic access level (public/private/restricted)
                $project_id,      // links document to the correct project
                $user_id
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            // Non-fatal: log but don't break main upload flow
            error_log('[registerFileInLibrary] ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * Recalculate and synchronize project progress based on milestone performance reports.
 */
if (!function_exists('syncProjectProgress')) {
    function syncProjectProgress(PDO $pdo, $projectId) {
        if (!$projectId) return 0;
        
        $stmtAct = $pdo->prepare("
            SELECT d.milestone_id, SUM(d.actual_value) as annual_act
            FROM project_progress_reports pr
            JOIN project_progress_report_details d ON pr.id = d.report_id
            WHERE pr.project_id = ? AND pr.report_type = 'daily' 
            GROUP BY d.milestone_id
        ");
        $stmtAct->execute([$projectId]);
        $act_map = $stmtAct->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $stmtM = $pdo->prepare("SELECT id, parent_id, weight_percent, scope FROM project_milestones WHERE project_id = ? AND scope_type = 'milestone'");
        $stmtM->execute([$projectId]);
        $m_list = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($m_list)) return 0;

        $m_tree = [];
        foreach($m_list as $m) {
            $m['children'] = [];
            $m['act'] = (float)($act_map[$m['id']] ?? 0);
            $m_tree[$m['id']] = $m;
        }

        $roots = [];
        foreach($m_tree as &$m) {
            if ($m['parent_id'] && isset($m_tree[$m['parent_id']])) {
                $m_tree[$m['parent_id']]['children'][] = &$m;
            } else {
                $roots[] = &$m;
            }
        }
        unset($m);

        // Closure-based recursion
        $recurse = function ($m, $rootWeight, $self) {
            if (count($m['children']) > 0) {
                $sumP = 0;
                foreach($m['children'] as $c) {
                    $sumP += $self($c, $rootWeight, $self);
                }
                return $sumP / count($m['children']);
            } else {
                $scope = (float)$m['scope'];
                return ($scope > 0) ? ($m['act'] / $scope) * $rootWeight : 0;
            }
        };

        $finalP = 0;
        foreach($roots as $r) {
            $rootW = (float)$r['weight_percent'];
            $finalP += $recurse($r, $rootW, $recurse);
        }

        $finalP = round(min(100, $finalP), 2);
        
        $pdo->prepare("UPDATE projects SET progress_percent = ? WHERE project_id = ?")->execute([$finalP, $projectId]);
        
        return $finalP;
    }
}

/**
 * Format bytes to human readable format
 */
if (!function_exists('format_bytes')) {
    function format_bytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

