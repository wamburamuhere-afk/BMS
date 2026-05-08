<?php
/**
 * Security Functions for POS System
 * Provides CSRF protection, input validation, sanitization, and audit logging
 */

class POSSecurity {
    private $pdo;
    private $sessionKey = 'pos_csrf_token';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Generate CSRF token and store in session
     * @return string The generated token
     */
    public function generateCSRFToken() {
        if (!isset($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$this->sessionKey];
    }
    
    /**
     * Validate CSRF token
     * @param string $token Token to validate
     * @return bool True if valid, false otherwise
     */
    public function validateCSRFToken($token) {
        if (!isset($_SESSION[$this->sessionKey])) {
            return false;
        }
        return hash_equals($_SESSION[$this->sessionKey], $token);
    }
    
    /**
     * Sanitize input based on type
     * @param mixed $input Input to sanitize
     * @param string $type Type of sanitization (string, email, int, float, html)
     * @return mixed Sanitized input
     */
    public function sanitizeInput($input, $type = 'string') {
        if (is_array($input)) {
            return array_map(function($item) use ($type) {
                return $this->sanitizeInput($item, $type);
            }, $input);
        }
        
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            
            case 'html':
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            
            case 'string':
            default:
                return trim(strip_tags($input));
        }
    }
    
    /**
     * Validate input against rules
     * @param mixed $input Input to validate
     * @param array $rules Validation rules
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateInput($input, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $fieldRules) {
            $value = isset($input[$field]) ? $input[$field] : null;
            
            foreach ($fieldRules as $rule => $ruleValue) {
                switch ($rule) {
                    case 'required':
                        if ($ruleValue && empty($value)) {
                            $errors[$field][] = ucfirst($field) . ' is required';
                        }
                        break;
                    
                    case 'min':
                        if (is_numeric($value) && $value < $ruleValue) {
                            $errors[$field][] = ucfirst($field) . " must be at least {$ruleValue}";
                        }
                        break;
                    
                    case 'max':
                        if (is_numeric($value) && $value > $ruleValue) {
                            $errors[$field][] = ucfirst($field) . " must not exceed {$ruleValue}";
                        }
                        break;
                    
                    case 'minLength':
                        if (strlen($value) < $ruleValue) {
                            $errors[$field][] = ucfirst($field) . " must be at least {$ruleValue} characters";
                        }
                        break;
                    
                    case 'maxLength':
                        if (strlen($value) > $ruleValue) {
                            $errors[$field][] = ucfirst($field) . " must not exceed {$ruleValue} characters";
                        }
                        break;
                    
                    case 'email':
                        if ($ruleValue && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = ucfirst($field) . ' must be a valid email';
                        }
                        break;
                    
                    case 'numeric':
                        if ($ruleValue && !is_numeric($value)) {
                            $errors[$field][] = ucfirst($field) . ' must be numeric';
                        }
                        break;
                    
                    case 'in':
                        if (!in_array($value, $ruleValue)) {
                            $errors[$field][] = ucfirst($field) . ' has invalid value';
                        }
                        break;
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Check rate limit for an action
     * @param string $action Action identifier
     * @param int $limit Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return bool True if within limit, false if exceeded
     */
    public function checkRateLimit($action, $limit = 10, $windowSeconds = 60) {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        $userId = $_SESSION['user_id'];
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
        
        try {
            // Clean old entries
            $stmt = $this->pdo->prepare("
                DELETE FROM rate_limits 
                WHERE user_id = ? AND action = ? AND window_start < ?
            ");
            $stmt->execute([$userId, $action, $windowStart]);
            
            // Check current count
            $stmt = $this->pdo->prepare("
                SELECT SUM(attempt_count) as total 
                FROM rate_limits 
                WHERE user_id = ? AND action = ? AND window_start >= ?
            ");
            $stmt->execute([$userId, $action, $windowStart]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $currentCount = $result['total'] ?? 0;
            
            if ($currentCount >= $limit) {
                $this->logAudit('rate_limit_exceeded', [
                    'action' => $action,
                    'limit' => $limit,
                    'window' => $windowSeconds
                ]);
                return false;
            }
            
            // Increment counter
            $stmt = $this->pdo->prepare("
                INSERT INTO rate_limits (user_id, action, attempt_count, window_start)
                VALUES (?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1
            ");
            $stmt->execute([$userId, $action]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Rate limit check failed: " . $e->getMessage());
            return true; // Fail open to not block legitimate users
        }
    }
    
    /**
     * Log audit trail
     * @param string $action Action performed
     * @param array $data Additional data
     * @return bool Success status
     */
    public function logAudit($action, $data = []) {
        try {
            $userId = $_SESSION['user_id'] ?? null;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs (
                    user_id, action, activity_type, entity_type, entity_id, 
                    description, old_values, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            return $stmt->execute([
                $userId,
                $action,
                $data['activity_type'] ?? $action,
                $data['entity_type'] ?? null,
                $data['entity_id'] ?? null,
                $data['description'] ?? null,
                isset($data['old_values']) ? json_encode($data['old_values']) : null,
                isset($data['new_values']) ? json_encode($data['new_values']) : null,
                $ipAddress,
                $userAgent
            ]);
            
        } catch (PDOException $e) {
            error_log("Audit log failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate session and check for hijacking
     * @return bool True if session is valid
     */
    public function validateSession() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        // Check session timeout (30 minutes)
        if (isset($_SESSION['last_activity'])) {
            $timeout = 1800; // 30 minutes
            if (time() - $_SESSION['last_activity'] > $timeout) {
                session_unset();
                session_destroy();
                return false;
            }
        }
        $_SESSION['last_activity'] = time();
        
        // Check for session hijacking
        $currentFingerprint = $this->getSessionFingerprint();
        if (isset($_SESSION['fingerprint'])) {
            if ($_SESSION['fingerprint'] !== $currentFingerprint) {
                session_unset();
                session_destroy();
                $this->logAudit('session_hijacking_attempt', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                return false;
            }
        } else {
            $_SESSION['fingerprint'] = $currentFingerprint;
        }
        
        return true;
    }
    
    /**
     * Generate session fingerprint
     * @return string Fingerprint hash
     */
    private function getSessionFingerprint() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        
        return hash('sha256', $userAgent . $acceptLanguage . $acceptEncoding);
    }
    
    /**
     * Validate API request
     * @param array $requiredFields Required fields in request
     * @return array ['valid' => bool, 'errors' => array, 'data' => array]
     */
    public function validateAPIRequest($requiredFields = []) {
        $errors = [];
        $data = [];
        
        // Check session
        if (!$this->validateSession()) {
            return [
                'valid' => false,
                'errors' => ['Session expired or invalid'],
                'data' => []
            ];
        }
        
        // Get request data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $rawData = file_get_contents('php://input');
            $data = json_decode($rawData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'valid' => false,
                    'errors' => ['Invalid JSON data'],
                    'data' => []
                ];
            }
        } else {
            $data = $_POST;
        }
        
        // Check CSRF token for POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!$this->validateCSRFToken($token)) {
                return [
                    'valid' => false,
                    'errors' => ['Invalid CSRF token'],
                    'data' => []
                ];
            }
        }
        
        // Check required fields
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = ucfirst($field) . ' is required';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }
}
