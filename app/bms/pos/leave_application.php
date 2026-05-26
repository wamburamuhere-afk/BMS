<?php
// File: app/bms/pos/leave_application.php
// scope-audit: skip — leave application form; write path gated via assertScopeForEmployee; read-side bulk scope deferred to Phase G-2
require_once __DIR__ . '/../../../roots.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

// Phase 5b — print pages get a canView gate (admin auto-bypass)
if (!canView('leaves')) die("Access Denied");

// Get current user name for the footer
$user_stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
$user_stmt->execute([$_SESSION['user_id']]);
$current_username = $user_stmt->fetchColumn() ?: 'System';

// Get Leave ID
$leave_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($leave_id <= 0) {
    die("Invalid Leave ID");
}

// Fetch Leave Details
global $pdo;
$stmt = $pdo->prepare("
    SELECT 
        l.*,
        e.first_name,
        e.last_name,
        e.employee_number,
        des.designation_name as designation,
        d.department_name,
        u1.username as applied_by_name,
        u2.username as approved_by_name
    FROM leaves l
    LEFT JOIN employees e ON l.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN designations des ON e.designation_id = des.designation_id
    LEFT JOIN users u1 ON l.applied_by = u1.user_id
    LEFT JOIN users u2 ON l.approved_by = u2.user_id
    WHERE l.leave_id = ?
");
$stmt->execute([$leave_id]);
$leave = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    die("Leave Application Not Found");
}

// Get company details
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_address', 'company_phone', 'company_email', 'company_logo')");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$company_name = $settings['company_name'] ?? 'BMS INDUSTRIAL SOLUTIONS';
$company_logo = $settings['company_logo'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave form - #<?= $leave['leave_id'] ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; }
        body { font-family: 'Inter', sans-serif; color: #1e293b; line-height: 1.5; margin: 0; background: #f1f5f9; }
        .page { width: 210mm; min-height: 297mm; padding: 20mm; margin: 20mm auto; background: white; box-shadow: 0 0 10px rgba(0,0,0,0.1); position: relative; }
        
        .letterhead { display: flex; justify-content: space-between; border-bottom: 3px solid #0f172a; padding-bottom: 20px; margin-bottom: 30px; }
        .brand h1 { margin: 0; font-size: 24px; color: #0f172a; text-transform: uppercase; letter-spacing: 1px; }
        .brand p { margin: 5px 0 0; font-size: 13px; color: #64748b; max-width: 300px; }
        .doc-meta { text-align: right; }
        .doc-meta h2 { margin: 0; color: #020617; font-size: 20px; font-weight: 700; }
        .doc-meta p { margin: 5px 0 0; font-size: 14px; font-weight: 600; color: #475569; }

        .status-badge { position: absolute; top: 120px; right: 50px; border: 4px solid; padding: 10px 30px; border-radius: 8px; font-size: 24px; font-weight: 900; transform: rotate(15deg); opacity: 0.15; z-index: 0; }
        .status-approved { border-color: #10b981; color: #10b981; }
        .status-pending { border-color: #f59e0b; color: #f59e0b; }
        .status-rejected { border-color: #ef4444; color: #ef4444; }

        .section { margin-bottom: 30px; position: relative; z-index: 1; }
        .section-header { background: #f8fafc; padding: 8px 15px; border-radius: 6px; font-weight: 700; font-size: 13px; color: #475569; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 0.5px; border-left: 5px solid #0f172a; }
        
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .field { margin-bottom: 10px; }
        .label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
        .value { font-size: 14px; font-weight: 600; color: #0f172a; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; }

        .full-field { margin-top: 15px; }
        .reason-text { background: #fdfdfd; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; min-height: 100px; font-size: 14px; color: #334155; }

        .signatures { margin-top: 60px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 40px; }
        .sig-box { text-align: center; }
        .sig-line { border-top: 2px solid #1e293b; margin-top: 40px; padding-top: 10px; font-size: 12px; font-weight: 700; }

        .footer { margin-top: 50px; border-top: 1px solid #e2e8f0; padding-top: 10px; text-align: center; font-size: 10px; color: #94a3b8; }

        @page {
            size: A4;
            margin: 0 !important;
        }

        @media print {
            * { -webkit-print-color-adjust: exact !important; color-adjust: exact !important; }
            html, body {
                margin: 0 !important;
                padding: 0 !important;
                height: auto !important;
                background: white !important;
            }
            .page { 
                margin: 0 !important; 
                padding: 20mm !important;
                box-shadow: none !important; 
                border: none !important; 
                width: 210mm !important;
                min-height: 297mm !important;
            }
            .no-print { display: none !important; }
            .footer { display: none !important; } /* Hide the manual footer too as requested */
        }
    </style>
</head>
<body>

<div class="no-print" style="position: fixed; top: 20px; right: 20px; z-index: 100;">
    <button onclick="window.print()" style="background: #0f172a; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        PRINT
    </button>
</div>

<div class="page">
    <div class="status-badge status-<?= strtolower($leave['status']) ?>">
        <?= strtoupper($leave['status']) ?>
    </div>

    <div style="text-align: center; margin-bottom: 2rem;">
        <?php if(!empty($company_logo)): ?>
            <div style="margin-bottom: 1rem;">
                <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt; font-family: sans-serif;"><?= safe_output($company_name) ?></h1>
        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px; font-family: sans-serif;">LEAVE APPLICATION REPORT</h2>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
        <p style="text-align: right; font-weight: 600; color: #475569; margin-bottom: 20px; font-size: 12px; font-family: sans-serif;">REFERENCE: #LEV-<?= $leave['leave_id'] ?> &nbsp;|&nbsp; DATE: <?= date('d M Y') ?></p>
    </div>

    <!-- Employee Section -->
    <div class="section">
        <div class="section-header">Employee Snapshot</div>
        <div class="grid">
            <div class="field">
                <div class="label">Employee Name</div>
                <div class="value"><?= safe_output($leave['first_name'] . ' ' . $leave['last_name']) ?></div>
            </div>
            <div class="field">
                <div class="label">Employee ID</div>
                <div class="value"><?= safe_output($leave['employee_number']) ?></div>
            </div>
            <div class="field">
                <div class="label">Department / Unit</div>
                <div class="value"><?= safe_output($leave['department_name']) ?></div>
            </div>
            <div class="field">
                <div class="label">Designation</div>
                <div class="value"><?= safe_output($leave['designation'] ?? 'N/A') ?></div>
            </div>
        </div>
    </div>

    <!-- Leave Section -->
    <div class="section">
        <div class="section-header">Leave Information</div>
        <div class="grid">
            <div class="field">
                <div class="label">Leave Category</div>
                <div class="value"><?= ucfirst($leave['leave_type']) ?></div>
            </div>
            <div class="field">
                <div class="label">Total Duration</div>
                <div class="value"><?= $leave['total_days'] ?> Official Days</div>
            </div>
            <div class="field">
                <div class="label">Commencement Date</div>
                <div class="value"><?= date('l, d M Y', strtotime($leave['start_date'])) ?></div>
            </div>
            <div class="field">
                <div class="label">Resumption Date</div>
                <div class="value"><?= date('l, d M Y', strtotime($leave['end_date'])) ?></div>
            </div>
        </div>
        
        <div class="full-field">
            <div class="label">Justification / Reason</div>
            <div class="reason-text">
                <?= nl2br(safe_output($leave['reason'])) ?>
            </div>
        </div>
    </div>

    <!-- Approval Section -->
    <div class="section">
        <div class="section-header">Approval Timeline</div>
        <div class="grid">
            <div class="field">
                <div class="label">Application Submitted</div>
                <div class="value"><?= date('d/m/Y H:i', strtotime($leave['created_at'])) ?></div>
            </div>
            <div class="field">
                <div class="label">Authorized By</div>
                <div class="value"><?= safe_output($leave['approved_by_name'] ?? 'PENDING REVIEW') ?></div>
            </div>
        </div>
    </div>

    <!-- Signatures -->
    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line">APPLICANT SIGNATURE</div>
        </div>
        <div class="sig-box">
            <div class="sig-line">HR MANAGER</div>
        </div>
        <div class="sig-box">
            <div class="sig-line">MANAGING DIRECTOR</div>
        </div>
    </div>

    <!-- Footer removed as requested for a cleaner print -->
</div>

</body>
</html>
