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

// Build SQL query with filters
$sql = "SELECT i.active_ingredient, s.service_name, i.stocks, i.inventory_id, s.service_id
        FROM inventory i
        LEFT JOIN services s ON i.service_id = s.service_id
        WHERE 1";

$params = [];
$types = '';

if ($search_service) {
    $sql .= " AND s.service_id = ?";
    $types .= 'i';
    $params[] = $search_service;
}

if ($search_ingredient) {
    $sql .= " AND i.active_ingredient LIKE ?";
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

// Get bookings with completed services in the last 3 months
$usage_sql = "
    SELECT 
        sb.service_id,
        s.service_name,
        i.active_ingredient,
        COUNT(*) as usage_count,
        MAX(sb.appointment_date) as last_used_date,
        MIN(sb.appointment_date) as first_used_date
    FROM service_bookings sb
    LEFT JOIN services s ON sb.service_id = s.service_id
    LEFT JOIN inventory i ON s.service_id = i.service_id
    WHERE sb.status = 'Completed' 
    AND sb.appointment_date >= ?
    AND i.active_ingredient IS NOT NULL
    AND i.active_ingredient != ''
    GROUP BY sb.service_id, s.service_name, i.active_ingredient
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
    
    // Find other ingredients for the same service
    $alt_sql = "
        SELECT DISTINCT i.active_ingredient, i.stocks, s.service_name
        FROM inventory i
        LEFT JOIN services s ON i.service_id = s.service_id
        WHERE s.service_id = ?
        AND i.active_ingredient != ?
        AND i.active_ingredient IS NOT NULL
        AND i.active_ingredient != ''
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
            SELECT DISTINCT i.active_ingredient, i.stocks, s.service_name
            FROM inventory i
            LEFT JOIN services s ON i.service_id = s.service_id
            WHERE i.active_ingredient != ?
            AND i.active_ingredient IS NOT NULL
            AND i.active_ingredient != ''
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Active Ingredient Rotation Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; background-color: #f0f2f5; color: #333; }
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
    </style>
</head>
<body>
    <header>
        <h1>Active Ingredient Rotation Dashboard</h1>
    </header>

    <div class="container">
        <a href="dashboard.php" class="btn">Back to Dashboard</a>

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

