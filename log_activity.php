<?php
session_start();
header('Content-Type: application/json');

// Only allow logged-in admins
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once 'audit_logger.php';

$host = "localhost";
$dbname = "pest control";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit();
    }
    
    $action = $input['action'];
    $details = $input['details'] ?? [];
    
    // Log the activity
    $result = logAuditAction($pdo, $action, 'dashboard', null, null, $details);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Activity logged']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to log activity']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

?>

