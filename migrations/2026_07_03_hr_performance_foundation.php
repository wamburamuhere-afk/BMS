<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: HR performance & development foundation (Tier 3) — indicators, appraisals, goals, training...\n";

try {
    // ── Competency framework ────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS performance_indicator_categories (
            category_id   INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(100) NOT NULL,
            sort_order    INT NOT NULL DEFAULT 0,
            status        ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
            created_by    INT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cat (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table performance_indicator_categories ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS performance_indicators (
            indicator_id   INT AUTO_INCREMENT PRIMARY KEY,
            category_id    INT NOT NULL,
            indicator_name VARCHAR(255) NOT NULL,
            description    VARCHAR(500) NULL,
            status         ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
            created_by     INT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pi_cat (category_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table performance_indicators ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS designation_indicator_targets (
            target_id       INT AUTO_INCREMENT PRIMARY KEY,
            designation_id  INT NOT NULL,
            indicator_id    INT NOT NULL,
            expected_rating TINYINT NOT NULL,
            created_by      INT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by      INT NULL,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_desig_ind (designation_id, indicator_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table designation_indicator_targets ensured.\n";

    // ── Appraisals ──────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appraisal_cycles (
            cycle_id    INT AUTO_INCREMENT PRIMARY KEY,
            cycle_name  VARCHAR(100) NOT NULL,
            period_from DATE NOT NULL,
            period_to   DATE NOT NULL,
            status      ENUM('open','closed','deleted') NOT NULL DEFAULT 'open',
            created_by  INT NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cycle (cycle_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table appraisal_cycles ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_appraisals (
            appraisal_id    INT AUTO_INCREMENT PRIMARY KEY,
            cycle_id        INT NOT NULL,
            employee_id     INT NOT NULL,
            designation_id  INT NULL,
            appraisal_date  DATE NOT NULL,
            overall_rating  DECIMAL(3,2) NULL,
            remarks         TEXT NULL,
            status          ENUM('draft','submitted','approved','rejected','deleted')
                            NOT NULL DEFAULT 'draft',
            approved_by     INT NULL,
            approved_at     DATETIME NULL,
            reject_reason   VARCHAR(500) NULL,
            created_by      INT NOT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by      INT NULL,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cycle_emp (cycle_id, employee_id),
            KEY idx_ea_emp (employee_id, status),
            CONSTRAINT fk_ea_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_appraisals ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_appraisal_items (
            item_id         INT AUTO_INCREMENT PRIMARY KEY,
            appraisal_id    INT NOT NULL,
            indicator_id    INT NOT NULL,
            expected_rating TINYINT NULL,
            actual_rating   TINYINT NOT NULL,
            comment         VARCHAR(500) NULL,
            UNIQUE KEY uniq_app_ind (appraisal_id, indicator_id),
            CONSTRAINT fk_eai_appraisal FOREIGN KEY (appraisal_id)
                REFERENCES employee_appraisals(appraisal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_appraisal_items ensured.\n";

    // ── Goals ───────────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS goal_types (
            goal_type_id INT AUTO_INCREMENT PRIMARY KEY,
            type_name    VARCHAR(100) NOT NULL,
            status       ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
            created_by   INT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_goal_type (type_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table goal_types ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_goals (
            goal_id        INT AUTO_INCREMENT PRIMARY KEY,
            employee_id    INT NOT NULL,
            goal_type_id   INT NOT NULL,
            subject        VARCHAR(255) NOT NULL,
            description    TEXT NULL,
            start_date     DATE NOT NULL,
            end_date       DATE NOT NULL,
            progress       TINYINT NOT NULL DEFAULT 0,
            status         ENUM('not_started','in_progress','completed','cancelled','deleted')
                           NOT NULL DEFAULT 'not_started',
            created_by     INT NOT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by     INT NULL,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_eg_emp (employee_id, status),
            CONSTRAINT fk_eg_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_goals ensured.\n";

    // ── Training ────────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS training_types (
            training_type_id INT AUTO_INCREMENT PRIMARY KEY,
            type_name        VARCHAR(100) NOT NULL,
            status           ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
            created_by       INT NULL,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_training_type (type_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table training_types ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trainings (
            training_id         INT AUTO_INCREMENT PRIMARY KEY,
            training_type_id    INT NOT NULL,
            title               VARCHAR(255) NOT NULL,
            description         TEXT NULL,
            trainer_kind        ENUM('internal','external') NOT NULL DEFAULT 'internal',
            trainer_employee_id INT NULL,
            trainer_name        VARCHAR(255) NULL,
            venue               VARCHAR(255) NULL,
            start_date          DATE NOT NULL,
            end_date            DATE NULL,
            cost                DECIMAL(15,2) NULL,
            status              ENUM('planned','in_progress','completed','cancelled','deleted')
                                NOT NULL DEFAULT 'planned',
            created_by          INT NOT NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by          INT NULL,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_tr_status (status, start_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table trainings ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS training_participants (
            participant_id          INT AUTO_INCREMENT PRIMARY KEY,
            training_id             INT NOT NULL,
            employee_id             INT NOT NULL,
            status                  ENUM('enrolled','attended','completed','failed','withdrawn')
                                    NOT NULL DEFAULT 'enrolled',
            score                   VARCHAR(50) NULL,
            remarks                 VARCHAR(500) NULL,
            certificate_path        VARCHAR(500) NULL,
            certificate_name        VARCHAR(255) NULL,
            certificate_expire_date DATE NULL,
            library_document_id     INT NULL,
            updated_by              INT NULL,
            updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_training_emp (training_id, employee_id),
            KEY idx_tp_emp (employee_id, status),
            CONSTRAINT fk_tp_training FOREIGN KEY (training_id) REFERENCES trainings(training_id),
            CONSTRAINT fk_tp_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table training_participants ensured.\n";

    // ── Seeds (INSERT IGNORE — idempotent) ──────────────────────────────────
    $catSeed = $pdo->prepare("INSERT IGNORE INTO performance_indicator_categories (category_name, sort_order) VALUES (?, ?)");
    $n = 0;
    foreach ([['Technical', 10], ['Behavioural', 20], ['Organizational', 30]] as $c) { $catSeed->execute($c); $n += $catSeed->rowCount(); }
    echo "  + indicator categories seeded ($n new).\n";

    $goalSeed = $pdo->prepare("INSERT IGNORE INTO goal_types (type_name) VALUES (?)");
    $n = 0;
    foreach (['Annual', 'Quarterly', 'Monthly', 'Project'] as $t) { $goalSeed->execute([$t]); $n += $goalSeed->rowCount(); }
    echo "  + goal types seeded ($n new).\n";

    $trSeed = $pdo->prepare("INSERT IGNORE INTO training_types (type_name) VALUES (?)");
    $n = 0;
    foreach (['Technical', 'Soft Skills', 'Compliance & Safety', 'Induction'] as $t) { $trSeed->execute([$t]); $n += $trSeed->rowCount(); }
    echo "  + training types seeded ($n new).\n";

    // ── Upload directory + deny-exec .htaccess (§19) ────────────────────────
    $rules = "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n"
           . "    Require all denied\n"
           . "</FilesMatch>\n"
           . "Options -ExecCGI\n"
           . "RemoveHandler .php .phtml .php5\n"
           . "RemoveType .php .phtml .php5\n";
    $path = __DIR__ . '/../uploads/training_certs';
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) { echo "Migration failed: could not create uploads/training_certs.\n"; exit(1); }
        echo "  + uploads/training_certs created.\n";
    } else {
        echo "  · uploads/training_certs already exists.\n";
    }
    if (!file_exists("$path/.htaccess")) {
        if (file_put_contents("$path/.htaccess", $rules) === false) { echo "Migration failed: could not write uploads/training_certs/.htaccess.\n"; exit(1); }
        echo "  + uploads/training_certs/.htaccess written.\n";
    } else {
        echo "  · uploads/training_certs/.htaccess already exists.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
