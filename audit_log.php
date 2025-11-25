<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$host = "localhost";
$dbname = "pest control";
$username = "root";
$password = "";

// Initialize variables
$logs = [];
$totalLogs = 0;
$todayLogs = 0;
$thisWeekLogs = 0;
$sectionCounts = [];
$uniqueActions = [];
$uniqueUsers = [];
$sections = [];
$search = '';
$filterAction = '';
$filterUser = '';
$dateFrom = '';
$dateTo = '';
$section = 'all';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$recordsPerPage = 100; // Show 100 records per page
$offset = ($page - 1) * $recordsPerPage;
$totalRecords = 0;
$totalPages = 0;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create audit_logs table if it doesn't exist
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

    // Handle filtering and searching
    $search = $_GET['search'] ?? '';
    $filterAction = $_GET['filter_action'] ?? '';
    $filterUser = $_GET['filter_user'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $section = $_GET['section'] ?? 'all';

    // Define sections/categories
    $sections = [
        'all' => ['label' => 'All Activities', 'icon' => 'bi-list-ul', 'actions' => []],
        'login' => ['label' => 'Login/Logout', 'icon' => 'bi-box-arrow-in-right', 'actions' => ['User Login', 'User Logout', 'Failed Login Attempt']],
        'inventory' => ['label' => 'Inventory', 'icon' => 'bi-box-seam', 'actions' => ['Update Record', 'Create Record', 'Delete Record'], 'tables' => ['inventory']],
        'bookings' => ['label' => 'Bookings', 'icon' => 'bi-journal-text', 'actions' => ['Create Record', 'Update Record', 'Delete Record'], 'tables' => ['service_bookings']],
        'emails' => ['label' => 'Emails', 'icon' => 'bi-envelope', 'actions' => ['Send Email']],
    ];

    // Build query based on section
    $query = "SELECT * FROM audit_logs WHERE 1=1";
    $params = [];

    // Apply section filter
    if ($section !== 'all' && isset($sections[$section])) {
        $sectionConfig = $sections[$section];
        $conditions = [];

        // If tables are specified, filter by table (more specific)
        // If only actions are specified, filter by action
        if (!empty($sectionConfig['tables'])) {
            $tablePlaceholders = implode(',', array_fill(0, count($sectionConfig['tables']), '?'));
            $conditions[] = "table_name IN ($tablePlaceholders)";
            $params = array_merge($params, $sectionConfig['tables']);
            
            // If actions are also specified, add them as additional filter (AND)
            if (!empty($sectionConfig['actions'])) {
                $actionPlaceholders = implode(',', array_fill(0, count($sectionConfig['actions']), '?'));
                $conditions[] = "action IN ($actionPlaceholders)";
                $params = array_merge($params, $sectionConfig['actions']);
            }
        } elseif (!empty($sectionConfig['actions'])) {
            // Only actions specified, filter by action
            $placeholders = implode(',', array_fill(0, count($sectionConfig['actions']), '?'));
            $conditions[] = "action IN ($placeholders)";
            $params = array_merge($params, $sectionConfig['actions']);
        }

        if (!empty($conditions)) {
            // Use AND when both table and action filters exist, otherwise just the single condition
            $query .= " AND (" . implode(' AND ', $conditions) . ")";
        }
    }

    if ($search) {
        $query .= " AND (action LIKE ? OR username LIKE ? OR table_name LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    if ($filterAction) {
        $query .= " AND action = ?";
        $params[] = $filterAction;
    }

    if ($filterUser) {
        $query .= " AND username = ?";
        $params[] = $filterUser;
    }

    if ($dateFrom) {
        $query .= " AND DATE(created_at) >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo) {
        $query .= " AND DATE(created_at) <= ?";
        $params[] = $dateTo;
    }

    // Get total count for pagination (before adding ORDER BY, LIMIT, OFFSET)
    // Create a copy of params for the count query
    $countParams = $params;
    try {
        $countQuery = str_replace("SELECT *", "SELECT COUNT(*)", $query);
        // Remove ORDER BY if it exists (it shouldn't at this point)
        $countQuery = preg_replace('/\s+ORDER\s+BY\s+.*$/i', '', $countQuery);
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($countParams);
        $totalRecords = (int)$countStmt->fetchColumn();
        $totalPages = $totalRecords > 0 ? ceil($totalRecords / $recordsPerPage) : 0;
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
            $offset = ($page - 1) * $recordsPerPage;
        }
    } catch (PDOException $e) {
        $totalRecords = 0;
        $totalPages = 0;
        error_log("Audit log count error: " . $e->getMessage());
    }

    // Add ORDER BY, LIMIT and OFFSET for pagination (using PDO::PARAM_INT for LIMIT/OFFSET)
    $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $recordsPerPage;
    $params[] = $offset;

    try {
        $stmt = $pdo->prepare($query);
        // Bind all parameters
        $paramIndex = 1;
        foreach ($params as $index => $param) {
            // Last two parameters are LIMIT and OFFSET - bind as integers
            if ($index >= count($params) - 2) {
                $stmt->bindValue($paramIndex, (int)$param, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($paramIndex, $param);
            }
            $paramIndex++;
        }
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$logs) {
            $logs = [];
        }
    } catch (PDOException $e) {
        $logs = [];
        error_log("Audit log query error: " . $e->getMessage());
    }

    // Get counts for each section
    $sectionCounts = [];
    foreach ($sections as $key => $config) {
        try {
            if ($key === 'all') {
                $result = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
                $sectionCounts[$key] = $result ? (int)$result : 0;
            } else {
                $countQuery = "SELECT COUNT(*) FROM audit_logs WHERE ";
                $countParams = [];
                $conditions = [];

                // If tables are specified, filter by table (more specific)
                // If only actions are specified, filter by action
                if (!empty($config['tables'])) {
                    $tablePlaceholders = implode(',', array_fill(0, count($config['tables']), '?'));
                    $conditions[] = "table_name IN ($tablePlaceholders)";
                    $countParams = array_merge($countParams, $config['tables']);
                    
                    // If actions are also specified, add them as additional filter (AND)
                    if (!empty($config['actions'])) {
                        $actionPlaceholders = implode(',', array_fill(0, count($config['actions']), '?'));
                        $conditions[] = "action IN ($actionPlaceholders)";
                        $countParams = array_merge($countParams, $config['actions']);
                    }
                } elseif (!empty($config['actions'])) {
                    // Only actions specified, filter by action
                    $placeholders = implode(',', array_fill(0, count($config['actions']), '?'));
                    $conditions[] = "action IN ($placeholders)";
                    $countParams = array_merge($countParams, $config['actions']);
                }

                if (!empty($conditions)) {
                    // Use AND when both table and action filters exist, otherwise just the single condition
                    $countQuery .= "(" . implode(' AND ', $conditions) . ")";
                    $countStmt = $pdo->prepare($countQuery);
                    $countStmt->execute($countParams);
                    $result = $countStmt->fetchColumn();
                    $sectionCounts[$key] = $result ? (int)$result : 0;
                } else {
                    $sectionCounts[$key] = 0;
                }
            }
        } catch (PDOException $e) {
            $sectionCounts[$key] = 0;
        }
    }

    // Get unique actions for filter
    try {
        $actionsStmt = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
        $uniqueActions = $actionsStmt ? $actionsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if (!$uniqueActions) {
            $uniqueActions = [];
        }
    } catch (PDOException $e) {
        $uniqueActions = [];
    }

    // Get unique users for filter
    try {
        $usersStmt = $pdo->query("SELECT DISTINCT username FROM audit_logs WHERE username IS NOT NULL ORDER BY username");
        $uniqueUsers = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if (!$uniqueUsers) {
            $uniqueUsers = [];
        }
    } catch (PDOException $e) {
        $uniqueUsers = [];
    }

    // Get statistics
    try {
        $result = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
        $totalLogs = $result ? (int)$result : 0;
    } catch (PDOException $e) {
        $totalLogs = 0;
    }

    try {
        $result = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
        $todayLogs = $result ? (int)$result : 0;
    } catch (PDOException $e) {
        $todayLogs = 0;
    }

    try {
        $result = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        $thisWeekLogs = $result ? (int)$result : 0;
    } catch (PDOException $e) {
        $thisWeekLogs = 0;
    }

} catch (PDOException $e) {
    $error_message = "Database connection failed: " . $e->getMessage();
    error_log($error_message);
}

// Function to format JSON values for display
function formatJsonValue($json) {
    if (empty($json)) return '—';
    $decoded = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return '<pre style="margin:0; font-size:0.85rem; background:#f5f5f5; padding:0.5rem; border-radius:4px;">' . htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT)) . '</pre>';
    }
    return htmlspecialchars($json);
}

// Function to get action icon
function getActionIcon($action) {
    if (empty($action)) return 'bi-activity';
    $actionLower = strtolower($action);
    if (strpos($actionLower, 'email') !== false) return 'bi-envelope-fill';
    if (strpos($actionLower, 'login') !== false) return 'bi-box-arrow-in-right';
    if (strpos($actionLower, 'logout') !== false) return 'bi-box-arrow-right';
    if (strpos($actionLower, 'create') !== false || strpos($actionLower, 'add') !== false) return 'bi-plus-circle';
    if (strpos($actionLower, 'update') !== false || strpos($actionLower, 'edit') !== false) return 'bi-pencil';
    if (strpos($actionLower, 'delete') !== false || strpos($actionLower, 'remove') !== false) return 'bi-trash';
    if (strpos($actionLower, 'view') !== false || strpos($actionLower, 'read') !== false) return 'bi-eye';
    return 'bi-activity';
}

// Function to get action color
function getActionColor($action) {
    if (empty($action)) return '#6366f1';
    $actionLower = strtolower($action);
    if (strpos($actionLower, 'email') !== false) return '#3b82f6';
    if (strpos($actionLower, 'login') !== false) return '#22c55e';
    if (strpos($actionLower, 'logout') !== false) return '#dc143c';
    if (strpos($actionLower, 'create') !== false || strpos($actionLower, 'add') !== false) return '#0891b2';
    if (strpos($actionLower, 'update') !== false || strpos($actionLower, 'edit') !== false) return '#ffa500';
    if (strpos($actionLower, 'delete') !== false || strpos($actionLower, 'remove') !== false) return '#dc143c';
    return '#6366f1';
}

// Function to parse email data from log
function parseEmailData($log) {
    $emailData = null;
    if (!empty($log['new_values'])) {
        $decoded = json_decode($log['new_values'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Check if this looks like email data
            if (isset($decoded['recipient']) || isset($decoded['subject']) || isset($decoded['type'])) {
                $emailData = $decoded;
            }
        }
    }
    return $emailData;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log • Techno Pest Control</title>
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --primary-light: #34d399;
            --secondary: #22c55e;
            --accent: #84cc16;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --light: #f0fdf4;
            --border: #e2e8f0;
            --green-50: #f0fdf4;
            --green-100: #dcfce7;
            --green-200: #bbf7d0;
            --green-300: #86efac;
            --green-400: #4ade80;
            --green-500: #22c55e;
            --green-600: #16a34a;
            --green-700: #15803d;
            --green-800: #166534;
            --green-900: #14532d;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%);
            background-attachment: fixed;
            color: var(--dark);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
            line-height: 1.6;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 50%, rgba(16, 185, 129, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(5, 150, 105, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(52, 211, 153, 0.2) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        body > * {
            position: relative;
            z-index: 1;
        }
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #14532d 0%, #166534 50%, #15803d 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 4px 0 30px rgba(5, 150, 105, 0.4);
            z-index: 1000;
            transition: transform 0.3s ease;
            border-right: 3px solid var(--green-400);
        }
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        .sidebar-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            padding: 8px;
            background: white;
            margin-bottom: 1rem;
            box-shadow: 0 10px 40px rgba(16, 185, 129, 0.5), 0 0 30px rgba(34, 197, 94, 0.3);
            border: 3px solid var(--green-300);
            animation: pulse-glow 3s ease-in-out infinite;
        }
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 10px 40px rgba(16, 185, 129, 0.5), 0 0 30px rgba(34, 197, 94, 0.3); }
            50% { box-shadow: 0 10px 50px rgba(16, 185, 129, 0.7), 0 0 40px rgba(34, 197, 94, 0.5); }
        }
        .sidebar-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(90deg, #6ee7b7, #34d399, #10b981);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
            text-shadow: 0 0 30px rgba(16, 185, 129, 0.5);
            animation: gradient-shift 3s ease infinite;
            background-size: 200% auto;
        }
        @keyframes gradient-shift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .sidebar-header p {
            font-size: 0.875rem;
            color: rgba(255,255,255,0.6);
        }
        .nav-menu {
            padding: 1.5rem 0;
        }
        .nav-item {
            margin: 0.5rem 1rem;
        }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
            position: relative;
        }
        .nav-link i {
            font-size: 1.25rem;
            width: 24px;
            text-align: center;
        }
        .nav-link:hover, .nav-link.active {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.25), rgba(34, 197, 94, 0.2));
            color: white;
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            border: 1px solid rgba(110, 231, 183, 0.3);
        }
        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 5px;
            height: 70%;
            background: linear-gradient(180deg, var(--green-400), var(--green-600));
            border-radius: 0 4px 4px 0;
            box-shadow: 0 0 15px rgba(110, 231, 183, 0.8);
            animation: glow-pulse 2s ease-in-out infinite;
        }
        @keyframes glow-pulse {
            0%, 100% { box-shadow: 0 0 15px rgba(110, 231, 183, 0.8); }
            50% { box-shadow: 0 0 25px rgba(110, 231, 183, 1); }
        }
        .user-section {
            padding: 1.5rem;
            border-top: 2px solid rgba(110, 231, 183, 0.2);
            margin-top: auto;
            background: linear-gradient(180deg, transparent, rgba(16, 185, 129, 0.05));
        }
        .sidebar {
            display: flex;
            flex-direction: column;
        }
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
            background: var(--light);
            width: calc(100% - 280px);
            min-width: 0;
            overflow-x: hidden;
        }
        .top-bar {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(5, 150, 105, 0.3);
            border-bottom: 3px solid var(--green-400);
        }
        .top-bar .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .top-bar .page-title i {
            font-size: 2rem;
            color: var(--green-200);
        }
        .dashboard-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
        }
        .content-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow:
                0 10px 40px rgba(16, 185, 129, 0.12),
                0 4px 15px rgba(5, 150, 105, 0.08);
            border: 2px solid var(--green-100);
            position: relative;
            transition: all 0.3s ease;
        }
        .content-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--green-400), var(--green-500), var(--green-400));
            background-size: 200% 100%;
            animation: gradient-flow 3s ease infinite;
            border-radius: 20px 20px 0 0;
        }
        .content-card:hover {
            transform: translateY(-4px);
            box-shadow:
                0 15px 50px rgba(16, 185, 129, 0.18),
                0 6px 20px rgba(5, 150, 105, 0.12);
            border-color: var(--green-300);
        }
        @keyframes gradient-flow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .content-card > h2:first-child,
        .content-card > h3:first-child {
            margin-top: 0;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--green-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--green-700);
            font-size: 1.5rem;
        }
        .content-card > h2:first-child i,
        .content-card > h3:first-child i {
            color: var(--green-600);
            font-size: 1.35rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
            padding: 2rem;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(16, 185, 129, 0.4), 0 0 40px rgba(34, 197, 94, 0.2);
            transition: all 0.3s ease;
            border: 2px solid var(--green-300);
            position: relative;
            overflow: hidden;
            text-align: center;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { transform: translate(-50%, -50%) rotate(0deg); }
            50% { transform: translate(-50%, -50%) rotate(180deg); }
        }
        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 40px rgba(16, 185, 129, 0.5), 0 0 50px rgba(34, 197, 94, 0.3);
            border-color: var(--green-200);
        }
        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
            color: white;
            position: relative;
            z-index: 1;
        }
        .stat-card p {
            opacity: 0.9;
            margin-top: 0.5rem;
            color: white;
            font-size: 0.95rem;
            position: relative;
            z-index: 1;
        }

        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(16, 185, 129, 0.12), 0 4px 15px rgba(5, 150, 105, 0.08);
            border: 2px solid var(--green-100);
            position: relative;
        }
        .filter-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--green-400), var(--green-500), var(--green-400));
            background-size: 200% 100%;
            animation: gradient-flow 3s ease infinite;
            border-radius: 20px 20px 0 0;
        }
        .logs-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(16, 185, 129, 0.12), 0 4px 15px rgba(5, 150, 105, 0.08);
            border: 2px solid var(--green-100);
            position: relative;
        }
        .logs-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--green-400), var(--green-500), var(--green-400));
            background-size: 200% 100%;
            animation: gradient-flow 3s ease infinite;
            border-radius: 20px 20px 0 0;
        }
        .log-item {
            background: var(--light);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--green-500);
            transition: all 0.3s ease;
            border: 2px solid var(--green-100);
        }
        .log-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
            border-left-color: var(--green-600);
        }
        .log-item[style*="border-left-color: #3b82f6"]:hover {
            border-left-color: #2563eb;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
        }
        
        /* Enhanced Email Log Styling */
        .email-log-item {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(59, 130, 246, 0.03) 50%, var(--light) 100%);
            border-left: 5px solid #3b82f6;
            border: 2px solid rgba(59, 130, 246, 0.2);
            position: relative;
            overflow: hidden;
        }
        .email-log-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(180deg, #3b82f6, #2563eb, #1d4ed8);
        }
        .email-log-item:hover {
            transform: translateX(8px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.25);
            border-left-color: #2563eb;
        }
        .email-header-section {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 12px;
            margin-bottom: 1rem;
        }
        .email-icon-large {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        .email-main-info {
            flex: 1;
        }
        .email-subject {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        .email-recipient {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #3b82f6;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .email-status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .email-status-sent {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .email-status-failed {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }
        .email-status-gmail {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }
        .email-type-badge {
            background: rgba(59, 130, 246, 0.15);
            color: #2563eb;
            padding: 0.35rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        .email-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .email-detail-card {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            border: 1px solid rgba(59, 130, 246, 0.15);
            transition: all 0.2s ease;
        }
        .email-detail-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            border-color: rgba(59, 130, 246, 0.3);
        }
        .email-detail-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .email-detail-value {
            font-size: 0.95rem;
            color: #1e293b;
            font-weight: 600;
        }
        .email-booking-ref {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
            padding: 0.75rem;
            border-radius: 8px;
            border-left: 3px solid #10b981;
            font-weight: 700;
            color: #059669;
        }
        .log-detail-label i {
            margin-right: 0.25rem;
            color: var(--green-600);
        }
        .log-item[style*="border-left-color: #3b82f6"] .log-detail-label i {
            color: #3b82f6;
        }
        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        .log-action {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .log-action-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        .log-action-text {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--dark);
        }
        .log-meta {
            text-align: right;
            font-size: 0.85rem;
            color: var(--dark);
        }
        .log-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--green-100);
        }
        .log-detail-item {
            font-size: 0.9rem;
        }
        .log-detail-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        .log-detail-value {
            color: var(--dark);
        }
        .btn {
            padding: 0.75rem 1.75rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
        }
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.18);
        }
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100%; }
        }
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1.5rem;
            left: 1.5rem;
            z-index: 1001;
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
            border: none;
            border-radius: 50%;
            width: 56px;
            height: 56px;
            font-size: 1.6rem;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
            cursor: pointer;
        }
        @media (max-width: 1024px) {
            .mobile-menu-btn { display: flex; align-items: center; justify-content: center; }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <i class="bi bi-list"></i>
    </button>

    <div class="dashboard-wrapper">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img src="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" alt="Techno Pest Control">
                <h3>TECHNO PEST</h3>
                <p>Manager Dashboard</p>
            </div>
            <nav class="nav-menu">
              <div class="user-section">
                  <a href="dashboard.php" class="btn btn-modern btn-primary-modern w-100 mb-2" style="background: linear-gradient(135deg, var(--green-500), var(--green-600)); color: white; border: none; padding: 0.75rem 2rem; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);">
                      <i class="bi bi-speedometer2"></i> Dashboard
                  </a>
              </div>
                <div class="nav-item">
                    <a href="inventory.php" class="nav-link">
                        <i class="bi bi-box-seam"></i>
                        <span>Inventory</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="service_records.php?view=all" class="nav-link">
                        <i class="bi bi-journal-text"></i>
                        <span>Service Records</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="sales_report.php" class="nav-link">
                        <i class="bi bi-graph-up-arrow"></i>
                        <span>Sales Report</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="analytics.php" class="nav-link">
                        <i class="bi bi-clipboard-data"></i>
                        <span>Analytics</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="audit_log.php" class="nav-link active">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>Audit Log</span>
                    </a>
                </div>
            </nav>

        </aside>

        <main class="main-content">
            <div class="top-bar">
                <h1 class="page-title">
                    <i class="bi bi-file-earmark-text"></i>
                    Audit Log
                </h1>
            </div>
            <div class="dashboard-container">
                <div class="stats-grid">
            <div class="stat-card">
                <h3><?= number_format($totalLogs) ?></h3>
                <p>Total Logs</p>
            </div>
            <div class="stat-card">
                <h3><?= number_format($todayLogs) ?></h3>
                <p>Today's Logs</p>
            </div>
            <div class="stat-card">
                <h3><?= number_format($thisWeekLogs) ?></h3>
                <p>This Week</p>
            </div>
        </div>

        <!-- Section Tabs -->
        <div class="filter-card mb-3">
            <div class="d-flex gap-2 flex-wrap">
                <?php foreach ($sections as $key => $config): ?>
                    <?php
                    $sectionParams = ['section' => $key];
                    if ($search) $sectionParams['search'] = $search;
                    if ($filterAction) $sectionParams['filter_action'] = $filterAction;
                    if ($filterUser) $sectionParams['filter_user'] = $filterUser;
                    if ($dateFrom) $sectionParams['date_from'] = $dateFrom;
                    if ($dateTo) $sectionParams['date_to'] = $dateTo;
                    $sectionUrl = '?' . http_build_query($sectionParams);
                    ?>
                    <a href="<?= htmlspecialchars($sectionUrl) ?>"
                       class="btn <?= $section === $key ? 'btn-success' : 'btn-outline-success' ?> d-flex align-items-center gap-2">
                        <i class="bi <?= $config['icon'] ?>"></i>
                        <?= htmlspecialchars($config['label']) ?>
                        <span class="badge bg-light text-dark ms-1"><?= number_format($sectionCounts[$key] ?? 0) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="filter-card">
            <form method="get" class="row g-3">
                <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search actions, users..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Action</label>
                    <select name="filter_action" class="form-select">
                        <option value="">All Actions</option>
                        <?php foreach ($uniqueActions as $action): ?>
                            <option value="<?= htmlspecialchars($action) ?>" <?= $filterAction === $action ? 'selected' : '' ?>>
                                <?= htmlspecialchars($action) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">User</label>
                    <select name="filter_user" class="form-select">
                        <option value="">All Users</option>
                        <?php foreach ($uniqueUsers as $user): ?>
                            <option value="<?= htmlspecialchars($user) ?>" <?= $filterUser === $user ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-bold">&nbsp;</label>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-search"></i> Filter
                    </button>
                </div>
            </form>
            <?php if ($search || $filterAction || $filterUser || $dateFrom || $dateTo || $section !== 'all'): ?>
                <a href="audit_log.php" class="btn btn-outline-secondary mt-3">
                    <i class="bi bi-x-circle"></i> Clear All Filters
                </a>
            <?php endif; ?>
        </div>

        <div class="logs-card">
            <h3 class="mb-4">
                <i class="bi bi-file-earmark-text"></i> Activity Logs
                <span class="badge bg-primary ms-2"><?= isset($totalRecords) ? number_format($totalRecords) : count($logs) ?> total records</span>
                <?php if (isset($totalPages) && $totalPages > 1): ?>
                    <span class="badge bg-info ms-2">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php endif; ?>
            </h3>

            <?php if (isset($totalPages) && $totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mb-4">
                    <ul class="pagination justify-content-center flex-wrap">
                        <?php
                        $queryParams = [];
                        if ($section !== 'all') $queryParams[] = "section=" . urlencode($section);
                        if ($search) $queryParams[] = "search=" . urlencode($search);
                        if ($filterAction) $queryParams[] = "filter_action=" . urlencode($filterAction);
                        if ($filterUser) $queryParams[] = "filter_user=" . urlencode($filterUser);
                        if ($dateFrom) $queryParams[] = "date_from=" . urlencode($dateFrom);
                        if ($dateTo) $queryParams[] = "date_to=" . urlencode($dateTo);
                        $baseUrl = "audit_log.php" . (!empty($queryParams) ? "?" . implode("&", $queryParams) : "");
                        $urlSeparator = strpos($baseUrl, '?') !== false ? '&' : '?';
                        ?>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $baseUrl . ($page > 1 ? $urlSeparator . 'page=' . ($page - 1) : '') ?>">
                                <i class="bi bi-chevron-left"></i> Previous
                            </a>
                        </li>
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= $baseUrl . $urlSeparator . 'page=1' ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $baseUrl . $urlSeparator . 'page=' . $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= $baseUrl . $urlSeparator . 'page=' . $totalPages ?>"><?= $totalPages ?></a>
                            </li>
                        <?php endif; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $baseUrl . $urlSeparator . 'page=' . ($page + 1) ?>">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

            <?php if (empty($logs)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <p class="text-muted mt-3">No audit logs found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($logs as $log):
                    $actionColor = getActionColor($log['action'] ?? '');
                    $actionIcon = getActionIcon($log['action'] ?? '');
                    $emailData = parseEmailData($log);
                    $isEmailLog = !empty($emailData);
                ?>
                    <div class="log-item <?= $isEmailLog ? 'email-log-item' : '' ?>">
                        <?php if ($isEmailLog): ?>
                            <!-- Enhanced Email Log Layout -->
                            <div class="email-header-section">
                                <div class="email-icon-large">
                                    <i class="bi bi-envelope-fill"></i>
                                </div>
                                <div class="email-main-info">
                                    <?php if (!empty($emailData['subject'])): ?>
                                        <div class="email-subject">
                                            <?= htmlspecialchars($emailData['subject']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($emailData['recipient'])): ?>
                                        <div class="email-recipient">
                                            <i class="bi bi-envelope-at-fill"></i>
                                            <?= htmlspecialchars($emailData['recipient']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div style="text-align: right;">
                                    <?php if (!empty($emailData['status'])): ?>
                                        <?php
                                        $statusClass = 'email-status-sent';
                                        if (stripos($emailData['status'], 'failed') !== false) {
                                            $statusClass = 'email-status-failed';
                                        } elseif (stripos($emailData['status'], 'gmail') !== false) {
                                            $statusClass = 'email-status-gmail';
                                        }
                                        ?>
                                        <div class="email-status-badge <?= $statusClass ?>">
                                            <i class="bi <?= $statusClass === 'email-status-failed' ? 'bi-x-circle' : ($statusClass === 'email-status-gmail' ? 'bi-google' : 'bi-check-circle') ?>"></i>
                                            <?= htmlspecialchars($emailData['status']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($emailData['type'])): ?>
                                        <div class="email-type-badge mt-2">
                                            <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($emailData['type']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding: 0.75rem; background: rgba(59, 130, 246, 0.05); border-radius: 8px;">
                                <div>
                                    <strong style="color: #1e293b;"><?= htmlspecialchars($log['username'] ?? 'System') ?></strong>
                                    <span style="color: #64748b; margin-left: 0.5rem;">•</span>
                                    <span style="color: #64748b; font-size: 0.9rem;">
                                        <?= !empty($log['created_at']) ? date('M d, Y h:i A', strtotime($log['created_at'])) : 'N/A' ?>
                                    </span>
                                </div>
                                <div style="color: #64748b; font-size: 0.85rem;">
                                    <i class="bi bi-clock"></i> <?= !empty($log['created_at']) ? date('h:i A', strtotime($log['created_at'])) : '' ?>
                                </div>
                            </div>

                            <div class="email-details-grid">
                                <?php if (!empty($emailData['reference_code']) || !empty($emailData['booking_id'])): ?>
                                    <div class="email-detail-card" style="grid-column: 1 / -1;">
                                        <div class="email-detail-label">
                                            <i class="bi bi-journal-text"></i> Booking Reference
                                        </div>
                                        <div class="email-booking-ref">
                                            <?php if (!empty($emailData['reference_code'])): ?>
                                                <?= htmlspecialchars($emailData['reference_code']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($emailData['booking_id'])): ?>
                                                <span style="color: #64748b; font-weight: 400; margin-left: 0.5rem;">(ID: <?= htmlspecialchars($emailData['booking_id']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($emailData['customer_name'])): ?>
                                    <div class="email-detail-card">
                                        <div class="email-detail-label">
                                            <i class="bi bi-person-fill"></i> Customer
                                        </div>
                                        <div class="email-detail-value">
                                            <?= htmlspecialchars($emailData['customer_name']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($emailData['pictures_count'])): ?>
                                    <div class="email-detail-card">
                                        <div class="email-detail-label">
                                            <i class="bi bi-images"></i> Pictures
                                        </div>
                                        <div class="email-detail-value">
                                            <?= htmlspecialchars($emailData['pictures_count']) ?> picture(s)
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($emailData['old_date']) || !empty($emailData['new_date'])): ?>
                                    <div class="email-detail-card" style="grid-column: 1 / -1;">
                                        <div class="email-detail-label">
                                            <i class="bi bi-calendar-event"></i> Reschedule Details
                                        </div>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 0.5rem;">
                                            <?php if (!empty($emailData['old_date']) || !empty($emailData['old_time'])): ?>
                                                <div style="padding: 0.75rem; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border-left: 3px solid #ef4444;">
                                                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 0.25rem;">Previous</div>
                                                    <div style="font-weight: 600; color: #1e293b;">
                                                        <?php if (!empty($emailData['old_date'])): ?>
                                                            <?= htmlspecialchars($emailData['old_date']) ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($emailData['old_time'])): ?>
                                                            <br><small><?= htmlspecialchars($emailData['old_time']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($emailData['new_date']) || !empty($emailData['new_time'])): ?>
                                                <div style="padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border-radius: 8px; border-left: 3px solid #10b981;">
                                                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 0.25rem;">New</div>
                                                    <div style="font-weight: 600; color: #1e293b;">
                                                        <?php if (!empty($emailData['new_date'])): ?>
                                                            <?= htmlspecialchars($emailData['new_date']) ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($emailData['new_time'])): ?>
                                                            <br><small><?= htmlspecialchars($emailData['new_time']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['ip_address'])): ?>
                                    <div class="email-detail-card">
                                        <div class="email-detail-label">
                                            <i class="bi bi-globe"></i> IP Address
                                        </div>
                                        <div class="email-detail-value" style="font-family: monospace; font-size: 0.85rem;">
                                            <?= htmlspecialchars($log['ip_address']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <!-- Standard log display -->
                            <div class="log-header">
                                <div class="log-action">
                                    <div class="log-action-icon" style="background: <?= $actionColor ?>">
                                        <i class="bi <?= $actionIcon ?>"></i>
                                    </div>
                                    <div>
                                        <div class="log-action-text">
                                            <?= htmlspecialchars($log['action'] ?? 'Unknown Action') ?>
                                        </div>
                                        <?php if (!empty($log['table_name'])): ?>
                                            <small class="text-muted">
                                                <i class="bi bi-table"></i> <?= htmlspecialchars($log['table_name']) ?>
                                                <?php if (!empty($log['record_id'])): ?>
                                                    (ID: <?= htmlspecialchars($log['record_id']) ?>)
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="log-meta">
                                    <div><strong><?= htmlspecialchars($log['username'] ?? 'System') ?></strong></div>
                                    <div><?= !empty($log['created_at']) ? date('M d, Y h:i A', strtotime($log['created_at'])) : 'N/A' ?></div>
                                </div>
                            </div>

                            <div class="log-details">

                                <?php if (!empty($log['username'])): ?>
                                    <div class="log-detail-item">
                                        <div class="log-detail-label">User</div>
                                        <div class="log-detail-value">
                                            <i class="bi bi-person-fill"></i> <?= htmlspecialchars($log['username']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['ip_address'])): ?>
                                    <div class="log-detail-item">
                                        <div class="log-detail-label">IP Address</div>
                                        <div class="log-detail-value">
                                            <i class="bi bi-globe"></i> <?= htmlspecialchars($log['ip_address']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['table_name'])): ?>
                                    <div class="log-detail-item">
                                        <div class="log-detail-label">Table</div>
                                        <div class="log-detail-value">
                                            <i class="bi bi-table"></i> <?= htmlspecialchars($log['table_name']) ?>
                                            <?php if (!empty($log['record_id'])): ?>
                                                (ID: <?= htmlspecialchars($log['record_id']) ?>)
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['old_values'])): ?>
                                    <div class="log-detail-item" style="grid-column: 1 / -1;">
                                        <div class="log-detail-label">Old Values</div>
                                        <div class="log-detail-value">
                                            <?= formatJsonValue($log['old_values']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['new_values'])): ?>
                                    <div class="log-detail-item" style="grid-column: 1 / -1;">
                                        <div class="log-detail-label">New Values</div>
                                        <div class="log-detail-value">
                                            <?= formatJsonValue($log['new_values']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['user_agent'])): ?>
                                    <div class="log-detail-item" style="grid-column: 1 / -1;">
                                        <div class="log-detail-label">User Agent</div>
                                        <div class="log-detail-value">
                                            <small><?= htmlspecialchars($log['user_agent']) ?></small>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
            const sidebar = document.getElementById('sidebar');

            if (mobileMenuBtn && sidebar) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                });
            }

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 1024) {
                    if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                        sidebar.classList.remove('open');
                    }
                }
            });

            // Handle form submissions and preserve filters
            const filterForm = document.querySelector('form[method="get"]');
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    // Form will submit normally, preserving all inputs
                });
            }

            // Auto-submit select changes for better UX (optional)
            const filterSelects = document.querySelectorAll('select[name^="filter_"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    // Optionally auto-submit on filter change
                    // this.form.submit();
                });
            });
        });
    </script>
</body>
</html>
