<?php
// api/operations/get_project_attendance.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID required']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('userCan') && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
    exit();
}

$date_from  = !empty($_GET['date_from'])  ? trim($_GET['date_from'])  : date('Y-m-d');
$date_to    = !empty($_GET['date_to'])    ? trim($_GET['date_to'])    : date('Y-m-d');
$status     = !empty($_GET['status'])     ? trim($_GET['status'])     : '';
$is_day     = ($date_from === $date_to);

function is_weekend_date($date) {
    return ((int)date('N', strtotime($date))) >= 6; // 6 = Saturday, 7 = Sunday
}

try {
    $empStmt = $pdo->prepare("
        SELECT e.employee_id, e.employee_number, e.first_name, e.last_name,
               d.department_name, des.designation_name
        FROM employees e
        LEFT JOIN departments d    ON e.department_id  = d.department_id
        LEFT JOIN designations des ON e.designation_id = des.designation_id
        WHERE e.project_id = ? AND e.status = 'active'
        ORDER BY e.first_name ASC, e.last_name ASC
    ");
    $empStmt->execute([$project_id]);
    $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

    $records = [];
    $stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'half_day' => 0, 'leave' => 0, 'total_hours' => 0];

    if ($is_day) {
        // Single date selected — one row per employee for THAT day, directly
        // markable in place (check-in/out, quick status), same behaviour as
        // the standalone Attendance page's Daily view.
        $attStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $leaveStmt = $pdo->prepare("
            SELECT leave_type FROM leaves
            WHERE employee_id = ? AND start_date <= ? AND end_date >= ? AND status = 'approved'
        ");

        foreach ($employees as $employee) {
            $eid = $employee['employee_id'];
            $attStmt->execute([$eid, $date_from]);
            $rec = $attStmt->fetch(PDO::FETCH_ASSOC);

            if ($rec) {
                $row = [
                    'attendance_id'   => $rec['attendance_id'],
                    'check_in_time'   => $rec['check_in_time'],
                    'check_out_time'  => $rec['check_out_time'],
                    'total_hours'     => $rec['total_hours'],
                    'status'          => $rec['status'],
                    'notes'           => $rec['notes'],
                    'existing_record' => true,
                ];
            } else {
                if (is_weekend_date($date_from)) {
                    $row = ['status' => 'weekend', 'notes' => 'Weekend'];
                } else {
                    $leaveStmt->execute([$eid, $date_from, $date_from]);
                    $onLeave = $leaveStmt->fetch(PDO::FETCH_ASSOC);
                    $row = $onLeave
                        ? ['status' => 'leave', 'notes' => ($onLeave['leave_type'] . ' leave')]
                        : ['status' => 'absent', 'notes' => ''];
                }
                $row += ['attendance_id' => null, 'check_in_time' => null, 'check_out_time' => null,
                         'total_hours' => '0.00', 'existing_record' => false];
            }

            $row = array_merge($employee, $row, ['attendance_date' => $date_from]);

            if (!$status || $row['status'] === $status) {
                $records[] = $row;
                if (isset($stats[$row['status']])) $stats[$row['status']]++;
                $stats['total_hours'] += (float)$row['total_hours'];
            }
        }
    } else {
        // Date range — aggregate one row per employee (same shape as the
        // standalone Attendance page's week/month view), not one row per
        // daily record, so a staff member with several days in range
        // doesn't repeat once per day.
        $stmt = $pdo->prepare("
            SELECT
                e.employee_id, e.employee_number, e.first_name, e.last_name,
                d.department_name, des.designation_name,
                COALESCE(SUM(CASE WHEN a.total_hours > 0 AND a.total_hours <= 24 THEN a.total_hours ELSE 0 END), 0) AS total_hours,
                SUM(CASE WHEN a.status = 'present'  THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN a.status = 'late'     THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) AS half_day_count,
                SUM(CASE WHEN a.status = 'absent'   THEN 1 ELSE 0 END) AS absent_count,
                SUM(CASE WHEN a.status = 'leave'    THEN 1 ELSE 0 END) AS leave_count
            FROM employees e
            LEFT JOIN departments d    ON e.department_id  = d.department_id
            LEFT JOIN designations des ON e.designation_id = des.designation_id
            LEFT JOIN attendance a ON a.employee_id = e.employee_id
                   AND a.attendance_date BETWEEN ? AND ?
            WHERE e.project_id = ? AND e.status = 'active'
            GROUP BY e.employee_id
            ORDER BY e.first_name ASC, e.last_name ASC
        ");
        $stmt->execute([$date_from, $date_to, $project_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($status) {
            $countKey = $status . '_count';
            $records = array_values(array_filter($records, function ($r) use ($countKey) {
                return isset($r[$countKey]) && (int)$r[$countKey] > 0;
            }));
        }

        foreach ($records as $r) {
            $stats['present']   += (int)$r['present_count'];
            $stats['late']      += (int)$r['late_count'];
            $stats['half_day']  += (int)$r['half_day_count'];
            $stats['absent']    += (int)$r['absent_count'];
            $stats['leave']     += (int)$r['leave_count'];
            $stats['total_hours'] += (float)$r['total_hours'];
        }
    }

    echo json_encode([
        'success'   => true,
        'view_mode' => $is_day ? 'day' : 'range',
        'data'      => $records,
        'stats'     => $stats
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
