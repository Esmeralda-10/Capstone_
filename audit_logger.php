<?php
/**
* Audit Logger Helper
* Include this file to enable audit logging functionality
*/

function logAuditAction($pdo, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null, $username = null, $userId = null) {
// Validate PDO connection
if (!$pdo || !($pdo instanceof PDO)) {
    error_log("Audit log error: Invalid PDO connection");
    return false;
}

try {
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Validate action is not empty
    if (empty($action)) {
        error_log("Audit log error: Action cannot be empty");
        return false;
    }

    // Get user information - use provided parameters or fall back to session
    if ($username === null || $username === '') {
        $username = $_SESSION['username'] ?? $_SESSION['admin_name'] ?? 'System';
    }
    if ($userId === null || $userId === '') {
        $userId = $_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
    }

    // Ensure username is not empty
    if (empty($username)) {
        $username = 'System';
    }

    // Get IP address with better handling for proxies
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Handle multiple IPs in X-Forwarded-For header
        $forwardedIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ipAddress = trim($forwardedIps[0]);
    }
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
    }

    // Get user agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    // Limit user agent length to prevent issues
    if ($userAgent && strlen($userAgent) > 500) {
        $userAgent = substr($userAgent, 0, 500);
    }

    // Convert arrays/objects to JSON
    if ($oldValues !== null) {
        if (is_array($oldValues) || is_object($oldValues)) {
            $oldValues = json_encode($oldValues, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($oldValues)) {
            // Already a string, keep as is
        } else {
            $oldValues = json_encode($oldValues, JSON_UNESCAPED_UNICODE);
        }
    }

    if ($newValues !== null) {
        if (is_array($newValues) || is_object($newValues)) {
            $newValues = json_encode($newValues, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($newValues)) {
            // Already a string, keep as is
        } else {
            $newValues = json_encode($newValues, JSON_UNESCAPED_UNICODE);
        }
    }

    // Ensure the audit_logs table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `audit_logs` (
        `log_id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` varchar(100) DEFAULT NULL,
        `username` varchar(100) DEFAULT NULL,
        `action` varchar(255) NOT NULL,
        `table_name` varchar(100) DEFAULT NULL,
        `record_id` int(11) DEFAULT NULL,
        `old_values` text DEFAULT NULL,
        `new_values` text DEFAULT NULL,
        `ip_address` varchar(45) DEFAULT NULL,
        `user_agent` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`log_id`),
        KEY `idx_user` (`username`),
        KEY `idx_action` (`action`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insert audit log
    $stmt = $pdo->prepare("INSERT INTO audit_logs
        (user_id, username, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $userId,
        $username,
        $action,
        $tableName,
        $recordId,
        $oldValues,
        $newValues,
        $ipAddress,
        $userAgent
    ]);

    return true;
} catch (PDOException $e) {
    // Silently fail to not break the main application
    error_log("Audit log error: " . $e->getMessage());
    return false;
} catch (Exception $e) {
    error_log("Audit log error: " . $e->getMessage());
    return false;
}
}

// Authentication logging functions

function logLogin($pdo, $username, $success = true, $userId = null) {
if (empty($username)) {
    $username = 'Unknown';
}
$action = $success ? "User Login" : "Failed Login Attempt";
logAuditAction($pdo, $action, 'users', null, null, ['username' => $username, 'success' => $success], $username, $userId);
}

function logLogout($pdo, $username, $userId = null) {
if (empty($username)) {
    $username = 'Unknown';
}
logAuditAction($pdo, "User Logout", 'users', null, null, ['username' => $username], $username, $userId);
}

// CRUD operation logging functions

function logCreate($pdo, $tableName, $recordId, $newValues) {
if (empty($tableName)) {
    error_log("Audit log error: Table name is required for logCreate");
    return false;
}
logAuditAction($pdo, "Create Record", $tableName, $recordId, null, $newValues);
return true;
}

function logUpdate($pdo, $tableName, $recordId, $oldValues, $newValues) {
if (empty($tableName)) {
    error_log("Audit log error: Table name is required for logUpdate");
    return false;
}
logAuditAction($pdo, "Update Record", $tableName, $recordId, $oldValues, $newValues);
return true;
}

function logDelete($pdo, $tableName, $recordId, $oldValues) {
if (empty($tableName)) {
    error_log("Audit log error: Table name is required for logDelete");
    return false;
}
logAuditAction($pdo, "Delete Record", $tableName, $recordId, $oldValues, null);
return true;
}

function logView($pdo, $tableName, $recordId = null) {
if (empty($tableName)) {
    error_log("Audit log error: Table name is required for logView");
    return false;
}
logAuditAction($pdo, "View Record", $tableName, $recordId, null, null);
return true;
}

// Communication logging functions

function logEmail($pdo, $recipient, $subject, $additionalData = null) {
if (empty($recipient)) {
    error_log("Audit log error: Recipient is required for logEmail");
    return false;
}
$newValues = [
    'recipient' => $recipient,
    'subject' => $subject ?? 'No Subject'
];
if ($additionalData && is_array($additionalData)) {
    $newValues = array_merge($newValues, $additionalData);
}
logAuditAction($pdo, "Send Email", null, null, null, $newValues);
return true;
}
