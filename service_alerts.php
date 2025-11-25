<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'pest control');
if ($conn->connect_error) die("DB failed: " . $conn->connect_error);

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
    <title>Service Alerts & Scheduling</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        header { background: white; padding: 25px; border-radius: 15px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        header h1 { color: #333; font-size: 28px; margin-bottom: 10px; }
        .btn { display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 6px; margin-top: 10px; transition: 0.3s; }
        .btn:hover { background: #1976D2; }
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
    <div class="container">
        <header>
            <h1>🔔 Service Alerts & Scheduling Dashboard</h1>
            <p style="color: #666; margin-top: 5px;">Monitor overdue services and upcoming appointments</p>
            <a href="dashboard.php" class="btn">← Back to Dashboard</a>
        </header>

        <!-- Statistics Overview -->
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
                <span>Overdue Services</span>
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
    </div>
</body>
</html>
<?php $conn->close(); ?>

