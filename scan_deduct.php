<?php
session_start();
header('Content-Type: application/json');

// Database connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=pest control;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Create deductions table if it doesn't exist - enhanced to record all chemical information
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chemical_deductions (
        deduction_id int(11) NOT NULL AUTO_INCREMENT,
        inventory_id int(11) NOT NULL,
        service_id int(11) DEFAULT NULL,
        service_name varchar(255) DEFAULT NULL,
        ai_id int(11) DEFAULT NULL,
        active_ingredient varchar(255) DEFAULT NULL,
        barcode varchar(255) DEFAULT NULL,
        quantity_deducted decimal(10,2) DEFAULT 1.00,
        stock_before decimal(10,2) DEFAULT NULL,
        stock_after decimal(10,2) DEFAULT NULL,
        deducted_by varchar(100) DEFAULT NULL,
        deduction_date datetime DEFAULT CURRENT_TIMESTAMP,
        source_page varchar(50) DEFAULT NULL,
        booking_id int(11) DEFAULT NULL,
        booking_reference varchar(50) DEFAULT NULL,
        notes text,
        PRIMARY KEY (deduction_id),
        KEY inventory_id (inventory_id),
        KEY service_id (service_id),
        KEY deduction_date (deduction_date),
        KEY booking_id (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Add new columns if table already exists (for backward compatibility)
    $columns_to_add = [
        'service_id' => "int(11) DEFAULT NULL",
        'service_name' => "varchar(255) DEFAULT NULL",
        'ai_id' => "int(11) DEFAULT NULL",
        'active_ingredient' => "varchar(255) DEFAULT NULL",
        'stock_before' => "decimal(10,2) DEFAULT NULL",
        'stock_after' => "decimal(10,2) DEFAULT NULL",
        'booking_id' => "int(11) DEFAULT NULL",
        'booking_reference' => "varchar(50) DEFAULT NULL"
    ];
    
    foreach ($columns_to_add as $column => $definition) {
        try {
            $checkColumn = $pdo->query("SHOW COLUMNS FROM chemical_deductions LIKE '$column'")->fetch();
            if (!$checkColumn) {
                $pdo->exec("ALTER TABLE chemical_deductions ADD COLUMN $column $definition");
            }
        } catch (PDOException $e) {
            // Column might already exist or table doesn't exist yet, ignore error
        }
    }
} catch (PDOException $e) {
    // Table might already exist
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'scan_deduct') {
        $barcode = trim($_POST['barcode'] ?? '');
        $quantity = floatval($_POST['quantity'] ?? 1.0);
        $source_page = $_POST['source_page'] ?? 'unknown';
        $notes = trim($_POST['notes'] ?? '');
        $username = $_SESSION['username'] ?? 'Unknown';
        
        // Get service and ingredient information if provided
        $service_id = !empty($_POST['service_id']) ? intval($_POST['service_id']) : null;
        $service_name = trim($_POST['service_name'] ?? '');
        $ingredient_id = !empty($_POST['ingredient_id']) ? intval($_POST['ingredient_id']) : null;
        $ingredient_name = trim($_POST['ingredient_name'] ?? '');
        $booking_id = !empty($_POST['booking_id']) ? intval($_POST['booking_id']) : null;
        $booking_reference = trim($_POST['booking_reference'] ?? '');
        
        // Validate required fields
        if (empty($barcode) && empty($ingredient_id)) {
            echo json_encode(['success' => false, 'message' => 'Barcode or ingredient ID is required']);
            exit;
        }
        
        // Find inventory item by barcode or ingredient_id
        $query = "
            SELECT i.inventory_id, i.service_id, i.ai_id, i.stocks, i.barcode, 
                   s.service_name, a.name AS active_ingredient
            FROM inventory i
            LEFT JOIN services s ON i.service_id = s.service_id
            LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
            WHERE 1=1
        ";
        $params = [];
        
        if (!empty($barcode)) {
            $query .= " AND i.barcode = ?";
            $params[] = $barcode;
        } elseif (!empty($ingredient_id)) {
            $query .= " AND i.inventory_id = ?";
            $params[] = $ingredient_id;
        }
        
        $query .= " LIMIT 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $item = $stmt->fetch();
        
        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Item not found in inventory']);
            exit;
        }
        
        // Use values from database if not provided in POST
        $final_service_id = $service_id ?? $item['service_id'];
        $final_service_name = !empty($service_name) ? $service_name : ($item['service_name'] ?? '');
        $final_ai_id = $item['ai_id'];
        $final_ingredient_name = !empty($ingredient_name) ? $ingredient_name : ($item['active_ingredient'] ?? '');
        $final_barcode = !empty($barcode) ? $barcode : $item['barcode'];
        
        $current_stock = floatval($item['stocks']);
        $stock_before = $current_stock;
        
        if ($current_stock < $quantity) {
            echo json_encode([
                'success' => false, 
                'message' => "Insufficient stock. Available: {$current_stock}, Requested: {$quantity}"
            ]);
            exit;
        }
        
        // Deduct from inventory
        $new_stock = $current_stock - $quantity;
        $stock_after = $new_stock;
        $updateStmt = $pdo->prepare("UPDATE inventory SET stocks = ?, updated_at = NOW() WHERE inventory_id = ?");
        $updateStmt->execute([$new_stock, $item['inventory_id']]);
        
        // Record the deduction with all chemical information
        $deductStmt = $pdo->prepare("
            INSERT INTO chemical_deductions 
            (inventory_id, service_id, service_name, ai_id, active_ingredient, barcode, 
             quantity_deducted, stock_before, stock_after, deducted_by, source_page, 
             booking_id, booking_reference, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $deductStmt->execute([
            $item['inventory_id'],
            $final_service_id,
            $final_service_name,
            $final_ai_id,
            $final_ingredient_name,
            $final_barcode,
            $quantity,
            $stock_before,
            $stock_after,
            $username,
            $source_page,
            $booking_id,
            $booking_reference,
            $notes
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully deducted {$quantity} bottle(s) of {$final_ingredient_name}",
            'data' => [
                'inventory_id' => $item['inventory_id'],
                'service_id' => $final_service_id,
                'service_name' => $final_service_name,
                'ai_id' => $final_ai_id,
                'active_ingredient' => $final_ingredient_name,
                'barcode' => $final_barcode,
                'previous_stock' => $stock_before,
                'new_stock' => $stock_after,
                'quantity_deducted' => $quantity,
                'booking_id' => $booking_id,
                'booking_reference' => $booking_reference
            ]
        ]);
        exit;
    }
    
    if ($action === 'get_deductions') {
        $from_date = $_POST['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $to_date = $_POST['to_date'] ?? date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT 
                cd.*,
                COALESCE(cd.service_name, s.service_name) AS service_name,
                COALESCE(cd.active_ingredient, a.name) AS active_ingredient,
                COALESCE(cd.barcode, i.barcode) AS barcode,
                sb.reference_code,
                sb.customer_name
            FROM chemical_deductions cd
            LEFT JOIN inventory i ON cd.inventory_id = i.inventory_id
            LEFT JOIN services s ON i.service_id = s.service_id OR cd.service_id = s.service_id
            LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id OR cd.ai_id = a.ai_id
            LEFT JOIN service_bookings sb ON cd.booking_id = sb.booking_id
            WHERE DATE(cd.deduction_date) BETWEEN ? AND ?
            ORDER BY cd.deduction_date DESC
        ");
        $stmt->execute([$from_date, $to_date]);
        $deductions = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $deductions]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>

