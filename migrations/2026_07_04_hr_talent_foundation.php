<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: HR talent & engagement foundation (Tier 4) — announcements, meetings, trips, checklists, recruitment, ESS link...\n";

try {
    // ── ESS linchpin: users.employee_id (D24, additive, guarded) ────────────
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'employee_id'")->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN employee_id INT NULL DEFAULT NULL AFTER role_id");
        echo "  + users.employee_id added.\n";
    } else {
        echo "  · users.employee_id already present.\n";
    }

    // ── Announcements (D25) ─────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            announcement_id INT AUTO_INCREMENT PRIMARY KEY,
            title           VARCHAR(255) NOT NULL,
            body            TEXT NOT NULL,
            priority        ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
            audience_type   ENUM('all','department','project') NOT NULL DEFAULT 'all',
            department_id   INT NULL,
            project_id      INT NULL,
            publish_date    DATE NOT NULL,
            expire_date     DATE NULL,
            status          ENUM('draft','published','archived','deleted') NOT NULL DEFAULT 'draft',
            created_by      INT NOT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by      INT NULL,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ann (status, publish_date, expire_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table announcements ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcement_reads (
            announcement_id INT NOT NULL,
            user_id         INT NOT NULL,
            read_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (announcement_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table announcement_reads ensured.\n";

    // ── Meetings (D29) ──────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meetings (
            meeting_id   INT AUTO_INCREMENT PRIMARY KEY,
            title        VARCHAR(255) NOT NULL,
            agenda       TEXT NULL,
            meeting_date DATE NOT NULL,
            start_time   TIME NULL,
            end_time     TIME NULL,
            venue        VARCHAR(255) NULL,
            minutes      TEXT NULL,
            status       ENUM('scheduled','completed','cancelled','deleted') NOT NULL DEFAULT 'scheduled',
            created_by   INT NOT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by   INT NULL,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mt (status, meeting_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table meetings ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meeting_attendees (
            meeting_id  INT NOT NULL,
            employee_id INT NOT NULL,
            attended    TINYINT(1) NULL,
            PRIMARY KEY (meeting_id, employee_id),
            CONSTRAINT fk_ma_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table meeting_attendees ensured.\n";

    // ── Business trips (D26) ────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_trips (
            trip_id           INT AUTO_INCREMENT PRIMARY KEY,
            employee_id       INT NOT NULL,
            purpose           VARCHAR(500) NOT NULL,
            destination       VARCHAR(255) NOT NULL,
            start_date        DATE NOT NULL,
            end_date          DATE NOT NULL,
            estimated_cost    DECIMAL(15,2) NULL,
            requested_advance DECIMAL(15,2) NULL,
            expense_reference VARCHAR(100) NULL,
            report            TEXT NULL,
            attachment_path   VARCHAR(500) NULL,
            attachment_name   VARCHAR(255) NULL,
            status            ENUM('pending','approved','rejected','completed','cancelled','deleted')
                              NOT NULL DEFAULT 'pending',
            approved_by       INT NULL,
            approved_at       DATETIME NULL,
            reject_reason     VARCHAR(500) NULL,
            created_by        INT NOT NULL,
            created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by        INT NULL,
            updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_tr_emp (employee_id, status),
            CONSTRAINT fk_tp2_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_trips ensured.\n";

    // ── Onboarding / offboarding checklists (D30) ───────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS checklist_templates (
            template_id   INT AUTO_INCREMENT PRIMARY KEY,
            template_name VARCHAR(150) NOT NULL,
            template_type ENUM('onboarding','offboarding') NOT NULL,
            is_default    TINYINT(1) NOT NULL DEFAULT 0,
            status        ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
            created_by    INT NOT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tpl (template_name, template_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table checklist_templates ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS checklist_template_items (
            item_id     INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            item_text   VARCHAR(500) NOT NULL,
            sort_order  INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_cti_tpl FOREIGN KEY (template_id) REFERENCES checklist_templates(template_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table checklist_template_items ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_checklists (
            checklist_id   INT AUTO_INCREMENT PRIMARY KEY,
            employee_id    INT NOT NULL,
            template_id    INT NULL,
            checklist_type ENUM('onboarding','offboarding') NOT NULL,
            status         ENUM('in_progress','completed','cancelled','deleted') NOT NULL DEFAULT 'in_progress',
            completed_at   DATETIME NULL,
            created_by     INT NOT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ec2_emp (employee_id, checklist_type, status),
            CONSTRAINT fk_ec2_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_checklists ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_checklist_items (
            item_id      INT AUTO_INCREMENT PRIMARY KEY,
            checklist_id INT NOT NULL,
            item_text    VARCHAR(500) NOT NULL,
            sort_order   INT NOT NULL DEFAULT 0,
            is_done      TINYINT(1) NOT NULL DEFAULT 0,
            done_by      INT NULL,
            done_at      DATETIME NULL,
            notes        VARCHAR(500) NULL,
            CONSTRAINT fk_eci_cl FOREIGN KEY (checklist_id) REFERENCES employee_checklists(checklist_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_checklist_items ensured.\n";

    // ── Recruitment / internal ATS (D27) ────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS job_openings (
            opening_id     INT AUTO_INCREMENT PRIMARY KEY,
            job_title      VARCHAR(255) NOT NULL,
            designation_id INT NULL,
            department_id  INT NULL,
            description    TEXT NULL,
            requirements   TEXT NULL,
            openings_count INT NOT NULL DEFAULT 1,
            close_date     DATE NULL,
            status         ENUM('open','on_hold','closed','deleted') NOT NULL DEFAULT 'open',
            created_by     INT NOT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by     INT NULL,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_jo (status, close_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table job_openings ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS candidates (
            candidate_id        INT AUTO_INCREMENT PRIMARY KEY,
            opening_id          INT NOT NULL,
            full_name           VARCHAR(255) NOT NULL,
            email               VARCHAR(255) NULL,
            phone               VARCHAR(50) NULL,
            source              VARCHAR(100) NULL,
            cv_path             VARCHAR(500) NULL,
            cv_name             VARCHAR(255) NULL,
            library_document_id INT NULL,
            stage               ENUM('applied','shortlisted','interview','offered','hired','rejected')
                                NOT NULL DEFAULT 'applied',
            stage_notes         VARCHAR(500) NULL,
            hired_employee_id   INT NULL,
            status              ENUM('active','deleted') NOT NULL DEFAULT 'active',
            created_by          INT NOT NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by          INT NULL,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_cand (opening_id, stage, status),
            CONSTRAINT fk_cand_opening FOREIGN KEY (opening_id) REFERENCES job_openings(opening_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table candidates ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS candidate_interviews (
            interview_id   INT AUTO_INCREMENT PRIMARY KEY,
            candidate_id   INT NOT NULL,
            interview_date DATE NOT NULL,
            interview_time TIME NULL,
            interviewers   VARCHAR(500) NULL,
            rating         TINYINT NULL,
            feedback       TEXT NULL,
            status         ENUM('scheduled','done','cancelled','deleted') NOT NULL DEFAULT 'scheduled',
            created_by     INT NOT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ci_candidate FOREIGN KEY (candidate_id) REFERENCES candidates(candidate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table candidate_interviews ensured.\n";

    // ── Seed one default onboarding + one default offboarding template (D28 b/c) ─
    $seedTemplate = function (string $name, string $type, array $items) use ($pdo) {
        $chk = $pdo->prepare("SELECT template_id FROM checklist_templates WHERE template_name = ? AND template_type = ?");
        $chk->execute([$name, $type]);
        $tid = $chk->fetchColumn();
        if ($tid) return (int)$tid;
        $pdo->prepare("INSERT INTO checklist_templates (template_name, template_type, is_default, created_by) VALUES (?, ?, 1, 0)")
            ->execute([$name, $type]);
        $tid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO checklist_template_items (template_id, item_text, sort_order) VALUES (?, ?, ?)");
        foreach ($items as $i => $text) $ins->execute([$tid, $text, ($i + 1) * 10]);
        return $tid;
    };
    $onNew = $seedTemplate('Default Onboarding', 'onboarding', [
        'Sign employment contract', 'Collect ID, TIN and bank details', 'Set up email & system accounts',
        'Issue workstation / tools', 'Health & safety induction', 'Introduce to team & assign buddy',
        'Enroll in payroll', 'Explain leave & HR policies',
    ]);
    $offNew = $seedTemplate('Default Offboarding', 'offboarding', [
        'Conduct exit interview', 'Recover company assets (laptop, ID, tools)', 'Revoke system & email access',
        'Handover of duties & documents', 'Final salary & benefits settlement', 'Update org chart & records',
    ]);
    echo "  + default onboarding + offboarding templates seeded (ids resolved: $onNew / $offNew).\n";

    // ── Upload directories + deny-exec .htaccess (§19) ──────────────────────
    $rules = "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n"
           . "    Require all denied\n"
           . "</FilesMatch>\n"
           . "Options -ExecCGI\n"
           . "RemoveHandler .php .phtml .php5\n"
           . "RemoveType .php .phtml .php5\n";
    foreach (['candidate_cvs', 'trips'] as $dir) {
        $path = __DIR__ . '/../uploads/' . $dir;
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) { echo "Migration failed: could not create uploads/$dir.\n"; exit(1); }
            echo "  + uploads/$dir created.\n";
        } else {
            echo "  · uploads/$dir already exists.\n";
        }
        if (!file_exists("$path/.htaccess")) {
            if (file_put_contents("$path/.htaccess", $rules) === false) { echo "Migration failed: could not write uploads/$dir/.htaccess.\n"; exit(1); }
            echo "  + uploads/$dir/.htaccess written.\n";
        } else {
            echo "  · uploads/$dir/.htaccess already exists.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
