<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'pest control');
if ($conn->connect_error) die("DB failed: " . $conn->connect_error);

$search_service = isset($_GET['service']) ? trim($_GET['service']) : '';
$search_ingredient = isset($_GET['ingredient']) ? trim($_GET['ingredient']) : '';

// Fetch services for dropdown filter
$services_result = $conn->query("SELECT service_id, service_name FROM services ORDER BY service_name");
$services_list = [];
while ($s = $services_result->fetch_assoc()) {
    $services_list[] = $s;
}

// Build SQL query with filters - Connected to active_ingredients table
$sql = "SELECT a.name AS active_ingredient, s.service_name, i.stocks, i.inventory_id, s.service_id, i.ai_id
        FROM inventory i
        LEFT JOIN services s ON i.service_id = s.service_id
        LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        WHERE 1";

$params = [];
$types = '';

if ($search_service) {
    $sql .= " AND s.service_id = ?";
    $types .= 'i';
    $params[] = $search_service;
}

if ($search_ingredient) {
    $sql .= " AND a.name LIKE ?";
    $types .= 's';
    $params[] = "%$search_ingredient%";
}

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$current_labels = [];
$current_stocks = [];
$ingredients = [];

while ($row = $result->fetch_assoc()) {
    $ingredient = $row['active_ingredient'] ?: '(No ingredient)';
    $service = $row['service_name'] ?: '(No service)';

    $current_labels[] = $ingredient . " (" . $service . ")";
    $current_stocks[] = $row['stocks'] ? (int)$row['stocks'] : 0;
    $ingredients[] = [
        'id' => $row['inventory_id'],
        'name' => $ingredient,
        'service' => $service,
        'service_id' => $row['service_id'],
        'stocks' => $row['stocks'] ? (int)$row['stocks'] : 0
    ];
}
$stmt->close();

// Product Rotation Recommendation: Check usage history
$rotation_recommendations = [];
$three_months_ago = date('Y-m-d', strtotime('-3 months'));

// Get bookings with completed services in the last 3 months - Connected to active_ingredients table
$usage_sql = "
    SELECT
        sb.service_id,
        s.service_name,
        a.name AS active_ingredient,
        COUNT(*) as usage_count,
        MAX(sb.appointment_date) as last_used_date,
        MIN(sb.appointment_date) as first_used_date
    FROM service_bookings sb
    LEFT JOIN services s ON sb.service_id = s.service_id
    LEFT JOIN inventory i ON s.service_id = i.service_id
    LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
    WHERE sb.status = 'Completed'
    AND sb.appointment_date >= ?
    AND a.name IS NOT NULL
    AND a.name != ''
    GROUP BY sb.service_id, s.service_name, a.name
    HAVING COUNT(*) >= 3
    ORDER BY usage_count DESC
";

$usage_stmt = $conn->prepare($usage_sql);
$usage_stmt->bind_param('s', $three_months_ago);
$usage_stmt->execute();
$usage_result = $usage_stmt->get_result();

$products_in_use = [];
while ($usage_row = $usage_result->fetch_assoc()) {
    $days_in_use = (strtotime('now') - strtotime($usage_row['first_used_date'])) / (60 * 60 * 24);

    if ($days_in_use >= 90) { // 3 months = 90 days
        $product_key = $usage_row['active_ingredient'] . '_' . $usage_row['service_id'];
        $products_in_use[$product_key] = [
            'ingredient' => $usage_row['active_ingredient'],
            'service' => $usage_row['service_name'],
            'service_id' => $usage_row['service_id'],
            'usage_count' => $usage_row['usage_count'],
            'days_in_use' => (int)$days_in_use,
            'first_used' => $usage_row['first_used_date'],
            'last_used' => $usage_row['last_used_date']
        ];
    }
}
$usage_stmt->close();

// Find alternative products for rotation
foreach ($products_in_use as $product) {
    $alternatives = [];

    // Find other ingredients for the same service - Connected to active_ingredients table
    $alt_sql = "
        SELECT DISTINCT a.name AS active_ingredient, i.stocks, s.service_name
        FROM inventory i
        LEFT JOIN services s ON i.service_id = s.service_id
        LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        WHERE s.service_id = ?
        AND a.name != ?
        AND a.name IS NOT NULL
        AND a.name != ''
        AND i.stocks > 0
        ORDER BY i.stocks DESC
        LIMIT 3
    ";

    $alt_stmt = $conn->prepare($alt_sql);
    $alt_stmt->bind_param('is', $product['service_id'], $product['ingredient']);
    $alt_stmt->execute();
    $alt_result = $alt_stmt->get_result();

    while ($alt_row = $alt_result->fetch_assoc()) {
        $alternatives[] = [
            'ingredient' => $alt_row['active_ingredient'],
            'stocks' => (int)$alt_row['stocks'],
            'service' => $alt_row['service_name']
        ];
    }
    $alt_stmt->close();

    // If no alternatives in same service, find alternatives in other services
    if (empty($alternatives)) {
        $alt_sql2 = "
            SELECT DISTINCT a.name AS active_ingredient, i.stocks, s.service_name
            FROM inventory i
            LEFT JOIN services s ON i.service_id = s.service_id
            LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
            WHERE a.name != ?
            AND a.name IS NOT NULL
            AND a.name != ''
            AND i.stocks > 0
            ORDER BY i.stocks DESC
            LIMIT 3
        ";

        $alt_stmt2 = $conn->prepare($alt_sql2);
        $alt_stmt2->bind_param('s', $product['ingredient']);
        $alt_stmt2->execute();
        $alt_result2 = $alt_stmt2->get_result();

        while ($alt_row2 = $alt_result2->fetch_assoc()) {
            $alternatives[] = [
                'ingredient' => $alt_row2['active_ingredient'],
                'stocks' => (int)$alt_row2['stocks'],
                'service' => $alt_row2['service_name']
            ];
        }
        $alt_stmt2->close();
    }

    if (!empty($alternatives)) {
        $rotation_recommendations[] = [
            'current_product' => $product['ingredient'],
            'current_service' => $product['service'],
            'days_in_use' => $product['days_in_use'],
            'usage_count' => $product['usage_count'],
            'first_used' => $product['first_used'],
            'last_used' => $product['last_used'],
            'alternatives' => $alternatives
        ];
    }
}

// Rotation schedule: dynamic 3-month periods
$rotation_periods = [];
$period_count = 5;
$month_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

$start_month = (int)date('n');
$start_year = (int)date('Y');

for ($i = 0; $i < $period_count; $i++) {
    $month1 = ($start_month + $i*3 - 1) % 12;
    $year1 = $start_year + floor(($start_month + $i*3 - 1)/12);
    $month2 = ($month1 + 2) % 12;
    $year2 = $year1 + floor(($month1 + 2)/12);

    $rotation_periods[] = $month_names[$month1] . " " . $year1 . " - " . $month_names[$month2] . " " . $year2;
}

// Prepare rotation stocks and ingredients
$rotation_stocks = [];
$rotation_ingredients = [];
$ingredient_count = count($ingredients);

for ($i = 0; $i < count($rotation_periods); $i++) {
    if ($ingredient_count === 0) {
        $rotation_stocks[] = 0;
        $rotation_ingredients[] = ['(No ingredient available)'];
        continue;
    }
    $index = $i % $ingredient_count;
    $rotation_stocks[] = $ingredients[$index]['stocks'];
    $rotation_ingredients[] = [$ingredients[$index]['name'] . " (" . $ingredients[$index]['service'] . ")"];
}

// ========== SERVICE ALERTS SECTION ==========
// Get today's date
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$next_week = date('Y-m-d', strtotime('+7 days'));

// Get overdue services (appointments that have passed but status is not 'Completed' or 'Cancelled')
$overdue_sql = "
    SELECT 
        sb.booking_id,
        sb.customer_name,
        sb.address,
        sb.phone_number,
        sb.appointment_date,
        sb.appointment_time,
        sb.status,
        s.service_name,
        DATEDIFF(?, sb.appointment_date) as days_overdue
    FROM service_bookings sb
    LEFT JOIN services s ON sb.service_id = s.service_id
    WHERE sb.appointment_date < ?
    AND sb.status NOT IN ('Completed', 'Cancelled')
    ORDER BY sb.appointment_date ASC, sb.appointment_time ASC
";

$overdue_stmt = $conn->prepare($overdue_sql);
$overdue_stmt->bind_param('ss', $today, $today);
$overdue_stmt->execute();
$overdue_result = $overdue_stmt->get_result();

$overdue_services = [];
while ($row = $overdue_result->fetch_assoc()) {
    $overdue_services[] = $row;
}
$overdue_stmt->close();

// Get upcoming services (tomorrow)
$tomorrow_sql = "
    SELECT 
        sb.booking_id,
        sb.customer_name,
        sb.address,
        sb.phone_number,
        sb.appointment_date,
        sb.appointment_time,
        sb.status,
        s.service_name
    FROM service_bookings sb
    LEFT JOIN services s ON sb.service_id = s.service_id
    WHERE sb.appointment_date = ?
    AND sb.status NOT IN ('Cancelled')
    ORDER BY sb.appointment_time ASC
";

$tomorrow_stmt = $conn->prepare($tomorrow_sql);
$tomorrow_stmt->bind_param('s', $tomorrow);
$tomorrow_stmt->execute();
$tomorrow_result = $tomorrow_stmt->get_result();

$tomorrow_services = [];
while ($row = $tomorrow_result->fetch_assoc()) {
    $tomorrow_services[] = $row;
}
$tomorrow_stmt->close();

// Get upcoming services (next 7 days)
$upcoming_sql = "
    SELECT 
        sb.booking_id,
        sb.customer_name,
        sb.address,
        sb.phone_number,
        sb.appointment_date,
        sb.appointment_time,
        sb.status,
        s.service_name,
        DATEDIFF(sb.appointment_date, ?) as days_until
    FROM service_bookings sb
    LEFT JOIN services s ON sb.service_id = s.service_id
    WHERE sb.appointment_date > ?
    AND sb.appointment_date <= ?
    AND sb.status NOT IN ('Cancelled')
    ORDER BY sb.appointment_date ASC, sb.appointment_time ASC
    LIMIT 20
";

$upcoming_stmt = $conn->prepare($upcoming_sql);
$upcoming_stmt->bind_param('sss', $today, $today, $next_week);
$upcoming_stmt->execute();
$upcoming_result = $upcoming_stmt->get_result();

$upcoming_services = [];
while ($row = $upcoming_result->fetch_assoc()) {
    $upcoming_services[] = $row;
}
$upcoming_stmt->close();

// Calculate statistics
$total_overdue = count($overdue_services);
$total_tomorrow = count($tomorrow_services);
$total_upcoming = count($upcoming_services);

// Group overdue by days
$overdue_by_days = [
    'critical' => [], // 14+ days
    'high' => [],     // 7-13 days
    'medium' => []    // 1-6 days
];

foreach ($overdue_services as $service) {
    $days = (int)$service['days_overdue'];
    if ($days >= 14) {
        $overdue_by_days['critical'][] = $service;
    } elseif ($days >= 7) {
        $overdue_by_days['high'][] = $service;
    } else {
        $overdue_by_days['medium'][] = $service;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Analytics & Service Alerts Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; color: #333; }
        header { background-color: #2196F3; color: #fff; padding: 20px; text-align: center; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        header h1 { margin: 0; font-size: 28px; }
        .container { display: flex; flex-direction: column; gap: 20px; padding: 20px; max-width: 1400px; margin: 0 auto; }
        .card { background-color: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
        .card h2 { margin-top: 0; font-size: 22px; margin-bottom: 20px; color: #2196F3; }
        .btn { display: inline-block; padding: 10px 20px; margin-bottom: 20px; font-size: 16px; color: #fff; background-color: #2196F3; border-radius: 6px; text-decoration: none; transition: 0.3s; }
        .btn:hover { background-color: #1976D2; }
        canvas { max-width: 100%; }
        form { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
        select, input[type=text], button { padding: 8px; border-radius: 6px; border: 1px solid #ccc; font-size: 16px; }
        button { background-color: #2196F3; color: #fff; border: none; cursor: pointer; }
        button:hover { background-color: #1976D2; }
        .recommendation-card { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%); color: white; padding: 20px; border-radius: 12px; margin-bottom: 15px; }
        .recommendation-card.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .recommendation-card.info { background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%); }
        .recommendation-header { font-size: 18px; font-weight: bold; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        .recommendation-body { margin-bottom: 15px; }
        .recommendation-details { background: rgba(255,255,255,0.2); padding: 10px; border-radius: 6px; margin: 10px 0; }
        .recommendation-details strong { display: block; margin-bottom: 5px; }
        .alternatives-list { margin-top: 15px; }
        .alternatives-list h4 { margin-bottom: 10px; }
        .alternative-item { background: rgba(255,255,255,0.2); padding: 12px; border-radius: 6px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
        .alternative-item .stock-badge { background: rgba(255,255,255,0.3); padding: 4px 12px; border-radius: 12px; font-weight: bold; }
        .no-recommendations { text-align: center; padding: 40px; color: #666; }
        .no-recommendations i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .stat-card.critical { border-left: 5px solid #ef4444; }
        .stat-card.warning { border-left: 5px solid #f59e0b; }
        .stat-card.info { border-left: 5px solid #2196F3; }
        .stat-card.success { border-left: 5px solid #10b981; }
        .stat-value { font-size: 36px; font-weight: bold; color: #333; margin-bottom: 5px; }
        .stat-label { color: #666; font-size: 14px; }
        .alert-section { background: white; padding: 25px; border-radius: 15px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .alert-section h2 { color: #333; font-size: 22px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-card { padding: 20px; border-radius: 12px; margin-bottom: 15px; border-left: 5px solid; }
        .alert-card.critical { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-color: #ef4444; }
        .alert-card.high { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-color: #f59e0b; }
        .alert-card.medium { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-color: #3b82f6; }
        .alert-card.info { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-color: #10b981; }
        .alert-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
        .alert-title { font-size: 18px; font-weight: bold; color: #1f2937; margin-bottom: 5px; }
        .alert-subtitle { color: #6b7280; font-size: 14px; }
        .alert-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .badge-critical { background: #ef4444; color: white; }
        .badge-high { background: #f59e0b; color: white; }
        .badge-medium { background: #3b82f6; color: white; }
        .badge-info { background: #10b981; color: white; }
        .alert-details { margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(0,0,0,0.1); }
        .detail-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; }
        .detail-label { color: #6b7280; }
        .detail-value { color: #1f2937; font-weight: 500; }
        .empty-state { text-align: center; padding: 40px; color: #9ca3af; }
        .empty-state-icon { font-size: 64px; margin-bottom: 15px; opacity: 0.5; }
        .service-list { display: grid; gap: 12px; }
        .service-item { background: rgba(255,255,255,0.7); padding: 15px; border-radius: 8px; border-left: 4px solid #2196F3; }
        .service-item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .service-name { font-weight: bold; color: #1f2937; }
        .service-time { color: #6b7280; font-size: 14px; }
        .service-details { font-size: 14px; color: #6b7280; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .alert-header { flex-direction: column; gap: 10px; }
        }
    </style>
</head>
<body>
    <header>
        <h1>📊 Analytics & Service Alerts Dashboard</h1>
    </header>

    <div class="container">
        <a href="dashboard.php" class="btn">← Back to Dashboard</a>

        <!-- Service Alerts Statistics -->
        <div class="stats-grid">
            <div class="stat-card critical">
                <div class="stat-value"><?= $total_overdue ?></div>
                <div class="stat-label">Overdue Services</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value"><?= count($overdue_by_days['critical']) ?></div>
                <div class="stat-label">Critical (14+ days)</div>
            </div>
            <div class="stat-card info">
                <div class="stat-value"><?= $total_tomorrow ?></div>
                <div class="stat-label">Scheduled for Tomorrow</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value"><?= $total_upcoming ?></div>
                <div class="stat-label">Upcoming (Next 7 Days)</div>
            </div>
        </div>

        <!-- Overdue Services -->
        <div class="alert-section">
            <h2>
                <span>⚠️</span>
                <span>Overdue Service Alerts</span>
                <?php if ($total_overdue > 0): ?>
                    <span style="background: #ef4444; color: white; padding: 4px 12px; border-radius: 12px; font-size: 14px; margin-left: 10px;">
                        <?= $total_overdue ?> <?= $total_overdue == 1 ? 'Service' : 'Services' ?>
                    </span>
                <?php endif; ?>
            </h2>

            <?php if ($total_overdue > 0): ?>
                <!-- Critical Overdue (14+ days) -->
                <?php if (!empty($overdue_by_days['critical'])): ?>
                    <?php foreach ($overdue_by_days['critical'] as $service): ?>
                        <div class="alert-card critical">
                            <div class="alert-header">
                                <div>
                                    <div class="alert-title">Client: <?= htmlspecialchars($service['customer_name']) ?></div>
                                    <div class="alert-subtitle">Service overdue by <?= $service['days_overdue'] ?> days</div>
                                </div>
                                <span class="alert-badge badge-critical">CRITICAL</span>
                            </div>
                            <div class="alert-details">
                                <div class="detail-row">
                                    <span class="detail-label">Service:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['service_name'] ?: 'N/A') ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Scheduled Date:</span>
                                    <span class="detail-value"><?= date('M d, Y', strtotime($service['appointment_date'])) ?> at <?= htmlspecialchars($service['appointment_time']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Address:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['address']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['phone_number']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Status:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['status']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- High Priority Overdue (7-13 days) -->
                <?php if (!empty($overdue_by_days['high'])): ?>
                    <?php foreach ($overdue_by_days['high'] as $service): ?>
                        <div class="alert-card high">
                            <div class="alert-header">
                                <div>
                                    <div class="alert-title">Client: <?= htmlspecialchars($service['customer_name']) ?></div>
                                    <div class="alert-subtitle">Service overdue by <?= $service['days_overdue'] ?> days</div>
                                </div>
                                <span class="alert-badge badge-high">HIGH PRIORITY</span>
                            </div>
                            <div class="alert-details">
                                <div class="detail-row">
                                    <span class="detail-label">Service:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['service_name'] ?: 'N/A') ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Scheduled Date:</span>
                                    <span class="detail-value"><?= date('M d, Y', strtotime($service['appointment_date'])) ?> at <?= htmlspecialchars($service['appointment_time']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Address:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['address']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['phone_number']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Medium Priority Overdue (1-6 days) -->
                <?php if (!empty($overdue_by_days['medium'])): ?>
                    <?php foreach ($overdue_by_days['medium'] as $service): ?>
                        <div class="alert-card medium">
                            <div class="alert-header">
                                <div>
                                    <div class="alert-title">Client: <?= htmlspecialchars($service['customer_name']) ?></div>
                                    <div class="alert-subtitle">Service overdue by <?= $service['days_overdue'] ?> days</div>
                                </div>
                                <span class="alert-badge badge-medium">OVERDUE</span>
                            </div>
                            <div class="alert-details">
                                <div class="detail-row">
                                    <span class="detail-label">Service:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['service_name'] ?: 'N/A') ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Scheduled Date:</span>
                                    <span class="detail-value"><?= date('M d, Y', strtotime($service['appointment_date'])) ?> at <?= htmlspecialchars($service['appointment_time']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Address:</span>
                                    <span class="detail-value"><?= htmlspecialchars($service['address']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">✅</div>
                    <h3>No Overdue Services</h3>
                    <p>All services are up to date!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tomorrow's Services -->
        <div class="alert-section">
            <h2>
                <span>📅</span>
                <span>Upcoming Service: Tomorrow</span>
                <?php if ($total_tomorrow > 0): ?>
                    <span style="background: #2196F3; color: white; padding: 4px 12px; border-radius: 12px; font-size: 14px; margin-left: 10px;">
                        <?= $total_tomorrow ?> <?= $total_tomorrow == 1 ? 'Appointment' : 'Appointments' ?> Scheduled
                    </span>
                <?php endif; ?>
            </h2>

            <?php if ($total_tomorrow > 0): ?>
                <div class="service-list">
                    <?php foreach ($tomorrow_services as $service): ?>
                        <div class="service-item">
                            <div class="service-item-header">
                                <div>
                                    <div class="service-name"><?= htmlspecialchars($service['customer_name']) ?></div>
                                    <div class="service-time"><?= htmlspecialchars($service['appointment_time']) ?></div>
                                </div>
                                <span class="alert-badge badge-info">TOMORROW</span>
                            </div>
                            <div class="service-details">
                                <div><strong>Service:</strong> <?= htmlspecialchars($service['service_name'] ?: 'N/A') ?></div>
                                <div><strong>Address:</strong> <?= htmlspecialchars($service['address']) ?></div>
                                <div><strong>Phone:</strong> <?= htmlspecialchars($service['phone_number']) ?></div>
                                <div><strong>Status:</strong> <?= htmlspecialchars($service['status']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📅</div>
                    <h3>No Appointments Tomorrow</h3>
                    <p>No services scheduled for tomorrow.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upcoming Services (Next 7 Days) -->
        <div class="alert-section">
            <h2>
                <span>📋</span>
                <span>Upcoming Services (Next 7 Days)</span>
                <?php if ($total_upcoming > 0): ?>
                    <span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 12px; font-size: 14px; margin-left: 10px;">
                        <?= $total_upcoming ?> <?= $total_upcoming == 1 ? 'Appointment' : 'Appointments' ?>
                    </span>
                <?php endif; ?>
            </h2>

            <?php if ($total_upcoming > 0): ?>
                <div class="service-list">
                    <?php foreach ($upcoming_services as $service): ?>
                        <div class="service-item">
                            <div class="service-item-header">
                                <div>
                                    <div class="service-name"><?= htmlspecialchars($service['customer_name']) ?></div>
                                    <div class="service-time">
                                        <?= date('M d, Y', strtotime($service['appointment_date'])) ?> at <?= htmlspecialchars($service['appointment_time']) ?>
                                        <?php if ($service['days_until'] == 1): ?>
                                            <span style="color: #2196F3; font-weight: bold;">(Tomorrow)</span>
                                        <?php elseif ($service['days_until'] <= 3): ?>
                                            <span style="color: #f59e0b; font-weight: bold;">(<?= $service['days_until'] ?> days)</span>
                                        <?php else: ?>
                                            <span style="color: #6b7280;">(<?= $service['days_until'] ?> days)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="alert-badge badge-info">UPCOMING</span>
                            </div>
                            <div class="service-details">
                                <div><strong>Service:</strong> <?= htmlspecialchars($service['service_name'] ?: 'N/A') ?></div>
                                <div><strong>Address:</strong> <?= htmlspecialchars($service['address']) ?></div>
                                <div><strong>Phone:</strong> <?= htmlspecialchars($service['phone_number']) ?></div>
                                <div><strong>Status:</strong> <?= htmlspecialchars($service['status']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <h3>No Upcoming Services</h3>
                    <p>No services scheduled for the next 7 days.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter Form -->
        <div class="card">
            <h2>Filter Ingredients</h2>
            <form method="GET">
                <select name="service">
                    <option value="">All Services</option>
                    <?php foreach ($services_list as $s): ?>
                        <option value="<?= $s['service_id'] ?>" <?= $search_service == $s['service_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['service_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="ingredient" placeholder="Search Ingredient" value="<?= htmlspecialchars($search_ingredient) ?>">
                <button type="submit">Filter</button>
            </form>
        </div>

        <!-- Product Rotation Recommendations -->
        <div class="card">
            <h2>Product Rotation Recommendations</h2>
            <?php if (!empty($rotation_recommendations)): ?>
                <?php foreach ($rotation_recommendations as $rec): ?>
                    <div class="recommendation-card <?= $rec['days_in_use'] >= 120 ? '' : 'warning' ?>">
                        <div class="recommendation-header">
                            <span>⚠️</span>
                            <span>Rotation Required</span>
                        </div>
                        <div class="recommendation-body">
                            <p><strong><?= htmlspecialchars($rec['current_product']) ?></strong> has been used continuously for <strong><?= $rec['days_in_use'] ?> days</strong> (<?= round($rec['days_in_use'] / 30, 1) ?> months) in <strong><?= htmlspecialchars($rec['current_service']) ?></strong>.</p>
                            <div class="recommendation-details">
                                <strong>Usage Statistics:</strong>
                                <div>• Total uses: <?= $rec['usage_count'] ?> times</div>
                                <div>• First used: <?= date('M d, Y', strtotime($rec['first_used'])) ?></div>
                                <div>• Last used: <?= date('M d, Y', strtotime($rec['last_used'])) ?></div>
                            </div>
                            <div class="alternatives-list">
                                <h4>💡 Recommended Alternatives:</h4>
                                <?php foreach ($rec['alternatives'] as $alt): ?>
                                    <div class="alternative-item">
                                        <div>
                                            <strong><?= htmlspecialchars($alt['ingredient']) ?></strong>
                                            <div style="font-size: 14px; opacity: 0.9;">Service: <?= htmlspecialchars($alt['service']) ?></div>
                                        </div>
                                        <span class="stock-badge">Stock: <?= $alt['stocks'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p style="margin-top: 15px; font-size: 14px; opacity: 0.9;">
                                <strong>Recommendation:</strong> Consider rotating to one of the alternatives above to prevent pest resistance development.
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-recommendations">
                    <div style="font-size: 48px; margin-bottom: 15px;">✅</div>
                    <h3>No Rotation Needed</h3>
                    <p>All products are within the recommended rotation period (less than 3 months of continuous use).</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Current Ingredients with Stocks</h2>
            <canvas id="currentChart"></canvas>
        </div>

        <div class="card">
            <h2>Active Ingredient Rotation (Every 3 Months)</h2>
            <canvas id="rotationChart"></canvas>
        </div>
    </div>

    <script>
        const currentData = {
            labels: <?= json_encode($current_labels) ?>,
            datasets: [{
                label: 'Stocks',
                data: <?= json_encode($current_stocks) ?>,
                backgroundColor: 'rgba(33, 150, 243, 0.6)',
                borderColor: 'rgba(33, 150, 243, 1)',
                borderWidth: 1
            }]
        };
        new Chart(document.getElementById('currentChart'), {
            type: 'bar',
            data: currentData,
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });

        const rotationData = {
            labels: <?= json_encode($rotation_periods) ?>,
            datasets: [{
                label: 'Stock Levels',
                data: <?= json_encode($rotation_stocks) ?>,
                backgroundColor: 'rgba(76, 175, 80, 0.6)',
                borderColor: 'rgba(76, 175, 80, 1)',
                borderWidth: 1,
                ingredients: <?= json_encode($rotation_ingredients) ?>
            }]
        };
        new Chart(document.getElementById('rotationChart'), {
            type: 'bar',
            data: rotationData,
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) label += ': ';
                                label += context.parsed.y;
                                return label;
                            },
                            afterLabel: function(context) {
                                const index = context.dataIndex;
                                const ingredients = context.dataset.ingredients[index];
                                return ingredients.length > 0 ? '\nSuggested Ingredient:\n' + ingredients[0] : '\nNo ingredient';
                            }
                        }
                    }
                },
                scales: { y: { beginAtZero: true } }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>

