<?php
session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}
function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
$adminName = $_SESSION['admin_name'] ?? 'Admin';

$host = "localhost";
$dbname = "pest control";  // Fixed space issue
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Counts
    $inventoryCount = $pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
    $serviceRecordCount = $pdo->query("SELECT COUNT(*) FROM service_bookings")->fetchColumn();

    // Latest 5 bookings
    $stmt = $pdo->query("SELECT customer_name, service_name, appointment_date FROM service_bookings ORDER BY appointment_date DESC LIMIT 5");
    $latestServiceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Workload: Next 14 days
    $workloadQuery = "SELECT
        appointment_date,
        COUNT(*) as booking_count,
        DAYNAME(appointment_date) as day_name,
        DATE_FORMAT(appointment_date, '%W, %M %d, %Y') as formatted_date
    FROM service_bookings
    WHERE appointment_date >= CURDATE()
      AND appointment_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
      AND status != 'Cancelled'
    GROUP BY appointment_date
    ORDER BY appointment_date ASC";
    $workloadStmt = $pdo->query($workloadQuery);
    $workloadData = $workloadStmt->fetchAll(PDO::FETCH_ASSOC);

    // Detailed bookings per day
    $workloadDetails = [];
    foreach ($workloadData as $day) {
        $detailsQuery = "SELECT
            sb.booking_id, sb.customer_name, sb.reference_code, sb.appointment_time,
            sb.phone_number, sb.email, sb.address, sb.status, sb.structure_types, sb.price_range,
            s.service_name
        FROM service_bookings sb
        LEFT JOIN services s ON sb.service_id = s.service_id
        WHERE sb.appointment_date = ? AND sb.status != 'Cancelled'
        ORDER BY sb.appointment_time ASC";
        $detailsStmt = $pdo->prepare($detailsQuery);
        $detailsStmt->execute([$day['appointment_date']]);
        $workloadDetails[$day['appointment_date']] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Workload level function
    function getWorkloadLevel($count) {
        if ($count >= 5) return ['level' => 'HIGH', 'color' => '#dc143c', 'bg' => 'rgba(220,20,60,0.1)'];
        if ($count >= 2) return ['level' => 'MEDIUM', 'color' => '#ffa500', 'bg' => 'rgba(255,165,0,0.1)'];
        return ['level' => 'LOW', 'color' => '#22c55e', 'bg' => 'rgba(34,197,94,0.1)'];
    }

    $today = date('Y-m-d');

    // === NOTIFICATIONS SYSTEM ===
    if (!isset($_SESSION['viewed_notifications'])) {
        $_SESSION['viewed_notifications'] = ['new_bookings'=>[], 'low_stock'=>[], 'empty_stock'=>[], 'expiring'=>[]];
        $_SESSION['last_notification_view_time'] = null;
    }

    // Mark as viewed via AJAX
    if (isset($_POST['mark_notifications_viewed'])) {
        $_SESSION['last_notification_view_time'] = date('Y-m-d H:i:s');
        echo json_encode(['success' => true]);
        exit;
    }

    $totalNotifications = 0;
    $newBookingsCount = $lowStockCount = $emptyStockCount = $expiringCount = 0;

    // New Bookings (last 24h or today/tomorrow)
    $newBookingsDetails = $pdo->query("SELECT sb.customer_name, sb.reference_code, COALESCE(s.service_name, 'Service') as service_name,
        sb.created_at, sb.appointment_date, sb.appointment_time
        FROM service_bookings sb
        LEFT JOIN services s ON sb.service_id = s.service_id
        WHERE (sb.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            OR sb.appointment_date IN (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY)))
          AND sb.status != 'Cancelled'
        ORDER BY sb.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $newBookingsCount = count($newBookingsDetails);

    // Low / Empty / Expiring Stock
    $lowStockDetails = $pdo->query("SELECT i.stocks, s.service_name, a.name AS active_ingredient
        FROM inventory i JOIN services s ON i.service_id = s.service_id
        LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        WHERE i.stocks > 0 AND i.stocks < 10 ORDER BY i.stocks ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $lowStockCount = count($lowStockDetails);

    $emptyStockDetails = $pdo->query("SELECT s.service_name, a.name AS active_ingredient
        FROM inventory i JOIN services s ON i.service_id = s.service_id
        LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        WHERE i.stocks = 0 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $emptyStockCount = count($emptyStockDetails);

    $expiringDetails = $pdo->query("SELECT i.expiry_date, i.stocks, s.service_name, a.name AS active_ingredient
        FROM inventory i JOIN services s ON i.service_id = s.service_id
        LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        WHERE i.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY i.expiry_date ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $expiringCount = count($expiringDetails);

    $totalNotifications = $newBookingsCount + $lowStockCount + $emptyStockCount + $expiringCount;

} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard • Techno Pest Control</title>
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .sidebar nav a i {
            width: 24px;
            font-size: 1.1rem;
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
        .top-bar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px) saturate(180%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 2px solid var(--green-200);
            margin-bottom: 2rem;
            border-radius: 0;
        }

        .page-title {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Notification Bell */
        .notification-bell {
            position: relative;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);
        }

        .notification-bell:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 30px rgba(16, 185, 129, 0.6);
        }

        .notification-bell.ringing {
            animation: ring 0.5s ease-in-out;
        }

        .notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: white;
            border-radius: 50%;
            min-width: 22px;
            height: 22px;
            font-size: 0.7rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(239,68,68,0.4);
            animation: pulse 2s infinite;
        }

        /* Notification Dropdown */
        .notification-dropdown {
            position: fixed;
            top: 85px;
            right: 20px;
            width: 420px;
            max-height: 80vh;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            overflow: hidden;
            z-index: 9999;
            display: none;
            border: 1px solid var(--border);
        }

        .notification-dropdown.show {
            display: block;
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .notification-header {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .notification-header h5 {
            margin: 0;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .notification-content {
            max-height: 60vh;
            overflow-y: auto;
            padding: 1.25rem;
            background: #f8fafc;
        }

        .notification-content::-webkit-scrollbar { width: 6px; }
        .notification-content::-webkit-scrollbar-track { background: #f1f5f9; }
        .notification-content::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

        .notification-item {
            padding: 1.25rem;
            border-radius: 12px;
            background: white;
            margin-bottom: 0.75rem;
            border-left: 4px solid var(--success);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }

        .notification-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .notification-item.empty-stock { border-left-color: var(--danger); }
        .notification-item.low-stock { border-left-color: var(--warning); }
        .notification-item.expiring { border-left-color: var(--primary); }

        .notification-item strong {
            display: block;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        /* Content Cards */
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 30px rgba(16, 185, 129, 0.2), 0 0 40px rgba(34, 197, 94, 0.1);
            border: 2px solid var(--green-200);
            transition: all 0.3s ease;
        }

        .content-card:hover {
            box-shadow: 0 12px 40px rgba(16, 185, 129, 0.3), 0 0 50px rgba(34, 197, 94, 0.2);
            border-color: var(--green-300);
        }

        .content-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--green-700);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--green-200);
        }

        .content-card h3 i {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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
        .stat-card-icon {
            display: none;
        }

        /* Workload List */
        .workload-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .workload-item {
            background: white;
            border-radius: 16px;
            padding: 1.75rem;
            border-left: 5px solid var(--green-500);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
            transition: all 0.3s ease;
            border: 2px solid var(--green-200);
        }

        .workload-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
            border-color: var(--green-300);
        }

        .workload-item.high { border-left-color: var(--danger); }
        .workload-item.medium { border-left-color: var(--warning); }

        .workload-item strong {
            font-size: 1.1rem;
            color: var(--text-primary);
            display: block;
            margin-bottom: 0.5rem;
        }

        .workload-item .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
        }

        .workload-toggle {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            border: none;
            width: 100%;
            margin-top: 1rem;
            padding: 0.875rem;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);
            position: relative;
            overflow: hidden;
        }

        .workload-toggle::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .workload-toggle:hover::before {
            left: 100%;
        }

        .workload-toggle:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 30px rgba(16, 185, 129, 0.6);
        }

        .workload-details {
            display: none;
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 2px solid var(--border);
        }

        .workload-details.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .workload-details .border-start {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem !important;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }

        .workload-details .border-start:hover {
            background: #f1f5f9;
            transform: translateX(5px);
        }

        /* User Avatar & Logout */
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(59,130,246,0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            border: none;
            box-shadow: 0 4px 20px rgba(239, 68, 68, 0.4);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-danger::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .btn-danger:hover::before {
            left: 100%;
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 30px rgba(239, 68, 68, 0.6);
        }

        /* Animations */
        @keyframes ring {
            0%,100%{transform:rotate(0) scale(1)}
            10%,30%,50%,70%,90%{transform:rotate(15deg) scale(1.05)}
            20%,40%,60%,80%{transform:rotate(-15deg) scale(1.05)}
        }

        @keyframes slideDown {
            from { opacity:0; transform:translateY(-20px) scale(0.95); }
            to { opacity:1; transform:translateY(0) scale(1); }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding: 1rem;
                width: 100%;
            }
            .content-card {
                padding: 1rem;
            }
            .mobile-menu-btn {
                display: flex !important;
                align-items: center;
                justify-content: center;
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1001;
                background: linear-gradient(135deg, var(--green-600), var(--green-700));
                color: white;
                border: none;
                width: 50px;
                height: 50px;
                border-radius: 12px;
                font-size: 1.5rem;
                box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);
            }
            .notification-dropdown {
                width: calc(100vw - 2rem);
                right: 1rem;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .workload-list {
                grid-template-columns: 1fr;
            }
            .content-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animated"></div>
    <button class="mobile-menu-btn d-none" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <i class="bi bi-list"></i>
    </button>

    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img src="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" alt="Logo">
                <h3>TECHNO PEST</h3>
                <p>Manager Dashboard</p>
            </div>
            <nav class="nav-menu">
                <div class="nav-item">
                    <a href="dashboard.php" class="nav-link active">
                        <i class="bi bi-speedometer2"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="inventory.php" class="nav-link">
                        <i class="bi bi-box-seam"></i>
                        <span>Inventory</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="service_records.php" class="nav-link">
                        <i class="bi bi-journal-text"></i>
                        <span>Service Records</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="sales_report.php" class="nav-link">
                        <i class="bi bi-graph-up-arrow"></i>
                        <span>Sales Reports</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="analytics.php" class="nav-link">
                        <i class="bi bi-clipboard-data"></i>
                        <span>Analytics</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="audit_log.php" class="nav-link">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>Audit Log</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="backup_manager.php" class="nav-link">
                        <i class="bi bi-shield-check"></i>
                        <span>Backup Manager</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="cloud_storage.php" class="nav-link">
                        <i class="bi bi-cloud"></i>
                        <span>Cloud Storage</span>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <div class="container-fluid d-flex justify-content-between align-items-center">
                    <h1 class="page-title">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </h1>
                    <div class="d-flex align-items-center gap-3">
                        <button class="notification-bell" id="notificationBell" onclick="toggleNotifications()">
                            <i class="bi bi-bell-fill"></i>
                            <?php if ($totalNotifications > 0): ?>
                                <span class="notification-badge"><?= $totalNotifications > 99 ? '99+' : $totalNotifications ?></span>
                            <?php endif; ?>
                        </button>
                        <div>
                            <strong><?= h($_SESSION['username'] ?? 'Admin') ?></strong>
                            <small>Manager</small>
                        </div>
                        <form action="logout.php" method="post" class="m-0">
                            <button class="btn btn-danger btn-sm"><i class="bi bi-box-arrow-right me-2"></i> Logout</button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card success">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <i class="bi bi-box-seam" style="font-size: 2.5rem; opacity: 0.9;"></i>
                    </div>
                    <h3><?= number_format($inventoryCount) ?></h3>
                    <p class="mb-0">Inventory Items</p>
                </div>
                <div class="stat-card success">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <i class="bi bi-journal-text" style="font-size: 2.5rem; opacity: 0.9;"></i>
                    </div>
                    <h3><?= number_format($serviceRecordCount) ?></h3>
                    <p class="mb-0">Total Bookings</p>
                </div>
                <div class="stat-card success">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <i class="bi bi-calendar-check" style="font-size: 2.5rem; opacity: 0.9;"></i>
                    </div>
                    <h3><?= count($workloadData) ?></h3>
                    <p class="mb-0">Busy Days Ahead</p>
                </div>
                <div class="stat-card success">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <i class="bi bi-bell-fill" style="font-size: 2.5rem; opacity: 0.9;"></i>
                    </div>
                    <h3><?= $totalNotifications ?></h3>
                    <p class="mb-0">Notifications</p>
                </div>
            </div>

            <!-- Workload -->
            <div class="content-card">
                <h3><i class="bi bi-calendar-week"></i> Workload Optimization (Next 14 Days)</h3>
                <?php if (empty($workloadData)): ?>
                    <p class="text-center text-muted py-5">No upcoming bookings in the next 14 days.</p>
                <?php else: ?>
                    <div class="workload-list">
                        <?php foreach ($workloadData as $day):
                            $level = getWorkloadLevel($day['booking_count']);
                            $isToday = $day['appointment_date'] === $today;
                            $id = 'details-' . str_replace('-', '', $day['appointment_date']);
                        ?>
                            <div class="workload-item <?= strtolower($level['level']) ?>">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong><?= $isToday ? 'Today' : $day['day_name'] ?></strong><br>
                                        <small class="text-muted"><?= $day['formatted_date'] ?></small>
                                    </div>
                                    <span class="badge" style="background: <?= $level['bg'] ?>; color: <?= $level['color'] ?>">
                                        <?= $level['level'] ?> • <?= $day['booking_count'] ?> booking<?= $day['booking_count'] > 1 ? 's' : '' ?>
                                    </span>
                                </div>
                                <?php if (!empty($workloadDetails[$day['appointment_date']])): ?>
                                    <button class="workload-toggle mt-3" onclick="document.getElementById('<?= $id ?>').classList.toggle('show'); this.classList.toggle('active')">
                                        View Details <i class="bi bi-chevron-down ms-2"></i>
                                    </button>
                                    <div class="workload-details" id="<?= $id ?>">
                                        <?php foreach ($workloadDetails[$day['appointment_date']] as $b): ?>
                                            <div class="border-start border-3 border-primary ps-3 py-2 mt-2">
                                                <strong><?= htmlspecialchars($b['customer_name']) ?></strong>
                                                <small class="text-muted d-block">
                                                    <?= htmlspecialchars($b['appointment_time']) ?> • <?= htmlspecialchars($b['service_name']) ?>
                                                </small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

<!-- Notification Dropdown -->
<div class="notification-dropdown" id="notificationDropdown">
    <div class="notification-header">
        <h5 class="m-0"><i class="bi bi-bell-fill"></i> Notifications (<?= $totalNotifications ?>)</h5>
    </div>
    <div class="notification-content">
            <?php if ($totalNotifications == 0): ?>
            <div class="text-center py-5">
                <i class="bi bi-check-circle-fill" style="font-size: 3rem; color: var(--success);"></i>
                <p class="text-muted mt-3 mb-0">All caught up! No new notifications</p>
            </div>
        <?php else: ?>
            <?php if ($newBookingsCount > 0): ?>
                <?php foreach ($newBookingsDetails as $b): ?>
                    <div class="notification-item">
                        <strong><i class="bi bi-calendar-check me-2"></i>New Booking</strong>
                        <div class="mt-2">
                            <?= htmlspecialchars($b['customer_name']) ?> - <?= htmlspecialchars($b['service_name']) ?>
                            <?php if (!empty($b['appointment_date'])): ?>
                                <small class="d-block text-muted mt-1">
                                    <i class="bi bi-clock me-1"></i><?= date('M d, Y', strtotime($b['appointment_date'])) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($emptyStockCount > 0): ?>
                <?php foreach ($emptyStockDetails as $i): ?>
                    <div class="notification-item empty-stock">
                        <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Out of Stock</strong>
                        <div class="mt-2">
                            <?= htmlspecialchars($i['service_name']) ?>
                            <?php if (!empty($i['active_ingredient'])): ?>
                                <span class="text-muted">- <?= htmlspecialchars($i['active_ingredient']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($lowStockCount > 0): ?>
                <?php foreach ($lowStockDetails as $i): ?>
                    <div class="notification-item low-stock">
                        <strong><i class="bi bi-exclamation-circle-fill me-2"></i>Low Stock</strong>
                        <div class="mt-2">
                            <?= htmlspecialchars($i['service_name']) ?>
                            <span class="text-muted">- Only <?= $i['stocks'] ?> remaining</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($expiringCount > 0): ?>
                <?php foreach ($expiringDetails as $i): ?>
                    <div class="notification-item expiring">
                        <strong><i class="bi bi-clock-fill me-2"></i>Expiring Soon</strong>
                        <div class="mt-2">
                            <?= htmlspecialchars($i['service_name']) ?>
                            <small class="d-block text-muted mt-1">
                                Expires: <?= date('M d, Y', strtotime($i['expiry_date'])) ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const bell = document.getElementById('notificationBell');
    const isOpen = dropdown.classList.contains('show');

    if (!isOpen) {
        dropdown.classList.add('show');
        bell.classList.add('ringing');
        setTimeout(() => bell.classList.remove('ringing'), 500);

        // Mark notifications as viewed
        const formData = new FormData();
        formData.append('mark_notifications_viewed', '1');
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(response => response.json())
          .then(data => {
              if (data.success) {
                  const badge = document.querySelector('.notification-badge');
                  if (badge) badge.style.display = 'none';
              }
          }).catch(err => console.error('Error:', err));
    } else {
        dropdown.classList.remove('show');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', e => {
    const dropdown = document.getElementById('notificationDropdown');
    const bell = document.getElementById('notificationBell');
    if (!bell.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.remove('show');
    }
});

// Mobile menu toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const sidebar = document.getElementById('sidebar');

    if (mobileMenuBtn && sidebar) {
        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }
});
</script>
</body>
</html>
