<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: HR compliance foundation (Tier 2) — documents, contracts, org column...\n";

try {
    // ── 1. employee_document_types (D11) ────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_document_types (
            doc_type_id     INT AUTO_INCREMENT PRIMARY KEY,
            type_name       VARCHAR(100) NOT NULL,
            requires_expiry TINYINT(1) NOT NULL DEFAULT 0,
            sort_order      INT NOT NULL DEFAULT 0,
            status          ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
            created_by      INT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_type_name (type_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_document_types ensured.\n";

    // Seed the D11 list — permits/licenses/contracts require an expiry date
    $types = [
        // [name, requires_expiry, sort]
        ['CV/Resume',            0, 10],
        ['ID Copy',              0, 20],
        ['Certificate',          0, 30],
        ['Employment Contract',  1, 40],
        ['Work Permit',          1, 50],
        ['Professional License', 1, 60],
        ['Medical',              0, 70],
        ['Other',                0, 80],
    ];
    $seed = $pdo->prepare("INSERT IGNORE INTO employee_document_types (type_name, requires_expiry, sort_order) VALUES (?, ?, ?)");
    $n = 0;
    foreach ($types as $t) { $seed->execute($t); $n += $seed->rowCount(); }
    echo "  + document types seeded ($n new of " . count($types) . ").\n";

    // ── 2. employee_documents (D8) ───────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_documents (
            emp_doc_id          INT AUTO_INCREMENT PRIMARY KEY,
            employee_id         INT NOT NULL,
            doc_type_id         INT NOT NULL,
            document_name       VARCHAR(255) NOT NULL,
            file_path           VARCHAR(500) NOT NULL,
            original_filename   VARCHAR(255) NOT NULL,
            file_size           INT NOT NULL DEFAULT 0,
            issue_date          DATE NULL,
            expire_date         DATE NULL,
            library_document_id INT NULL,
            notes               VARCHAR(500) NULL,
            status              ENUM('active','archived','deleted') NOT NULL DEFAULT 'active',
            created_by          INT NOT NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by          INT NULL,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_emp_doc (employee_id, status),
            KEY idx_expire (status, expire_date),
            CONSTRAINT fk_ed_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_documents ensured.\n";

    // ── 3. employee_contracts (D12) ──────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_contracts (
            contract_id              INT AUTO_INCREMENT PRIMARY KEY,
            employee_id              INT NOT NULL,
            contract_type            VARCHAR(100) NOT NULL,
            start_date               DATE NOT NULL,
            end_date                 DATE NULL,
            probation_months         INT NULL,
            basic_salary             DECIMAL(15,2) NULL,
            terms                    TEXT NULL,
            attachment_path          VARCHAR(500) NULL,
            attachment_name          VARCHAR(255) NULL,
            library_document_id      INT NULL,
            status                   ENUM('draft','active','expired','renewed','terminated','deleted')
                                     NOT NULL DEFAULT 'draft',
            renewed_from_contract_id INT NULL,
            activated_by             INT NULL,
            activated_at             DATETIME NULL,
            created_by               INT NOT NULL,
            created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by               INT NULL,
            updated_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_emp_contract (employee_id, status),
            KEY idx_end (status, end_date),
            CONSTRAINT fk_ec_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table employee_contracts ensured.\n";

    // ── 4. employees.reporting_to_id (D14 — additive, guarded) ──────────────
    if (!$pdo->query("SHOW COLUMNS FROM employees LIKE 'reporting_to_id'")->fetch()) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN reporting_to_id INT NULL DEFAULT NULL AFTER reporting_to");
        echo "  + employees.reporting_to_id added.\n";
    } else {
        echo "  · employees.reporting_to_id already present.\n";
    }

    // ── 5. Upload directories + deny-exec .htaccess (§19) ───────────────────
    $rules = "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n"
           . "    Require all denied\n"
           . "</FilesMatch>\n"
           . "Options -ExecCGI\n"
           . "RemoveHandler .php .phtml .php5\n"
           . "RemoveType .php .phtml .php5\n";
    foreach (['employee_docs', 'contracts'] as $dir) {
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
