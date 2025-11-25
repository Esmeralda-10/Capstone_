<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

// Include audit logger
require_once 'audit_logger.php';

// CHANGE THIS LINE ONLY — your real database name
$pdo = new PDO("mysql:host=localhost;dbname=pest control;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Ensure created_at and updated_at columns exist
try {
    // Check if columns exist, if not add them
    $columns = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'created_at'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }

    $columns = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'updated_at'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
} catch (PDOException $e) {
    // Ignore errors - columns might already exist or table structure issue
}

/* ==================== ADD STOCK ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_stock') {
    header('Content-Type: application/json');

    $id = (int)($_POST['id'] ?? 0);
    $stock_to_add = (int)round((float)($_POST['stock_to_add'] ?? 0));
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid inventory item ID']);
        exit;
    }

    if ($stock_to_add <= 0) {
        echo json_encode(['success' => false, 'message' => 'Stock amount must be greater than 0']);
        exit;
    }

    try {
        // Get old values for logging
        $oldStmt = $pdo->prepare("SELECT stocks, expiry_date FROM inventory WHERE inventory_id = ?");
        $oldStmt->execute([$id]);
        $oldData = $oldStmt->fetch();

        if (!$oldData) {
            echo json_encode(['success' => false, 'message' => 'Inventory item not found']);
            exit;
        }

        $oldStocks = (int)round((float)$oldData['stocks']);
        $newStocks = $oldStocks + $stock_to_add;
        $oldExpiry = $oldData['expiry_date'] ?? null;

        // Update stocks and expiry date if provided
        if ($expiry_date) {
            $stmt = $pdo->prepare("UPDATE inventory SET stocks = ?, expiry_date = ?, updated_at = NOW() WHERE inventory_id = ?");
            $stmt->execute([$newStocks, $expiry_date, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE inventory SET stocks = ?, updated_at = NOW() WHERE inventory_id = ?");
            $stmt->execute([$newStocks, $id]);
        }

        // Log the update
        if (function_exists('logUpdate')) {
            $oldValues = ['stocks' => $oldStocks];
            $newValues = ['stocks' => $newStocks];

            if ($expiry_date && $oldExpiry != $expiry_date) {
                $oldValues['expiry_date'] = $oldExpiry;
                $newValues['expiry_date'] = $expiry_date;
            }

            logUpdate($pdo, "inventory", $id, $oldValues, $newValues);
        }

        echo json_encode([
            'success' => true,
            'old_stocks' => $oldStocks,
            'added' => $stock_to_add,
            'new_stocks' => $newStocks
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

/* ==================== ROTATION ==================== */
$termite_ingredients = ['Fipronil', 'Bifenthrin', 'Imidacloprid'];
$pest_control_ingredients = ['Lambda-Cyhalothrin', 'Beta-Cyfluthrin', 'Cypermethrin', 'Deltamethrin'];
$month = date('n');
$rotation_period = floor(($month - 1) / 3);
$quarters = ["Jan - Mar", "Apr - Jun", "Jul - Sep", "Oct - Dec"];
$current_period = $quarters[$rotation_period] . " " . date('Y');

/* ==================== INVENTORY ==================== */
// Try to get category from services table, if it exists
try {
    $inventory = $pdo->query("
        SELECT i.inventory_id, s.service_name, 
               COALESCE(s.category, s.service_type, 'Other Services') AS service_category,
               a.name AS active_ingredient, i.stocks, i.expiry_date, i.barcode,
               i.created_at, i.updated_at
        FROM inventory i
        JOIN services s ON i.service_id = s.service_id
        LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        ORDER BY s.category, s.service_name, a.name, i.expiry_date
    ")->fetchAll();
} catch (PDOException $e) {
    // If category column doesn't exist, fetch without it and categorize manually
$inventory = $pdo->query("
    SELECT i.inventory_id, s.service_name, a.name AS active_ingredient, i.stocks, i.expiry_date, i.barcode,
           i.created_at, i.updated_at
    FROM inventory i
    JOIN services s ON i.service_id = s.service_id
    LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        ORDER BY s.service_name, a.name, i.expiry_date
")->fetchAll();
    
    // Categorize services based on service name patterns
    foreach ($inventory as &$item) {
        $service_name = strtolower($item['service_name']);
        if (stripos($service_name, 'termite') !== false) {
            $item['service_category'] = 'Termite Control';
        } elseif (stripos($service_name, 'pest') !== false || stripos($service_name, 'general') !== false) {
            $item['service_category'] = 'General Pest Control';
        } elseif (stripos($service_name, 'rodent') !== false || stripos($service_name, 'rat') !== false || stripos($service_name, 'mouse') !== false) {
            $item['service_category'] = 'Rodent Control';
        } elseif (stripos($service_name, 'fumigat') !== false) {
            $item['service_category'] = 'Fumigation';
        } elseif (stripos($service_name, 'mosquito') !== false) {
            $item['service_category'] = 'Mosquito Control';
        } elseif (stripos($service_name, 'cockroach') !== false) {
            $item['service_category'] = 'Cockroach Control';
        } else {
            $item['service_category'] = 'Other Services';
        }
    }
    unset($item);
}

// Group inventory by category
$inventory_by_category = [];
foreach ($inventory as $item) {
    $category = $item['service_category'] ?? 'Other Services';
    if (!isset($inventory_by_category[$category])) {
        $inventory_by_category[$category] = [];
    }
    $inventory_by_category[$category][] = $item;
}

// Define category order and icons
$category_order = [
    'Termite Control' => ['icon' => 'bi-bug', 'color' => 'var(--success)'],
    'General Pest Control' => ['icon' => 'bi-shield-fill', 'color' => '#3b82f6'],
    'Rodent Control' => ['icon' => 'bi-mouse', 'color' => '#8b5cf6'],
    'Mosquito Control' => ['icon' => 'bi-droplet', 'color' => '#06b6d4'],
    'Cockroach Control' => ['icon' => 'bi-bug-fill', 'color' => '#f59e0b'],
    'Fumigation' => ['icon' => 'bi-cloud-fog', 'color' => '#ef4444'],
    'Other Services' => ['icon' => 'bi-box-seam', 'color' => '#64748b']
];

/* ==================== CURRENT CHEMICALS ==================== */
$termite_items = array_filter($inventory, fn($i)=>in_array(trim($i['active_ingredient'] ?? ''), $termite_ingredients));
$pest_items = array_filter($inventory, fn($i)=>in_array(trim($i['active_ingredient'] ?? ''), $pest_control_ingredients));

$current_termite = !empty($termite_items)
    ? array_values($termite_items)[$rotation_period % count($termite_items)]['active_ingredient']
    : 'None Selected';
$current_pest = !empty($pest_items)
    ? array_values($pest_items)[$rotation_period % count($pest_items)]['active_ingredient']
    : 'None Selected';

/* ==================== STOCK ALERTS ==================== */
$low_stock_items = array_filter($inventory, fn($i) => (float)$i['stocks'] > 0 && (float)$i['stocks'] <= 20);
$empty_items = array_filter($inventory, fn($i) => (float)$i['stocks'] <= 0);
$has_low = !empty($low_stock_items);
$has_empty = !empty($empty_items);
$alert_count = count($low_stock_items);

// Calculate stats
$total_items = count($inventory);
$total_stock = array_sum(array_map(fn($i) => (float)$i['stocks'], $inventory));
$healthy_items = count(array_filter($inventory, fn($i) => (float)$i['stocks'] > 20));

/* ==================== RECENT DEDUCTIONS ==================== */
// Fetch recent chemical deductions (last 10) with notes
try {
    $recent_deductions = $pdo->query("
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
        ORDER BY cd.deduction_date DESC
        LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    // If table doesn't exist yet, use empty array
    $recent_deductions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory Management - TECHNO PEST</title>
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
            background: linear-gradient(135deg, var(--green-400), var(--green-600));
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
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            background: var(--light);
            width: calc(100% - 280px);
            min-width: 0;
            overflow-x: hidden;
        }

        /* Animated Background - Light Green */
        .bg-animated {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: linear-gradient(135deg, var(--green-50) 0%, var(--green-100) 50%, var(--green-200) 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            overflow: hidden;
        }

        .bg-animated::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background-image:
                radial-gradient(circle at 20% 50%, rgba(16, 185, 129, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(34, 197, 94, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 50% 20%, rgba(52, 211, 153, 0.1) 0%, transparent 50%);
            animation: particleFloat 20s ease-in-out infinite;
        }

        .bg-animated::after {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            top: -50%;
            left: -50%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: meshMove 30s linear infinite;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes particleFloat {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.8; }
            50% { transform: translate(50px, -50px) scale(1.2); opacity: 0.5; }
        }

        @keyframes meshMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(100px, 100px); }
        }

        /* Top Navigation Bar - Light Green */
        .top-nav {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px) saturate(180%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 2px solid var(--green-200);
        }

        .nav-brand {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .back-btn-modern {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            position: relative;
            overflow: hidden;
        }

        .back-btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }

        .back-btn-modern:hover::before {
            left: 100%;
        }


        /* Stats Cards - Light Green */
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
        .stat-card.success {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
        }
        .stat-card.warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
        }
        .stat-card.danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
        }
        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
        }
        .stat-card p {
            opacity: 0.9;
            margin-top: 0.5rem;
        }


        /* Alert Banner - Light Green */
        #stockAlertBanner {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(239, 68, 68, 0.3);
            animation: slideDown 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        /* Deduction Notifications Section */
        .deduction-notifications {
            margin-bottom: 2rem;
        }

        .deduction-notification-card {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid var(--warning);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .deduction-notification-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--warning), #d97706);
            animation: pulse-border 2s ease-in-out infinite;
        }

        @keyframes pulse-border {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .deduction-notification-card:hover {
            transform: translateX(5px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
        }

        .deduction-notification-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .deduction-notification-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .deduction-notification-title i {
            color: var(--warning);
            font-size: 1.25rem;
        }

        .deduction-notification-time {
            font-size: 0.875rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .deduction-notification-body {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 0.75rem;
        }

        .deduction-info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .deduction-info-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .deduction-info-value {
            font-size: 0.9375rem;
            color: var(--dark);
            font-weight: 600;
        }

        .deduction-notes {
            background: #f8fafc;
            border-radius: 8px;
            padding: 0.875rem;
            margin-top: 0.75rem;
            border-left: 3px solid var(--green-500);
        }

        .deduction-notes-label {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .deduction-notes-text {
            font-size: 0.875rem;
            color: var(--dark);
            line-height: 1.5;
        }

        .deduction-notifications-empty {
            text-align: center;
            padding: 3rem 2rem;
            color: #64748b;
        }

        .deduction-notifications-empty i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Modal Styles for Notifications */
        .deduction-notifications-modal .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .deduction-notifications-modal .modal-header {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
            padding: 1.5rem 2rem;
            border: none;
        }

        .deduction-notifications-modal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        .deduction-notifications-modal .modal-header .btn-close:hover {
            opacity: 1;
        }

        .deduction-notifications-modal .modal-body {
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .deduction-notifications-modal .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .deduction-notifications-modal .modal-body::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .deduction-notifications-modal .modal-body::-webkit-scrollbar-thumb {
            background: var(--green-400);
            border-radius: 10px;
        }

        .deduction-notifications-modal .modal-body::-webkit-scrollbar-thumb:hover {
            background: var(--green-500);
        }

        .deduction-notifications-modal .modal-footer {
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
            background: #f8fafc;
        }

        #stockAlertBanner::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shine 3s infinite;
        }

        #stockAlertBanner::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes shine {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .alert-icon {
            animation: alertPulse 1.5s ease-in-out infinite;
            filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.5));
        }

        @keyframes alertPulse {
            0%, 100% {
                transform: scale(1) rotate(0deg);
                opacity: 1;
            }
            25% {
                transform: scale(1.2) rotate(-5deg);
                opacity: 0.9;
            }
            50% {
                transform: scale(1.1) rotate(5deg);
                opacity: 1;
            }
            75% {
                transform: scale(1.15) rotate(-3deg);
                opacity: 0.95;
            }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.3; }
            50% { transform: scale(1.5); opacity: 0.5; }
        }

        /* Low Stock Grid */
        .low-stock-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .low-stock-item {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border-left: 4px solid;
            opacity: 0;
            transform: translateX(-30px) scale(0.9);
            animation: slideInLeft 0.5s ease forwards;
        }

        @keyframes slideInLeft {
            to {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
        }

        .low-stock-item:hover {
            transform: translateX(10px) scale(1.05);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .low-stock-item i {
            animation: pulse 2s ease-in-out infinite;
        }

        .chemical-title {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 1rem;
            color: var(--green-600);
        }

        .chemical-name {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .active-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
            background: linear-gradient(135deg, var(--green-400), var(--green-600));
            color: white;
        }

        .chemical-title {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 1rem;
        }

        .chemical-name {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .active-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
        }


        .btn-modern {
            padding: 0.75rem 2rem;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        .btn-primary-modern {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);
            position: relative;
            overflow: hidden;
        }
        .btn-primary-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        .btn-primary-modern:hover::before {
            left: 100%;
        }
        .btn-primary-modern:hover {
            background: linear-gradient(135deg, var(--green-400), var(--green-500));
            box-shadow: 0 6px 30px rgba(16, 185, 129, 0.6);
            transform: translateY(-3px);
        }
        .btn-success-modern {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);
        }
        .btn-success-modern:hover {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            box-shadow: 0 6px 30px rgba(16, 185, 129, 0.6);
        }

        .table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: auto;
        }
        .table-modern thead {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            box-shadow: 0 4px 15px rgba(5, 150, 105, 0.3);
            border-top: 3px solid var(--green-400);
        }
        .table-modern thead th {
            padding: 0.875rem 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            border: none;
            white-space: nowrap;
            text-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }

        .table-modern tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid var(--border);
        }
        .table-modern tbody tr:hover {
            background: linear-gradient(90deg, rgba(240, 253, 244, 0.8), rgba(220, 252, 231, 0.6));
            transform: scale(1.01);
            box-shadow: 0 2px 10px rgba(16, 185, 129, 0.15);
        }

        .table-modern tbody tr.table-danger {
            background: rgba(239, 68, 68, 0.1);
        }

        .table-modern tbody tr.table-warning {
            background: rgba(245, 158, 11, 0.1);
        }

        /* Category Header Row Styles */
        .category-header-row {
            transition: all 0.3s ease;
            user-select: none;
        }
        .category-header-row:hover {
            opacity: 0.9;
            transform: scale(1.01);
        }
        .category-toggle-icon {
            transition: transform 0.3s ease;
        }
        .category-header-row.collapsed .category-toggle-icon {
            transform: rotate(-90deg);
        }

        /* Category Row Styles */
        .category-row {
            transition: all 0.3s ease;
        }
        .category-row.hidden {
            display: none;
        }

        .table-modern tbody td {
            padding: 0.875rem 0.75rem;
            vertical-align: middle;
            font-size: 0.875rem;
            color: var(--dark);
        }
        .table-modern tbody td:first-child {
            font-weight: 600;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
        }
        .table-responsive table {
            min-width: 1000px;
            width: 100%;
        }
        @media (min-width: 1400px) {
            .table-responsive table {
                min-width: auto;
            }
        }

        .table-modern tbody td code {
            background: var(--light);
            color: var(--green-700);
            border: 1px solid var(--border);
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
        }

        .badge-modern {
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .edit-btn-modern {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .edit-btn-modern::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .edit-btn-modern:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.5);
            color: white;
        }

        .edit-btn-modern:hover::before {
            width: 300px;
            height: 300px;
        }

        .edit-btn-modern:active {
            transform: translateY(-1px) scale(1.02);
        }

        /* Modal - Light Green */
        .modal-content-modern {
            border: none;
            border-radius: 25px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.3);
            animation: modalFadeIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            background: white;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-30px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-backdrop.show {
            background: rgba(0, 0, 0, 0.5);
            animation: backdropFadeIn 0.3s ease;
        }

        @keyframes backdropFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-header-modern {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            padding: 2rem;
            border: none;
        }

        .modal-header-modern .btn-close {
            filter: brightness(0) invert(1);
        }

        .form-control-modern {
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
            color: var(--dark);
        }

        .form-control-modern:focus {
            border-color: var(--green-500);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
            color: var(--dark);
        }

        /* Status Icons */
        .status-icon {
            font-size: 1.5rem;
            animation: scaleIn 0.3s ease;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }


        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
                padding: 1rem;
                width: 100%;
            }
            .content-card {
                padding: 1rem;
            }
            .table-responsive table {
                min-width: 800px;
            }
            .table-modern thead th,
            .table-modern tbody td {
                padding: 0.625rem 0.5rem;
                font-size: 0.8rem;
            }
            .page-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animated"></div>
    <div class="dashboard-wrapper">
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <img src="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" alt="Logo">
                <h3>TECHNO PEST</h3>
                <p>Inventory Management</p>
            </div>
            <nav class="nav-menu">
              <div class="user-section">
                  <a href="dashboard.php" class="btn btn-modern btn-primary-modern w-100 mb-2" style="background: linear-gradient(135deg, var(--green-500), var(--green-600)); color: white; border: none; padding: 0.75rem 2rem; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);">
                      <i class="bi bi-speedometer2"></i> Dashboard
                  </a>
              </div>
                <div class="nav-item">
                    <a href="inventory.php" class="nav-link active">
                        <i class="bi bi-box-seam"></i>
                        <span>Inventory</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="inventory_report.php" class="nav-link">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                        <span>Reports</span>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <div class="top-bar">
                <h1 class="page-title">
                    <i class="bi bi-box-seam me-2"></i>Inventory Management
                </h1>
            </div>
            <!-- Rotation Badge Section -->
            <div class="content-card mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h2 class="mb-1" style="font-size: 1.5rem; color: var(--green-700);">
                            <i class="bi bi-arrow-repeat me-2"></i>Smart Rotation Dashboard
                        </h2>
                        <p class="text-muted mb-0">Quarter <?= $rotation_period + 1 ?> • <?= $current_period ?></p>
                    </div>
                </div>
            </div>

            <!-- Active Chemicals -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="content-card" style="border-top-color: var(--success);">
                        <div class="chemical-title" style="color: var(--success);">
                            <i class="bi bi-bug me-2"></i>Termite Control
                        </div>
                        <div class="chemical-name"><?= htmlspecialchars(ucwords(strtolower($current_termite))) ?></div>
                        <span class="active-badge" style="background: linear-gradient(135deg, var(--green-400), var(--green-600)); color: white;">
                            <i class="bi bi-check-circle me-2"></i>Active This Quarter
                        </span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="content-card" style="border-top-color: #3b82f6;">
                        <div class="chemical-title" style="color: #3b82f6;">
                            <i class="bi bi-shield-fill me-2"></i>General Pest
                        </div>
                        <div class="chemical-name"><?= htmlspecialchars(ucwords(strtolower($current_pest))) ?></div>
                        <span class="active-badge" style="background: linear-gradient(135deg, #60a5fa, #3b82f6); color: white;">
                            <i class="bi bi-check-circle me-2"></i>Active This Quarter
                        </span>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card success">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <i class="bi bi-boxes" style="font-size: 2.5rem; opacity: 0.9;"></i>
                    </div>
                    <h3><?= $total_items ?></h3>
                    <p class="mb-0">Total Items</p>
                </div>
                <div class="stat-card success">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <i class="bi bi-check-circle" style="font-size: 2.5rem; opacity: 0.9;"></i>
                    </div>
                    <h3><?= number_format($total_stock, 0) ?></h3>
                    <p class="mb-0">Total Stock</p>
                </div>
                <div class="stat-card success">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <i class="bi bi-check2-all" style="font-size: 2.5rem; opacity: 0.9;"></i>
                    </div>
                    <h3><?= $healthy_items ?></h3>
                    <p class="mb-0">Healthy Items</p>
                </div>
                <div class="stat-card warning">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <i class="bi bi-exclamation-triangle" style="font-size: 2.5rem; opacity: 0.9;"></i>
                    </div>
                    <h3><?= $alert_count ?></h3>
                    <p class="mb-0">Alerts</p>
                </div>
            </div>

        <!-- Stock Alert Banner -->
        <?php if ($has_low || $has_empty): ?>
        <div id="stockAlertBanner">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-exclamation-triangle-fill fs-1 alert-icon"></i>
                    <div>
                        <h5 class="mb-1 fw-bold">Stock Alert!</h5>
                        <p class="mb-0">
                            <?= $has_empty ? '<span class="fw-bold">' . count($empty_items) . ' EMPTY</span>' : '' ?>
                            <?= $has_empty && $has_low ? ' | ' : '' ?>
                            <?= $has_low ? '<span class="fw-bold">' . ($alert_count - count($empty_items)) . ' LOW STOCK</span>' : '' ?>
                        </p>
                    </div>
                </div>
                <button class="btn btn-outline-light rounded-pill" onclick="document.getElementById('stockAlertBanner').remove()">
                    <i class="bi bi-x-lg me-2"></i>Dismiss
                </button>
            </div>
        </div>
        <?php endif; ?>


            <!-- Low Stock Items -->
            <?php if ($has_low): ?>
            <div class="content-card mb-4">
            <h4 class="section-title mb-4">
                <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Critical Stock Alert (<?= $alert_count ?>)
            </h4>
            <div class="low-stock-grid">
                <?php foreach ($low_stock_items as $item):
                    $stock = (float)$item['stocks'];
                    $icon = $stock <= 0 ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill';
                    $color = $stock <= 0 ? 'var(--danger)' : 'var(--warning)';
                ?>
                <div class="low-stock-item" style="border-left-color: <?= $color ?>;">
                    <i class="bi <?= $icon ?> fs-3" style="color: <?= $color ?>;"></i>
                    <div>
                        <div class="fw-bold text-dark"><?= htmlspecialchars($item['active_ingredient'] ?: $item['service_name']) ?></div>
                        <div class="text-muted small"><?= number_format($stock, 0) ?> bottle<?= $stock==1?'':'s' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

            <!-- Inventory Table -->
            <div class="content-card">
                <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <h2 class="mb-0" style="font-size: 1.5rem; color: var(--green-700);">
                        <i class="bi bi-list-ul me-2"></i>Full Inventory
                    </h2>
                    <div>
                        <a href="inventory_report.php" class="btn btn-modern btn-primary-modern me-2">
                            <i class="bi bi-file-earmark-bar-graph"></i> Generate Report
                        </a>
                        <button type="button" class="btn btn-modern btn-warning-modern me-2 position-relative" data-bs-toggle="modal" data-bs-target="#deductionNotificationsModal" style="background: linear-gradient(135deg, var(--warning), #d97706); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);">
                            <i class="bi bi-bell-fill me-2"></i>Notifications
                            <?php if (!empty($recent_deductions)): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                    <?= count($recent_deductions) ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        <a href="add_inventory.php" class="btn btn-modern btn-success-modern">
                            <i class="bi bi-plus-circle"></i> Add New
                        </a>
                    </div>
                </div>

            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Chemical</th>
                            <th class="text-center">Bottles</th>
                            <th class="text-center">Expiry</th>
                            <th>Barcode</th>
                            <th class="text-center">Date Added</th>
                            <th class="text-center">Last Updated</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($inventory)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5">
                                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                                <p class="text-muted fs-5">No inventory items yet.</p>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php 
                            // Display categories in defined order, then any remaining categories
                            $displayed_categories = [];
                            foreach ($category_order as $cat_name => $cat_info):
                                if (!isset($inventory_by_category[$cat_name]) || empty($inventory_by_category[$cat_name])) {
                                    continue;
                                }
                                $displayed_categories[] = $cat_name;
                                $items = $inventory_by_category[$cat_name];
                                $item_count = count($items);
                            ?>
                            <tr class="category-header-row" style="background: linear-gradient(135deg, <?= $cat_info['color'] ?>, <?= $cat_info['color'] ?>dd); color: white; cursor: pointer;" onclick="toggleCategory('<?= htmlspecialchars($cat_name, ENT_QUOTES) ?>')" data-category="<?= htmlspecialchars(str_replace(' ', '-', strtolower($cat_name)), ENT_QUOTES) ?>">
                                <td colspan="9" class="py-3">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center gap-3">
                                            <i class="bi <?= $cat_info['icon'] ?> fs-4"></i>
                                            <div>
                                                <h5 class="mb-0 fw-bold"><?= htmlspecialchars($cat_name) ?></h5>
                                                <small class="opacity-90"><?= $item_count ?> item<?= $item_count != 1 ? 's' : '' ?></small>
                                            </div>
                                        </div>
                                        <i class="bi bi-chevron-down category-toggle-icon" id="toggle-icon-<?= htmlspecialchars(str_replace(' ', '-', strtolower($cat_name)), ENT_QUOTES) ?>"></i>
                                    </div>
                                </td>
                            </tr>
                            <?php foreach ($items as $i):
                            $stock = (float)$i['stocks'];
                            $is_expired = !empty($i['expiry_date']) && strtotime($i['expiry_date']) < time();
                            $near_exp = !empty($i['expiry_date']) && strtotime($i['expiry_date']) < strtotime('+3 months') && !$is_expired;
                            $exp_display = !empty($i['expiry_date']) ? date('Y-m-d', strtotime($i['expiry_date'])) : '';
                            $ingredient = ucwords(strtolower($i['active_ingredient'] ?? ''));
                        ?>
                                <tr class="category-row category-<?= htmlspecialchars(str_replace(' ', '-', strtolower($cat_name)), ENT_QUOTES) ?> <?= $is_expired || $stock<=0 ? 'table-danger' : ($stock<=20 ? 'table-warning' : '') ?>">
                            <td class="fw-semibold"><?= htmlspecialchars($i['service_name']) ?></td>
                            <td><?= htmlspecialchars($ingredient ?: '—') ?></td>
                            <td class="text-center">
                                <span class="fw-bold"><?= number_format($stock, 0) ?></span>
                                <div class="d-flex flex-wrap gap-1 justify-content-center mt-1">
                                    <?php if($stock<=0): ?>
                                        <span class="badge-modern bg-danger text-white">EMPTY</span>
                                    <?php elseif($stock<=20): ?>
                                        <span class="badge-modern bg-warning text-dark">LOW</span>
                                    <?php else: ?>
                                        <span class="badge-modern bg-success text-white">In Stock</span>
                                    <?php endif; ?>
                                    <?php if($near_exp && $stock>0): ?>
                                        <span class="badge-modern bg-info text-white">NEAR EXP</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <?= $exp_display ?: '—' ?>
                                <?php if($is_expired): ?>
                                    <span class="badge-modern bg-danger text-white ms-2">EXPIRED</span>
                                <?php endif; ?>
                            </td>
                            <td><code class="bg-light p-1 rounded"><?= htmlspecialchars($i['barcode'] ?? '—') ?></code></td>
                            <td class="text-center small">
                                <?php if (!empty($i['created_at'])): ?>
                                    <div class="text-muted">
                                        <i class="bi bi-calendar-plus text-success"></i><br>
                                        <?= date('M d, Y', strtotime($i['created_at'])) ?><br>
                                        <small><?= date('h:i A', strtotime($i['created_at'])) ?></small>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center small">
                                <?php if (!empty($i['updated_at'])): ?>
                                    <div class="text-muted">
                                        <i class="bi bi-pencil-square text-primary"></i><br>
                                        <?= date('M d, Y', strtotime($i['updated_at'])) ?><br>
                                        <small><?= date('h:i A', strtotime($i['updated_at'])) ?></small>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if($is_expired || $stock<=0): ?>
                                    <i class="bi bi-x-circle-fill text-danger status-icon"></i>
                                <?php elseif($stock<=20): ?>
                                    <i class="bi bi-exclamation-triangle-fill text-warning status-icon"></i>
                                <?php else: ?>
                                    <i class="bi bi-check-circle-fill text-success status-icon"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <button class="edit-btn-modern add-stock-btn"
                                    data-id="<?= $i['inventory_id'] ?>"
                                    data-service="<?= htmlspecialchars($i['service_name']) ?>"
                                    data-ingredient="<?= htmlspecialchars($ingredient) ?>"
                                    data-stocks="<?= $stock ?>">
                                    <i class="bi bi-plus-circle me-1"></i>Add Stock
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                            <?php endforeach; ?>
                            
                            <?php 
                            // Display any remaining categories not in the defined order
                            foreach ($inventory_by_category as $cat_name => $items):
                                if (in_array($cat_name, $displayed_categories)) {
                                    continue;
                                }
                                $item_count = count($items);
                                $cat_info = $category_order['Other Services'] ?? ['icon' => 'bi-box-seam', 'color' => '#64748b'];
                            ?>
                            <tr class="category-header-row" style="background: linear-gradient(135deg, <?= $cat_info['color'] ?>, <?= $cat_info['color'] ?>dd); color: white; cursor: pointer;" onclick="toggleCategory('<?= htmlspecialchars($cat_name, ENT_QUOTES) ?>')" data-category="<?= htmlspecialchars(str_replace(' ', '-', strtolower($cat_name)), ENT_QUOTES) ?>">
                                <td colspan="9" class="py-3">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center gap-3">
                                            <i class="bi <?= $cat_info['icon'] ?> fs-4"></i>
                                            <div>
                                                <h5 class="mb-0 fw-bold"><?= htmlspecialchars($cat_name) ?></h5>
                                                <small class="opacity-90"><?= $item_count ?> item<?= $item_count != 1 ? 's' : '' ?></small>
                                            </div>
                                        </div>
                                        <i class="bi bi-chevron-down category-toggle-icon" id="toggle-icon-<?= htmlspecialchars(str_replace(' ', '-', strtolower($cat_name)), ENT_QUOTES) ?>"></i>
                                    </div>
                            </td>
                        </tr>
                            <?php foreach ($items as $i):
                                    $stock = (float)$i['stocks'];
                                    $is_expired = !empty($i['expiry_date']) && strtotime($i['expiry_date']) < time();
                                    $near_exp = !empty($i['expiry_date']) && strtotime($i['expiry_date']) < strtotime('+3 months') && !$is_expired;
                                    $exp_display = !empty($i['expiry_date']) ? date('Y-m-d', strtotime($i['expiry_date'])) : '';
                                    $ingredient = ucwords(strtolower($i['active_ingredient'] ?? ''));
                                ?>
                                <tr class="category-row category-<?= htmlspecialchars(str_replace(' ', '-', strtolower($cat_name)), ENT_QUOTES) ?> <?= $is_expired || $stock<=0 ? 'table-danger' : ($stock<=20 ? 'table-warning' : '') ?>">
                                    <td class="fw-semibold"><?= htmlspecialchars($i['service_name']) ?></td>
                                    <td><?= htmlspecialchars($ingredient ?: '—') ?></td>
                                    <td class="text-center">
                                        <span class="fw-bold"><?= number_format($stock, 0) ?></span>
                                        <div class="d-flex flex-wrap gap-1 justify-content-center mt-1">
                                            <?php if($stock<=0): ?>
                                                <span class="badge-modern bg-danger text-white">EMPTY</span>
                                            <?php elseif($stock<=20): ?>
                                                <span class="badge-modern bg-warning text-dark">LOW</span>
                                            <?php else: ?>
                                                <span class="badge-modern bg-success text-white">In Stock</span>
                                            <?php endif; ?>
                                            <?php if($near_exp && $stock>0): ?>
                                                <span class="badge-modern bg-info text-white">NEAR EXP</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?= $exp_display ?: '—' ?>
                                        <?php if($is_expired): ?>
                                            <span class="badge-modern bg-danger text-white ms-2">EXPIRED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="bg-light p-1 rounded"><?= htmlspecialchars($i['barcode'] ?? '—') ?></code></td>
                                    <td class="text-center small">
                                        <?php if (!empty($i['created_at'])): ?>
                                            <div class="text-muted">
                                                <i class="bi bi-calendar-plus text-success"></i><br>
                                                <?= date('M d, Y', strtotime($i['created_at'])) ?><br>
                                                <small><?= date('h:i A', strtotime($i['created_at'])) ?></small>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center small">
                                        <?php if (!empty($i['updated_at'])): ?>
                                            <div class="text-muted">
                                                <i class="bi bi-pencil-square text-primary"></i><br>
                                                <?= date('M d, Y', strtotime($i['updated_at'])) ?><br>
                                                <small><?= date('h:i A', strtotime($i['updated_at'])) ?></small>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if($is_expired || $stock<=0): ?>
                                            <i class="bi bi-x-circle-fill text-danger status-icon"></i>
                                        <?php elseif($stock<=20): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-warning status-icon"></i>
                                        <?php else: ?>
                                            <i class="bi bi-check-circle-fill text-success status-icon"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="edit-btn-modern add-stock-btn"
                                            data-id="<?= $i['inventory_id'] ?>"
                                            data-service="<?= htmlspecialchars($i['service_name']) ?>"
                                            data-ingredient="<?= htmlspecialchars($ingredient) ?>"
                                            data-stocks="<?= $stock ?>">
                                            <i class="bi bi-plus-circle me-1"></i>Add Stock
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Stock Modal -->
    <div class="modal fade" id="addStockModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-modern">
                <div class="modal-header modal-header-modern">
                    <h3 class="modal-title fw-bold">
                        <i class="bi bi-plus-circle me-2"></i>Add Stock
                    </h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="addStockForm">
                        <input type="hidden" name="id" id="stock_id">

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="p-3 rounded-3" style="background: linear-gradient(135deg, var(--green-400), var(--green-600)); color: white;">
                                    <small class="fw-bold text-uppercase d-block mb-2 opacity-90">Service</small>
                                    <p class="fs-5 fw-bold mb-0" id="modal_service">—</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 rounded-3" style="background: linear-gradient(135deg, #60a5fa, #3b82f6); color: white;">
                                    <small class="fw-bold text-uppercase d-block mb-2 opacity-90">Chemical</small>
                                    <p class="fs-5 fw-bold mb-0" id="modal_ingredient">—</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <div class="p-3 rounded-3" style="background: var(--green-50); border: 2px solid var(--green-200);">
                                    <small class="fw-bold text-uppercase d-block mb-2 text-muted">Current Stock</small>
                                    <p class="fs-4 fw-bold mb-0 text-success" id="current_stock">—</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-plus-circle me-2"></i>Stock to Add (bottles)
                                </label>
                                <input type="number" step="1" min="1" name="stock_to_add" id="stock_to_add" class="form-control form-control-modern" placeholder="Enter amount to add" required autofocus>
                                <small class="text-muted">This will be added to the current stock.</small>
                                <div class="invalid-feedback" id="stock_error" style="display: none;">
                                    Please enter a valid number greater than 0.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-calendar-x me-2"></i>Expiry Date (optional)
                                </label>
                                <input type="date" name="expiry_date" id="expiry_date" class="form-control form-control-modern">
                                <small class="text-muted">Set expiry date for the new stock being added.</small>
                            </div>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-md-12">
                                <div class="p-3 rounded-3" style="background: var(--green-100); border: 2px solid var(--green-300);">
                                    <small class="fw-bold text-uppercase d-block mb-2 text-muted">New Total Stock</small>
                                    <p class="fs-4 fw-bold mb-0 text-success" id="new_total_stock">—</p>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 p-4">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="addStockSubmitBtn" form="addStockForm" class="btn btn-success-modern btn-modern px-4">
                        <i class="bi bi-plus-circle me-2"></i>Add Stock
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Particles -->
    <canvas id="particlesCanvas" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none;"></canvas>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Floating Particles Animation
        const canvas = document.getElementById('particlesCanvas');
        const ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;

        const particles = [];
        const particleCount = 50;

        class Particle {
            constructor() {
                this.x = Math.random() * canvas.width;
                this.y = Math.random() * canvas.height;
                this.size = Math.random() * 3 + 1;
                this.speedX = Math.random() * 1 - 0.5;
                this.speedY = Math.random() * 1 - 0.5;
                this.opacity = Math.random() * 0.3 + 0.1;
                const colors = ['rgba(16, 185, 129, 0.2)', 'rgba(34, 197, 94, 0.2)', 'rgba(52, 211, 153, 0.2)'];
                this.color = colors[Math.floor(Math.random() * colors.length)];
            }

            update() {
                this.x += this.speedX;
                this.y += this.speedY;

                if (this.x > canvas.width) this.x = 0;
                if (this.x < 0) this.x = canvas.width;
                if (this.y > canvas.height) this.y = 0;
                if (this.y < 0) this.y = canvas.height;
            }

            draw() {
                ctx.shadowBlur = 10;
                ctx.shadowColor = this.color;
                ctx.fillStyle = this.color;
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
                ctx.shadowBlur = 0;
            }
        }

        for (let i = 0; i < particleCount; i++) {
            particles.push(new Particle());
        }

        function animateParticles() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(particle => {
                particle.update();
                particle.draw();
            });
            requestAnimationFrame(animateParticles);
        }

        animateParticles();

        window.addEventListener('resize', () => {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        });

        // Animated Counter Function
        function animateCounter(element, start, end, duration, suffix = '') {
            const range = end - start;
            const increment = end > start ? 1 : -1;
            const stepTime = Math.abs(Math.floor(duration / range));
            let current = start;

            const timer = setInterval(() => {
                current += increment;
                if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
                    element.textContent = end.toLocaleString('en-US', { maximumFractionDigits: 1 }) + suffix;
                    clearInterval(timer);
                } else {
                    element.textContent = current.toLocaleString('en-US', { maximumFractionDigits: 1 }) + suffix;
                }
            }, stepTime);
        }

        // Initialize animated counters when stat cards are visible
        const observerOptions = {
            threshold: 0.5,
            rootMargin: '0px'
        };

        const statObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const statCard = entry.target;
                    const statValue = statCard.querySelector('.stat-value');
                    const currentValue = statValue.textContent.trim();

                    // Extract numeric value
                    const numValue = parseFloat(currentValue.replace(/,/g, ''));

                    if (!isNaN(numValue) && numValue > 0) {
                        statValue.textContent = '0';
                        setTimeout(() => {
                            animateCounter(statValue, 0, numValue, 2000);
                        }, 200);
                    }

                    statObserver.unobserve(statCard);
                }
            });
        }, observerOptions);

        // Observe all stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            statObserver.observe(card);
        });

        // Scroll-triggered animations for table rows
        const tableObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationPlayState = 'running';
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.table-modern tbody tr').forEach((row, index) => {
            row.style.animationDelay = `${index * 0.05}s`;
            tableObserver.observe(row);
        });

        // Staggered animations for low stock items
        document.querySelectorAll('.low-stock-item').forEach((item, index) => {
            item.style.animationDelay = `${index * 0.1}s`;
        });


        // Add Stock button handler with animation
        document.querySelectorAll('.add-stock-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                // Add ripple effect
                const ripple = document.createElement('span');
                ripple.style.cssText = `
                    position: absolute;
                    border-radius: 50%;
                    background: rgba(255,255,255,0.6);
                    width: 100px;
                    height: 100px;
                    margin-top: -50px;
                    margin-left: -50px;
                    animation: ripple 0.6s;
                    pointer-events: none;
                `;

                // Add ripple animation
                if (!document.getElementById('rippleStyle')) {
                    const style = document.createElement('style');
                    style.id = 'rippleStyle';
                    style.textContent = `
                        @keyframes ripple {
                            to {
                                transform: scale(4);
                                opacity: 0;
                            }
                        }
                    `;
                    document.head.appendChild(style);
                }

                btn.style.position = 'relative';
                btn.appendChild(ripple);

                setTimeout(() => ripple.remove(), 600);

                currentStockValue = parseFloat(btn.dataset.stocks) || 0;

                // Populate modal fields
                document.getElementById('stock_id').value = btn.dataset.id;
                document.getElementById('current_stock').textContent = Math.round(currentStockValue) + ' bottles';
                document.getElementById('stock_to_add').value = '';
                document.getElementById('expiry_date').value = '';
                document.getElementById('new_total_stock').textContent = Math.round(currentStockValue) + ' bottles';
                document.getElementById('modal_service').textContent = btn.dataset.service;
                document.getElementById('modal_ingredient').textContent = btn.dataset.ingredient || '—';

                // Reset form validation
                const stockInput = document.getElementById('stock_to_add');
                const errorDiv = document.getElementById('stock_error');
                if (stockInput) {
                    stockInput.classList.remove('is-invalid');
                }
                if (errorDiv) {
                    errorDiv.style.display = 'none';
                }

                // Reset submit button state
                const submitBtn = document.getElementById('addStockSubmitBtn');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('bg-success', 'bg-danger', 'text-white', 'disabled');
                    submitBtn.classList.add('btn-success-modern');
                    submitBtn.innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add Stock';
                }

                const modal = new bootstrap.Modal(document.getElementById('addStockModal'));
                modal.show();

                // Focus input after modal is shown
                setTimeout(() => {
                    if (stockInput) {
                        stockInput.focus();
                    }
                }, 300);
            });
        });

        // Store current stock value
        let currentStockValue = 0;

        // Calculate new total stock as user types
        const stockToAddInput = document.getElementById('stock_to_add');
        const newTotalStockDisplay = document.getElementById('new_total_stock');

        if (stockToAddInput) {
            stockToAddInput.addEventListener('input', function() {
                const stockToAdd = parseFloat(this.value) || 0;
                const newTotal = currentStockValue + stockToAdd;
                newTotalStockDisplay.textContent = Math.round(newTotal) + ' bottles';

                // Validate input
                const errorDiv = document.getElementById('stock_error');
                if (stockToAdd <= 0 && this.value !== '') {
                    this.classList.add('is-invalid');
                    if (errorDiv) errorDiv.style.display = 'block';
                } else {
                    this.classList.remove('is-invalid');
                    if (errorDiv) errorDiv.style.display = 'none';
                }
            });

            // Validate on blur
            stockToAddInput.addEventListener('blur', function() {
                const stockToAdd = parseFloat(this.value) || 0;
                const errorDiv = document.getElementById('stock_error');
                if (stockToAdd <= 0 && this.value !== '') {
                    this.classList.add('is-invalid');
                    if (errorDiv) errorDiv.style.display = 'block';
                } else {
                    this.classList.remove('is-invalid');
                    if (errorDiv) errorDiv.style.display = 'none';
                }
            });
        }

        // Reset form when modal is closed
        const addStockModal = document.getElementById('addStockModal');
        if (addStockModal) {
            addStockModal.addEventListener('hidden.bs.modal', function() {
                document.getElementById('stock_to_add').value = '';
                document.getElementById('expiry_date').value = '';
                currentStockValue = 0;
            });
        }

        // Category Toggle Function
        function toggleCategory(categoryName) {
            // Create selector matching the class name format used in PHP
            const categoryClass = 'category-' + categoryName.replace(/\s+/g, '-').toLowerCase();
            const categoryRows = document.querySelectorAll('tr.' + categoryClass);
            const headerRow = event.currentTarget.closest('tr');
            const toggleIconId = 'toggle-icon-' + categoryName.replace(/\s+/g, '-').toLowerCase();
            const toggleIcon = document.getElementById(toggleIconId);
            
            if (categoryRows.length === 0) return;
            
            // Check if currently hidden by checking the first row
            const firstRow = categoryRows[0];
            const isCurrentlyHidden = firstRow.style.display === 'none';
            
            // Toggle visibility of category rows with smooth animation
            categoryRows.forEach((row, index) => {
                if (isCurrentlyHidden) {
                    // Show rows
                    setTimeout(() => {
                        row.style.display = '';
                        row.style.opacity = '0';
                        row.style.transition = 'opacity 0.3s ease';
                        setTimeout(() => {
                            row.style.opacity = '1';
                        }, 10);
                    }, index * 20);
                    row.classList.remove('hidden');
                } else {
                    // Hide rows
                    row.style.transition = 'opacity 0.3s ease';
                    row.style.opacity = '0';
                    setTimeout(() => {
                        row.style.display = 'none';
                    }, 300);
                    row.classList.add('hidden');
                }
            });
            
            // Update toggle icon and header
            if (toggleIcon && headerRow) {
                if (!isCurrentlyHidden) {
                    toggleIcon.classList.remove('bi-chevron-down');
                    toggleIcon.classList.add('bi-chevron-right');
                    headerRow.classList.add('collapsed');
                } else {
                    toggleIcon.classList.remove('bi-chevron-right');
                    toggleIcon.classList.add('bi-chevron-down');
                    headerRow.classList.remove('collapsed');
                }
            }
        }

        // Form submission handler
        const addStockForm = document.getElementById('addStockForm');
        const submitBtn = document.getElementById('addStockSubmitBtn') || document.querySelector('button[form="addStockForm"][type="submit"]');

        if (addStockForm && submitBtn) {
            addStockForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                // Get the submit button
                const btn = submitBtn;
                const orig = btn.innerHTML;

                // Validate input
                const stockToAdd = parseFloat(document.getElementById('stock_to_add').value);
                if (!stockToAdd || stockToAdd <= 0) {
                    alert('Please enter a valid stock amount greater than 0');
                    return;
                }

                // Update button state
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Adding Stock...';
                btn.disabled = true;
                btn.classList.add('disabled');

                // Prepare form data
                const formData = new FormData(addStockForm);
                formData.append('action', 'add_stock');

                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        const text = await response.text();
                        throw new Error('Invalid response from server. Please try again.');
                    }

                    const result = await response.json();

                    if (result.success) {
                        // Success feedback
                        btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Stock Added Successfully!';
                        btn.classList.remove('btn-success-modern');
                        btn.classList.add('bg-success', 'text-white');

                        // Show success message
                        const successAlert = document.createElement('div');
                        successAlert.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                        successAlert.style.zIndex = '9999';
                        successAlert.innerHTML = `
                            <i class="bi bi-check-circle me-2"></i>
                            <strong>Success!</strong> Added ${result.added} bottles. New total: ${result.new_stocks} bottles.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        document.body.appendChild(successAlert);

                        // Close modal and reload after delay
                        const modal = bootstrap.Modal.getInstance(document.getElementById('addStockModal'));
                        if (modal) {
                            modal.hide();
                        }

                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        throw new Error(result.message || 'Failed to add stock');
                    }
                } catch (error) {
                    console.error('Error adding stock:', error);

                    // Error feedback
                    btn.innerHTML = '<i class="bi bi-x-circle me-2"></i>Failed';
                    btn.classList.remove('btn-success-modern');
                    btn.classList.add('bg-danger', 'text-white');

                    // Show error message
                    const errorAlert = document.createElement('div');
                    errorAlert.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                    errorAlert.style.zIndex = '9999';
                    errorAlert.innerHTML = `
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Error!</strong> ${error.message || 'Failed to add stock. Please try again.'}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.body.appendChild(errorAlert);

                    // Reset button after delay
                    setTimeout(() => {
                        btn.innerHTML = orig;
                        btn.classList.remove('bg-danger', 'text-white', 'disabled');
                        btn.classList.add('btn-success-modern');
                        btn.disabled = false;

                        // Remove error alert
                        if (errorAlert.parentNode) {
                            errorAlert.remove();
                        }
                    }, 3000);
                }
            });
        }
    </script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Deduction Notifications Modal -->
    <div class="modal fade deduction-notifications-modal" id="deductionNotificationsModal" tabindex="-1" aria-labelledby="deductionNotificationsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fw-bold" id="deductionNotificationsModalLabel">
                        <i class="bi bi-bell-fill me-2"></i>Recent Chemical Deductions
                    </h2>
                    <span class="badge bg-light text-dark px-3 py-2 ms-2" style="font-size: 0.875rem;">
                        <?= count($recent_deductions) ?> Notification<?= count($recent_deductions) != 1 ? 's' : '' ?>
                    </span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($recent_deductions)): ?>
                        <div class="deduction-notifications-empty">
                            <i class="bi bi-inbox"></i>
                            <p class="mb-0">No recent deductions to display</p>
                        </div>
                    <?php else: ?>
                        <div class="deduction-notifications">
                            <?php foreach ($recent_deductions as $deduction): 
                                $deduction_date = new DateTime($deduction['deduction_date']);
                                $time_ago = '';
                                $now = new DateTime();
                                $diff = $now->diff($deduction_date);
                                
                                if ($diff->days > 0) {
                                    $time_ago = $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
                                } elseif ($diff->h > 0) {
                                    $time_ago = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
                                } elseif ($diff->i > 0) {
                                    $time_ago = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
                                } else {
                                    $time_ago = 'Just now';
                                }
                            ?>
                                <div class="deduction-notification-card">
                                    <div class="deduction-notification-header">
                                        <div class="deduction-notification-title">
                                            <i class="bi bi-dash-circle-fill"></i>
                                            Chemical Deducted
                                        </div>
                                        <div class="deduction-notification-time">
                                            <i class="bi bi-clock"></i>
                                            <?= $time_ago ?>
                                        </div>
                                    </div>
                                    
                                    <div class="deduction-notification-body">
                                        <div class="deduction-info-item">
                                            <span class="deduction-info-label">Chemical</span>
                                            <span class="deduction-info-value">
                                                <?= htmlspecialchars($deduction['active_ingredient'] ?: 'Unknown') ?>
                                            </span>
                                        </div>
                                        
                                        <div class="deduction-info-item">
                                            <span class="deduction-info-label">Service</span>
                                            <span class="deduction-info-value">
                                                <?= htmlspecialchars($deduction['service_name'] ?: 'N/A') ?>
                                            </span>
                                        </div>
                                        
                                        <div class="deduction-info-item">
                                            <span class="deduction-info-label">Quantity Deducted</span>
                                            <span class="deduction-info-value" style="color: var(--warning);">
                                                <?= number_format((float)$deduction['quantity_deducted'], 0) ?> bottle<?= (float)$deduction['quantity_deducted'] != 1 ? 's' : '' ?>
                                            </span>
                                        </div>
                                        
                                        <div class="deduction-info-item">
                                            <span class="deduction-info-label">Stock After</span>
                                            <span class="deduction-info-value">
                                                <?= number_format((float)$deduction['stock_after'], 0) ?> bottle<?= (float)$deduction['stock_after'] != 1 ? 's' : '' ?>
                                            </span>
                                        </div>
                                        
                                        <?php if (!empty($deduction['barcode'])): ?>
                                        <div class="deduction-info-item">
                                            <span class="deduction-info-label">Barcode</span>
                                            <span class="deduction-info-value" style="font-family: monospace; font-size: 0.875rem;">
                                                <?= htmlspecialchars($deduction['barcode']) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($deduction['booking_reference'])): ?>
                                        <div class="deduction-info-item">
                                            <span class="deduction-info-label">Booking Reference</span>
                                            <span class="deduction-info-value" style="color: var(--green-600);">
                                                <?= htmlspecialchars($deduction['booking_reference']) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($deduction['customer_name'])): ?>
                                        <div class="deduction-info-item">
                                            <span class="deduction-info-label">Customer</span>
                                            <span class="deduction-info-value">
                                                <?= htmlspecialchars($deduction['customer_name']) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="deduction-info-item">
                                            <span class="deduction-info-label">Deducted By</span>
                                            <span class="deduction-info-value">
                                                <?= htmlspecialchars($deduction['deducted_by'] ?: 'System') ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($deduction['notes'])): ?>
                                        <div class="deduction-notes">
                                            <div class="deduction-notes-label">
                                                <i class="bi bi-sticky-fill"></i>Notes
                                            </div>
                                            <div class="deduction-notes-text">
                                                <?= htmlspecialchars($deduction['notes']) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-modern btn-secondary-modern" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
