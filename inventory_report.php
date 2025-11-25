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

    // Get report type and filters
    $report_type = $_GET['type'] ?? 'full';
    $export_format = $_GET['export'] ?? '';
    $filter_status = $_GET['status'] ?? 'all';
    $filter_service = $_GET['service'] ?? 'all';
    $filter_ingredient = $_GET['ingredient'] ?? 'all';
    $selected_month = $_GET['month'] ?? date('Y-m');

    /* ==================== INVENTORY DATA ==================== */
    $inventory = $pdo->query("
        SELECT i.inventory_id, s.service_name, a.name AS active_ingredient, i.stocks, i.expiry_date, i.barcode,
            a.ai_id, s.service_id, i.created_at, i.updated_at
        FROM inventory i
        JOIN services s ON i.service_id = s.service_id
        LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        ORDER BY a.name, i.expiry_date
    ")->fetchAll();

    // Get unique services and chemicals for filters
    $services = $pdo->query("SELECT DISTINCT s.service_id, s.service_name FROM services s JOIN inventory i ON s.service_id = i.service_id ORDER BY s.service_name")->fetchAll();
    $ingredients = $pdo->query("SELECT DISTINCT a.ai_id, a.name FROM active_ingredients a JOIN inventory i ON a.ai_id = i.ai_id WHERE a.name IS NOT NULL ORDER BY a.name")->fetchAll();

    // Apply filters
    $filtered_inventory = $inventory;
    if ($filter_service !== 'all') {
        $filtered_inventory = array_filter($filtered_inventory, fn($i) => $i['service_id'] == $filter_service);
    }
    if ($filter_ingredient !== 'all') {
        $filtered_inventory = array_filter($filtered_inventory, fn($i) => $i['ai_id'] == $filter_ingredient);
    }
    if ($filter_status !== 'all') {
        $filtered_inventory = array_filter($filtered_inventory, function($i) use ($filter_status) {
            $stock = (float)$i['stocks'];
            $is_expired = !empty($i['expiry_date']) && strtotime($i['expiry_date']) < time();
            if ($filter_status === 'empty') return $stock <= 0;
            if ($filter_status === 'low') return $stock > 0 && $stock <= 20;
            if ($filter_status === 'expired') return $is_expired;
            if ($filter_status === 'near_exp') return !empty($i['expiry_date']) && strtotime($i['expiry_date']) < strtotime('+3 months') && !$is_expired;
            return true;
        });
    }
    $filtered_inventory = array_values($filtered_inventory);

    // Calculate statistics
    $total_items = count($inventory);
    $total_stock = array_sum(array_map(fn($i) => (float)$i['stocks'], $inventory));
    $low_stock_count = count(array_filter($inventory, fn($i) => (float)$i['stocks'] > 0 && (float)$i['stocks'] <= 20));
    $empty_count = count(array_filter($inventory, fn($i) => (float)$i['stocks'] <= 0));
    $expired_count = count(array_filter($inventory, fn($i) => !empty($i['expiry_date']) && strtotime($i['expiry_date']) < time()));
    $near_exp_count = count(array_filter($inventory, fn($i) => !empty($i['expiry_date']) && strtotime($i['expiry_date']) < strtotime('+3 months') && strtotime($i['expiry_date']) >= time()));

    /* ==================== ENHANCED ANALYTICS ==================== */
    // Calculate advanced statistics
    $avg_stock = $total_items > 0 ? $total_stock / $total_items : 0;
    $ok_items = $total_items - $empty_count - $low_stock_count - $expired_count;
    $health_score = $total_items > 0 ? round(($ok_items / $total_items) * 100) : 0;

    // Service-wise analysis
    $service_breakdown = [];
    foreach ($inventory as $item) {
        $service_name = $item['service_name'];
        if (!isset($service_breakdown[$service_name])) {
            $service_breakdown[$service_name] = ['count' => 0, 'total_stock' => 0, 'low' => 0, 'empty' => 0];
        }
        $stock = (float)$item['stocks'];
        $service_breakdown[$service_name]['count']++;
        $service_breakdown[$service_name]['total_stock'] += $stock;
        if ($stock <= 0) $service_breakdown[$service_name]['empty']++;
        elseif ($stock <= 20) $service_breakdown[$service_name]['low']++;
    }

    // Chemical-wise analysis
    $ingredient_breakdown = [];
    foreach ($inventory as $item) {
        $ingredient = $item['active_ingredient'] ?? 'Unknown';
        if (!isset($ingredient_breakdown[$ingredient])) {
            $ingredient_breakdown[$ingredient] = ['count' => 0, 'total_stock' => 0, 'low' => 0, 'empty' => 0, 'expired' => 0];
        }
        $stock = (float)$item['stocks'];
        $is_expired = !empty($item['expiry_date']) && strtotime($item['expiry_date']) < time();
        $ingredient_breakdown[$ingredient]['count']++;
        $ingredient_breakdown[$ingredient]['total_stock'] += $stock;
        if ($stock <= 0) $ingredient_breakdown[$ingredient]['empty']++;
        elseif ($stock <= 20) $ingredient_breakdown[$ingredient]['low']++;
        if ($is_expired) $ingredient_breakdown[$ingredient]['expired']++;
    }

    // Expiry timeline
    $expiry_timeline = ['expired' => 0, '0-3_months' => 0, '3-6_months' => 0, '6-12_months' => 0, '12+_months' => 0, 'no_expiry' => 0];
    foreach ($inventory as $item) {
        if (empty($item['expiry_date'])) {
            $expiry_timeline['no_expiry']++;
            continue;
        }
        $expiry_ts = strtotime($item['expiry_date']);
        $now = time();
        $months_until = ($expiry_ts - $now) / (30 * 24 * 60 * 60);
        if ($expiry_ts < $now) $expiry_timeline['expired']++;
        elseif ($months_until <= 3) $expiry_timeline['0-3_months']++;
        elseif ($months_until <= 6) $expiry_timeline['3-6_months']++;
        elseif ($months_until <= 12) $expiry_timeline['6-12_months']++;
        else $expiry_timeline['12+_months']++;
    }

    // Status distribution for charts
    $status_distribution = [
        'In Stock' => $ok_items,
        'Low Stock' => $low_stock_count,
        'Empty' => $empty_count,
        'Expired' => $expired_count
    ];

    // Top and bottom items
    $sorted_by_stock = $inventory;
    usort($sorted_by_stock, fn($a, $b) => (float)$b['stocks'] <=> (float)$a['stocks']);
    $top_stocked = array_slice($sorted_by_stock, 0, 5);
    $least_stocked = array_filter($inventory, fn($i) => (float)$i['stocks'] > 0 && (float)$i['stocks'] < 10);
    usort($least_stocked, fn($a, $b) => (float)$a['stocks'] <=> (float)$b['stocks']);
    $bottom_stocked = array_slice($least_stocked, 0, 5);

    /* ==================== MONTHLY REPORT DATA ==================== */
    $monthly_data = [];
    $monthly_changes = [];
    $monthly_summary = [];

    if ($report_type === 'monthly') {
        // Get audit logs for inventory updates in the selected month
        $month_start = $selected_month . '-01';
        $month_end = date('Y-m-t', strtotime($month_start));

        $monthly_logs = $pdo->prepare("
            SELECT * FROM audit_logs
            WHERE table_name = 'inventory'
            AND action = 'Update Record'
            AND DATE(created_at) >= ?
            AND DATE(created_at) <= ?
            ORDER BY created_at DESC
        ");
        $monthly_logs->execute([$month_start, $month_end]);
        $monthly_changes = $monthly_logs->fetchAll();

        // Get current month inventory snapshot
        $monthly_data = $inventory;

        // Calculate monthly statistics
        $monthly_summary = [
            'month' => date('F Y', strtotime($month_start)),
            'total_items' => count($monthly_data),
            'total_stock' => array_sum(array_map(fn($i) => (float)$i['stocks'], $monthly_data)),
            'updates_count' => count($monthly_changes),
            'low_stock' => count(array_filter($monthly_data, fn($i) => (float)$i['stocks'] > 0 && (float)$i['stocks'] <= 20)),
            'empty_items' => count(array_filter($monthly_data, fn($i) => (float)$i['stocks'] <= 0)),
            'expired_items' => count(array_filter($monthly_data, fn($i) => !empty($i['expiry_date']) && strtotime($i['expiry_date']) < time())),
            'items_added' => 0,
            'items_updated' => count($monthly_changes)
        ];

        // Count items added this month
        $items_added = $pdo->prepare("
            SELECT COUNT(*) FROM audit_logs
            WHERE table_name = 'inventory'
            AND action = 'Create Record'
            AND DATE(created_at) >= ?
            AND DATE(created_at) <= ?
        ");
        $items_added->execute([$month_start, $month_end]);
        $monthly_summary['items_added'] = $items_added->fetchColumn();

        // Filter inventory for selected month if needed
        if ($filter_status !== 'all' || $filter_service !== 'all' || $filter_ingredient !== 'all') {
            $filtered_inventory = $monthly_data;
            if ($filter_service !== 'all') {
                $filtered_inventory = array_filter($filtered_inventory, fn($i) => $i['service_id'] == $filter_service);
            }
            if ($filter_ingredient !== 'all') {
                $filtered_inventory = array_filter($filtered_inventory, fn($i) => $i['ai_id'] == $filter_ingredient);
            }
            if ($filter_status !== 'all') {
                $filtered_inventory = array_filter($filtered_inventory, function($i) use ($filter_status) {
                    $stock = (float)$i['stocks'];
                    $is_expired = !empty($i['expiry_date']) && strtotime($i['expiry_date']) < time();
                    if ($filter_status === 'empty') return $stock <= 0;
                    if ($filter_status === 'low') return $stock > 0 && $stock <= 20;
                    if ($filter_status === 'expired') return $is_expired;
                    if ($filter_status === 'near_exp') return !empty($i['expiry_date']) && strtotime($i['expiry_date']) < strtotime('+3 months') && !$is_expired;
                    return true;
                });
            }
            $filtered_inventory = array_values($filtered_inventory);
        } else {
            $filtered_inventory = $monthly_data;
        }
    }

    /* ==================== EXPORT FUNCTIONALITY ==================== */
    if ($export_format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        $filename = $report_type === 'monthly'
            ? 'inventory_monthly_report_' . $selected_month . '.csv'
            : 'inventory_report_' . date('Y-m-d') . '.csv';
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Service', 'Chemical', 'Stock (Bottles)', 'Expiry Date', 'Barcode', 'Date Added', 'Last Updated', 'Status']);

        foreach ($filtered_inventory as $item) {
            $stock = (float)$item['stocks'];
            $is_expired = !empty($item['expiry_date']) && strtotime($item['expiry_date']) < time();
            $near_exp = !empty($item['expiry_date']) && strtotime($item['expiry_date']) < strtotime('+3 months') && !$is_expired;

            $status = 'In Stock';
            if ($stock <= 0) {
                $status = 'EMPTY';
            } elseif ($is_expired) {
                $status = 'EXPIRED';
            } else {
                $status_parts = [];
                if ($stock <= 20) {
                    $status_parts[] = 'LOW';
                } else {
                    $status_parts[] = 'In Stock';
                }
                if ($near_exp) {
                    $status_parts[] = 'NEAR EXPIRY';
                }
                $status = implode(' + ', $status_parts);
            }

            fputcsv($output, [
                $item['service_name'],
                $item['active_ingredient'] ?? '—',
                number_format($stock, 0),
                $item['expiry_date'] ?? '—',
                $item['barcode'] ?? '—',
                !empty($item['created_at']) ? date('Y-m-d H:i:s', strtotime($item['created_at'])) : '—',
                !empty($item['updated_at']) ? date('Y-m-d H:i:s', strtotime($item['updated_at'])) : '—',
                $status
            ]);
        }
        fclose($output);
        exit;
    }

    if ($export_format === 'pdf') {
        // For PDF, we'll generate HTML that can be printed to PDF
        // In production, you might want to use a library like TCPDF or FPDF
        header('Content-Type: text/html; charset=utf-8');
        // This will be handled in the HTML section with print functionality
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Report • TECHNO PEST</title>
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap');
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
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
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
            .top-bar {
                background: white;
                padding: 1.25rem 1.5rem;
                border-radius: 16px;
                box-shadow: 0 6px 25px rgba(16, 185, 129, 0.15), 0 2px 10px rgba(5, 150, 105, 0.1);
                margin-bottom: 1.5rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 1rem;
                border: 2px solid var(--green-100);
                position: relative;
                overflow: hidden;
            }
            .top-bar::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, var(--green-400), var(--green-500), var(--green-400));
                background-size: 200% 100%;
                animation: gradient-flow 3s ease infinite;
            }
            @keyframes gradient-flow {
                0%, 100% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
            }
            .page-title {
                font-size: 1.75rem;
                font-weight: 700;
                background: linear-gradient(135deg, var(--green-600), var(--green-700));
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
                margin: 0;
            }
            .content-card {
                background: white;
                border-radius: 20px;
                padding: 1.5rem;
                box-shadow: 0 8px 30px rgba(16, 185, 129, 0.15), 0 4px 15px rgba(5, 150, 105, 0.1);
                margin-bottom: 2rem;
                width: 100%;
                max-width: 100%;
                overflow: hidden;
                border: 2px solid var(--green-100);
                position: relative;
                transition: all 0.3s ease;
            }
            .content-card:hover {
                box-shadow: 0 12px 40px rgba(16, 185, 129, 0.2), 0 6px 20px rgba(5, 150, 105, 0.15);
                border-color: var(--green-300);
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
            .stat-card.info {
                background: linear-gradient(135deg, #0ea5e9, #0284c7);
            }
            .stat-card.primary {
                background: linear-gradient(135deg, var(--green-500), var(--green-600));
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
            .filter-card {
                background: white;
                border-radius: 20px;
                padding: 1.5rem;
                box-shadow: 0 8px 30px rgba(16, 185, 129, 0.15), 0 4px 15px rgba(5, 150, 105, 0.1);
                margin-bottom: 2rem;
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
            .table-modern tbody td {
                padding: 0.875rem 0.75rem;
                vertical-align: middle;
                font-size: 0.875rem;
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
            }
            .report-type-btn {
                transition: all .3s;
            }
            .report-type-btn.active {
                transform: scale(1.05);
                box-shadow: 0 8px 20px rgba(0,0,0,.3);
            }
            /* PDF Generation Styles */
            @media print {
                body { background: white; color: black; }
                .back-btn, .filter-card, .no-print { display: none !important; }
                .glass-card { box-shadow: none; border: 1px solid #ddd; }
            }
            #pdfContent {
                background: white;
            }
            #pdfContent .header-section {
                background: linear-gradient(120deg, #198754, #2ecc71) !important;
            }
            canvas {
                max-width: 100%;
            }
            .alert {
                border-left: 4px solid;
                border-radius: 0.5rem;
            }
            .alert-danger {
                border-left-color: #dc3545;
            }
            .alert-warning {
                border-left-color: #ffc107;
            }
            .alert-info {
                border-left-color: #0dcaf0;
            }
            .table-responsive {
                border-radius: 0.5rem;
            }
            .sticky-top {
                background: #f8f9fa !important;
            }
        </style>
    </head>
    <body>
        <div class="dashboard-wrapper">
            <!-- SIDEBAR -->
            <aside class="sidebar">
                <div class="sidebar-header">
                    <img src="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" alt="Logo">
                    <h3>TECHNO PEST</h3>
                    <p>Inventory Reports</p>
                </div>
                <nav class="nav-menu">
                  <div class="user-section">
                      <a href="inventory.php" class="btn btn-modern btn-primary-modern w-100 mb-2" style="background: linear-gradient(135deg, var(--green-500), var(--green-600)); color: white; border: none; padding: 0.75rem 2rem; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);">
                          <i class="bi bi-arrow-left"></i> Back
                      </a>
                  </div>
                    <div class="nav-item">
                        <a href="inventory.php" class="nav-link">
                            <i class="bi bi-box-seam"></i>
                            <span>Inventory</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="inventory_report.php" class="nav-link active">
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
                        <i class="bi bi-file-earmark-bar-graph me-2"></i>Inventory Report
                    </h1>
                    <div class="text-muted small">
                        Generated: <?= date('F d, Y h:i A') ?>
                    </div>
                </div>

                <div id="pdfContent">
                    <!-- Statistics -->
                    <div class="content-card">
                        <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                            <i class="bi bi-bar-chart me-2"></i>Statistics Overview
                        </h2>
                        <div class="stats-grid">
                        <?php if ($report_type === 'monthly' && !empty($monthly_summary)): ?>
                            <div class="stat-card success">
                                <h3><?= number_format($monthly_summary['total_items']) ?></h3>
                                <p class="mb-0">Total Items</p>
                            </div>
                            <div class="stat-card primary">
                                <h3><?= number_format($monthly_summary['total_stock'], 0) ?></h3>
                                <p class="mb-0">Total Stock (Bottles)</p>
                            </div>
                            <div class="stat-card info">
                                <h3><?= $monthly_summary['items_added'] ?></h3>
                                <p class="mb-0">Items Added</p>
                            </div>
                            <div class="stat-card warning">
                                <h3><?= $monthly_summary['items_updated'] ?></h3>
                                <p class="mb-0">Items Updated</p>
                            </div>
                            <div class="stat-card warning">
                                <h3><?= $monthly_summary['low_stock'] ?></h3>
                                <p class="mb-0">Low Stock Items</p>
                            </div>
                            <div class="stat-card danger">
                                <h3><?= $monthly_summary['empty_items'] ?></h3>
                                <p class="mb-0">Empty Items</p>
                            </div>
                        <?php else: ?>
                            <div class="stat-card success">
                                <h3><?= number_format($total_items) ?></h3>
                                <p class="mb-0">Total Items</p>
                            </div>
                            <div class="stat-card primary">
                                <h3><?= number_format($total_stock, 0) ?></h3>
                                <p class="mb-0">Total Stock (Bottles)</p>
                            </div>
                            <div class="stat-card warning">
                                <h3><?= $low_stock_count ?></h3>
                                <p class="mb-0">Low Stock Items</p>
                            </div>
                            <div class="stat-card danger">
                                <h3><?= $empty_count ?></h3>
                                <p class="mb-0">Empty Items</p>
                            </div>
                            <div class="stat-card danger">
                                <h3><?= $expired_count ?></h3>
                                <p class="mb-0">Expired Items</p>
                            </div>
                            <div class="stat-card info">
                                <h3><?= $near_exp_count ?></h3>
                                <p class="mb-0">Near Expiry</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Actionable Insights -->
                <?php if ($report_type === 'full' || $report_type === 'monthly'): ?>
                <div class="content-card">
                    <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                        <i class="bi bi-lightbulb me-2"></i>Actionable Insights
                    </h2>
                    <div class="row g-3">
                        <?php if ($empty_count > 0): ?>
                        <div class="col-md-6">
                            <div class="alert alert-danger mb-0">
                                <i class="bi bi-x-octagon"></i> <strong><?= $empty_count ?> items are empty</strong> - Consider removing or restocking these items immediately.
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($expired_count > 0): ?>
                        <div class="col-md-6">
                            <div class="alert alert-danger mb-0">
                                <i class="bi bi-calendar-x"></i> <strong><?= $expired_count ?> items have expired</strong> - Remove expired items from inventory to maintain safety standards.
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($low_stock_count > 0): ?>
                        <div class="col-md-6">
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle"></i> <strong><?= $low_stock_count ?> items are low on stock</strong> - Plan restocking to avoid shortages.
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($near_exp_count > 0): ?>
                        <div class="col-md-6">
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-calendar-check"></i> <strong><?= $near_exp_count ?> items are expiring within 3 months</strong> - Use these items first to minimize waste.
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($health_score < 70): ?>
                        <div class="col-12">
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-heart-pulse"></i> <strong>Inventory health is <?= $health_score ?>%</strong> - Focus on restocking low items and removing expired stock to improve overall inventory health.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Enhanced Analytics Section -->
                <?php if ($report_type === 'full' || $report_type === 'monthly'): ?>
                <div class="content-card">
                    <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                        <i class="bi bi-graph-up me-2"></i>Key Metrics
                    </h2>
                        <div class="row g-4 mb-4">
                            <!-- Key Metrics -->
                            <div class="col-md-3">
                                <div class="filter-card text-center">
                                <h6 class="text-muted mb-2">Average Stock</h6>
                                <h3 class="fw-bold text-primary mb-0"><?= number_format($avg_stock, 0) ?></h3>
                                <small class="text-muted">bottles/item</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="filter-card text-center">
                                <h6 class="text-muted mb-2">Health Score</h6>
                                <h3 class="fw-bold mb-0" style="color: <?= $health_score >= 70 ? '#198754' : ($health_score >= 40 ? '#ffc107' : '#dc3545') ?>">
                                    <?= $health_score ?>%
                                </h3>
                                <small class="text-muted">inventory health</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="filter-card text-center">
                                <h6 class="text-muted mb-2">Items in Good Condition</h6>
                                <h3 class="fw-bold text-success mb-0"><?= $ok_items ?></h3>
                                <small class="text-muted">out of <?= $total_items ?></small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="filter-card text-center">
                                <h6 class="text-muted mb-2">Items Needing Attention</h6>
                                <h3 class="fw-bold text-warning mb-0"><?= $low_stock_count + $empty_count + $expired_count ?></h3>
                                <small class="text-muted">require action</small>
                            </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Section -->
                    <div class="content-card">
                        <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                            <i class="bi bi-pie-chart me-2"></i>Charts & Analysis
                        </h2>
                        <div class="row g-4 mb-4">
                            <div class="col-md-6">
                                <div class="filter-card">
                                <h5 class="fw-bold text-success mb-3">
                                    <i class="bi bi-pie-chart"></i> Stock Status Distribution
                                </h5>
                                <canvas id="statusChart" style="max-height: 300px;"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="filter-card">
                                <h5 class="fw-bold text-warning mb-3">
                                    <i class="bi bi-bar-chart"></i> Expiry Timeline
                                </h5>
                                <canvas id="expiryChart" style="max-height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Breakdown Tables -->
                    <div class="content-card">
                        <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                            <i class="bi bi-list-check me-2"></i>Breakdown Analysis
                        </h2>
                        <div class="row g-4 mb-4">
                            <!-- Service Breakdown -->
                            <div class="col-md-6">
                                <div class="filter-card">
                                <h5 class="fw-bold text-success mb-3">
                                    <i class="bi bi-list-check"></i> Service Breakdown
                                </h5>
                                <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th>Service</th>
                                                <th class="text-center">Items</th>
                                                <th class="text-center">Stock</th>
                                                <th class="text-center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            uasort($service_breakdown, fn($a, $b) => $b['total_stock'] <=> $a['total_stock']);
                                            foreach (array_slice($service_breakdown, 0, 10, true) as $service => $data):
                                            ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($service) ?></td>
                                                <td class="text-center"><?= $data['count'] ?></td>
                                                <td class="text-center fw-bold"><?= number_format($data['total_stock'], 0) ?></td>
                                                <td class="text-center">
                                                    <?php if ($data['empty'] > 0): ?>
                                                        <span class="badge bg-danger"><?= $data['empty'] ?> Empty</span>
                                                    <?php elseif ($data['low'] > 0): ?>
                                                        <span class="badge bg-warning text-dark"><?= $data['low'] ?> Low</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">In Stock</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Top & Bottom Stocked -->
                        <div class="col-md-6">
                            <div class="filter-card">
                                <h5 class="fw-bold text-primary mb-3">
                                    <i class="bi bi-trophy"></i> Top Stocked Items
                                </h5>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Item</th>
                                                <th class="text-center">Stock</th>
                                                <th class="text-center">Service</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top_stocked as $item): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($item['active_ingredient'] ?: $item['service_name']) ?></td>
                                                <td class="text-center fw-bold text-success"><?= number_format((float)$item['stocks'], 0) ?></td>
                                                <td class="text-center small"><?= htmlspecialchars($item['service_name']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (!empty($bottom_stocked)): ?>
                                <div class="border-top pt-3">
                                    <h6 class="fw-bold text-warning mb-2">
                                        <i class="bi bi-exclamation-triangle"></i> Needs Restocking
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Item</th>
                                                    <th class="text-center">Stock</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($bottom_stocked as $item): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= htmlspecialchars($item['active_ingredient'] ?: $item['service_name']) ?></td>
                                                    <td class="text-center fw-bold text-warning"><?= number_format((float)$item['stocks'], 0) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            </div>
                    </div>
                </div>
                    <?php endif; ?>

                    <!-- Report Type Selection -->
                    <div class="content-card">
                        <h5 class="mb-3 fw-bold text-success">
                            <i class="bi bi-funnel"></i> Report Type
                        </h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="?type=full&status=<?= $filter_status ?>&service=<?= $filter_service ?>&ingredient=<?= $filter_ingredient ?>"
                            class="btn report-type-btn <?= $report_type === 'full' ? 'btn-success active' : 'btn-outline-success' ?>">
                                <i class="bi bi-list-ul"></i> Full Inventory
                            </a>
                            <a href="?type=low&status=low&service=<?= $filter_service ?>&ingredient=<?= $filter_ingredient ?>"
                            class="btn report-type-btn <?= $report_type === 'low' ? 'btn-warning active' : 'btn-outline-warning' ?>">
                                <i class="bi bi-exclamation-triangle"></i> Low Stock
                            </a>
                            <a href="?type=empty&status=empty&service=<?= $filter_service ?>&ingredient=<?= $filter_ingredient ?>"
                            class="btn report-type-btn <?= $report_type === 'empty' ? 'btn-danger active' : 'btn-outline-danger' ?>">
                                <i class="bi bi-x-octagon"></i> Empty Items
                            </a>
                            <a href="?type=expired&status=expired&service=<?= $filter_service ?>&ingredient=<?= $filter_ingredient ?>"
                            class="btn report-type-btn <?= $report_type === 'expired' ? 'btn-danger active' : 'btn-outline-danger' ?>">
                                <i class="bi bi-calendar-x"></i> Expired
                            </a>
                            <a href="?type=near_exp&status=near_exp&service=<?= $filter_service ?>&ingredient=<?= $filter_ingredient ?>"
                            class="btn report-type-btn <?= $report_type === 'near_exp' ? 'btn-info active' : 'btn-outline-info' ?>">
                                <i class="bi bi-calendar-check"></i> Near Expiry
                            </a>
                            <a href="?type=monthly&month=<?= $selected_month ?>&status=<?= $filter_status ?>&service=<?= $filter_service ?>&ingredient=<?= $filter_ingredient ?>"
                            class="btn report-type-btn <?= $report_type === 'monthly' ? 'btn-primary active' : 'btn-outline-primary' ?>">
                                <i class="bi bi-calendar-month"></i> Monthly Report
                            </a>
                        </div>
                    </div>

                    <!-- Month Selector for Monthly Report -->
                    <?php if ($report_type === 'monthly'): ?>
                    <div class="filter-card">
                        <h5 class="mb-3 fw-bold text-success">
                            <i class="bi bi-calendar3"></i> Select Month
                        </h5>
                        <form method="get" class="row g-3">
                            <input type="hidden" name="type" value="monthly">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                            <input type="hidden" name="service" value="<?= htmlspecialchars($filter_service) ?>">
                            <input type="hidden" name="ingredient" value="<?= htmlspecialchars($filter_ingredient) ?>">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Month & Year</label>
                                <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($selected_month) ?>" max="<?= date('Y-m') ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold">&nbsp;</label>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-search"></i> Load
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Filters -->
                    <div class="filter-card">
                        <h5 class="mb-3 fw-bold text-success">
                            <i class="bi bi-sliders"></i> Filters
                        </h5>
                        <form method="get" class="row g-3">
                            <input type="hidden" name="type" value="<?= htmlspecialchars($report_type) ?>">
                            <?php if ($report_type === 'monthly'): ?>
                                <input type="hidden" name="month" value="<?= htmlspecialchars($selected_month) ?>">
                            <?php endif; ?>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="ok" <?= $filter_status === 'ok' ? 'selected' : '' ?>>In Stock</option>
                                    <option value="low" <?= $filter_status === 'low' ? 'selected' : '' ?>>Low Stock</option>
                                    <option value="empty" <?= $filter_status === 'empty' ? 'selected' : '' ?>>Empty</option>
                                    <option value="expired" <?= $filter_status === 'expired' ? 'selected' : '' ?>>Expired</option>
                                    <option value="near_exp" <?= $filter_status === 'near_exp' ? 'selected' : '' ?>>Near Expiry</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Service</label>
                                <select name="service" class="form-select">
                                    <option value="all" <?= $filter_service === 'all' ? 'selected' : '' ?>>All Services</option>
                                    <?php foreach ($services as $s): ?>
                                        <option value="<?= $s['service_id'] ?>" <?= $filter_service == $s['service_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['service_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Chemical</label>
                                <select name="ingredient" class="form-select">
                                    <option value="all" <?= $filter_ingredient === 'all' ? 'selected' : '' ?>>All Chemicals</option>
                                    <?php foreach ($ingredients as $ing): ?>
                                        <option value="<?= $ing['ai_id'] ?>" <?= $filter_ingredient == $ing['ai_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ing['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="bi bi-funnel-fill"></i> Apply
                                    </button>
                                    <a href="inventory_report.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle"></i>
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Export Options -->
                    <div class="filter-card no-print">
                        <h5 class="mb-3 fw-bold text-success">
                            <i class="bi bi-download"></i> Export Report
                        </h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="?type=<?= $report_type ?>&status=<?= $filter_status ?>&service=<?= $filter_service ?>&ingredient=<?= $filter_ingredient ?><?= $report_type === 'monthly' ? '&month=' . urlencode($selected_month) : '' ?>&export=csv"
                            class="btn btn-success">
                                <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                            </a>
                            <button id="downloadPdfBtn" class="btn btn-primary">
                                <i class="bi bi-file-pdf"></i> Download PDF
                            </button>
                        </div>
                    </div>

                    <!-- Monthly Changes Log -->
                    <?php if ($report_type === 'monthly' && !empty($monthly_changes)): ?>
                    <div class="filter-card mb-4">
                        <h5 class="mb-3 fw-bold text-success">
                            <i class="bi bi-clock-history"></i> Monthly Changes (<?= count($monthly_changes) ?> updates)
                        </h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>User</th>
                                        <th>Item ID</th>
                                        <th>Changes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($monthly_changes, 0, 10) as $change):
                                        $oldValues = !empty($change['old_values']) ? json_decode($change['old_values'], true) : [];
                                        $newValues = !empty($change['new_values']) ? json_decode($change['new_values'], true) : [];
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y h:i A', strtotime($change['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($change['username'] ?? 'System') ?></td>
                                        <td>#<?= htmlspecialchars($change['record_id']) ?></td>
                                        <td>
                                            <small>
                                                <?php
                                                $changes = [];
                                                if (!empty($oldValues) && !empty($newValues)) {
                                                    foreach ($newValues as $key => $newVal) {
                                                        $oldVal = $oldValues[$key] ?? '';
                                                        if ($oldVal != $newVal) {
                                                            $changes[] = "<strong>$key</strong>: " . htmlspecialchars($oldVal) . " → " . htmlspecialchars($newVal);
                                                        }
                                                    }
                                                }
                                                echo !empty($changes) ? implode('<br>', $changes) : 'No changes detected';
                                                ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($monthly_changes) > 10): ?>
                                <p class="text-muted small mt-2">Showing 10 of <?= count($monthly_changes) ?> changes. View full log in <a href="audit_log.php?section=inventory">Audit Log</a>.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Report Table -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="text-success mb-0">
                            <?php
                            $titles = [
                                'full' => 'Full Inventory Report',
                                'low' => 'Low Stock Report',
                                'empty' => 'Empty Items Report',
                                'expired' => 'Expired Items Report',
                                'near_exp' => 'Near Expiry Report',
                                'monthly' => 'Monthly Inventory Report - ' . (!empty($monthly_summary['month']) ? $monthly_summary['month'] : date('F Y'))
                            ];
                            echo $titles[$report_type] ?? 'Inventory Report';
                            ?>
                            <span class="badge bg-secondary ms-2"><?= count($filtered_inventory) ?> items</span>
                        </h3>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Service</th>
                                    <th>Chemical</th>
                                    <th class="text-center">Stock (Bottles)</th>
                                    <th class="text-center">Expiry Date</th>
                                    <th>Barcode</th>
                                    <th class="text-center">Date Added</th>
                                    <th class="text-center">Last Updated</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($filtered_inventory)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5 text-muted">
                                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                            No items found matching the selected filters.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($filtered_inventory as $idx => $item):
                                        $stock = (float)$item['stocks'];
                                        $is_expired = !empty($item['expiry_date']) && strtotime($item['expiry_date']) < time();
                                        $near_exp = !empty($item['expiry_date']) && strtotime($item['expiry_date']) < strtotime('+3 months') && !$is_expired;
                                        $exp_display = !empty($item['expiry_date']) ? date('Y-m-d', strtotime($item['expiry_date'])) : '';
                                        $ingredient = ucwords(strtolower($item['active_ingredient'] ?? ''));
                                    ?>
                                    <tr class="<?= $is_expired || $stock<=0 ? 'table-danger' : ($stock<=20 ? 'table-warning' : '') ?>">
                                        <td class="fw-semibold"><?= $idx + 1 ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($item['service_name']) ?></td>
                                        <td><?= htmlspecialchars($ingredient ?: '—') ?></td>
                                        <td class="text-center fw-bold"><?= number_format($stock, 0) ?></td>
                                        <td class="text-center"><?= $exp_display ?: '—' ?></td>
                                        <td><code><?= htmlspecialchars($item['barcode'] ?? '—') ?></code></td>
                                        <td class="text-center small">
                                            <?php if (!empty($item['created_at'])): ?>
                                                <div class="text-muted">
                                                    <?= date('M d, Y', strtotime($item['created_at'])) ?><br>
                                                    <small><?= date('h:i A', strtotime($item['created_at'])) ?></small>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center small">
                                            <?php if (!empty($item['updated_at'])): ?>
                                                <div class="text-muted">
                                                    <?= date('M d, Y', strtotime($item['updated_at'])) ?><br>
                                                    <small><?= date('h:i A', strtotime($item['updated_at'])) ?></small>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                <?php if($is_expired || $stock<=0): ?>
                                                    <span class="badge bg-danger"><?= $stock<=0 ? 'EMPTY' : 'EXPIRED' ?></span>
                                                <?php else: ?>
                                                    <?php if($stock<=20): ?>
                                                        <span class="badge bg-warning text-dark">LOW</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">In Stock</span>
                                                    <?php endif; ?>
                                                    <?php if($near_exp): ?>
                                                        <span class="badge bg-info">NEAR EXP</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($filtered_inventory)): ?>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="3" class="fw-bold text-end">Total Stock:</td>
                                    <td class="text-center fw-bold text-success">
                                        <?= number_format(array_sum(array_map(fn($i) => (float)$i['stocks'], $filtered_inventory)), 0) ?> bottles
                                    </td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!-- Summary Section -->
                    <?php if (!empty($filtered_inventory)): ?>
                    <div class="mt-4 p-4 bg-light rounded-3">
                        <h5 class="fw-bold text-success mb-3">
                            <i class="bi bi-info-circle"></i> Report Summary
                        </h5>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-2">
                                    <strong>Report Type:</strong>
                                    <?= $titles[$report_type] ?? 'Inventory Report' ?>
                                </p>
                                <p class="mb-2">
                                    <strong>Total Items:</strong>
                                    <?= count($filtered_inventory) ?>
                                </p>
                                <p class="mb-2">
                                    <strong>Total Stock Value:</strong>
                                    <?= number_format(array_sum(array_map(fn($i) => (float)$i['stocks'], $filtered_inventory)), 0) ?> bottles
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-2">
                                    <strong>Generated:</strong>
                                    <?= date('F d, Y h:i A') ?>
                                </p>
                                <p class="mb-2">
                                    <strong>Filters Applied:</strong>
                                    <?php
                                    $filters = [];
                                    if ($filter_status !== 'all') $filters[] = "Status: " . ucfirst($filter_status);
                                    if ($filter_service !== 'all') {
                                        $service_name = array_values(array_filter($services, fn($s) => $s['service_id'] == $filter_service))[0]['service_name'] ?? 'Unknown';
                                        $filters[] = "Service: " . $service_name;
                                    }
                                    if ($filter_ingredient !== 'all') {
                                        $ing_name = array_values(array_filter($ingredients, fn($ing) => $ing['ai_id'] == $filter_ingredient))[0]['name'] ?? 'Unknown';
                                        $filters[] = "Chemical: " . $ing_name;
                                    }
                                    echo !empty($filters) ? implode(', ', $filters) : 'None';
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>


        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const downloadBtn = document.getElementById('downloadPdfBtn');
                if (downloadBtn) {
                    downloadBtn.addEventListener('click', downloadPDF);
                }

                // Initialize Charts
                <?php if ($report_type === 'full' || $report_type === 'monthly'): ?>
                initCharts();
                <?php endif; ?>
            });

            function initCharts() {
                // Status Distribution Pie Chart
                const statusCtx = document.getElementById('statusChart');
                if (statusCtx) {
                    const statusData = <?= json_encode($status_distribution) ?>;
                    new Chart(statusCtx, {
                        type: 'pie',
                        data: {
                            labels: Object.keys(statusData),
                            datasets: [{
                                data: Object.values(statusData),
                                backgroundColor: ['#198754', '#ffc107', '#dc3545', '#6c757d'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: { position: 'bottom', labels: { padding: 15, font: { size: 12 } } },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                                            return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Expiry Timeline Bar Chart
                const expiryCtx = document.getElementById('expiryChart');
                if (expiryCtx) {
                    const expiryData = <?= json_encode($expiry_timeline) ?>;
                    const labels = ['Expired', '0-3 Months', '3-6 Months', '6-12 Months', '12+ Months', 'No Expiry'];
                    const colors = ['#dc3545', '#ffc107', '#fd7e14', '#20c997', '#198754', '#6c757d'];

                    new Chart(expiryCtx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Items',
                                data: Object.values(expiryData),
                                backgroundColor: colors,
                                borderColor: colors,
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: { display: false },
                                tooltip: { callbacks: { label: (ctx) => ctx.parsed.y + ' items' } }
                            },
                            scales: {
                                y: { beginAtZero: true, ticks: { stepSize: 1 } }
                            }
                        }
                    });
                }
            }

            async function downloadPDF() {
                const btn = document.getElementById('downloadPdfBtn');
                if (!btn) return;

                // Check if required libraries are loaded
                if (typeof html2canvas === 'undefined') {
                    alert('Error: html2canvas library not loaded. Please refresh the page and try again.');
                    return;
                }
                if (typeof window.jspdf === 'undefined') {
                    alert('Error: jsPDF library not loaded. Please refresh the page and try again.');
                    return;
                }

                const originalText = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating PDF...';
                btn.disabled = true;

                // Store original displays
                const noPrintElements = document.querySelectorAll('.no-print, .back-btn');
                noPrintElements.forEach((el) => {
                    el.setAttribute('data-original-display', el.style.display || '');
                    el.style.display = 'none';
                });

                try {

                    // Get the content to convert
                    const element = document.getElementById('pdfContent');
                    if (!element) {
                        throw new Error('Content not found');
                    }

                    // Wait a bit for elements to hide
                    await new Promise(resolve => setTimeout(resolve, 100));

                    // Use html2canvas to capture the element
                    const canvas = await html2canvas(element, {
                        scale: 1.5,
                        useCORS: true,
                        logging: false,
                        backgroundColor: '#ffffff',
                        allowTaint: false,
                        removeContainer: false,
                        imageTimeout: 15000,
                        onclone: function(clonedDoc) {
                            // Ensure background is white in cloned document
                            const clonedBody = clonedDoc.body;
                            clonedBody.style.background = '#ffffff';
                            clonedBody.style.color = '#000000';
                        }
                    });

                    // Calculate PDF dimensions
                    const imgWidth = 210; // A4 width in mm
                    const pageHeight = 297; // A4 height in mm
                    const imgHeight = (canvas.height * imgWidth) / canvas.width;

                    // Initialize PDF
                    const { jsPDF } = window.jspdf;
                    const pdf = new jsPDF('p', 'mm', 'a4');

                    // Handle multi-page content
                    if (imgHeight <= pageHeight) {
                        // Content fits on one page
                        pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, 0, imgWidth, imgHeight);
                    } else {
                        // Content needs multiple pages
                        let heightLeft = imgHeight;
                        let position = 0;
                        const pageWidth = imgWidth;

                        // Add first page
                        pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, position, pageWidth, imgHeight);
                        heightLeft -= pageHeight;

                        // Add additional pages
                        while (heightLeft >= 0) {
                            position = position - pageHeight;
                            pdf.addPage();
                            pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, position, pageWidth, imgHeight);
                            heightLeft -= pageHeight;
                        }
                    }

                    // Save the PDF
                    const pdfFilename = <?= $report_type === 'monthly' ? "'inventory_monthly_report_" . $selected_month . ".pdf'" : "'inventory_report_" . date('Y-m-d') . ".pdf'" ?>;
                    pdf.save(pdfFilename);

                    // Restore everything
                    restoreElements();
                    btn.innerHTML = originalText;
                    btn.disabled = false;

                } catch (error) {
                    console.error('PDF generation error:', error);
                    restoreElements();
                    alert('Error generating PDF: ' + (error.message || 'Unknown error. Please try again or use the Print option.'));
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }

                function restoreElements() {
                    noPrintElements.forEach((el) => {
                        const originalDisplay = el.getAttribute('data-original-display');
                        el.style.display = originalDisplay || '';
                        el.removeAttribute('data-original-display');
                    });
                }
            }

        </script>
    </body>
    </html>
