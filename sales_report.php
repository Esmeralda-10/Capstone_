<?php
session_start();

// Include audit logger
require_once 'audit_logger.php';

// HTML escaping helper function
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Parse price from price_range string
function parsePrice(?string $priceRange): float {
    if (!$priceRange || trim($priceRange) === '') return 0;

    $parts = explode('=', $priceRange, 2);
    $amountPart = isset($parts[1]) ? trim($parts[1]) : '';

    // Extract amount after =
    $amountRaw = preg_replace('/[^0-9.]/', '', str_replace(',', '', $amountPart));
    return $amountRaw === '' ? 0 : (float)$amountRaw;
}

function formatPriceDisplay(?string $priceRange, ?PDO $pdo = null, ?int $serviceId = null): string {
    if (!$priceRange || trim($priceRange) === '') return '—';
    $priceRange = trim($priceRange);
    $originalPriceRange = $priceRange; // Keep original for database lookup
    $parts = explode('=', $priceRange, 2);
    $rangePart = trim($parts[0]);
    $amountPart = isset($parts[1]) ? trim($parts[1]) : '';

    // Store original range part for database lookup
    $originalRangePart = $rangePart;

    // Remove all P/p symbols and spaces from range part for display
    $rangePartClean = preg_replace('/[Pp\s]/', '', $rangePart);
    $rangePartClean = preg_replace('/sqm$/i', '', $rangePartClean);

    // Extract range (e.g., "80-100" or just "80")
    if (preg_match('/(\d+)\s*-\s*(\d+)/', $rangePartClean, $matches)) {
        $range = strtolower($matches[1] . '-' . $matches[2]) . 'sqm';
        $minRange = (int)$matches[1];
        $maxRange = (int)$matches[2];
    } elseif (preg_match('/(\d+)/', $rangePartClean, $matches)) {
        $range = strtolower($matches[1]) . 'sqm';
        $minRange = (int)$matches[1];
        $maxRange = (int)$matches[1];
    } else {
        $range = '0sqm';
        $minRange = 0;
        $maxRange = 0;
    }

    // Extract amount after =
    $amountRaw = preg_replace('/[^0-9.]/', '', str_replace(',', '', $amountPart));
    $value = $amountRaw === '' ? 0 : (float)$amountRaw;

    // If value is 0 or missing, try to look up from database
    if (($value == 0 || $value == '') && $pdo && $serviceId && $minRange > 0) {
        try {
            // Look up price from service_price_ranges table
            // Try multiple patterns to match the price_range format
            $lookupStmt = $pdo->prepare("
                SELECT price, price_range
                FROM service_price_ranges
                WHERE service_id = ?
                AND (
                    price_range LIKE ?
                    OR price_range LIKE ?
                    OR price_range LIKE ?
                    OR price_range LIKE ?
                    OR price_range LIKE ?
                    OR price_range = ?
                )
                ORDER BY price DESC
                LIMIT 1
            ");

            // Try different patterns to match
            $pattern1 = '%' . $minRange . '-' . $maxRange . '%';
            $pattern2 = '%' . $minRange . '%' . $maxRange . '%';
            $pattern3 = $minRange . '-' . $maxRange . 'sqm%';
            $pattern4 = $minRange . '-' . $maxRange . 'SQM%';
            $pattern5 = $originalRangePart . '%';
            $pattern6 = $originalPriceRange;

            $lookupStmt->execute([$serviceId, $pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6]);
            $priceRow = $lookupStmt->fetch();

            if ($priceRow && isset($priceRow['price']) && $priceRow['price'] > 0) {
                $value = (float)$priceRow['price'];
            }
        } catch (Exception $e) {
            // If lookup fails, keep the original value (0)
        }
    }

    $formattedAmount = number_format($value, 0, '', ',');

    return "{$range}={$formattedAmount}";
}

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=pest control;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . h($e->getMessage()));
}

// Get date range filters
// Check if show_all is enabled first
$showAll = isset($_GET['show_all']) && $_GET['show_all'] == '1';
if ($showAll) {
    // When "All Sales" is active, set date range to cover all possible dates
    $fromDate = '2000-01-01'; // Very early date
    $toDate   = '2099-12-31'; // Very far future date
} else {
$fromDate = $_GET['from_date'] ?? date('Y-01-01');
$toDate   = $_GET['to_date']   ?? date('Y-12-31');

$fromValid = DateTime::createFromFormat('Y-m-d', $fromDate) !== false;
$toValid   = DateTime::createFromFormat('Y-m-d', $toDate) !== false;
if (!$fromValid || !$toValid) {
    $fromDate = date('Y-01-01');
    $toDate   = date('Y-12-31');
}
}


// Helper function to get start/end dates for different timeframes
function getTimeframeDates($type, $selectedValue = null) {
    $date = new DateTime();

    if ($selectedValue) {
        // For yearly, selectedValue is just a year number
        if ($type === 'yearly' && is_numeric($selectedValue)) {
            $date = new DateTime($selectedValue . '-01-01');
        } elseif (DateTime::createFromFormat('Y-m-d', $selectedValue) !== false) {
            $date = new DateTime($selectedValue);
        }
    }

    switch ($type) {
        case 'daily':
            return [
                'start' => $date->format('Y-m-d'),
                'end' => $date->format('Y-m-d'),
                'label' => 'Daily Sales (' . $date->format('M d, Y') . ')'
            ];
        case 'weekly':
            $monday = clone $date;
            $monday->modify('monday this week');
            $sunday = clone $monday;
            $sunday->modify('sunday this week');
            return [
                'start' => $monday->format('Y-m-d'),
                'end' => $sunday->format('Y-m-d'),
                'label' => 'Weekly Sales (' . $monday->format('M d') . ' - ' . $sunday->format('M d, Y') . ')'
            ];
        case 'monthly':
            $firstDay = clone $date;
            $firstDay->modify('first day of this month');
            $lastDay = clone $date;
            $lastDay->modify('last day of this month');
            return [
                'start' => $firstDay->format('Y-m-d'),
                'end' => $lastDay->format('Y-m-d'),
                'label' => 'Monthly Sales (' . $date->format('F Y') . ')'
            ];
        case 'yearly':
            $year = $date->format('Y');
            return [
                'start' => $year . '-01-01',
                'end' => $year . '-12-31',
                'label' => 'Yearly Sales (' . $year . ')'
            ];
        default:
            return ['start' => date('Y-m-d'), 'end' => date('Y-m-d'), 'label' => ''];
    }
}

$fullName = 'Guest';
try {
    if (isset($_SESSION['id']) && $_SESSION['id'] > 0) {
        $userStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['id']]);
        $userRow = $userStmt->fetch();
        if ($userRow) {
            $fullName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
            if (empty($fullName)) {
                $fullName = $_SESSION['username'] ?? 'Guest';
            }
        } else {
            $fullName = $_SESSION['username'] ?? 'Guest';
        }
    } else {
        $fullName = $_SESSION['username'] ?? 'Guest';
    }
} catch (Exception $e) {
    $fullName = $_SESSION['username'] ?? 'Guest';
}

// Get status filter
// If "show_all" is enabled, automatically set status to "all"
if ($showAll) {
    $statusFilter = 'all';
} else {
    $statusFilter = $_GET['status'] ?? 'all';
}
$validStatuses = ['all', 'Pending', 'In Progress', 'Completed', 'Cancelled'];
if (!in_array($statusFilter, $validStatuses)) {
    $statusFilter = 'all';
}

$bookings = [];
$totalBookingsInDB = 0;
try {
    $sql = "
        SELECT
            sb.*,
            COALESCE(sb.structure_types, '—') AS structure_label,
            s.service_name
        FROM service_bookings sb
        LEFT JOIN services s ON sb.service_id = s.service_id
        WHERE 1=1
    ";

    $params = [];

    // Only apply date filter if show_all is not active
    if (!$showAll) {
        $sql .= " AND DATE(sb.appointment_date) BETWEEN ? AND ?";
        $params[] = $fromDate;
        $params[] = $toDate;
    }

    // Add status filter if not "all"
    if ($statusFilter !== 'all') {
        $sql .= " AND sb.status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY sb.booking_id DESC";

    $bookingStmt = $pdo->prepare($sql);
    $bookingStmt->execute($params);
    $bookings = $bookingStmt->fetchAll() ?: [];
} catch (Exception $e) {
    $bookings = [];
}

try {
    $totalBookingsInDB = (int)$pdo->query("SELECT COUNT(*) FROM service_bookings")->fetchColumn() ?: 0;
} catch (Exception $e) {
    $totalBookingsInDB = 0;
}

$bookingsInRange = count($bookings);

// Calculate timeframes based on filters or defaults
// When "All Sales" is active, use today's date as reference (not the far-future end date)
// This ensures timeframe labels are based on current dates, but totals include all bookings
if ($showAll) {
    $referenceDate = date('Y-m-d'); // Use today's date
} else {
    $referenceDate = $toDate; // Use the selected end date
}
$dailyDates = getTimeframeDates('daily', $referenceDate);
$weeklyDates = getTimeframeDates('weekly', $referenceDate);
$monthlyDates = getTimeframeDates('monthly', $referenceDate);
$yearlyDates = getTimeframeDates('yearly', $referenceDate);

// Update timeframe labels when "All Sales" is active to show specific periods
if ($showAll) {
    $dailyDates['label'] = 'Daily Sales (' . date('M d, Y') . ')';
    $weeklyDates['label'] = 'Weekly Sales (' . date('M d', strtotime('monday this week')) . ' - ' . date('M d, Y', strtotime('sunday this week')) . ')';
    $monthlyDates['label'] = 'Monthly Sales (' . date('F Y') . ')';
    $yearlyDates['label'] = 'Yearly Sales (' . date('Y') . ')';
}

$timeframes = [
    'daily' => [
        'label' => $dailyDates['label'],
        'start' => $dailyDates['start'],
        'end' => $dailyDates['end'],
        'total' => 0,
        'pctOf' => 'weekly',
        'breakdown' => [],
    ],
    'weekly' => [
        'label' => $weeklyDates['label'],
        'start' => $weeklyDates['start'],
        'end' => $weeklyDates['end'],
        'total' => 0,
        'pctOf' => 'monthly',
        'breakdown' => [],
    ],
    'monthly' => [
        'label' => $monthlyDates['label'],
        'start' => $monthlyDates['start'],
        'end' => $monthlyDates['end'],
        'total' => 0,
        'pctOf' => 'yearly',
        'breakdown' => [],
    ],
    'yearly' => [
        'label' => $yearlyDates['label'],
        'start' => $yearlyDates['start'],
        'end' => $yearlyDates['end'],
        'total' => 0,
        'pctOf' => null,
        'breakdown' => [],
    ],
];

$statusCounts = [];
$totalRevenue = 0;

// First, set price_value for all bookings (needed for later calculations)
foreach ($bookings as &$booking) {
    $priceRange = $booking['price_range'] ?? '';
    $booking['price_value'] = parsePrice($priceRange);
    $status = $booking['status'] ?? 'Pending';
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

    if ($status === 'Completed' && $booking['price_value'] > 0) {
        $totalRevenue += $booking['price_value'];
    }
}
unset($booking);

// Calculate timeframes based on their specific date ranges
// When "All Sales" is active, calculate from all bookings but filter by timeframe periods
// Otherwise, use the normal date filtering logic
foreach ($bookings as &$booking) {
    $status = $booking['status'] ?? 'Pending';
    $apptDate = $booking['appointment_date'] ?? null;
    if (!$apptDate) continue;

        // Normalize appointment date to Y-m-d format for comparison
        if (is_string($apptDate)) {
            $dateObj = DateTime::createFromFormat('Y-m-d', $apptDate);
            if ($dateObj === false) {
                $dateObj = new DateTime($apptDate);
            }
            $apptDateStr = $dateObj->format('Y-m-d');
        } elseif (is_object($apptDate) && $apptDate instanceof DateTime) {
            $apptDateStr = $apptDate->format('Y-m-d');
        } else {
            $apptDateStr = date('Y-m-d', strtotime($apptDate));
        }

    foreach ($timeframes as $key => &$tf) {
        // Ensure timeframe dates are in Y-m-d format
        $tfStart = $tf['start'];
        $tfEnd = $tf['end'];

        // Compare dates as strings (Y-m-d format) for accurate comparison
        // When "All Sales" is active, still filter by timeframe date ranges (today, this week, this month, this year)
        // When normal mode, filter by both the selected date range AND the timeframe date range
        $withinTimeframe = ($apptDateStr >= $tfStart && $apptDateStr <= $tfEnd);
        $includeInTimeframe = $withinTimeframe;

        if (!$showAll) {
            // Normal mode: booking must be within both the selected date range AND timeframe range
            // (The booking query already filtered by selected date range, so we just check timeframe)
            $includeInTimeframe = $withinTimeframe;
        }
        // When showAll is active, $withinTimeframe already checks if booking is in the timeframe period

        if ($includeInTimeframe) {
            if ($status === 'Completed' && $booking['price_value'] > 0) {
                $tf['total'] += $booking['price_value'];
            }
            $serviceName = $booking['service_name'] ?: 'Unknown Service';
            if (!isset($tf['breakdown'][$serviceName])) {
                $tf['breakdown'][$serviceName] = [
                    'bookings' => 0,
                    'amount' => 0,
                    'status_counts' => []
                ];
            }
            $tf['breakdown'][$serviceName]['bookings']++;
            if ($status === 'Completed' && $booking['price_value'] > 0) {
                $tf['breakdown'][$serviceName]['amount'] += $booking['price_value'];
            }
            $tf['breakdown'][$serviceName]['status_counts'][$status] =
                ($tf['breakdown'][$serviceName]['status_counts'][$status] ?? 0) + 1;
        }
    }
}
unset($tf, $booking);

$bookingsByStatus = [];
foreach ($statusCounts as $label => $count) {
    $bookingsByStatus[] = ['status' => $label, 'count' => $count];
}

$months = [];
if ($showAll) {
    // When "All Sales" is active, get all months from the database
    try {
        $allMonthsStmt = $pdo->prepare("
            SELECT DISTINCT DATE_FORMAT(appointment_date, '%Y-%m') as ym
            FROM service_bookings
            WHERE status = 'Completed'
            ORDER BY ym ASC
        ");
        $allMonthsStmt->execute();
        while ($row = $allMonthsStmt->fetch()) {
            if (!empty($row['ym'])) {
                $months[$row['ym']] = 0;
            }
        }
    } catch (Exception $e) {
        // If query fails, fall back to last 12 months
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $months[$m] = 0;
}
    }
} else {
    for ($i = 11; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i months"));
        $months[$m] = 0;
    }
}

try {
    if ($showAll) {
        // When "All Sales" is active, get all completed bookings
        $monthlyStmt = $pdo->prepare("
            SELECT appointment_date, price_range
            FROM service_bookings
            WHERE status = 'Completed'
        ");
        $monthlyStmt->execute();
    } else {
        // Normal mode: last 12 months only
    $monthlyStmt = $pdo->prepare("
        SELECT appointment_date, price_range
        FROM service_bookings
        WHERE status = 'Completed'
        AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    ");
    $monthlyStmt->execute();
    }

    while ($row = $monthlyStmt->fetch()) {
        if (!isset($row['appointment_date']) || empty($row['appointment_date'])) continue;
        $ym = date('Y-m', strtotime($row['appointment_date']));
        if (!isset($months[$ym])) {
            $months[$ym] = 0;
        }
        $priceValue = parsePrice($row['price_range'] ?? '');
        if ($priceValue > 0) {
            $months[$ym] += $priceValue;
        }
    }
} catch (Exception $e) {
    // If query fails, months array remains with zeros
}
$monthlySales = [];
foreach ($months as $ym => $value) {
    $monthlySales[] = [
        'label' => date('M Y', strtotime("$ym-01")),
        'value' => $value
    ];
}

function framePercentage($tfKey, $timeframes) {
    $tf = $timeframes[$tfKey];
    if (!$tf['pctOf']) return null;
    $den = $timeframes[$tf['pctOf']]['total'] ?: 0;
    if ($den === 0) return null;
    return round(($tf['total'] / $den) * 100, 1);
}

function bestStatus(array $counts): string {
    if (empty($counts)) return 'Pending';
    arsort($counts);
    return array_key_first($counts);
}

// Calculate service performance
$servicePerformance = [];
foreach ($bookings as $booking) {
    $serviceName = $booking['service_name'] ?: 'Unknown Service';
    if (!isset($servicePerformance[$serviceName])) {
        $servicePerformance[$serviceName] = [
            'total_bookings' => 0,
            'completed' => 0,
            'pending' => 0,
            'cancelled' => 0,
            'total_revenue' => 0,
            'avg_revenue' => 0
        ];
    }
    $servicePerformance[$serviceName]['total_bookings']++;
    $status = $booking['status'] ?? 'Pending';
    if ($status === 'Completed') {
        $servicePerformance[$serviceName]['completed']++;
        if ($booking['price_value'] > 0) {
            $servicePerformance[$serviceName]['total_revenue'] += $booking['price_value'];
        }
    } elseif ($status === 'Pending' || $status === 'In Progress') {
        $servicePerformance[$serviceName]['pending']++;
    } elseif ($status === 'Cancelled') {
        $servicePerformance[$serviceName]['cancelled']++;
    }
}

// Calculate averages
foreach ($servicePerformance as &$perf) {
    if ($perf['completed'] > 0) {
        $perf['avg_revenue'] = $perf['total_revenue'] / $perf['completed'];
    }
    $perf['completion_rate'] = $perf['total_bookings'] > 0
        ? round(($perf['completed'] / $perf['total_bookings']) * 100, 1)
        : 0;
}
unset($perf);

// Sort by revenue
uasort($servicePerformance, function($a, $b) {
    return $b['total_revenue'] <=> $a['total_revenue'];
});

// Calculate revenue trends
$dailyTrends = [];
if ($showAll) {
    // When "All Sales" is active, get all days from the database
    try {
        $allDaysStmt = $pdo->prepare("
            SELECT DISTINCT DATE(appointment_date) as date
            FROM service_bookings
            WHERE status = 'Completed'
            ORDER BY date ASC
        ");
        $allDaysStmt->execute();
        while ($row = $allDaysStmt->fetch()) {
            if (!empty($row['date'])) {
                $dailyTrends[$row['date']] = [
                    'date' => $row['date'],
                    'label' => date('M d', strtotime($row['date'])),
                    'revenue' => 0,
                    'bookings' => 0
                ];
            }
        }
    } catch (Exception $e) {
        // If query fails, fall back to last 7 days
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dailyTrends[$date] = [
        'date' => $date,
        'label' => date('M d', strtotime($date)),
        'revenue' => 0,
        'bookings' => 0
    ];
        }
    }
} else {
    // Normal mode: last 7 days only
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dailyTrends[$date] = [
            'date' => $date,
            'label' => date('M d', strtotime($date)),
            'revenue' => 0,
            'bookings' => 0
        ];
    }
}

try {
    if ($showAll) {
        // When "All Sales" is active, get all completed bookings
        $trendStmt = $pdo->prepare("
            SELECT
                DATE(appointment_date) as date,
                COUNT(*) as bookings,
                price_range
            FROM service_bookings
            WHERE status = 'Completed'
            GROUP BY DATE(appointment_date)
            ORDER BY date ASC
        ");
        $trendStmt->execute();
    } else {
        // Normal mode: last 7 days only
    $trendStmt = $pdo->prepare("
        SELECT
            DATE(appointment_date) as date,
            COUNT(*) as bookings,
            price_range
        FROM service_bookings
        WHERE status = 'Completed'
        AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(appointment_date)
    ");
    $trendStmt->execute();
    }

    while ($row = $trendStmt->fetch()) {
        $date = $row['date'];
        if (!isset($dailyTrends[$date])) {
            $dailyTrends[$date] = [
                'date' => $date,
                'label' => date('M d', strtotime($date)),
                'revenue' => 0,
                'bookings' => 0
            ];
        }
            $dailyTrends[$date]['bookings'] = (int)$row['bookings'];
            // Calculate revenue for this day
            $dayStmt = $pdo->prepare("
                SELECT price_range
                FROM service_bookings
                WHERE status = 'Completed'
                AND DATE(appointment_date) = ?
            ");
            $dayStmt->execute([$date]);
            $revenue = 0;
            while ($dayRow = $dayStmt->fetch()) {
                $revenue += parsePrice($dayRow['price_range'] ?? '');
            }
            $dailyTrends[$date]['revenue'] = $revenue;
    }
} catch (Exception $e) {
    // If query fails, trends remain with zeros
}

$dailyTrendsData = array_values($dailyTrends);

// Calculate top customers
$topCustomers = [];
foreach ($bookings as $booking) {
    $customerName = $booking['customer_name'] ?? 'Unknown';
    $phone = $booking['phone_number'] ?? '';
    $key = $customerName . '|' . $phone;

    if (!isset($topCustomers[$key])) {
        $topCustomers[$key] = [
            'name' => $customerName,
            'phone' => $phone,
            'bookings' => 0,
            'revenue' => 0
        ];
    }
    $topCustomers[$key]['bookings']++;
    if ($booking['status'] === 'Completed' && $booking['price_value'] > 0) {
        $topCustomers[$key]['revenue'] += $booking['price_value'];
    }
}

// Sort by revenue
uasort($topCustomers, function($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
$topCustomers = array_slice($topCustomers, 0, 10, true); // Top 10

// Calculate growth metrics
$previousPeriodRevenue = 0;
$currentPeriodRevenue = $totalRevenue;
try {
    $daysDiff = (strtotime($toDate) - strtotime($fromDate)) / (60 * 60 * 24);
    $prevFrom = date('Y-m-d', strtotime($fromDate . " -" . ($daysDiff + 1) . " days"));
    $prevTo = date('Y-m-d', strtotime($fromDate . " -1 day"));

    $prevStmt = $pdo->prepare("
        SELECT price_range
        FROM service_bookings
        WHERE status = 'Completed'
        AND DATE(appointment_date) BETWEEN ? AND ?
    ");
    $prevStmt->execute([$prevFrom, $prevTo]);
    while ($row = $prevStmt->fetch()) {
        $previousPeriodRevenue += parsePrice($row['price_range'] ?? '');
    }
} catch (Exception $e) {
    // If query fails, previous revenue remains 0
}

$growthRate = $previousPeriodRevenue > 0
    ? round((($currentPeriodRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100, 1)
    : 0;

// Calculate insights for actionable recommendations
$insights = [];

// Services with low completion rates
$lowCompletionServices = [];
foreach ($servicePerformance as $serviceName => $perf) {
    if ($perf['completion_rate'] < 50 && $perf['total_bookings'] >= 2) {
        $lowCompletionServices[] = [
            'name' => $serviceName,
            'rate' => $perf['completion_rate'],
            'bookings' => $perf['total_bookings']
        ];
    }
}
if (!empty($lowCompletionServices)) {
    $insights[] = [
        'type' => 'warning',
        'icon' => 'exclamation-triangle',
        'message' => count($lowCompletionServices) . ' service(s) have completion rates below 50%',
        'details' => implode(', ', array_column($lowCompletionServices, 'name'))
    ];
}

// Services with high cancellation rates
$highCancellationServices = [];
foreach ($servicePerformance as $serviceName => $perf) {
    $cancellationRate = $perf['total_bookings'] > 0
        ? round(($perf['cancelled'] / $perf['total_bookings']) * 100, 1)
        : 0;
    if ($cancellationRate > 30 && $perf['total_bookings'] >= 3) {
        $highCancellationServices[] = [
            'name' => $serviceName,
            'rate' => $cancellationRate,
            'cancelled' => $perf['cancelled']
        ];
    }
}
if (!empty($highCancellationServices)) {
    $insights[] = [
        'type' => 'danger',
        'icon' => 'x-octagon',
        'message' => count($highCancellationServices) . ' service(s) have cancellation rates above 30%',
        'details' => implode(', ', array_column($highCancellationServices, 'name'))
    ];
}

// Revenue growth insights
if ($growthRate > 0 && $growthRate > 10) {
    $insights[] = [
        'type' => 'success',
        'icon' => 'arrow-up-circle',
        'message' => 'Revenue is up ' . abs($growthRate) . '% compared to the previous period',
        'details' => 'Great performance! Consider scaling successful strategies.'
    ];
} elseif ($growthRate < 0 && abs($growthRate) > 10) {
    $insights[] = [
        'type' => 'warning',
        'icon' => 'arrow-down-circle',
        'message' => 'Revenue is down ' . abs($growthRate) . '% compared to the previous period',
        'details' => 'Review sales strategies and focus on high-performing services.'
    ];
}

// Services with no revenue
$noRevenueServices = [];
foreach ($servicePerformance as $serviceName => $perf) {
    if ($perf['total_revenue'] == 0 && $perf['total_bookings'] > 0) {
        $noRevenueServices[] = $serviceName;
    }
}
if (!empty($noRevenueServices)) {
    $insights[] = [
        'type' => 'info',
        'icon' => 'info-circle',
        'message' => count($noRevenueServices) . ' service(s) have bookings but no completed revenue',
        'details' => implode(', ', $noRevenueServices) . ' - Focus on converting pending bookings to completed.'
    ];
}

// Top performing service
$topService = null;
$maxRevenue = 0;
foreach ($servicePerformance as $serviceName => $perf) {
    if ($perf['total_revenue'] > $maxRevenue) {
        $maxRevenue = $perf['total_revenue'];
        $topService = $serviceName;
    }
}
if ($topService && $maxRevenue > 0) {
    $topServicePerf = $servicePerformance[$topService];
    $insights[] = [
        'type' => 'success',
        'icon' => 'trophy',
        'message' => 'Top performing service: ' . $topService,
        'details' => '₱' . number_format($maxRevenue, 2) . ' revenue with ' .
                     $topServicePerf['completion_rate'] . '% completion rate'
    ];
}

// Pending bookings insight
$pendingCount = 0;
$inProgressCount = 0;
foreach ($bookings as $booking) {
    $status = $booking['status'] ?? '';
    if ($status === 'Pending') $pendingCount++;
    if ($status === 'In Progress') $inProgressCount++;
}
$totalPending = $pendingCount + $inProgressCount;
if ($totalPending > 10) {
    $insights[] = [
        'type' => 'info',
        'icon' => 'clock-history',
        'message' => $totalPending . ' bookings are still pending or in progress',
        'details' => 'Focus on converting these bookings to completed status to increase revenue.'
    ];
}

// Low booking services
$lowBookingServices = [];
foreach ($servicePerformance as $serviceName => $perf) {
    if ($perf['total_bookings'] <= 2 && $perf['total_bookings'] > 0) {
        $lowBookingServices[] = $serviceName;
    }
}
if (!empty($lowBookingServices) && count($lowBookingServices) <= 5) {
    $insights[] = [
        'type' => 'warning',
        'icon' => 'megaphone',
        'message' => count($lowBookingServices) . ' service(s) have very few bookings',
        'details' => implode(', ', $lowBookingServices) . ' - Consider increasing marketing efforts.'
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
        }
        .content-card > h2:first-child i {
            color: var(--green-600);
            font-size: 1.35rem;
        }
        .header-card {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 50%, #1e3a8a 100%);
            border-radius: 16px;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3);
            position: relative;
            overflow: hidden;
        }
        .header-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 8s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        .header-content {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 2rem;
        }
        .header-title {
            color: white;
            font-size: 2.25rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            letter-spacing: -0.5px;
        }
        .header-subtitle {
            color: rgba(255,255,255,0.95);
            font-size: 1rem;
            font-weight: 400;
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
            position: relative;
            overflow: hidden;
        }
        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        .btn:hover::before {
            width: 300px;
            height: 300px;
        }
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.18);
        }
        .btn:active {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-modern {
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
            position: relative;
            overflow: hidden;
        }
        .btn-modern::before {
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
        .btn-modern:hover::before {
            width: 300px;
            height: 300px;
        }
        .btn-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.18);
        }
        .btn-modern:active {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-primary {
            background: rgba(255,255,255,0.25);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }
        .btn-primary:hover { background: rgba(255,255,255,0.35); }
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        .btn-danger:hover { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); }
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        .filter-card:hover { box-shadow: 0 8px 12px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.12); }
        .filter-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f3f4f6;
        }
        .filter-title i { color: #2563eb; }
        .filter-form {
            padding: 1.5rem;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(34, 197, 94, 0.03));
            border-radius: 16px;
            border: 2px solid rgba(16, 185, 129, 0.1);
            margin-top: 1rem;
        }
        .filter-form .row {
            margin: 0;
            align-items: flex-end;
        }
        .filter-form .row > div {
            padding: 0.5rem;
        }
        .filter-form .row {
            display: flex;
            align-items: flex-end;
            margin: 0;
        }
        .filter-form .col-md-3,
        .filter-form .col-md-6 {
            display: flex;
            flex-direction: column;
        }
        .filter-form .form-label {
            height: 1.5rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }
        .filter-form input[type="date"] {
            height: 40px;
        }
        .filter-form .col-md-6 .d-flex {
            height: 40px;
            justify-content: flex-start;
            align-items: center;
            flex-wrap: nowrap;
            overflow-x: auto;
        }
        .filter-form .col-md-6 .d-flex .btn,
        .filter-form .col-md-6 .d-flex .btn-modern {
            height: 40px;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }
        .filter-form .col-md-6 .d-flex::-webkit-scrollbar {
            height: 4px;
        }
        .filter-form .col-md-6 .d-flex::-webkit-scrollbar-track {
            background: rgba(16, 185, 129, 0.1);
            border-radius: 10px;
        }
        .filter-form .col-md-6 .d-flex::-webkit-scrollbar-thumb {
            background: var(--green-400);
            border-radius: 10px;
        }
        @media (max-width: 768px) {
            .filter-form .row {
                flex-direction: column;
            }
            .filter-form .col-md-3,
            .filter-form .col-md-6 {
            width: 100%;
                max-width: 100%;
            }
            .filter-form .col-md-6 .d-flex {
                flex-wrap: wrap;
                height: auto;
            }
            .filter-form .col-md-6 .d-flex .btn,
            .filter-form .col-md-6 .d-flex .btn-modern {
                height: auto;
            }
        }
        .filter-form input[type="date"] {
            position: relative;
        }
        .filter-form input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: 0;
            position: absolute;
            right: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .form-label {
            font-weight: 600;
            color: var(--green-700);
            margin-bottom: 0.5rem;
            display: block;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }
        .form-label i {
            color: var(--green-600);
            margin-right: 0.25rem;
        }
        .form-control-modern {
            padding: 0.75rem 1rem;
            border: 2px solid var(--green-200);
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
            color: var(--dark);
        }
        .form-control-modern:focus {
            outline: none;
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
            background: white;
        }
        .form-control-modern:hover {
            border-color: var(--green-300);
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
        .trend {
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.5rem;
            color: rgba(255,255,255,0.9);
        }
        .trend.up { color: rgba(255,255,255,0.95); }
        .stat-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: white;
            box-shadow:
                0 8px 20px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }
        .stat-card:hover .stat-icon {
            transform: rotate(5deg) scale(1.1);
            box-shadow:
                0 12px 30px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.4);
        }
        .stat-icon.revenue { background: linear-gradient(135deg, var(--green-400), var(--green-600)); }
        .stat-icon.daily { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
        .stat-icon.weekly { background: linear-gradient(135deg, var(--warning), #d97706); }
        .stat-icon.monthly { background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); }
        .stat-icon.yearly { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .chart-card, .table-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 30px rgba(16, 185, 129, 0.15), 0 4px 15px rgba(5, 150, 105, 0.1);
            border: 2px solid var(--green-100);
            position: relative;
        }
        .chart-card::before, .table-card::before {
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
        .chart-title, .table-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--green-700);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--green-100);
        }
        .chart-title i, .table-title i { color: var(--green-600); }
        .table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: auto;
        }
        .table-modern thead {
            background: linear-gradient(135deg, var(--green-600) 0%, var(--green-700) 50%, var(--green-800) 100%);
            color: white;
            box-shadow:
                0 8px 25px rgba(5, 150, 105, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
            border-top: 4px solid var(--green-400);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-modern thead::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
        }
        .table-modern thead th {
            padding: 1.25rem 1rem;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.8px;
            border: none;
            white-space: nowrap;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            position: relative;
            color: white;
        }
        .table-modern thead th:not(:last-child)::after {
            content: '';
            position: absolute;
            right: 0;
            top: 25%;
            bottom: 25%;
            width: 1px;
            background: rgba(255, 255, 255, 0.25);
        }
        .table-modern thead th:first-child {
            padding-left: 1.5rem;
        }
        .table-modern thead th:last-child {
            padding-right: 1.5rem;
        }
        .table-modern tbody {
            background: white;
        }
        .table-modern tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-bottom: 1px solid rgba(16, 185, 129, 0.08);
            position: relative;
            background: white;
        }
        .table-modern tbody tr:last-child {
            border-bottom: none;
        }
        .table-modern tbody tr:nth-child(even) {
            background: linear-gradient(90deg, rgba(240, 253, 244, 0.2), rgba(255, 255, 255, 0.3));
        }
        .table-modern tbody tr::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, var(--green-400), var(--green-600));
            transform: scaleY(0);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 0 4px 4px 0;
        }
        .table-modern tbody tr:hover {
            background: linear-gradient(90deg,
                rgba(240, 253, 244, 0.6) 0%,
                rgba(220, 252, 231, 0.5) 50%,
                rgba(240, 253, 244, 0.6) 100%);
            transform: translateX(6px);
            box-shadow:
                0 6px 20px rgba(16, 185, 129, 0.15),
                inset 5px 0 0 var(--green-500);
            border-left: 3px solid var(--green-500);
        }
        .table-modern tbody tr:hover::before {
            transform: scaleY(1);
        }
        .table-modern tbody td {
            padding: 1.125rem 1rem;
            vertical-align: middle;
            font-size: 0.9rem;
            color: var(--dark);
            line-height: 1.6;
        }
        .table-modern tbody td:first-child {
            font-weight: 600;
            color: var(--green-700);
            padding-left: 1.5rem;
        }
        .table-modern tbody td:last-child {
            padding-right: 1.5rem;
        }
        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
        }
        .table th {
            padding: 0.875rem 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
            text-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .table th:first-child { border-top-left-radius: 12px; }
        .table th:last-child { border-top-right-radius: 12px; }
        .table td {
            padding: 0.875rem 0.75rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            white-space: normal;
            font-size: 0.875rem;
        }
        .table tbody tr:last-child td { border-bottom: none; }
        .status-badge {
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            letter-spacing: 0.5px;
        }
        .status-badge.circular {
            padding: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: 600;
            min-width: 32px;
        }
        .status-pending { background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); color: white; }
        .status-pending.circular { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .status-inprogress, .status-in-progress { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); color: white; }
        .status-completed { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
        .status-completed.circular { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .status-cancelled { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
        .status-cancelled.circular { background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%); }

        /* Service Performance Table - Modern Elegant Design */
        .table-service-performance {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow:
                0 10px 40px rgba(245, 158, 11, 0.12),
                0 4px 15px rgba(245, 158, 11, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            border: none;
            table-layout: fixed;
        }
        .table-service-performance thead {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%);
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-service-performance thead th {
            padding: 1.5rem 1.25rem;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1.2px;
            border: none;
            border-right: 1px solid rgba(255, 255, 255, 0.15);
            white-space: nowrap;
            text-shadow: 0 1px 3px rgba(0,0,0,0.2);
            color: white;
            text-align: left;
            vertical-align: middle;
            background: transparent;
        }
        .table-service-performance thead th:last-child {
            border-right: none;
        }
        .table-service-performance thead th:nth-child(1) {
            text-align: left;
            padding-left: 2rem;
        }
        .table-service-performance thead th:nth-child(2),
        .table-service-performance thead th:nth-child(3),
        .table-service-performance thead th:nth-child(4),
        .table-service-performance thead th:nth-child(5),
        .table-service-performance thead th:nth-child(6),
        .table-service-performance thead th:nth-child(7),
        .table-service-performance thead th:nth-child(8) {
            text-align: center;
        }
        .table-service-performance thead th:last-child {
            padding-right: 2rem;
        }
        .table-service-performance tbody {
            background: white;
        }
        .table-service-performance tbody tr {
            transition: all 0.25s ease;
            border-bottom: 1px solid rgba(245, 158, 11, 0.08);
            position: relative;
            background: white;
        }
        .table-service-performance tbody tr:last-child {
            border-bottom: none;
        }
        .table-service-performance tbody tr:nth-child(even) {
            background: rgba(254, 243, 199, 0.15);
        }
        .table-service-performance tbody tr:hover {
            background: linear-gradient(90deg,
                rgba(254, 243, 199, 0.4) 0%,
                rgba(253, 230, 138, 0.3) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
        }
        .table-service-performance tbody td {
            padding: 1.5rem 1.25rem;
            vertical-align: middle;
            font-size: 0.95rem;
            color: #1f2937;
            line-height: 1.5;
            text-align: left;
            border: none;
            border-right: 1px solid rgba(245, 158, 11, 0.08);
            background: transparent;
        }
        .table-service-performance tbody td:last-child {
            border-right: none;
        }
        .table-service-performance tbody td:nth-child(1) {
            font-weight: 600;
            color: #d97706;
            padding-left: 2rem;
            text-align: left;
        }
        .table-service-performance tbody td:nth-child(2),
        .table-service-performance tbody td:nth-child(3),
        .table-service-performance tbody td:nth-child(4),
        .table-service-performance tbody td:nth-child(5),
        .table-service-performance tbody td:nth-child(6),
        .table-service-performance tbody td:nth-child(7),
        .table-service-performance tbody td:nth-child(8) {
            text-align: center;
        }
        .table-service-performance tbody td:last-child {
            padding-right: 2rem;
        }
        .table-service-performance tbody td .status-badge.circular,
        .table-service-performance tbody td .badge {
            display: inline-block;
            margin: 0;
        }

        /* Top Customers Table - Modern Elegant Design */
        .table-top-customers {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow:
                0 10px 40px rgba(59, 130, 246, 0.12),
                0 4px 15px rgba(59, 130, 246, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            border: none;
            table-layout: fixed;
        }
        .table-top-customers thead {
            background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 50%, #2563eb 100%);
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-top-customers thead th {
            padding: 1.5rem 1.25rem;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1.2px;
            border: none;
            border-right: 1px solid rgba(255, 255, 255, 0.15);
            white-space: nowrap;
            text-shadow: 0 1px 3px rgba(0,0,0,0.2);
            color: white;
            text-align: left;
            vertical-align: middle;
            background: transparent;
        }
        .table-top-customers thead th:last-child {
            border-right: none;
        }
        .table-top-customers thead th:nth-child(1),
        .table-top-customers thead th:nth-child(4),
        .table-top-customers thead th:nth-child(5) {
            text-align: center;
        }
        .table-top-customers thead th:nth-child(2),
        .table-top-customers thead th:nth-child(3) {
            text-align: left;
        }
        .table-top-customers thead th:first-child {
            padding-left: 2rem;
        }
        .table-top-customers thead th:last-child {
            padding-right: 2rem;
        }
        .table-top-customers tbody {
            background: white;
        }
        .table-top-customers tbody tr {
            transition: all 0.25s ease;
            border-bottom: 1px solid rgba(59, 130, 246, 0.08);
            position: relative;
            background: white;
        }
        .table-top-customers tbody tr:last-child {
            border-bottom: none;
        }
        .table-top-customers tbody tr:nth-child(even) {
            background: rgba(219, 234, 254, 0.15);
        }
        .table-top-customers tbody tr:hover {
            background: linear-gradient(90deg,
                rgba(219, 234, 254, 0.4) 0%,
                rgba(191, 219, 254, 0.3) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        }
        .table-top-customers tbody td {
            padding: 1.5rem 1.25rem;
            vertical-align: middle;
            font-size: 0.95rem;
            color: #1f2937;
            line-height: 1.5;
            text-align: left;
            border: none;
            border-right: 1px solid rgba(59, 130, 246, 0.08);
            background: transparent;
        }
        .table-top-customers tbody td:last-child {
            border-right: none;
        }
        .table-top-customers tbody td:nth-child(1),
        .table-top-customers tbody td:nth-child(4),
        .table-top-customers tbody td:nth-child(5) {
            text-align: center;
        }
        .table-top-customers tbody td:nth-child(1) {
            font-weight: 600;
            color: #2563eb;
            padding-left: 2rem;
        }
        .table-top-customers tbody td:last-child {
            padding-right: 2rem;
        }
        .table-top-customers tbody td .badge {
            display: inline-block;
            margin: 0;
        }

        /* All Bookings Table - Modern Elegant Design */
        .table-all-bookings {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow:
                0 10px 40px rgba(16, 185, 129, 0.12),
                0 4px 15px rgba(16, 185, 129, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            border: none;
            table-layout: fixed;
        }
        .table-all-bookings thead {
            background: linear-gradient(135deg, var(--green-500) 0%, var(--green-600) 50%, var(--green-700) 100%);
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-all-bookings thead th {
            padding: 1.5rem 1.25rem;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1.2px;
            border: none;
            border-right: 1px solid rgba(255, 255, 255, 0.15);
            white-space: nowrap;
            text-shadow: 0 1px 3px rgba(0,0,0,0.2);
            color: white;
            text-align: left;
            vertical-align: middle;
            background: transparent;
        }
        .table-all-bookings thead th:last-child {
            border-right: none;
        }
        .table-all-bookings thead th:nth-child(1),
        .table-all-bookings thead th:nth-child(6),
        .table-all-bookings thead th:nth-child(7),
        .table-all-bookings thead th:nth-child(8),
        .table-all-bookings thead th:nth-child(9) {
            text-align: center;
        }
        .table-all-bookings thead th:nth-child(2),
        .table-all-bookings thead th:nth-child(3),
        .table-all-bookings thead th:nth-child(4),
        .table-all-bookings thead th:nth-child(5) {
            text-align: left;
        }
        .table-all-bookings thead th:first-child {
            padding-left: 2rem;
        }
        .table-all-bookings thead th:last-child {
            padding-right: 2rem;
        }
        .table-all-bookings tbody {
            background: white;
        }
        .table-all-bookings tbody tr {
            transition: all 0.25s ease;
            border-bottom: 1px solid rgba(16, 185, 129, 0.08);
            position: relative;
            background: white;
        }
        .table-all-bookings tbody tr:last-child {
            border-bottom: none;
        }
        .table-all-bookings tbody tr:nth-child(even) {
            background: rgba(240, 253, 244, 0.15);
        }
        .table-all-bookings tbody tr:hover {
            background: linear-gradient(90deg,
                rgba(240, 253, 244, 0.4) 0%,
                rgba(220, 252, 231, 0.3) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
        }
        .table-all-bookings tbody td {
            padding: 1.5rem 1.25rem;
            vertical-align: middle;
            font-size: 0.95rem;
            color: #1f2937;
            line-height: 1.5;
            text-align: left;
            border: none;
            border-right: 1px solid rgba(16, 185, 129, 0.08);
            background: transparent;
        }
        .table-all-bookings tbody td:last-child {
            border-right: none;
        }
        .table-all-bookings tbody td:nth-child(1),
        .table-all-bookings tbody td:nth-child(6),
        .table-all-bookings tbody td:nth-child(7),
        .table-all-bookings tbody td:nth-child(8),
        .table-all-bookings tbody td:nth-child(9) {
            text-align: center;
        }
        .table-all-bookings tbody td:nth-child(1) {
            font-weight: 600;
            color: var(--green-700);
            padding-left: 2rem;
        }
        .table-all-bookings tbody td:last-child {
            padding-right: 2rem;
        }
        .table-all-bookings tbody td .status-badge {
            display: inline-block;
            margin: 0;
        }

        .timeframe-card {
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: #f9fafb;
            transition: all 0.3s ease;
        }
        .timeframe-card:hover {
            border-color: #2563eb;
            background: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        }
        .progress-bar {
            height: 10px;
            border-radius: 999px;
            background: #e5e7eb;
            overflow: hidden;
            margin: 0.75rem 0 1rem;
        }
        .progress-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #2563eb 0%, #1e40af 100%);
            transition: width 0.5s ease;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.4;
        }
        .chart-container {
            position: relative;
            height: 400px;
            margin-top: 1rem;
        }
        .timeframe-breakdown {
            margin-top: 1rem;
        }
        .breakdown-item {
            padding: 1rem;
            background: white;
            border-radius: 10px;
            margin-bottom: 0.75rem;
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }
        .breakdown-item:hover {
            border-color: #2563eb;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.1);
        }
        .breakdown-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .breakdown-item-title {
            font-weight: 600;
            color: #111827;
        }
        .breakdown-item-value {
            font-weight: 700;
            color: #2563eb;
            font-size: 1.1rem;
        }
        .timeframe-filter-note {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 0.5rem;
            font-style: italic;
        }
        .timeframe-btn {
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .timeframe-btn.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            border-color: #059669;
        }
        .timeframe-card[style*="display: none"] { display: none !important; }
        .stat-card[style*="display: none"] { display: none !important; }
        .btn-preset {
            padding: 0.625rem 1.25rem;
            background: var(--green-50);
            color: var(--green-700);
            border: 2px solid var(--green-200);
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.08);
        }
        .btn-preset:hover {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            border-color: var(--green-500);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
        }
        .btn-preset.active {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            border-color: var(--green-500);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        .btn-preset i {
            font-size: 1rem;
        }
        .btn-action {
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            white-space: nowrap;
            border: 2px solid transparent;
            cursor: pointer;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
        }
        .btn-action:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        .btn-action i {
            font-size: 1rem;
        }
        .btn-action-primary {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            border-color: var(--green-500);
        }
        .btn-action-primary:hover {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        .btn-action-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-color: #dc2626;
        }
        .btn-action-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }
        .btn-action-export {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-color: #2563eb;
        }
        .btn-action-export:hover {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        .btn-action-success {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            border-color: var(--green-600);
        }
        .btn-action-success:hover {
            background: linear-gradient(135deg, var(--green-700), var(--green-800));
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        .growth-positive { color: #10b981; }
        .growth-negative { color: #ef4444; }
        .search-box {
            padding: 0.75rem 1rem;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            font-size: 0.95rem;
            width: 100%;
            max-width: 400px;
            transition: all 0.2s ease;
        }
        .search-box:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .table-row:hover {
            background: #f9fafb;
        }
        .bg-red-50 { background-color: #fef2f2 !important; }
        .bg-yellow-50 { background-color: #fffbeb !important; }
        .text-red-600 { color: #dc2626 !important; }
        .text-yellow-600 { color: #d97706 !important; }
        .text-green-600 { color: #059669 !important; }
        .text-blue-600 { color: #2563eb !important; }
        .text-gray-500 { color: #6b7280 !important; }
        .text-gray-600 { color: #4b5563 !important; }
        .font-semibold { font-weight: 600 !important; }
        .font-bold { font-weight: 700 !important; }
        .ms-2 { margin-left: 0.5rem !important; }
        .gap-2 { gap: 0.5rem !important; }
        .flex { display: flex !important; }
        .items-center { align-items: center !important; }
        code {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
        }
        .alert {
            border-left: 4px solid;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border-top: none;
            border-right: none;
            border-bottom: none;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .alert:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
        }
        .alert-dismissible {
            padding-right: 3.5rem;
        }
        .btn-close {
            padding: 0.75rem;
            opacity: 0.7;
            transition: all 0.2s ease;
        }
        .btn-close:hover {
            opacity: 1;
            transform: scale(1.1);
        }
        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
            border-left-color: #10b981;
            color: #065f46;
        }
        .alert-success strong {
            color: #047857;
        }
        .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));
            border-left-color: #ef4444;
            color: #991b1b;
        }
        .alert-danger strong {
            color: #dc2626;
        }
        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05));
            border-left-color: #f59e0b;
            color: #92400e;
        }
        .alert-warning strong {
            color: #d97706;
        }
        .alert-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));
            border-left-color: #3b82f6;
            color: #1e40af;
        }
        .alert-info strong {
            color: #2563eb;
        }
        .alert i {
            flex-shrink: 0;
        }
        .btn-modern {
            padding: 0.875rem 2rem;
            border-radius: 16px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow:
                0 8px 25px rgba(0,0,0,0.12),
                0 3px 10px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }
        .btn-modern::before {
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
        .btn-modern:hover::before {
            width: 300px;
            height: 300px;
        }
        .btn-modern:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow:
                0 12px 35px rgba(0,0,0,0.18),
                0 5px 15px rgba(0,0,0,0.12);
        }
        .btn-primary-modern {
            background: linear-gradient(135deg, var(--green-500) 0%, var(--green-600) 50%, var(--green-700) 100%);
            color: white;
            box-shadow:
                0 8px 30px rgba(16, 185, 129, 0.4),
                0 3px 12px rgba(34, 197, 94, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .btn-primary-modern:hover {
            background: linear-gradient(135deg, var(--green-400) 0%, var(--green-500) 50%, var(--green-600) 100%);
            box-shadow:
                0 12px 40px rgba(16, 185, 129, 0.5),
                0 5px 18px rgba(34, 197, 94, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            transform: translateY(-4px) scale(1.03);
        }
        .form-control-modern {
            padding: 0.875rem 1.5rem;
            border-radius: 16px;
            border: 2px solid var(--border);
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(145deg, #ffffff 0%, #fafafa 100%);
            box-shadow:
                0 2px 8px rgba(0, 0, 0, 0.05),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }
        .form-control-modern:focus {
            border-color: var(--green-500);
            box-shadow:
                0 0 0 4px rgba(16, 185, 129, 0.15),
                0 4px 15px rgba(16, 185, 129, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.95);
            outline: none;
            background: #ffffff;
            transform: translateY(-1px);
        }

        /* Quick Preset Button Active State */
        .btn-preset {
            padding: 0.625rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(34, 197, 94, 0.05));
            color: var(--green-700);
            border: 2px solid rgba(16, 185, 129, 0.3);
            display: inline-block;
        }
        .btn-preset:hover {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(34, 197, 94, 0.1));
            border-color: var(--green-500);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .btn-preset.active {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
            border-color: var(--green-600);
            box-shadow:
                0 4px 15px rgba(16, 185, 129, 0.4),
                0 2px 8px rgba(34, 197, 94, 0.3);
            transform: translateY(-2px);
        }
        .btn-preset.active:hover {
            background: linear-gradient(135deg, var(--green-400), var(--green-500));
            box-shadow:
                0 6px 20px rgba(16, 185, 129, 0.5),
                0 3px 10px rgba(34, 197, 94, 0.4);
        }

        /* Filter Form Styling */
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--green-100);
        }
        .filter-form > div {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .filter-form .form-label {
            font-weight: 600;
            color: var(--green-700);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .filter-form .btn-modern {
            width: 100%;
            justify-content: center;
        }

        /* Stats Grid Improvements */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        /* Chart Cards Styling */
        .chart-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(249, 250, 251, 0.9));
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 2px solid var(--green-100);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.1);
            transition: all 0.3s ease;
        }
        .chart-card:hover {
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.2);
            border-color: var(--green-300);
            transform: translateY(-2px);
        }
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--green-700);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .chart-title i {
            color: var(--green-500);
        }
        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
        }

        /* Breakdown Items Styling */
        .breakdown-item {
            background: linear-gradient(135deg, rgba(240, 253, 244, 0.6), rgba(220, 252, 231, 0.4));
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 4px solid var(--green-500);
            transition: all 0.3s ease;
        }
        .breakdown-item:hover {
            background: linear-gradient(135deg, rgba(240, 253, 244, 0.8), rgba(220, 252, 231, 0.6));
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }
        .breakdown-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .breakdown-item-title {
            font-weight: 600;
            color: var(--green-700);
            font-size: 0.95rem;
        }
        .breakdown-item-value {
            font-weight: 700;
            color: var(--green-600);
            font-size: 1rem;
        }
        .progress-bar {
            height: 8px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--green-500), var(--green-600));
            border-radius: 4px;
            transition: width 0.6s ease;
        }

        /* Empty State Styling */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-gray);
        }
        .empty-state i {
            font-size: 3rem;
            color: var(--green-300);
            margin-bottom: 1rem;
            opacity: 0.6;
        }
        .empty-state p {
            font-size: 1rem;
            color: var(--text-gray);
            margin: 0;
        }

        /* Timeframe Breakdown Styling */
        .timeframe-breakdown {
            margin-top: 1rem;
        }

        /* Quick Presets Container */
        .quick-presets-container {
            padding: 1rem;
            background: linear-gradient(135deg, rgba(240, 253, 244, 0.5), rgba(220, 252, 231, 0.3));
            border-radius: 12px;
            border: 2px solid var(--green-100);
            margin-bottom: 1.5rem;
        }

        /* Improved Responsive Design */
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .chart-container {
                height: 250px;
            }
            .breakdown-item-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }

        /* Modal Styles */
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

        .modal-header-modern {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            padding: 2rem;
            border: none;
        }

        .modal-header-modern .btn-close {
            filter: brightness(0) invert(1);
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
            .page-title {
                font-size: 1.5rem;
            }
        }
        /* Content Card - Light Green (Enhanced) */
        .content-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 8px 30px rgba(16, 185, 129, 0.15), 0 4px 15px rgba(5, 150, 105, 0.1);
            margin-bottom: 2rem;
            width: 100%;
            max-width: 100%;
            overflow: hidden;
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
        }
        @keyframes gradient-flow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .content-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 50px rgba(16, 185, 129, 0.18), 0 6px 20px rgba(5, 150, 105, 0.12);
            border-color: var(--green-300);
        }
        .content-card > h2 {
            margin-top: 0;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--green-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .content-card > h2 i {
            color: var(--green-600);
            font-size: 1.35rem;
        }
        .top-bar {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(5, 150, 105, 0.3);
            border-bottom: 3px solid var(--green-400);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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
        .top-bar .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
            color: white;
        }
        .top-bar .page-title i {
            font-size: 2rem;
            color: var(--green-200);
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
                <p>Sales Reports</p>
            </div>
            <div class="user-section">
                <a href="dashboard.php" class="btn btn-modern btn-primary-modern w-100 mb-2" style="background: linear-gradient(135deg, var(--green-500), var(--green-600)); color: white; border: none; padding: 0.75rem 2rem; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>

            </div>
                <div class="nav-item">
                    <a href="sales_report.php" class="nav-link active">
                        <i class="bi bi-graph-up-arrow"></i>
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
                    <span>Sales Report</span>
                </h1>
            </div>

            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show content-card" role="alert">
                <i class="bi bi-check-circle me-2"></i><?= h($_GET['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show content-card" role="alert">
                <i class="bi bi-exclamation-circle me-2"></i><?= h($_GET['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="content-card">
                <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                    <i class="bi bi-calendar3 me-2"></i>Filter by Date Range
                </h2>
        <div class="mb-4">
            <p class="mb-3" style="font-weight: 600; color: var(--green-700); font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="bi bi-calendar-event" style="color: var(--green-600); font-size: 1.1rem;"></i>
                <span>Quick Presets:</span>
            </p>
            <div class="d-flex flex-wrap" style="gap: 0.75rem;">
                <?php
                $todayStart = date('Y-m-d');
                $todayEnd = date('Y-m-d');
                $isToday = ($fromDate === $todayStart && $toDate === $todayEnd);
                ?>
                <?php
                // Preserve status filter in preset links
                $statusParam = $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '';
                // Only activate date presets if show_all is not active
                $isActivePreset = !$showAll;
                ?>
                    <a href="?from_date=<?= $todayStart ?>&to_date=<?= $todayEnd ?><?= $statusParam ?>" class="btn-preset <?= ($isToday && $isActivePreset) ? 'active' : '' ?>">
                        <span>Today</span>
                    </a>
                <?php
                $weekStart = date('Y-m-d', strtotime('monday this week'));
                $weekEnd = date('Y-m-d', strtotime('sunday this week'));
                $isThisWeek = ($fromDate === $weekStart && $toDate === $weekEnd);
                ?>
                <a href="?from_date=<?= $weekStart ?>&to_date=<?= $weekEnd ?><?= $statusParam ?>" class="btn-preset <?= ($isThisWeek && $isActivePreset) ? 'active' : '' ?>">
                    <span>This Week</span>
                </a>
                <?php
                $monthStart = date('Y-m-01');
                $monthEnd = date('Y-m-t');
                $isThisMonth = ($fromDate === $monthStart && $toDate === $monthEnd);
                ?>
                <a href="?from_date=<?= $monthStart ?>&to_date=<?= $monthEnd ?><?= $statusParam ?>" class="btn-preset <?= ($isThisMonth && $isActivePreset) ? 'active' : '' ?>">
                    <span>This Month</span>
                </a>
                <?php
                $yearStart = date('Y-01-01');
                $yearEnd = date('Y-12-31');
                $isThisYear = ($fromDate === $yearStart && $toDate === $yearEnd);
                ?>
                <a href="?from_date=<?= $yearStart ?>&to_date=<?= $yearEnd ?><?= $statusParam ?>" class="btn-preset <?= ($isThisYear && $isActivePreset) ? 'active' : '' ?>">
                    <span>This Year</span>
                </a>
                <?php
                $last7Start = date('Y-m-d', strtotime('-7 days'));
                $last7End = date('Y-m-d');
                $isLast7 = ($fromDate === $last7Start && $toDate === $last7End);
                ?>
                <a href="?from_date=<?= $last7Start ?>&to_date=<?= $last7End ?><?= $statusParam ?>" class="btn-preset <?= ($isLast7 && $isActivePreset) ? 'active' : '' ?>">
                    <span>Last 7 Days</span>
                </a>
                <?php
                $last30Start = date('Y-m-d', strtotime('-30 days'));
                $last30End = date('Y-m-d');
                $isLast30 = ($fromDate === $last30Start && $toDate === $last30End);
                ?>
                <a href="?from_date=<?= $last30Start ?>&to_date=<?= $last30End ?><?= $statusParam ?>" class="btn-preset <?= ($isLast30 && $isActivePreset) ? 'active' : '' ?>">
                    <span>Last 30 Days</span>
                </a>
                <?php
                $showAll = isset($_GET['show_all']) && $_GET['show_all'] == '1';
                ?>
                <a href="?show_all=1&status=all" class="btn-preset <?= $showAll ? 'active' : '' ?>">
                    <i class="bi bi-grid-3x3-gap"></i>
                    <span>All Sales</span>
                </a>
                </div>
                    </div>
        <div class="mb-4">
            <p class="mb-3" style="font-weight: 600; color: var(--green-700); font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="bi bi-funnel-fill" style="color: var(--green-600); font-size: 1.1rem;"></i>
                <span>Filter by Status:</span>
            </p>
            <div class="d-flex flex-wrap" style="gap: 0.75rem;">
                <?php
                $statuses = ['all' => 'All Statuses', 'Pending' => 'Pending', 'In Progress' => 'In Progress', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled'];
                foreach ($statuses as $status => $label):
                    $isActive = ($statusFilter === $status);
                    $statusIcon = [
                        'all' => 'grid-3x3-gap',
                        'Pending' => 'clock',
                        'In Progress' => 'arrow-repeat',
                        'Completed' => 'check-circle',
                        'Cancelled' => 'x-circle'
                    ][$status] ?? 'circle';
                ?>
                    <a href="?from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&status=<?= urlencode($status) ?>"
                       class="btn-preset <?= $isActive ? 'active' : '' ?>">
                        <i class="bi bi-<?= $statusIcon ?>"></i>
                        <span><?= h($label) ?></span>
                    </a>
                <?php endforeach; ?>
                    </div>
                    </div>
        <div class="mb-4">
            <p class="mb-3" style="font-weight: 600; color: var(--green-700); font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="bi bi-gear" style="color: var(--green-600); font-size: 1.1rem;"></i>
                <span>Actions:</span>
            </p>
            <form method="get" style="display: contents;">
                        <input type="hidden" name="from_date" value="<?= h($fromDate) ?>">
                        <input type="hidden" name="to_date" value="<?= h($toDate) ?>">
                <?php if ($showAll): ?>
                    <input type="hidden" name="show_all" value="1">
                <?php endif; ?>
                <?php if ($statusFilter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                <?php endif; ?>
                <div class="d-flex flex-wrap" style="gap: 0.75rem;">
                    <button type="submit" class="btn-action btn-action-primary">
                        <i class="bi bi-funnel-fill"></i>
                        <span>Filter</span>
                    </button>
                    <a href="?show_all=1&status=all" class="btn-action btn-action-danger">
                        <i class="bi bi-arrow-clockwise"></i>
                        <span>Reset</span>
                    </a>
                    <button type="button" onclick="downloadPDF(this)" class="btn-action btn-action-export">
                        <i class="bi bi-download"></i>
                        <span>PDF</span>
                    </button>
                    <button type="button" onclick="exportToExcel()" class="btn-action btn-action-success">
                        <i class="bi bi-file-earmark-spreadsheet"></i>
                        <span>Excel</span>
                    </button>
                    </div>
                </form>
        </div>
            </div>


            <div class="content-card">
                <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700); cursor: pointer;" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none';">
                    <i class="bi bi-info-circle me-2"></i>Report Info
                </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm" style="display: none;">
            <div><strong>Date Range:</strong> <?= h($fromDate) ?> to <?= h($toDate) ?></div>
            <div><strong>Total Bookings in DB:</strong> <?= $totalBookingsInDB ?></div>
            <div><strong>Bookings in Range:</strong> <?= $bookingsInRange ?> <?= $statusFilter !== 'all' ? '(' . $statusFilter . ')' : '(All Statuses)' ?></div>
            <div><strong>Total Revenue (Completed):</strong> ₱<?= number_format($totalRevenue, 2) ?></div>
            <?php if ($showAll): ?>
            <div class="col-span-2"><strong>View Mode:</strong> <span style="color: var(--green-600); font-weight: 700;">All Timeframes & All Statuses</span></div>
            <?php endif; ?>
        </div>
    </div>

            <!-- Statistics -->
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
                    <h2 class="mb-0" style="font-size: 1.5rem; color: var(--green-700);">
                        <i class="bi bi-bar-chart me-2"></i>Sales Statistics
                    </h2>
                    <?php if ($showAll): ?>
                        <div class="d-flex align-items-center gap-2" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(34, 197, 94, 0.05)); padding: 0.625rem 1.25rem; border-radius: 12px; border: 2px solid rgba(16, 185, 129, 0.2);">
                            <i class="bi bi-eye-fill" style="color: var(--green-600); font-size: 1.1rem;"></i>
                            <span style="color: var(--green-700); font-weight: 600; font-size: 0.9rem;">Showing All Sales</span>
                        </div>
                    <?php endif; ?>
                </div>
            <div class="stats-grid">
                <div class="stat-card" data-timeframe="all">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 style="color: white;">₱<?= number_format($totalRevenue, 0) ?></h3>
                            <p class="mb-0">Total Sales (Filtered - Completed Only)</p>
                            <small class="trend">Range: <?= h($fromDate) ?> to <?= h($toDate) ?></small>
                            </div>
                        <div class="stat-icon revenue"><i class="bi bi-currency-dollar"></i></div>
                        </div>
                    </div>
                <?php
                // Determine which timeframes to show based on the selected preset
                $todayStart = date('Y-m-d');
                $todayEnd = date('Y-m-d');
                $weekStart = date('Y-m-d', strtotime('monday this week'));
                $weekEnd = date('Y-m-d', strtotime('sunday this week'));
                $monthStart = date('Y-m-01');
                $monthEnd = date('Y-m-t');
                $yearStart = date('Y-01-01');
                $yearEnd = date('Y-12-31');

                // Ensure $showAll is defined
                $showAll = isset($_GET['show_all']) && $_GET['show_all'] == '1';

                $showTimeframesStats = [];

                // If "All" button is clicked, show all timeframes
                if ($showAll) {
                    $showTimeframesStats = ['daily', 'weekly', 'monthly', 'yearly'];
                } else {
                    // Check which preset is active
                    if ($fromDate === $todayStart && $toDate === $todayEnd) {
                        // Today - show only daily
                        $showTimeframesStats = ['daily'];
                    } elseif ($fromDate === $weekStart && $toDate === $weekEnd) {
                        // This Week - show only weekly
                        $showTimeframesStats = ['weekly'];
                    } elseif ($fromDate === $monthStart && $toDate === $monthEnd) {
                        // This Month - show only monthly
                        $showTimeframesStats = ['monthly'];
                    } elseif ($fromDate === $yearStart && $toDate === $yearEnd) {
                        // This Year - show only yearly
                        $showTimeframesStats = ['yearly'];
                    } else {
                        // Custom date range - determine by date range length
                        $dateDiff = (strtotime($toDate) - strtotime($fromDate)) / (60 * 60 * 24);
                        if ($dateDiff == 0) {
                            $showTimeframesStats = ['daily'];
                        } elseif ($dateDiff <= 7) {
                            $showTimeframesStats = ['daily', 'weekly'];
                        } elseif ($dateDiff <= 31) {
                            $showTimeframesStats = ['daily', 'weekly', 'monthly'];
                        } else {
                            $showTimeframesStats = ['daily', 'weekly', 'monthly', 'yearly'];
                        }
                    }
                }

                $timeframeIcons = ['daily' => 'sun', 'weekly' => 'calendar-week', 'monthly' => 'calendar3', 'yearly' => 'calendar'];
                $timeframeClasses = ['daily' => 'warning', 'weekly' => 'warning', 'monthly' => 'info', 'yearly' => 'primary'];
                foreach ($timeframes as $key => $tf):
                    if (!in_array($key, $showTimeframesStats)) continue;
                    $icon = $timeframeIcons[$key] ?? 'calendar';
                    $cardClass = $timeframeClasses[$key] ?? 'primary';
                ?>
                    <div class="stat-card <?= $cardClass ?>" data-timeframe="<?= $key ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 style="color: white;">₱<?= number_format($tf['total'], 0) ?></h3>
                                <p class="mb-0"><?= h($tf['label']) ?></p>
                                <?php if ($key === 'daily' && !$showAll): ?>
                                    <small class="trend">Date: <?= h($tf['start']) ?></small>
                                    <?php
                                    $dailyBookingsCount = 0;
                                    foreach ($tf['breakdown'] as $breakdown) {
                                        $dailyBookingsCount += $breakdown['bookings'];
                                    }
                                    ?>
                                    <small class="trend d-block">Bookings: <?= $dailyBookingsCount ?></small>
                                <?php elseif ($key === 'daily' && $showAll): ?>
                                    <?php
                                    $totalBookingsCount = 0;
                                    foreach ($tf['breakdown'] as $breakdown) {
                                        $totalBookingsCount += $breakdown['bookings'];
                                    }
                                    ?>
                                    <small class="trend">Total Bookings: <?= $totalBookingsCount ?></small>
                                    <small class="trend d-block">All Completed Sales</small>
                                <?php endif; ?>
                                <?php if (($pct = framePercentage($key, $timeframes)) !== null): ?>
                                    <small class="trend d-block"><?= $pct ?>% of <?= ucfirst($tf['pctOf']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="stat-icon <?= $key ?>"><i class="bi bi-<?= $icon ?>"></i></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>

            <!-- Actionable Insights -->
            <?php if (!empty($insights)): ?>
            <div class="content-card">
                <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                    <i class="bi bi-lightbulb me-2"></i>Actionable Insights
                </h2>
                <div class="row g-3">
                    <?php foreach ($insights as $insight): ?>
                        <div class="col-md-6">
                            <div class="alert alert-<?= $insight['type'] ?> mb-0 d-flex align-items-start">
                                <i class="bi bi-<?= $insight['icon'] ?> me-2" style="font-size: 1.25rem; margin-top: 0.125rem;"></i>
                <div>
                                    <strong><?= h($insight['message']) ?></strong>
                                    <?php if (!empty($insight['details'])): ?>
                                        <div class="mt-1 small"><?= h($insight['details']) ?></div>
                                    <?php endif; ?>
                    </div>
                    </div>
                </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

    <!-- Charts Section -->
            <div class="content-card">
                <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                    <i class="bi bi-bar-chart me-2"></i>Charts & Analysis
                </h2>
    <div class="chart-card">
                    <h3 class="chart-title"><i class="bi bi-bar-chart"></i> Monthly Sales (Last 12 Months)</h3>
        <div class="chart-container">
            <canvas id="monthlyBarChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
                    <h3 class="chart-title"><i class="bi bi-pie-chart"></i> Booking Status Distribution</h3>
        <div class="chart-container">
            <canvas id="statusPieChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
                    <h3 class="chart-title"><i class="bi bi-bar-chart"></i> Service Performance</h3>
        <div class="chart-container">
            <canvas id="servicePerformanceChart"></canvas>
                    </div>
        </div>
    </div>

    <!-- Timeframe Breakdowns -->
            <?php
            // Determine which timeframes to show based on the selected preset
            $todayStart = date('Y-m-d');
            $todayEnd = date('Y-m-d');
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $weekEnd = date('Y-m-d', strtotime('sunday this week'));
            $monthStart = date('Y-m-01');
            $monthEnd = date('Y-m-t');
            $yearStart = date('Y-01-01');
            $yearEnd = date('Y-12-31');

            // Ensure $showAll is defined (use the same variable from earlier)
            if (!isset($showAll)) {
                $showAll = isset($_GET['show_all']) && $_GET['show_all'] == '1';
            }

            $showTimeframes = [];

            // If "All" button is clicked, show all timeframes
            if ($showAll) {
                $showTimeframes = ['daily', 'weekly', 'monthly', 'yearly'];
            } else {
                // Check which preset is active
                if ($fromDate === $todayStart && $toDate === $todayEnd) {
                    // Today - show only daily
                    $showTimeframes = ['daily'];
                } elseif ($fromDate === $weekStart && $toDate === $weekEnd) {
                    // This Week - show only weekly
                    $showTimeframes = ['weekly'];
                } elseif ($fromDate === $monthStart && $toDate === $monthEnd) {
                    // This Month - show only monthly
                    $showTimeframes = ['monthly'];
                } elseif ($fromDate === $yearStart && $toDate === $yearEnd) {
                    // This Year - show only yearly
                    $showTimeframes = ['yearly'];
                } else {
                    // Custom date range - determine by date range length
                    $dateDiff = (strtotime($toDate) - strtotime($fromDate)) / (60 * 60 * 24);
                    if ($dateDiff == 0) {
                        $showTimeframes = ['daily'];
                    } elseif ($dateDiff <= 7) {
                        $showTimeframes = ['daily', 'weekly'];
                    } elseif ($dateDiff <= 31) {
                        $showTimeframes = ['daily', 'weekly', 'monthly'];
                    } else {
                        $showTimeframes = ['daily', 'weekly', 'monthly', 'yearly'];
                    }
                }
            }
            ?>
    <?php foreach ($timeframes as $key => $tf): ?>
            <?php if (in_array($key, $showTimeframes)): ?>
            <div class="content-card timeframe-card" data-timeframe="<?= $key ?>">
                <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                    <i class="bi bi-<?= $key === 'daily' ? 'sun' : ($key === 'weekly' ? 'calendar-week' : ($key === 'monthly' ? 'calendar3' : 'calendar')) ?> me-2"></i>
                <?= h($tf['label']) ?>
                </h2>
            <div class="timeframe-breakdown">
                <div class="breakdown-item">
                    <div class="breakdown-item-header">
                        <span class="breakdown-item-title">Total Revenue</span>
                        <span class="breakdown-item-value">₱<?= number_format($tf['total'], 2) ?></span>
                    </div>
                    <?php if ($tf['pctOf'] && ($pct = framePercentage($key, $timeframes)) !== null): ?>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= min(100, $pct) ?>%"></div>
                        </div>
                        <div class="text-sm text-gray-600"><?= $pct ?>% of <?= ucfirst($tf['pctOf']) ?> Sales</div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($tf['breakdown'])): ?>
                    <h4 class="text-sm font-semibold text-gray-700 mt-4 mb-2">Service Breakdown:</h4>
                    <?php
                    // Sort breakdown by amount descending
                    uasort($tf['breakdown'], function($a, $b) {
                        return $b['amount'] <=> $a['amount'];
                    });
                    foreach ($tf['breakdown'] as $serviceName => $breakdown): ?>
                        <div class="breakdown-item">
                            <div class="breakdown-item-header">
                                <span class="breakdown-item-title"><?= h($serviceName) ?></span>
                                <span class="breakdown-item-value">₱<?= number_format($breakdown['amount'], 2) ?></span>
                            </div>
                            <div class="text-sm text-gray-600">
                                Bookings: <?= $breakdown['bookings'] ?> |
                                Status: <?php
                                $statusParts = [];
                                foreach ($breakdown['status_counts'] as $status => $count) {
                                    $statusParts[] = "$status ($count)";
                                }
                                echo implode(', ', $statusParts);
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No bookings in this timeframe</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
            <?php endif; ?>
    <?php endforeach; ?>

    <!-- Service Performance Table -->
            <div class="content-card">
                <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                    <i class="bi bi-trophy me-2"></i>Service Performance
                </h2>
                <div class="table-responsive">
                    <table class="table-service-performance">
                <thead>
                    <tr>
                        <th>Service Name</th>
                        <th>Total Bookings</th>
                        <th>Completed</th>
                        <th>Pending</th>
                        <th>Cancelled</th>
                        <th>Total Revenue</th>
                        <th>Avg Revenue</th>
                        <th>Completion Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($servicePerformance)): ?>
                        <?php foreach ($servicePerformance as $serviceName => $perf): ?>
                            <tr class="table-row">
                                <td><?= h($serviceName) ?></td>
                                <td style="text-align: center; font-weight: 600;"><?= $perf['total_bookings'] ?></td>
                                <td style="text-align: center;">
                                    <span class="status-badge status-completed circular"><?= $perf['completed'] ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="status-badge status-pending circular"><?= $perf['pending'] ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="status-badge status-cancelled circular"><?= $perf['cancelled'] ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($perf['total_revenue'] > 0): ?>
                                        <strong style="color: var(--green-600);">₱<?= number_format($perf['total_revenue'], 2) ?></strong>
                                    <?php else: ?>
                                        <span class="status-badge circular" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">0</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;"><strong>₱<?= number_format($perf['avg_revenue'], 2) ?></strong></td>
                                <td style="text-align: center;"><span class="badge <?= $perf['completion_rate'] >= 50 ? 'bg-success' : ($perf['completion_rate'] > 0 ? 'bg-warning' : 'bg-danger') ?>" style="font-weight: 600; padding: 0.5rem 0.75rem;"><?= $perf['completion_rate'] ?>%</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center text-gray-500 py-6">No service data available</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Customers -->
            <div class="content-card">
                <h2 class="mb-3" style="font-size: 1.5rem; color: var(--green-700);">
                    <i class="bi bi-people me-2"></i>Top Customers
                </h2>
                <div class="table-responsive">
                    <table class="table-top-customers">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Customer Name</th>
                        <th>Phone</th>
                        <th>Total Bookings</th>
                        <th>Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($topCustomers)): ?>
                        <?php $rank = 1; foreach ($topCustomers as $customer): ?>
                            <tr class="table-row">
                                <td style="text-align: center;"><strong style="color: var(--green-700);">#<?= $rank ?></strong></td>
                                <td><?= h($customer['name']) ?></td>
                                <td><?= h($customer['phone']) ?></td>
                                <td style="text-align: center;"><span class="badge bg-light text-dark"><?= $customer['bookings'] ?></span></td>
                                <td style="text-align: center;"><strong style="color: var(--green-600);">₱<?= number_format($customer['revenue'], 2) ?></strong></td>
                            </tr>
                        <?php $rank++; endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-gray-500 py-6">No customer data available</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

            <!-- All Bookings Table -->
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
                    <h2 class="mb-0" style="font-size: 1.5rem; color: var(--green-700);">
                        <i class="bi bi-table me-2"></i>All Bookings
                    </h2>
                    <input type="text" id="searchBookings" class="form-control form-control-modern" style="max-width: 400px;" placeholder="Search bookings (customer, service, phone, reference...)">
        </div>
                <div class="table-responsive">
                    <table class="table-all-bookings" id="bookingsTable">
                <thead>
                <tr>
                    <th style="cursor: pointer;" onclick="sortTable(0, 'bookingsTable')">ID <i class="bi bi-arrow-down-up"></i></th>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Price Range</th>
                    <th>Phone</th>
                    <th style="cursor: pointer;" onclick="sortTable(5, 'bookingsTable')">Date <i class="bi bi-arrow-down-up"></i></th>
                    <th>Time</th>
                    <th style="cursor: pointer;" onclick="sortTable(7, 'bookingsTable')">Status <i class="bi bi-arrow-down-up"></i></th>
                    <th>Reference</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($bookings): ?>
                    <?php foreach ($bookings as $row): ?>
                        <tr class="table-row">
                            <td style="text-align: center;"><?= h($row['booking_id']) ?></td>
                            <td><?= h($row['customer_name'] ?? '') ?></td>
                            <td><?= h($row['service_name'] ?? '') ?></td>
                            <td><?= h(formatPriceDisplay($row['price_range'] ?? null, $pdo, $row['service_id'] ?? null)) ?></td>
                            <td><?= h($row['phone_number'] ?? '') ?></td>
                            <td style="text-align: center;"><?= h($row['appointment_date'] ?? '') ?></td>
                            <td style="text-align: center;"><?= h($row['appointment_time'] ?? '') ?></td>
                            <td style="text-align: center;"><span class="status-badge status-<?= strtolower(str_replace(' ', '', $row['status'] ?? 'pending')) ?>"><?= h($row['status'] ?? 'Pending') ?></span></td>
                            <td style="text-align: center;"><code style="background: rgba(16, 185, 129, 0.1); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;"><?= h($row['reference_code'] ?? '') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center text-gray-500 py-6">No bookings in this range</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
            <div id="noResults" class="text-center text-muted py-4" style="display: none;">
                <i class="bi bi-search"></i> No bookings match your search
        </div>
    </div>
            </div>
        </main>
</div>

<script>
const statusData = <?= json_encode($bookingsByStatus ?? [], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const monthlyData = <?= json_encode($monthlySales ?? [], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const servicePerformanceData = <?= json_encode(array_values($servicePerformance ?? []), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;

let sortDirection = {};

document.addEventListener('DOMContentLoaded', () => {
    // Show all timeframes by default (all are always visible now)

    // Search functionality for bookings
    const searchInput = document.getElementById('searchBookings');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#bookingsTable tbody tr');
            const noResults = document.getElementById('noResults');
            let visibleCount = 0;

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        });
    }


    // Service Performance Chart
    const serviceChartEl = document.getElementById('servicePerformanceChart');
    if (serviceChartEl) {
        try {
            const ctx = serviceChartEl.getContext('2d');
            const servicePerf = <?= json_encode($servicePerformance ?? [], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
            const serviceNames = Object.keys(servicePerf).slice(0, 10);
            const topServices = serviceNames.map(name => servicePerf[name]);

            if (serviceNames.length === 0) {
                serviceChartEl.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No service data available</p></div>';
            } else {

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: serviceNames,
                    datasets: [{
                        label: 'Revenue',
                        data: topServices.map(item => parseFloat(item.total_revenue) || 0),
                        backgroundColor: 'rgba(79, 70, 229, 0.9)'
                    }, {
                        label: 'Bookings',
                        data: topServices.map(item => parseInt(item.total_bookings) || 0),
                        backgroundColor: 'rgba(16, 185, 129, 0.9)',
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            position: 'left',
                            ticks: {
                                callback: function(value) {
                                    return Number(value).toLocaleString();
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
            }
        } catch (e) {
            console.error('Error creating service chart:', e);
        }
    }

    // Status color mapping function (shared by both pie and bar charts)
    const getStatusColor = (status) => {
        const statusColors = {
            'pending': '#6b7280',        // Gray
            'inprogress': '#f97316',     // Orange
            'in-progress': '#f97316',    // Orange (with hyphen)
            'completed': '#10b981',      // Green
            'cancelled': '#ef4444'       // Red
        };
        const statusKey = (status || '').toLowerCase().replace(/\s+/g, '');
        return statusColors[statusKey] || '#9ca3af'; // Default gray
    };

    // Status Pie Chart
    const pieChartEl = document.getElementById('statusPieChart');
    if (pieChartEl) {
        try {
            const ctx = pieChartEl.getContext('2d');
            if (!statusData || statusData.length === 0) {
                pieChartEl.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No status data available</p></div>';
            } else {
                const labels = statusData.map(item => item.status || 'Unknown');
                const backgroundColors = labels.map(label => getStatusColor(label));

                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: statusData.map(item => parseInt(item.count) || 0),
                            backgroundColor: backgroundColors,
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { position: 'right' }
                        }
                    }
                });
            }
        } catch (e) {
            console.error('Error creating pie chart:', e);
        }
    }


    // Monthly Bar Chart
    const barChartEl = document.getElementById('monthlyBarChart');
    if (barChartEl) {
        try {
            const ctx = barChartEl.getContext('2d');
            if (!monthlyData || monthlyData.length === 0) {
                barChartEl.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No monthly data available</p></div>';
            } else {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: monthlyData.map(item => item.label || ''),
                        datasets: [{
                            label: 'Revenue',
                            data: monthlyData.map(item => parseFloat(item.value) || 0),
                            backgroundColor: 'rgba(79, 70, 229, 0.9)'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return Number(value).toLocaleString();
                                    }
                                }
                            }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        } catch (e) {
            console.error('Error creating bar chart:', e);
        }
    }
});


        function sortTable(columnIndex, tableId = 'bookingsTable') {
            const table = document.getElementById(tableId);
            if (!table) return;

            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr:not([style*="display: none"])'));

            const sortKey = `${tableId}_${columnIndex}`;
            const isAsc = sortDirection[sortKey] !== 'asc';
            sortDirection[sortKey] = isAsc ? 'asc' : 'desc';

            rows.sort((a, b) => {
                const aText = a.cells[columnIndex]?.textContent.trim() || '';
                const bText = b.cells[columnIndex]?.textContent.trim() || '';

                // Try to parse as number
                const aNum = parseFloat(aText.replace(/[^0-9.-]/g, ''));
                const bNum = parseFloat(bText.replace(/[^0-9.-]/g, ''));

                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return isAsc ? aNum - bNum : bNum - aNum;
                }

                // Try to parse as date
                const aDate = new Date(aText);
                const bDate = new Date(bText);
                if (!isNaN(aDate.getTime()) && !isNaN(bDate.getTime())) {
                    return isAsc ? aDate - bDate : bDate - aDate;
                }

                return isAsc
                    ? aText.localeCompare(bText)
                    : bText.localeCompare(aText);
            });

            rows.forEach(row => tbody.appendChild(row));
        }

        function exportToExcel() {
            const table = document.getElementById('bookingsTable');
            if (!table) {
                alert('No data to export');
                return;
            }

            let csv = [];
            const rows = table.querySelectorAll('tr');

            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');

                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""');
                    row.push('"' + data + '"');
                }

                csv.push(row.join(','));
            }

            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);

            link.setAttribute('href', url);
            link.setAttribute('download', 'Sales_Report_' + new Date().toISOString().slice(0,10) + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        async function downloadPDF(clickedButton) {
            // Find the PDF button that was clicked
            const btn = clickedButton || document.querySelector('button[onclick*="downloadPDF"]');
            if (!btn) {
                alert('Error: Could not find PDF button.');
                return;
            }

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
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span><span>Generating PDF...</span>';
            btn.disabled = true;

            // Hide elements that shouldn't be in PDF
            const sidebar = document.querySelector('.sidebar');
            const topBar = document.querySelector('.top-bar');
            const bgAnimated = document.querySelector('.bg-animated');
            const noPrintElements = document.querySelectorAll('.btn-action, .btn-preset, .sidebar, .bg-animated');

            const originalDisplays = {};
            noPrintElements.forEach((el) => {
                originalDisplays[el] = el.style.display || '';
                el.style.display = 'none';
            });

            try {
                // Get the content to convert
                const container = document.querySelector('.dashboard-container');
                if (!container) {
                    throw new Error('Content container not found');
                }

                // Wait a bit for elements to hide
                await new Promise(resolve => setTimeout(resolve, 200));

                // Use html2canvas to capture the element
                const canvas = await html2canvas(container, {
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
                const fileName = 'Sales_Report_' + new Date().toISOString().slice(0,10) + '.pdf';
                pdf.save(fileName);

                // Restore elements
                noPrintElements.forEach((el) => {
                    el.style.display = originalDisplays[el] || '';
                });

                btn.innerHTML = originalText;
                btn.disabled = false;

            } catch (error) {
                console.error('PDF generation error:', error);

                // Restore elements on error
                noPrintElements.forEach((el) => {
                    el.style.display = originalDisplays[el] || '';
                });

                alert('Error generating PDF: ' + (error.message || 'Unknown error. Please try again.'));
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
</script>
</body>
</html>
