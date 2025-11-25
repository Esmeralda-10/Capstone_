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

// Seasonal/Monthly Service Recommendations (Philippine Seasons)
$current_month = (int)date('n'); // 1-12 (January = 1, December = 12)
$seasonal_recommendations = [
    1 => [ // January - Cool Dry Season (Tag-lamig)
        'title' => 'Cool Dry Season Pest Control',
        'description' => 'Cool weather drives pests indoors seeking warmth and shelter.',
        'services' => ['Rodent Control', 'Cockroach Control', 'Ant Control'],
        'icon' => '🍃',
        'reason' => 'Cool dry season causes rodents and cockroaches to seek indoor shelter.'
    ],
    2 => [ // February - Cool Dry Season (Tag-lamig)
        'title' => 'Cool Dry Season Pest Control',
        'description' => 'Continue indoor pest management as pests remain active indoors.',
        'services' => ['Rodent Control', 'Cockroach Control', 'Termite Control'],
        'icon' => '🍃',
        'reason' => 'Cool weather continues to drive pests indoors seeking warmth.'
    ],
    3 => [ // March - Hot Dry Season begins (Tag-init)
        'title' => 'Hot Dry Season Pest Control',
        'description' => 'Hot and dry weather begins. Mosquitoes and flies become more active.',
        'services' => ['Mosquito Control', 'Flies Control', 'Termite Control'],
        'icon' => '☀️',
        'reason' => 'Hot dry weather increases mosquito and fly breeding activity.'
    ],
    4 => [ // April - Hot Dry Season (Tag-init) - Peak heat
        'title' => 'Peak Hot Dry Season Pest Control',
        'description' => 'Peak hot season. Mosquitoes, flies, and ants are most active.',
        'services' => ['Mosquito Control', 'Flies Control', 'Ant Control'],
        'icon' => '🔥',
        'reason' => 'Extreme heat and dry conditions maximize mosquito, fly, and ant activity.'
    ],
    5 => [ // May - Hot Dry Season (Tag-init) - End of hot season
        'title' => 'Hot Dry Season Pest Control',
        'description' => 'Still hot and dry. Continue mosquito and fly control.',
        'services' => ['Mosquito Control', 'Flies Control', 'Cockroach Control'],
        'icon' => '☀️',
        'reason' => 'Hot dry conditions continue to favor mosquito and fly breeding.'
    ],
    6 => [ // June - Wet/Rainy Season begins (Tag-ulan)
        'title' => 'Wet Season Pest Control',
        'description' => 'Rainy season starts. Mosquitoes breed in standing water, rodents seek shelter.',
        'services' => ['Mosquito Control', 'Rodent Control', 'Cockroach Control'],
        'icon' => '🌧️',
        'reason' => 'Rain creates breeding grounds for mosquitoes and drives rodents indoors.'
    ],
    7 => [ // July - Wet/Rainy Season (Tag-ulan) - Peak monsoon
        'title' => 'Wet Season Pest Control',
        'description' => 'Peak rainy season. Intensive mosquito, rodent, and cockroach control needed.',
        'services' => ['Mosquito Control', 'Rodent Control', 'Cockroach Control'],
        'icon' => '🌧️',
        'reason' => 'Heavy rains flood pest habitats and create mosquito breeding sites.'
    ],
    8 => [ // August - Wet/Rainy Season (Tag-ulan)
        'title' => 'Wet Season Pest Control',
        'description' => 'Continue intensive pest control during rainy season.',
        'services' => ['Mosquito Control', 'Rodent Control', 'Cockroach Control'],
        'icon' => '🌧️',
        'reason' => 'Persistent rains continue to drive pests indoors and breed mosquitoes.'
    ],
    9 => [ // September - Wet/Rainy Season (Tag-ulan)
        'title' => 'Wet Season Pest Control',
        'description' => 'Rainy season continues. Focus on mosquitoes and indoor pests.',
        'services' => ['Mosquito Control', 'Rodent Control', 'Cockroach Control'],
        'icon' => '🌧️',
        'reason' => 'Rainy conditions maintain high mosquito populations and indoor pest activity.'
    ],
    10 => [ // October - Wet/Rainy Season (Tag-ulan) - Late monsoon
        'title' => 'Wet Season Pest Control',
        'description' => 'Late rainy season. Rodents and cockroaches are driven indoors by floods.',
        'services' => ['Rodent Control', 'Cockroach Control', 'Mosquito Control'],
        'icon' => '🌧️',
        'reason' => 'Continuing rains force rodents and cockroaches to seek indoor shelter.'
    ],
    11 => [ // November - Wet/Rainy Season ends (Tag-ulan) - Transition to cool dry
        'title' => 'Late Wet Season Pest Control',
        'description' => 'End of rainy season. Prepare for cool dry season with pest prevention.',
        'services' => ['Rodent Control', 'Cockroach Control', 'Termite Control'],
        'icon' => '🌧️',
        'reason' => 'Rains subside but pests remain active indoors, preparing for cooler weather.'
    ],
    12 => [ // December - Cool Dry Season begins (Tag-lamig)
        'title' => 'Cool Dry Season Pest Control',
        'description' => 'Cool dry season begins. Rodents and cockroaches seek indoor shelter.',
        'services' => ['Rodent Control', 'Cockroach Control', 'Termite Control'],
        'icon' => '🍃',
        'reason' => 'Cooler weather drives rodents and cockroaches indoors seeking warmth.'
    ]
];

$current_recommendations = $seasonal_recommendations[$current_month] ?? $seasonal_recommendations[12];
$recommended_service_names = $current_recommendations['services'];

// Get available services that match recommendations
$recommended_services = [];
foreach ($services_list as $service) {
    foreach ($recommended_service_names as $rec_name) {
        if (stripos($service['service_name'], $rec_name) !== false) {
            $recommended_services[] = $service;
            break;
        }
    }
}

// If no exact matches, get all services for fallback
if (empty($recommended_services)) {
    $recommended_services = $services_list;
}

// Get next month's recommendations (for November - Pre-Winter)
$next_month = ($current_month % 12) + 1;
$next_month_recommendations = $seasonal_recommendations[$next_month] ?? null;
$next_month_service_names = $next_month_recommendations ? $next_month_recommendations['services'] : [];

// Get available services that match next month's recommendations
$next_month_services = [];
if (!empty($next_month_recommendations)) {
    foreach ($services_list as $service) {
        foreach ($next_month_service_names as $rec_name) {
            if (stripos($service['service_name'], $rec_name) !== false) {
                $next_month_services[] = $service;
                break;
            }
        }
    }
}

// Get next month name
$month_names = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
                7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$next_month_name = $month_names[$next_month] ?? 'Next Month';

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

// ========== MONTHLY INSIGHTS ANALYSIS ==========
// Get monthly booking data for the last 2 years
$monthly_insights_sql = "
    SELECT
        DATE_FORMAT(sb.appointment_date, '%Y-%m') as month_year,
        DATE_FORMAT(sb.appointment_date, '%M %Y') as month_name,
        DATE_FORMAT(sb.appointment_date, '%m') as month_num,
        s.service_name,
        COUNT(*) as booking_count,
        SUM(CASE WHEN sb.status = 'Completed' THEN 1 ELSE 0 END) as completed_count
    FROM service_bookings sb
    LEFT JOIN services s ON sb.service_id = s.service_id
    WHERE sb.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
    AND sb.status != 'Cancelled'
    AND s.service_name IS NOT NULL
    GROUP BY month_year, month_name, s.service_name, month_num
    ORDER BY month_year ASC, s.service_name ASC
";

$monthly_result = $conn->query($monthly_insights_sql);
$monthly_data = [];
$service_types = ['Pest Control', 'Termite', 'Sanitation and Disinfection'];

// Initialize monthly data structure
$monthly_stats = [];
$all_months = ['01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
               '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
               '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'];

foreach ($service_types as $service_type) {
    $monthly_stats[$service_type] = [];
    foreach ($all_months as $num => $name) {
        $monthly_stats[$service_type][$num] = [
            'name' => $name,
            'total_bookings' => 0,
            'completed' => 0,
            'years' => []
        ];
    }
}

// Process monthly data
while ($row = $monthly_result->fetch_assoc()) {
    $service_name = strtolower(trim($row['service_name']));
    $month_num = $row['month_num'];

    // Enhanced service matching with multiple keywords
    $service_keywords = [
        'Pest Control' => ['pest control', 'pest', 'pestcontrol', 'pest-control', 'pestmanagement', 'pest management'],
        'Termite' => ['termite', 'termites', 'termite control', 'termite treatment', 'termitecontrol'],
        'Sanitation and Disinfection' => ['sanitation', 'disinfection', 'sanitation and disinfection', 'sanitation & disinfection', 'sanitization', 'disinfect']
    ];

    // Check which service type this booking belongs to
    foreach ($service_keywords as $service_type => $keywords) {
        $matched = false;
        foreach ($keywords as $keyword) {
            if (stripos($service_name, $keyword) !== false) {
                $matched = true;
                break;
            }
        }

        if ($matched && isset($monthly_stats[$service_type][$month_num])) {
            $monthly_stats[$service_type][$month_num]['total_bookings'] += (int)$row['booking_count'];
            $monthly_stats[$service_type][$month_num]['completed'] += (int)$row['completed_count'];
            $monthly_stats[$service_type][$month_num]['years'][$row['month_year']] = [
                'bookings' => (int)$row['booking_count'],
                'completed' => (int)$row['completed_count']
            ];
            break; // Only count once per service
        }
    }
}

// Calculate average bookings per month for each service type
$monthly_insights = [];
foreach ($service_types as $service_type) {
    $monthly_insights[$service_type] = [
        'monthly_averages' => [],
        'peak_months' => [],
        'low_months' => [],
        'total_bookings' => 0,
        'best_months' => []
    ];

    $total = 0;
    $month_counts = [];

    foreach ($monthly_stats[$service_type] as $num => $data) {
        $avg = $data['total_bookings'];
        $monthly_insights[$service_type]['monthly_averages'][$num] = [
            'month' => $data['name'],
            'average' => $avg,
            'total' => $data['total_bookings'],
            'completed' => $data['completed']
        ];
        $month_counts[$num] = $avg;
        $total += $avg;
    }

    $monthly_insights[$service_type]['total_bookings'] = $total;

    // Find peak months (top 3)
    arsort($month_counts);
    $top_months = array_slice($month_counts, 0, 3, true);
    foreach ($top_months as $num => $count) {
        if ($count > 0) {
            $monthly_insights[$service_type]['peak_months'][] = [
                'month' => $all_months[$num],
                'count' => $count
            ];
        }
    }

    // Find low months (bottom 3)
    asort($month_counts);
    $bottom_months = array_slice($month_counts, 0, 3, true);
    foreach ($bottom_months as $num => $count) {
        if ($count > 0) {
            $monthly_insights[$service_type]['low_months'][] = [
                'month' => $all_months[$num],
                'count' => $count
            ];
        }
    }

    // Determine best months (above average)
    $average = $total > 0 ? $total / 12 : 0;
    foreach ($month_counts as $num => $count) {
        if ($count > $average && $count > 0) {
            $monthly_insights[$service_type]['best_months'][] = [
                'month' => $all_months[$num],
                'count' => $count,
                'percentage' => $average > 0 ? round(($count / $average - 1) * 100, 1) : 0
            ];
        }
    }

    // Sort best months by count
    usort($monthly_insights[$service_type]['best_months'], function($a, $b) {
        return $b['count'] - $a['count'];
    });
}

// Prepare chart data for monthly trends
$chart_labels = array_values($all_months);
$chart_datasets = [];

foreach ($service_types as $service_type) {
    $data = [];
    foreach (array_keys($all_months) as $num) {
        $data[] = $monthly_insights[$service_type]['monthly_averages'][$num]['average'];
    }

    $colors = [
        'Pest Control' => ['bg' => 'rgba(33, 150, 243, 0.6)', 'border' => 'rgba(33, 150, 243, 1)'],
        'Termite' => ['bg' => 'rgba(255, 152, 0, 0.6)', 'border' => 'rgba(255, 152, 0, 1)'],
        'Sanitation and Disinfection' => ['bg' => 'rgba(76, 175, 80, 0.6)', 'border' => 'rgba(76, 175, 80, 1)']
    ];

    $color = $colors[$service_type] ?? ['bg' => 'rgba(156, 39, 176, 0.6)', 'border' => 'rgba(156, 39, 176, 1)'];

    $chart_datasets[] = [
        'label' => $service_type,
        'data' => $data,
        'backgroundColor' => $color['bg'],
        'borderColor' => $color['border'],
        'borderWidth' => 2
    ];
}

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

// Rotation schedule: dynamic 3-month periods (quarterly rotation like inventory.php)
$rotation_periods = [];
$period_count = 5;
$month_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// Get current rotation period (quarterly like inventory.php)
$month = (int)date('n');
$rotation_period = floor(($month - 1) / 3);
$quarters = ["Jan - Mar", "Apr - Jun", "Jul - Sep", "Oct - Dec"];
$current_period = $quarters[$rotation_period] . " " . date('Y');

// Generate rotation periods starting from current period
$start_month = (int)date('n');
$start_year = (int)date('Y');

for ($i = 0; $i < $period_count; $i++) {
    $month1 = ($start_month + $i*3 - 1) % 12;
    $year1 = $start_year + floor(($start_month + $i*3 - 1)/12);
    $month2 = ($month1 + 2) % 12;
    $year2 = $year1 + floor(($month1 + 2)/12);

    $rotation_periods[] = $month_names[$month1] . " " . $year1 . " - " . $month_names[$month2] . " " . $year2;
}

// Get rotation ingredients for each service type (like inventory.php)
$termite_ingredients = ['Fipronil', 'Bifenthrin', 'Imidacloprid'];
$pest_control_ingredients = ['Lambda-Cyhalothrin', 'Beta-Cyfluthrin', 'Cypermethrin', 'Deltamethrin'];

// Determine current active chemicals (like inventory.php)
// Match ingredients by checking if name contains any of the rotation ingredients (case-insensitive)
$termite_items = [];
$pest_items = [];

foreach ($ingredients as $ing) {
    $ingredient_name = trim($ing['name'] ?? '');
    // Check termite ingredients
    foreach ($termite_ingredients as $termite_ing) {
        if (stripos($ingredient_name, $termite_ing) !== false || stripos($termite_ing, $ingredient_name) !== false) {
            if (!in_array($ingredient_name, $termite_items)) {
                $termite_items[] = $ingredient_name;
            }
            break;
        }
    }
    // Check pest control ingredients
    foreach ($pest_control_ingredients as $pest_ing) {
        if (stripos($ingredient_name, $pest_ing) !== false || stripos($pest_ing, $ingredient_name) !== false) {
            if (!in_array($ingredient_name, $pest_items)) {
                $pest_items[] = $ingredient_name;
            }
            break;
        }
    }
}

// Determine current active chemicals based on rotation period
if (!empty($termite_items)) {
    $termite_index = $rotation_period % count($termite_items);
    $current_termite = $termite_items[$termite_index];
} else {
    $current_termite = 'None Selected';
}

if (!empty($pest_items)) {
    $pest_index = $rotation_period % count($pest_items);
    $current_pest = $pest_items[$pest_index];
} else {
    $current_pest = 'None Selected';
}

// Generate rotation schedule for graph (next 8 quarters)
$rotation_schedule = [];
$quarters_full = ["Jan - Mar", "Apr - Jun", "Jul - Sep", "Oct - Dec"];
$current_year = (int)date('Y');
$start_quarter = $rotation_period;

$termite_count = count($termite_items);
$pest_count = count($pest_items);

for ($q = 0; $q < 8; $q++) {
    $quarter_index = ($start_quarter + $q) % 4;
    $year_offset = floor(($start_quarter + $q) / 4);
    $year = $current_year + $year_offset;

    // Calculate rotation index based on quarter progression
    $termite_rot_index = $termite_count > 0 ? (($start_quarter + $q) % $termite_count) : 0;
    $pest_rot_index = $pest_count > 0 ? (($start_quarter + $q) % $pest_count) : 0;

    $rotation_schedule[] = [
        'period' => 'Q' . ($quarter_index + 1) . ' ' . $quarters_full[$quarter_index] . ' ' . $year,
        'quarter_num' => $quarter_index + 1,
        'termite' => $termite_count > 0 ? $termite_items[$termite_rot_index] : 'None',
        'pest' => $pest_count > 0 ? $pest_items[$pest_rot_index] : 'None',
        'is_current' => ($q === 0),
        'label' => 'Q' . ($quarter_index + 1) . ' • ' . $quarters_full[$quarter_index] . ' ' . $year
    ];
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Analytics & Service Alerts Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
        .content-card > h2:first-child {
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
        .content-card > h2:first-child i {
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
        .stat-card.critical {
            background: linear-gradient(135deg, var(--danger), #dc2626);
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
            color: white;
        }
        .stat-label {
            opacity: 0.9;
            margin-top: 0.5rem;
            color: white;
            font-size: 0.95rem;
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
        .alert-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .alert-section h2 {
            color: var(--green-700);
            font-size: 1.5rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-card {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            border-left: 5px solid;
        }
        .alert-card.critical {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border-color: #ef4444;
        }
        .alert-card.high {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-color: #f59e0b;
        }
        .alert-card.medium {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #3b82f6;
        }
        .alert-card.info {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-color: #10b981;
        }
        .alert-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        .alert-title {
            font-size: 18px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 5px;
        }
        .alert-subtitle {
            color: #6b7280;
            font-size: 14px;
        }
        .alert-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-critical {
            background: #ef4444;
            color: white;
        }
        .badge-high {
            background: #f59e0b;
            color: white;
        }
        .badge-medium {
            background: #3b82f6;
            color: white;
        }
        .badge-info {
            background: #10b981;
            color: white;
        }
        .alert-details {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0,0,0,0.1);
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }
        .detail-label {
            color: #6b7280;
        }
        .detail-value {
            color: #1f2937;
            font-weight: 500;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        .service-list {
            display: grid;
            gap: 12px;
        }
        .service-item {
            background: rgba(255,255,255,0.7);
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--green-500);
        }
        .service-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .service-name {
            font-weight: bold;
            color: #1f2937;
        }
        .service-time {
            color: #6b7280;
            font-size: 14px;
        }
        .service-details {
            font-size: 14px;
            color: #6b7280;
        }
        .insight-card {
            background: linear-gradient(135deg, var(--green-600) 0%, var(--green-700) 50%, var(--green-800) 100%);
            color: white;
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(16, 185, 129, 0.3);
        }
        .insight-card h3 {
            font-size: 1.75rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
        }
        .insight-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .insight-item {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .insight-item h4 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #fff;
        }
        .insight-badge {
            display: inline-block;
            background: rgba(255,255,255,0.3);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin: 5px 5px 5px 0;
        }
        .insight-list {
            list-style: none;
            padding: 0;
        }
        .insight-list li {
            padding: 10px;
            background: rgba(255,255,255,0.1);
            margin-bottom: 8px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .insight-list li strong {
            color: #fff;
        }
        .month-tag {
            background: rgba(255,255,255,0.25);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        /* Unique Rotation Recommendation Cards */
        .rotation-recommendation-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 3px solid;
            border-radius: 24px;
            padding: 0;
            margin-bottom: 2rem;
            box-shadow:
                0 20px 60px rgba(0, 0, 0, 0.15),
                0 8px 25px rgba(0, 0, 0, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            overflow: hidden;
            position: relative;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideInUp 0.6s ease-out;
        }
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .rotation-recommendation-card.critical {
            border-color: #ef4444;
            box-shadow:
                0 20px 60px rgba(239, 68, 68, 0.25),
                0 8px 25px rgba(239, 68, 68, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }
        .rotation-recommendation-card.warning {
            border-color: #f59e0b;
            box-shadow:
                0 20px 60px rgba(245, 158, 11, 0.25),
                0 8px 25px rgba(245, 158, 11, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }
        .rotation-recommendation-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow:
                0 30px 80px rgba(0, 0, 0, 0.2),
                0 12px 35px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.95);
        }
        .rotation-card-header {
            padding: 2rem 2.5rem;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%);
            color: white;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .rotation-card-header.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%);
        }
        .rotation-card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            animation: rotateGlow 8s linear infinite;
        }
        @keyframes rotateGlow {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .rotation-header-content {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            position: relative;
            z-index: 1;
        }
        .rotation-icon-wrapper {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            box-shadow:
                0 8px 20px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.3);
            animation: pulseIcon 2s ease-in-out infinite;
        }
        @keyframes pulseIcon {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        .rotation-header-text h3 {
            font-size: 1.75rem;
            font-weight: 800;
            margin: 0 0 0.5rem 0;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            letter-spacing: -0.5px;
        }
        .rotation-header-text p {
            margin: 0;
            opacity: 0.95;
            font-size: 1rem;
            font-weight: 500;
        }
        .rotation-badge {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            position: relative;
            z-index: 1;
            animation: shimmerBadge 3s ease-in-out infinite;
        }
        @keyframes shimmerBadge {
            0%, 100% { box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); }
            50% { box-shadow: 0 4px 25px rgba(255, 255, 255, 0.4); }
        }
        .rotation-card-body {
            padding: 2.5rem;
        }
        .rotation-timeline {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, rgba(239, 68, 68, 0.03) 100%);
            border-radius: 16px;
            border-left: 5px solid #ef4444;
            position: relative;
            overflow: hidden;
        }
        .rotation-timeline.warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.08) 0%, rgba(245, 158, 11, 0.03) 100%);
            border-left-color: #f59e0b;
        }
        .timeline-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            flex-shrink: 0;
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }
        .timeline-icon.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
        }
        .timeline-content {
            flex: 1;
        }
        .timeline-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .timeline-subtitle {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .timeline-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        .timeline-stat-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .timeline-stat-label {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .timeline-stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }
        .alternatives-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        .alternative-card {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(34, 197, 94, 0.05) 100%);
            border: 2px solid rgba(16, 185, 129, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .alternative-card::before {
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
        .alternative-card:hover {
            transform: translateY(-4px);
            border-color: var(--green-500);
            box-shadow: 0 12px 30px rgba(16, 185, 129, 0.2);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15) 0%, rgba(34, 197, 94, 0.08) 100%);
        }
        .alternative-card-header {
            display: flex;
            align-items: start;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .alternative-name {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--green-700);
            margin-bottom: 0.25rem;
        }
        .alternative-service {
            font-size: 0.875rem;
            color: #64748b;
        }
        .alternative-stock {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.875rem;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .recommendation-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(239, 68, 68, 0.1);
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.05) 0%, rgba(239, 68, 68, 0.02) 100%);
            padding: 1.5rem;
            border-radius: 12px;
            display: flex;
            align-items: start;
            gap: 1rem;
        }
        .recommendation-footer.warning {
            border-top-color: rgba(245, 158, 11, 0.1);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(245, 158, 11, 0.02) 100%);
        }
        .footer-icon {
            font-size: 1.5rem;
            color: var(--green-600);
            flex-shrink: 0;
        }
        .footer-text {
            flex: 1;
        }
        .footer-text strong {
            display: block;
            color: var(--dark);
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        .footer-text p {
            margin: 0;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        .no-recommendations {
            text-align: center;
            padding: 5rem 2rem;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(34, 197, 94, 0.02) 100%);
            border-radius: 20px;
            border: 3px dashed rgba(16, 185, 129, 0.3);
        }
        .no-recommendations-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 2rem;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--green-400), var(--green-600));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            box-shadow:
                0 20px 60px rgba(16, 185, 129, 0.3),
                0 8px 25px rgba(34, 197, 94, 0.2),
                inset 0 2px 0 rgba(255, 255, 255, 0.3);
            animation: floatIcon 3s ease-in-out infinite;
        }
        @keyframes floatIcon {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .no-recommendations h3 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--green-700);
            margin-bottom: 0.75rem;
        }
        .no-recommendations p {
            color: #64748b;
            font-size: 1rem;
            margin: 0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }
        form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        select, input[type=text], button {
            padding: 0.75rem 1rem;
            border-radius: 10px;
            border: 2px solid var(--green-200);
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        select:focus, input[type=text]:focus {
            outline: none;
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
        }
        button {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        button:hover {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        canvas {
            max-width: 100%;
        }
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
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .alert-header {
                flex-direction: column;
                gap: 10px;
            }
            .insight-grid {
                grid-template-columns: 1fr;
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
                <p>Analytics & Alerts</p>
            </div>
            <nav class="nav-menu">
              <div class="user-section">
                  <a href="dashboard.php" class="btn btn-modern btn-primary-modern w-100 mb-2" style="background: linear-gradient(135deg, var(--green-500), var(--green-600)); color: white; border: none; padding: 0.75rem 2rem; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);">
                      <i class="bi bi-speedometer2"></i> Dashboard
                  </a>

              </div>
                <div class="nav-item">
                    <a href="analytics.php" class="nav-link active">
                        <i class="bi bi-graph-up-arrow"></i>
                        <span>Analytics</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="sales_report.php" class="nav-link">
                        <i class="bi bi-bar-chart"></i>
                        <span>Sales Report</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="service_records.php" class="nav-link">
                        <i class="bi bi-calendar-check"></i>
                        <span>Service Records</span>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <div class="dashboard-container">
            <div class="top-bar">
                <h1 class="page-title">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Analytics & Service Alerts Dashboard</span>
                </h1>
        </div>

        <!-- Monthly Insights Section -->
            <div class="content-card">
        <div class="insight-card">
            <h3>📅 Monthly Service Insights</h3>
            <p style="margin-bottom: 20px; opacity: 0.9;">Analyzing booking patterns to identify the best months for each service type based on historical data from the last 24 months.</p>

            <!-- Summary Insights -->
            <div style="background: rgba(255,255,255,0.2); padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                <h4 style="font-size: 18px; margin-bottom: 15px;">💡 Key Insights:</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <?php
                    $has_insights = false;
                    foreach ($service_types as $service_type):
                        $insight = $monthly_insights[$service_type];
                        if ($insight['total_bookings'] == 0) continue;
                        $has_insights = true;

                        $best_month = !empty($insight['best_months']) ? $insight['best_months'][0] : null;
                        $peak_month = !empty($insight['peak_months']) ? $insight['peak_months'][0] : null;
                    ?>
                        <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px;">
                            <strong style="display: block; margin-bottom: 8px; font-size: 16px;"><?= htmlspecialchars($service_type) ?>:</strong>
                            <?php if ($best_month): ?>
                                <div style="font-size: 14px; margin-bottom: 5px;">
                                    ⭐ Best Month: <strong><?= htmlspecialchars($best_month['month']) ?></strong>
                                    <span style="opacity: 0.9;">(<?= $best_month['count'] ?> bookings, +<?= $best_month['percentage'] ?>%)</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($peak_month && $peak_month['month'] != $best_month['month']): ?>
                                <div style="font-size: 14px;">
                                    📈 Peak: <strong><?= htmlspecialchars($peak_month['month']) ?></strong>
                                    <span style="opacity: 0.9;">(<?= $peak_month['count'] ?> bookings)</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$has_insights): ?>
                        <div style="text-align: center; padding: 20px; opacity: 0.8;">
                            <p>No booking data available for analysis. Insights will appear as more bookings are recorded.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="insight-grid">
                <?php foreach ($service_types as $service_type):
                    $insight = $monthly_insights[$service_type];
                    if ($insight['total_bookings'] == 0) continue;
                ?>
                    <div class="insight-item">
                        <h4>🐛 <?= htmlspecialchars($service_type) ?></h4>

                        <?php if (!empty($insight['best_months'])): ?>
                            <div style="margin-bottom: 15px;">
                                <strong style="display: block; margin-bottom: 8px;">⭐ Best Months:</strong>
                                <ul class="insight-list">
                                    <?php foreach (array_slice($insight['best_months'], 0, 3) as $best): ?>
                                        <li>
                                            <strong><?= htmlspecialchars($best['month']) ?></strong>
                                            <span class="month-tag">+<?= $best['percentage'] ?>% above average</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($insight['peak_months'])): ?>
                            <div style="margin-bottom: 15px;">
                                <strong style="display: block; margin-bottom: 8px;">📈 Peak Months:</strong>
                                <?php foreach ($insight['peak_months'] as $peak): ?>
                                    <span class="insight-badge"><?= htmlspecialchars($peak['month']) ?> (<?= $peak['count'] ?> bookings)</span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <strong>Total Bookings:</strong> <?= $insight['total_bookings'] ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="chart-container">
                <h4 style="color: #333; margin-bottom: 15px;">Monthly Booking Trends</h4>
                <canvas id="monthlyTrendsChart"></canvas>
            </div>
                </div>
        </div>

            <!-- Smart Rotation Dashboard (like inventory.php) -->
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                    <div>
                        <h2 style="font-size: 1.5rem; color: var(--green-700); margin-bottom: 0.5rem;">
                            <i class="bi bi-arrow-repeat me-2"></i>Smart Rotation Dashboard
                        </h2>
                        <p class="text-muted mb-0">Quarter <?= $rotation_period + 1 ?> • <?= $current_period ?></p>
            </div>
            </div>

                <!-- Active Chemicals -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="content-card" style="border-top-color: var(--success); padding: 1.5rem; margin-bottom: 0;">
                            <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 1rem; color: var(--success);">
                                <i class="bi bi-bug me-2"></i>Termite Control
            </div>
                            <div style="font-size: 2rem; font-weight: 800; color: var(--dark); margin-bottom: 1rem;">
                                <?= htmlspecialchars(ucwords(strtolower($current_termite))) ?>
            </div>
                            <span style="display: inline-block; padding: 0.5rem 1rem; border-radius: 50px; font-weight: 600; font-size: 0.85rem; background: linear-gradient(135deg, var(--green-400), var(--green-600)); color: white;">
                                <i class="bi bi-check-circle me-2"></i>Active This Quarter
                    </span>
                                </div>
                            </div>
                    <div class="col-md-6">
                        <div class="content-card" style="border-top-color: #3b82f6; padding: 1.5rem; margin-bottom: 0;">
                            <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 1rem; color: #3b82f6;">
                                <i class="bi bi-shield-fill me-2"></i>General Pest
                                </div>
                            <div style="font-size: 2rem; font-weight: 800; color: var(--dark); margin-bottom: 1rem;">
                                <?= htmlspecialchars(ucwords(strtolower($current_pest))) ?>
                                </div>
                            <span style="display: inline-block; padding: 0.5rem 1rem; border-radius: 50px; font-weight: 600; font-size: 0.85rem; background: linear-gradient(135deg, #60a5fa, #3b82f6); color: white;">
                                <i class="bi bi-check-circle me-2"></i>Active This Quarter
                            </span>
                                </div>
                                </div>
                                </div>
                            </div>

            <!-- Rotation Schedule Graph -->
            <div class="content-card">
                <h2><i class="bi bi-calendar-range"></i>Rotation Schedule</h2>
                <p class="text-muted mb-4">Visual schedule showing which chemicals are active for each quarter based on rotation pattern.</p>
                <div class="chart-container" style="height: 450px;">
                    <canvas id="rotationScheduleChart"></canvas>
                        </div>
            </div>

            <!-- Seasonal Service Recommendations -->
            <div class="content-card">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                                <div>
                        <h2 style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                            <span style="font-size: 2rem;"><?= $current_recommendations['icon'] ?></span>
                            <span><?= $current_recommendations['title'] ?></span>
                        </h2>
                        <p class="text-muted mb-0" style="font-size: 0.95rem; padding-left: 3rem;">
                            <strong><?= date('F Y') ?></strong> - <?= $current_recommendations['description'] ?>
                        </p>
                                </div>
                    <div style="background: linear-gradient(135deg, var(--green-400), var(--green-600)); color: white; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 600; text-align: center;">
                        <div style="font-size: 0.85rem; opacity: 0.9;">Recommended</div>
                        <div style="font-size: 1.25rem;"><?= count($recommended_services) ?> Services</div>
                            </div>
                                </div>

                <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 1rem 1.5rem; border-radius: 12px; border-left: 4px solid #f59e0b; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: start; gap: 1rem;">
                        <div style="font-size: 1.5rem;">💡</div>
                                <div>
                            <strong style="color: #92400e; display: block; margin-bottom: 0.5rem;">Why These Services?</strong>
                            <p style="color: #78350f; margin: 0; font-size: 0.95rem;"><?= $current_recommendations['reason'] ?></p>
                                </div>
                            </div>
                                </div>

                <?php if (!empty($recommended_services)): ?>
                    <div class="row g-4">
                        <?php foreach ($recommended_services as $service): ?>
                            <div class="col-md-6 col-lg-4">
                                <div style="background: white; border: 2px solid var(--green-200); border-radius: 16px; padding: 1.5rem; height: 100%; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"
                                     onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 16px rgba(16, 185, 129, 0.2)'; this.style.borderColor='var(--green-400)';"
                                     onmouseout="this.style.transform=''; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)'; this.style.borderColor='var(--green-200)';">
                                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                                        <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--green-400), var(--green-600)); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; font-weight: bold; flex-shrink: 0;">
                                            🛡️
                                </div>
                                        <div style="flex: 1;">
                                            <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--dark); margin: 0;">
                                                <?= htmlspecialchars($service['service_name']) ?>
                                            </h4>
                                            <div style="font-size: 0.85rem; color: var(--green-600); font-weight: 600; margin-top: 0.25rem;">
                                                Recommended This Month
                                </div>
                            </div>
                        </div>
                                    <div style="padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                                        <div style="display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.9rem;">
                                            <i class="bi bi-calendar3" style="color: var(--green-500);"></i>
                                            <span>Best Time: <?= date('F Y') ?></span>
                </div>
        </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                    <div class="empty-state" style="padding: 3rem 2rem; text-align: center; background: #f9fafb; border-radius: 12px; border: 2px dashed #d1d5db;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                        <h3 style="color: var(--dark); margin-bottom: 0.5rem;">No Services Available</h3>
                        <p style="color: #6b7280; margin: 0;">No services match the current seasonal recommendations.</p>
                </div>
            <?php endif; ?>
        </div>

            <!-- Next Month's Recommendations (for November - Late Wet Season) -->
            <?php if ($current_month == 11 && !empty($next_month_recommendations) && !empty($next_month_services)): ?>
                <div class="content-card" style="margin-top: 2rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                                <div>
                            <h2 style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <span style="font-size: 2rem;"><?= $next_month_recommendations['icon'] ?></span>
                                <span>Upcoming: <?= $next_month_recommendations['title'] ?></span>
                            </h2>
                            <p class="text-muted mb-0" style="font-size: 0.95rem; padding-left: 3rem;">
                                <strong><?= $next_month_name ?> <?= date('Y', strtotime('+1 month')) ?></strong> - <?= $next_month_recommendations['description'] ?>
                            </p>
                                    </div>
                        <div style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 600; text-align: center;">
                            <div style="font-size: 0.85rem; opacity: 0.9;">Next Month</div>
                            <div style="font-size: 1.25rem;"><?= count($next_month_services) ?> Services</div>
                                </div>
                            </div>

                    <div style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); padding: 1rem 1.5rem; border-radius: 12px; border-left: 4px solid #3b82f6; margin-bottom: 1.5rem;">
                        <div style="display: flex; align-items: start; gap: 1rem;">
                            <div style="font-size: 1.5rem;">🔮</div>
                            <div>
                                <strong style="color: #1e40af; display: block; margin-bottom: 0.5rem;">Prepare for Next Month</strong>
                                <p style="color: #1e3a8a; margin: 0; font-size: 0.95rem;"><?= $next_month_recommendations['reason'] ?></p>
                            </div>
                        </div>
        </div>

                    <div class="row g-4">
                        <?php foreach ($next_month_services as $service): ?>
                            <div class="col-md-6 col-lg-4">
                                <div style="background: white; border: 2px solid #93c5fd; border-radius: 16px; padding: 1.5rem; height: 100%; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"
                                     onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 16px rgba(59, 130, 246, 0.2)'; this.style.borderColor='#3b82f6';"
                                     onmouseout="this.style.transform=''; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)'; this.style.borderColor='#93c5fd';">
                                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                                        <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; font-weight: bold; flex-shrink: 0;">
                                            📅
        </div>
                                        <div style="flex: 1;">
                                            <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--dark); margin: 0;">
                                                <?= htmlspecialchars($service['service_name']) ?>
                                            </h4>
                                            <div style="font-size: 0.85rem; color: #3b82f6; font-weight: 600; margin-top: 0.25rem;">
                                                Recommended Next Month
                        </div>
                            </div>
                                        </div>
                                    <div style="padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                                        <div style="display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.9rem;">
                                            <i class="bi bi-calendar3" style="color: #3b82f6;"></i>
                                            <span>Best Time: <?= $next_month_name ?> <?= date('Y', strtotime('+1 month')) ?></span>
                                    </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        </main>
    </div>

    <script>
        // Monthly Trends Chart
        const monthlyTrendsData = {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: <?= json_encode($chart_datasets) ?>
        };

        new Chart(document.getElementById('monthlyTrendsChart'), {
            type: 'line',
            data: monthlyTrendsData,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    title: {
                        display: true,
                        text: 'Monthly Booking Trends by Service Type',
                        font: {
                            size: 16
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Bookings'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Month'
                        }
                    }
                }
            }
        });

        // Rotation Schedule Chart
        const rotationScheduleLabels = <?= json_encode(array_column($rotation_schedule, 'label')) ?>;
        const rotationScheduleTermite = <?= json_encode(array_column($rotation_schedule, 'termite')) ?>;
        const rotationSchedulePest = <?= json_encode(array_column($rotation_schedule, 'pest')) ?>;
        const rotationScheduleCurrent = <?= json_encode(array_column($rotation_schedule, 'is_current')) ?>;

        const rotationScheduleData = {
            labels: rotationScheduleLabels,
            datasets: [
                {
                    label: 'Termite Control',
                    data: rotationScheduleLabels.map(() => 1),
                    backgroundColor: rotationScheduleCurrent.map((isCur, idx) =>
                        isCur ? 'rgba(16, 185, 129, 1)' : 'rgba(16, 185, 129, 0.7)'
                    ),
                    borderColor: rotationScheduleCurrent.map((isCur, idx) =>
                        isCur ? 'rgba(16, 185, 129, 1)' : 'rgba(16, 185, 129, 0.5)'
                    ),
                    borderWidth: rotationScheduleCurrent.map((isCur) => isCur ? 3 : 2),
                    ingredients: rotationScheduleTermite,
                    service_type: 'Termite Control'
                },
                {
                    label: 'General Pest',
                    data: rotationScheduleLabels.map(() => 1),
                    backgroundColor: rotationScheduleCurrent.map((isCur, idx) =>
                        isCur ? 'rgba(59, 130, 246, 1)' : 'rgba(59, 130, 246, 0.7)'
                    ),
                    borderColor: rotationScheduleCurrent.map((isCur, idx) =>
                        isCur ? 'rgba(59, 130, 246, 1)' : 'rgba(59, 130, 246, 0.5)'
                    ),
                    borderWidth: rotationScheduleCurrent.map((isCur) => isCur ? 3 : 2),
                    ingredients: rotationSchedulePest,
                    service_type: 'General Pest'
                }
            ]
        };

        new Chart(document.getElementById('rotationScheduleChart'), {
            type: 'bar',
            data: rotationScheduleData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: { size: 14, weight: '600' },
                            padding: 20,
                            usePointStyle: true,
                            boxWidth: 15,
                            boxHeight: 15
                        }
                    },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                const index = context[0].dataIndex;
                                const isCur = rotationScheduleCurrent[index];
                                return context[0].label + (isCur ? ' (Current)' : '');
                            },
                            label: function(context) {
                                const dataset = context.dataset;
                                const ingredient = dataset.ingredients[context.dataIndex];
                                return dataset.service_type + ': ' + ingredient;
                            },
                            afterBody: function(context) {
                                const index = context[0].dataIndex;
                                if (rotationScheduleCurrent[index]) {
                                    return ['\n✓ This is the current quarter'];
                                }
                                return '';
                            }
                        },
                        padding: 15,
                        titleFont: { size: 16, weight: 'bold' },
                        bodyFont: { size: 14 },
                        backgroundColor: 'rgba(0, 0, 0, 0.85)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        cornerRadius: 12,
                        displayColors: true
                    }
                },
                scales: {
                    x: {
                        display: false,
                        beginAtZero: true,
                        max: 2,
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        ticks: {
                            font: { size: 13, weight: '600' },
                            color: '#1e293b',
                            padding: 10
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    </script>

</body>
</html>
<?php $conn->close(); ?>
