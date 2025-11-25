<?php
session_start();

$host = 'localhost';
$dbname = 'pest control';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Redirect if not a logged-in customer
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'customer' || !isset($_SESSION['id'])) {
    header("Location: customer_login.php");
    exit();
}

// Fetch user full name
$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$fullName = $userData ? $userData['first_name'] . ' ' . $userData['last_name'] : $_SESSION['username'];

// Determine current rotation period and ingredient
$month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$start_month = (int)date('n');
$start_year = (int)date('Y');
$rotation_period = floor(($start_month - 1) / 3);
$current_period = $month_names[($rotation_period * 3) % 12] . " $start_year - " .
                 $month_names[($rotation_period * 3 + 2) % 12] . " " . ($start_year + floor(($rotation_period * 3 + 2) / 12));
$stmt = $pdo->query("SELECT active_ingredient FROM inventory ORDER BY active_ingredient");
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
$ingredient_count = count($ingredients);
$current_ingredient_index = $rotation_period % $ingredient_count;
$current_rotated_ingredient = $ingredient_count > 0 ? $ingredients[$current_ingredient_index]['active_ingredient'] : null;

// Handle AJAX service detail request
if (isset($_POST['action']) && $_POST['action'] === 'get_service_details' && isset($_POST['service_name'])) {
    $stmt = $pdo->prepare("
        SELECT s.service_id, s.service_name, s.service_type, s.service_details,
               GROUP_CONCAT(si.active_ingredient) as active_ingredients
        FROM services s
        LEFT JOIN service_inventory si ON s.service_name = si.service_name
        WHERE s.service_name = ?
        GROUP BY s.service_id
    ");
    $stmt->execute([$_POST['service_name']]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    $response = [];
    if ($service) {
        $price_stmt = $pdo->prepare("
            SELECT price_range, price
            FROM service_price_ranges
            WHERE service_id = ?
            ORDER BY CAST(SUBSTRING_INDEX(price_range, '-', 1) AS UNSIGNED)
        ");
        $price_stmt->execute([$service['service_id']]);
        $price_ranges = $price_stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'service_id' => $service['service_id'],
            'service_name' => $service['service_name'],
            'service_type' => $service['service_type'],
            'service_details' => $service['service_details'],
            'active_ingredients' => $service['active_ingredients'] ? explode(',', $service['active_ingredients']) : [],
            'price_ranges' => $price_ranges,
            'is_rotated' => $current_rotated_ingredient && in_array(strtolower($current_rotated_ingredient), array_map('strtolower', explode(',', $service['active_ingredients'])))
        ];
    } else {
        $response = ['error' => 'Service not found'];
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle AJAX request to get booked time slots for a date
if (isset($_POST['action']) && $_POST['action'] === 'get_booked_times' && isset($_POST['appointment_date'])) {
    $appointment_date = $_POST['appointment_date'];
    $stmt = $pdo->prepare("
        SELECT appointment_time
        FROM service_bookings
        WHERE appointment_date = ? AND status != 'Cancelled'
    ");
    $stmt->execute([$appointment_date]);
    $booked_times = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    header('Content-Type: application/json');
    echo json_encode(['booked_times' => $booked_times]);
    exit();
}

// Cancel booking
if (isset($_POST['cancel_booking']) && isset($_POST['reference_code'])) {
    $ref = $_POST['reference_code'];
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT service_name FROM service_bookings WHERE reference_code = ? AND id = ?");
        $stmt->execute([$ref, $_SESSION['id']]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            $_SESSION['error'] = "Booking not found or you don't have permission to cancel it.";
            header("Location: booking_form.php");
            exit();
        }

        $cancel = $pdo->prepare("UPDATE service_bookings SET status = 'Cancelled' WHERE reference_code = ? AND id = ?");
        $cancel->execute([$ref, $_SESSION['id']]);

        $stmt = $pdo->prepare("SELECT active_ingredient, stocks_used FROM service_inventory WHERE service_name = ?");
        $stmt->execute([$booking['service_name']]);
        $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($requirements as $req) {
            $restore = $pdo->prepare("UPDATE inventory SET stocks = stocks + ? WHERE LOWER(active_ingredient) = LOWER(?)");
            $restore->execute([$req['stocks_used'], $req['active_ingredient']]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Booking with reference $ref has been cancelled.";
        header("Location: booking_form.php");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to cancel booking: " . $e->getMessage();
        header("Location: booking_form.php");
        exit();
    }
}

// Download receipt
if (isset($_POST['download_receipt']) && isset($_POST['reference_code'])) {
    $ref = $_POST['reference_code'];
    $stmt = $pdo->prepare("
        SELECT sb.reference_code, sb.service_name, sb.service_type, sb.appointment_date, sb.appointment_time,
               sb.price_range, sb.structure_types, sb.status, sb.customer_name, sb.address, sb.service_id, sb.email, sb.phone_number
        FROM service_bookings sb
        WHERE sb.reference_code = ? AND sb.id = ?
    ");
    $stmt->execute([$ref, $_SESSION['id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking || $booking['status'] === 'Cancelled') {
        $_SESSION['error'] = "Booking not found, cancelled, or you don't have permission to access it.";
        header("Location: booking_form.php");
        exit();
    }

    $receipt = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Booking Receipt - {$booking['reference_code']}</title>
        <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap' rel='stylesheet'>
        <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css' rel='stylesheet'>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Poppins', 'Inter', sans-serif; 
                max-width: 900px; 
                margin: 0 auto; 
                padding: 20px; 
                background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 25%, #2d7a47 50%, #22c55e 75%, #16a34a 100%);
                background-size: 400% 400%;
                min-height: 100vh;
            }
            .receipt { 
                border: none; 
                padding: 0; 
                border-radius: 24px; 
                background: white; 
                box-shadow: 0 25px 50px rgba(0,0,0,0.3);
                overflow: hidden;
            }
            .receipt-header {
                background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 50%, #22c55e 100%);
                color: white;
                padding: 3rem 2.5rem;
                text-align: center;
                position: relative;
                overflow: hidden;
            }
            .receipt-header::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 5px;
                background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
                background-size: 300% 100%;
                animation: gradientMove 3s ease infinite;
            }
            .company-logo {
                max-width: 200px;
                height: auto;
                margin-bottom: 1rem;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            }
            .receipt-header h1 {
                font-size: 2.5rem;
                font-weight: 800;
                margin-bottom: 0.5rem;
                text-shadow: 0 4px 8px rgba(0,0,0,0.3);
            }
            .receipt-header .subtitle {
                font-size: 1.1rem;
                opacity: 0.95;
                font-weight: 300;
            }
            .receipt-body {
                padding: 3rem 2.5rem;
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(240, 253, 244, 0.95) 100%);
            }
            .receipt-info {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.5rem;
                margin-bottom: 2rem;
                padding: 1.5rem;
                background: rgba(255, 255, 255, 0.8);
                border-radius: 16px;
                border: 2px solid #dcfce7;
            }
            .info-section {
                margin-bottom: 2rem;
            }
            .info-section h2 {
                color: #0f4c3a;
                font-size: 1.3rem;
                font-weight: 700;
                margin-bottom: 1rem;
                padding-bottom: 0.5rem;
                border-bottom: 3px solid #22c55e;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            .info-section h2 i {
                color: #22c55e;
            }
            .detail-row {
                display: flex;
                justify-content: space-between;
                padding: 0.75rem 0;
                border-bottom: 1px solid #e5e7eb;
            }
            .detail-row:last-child {
                border-bottom: none;
            }
            .detail-label {
                font-weight: 600;
                color: #374151;
                font-size: 0.95rem;
            }
            .detail-value {
                color: #1f2937;
                font-weight: 500;
                text-align: right;
                font-size: 0.95rem;
            }
            .status-badge {
                display: inline-block;
                padding: 0.5rem 1rem;
                border-radius: 50px;
                font-size: 0.85rem;
                font-weight: 700;
                text-transform: uppercase;
            }
            .status-pending {
                background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
                color: #92400e;
            }
            .status-confirmed {
                background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
                color: #166534;
            }
            .status-completed {
                background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
                color: #1e40af;
            }
            .status-cancelled {
                background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
                color: #991b1b;
            }
            .receipt-footer {
                background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
                padding: 2rem 2.5rem;
                text-align: center;
                border-top: 3px solid #22c55e;
            }
            .receipt-footer p {
                margin: 0.5rem 0;
                color: #374151;
                font-size: 0.95rem;
            }
            .receipt-footer .contact-info {
                margin-top: 1rem;
                font-weight: 600;
                color: #0f4c3a;
            }
            @keyframes gradientMove {
                0% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }
            @media print {
                body { background: white; padding: 0; }
                .receipt { box-shadow: none; }
            }
        </style>
    </head>
    <body>
        <div class='receipt'>
            <div class='receipt-header'>
                <img src='https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png/v1/fit/w_2500,h_1330,al_c/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png' 
                     alt='TECHNO PEST Logo' 
                     class='company-logo'>
                <h1><i class='fas fa-receipt'></i> Booking Receipt</h1>
                <p class='subtitle'>Official Service Confirmation Document</p>
            </div>
            <div class='receipt-body'>
                <div class='receipt-info'>
                    <div>
                        <div class='detail-row'>
                            <span class='detail-label'><i class='fas fa-hashtag'></i> Reference Code:</span>
                            <span class='detail-value'><strong>" . htmlspecialchars($booking['reference_code']) . "</strong></span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'><i class='fas fa-calendar'></i> Issue Date:</span>
                            <span class='detail-value'>" . date('F d, Y') . "</span>
                        </div>
                    </div>
                    <div>
                        <div class='detail-row'>
                            <span class='detail-label'><i class='fas fa-info-circle'></i> Status:</span>
                            <span class='detail-value'>
                                <span class='status-badge status-" . strtolower($booking['status']) . "'>" . htmlspecialchars($booking['status']) . "</span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class='info-section'>
                    <h2><i class='fas fa-user'></i> Customer Information</h2>
                    <div class='detail-row'>
                        <span class='detail-label'>Customer Name:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['customer_name']) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Email Address:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['email'] ?? 'N/A') . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Contact Number:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['phone_number'] ?? 'N/A') . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Service Address:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['address']) . "</span>
                    </div>
                </div>

                <div class='info-section'>
                    <h2><i class='fas fa-bug'></i> Service Details</h2>
                    <div class='detail-row'>
                        <span class='detail-label'>Service Name:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['service_name']) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Service Type:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['service_type']) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Property Type:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['structure_types'] ?: 'N/A') . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Price Range:</span>
                        <span class='detail-value'><strong>" . htmlspecialchars(formatPriceRangeDisplay($booking['price_range'], $booking['service_id'] ?? null, $pdo, $booking['service_name'] ?? null)) . "</strong></span>
                    </div>
                </div>

                <div class='info-section'>
                    <h2><i class='fas fa-calendar-alt'></i> Appointment Schedule</h2>
                    <div class='detail-row'>
                        <span class='detail-label'>Appointment Date:</span>
                        <span class='detail-value'><strong>" . htmlspecialchars($booking['appointment_date']) . "</strong></span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Appointment Time:</span>
                        <span class='detail-value'><strong>" . htmlspecialchars($booking['appointment_time']) . "</strong></span>
                    </div>
                </div>
            </div>
            <div class='receipt-footer'>
                <p><strong>Thank you for choosing TECHNO PEST services!</strong></p>
                <p class='contact-info'><i class='fas fa-envelope'></i> support@pestcontrol.com</p>
                <p style='margin-top: 1rem; font-size: 0.85rem; color: #6b7280;'>This is an official receipt. Please keep this document for your records.</p>
            </div>
        </div>
    </body>
    </html>";

    ob_start();
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="receipt_' . $booking['reference_code'] . '.html"');
    header('Content-Length: ' . strlen($receipt));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $receipt;
    ob_end_flush();
    exit();
}

$maxBookingsPerDay = 10;

// Store booking data for confirmation modal
$bookingConfirmation = null;

if (isset($_POST['submit_booking'])) {
    $id = $_SESSION['id'];
    $service_name = $_POST['service_type'];
    $phone = $_POST['phone_number'];
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    // Validate email
    if (empty($email)) {
        $_SESSION['error'] = "Please provide your email address.";
        header("Location: booking_form.php");
        exit();
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please provide a valid email address.";
        header("Location: booking_form.php");
        exit();
    }
    $email = strtolower($email);

    // Combine address fields
    $street = isset($_POST['street']) ? trim($_POST['street']) : '';
    $barangay = isset($_POST['barangay']) ? trim($_POST['barangay']) : '';
    $city = isset($_POST['city']) ? trim($_POST['city']) : '';
    $province = isset($_POST['province']) ? trim($_POST['province']) : '';

    // Validate all address fields are provided
    if (empty($street) || empty($barangay) || empty($city) || empty($province)) {
        $_SESSION['error'] = "Please fill in all address fields (Street, Barangay, City, and Province).";
        header("Location: booking_form.php");
        exit();
    }

    // Capitalize first letter of each word in address fields
    $street = ucwords(strtolower($street));
    $barangay = ucwords(strtolower($barangay));
    $city = ucwords(strtolower($city));
    $province = ucwords(strtolower($province));

    // Combine into full address
    $address = $street . ', ' . $barangay . ', ' . $city . ', ' . $province;

    $appointment = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $price_range = $_POST['price_range'];

    // Check if the selected date has announcements
    $announcement_check = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM announcements
        WHERE announcement_date = ?
    ");
    $announcement_check->execute([$appointment]);
    $announcement_result = $announcement_check->fetch(PDO::FETCH_ASSOC);

    if ($announcement_result && $announcement_result['count'] > 0) {
        $_SESSION['error'] = "The selected date has announcements and cannot be booked. Please choose a different date.";
        header("Location: booking_form.php");
        exit();
    }

    // Validate phone number - must be exactly 11 digits
    $phone = preg_replace('/[^0-9]/', '', $phone); // Remove any non-numeric characters
    if (strlen($phone) !== 11 || !preg_match('/^[0-9]{11}$/', $phone)) {
        $_SESSION['error'] = "Contact number must be exactly 11 digits (e.g., 09123456789).";
        header("Location: booking_form.php");
        exit();
    }

    // Validate appointment time contains AM or PM
    if (!preg_match('/(AM|PM)$/', $appointment_time)) {
        $_SESSION['error'] = "Invalid appointment time format. Please select a time with AM or PM.";
        header("Location: booking_form.php");
        exit();
    }

    // Validate structure type first (needed for reference code)
    $structure_type = isset($_POST['structure_type']) ? htmlspecialchars($_POST['structure_type']) : '';
    if (empty($structure_type)) {
        $_SESSION['error'] = "Please select a structure type.";
        header("Location: booking_form.php");
        exit();
    }
    if ($structure_type === 'Other') {
        $structure_type = isset($_POST['structure_type_other']) ? htmlspecialchars($_POST['structure_type_other']) : '';
        if (empty($structure_type)) {
            $_SESSION['error'] = "Please specify the structure type for 'Other'.";
            header("Location: booking_form.php");
            exit();
        }
    }

    // Check if service exists
    $stmt = $pdo->prepare("SELECT service_id, service_type FROM services WHERE service_name = ?");
    $stmt->execute([$service_name]);
    $service_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$service_data) {
        $_SESSION['error'] = "Selected service does not exist.";
        header("Location: booking_form.php");
        exit();
    }

    // Generate reference code based on service type and structure type
    $service_type_abbr = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $service_data['service_type']), 0, 3));
    if (strlen($service_type_abbr) < 3) {
        $service_type_abbr = str_pad($service_type_abbr, 3, 'X', STR_PAD_RIGHT);
    }

    $structure_type_abbr = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $structure_type), 0, 3));
    if (strlen($structure_type_abbr) < 3) {
        $structure_type_abbr = str_pad($structure_type_abbr, 3, 'X', STR_PAD_RIGHT);
    }

    $year = date('y'); // 2-digit year
    $unique_id = strtoupper(substr(uniqid(), -6)); // Last 6 characters of uniqid

    $reference_code = $service_type_abbr . '-' . $structure_type_abbr . '-' . $year . '-' . $unique_id;

    // Validate price range
    if (!preg_match('/^(.+?)\s+SQM\s*=\s*(.+?)\s+PHP$/', $price_range, $matches)) {
        $_SESSION['error'] = "Invalid price range format.";
        header("Location: booking_form.php");
        exit();
    }

    $selected_range = trim($matches[1]);
    $selected_price = trim(str_replace(',', '', $matches[2]));

    $price_stmt = $pdo->prepare("
        SELECT price_range, price
        FROM service_price_ranges
        WHERE service_id = ? AND price_range = ?
    ");
    $price_stmt->execute([$service_data['service_id'], $selected_range]);
    $valid_price = $price_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$valid_price) {
        $_SESSION['error'] = "Invalid price range selected for the service.";
        header("Location: booking_form.php");
        exit();
    }

    if (number_format($valid_price['price'], 0) != number_format($selected_price, 0)) {
        $_SESSION['error'] = "Price mismatch. Please select a valid price range.";
        header("Location: booking_form.php");
        exit();
    }

    // Check daily limit
    $checkBookings = $pdo->prepare("SELECT COUNT(*) FROM service_bookings WHERE appointment_date = ?");
    $checkBookings->execute([$appointment]);
    if ($checkBookings->fetchColumn() >= $maxBookingsPerDay) {
        $_SESSION['error'] = "The selected date is fully booked. Please choose another date.";
        header("Location: booking_form.php");
        exit();
    }

    // Check inventory for stock and expiration
    $stmt = $pdo->prepare("SELECT active_ingredient, stocks_used FROM service_inventory WHERE service_name = ?");
    $stmt->execute([$service_name]);
    $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insufficient = [];
    $expired = [];
    $debug_info = [];
    $now = new DateTime();
    foreach ($requirements as $req) {
        $check = $pdo->prepare("SELECT stocks, expiry_date FROM inventory WHERE LOWER(active_ingredient) = LOWER(?)");
        $check->execute([$req['active_ingredient']]);
        $inventory_item = $check->fetch(PDO::FETCH_ASSOC);

        if ($inventory_item === false) {
            $insufficient[] = $req['active_ingredient'];
            $debug_info[] = "{$req['active_ingredient']}: Not found in inventory";
        } elseif ($inventory_item['stocks'] < $req['stocks_used']) {
            $insufficient[] = $req['active_ingredient'];
            $debug_info[] = "{$req['active_ingredient']}: Available={$inventory_item['stocks']}, Required={$req['stocks_used']}";
        } elseif (!empty($inventory_item['expiry_date'])) {
            $expiry = new DateTime($inventory_item['expiry_date']);
            if ($expiry < $now) {
                $expired[] = $req['active_ingredient'];
                $debug_info[] = "{$req['active_ingredient']}: Expired on {$inventory_item['expiry_date']}";
            }
        }
    }

    if (!empty($insufficient)) {
        $error_message = "Sorry, we cannot process your booking due to insufficient stock for: " . implode(", ", $insufficient) . ". Please try another service or contact support at support@pestcontrol.com.";
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
            $error_message .= "<br><strong>Debug Info:</strong> " . implode("; ", $debug_info);
        }
        $_SESSION['error'] = $error_message;
        header("Location: booking_form.php");
        exit();
    }

    if (!empty($expired)) {
        $error_message = "Sorry, we cannot process your booking because the following items are expired: " . implode(", ", $expired) . ". Please try another service or contact support at support@pestcontrol.com.";
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
            $error_message .= "<br><strong>Debug Info:</strong> " . implode("; ", $debug_info);
        }
        $_SESSION['error'] = $error_message;
        header("Location: booking_form.php");
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Check if the time slot is already booked for this date
        $check_time_stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM service_bookings
            WHERE appointment_date = ? AND appointment_time = ? AND status != 'Cancelled'
        ");
        $check_time_stmt->execute([$appointment, $appointment_time]);
        $time_check = $check_time_stmt->fetch(PDO::FETCH_ASSOC);

        if ($time_check && $time_check['count'] > 0) {
            $pdo->rollBack();
            $_SESSION['error'] = "The selected time slot ($appointment_time) is already booked for this date. Please choose a different time.";
            header("Location: booking_form.php");
            exit();
        }

        $service_id = $service_data['service_id'];
        $service_type = $service_data['service_type'];
        $formatted_price_range = $selected_range . ' SQM = ₱' . number_format($valid_price['price'], 0) . ' PHP';

        $insert = $pdo->prepare("
            INSERT INTO service_bookings
            (id, service_id, phone_number, email, address, appointment_date, appointment_time, reference_code, customer_name, price_range, status, service_type, structure_types, service_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)
        ");
        $insert->execute([
            $id, $service_id, $phone, $email, $address, $appointment, $appointment_time,
            $reference_code, $fullName, $formatted_price_range, $service_type, $structure_type, $service_name
        ]);

        // Deduct inventory stocks
        foreach ($requirements as $req) {
            $update_stmt = $pdo->prepare("UPDATE inventory SET stocks = stocks - ? WHERE LOWER(active_ingredient) = LOWER(?)");
            $update_stmt->execute([$req['stocks_used'], $req['active_ingredient']]);
        }

        // Update session inventory
        if (isset($_SESSION['inventory'])) {
            foreach ($_SESSION['inventory'] as &$item) {
                foreach ($requirements as $req) {
                    if (strtolower($item['active_ingredient']) === strtolower($req['active_ingredient'])) {
                        $item['stocks'] -= $req['stocks_used'];
                    }
                }
            }
            unset($item);
        }

        $pdo->commit();

        $bookingConfirmation = [
            'reference_code' => $reference_code,
            'service_name' => $service_name,
            'service_id' => $service_id,
            'service_type' => $service_type,
            'appointment_date' => $appointment,
            'appointment_time' => $appointment_time,
            'price_range' => $formatted_price_range,
            'structure_types' => $structure_type ?: 'N/A',
            'status' => 'Pending',
            'customer_name' => $fullName,
            'address' => $address,
            'phone_number' => $phone,
            'email' => $email
        ];

        $_SESSION['success'] = "Booking submitted successfully!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Booking failed: " . $e->getMessage();
        header("Location: booking_form.php");
        exit();
    }
}

/**
 * Format price range as "50-70sqm=25,000" (lowercase sqm, no ₱, comma separator)
 * If amount is 0, fetch actual price from service_price_ranges table
 */
function formatPriceRangeDisplay(?string $priceRange, ?int $serviceId = null, $pdo = null, ?string $serviceName = null): string {
    if (!$priceRange || trim($priceRange) === '') return '—';
    $priceRange = trim($priceRange);
    $parts = explode('=', $priceRange, 2);
    $rangePart = trim($parts[0]);
    $amountPart = isset($parts[1]) ? trim($parts[1]) : '';

    // Remove all ₱, P, p symbols and spaces from range part
    $rangePart = preg_replace('/[₱Pp\s]/u', '', $rangePart);
    $rangePart = preg_replace('/sqm$/i', '', $rangePart);

    // Extract range (e.g., "80-100" or just "80")
    if (preg_match('/(\d+)\s*-\s*(\d+)/', $rangePart, $matches)) {
        $range = strtolower($matches[1] . '-' . $matches[2]) . 'sqm';
        $originalRange = $matches[1] . '-' . $matches[2];
    } elseif (preg_match('/(\d+)/', $rangePart, $matches)) {
        $range = strtolower($matches[1]) . 'sqm';
        $originalRange = $matches[1];
    } else {
        $range = '0sqm';
        $originalRange = '';
    }

    // Remove all ₱, P, p symbols from amount part and extract number
    $amountRaw = preg_replace('/[₱Pp\s]/u', '', $amountPart);
    $amountRaw = preg_replace('/[^0-9.]/', '', str_replace(',', '', $amountRaw));
    $value = $amountRaw === '' ? 0 : (float)$amountRaw;

    // If amount is 0 or missing and we have service info and pdo, look up actual price
    if (($value == 0 || $amountRaw === '') && $pdo && ($serviceId || $serviceName) && $originalRange) {
        try {
            // First, get service_id if we only have service_name
            if (!$serviceId && $serviceName) {
                $svcStmt = $pdo->prepare("SELECT service_id FROM services WHERE service_name = ? LIMIT 1");
                $svcStmt->execute([$serviceName]);
                $svcRow = $svcStmt->fetch(PDO::FETCH_ASSOC);
                if ($svcRow) {
                    $serviceId = (int)$svcRow['service_id'];
                }
            }

            // Look up price from service_price_ranges
            if ($serviceId && $originalRange) {
                // Try exact match first (match the range part)
                $priceStmt = $pdo->prepare("SELECT price FROM service_price_ranges WHERE service_id = ? AND (price_range LIKE ? OR price_range LIKE ?) LIMIT 1");
                $searchPattern1 = '%' . $originalRange . '%';
                $searchPattern2 = $originalRange . '%';
                $priceStmt->execute([$serviceId, $searchPattern1, $searchPattern2]);
                $priceRow = $priceStmt->fetch(PDO::FETCH_ASSOC);

                if ($priceRow && $priceRow['price'] > 0) {
                    $value = (float)$priceRow['price'];
                }
            }
        } catch (Exception $e) {
            // If lookup fails, keep value as 0
        }
    }

    $formattedAmount = number_format($value, 0, '', ',');

    return "{$range}={$formattedAmount}";
}

// Fetch booking history with service_id for price lookup
$bookingHistory = [];
$historyQuery = $pdo->prepare("
    SELECT sb.reference_code, sb.service_name, sb.service_type, sb.appointment_date, sb.appointment_time, sb.status, sb.price_range, sb.structure_types, sb.address, sb.service_id, sb.email
    FROM service_bookings sb
    WHERE sb.id = ?
    ORDER BY STR_TO_DATE(CONCAT(sb.appointment_date, ' ', sb.appointment_time), '%Y-%m-%d %h:%i %p') DESC
");
$historyQuery->execute([$_SESSION['id']]);
$bookingHistory = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch scheduled bookings for calendar display
$scheduledBookings = [];
$calendarQuery = $pdo->prepare("
    SELECT appointment_date, appointment_time, service_name, status, reference_code, customer_name
    FROM service_bookings
    WHERE appointment_date >= CURDATE() AND status != 'Cancelled'
    ORDER BY appointment_date, appointment_time
");
$calendarQuery->execute();
$allBookings = $calendarQuery->fetchAll(PDO::FETCH_ASSOC);

foreach ($allBookings as $booking) {
    $date = $booking['appointment_date'];
    if (!isset($scheduledBookings[$date])) {
        $scheduledBookings[$date] = [];
    }
    $scheduledBookings[$date][] = $booking;
}

// Fetch services with availability status, including expiration check
$services_status = [];
$all_services = $pdo->query("SELECT service_id, service_name FROM services ORDER BY service_name")->fetchAll(PDO::FETCH_ASSOC);
$now = new DateTime();

// Fetch active ingredients for each service to check rotation status
$service_ingredients = [];
foreach ($all_services as $service) {
    $stmt = $pdo->prepare("SELECT active_ingredient FROM service_inventory WHERE service_name = ?");
    $stmt->execute([$service['service_name']]);
    $ingredients = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $service_ingredients[$service['service_name']] = $ingredients;
}

foreach ($all_services as $service) {
    $service_name = $service['service_name'];
    $service_id = $service['service_id'];
    $stmt = $pdo->prepare("SELECT active_ingredient, stocks_used FROM service_inventory WHERE service_name = ?");
    $stmt->execute([$service_name]);
    $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $has_sufficient_stock = true;
    $unavailable_reasons = [];
    foreach ($requirements as $req) {
        $check = $pdo->prepare("SELECT stocks, expiry_date FROM inventory WHERE LOWER(active_ingredient) = LOWER(?)");
        $check->execute([$req['active_ingredient']]);
        $inventory_item = $check->fetch(PDO::FETCH_ASSOC);
        if ($inventory_item === false) {
            $has_sufficient_stock = false;
            $unavailable_reasons[] = "{$req['active_ingredient']}: Not found in inventory";
        } elseif ($inventory_item['stocks'] < $req['stocks_used']) {
            $has_sufficient_stock = false;
            $unavailable_reasons[] = "{$req['active_ingredient']}: Available={$inventory_item['stocks']}, Required={$req['stocks_used']}";
        } elseif (!empty($inventory_item['expiry_date'])) {
            $expiry = new DateTime($inventory_item['expiry_date']);
            if ($expiry < $now) {
                $has_sufficient_stock = false;
                $unavailable_reasons[] = "{$req['active_ingredient']}: Expired on {$inventory_item['expiry_date']}";
            }
        }
    }
    $is_rotated = $current_rotated_ingredient && in_array(strtolower($current_rotated_ingredient), array_map('strtolower', $service_ingredients[$service_name]));
    $services_status[] = [
        'name' => $service_name,
        'service_id' => $service_id,
        'available' => $has_sufficient_stock,
        'reasons' => $unavailable_reasons,
        'is_rotated' => $is_rotated
    ];
}

// Fetch all price ranges for services
$price_ranges_map = [];
$price_stmt = $pdo->prepare("SELECT service_id, price_range, price FROM service_price_ranges ORDER BY service_id, price_range");
$price_stmt->execute();
$price_ranges = $price_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($price_ranges as $pr) {
    $price_ranges_map[$pr['service_id']][] = [
        'price_range' => $pr['price_range'],
        'price' => $pr['price']
    ];
}

// Fetch scheduled bookings for calendar display
$scheduledBookings = [];
$calendarQuery = $pdo->prepare("
    SELECT appointment_date, appointment_time, service_name, status, reference_code, customer_name
    FROM service_bookings
    WHERE appointment_date >= CURDATE() AND status != 'Cancelled'
    ORDER BY appointment_date, appointment_time
");
$calendarQuery->execute();
$allBookings = $calendarQuery->fetchAll(PDO::FETCH_ASSOC);

foreach ($allBookings as $booking) {
    $date = $booking['appointment_date'];
    if (!isset($scheduledBookings[$date])) {
        $scheduledBookings[$date] = [];
    }
    $scheduledBookings[$date][] = $booking;
}

// Fetch announcements for calendar display
$announcements = [];
$allAnnouncementsData = [];
try {
    // Check if announcements table exists, if not create it
    $pdo->exec("CREATE TABLE IF NOT EXISTS `announcements` (
        `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `description` text,
        `announcement_date` date NOT NULL,
        `announcement_time` varchar(50) DEFAULT NULL,
        `color` varchar(20) DEFAULT '#ff6b6b',
        `created_by` varchar(100) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`announcement_id`),
        KEY `announcement_date` (`announcement_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $announcementQuery = $pdo->prepare("
        SELECT announcement_id, title, description, announcement_date, announcement_time, color
        FROM announcements
        WHERE announcement_date >= CURDATE()
        ORDER BY announcement_date, announcement_time
    ");
    $announcementQuery->execute();
    $allAnnouncements = $announcementQuery->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allAnnouncements as $announcement) {
        $date = $announcement['announcement_date'];
        if (!isset($announcements[$date])) {
            $announcements[$date] = [];
        }
        $announcements[$date][] = $announcement;
        // Store all announcements by ID for easy lookup
        $allAnnouncementsData[$announcement['announcement_id']] = $announcement;
    }
} catch (PDOException $e) {
    // If table doesn't exist or query fails, just continue without announcements
    $announcements = [];
    $allAnnouncementsData = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Form - TECHNO PEST</title>
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Existing styles unchanged */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 25%, #2d7a47 50%, #22c55e 75%, #16a34a 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
            color: #1f2937;
            overflow-x: hidden;
            position: relative;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background:
                radial-gradient(circle at 20% 30%, rgba(34, 197, 94, 0.15), transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(22, 163, 74, 0.15), transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(220, 252, 231, 0.1), transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
            padding-top: 5rem;
            position: relative;
            z-index: 1;
        }
        .hero-section {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 4rem 3rem;
            border-radius: 32px;
            margin-bottom: 3rem;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: translateY(0);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .hero-image {
            max-width: 100%;
            height: auto;
            max-height: 300px;
            margin: 2rem auto;
            display: block;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(15, 76, 58, 0.3), 0 0 0 2px rgba(255, 255, 255, 0.3);
            transition: all 0.4s ease;
            position: relative;
            z-index: 2;
        }
        .hero-image:hover {
            transform: scale(1.05);
            box-shadow: 0 25px 50px rgba(15, 76, 58, 0.4), 0 0 0 2px rgba(255, 255, 255, 0.5);
        }
        .hero-section:hover {
            transform: translateY(-10px);
            box-shadow: 0 35px 70px rgba(0, 0, 0, 0.3);
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: float 8s ease-in-out infinite;
        }
        .hero-section::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite;
        }
        .hero-title {
            font-size: 3.5rem;
            font-weight: 900;
            margin-bottom: 1.5rem;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 2;
            background: linear-gradient(45deg, #fff, #f0f9ff, #fff);
            background-size: 200% 200%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: textShimmer 3s ease-in-out infinite;
        }
        .hero-subtitle {
            font-size: 1.4rem;
            opacity: 0.95;
            margin-bottom: 0.75rem;
            position: relative;
            z-index: 2;
            font-weight: 300;
            letter-spacing: 0.5px;
        }
        .card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(30px);
            border-radius: 32px;
            box-shadow: 0 25px 50px rgba(15, 76, 58, 0.15), 0 0 0 1px rgba(255, 255, 255, 0.6);
            overflow: hidden;
            margin-bottom: 3rem;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid rgba(255, 255, 255, 0.8);
            position: relative;
        }
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }
        .card:hover {
            transform: translateY(-10px);
            box-shadow: 0 35px 70px rgba(15, 76, 58, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.9);
        }
        .card-header {
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 50%, #22c55e 100%);
            color: #ffffff;
            padding: 3rem 2.5rem;
            font-size: 2.25rem;
            font-weight: 800;
            text-align: center;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(15, 76, 58, 0.3);
        }
        .card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.8s;
        }
        .card:hover .card-header::before {
            left: 100%;
        }
        .card-body {
            padding: 3.5rem;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(240, 253, 244, 0.95) 100%);
        }
        .form-section {
            background: rgba(255, 255, 255, 0.6);
            border-radius: 20px;
            padding: 2.5rem;
            margin-bottom: 2.5rem;
            border: 2px solid rgba(220, 252, 231, 0.5);
            box-shadow: 0 4px 15px rgba(15, 76, 58, 0.08);
            transition: all 0.3s ease;
        }
        .form-section:hover {
            border-color: rgba(34, 197, 94, 0.5);
            box-shadow: 0 6px 20px rgba(15, 76, 58, 0.12);
        }
        .form-section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 3px solid #dcfce7;
        }
        .form-section-header i {
            font-size: 1.8rem;
            color: #22c55e;
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(220, 252, 231, 0.1) 100%);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
        }
        .form-section-header h3 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f4c3a;
            margin: 0;
            flex: 1;
        }
        .required-badge {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-section-body {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        .address-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        .appointment-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }
        @media (min-width: 768px) {
            .appointment-grid {
                grid-template-columns: 2fr 1fr;
            }
        }
        .appointment-date-group {
            min-width: 0;
        }
        .appointment-time-group {
            display: flex;
            flex-direction: column;
        }
        .selected-date-display {
            margin-top: 1.5rem;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            border-radius: 12px;
            text-align: center;
            color: #166534;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 2px solid #22c55e;
        }
        .selected-date-display i {
            color: #22c55e;
            font-size: 1.2rem;
        }
        .selected-date-display strong {
            color: #0f4c3a;
            font-size: 1.1rem;
        }
        .form-label-small {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-label-small i {
            color: #22c55e;
            font-size: 0.9rem;
        }
        .form-hint {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .form-hint i {
            color: #22c55e;
            font-size: 0.8rem;
        }
        .other-input-wrapper {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px dashed #bbf7d0;
        }
        .form-submit-wrapper {
            display: flex;
            justify-content: center;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 3px solid #dcfce7;
        }
        .btn-submit {
            padding: 1.5rem 4rem;
            font-size: 1.2rem;
            min-width: 300px;
        }
        .form-group {
            margin-bottom: 0;
            position: relative;
        }
        .form-label {
            font-weight: 700;
            color: #0f4c3a;
            margin-bottom: 1rem;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            letter-spacing: -0.01em;
        }
        .form-label::before {
            content: '';
            width: 5px;
            height: 28px;
            background: linear-gradient(135deg, #0f4c3a, #22c55e);
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(15, 76, 58, 0.4);
        }
        .form-label i {
            color: #22c55e;
            font-size: 1.25rem;
        }
        .form-control,         .form-select {
            border: 2px solid #bbf7d0;
            border-radius: 20px;
            padding: 1.35rem 1.5rem;
            font-size: 1.05rem;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(240, 253, 244, 0.9) 100%);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            position: relative;
            color: #1f2937;
            box-shadow: 0 4px 12px rgba(15, 76, 58, 0.08);
        }
        .form-select option:disabled {
            background-color: #f3f4f6;
            color: #9ca3af;
            font-style: italic;
        }
        .form-select option:disabled::after {
            content: ' (Booked)';
        }
        .form-control:focus, .form-select:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.2), 0 8px 20px rgba(34, 197, 94, 0.15);
            outline: none;
            background: rgba(255, 255, 255, 1);
            transform: translateY(-2px);
        }
        .form-control:hover, .form-select:hover {
            border-color: #86efac;
            background: rgba(255, 255, 255, 0.98);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(34, 197, 94, 0.12);
        }
        .form-control:disabled, .form-select:disabled {
            background: rgba(229, 231, 235, 0.8);
            cursor: not-allowed;
            opacity: 0.7;
        }
        .btn {
            padding: 1.25rem 3rem;
            border-radius: 16px;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            border: none;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s;
        }
        .btn:hover::before {
            left: 100%;
        }
        .btn-primary {
            background: linear-gradient(135deg, #0f4c3a 0%, #22c55e 100%);
            color: #ffffff;
            box-shadow: 0 10px 30px rgba(15, 76, 58, 0.4);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #1a5f3f 0%, #16a34a 100%);
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(15, 76, 58, 0.5);
        }
        .btn-primary:active {
            transform: translateY(-2px);
        }
        .btn-danger {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: #ffffff;
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
        }
        .btn-danger:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(220, 38, 38, 0.6);
        }
        .btn-success {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #ffffff;
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4);
        }
        .btn-success:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(34, 197, 94, 0.6);
        }
        .btn-outline-danger {
            border: 3px solid #dc2626;
            color: #dc2626;
            background: rgba(255, 255, 255, 0.9);
            text-transform: none;
            letter-spacing: normal;
            font-weight: 600;
        }
        .btn-outline-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(220, 38, 38, 0.4);
        }
        .btn-history {
            background: linear-gradient(135deg, #2d7a47 0%, #22c55e 100%);
            color: #ffffff;
            box-shadow: 0 10px 30px rgba(45, 122, 71, 0.4);
            margin-bottom: 2rem;
        }
        .btn-history:hover {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(45, 122, 71, 0.5);
        }
        .confirmation-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            animation: fadeIn 0.4s ease;
        }
        .confirmation-modal-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            margin: 3% auto;
            padding: 0;
            border-radius: 24px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
            animation: slideInDown 0.5s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .confirmation-modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }
        .confirmation-modal-header {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            padding: 3rem 2.5rem;
            text-align: center;
            position: relative;
            flex-shrink: 0;
            overflow: hidden;
        }
        .confirmation-modal-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }
        .success-icon-wrapper {
            margin-bottom: 1rem;
        }
        .success-icon {
            font-size: 5rem;
            color: white;
            animation: scaleIn 0.5s ease;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        @keyframes scaleIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }
        .confirmation-modal-header h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 0.5rem 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        .confirmation-subtitle {
            font-size: 1.1rem;
            opacity: 0.95;
            margin: 0;
            font-weight: 300;
        }
        .close-confirmation {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }
        .close-confirmation:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        .confirmation-modal-body {
            padding: 2.5rem;
            flex: 1;
            overflow-y: auto;
        }
        .reference-code-banner {
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 50%, #22c55e 100%);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            text-align: center;
            box-shadow: 0 8px 20px rgba(15, 76, 58, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            font-size: 1.2rem;
        }
        .reference-code-banner i {
            font-size: 1.5rem;
        }
        .reference-code-text {
            font-weight: 600;
        }
        .reference-code-text strong {
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: 1px;
        }
        .booking-summary {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-radius: 20px;
            padding: 2.5rem;
            margin-bottom: 2rem;
            border: 2px solid #bbf7d0;
            box-shadow: 0 4px 15px rgba(15, 76, 58, 0.1);
        }
        .booking-summary h3 {
            color: #0f4c3a;
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 3px solid #22c55e;
        }
        .booking-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        .detail-section {
            background: rgba(255, 255, 255, 0.9);
            padding: 1.5rem;
            border-radius: 16px;
            border: 2px solid #dcfce7;
            box-shadow: 0 2px 10px rgba(15, 76, 58, 0.08);
        }
        .detail-section h4 {
            color: #0f4c3a;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #bbf7d0;
        }
        .detail-section h4 i {
            color: #22c55e;
        }
        .detail-item {
            padding: 1rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #374151;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .detail-label i {
            color: #22c55e;
            font-size: 0.9rem;
        }
        .detail-value {
            color: #1f2937;
            font-size: 1rem;
            font-weight: 500;
            word-break: break-word;
        }
        .price-highlight {
            color: #0f4c3a;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .date-highlight, .time-highlight {
            color: #22c55e;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .confirmation-message {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 2px solid #3b82f6;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        .confirmation-message i {
            color: #1e40af;
            font-size: 1.3rem;
            margin-top: 0.2rem;
        }
        .confirmation-message p {
            margin: 0;
            color: #1e40af;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .confirmation-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }
        .btn-confirm {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #ffffff;
            padding: 1rem 2rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }
        .btn-confirm:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }
        .btn-download {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            padding: 1rem 2rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        .btn-download:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }
        .modal-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            margin: 2% auto;
            padding: 0;
            border-radius: 24px;
            width: 96%;
            max-width: 1600px;
            max-height: 96vh;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
            animation: slideInDown 0.4s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            border: 1px solid rgba(220, 252, 231, 0.3);
        }
        .modal {
            overflow-x: hidden;
        }
        .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }
        .modal-header {
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%);
            color: white;
            padding: 2rem 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .modal-header h2 {
            font-size: 1.75rem;
            font-weight: 800;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .modal-body {
            padding: 2rem;
            overflow-y: auto;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
        }
        .close {
            color: white;
            font-size: 1.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
        }
        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }
        .table-container {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            overflow-x: auto;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            border: 2px solid rgba(220, 252, 231, 0.5);
            flex: 1;
            position: relative;
            max-width: 100%;
            width: 100%;
            margin: 0;
            min-height: 0;
        }
        .table-container::-webkit-scrollbar {
            height: 12px;
            width: 12px;
        }
        .table-container::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 6px;
        }
        .table-container::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #0f4c3a, #1a5f3f);
            border-radius: 6px;
            border: 2px solid #f1f5f9;
        }
        .table-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #1a5f3f, #2d7a47);
        }
        .table-container::-webkit-scrollbar-corner {
            background: #f1f5f9;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            min-width: 1200px;
        }
        .table th {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #0f4c3a;
            font-weight: 800;
            padding: 1rem 0.5rem;
            font-size: 0.85rem;
            text-align: left;
            border-bottom: 3px solid #dcfce7;
            white-space: normal;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            word-wrap: break-word;
        }
        .table th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }
        .table td {
            padding: 1rem 0.5rem;
            vertical-align: middle;
            font-size: 0.8rem;
            border-bottom: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            word-wrap: break-word;
            overflow-wrap: break-word;
            color: #374151;
            overflow: hidden;
        }
        .table th:nth-child(1),
        .table td:nth-child(1) {
            width: 10%;
        }
        .table th:nth-child(2),
        .table td:nth-child(2) {
            width: 12%;
        }
        .table th:nth-child(3),
        .table td:nth-child(3) {
            width: 10%;
        }
        .table th:nth-child(4),
        .table td:nth-child(4) {
            width: 15%;
        }
        .table th:nth-child(5),
        .table td:nth-child(5) {
            width: 12%;
        }
        .table th:nth-child(6),
        .table td:nth-child(6) {
            width: 8%;
        }
        .table th:nth-child(7),
        .table td:nth-child(7) {
            width: 8%;
        }
        .table th:nth-child(8),
        .table td:nth-child(8) {
            width: 8%;
        }
        .table th:nth-child(9),
        .table td:nth-child(9) {
            width: 10%;
        }
        .table th:nth-child(10),
        .table td:nth-child(10) {
            width: 12%;
            min-width: 120px;
        }
        .table th:nth-child(11),
        .table td:nth-child(11) {
            width: 10%;
        }
        .table td:nth-child(1) {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table td:nth-child(2),
        .table td:nth-child(3),
        .table td:nth-child(4),
        .table td:nth-child(5),
        .table td:nth-child(9) {
            white-space: normal;
            word-break: break-word;
        }
        .table td:nth-child(6),
        .table td:nth-child(7),
        .table td:nth-child(8),
        .table td:nth-child(11) {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table td:nth-child(10) {
            white-space: normal;
            overflow: visible;
        }
        .table tbody tr {
            transition: all 0.2s ease;
        }
        .table tbody tr:hover {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            transform: scale(1.001);
            box-shadow: 0 2px 8px rgba(15, 76, 58, 0.1);
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table tbody tr:nth-child(even) {
            background-color: rgba(249, 250, 251, 0.5);
        }
        .table tbody tr:nth-child(even):hover {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        }
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        .badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .badge-pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border: 1px solid #fbbf24;
        }
        .badge-confirmed {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #166534;
            border: 1px solid #22c55e;
        }
        .badge-cancelled {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        .badge-completed {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            border: 1px solid #3b82f6;
        }
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: stretch;
            width: 100%;
        }
        .action-buttons form {
            width: 100%;
        }
        .action-buttons .btn {
            width: 100%;
            justify-content: center;
            padding: 0.4rem 0.6rem;
            font-size: 0.75rem;
        }
        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            border-radius: 8px;
            font-weight: 600;
            text-transform: none;
            letter-spacing: normal;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        .btn-small:hover {
            transform: translateY(-2px);
        }
        .structure-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.25rem;
            margin-top: 0;
        }
        .structure-option {
            position: relative;
            cursor: pointer;
        }
        .structure-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }
        .structure-option-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1.5rem 1rem;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(240, 253, 244, 0.9) 100%);
            border: 2px solid #bbf7d0;
            border-radius: 16px;
            text-align: center;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(15, 76, 58, 0.08);
            min-height: 120px;
        }
        .structure-option-label i {
            font-size: 2rem;
            color: #22c55e;
            transition: all 0.3s ease;
        }
        .structure-option-label::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(15, 76, 58, 0.1), transparent);
            transition: left 0.5s;
        }
        .structure-option input[type="radio"]:checked + .structure-option-label {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(220, 252, 231, 0.15) 100%);
            border-color: #22c55e;
            border-width: 3px;
            color: #0f4c3a;
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(34, 197, 94, 0.3);
        }
        .structure-option input[type="radio"]:checked + .structure-option-label i {
            transform: scale(1.2);
            color: #16a34a;
        }
        .structure-option input[type="radio"]:checked + .structure-option-label::before {
            left: 100%;
        }
        .structure-option:hover .structure-option-label {
            border-color: #d1d5db;
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-2px);
        }
        .alert {
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2.5rem;
            animation: slideInDown 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1.1rem;
            line-height: 1.7;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        .alert::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 6px;
            height: 100%;
            background: currentColor;
            opacity: 0.4;
        }
        .alert-success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(22, 163, 74, 0.1) 100%);
            color: #065f46;
            border-color: rgba(34, 197, 94, 0.3);
        }
        .alert-error {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1) 0%, rgba(185, 28, 28, 0.1) 100%);
            color: #991b1b;
            border-color: rgba(220, 38, 38, 0.3);
        }
        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.1) 100%);
            color: #92400e;
            border-color: rgba(245, 158, 11, 0.3);
        }
        .alert-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(37, 99, 235, 0.1) 100%);
            color: #1e40af;
            border-color: rgba(59, 130, 246, 0.3);
        }
        #serviceDetails .card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 2.5rem;
            border-radius: 20px;
            margin-top: 2.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 2px solid #e2e8f0;
        }
        .loading {
            position: relative;
            overflow: hidden;
        }
        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.6), transparent);
            animation: loading 2s infinite;
        }
        .fade-in {
            animation: fadeIn 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes loading {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        @keyframes textShimmer {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        @keyframes gradientMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .header-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 2rem;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        .header-nav .btn {
            padding: 0.75rem 1.5rem;
            font-size: 0.95rem;
            margin: 0;
        }
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }
        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
            animation: particleFloat 20s linear infinite;
        }
        @keyframes particleFloat {
            0% {
                transform: translateY(100vh) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) translateX(100px);
                opacity: 0;
            }
        }
        @media (max-width: 1024px) {
            .container {
                padding: 1.5rem;
            }
            .card-body {
                padding: 2rem;
            }
            .card-header {
                font-size: 1.5rem;
                padding: 1.5rem;
            }
            .hero-title {
                font-size: 2.5rem;
            }
            .hero-subtitle {
                font-size: 1.1rem;
            }
            .hero-image {
                max-height: 250px;
                margin: 1.5rem auto;
            }
            .modal-content {
                width: 98%;
                margin: 1% auto;
            }
            .modal-body {
                padding: 1rem;
            }
            .table {
                table-layout: fixed;
            }
            .table th, .table td {
                padding: 0.6rem 0.4rem;
                font-size: 0.7rem;
            }
            .table th {
                font-size: 0.75rem;
                padding: 0.7rem 0.4rem;
            }
            .action-buttons .btn {
                padding: 0.35rem 0.5rem;
                font-size: 0.7rem;
            }
            .confirmation-modal-content {
                width: 95%;
                margin: 2% auto;
            }
        }
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            .card-body {
                padding: 1.5rem;
            }
            .card-header {
                font-size: 1.25rem;
                padding: 1rem;
            }
            .hero-section {
                padding: 2rem 1rem;
            }
            .hero-title {
                font-size: 2rem;
            }
            .hero-image {
                max-height: 200px;
                margin: 1rem auto;
                border-radius: 16px;
            }
            .form-section {
                padding: 1.5rem;
            }
            .form-section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            .form-section-header h3 {
                font-size: 1.25rem;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .address-grid {
                grid-template-columns: 1fr;
            }
            .appointment-grid {
                grid-template-columns: 1fr;
            }
            .structure-options {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 1rem;
            }
            .structure-option-label {
                min-height: 100px;
                padding: 1.25rem 0.75rem;
            }
            .btn-submit {
                min-width: 100%;
                padding: 1.25rem 2rem;
            }
            .table-container {
                overflow-x: auto;
            }
            .btn {
                padding: 1rem 2rem;
                font-size: 1rem;
            }
            .modal-body {
                padding: 1rem;
            }
            .table th, .table td {
                padding: 0.5rem;
                font-size: 0.75rem;
            }
            .action-buttons {
                min-width: 100px;
            }
            .btn-small {
                padding: 0.4rem 0.8rem;
                font-size: 0.7rem;
            }
            .confirmation-modal-content {
                width: 98%;
                margin: 1% auto;
            }
            .confirmation-modal-body {
                padding: 1.5rem;
            }
            .booking-details {
                grid-template-columns: 1fr;
            }
            .confirmation-actions {
                flex-direction: column;
            }
            .calendar-container {
                padding: 1rem;
            }
            .calendar-days {
                gap: 0.4rem;
            }
            .calendar-day {
                min-height: 90px;
                padding: 0.4rem 0.25rem;
            }
            .calendar-day-label {
                font-size: 0.55rem;
                padding: 0.12rem 0.25rem;
            }
            .calendar-day-labels {
                gap: 0.1rem;
            }
            .calendar-month-year {
                font-size: 1.2rem;
            }
            .calendar-nav-btn {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
        }
        @media (max-width: 480px) {
            .hero-section {
                padding: 1.5rem 1rem;
            }
            .hero-title {
                font-size: 1.75rem;
            }
            .structure-options {
                grid-template-columns: 1fr;
            }
            .btn {
                padding: 0.875rem 1.5rem;
                font-size: 0.9rem;
            }
            .modal-content {
                width: 100%;
                height: 100%;
                margin: 0;
                border-radius: 0;
            }
            .modal-body {
                padding: 0.5rem;
            }
            .table-container {
                overflow-x: auto;
            }
            .table {
                table-layout: fixed;
            }
            .table th, .table td {
                padding: 0.5rem 0.3rem;
                font-size: 0.65rem;
            }
            .table th {
                font-size: 0.7rem;
                padding: 0.6rem 0.3rem;
            }
            .table td {
                white-space: normal;
                word-break: break-word;
            }
            .action-buttons .btn {
                padding: 0.3rem 0.4rem;
                font-size: 0.65rem;
            }
            .modal-header {
                padding: 1.5rem 1rem;
            }
            .modal-header h2 {
                font-size: 1.25rem;
            }
            .modal-body {
                padding: 1rem;
            }
            .action-buttons {
                min-width: 80px;
            }
            .btn-small {
                padding: 0.3rem 0.6rem;
                font-size: 0.65rem;
            }
            .confirmation-modal-content {
                width: 100%;
                height: 100%;
                margin: 0;
                border-radius: 0;
            }
            .confirmation-modal-body {
                padding: 1rem;
            }
            .calendar-container {
                padding: 0.75rem;
            }
            .calendar-days {
                gap: 0.3rem;
            }
            .calendar-day {
                min-height: 75px;
                padding: 0.3rem 0.2rem;
            }
            .calendar-day-number {
                font-size: 0.9rem;
            }
            .calendar-day-label {
                font-size: 0.5rem;
                padding: 0.1rem 0.2rem;
            }
            .calendar-day-labels {
                gap: 0.08rem;
            }
            .calendar-day-bookings-count {
                font-size: 0.5rem;
            }
            .calendar-month-year {
                font-size: 1rem;
            }
            .calendar-nav-btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
            .calendar-weekday {
                font-size: 0.75rem;
                padding: 0.4rem;
            }
            .calendar-legend {
                gap: 0.5rem;
                font-size: 0.75rem;
            }
        }
        /* Calendar Styles */
        .calendar-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border: 2px solid #e5e7eb;
        }
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .calendar-nav-btn {
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1.1rem;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        .calendar-nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 76, 58, 0.4);
        }
        .calendar-month-year {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f4c3a;
        }
        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .calendar-weekday {
            text-align: center;
            font-weight: 700;
            font-size: 0.9rem;
            color: #0f4c3a;
            padding: 0.5rem;
        }
        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.6rem;
        }
        .calendar-day {
            border-radius: 12px;
            padding: 0.5rem 0.3rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            border: 2px solid transparent;
            background: rgba(249, 250, 251, 0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: 110px;
            min-width: 0;
            overflow: visible;
            box-sizing: border-box;
        }
        .calendar-day:hover:not(.disabled):not(.past):not(.announcement-day) {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
            border-color: #0f4c3a;
        }
        .calendar-day.disabled {
            opacity: 0.3;
            cursor: not-allowed;
            background: #f3f4f6;
        }
        .calendar-day.announcement-day {
            opacity: 0.6;
            cursor: not-allowed;
            background: rgba(255, 107, 107, 0.1);
            border: 2px dashed #ff6b6b;
            position: relative;
        }
        .calendar-day.announcement-day::before {
            content: '📢';
            position: absolute;
            top: 2px;
            right: 2px;
            font-size: 0.8rem;
            opacity: 0.8;
        }
        .calendar-day.announcement-day:hover {
            transform: none;
            box-shadow: none;
            border-color: #ff6b6b;
        }
        .calendar-day.past {
            opacity: 0.5;
            cursor: not-allowed;
            background: #f9fafb;
        }
        .calendar-day.today {
            border: 3px solid #22c55e;
            background: rgba(34, 197, 94, 0.1);
            font-weight: 800;
        }
        .calendar-day.selected {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            border-color: #16a34a;
            font-weight: 800;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.4);
        }
        .calendar-day-number {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            flex-shrink: 0;
        }
        .calendar-day-labels {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            width: 100%;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            margin-bottom: 0.2rem;
        }
        .calendar-day-label {
            font-size: 0.6rem;
            font-weight: 600;
            padding: 0.15rem 0.3rem;
            border-radius: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            text-align: center;
            line-height: 1.3;
            flex-shrink: 0;
        }
        .calendar-day-label.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .calendar-day-label.confirmed {
            background: #dcfce7;
            color: #166534;
        }
        .calendar-day-label.completed {
            background: #dbeafe;
            color: #1e40af;
        }
        .calendar-day-label.full {
            background: #fee2e2;
            color: #991b1b;
            font-weight: 800;
        }
        .calendar-day-label.announcement {
            background: #ff6b6b;
            color: white;
            border: 2px dashed rgba(255,255,255,0.5) !important;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            cursor: pointer;
        }
        .calendar-day-label.announcement:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .calendar-day-bookings-count {
            font-size: 0.6rem;
            margin-top: auto;
            padding-top: 0.2rem;
            font-weight: 600;
            color: #6b7280;
            flex-shrink: 0;
            text-align: center;
            width: 100%;
        }
        .calendar-day.selected .calendar-day-bookings-count {
            color: rgba(255, 255, 255, 0.9);
        }
        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #e5e7eb;
            justify-content: center;
        }
        .calendar-legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
        }
        .calendar-legend-color {
            width: 20px;
            height: 20px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <!-- Header Navigation -->
    <div class="header-nav">
        <form action="logout.php" method="post">
            <button type="submit" class="btn btn-outline-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </form>
    </div>

    <!-- Floating Particles -->
    <div class="particles" id="particles"></div>

    <div class="container">
        <!-- Hero Section -->
        <div class="hero-section fade-in">
            <img src="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png/v1/fit/w_2500,h_1330,al_c/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png"
                 alt="Pest Control Service"
                 class="hero-image">
            <h1 class="hero-title">
                <i class="fas fa-shield-alt"></i> Welcome, <?= htmlspecialchars($fullName) ?>
            </h1>
            <p class="hero-subtitle">Experience premium pest control services with professional care and guaranteed results</p>
        </div>

        <!-- Booking History Button -->
        <?php if (!empty($bookingHistory)): ?>
            <div class="text-center mb-6">
                <button onclick="openBookingHistoryModal()" class="btn btn-history">
                    <i class="fas fa-history"></i> View Booking History
                </button>
            </div>
        <?php endif; ?>

        <!-- Booking Form Card -->
        <div class="card fade-in">
            <div class="card-header">
                <i class="fas fa-calendar-plus"></i> Book Your Service
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php
                $has_available_services = false;
                foreach ($services_status as $service) {
                    if ($service['available']) {
                        $has_available_services = true;
                        break;
                    }
                }
                if (!$has_available_services):
                ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle"></i>
                        No services are currently available due to insufficient inventory or expired items. Please contact support at support@pestcontrol.com for assistance.
                        <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin'): ?>
                            <br><strong>Debug Info:</strong>
                            <?php foreach ($services_status as $service): ?>
                                <?php if (!empty($service['reasons'])): ?>
                                    <br><?= htmlspecialchars($service['name']) ?>: <?= htmlspecialchars(implode("; ", $service['reasons'])) ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <form method="post" id="bookingForm">
                        <!-- Service Selection Section -->
                        <div class="form-section">
                            <div class="form-section-header">
                                <i class="fas fa-bug"></i>
                                <h3>Service Selection</h3>
                            </div>
                            <div class="form-section-body">
                        <div class="form-group">
                            <label class="form-label">
                                        <i class="fas fa-list-alt"></i> Choose Your Service
                            </label>
                            <select name="service_type" class="form-select" required onchange="fetchServiceDetails(this.value)">
                                <option value="">-- Choose Your Service --</option>
                                <?php
                                foreach ($services_status as $service) {
                                    $label = $service['name'];
                                    if ($service['is_rotated']) {
                                        $label .= ' (Recommended)';
                                    }
                                    if (!$service['available']) {
                                        $label .= ' (Currently Unavailable)';
                                    }
                                    $disabled = $service['available'] ? '' : 'disabled';
                                    echo "<option value='" . htmlspecialchars($service['name']) . "' $disabled>" . htmlspecialchars($label) . "</option>";
                                }
                                ?>
                            </select>
                            <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin'): ?>
                                        <small class="form-hint">
                                    <i class="fas fa-info-circle"></i>
                                    Unavailable services are marked due to insufficient stock or expired items. Check inventory at <a href='inventory.php?view=all' class='text-blue-500 hover:underline'>Inventory Management</a>.
                                </small>
                            <?php endif; ?>
                                </div>
                                <div id="serviceDetails"></div>
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-peso-sign"></i> Price Range
                                    </label>
                                    <select name="price_range" id="price_range_select" class="form-select" required>
                                        <option value="">-- Select Price Range --</option>
                                    </select>
                                    <div id="price_range_message" class="form-hint"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information Section -->
                        <div class="form-section">
                            <div class="form-section-header">
                                <i class="fas fa-user"></i>
                                <h3>Contact Information</h3>
                            </div>
                            <div class="form-section-body">
                                <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-phone"></i> Contact Number
                            </label>
                            <input type="text" name="phone_number" id="phone_number" class="form-control" required
                                   pattern="[0-9]{11}" maxlength="11"
                                   title="Enter exactly 11 digits (e.g., 09123456789)"
                                               placeholder="09123456789"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                        <small class="form-hint">
                                            <i class="fas fa-info-circle"></i> Must be exactly 11 digits
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i> Email Address
                            </label>
                            <input type="email" name="email" id="email" class="form-control" required
                                               placeholder="example@email.com"
                                   pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$">
                                        <small class="form-hint">
                                            <i class="fas fa-info-circle"></i> For booking confirmations and updates
                            </small>
                        </div>
                                </div>
                            </div>
                        </div>

                        <!-- Service Address Section -->
                        <div class="form-section">
                            <div class="form-section-header">
                                <i class="fas fa-map-marker-alt"></i>
                                <h3>Service Address</h3>
                            </div>
                            <div class="form-section-body">
                                <div class="address-grid">
                                    <div class="form-group">
                                        <label class="form-label-small">
                                        <i class="fas fa-road"></i> Street
                                    </label>
                                    <input type="text" name="street" id="street" class="form-control" required
                                               placeholder="Street name and number">
                                </div>
                                    <div class="form-group">
                                        <label class="form-label-small">
                                        <i class="fas fa-map-pin"></i> Barangay
                                    </label>
                                    <input type="text" name="barangay" id="barangay" class="form-control" required
                                               placeholder="Barangay">
                                </div>
                                    <div class="form-group">
                                        <label class="form-label-small">
                                        <i class="fas fa-city"></i> City
                                    </label>
                                    <input type="text" name="city" id="city" class="form-control" required
                                               placeholder="City">
                                </div>
                                    <div class="form-group">
                                        <label class="form-label-small">
                                        <i class="fas fa-landmark"></i> Province
                                    </label>
                                    <input type="text" name="province" id="province" class="form-control" required
                                               placeholder="Province">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Appointment Schedule Section -->
                        <div class="form-section">
                            <div class="form-section-header">
                                <i class="fas fa-calendar-alt"></i>
                                <h3>Appointment Schedule</h3>
                            </div>
                            <div class="form-section-body">
                                <div class="appointment-grid">
                                    <div class="form-group appointment-date-group">
                            <label class="form-label">
                                <i class="fas fa-calendar"></i> Preferred Date
                            </label>
                            <input type="hidden" name="appointment_date" id="appointment_date" required>
                            <div id="calendar-container" class="calendar-container"></div>
                                        <div id="selected-date-display" class="selected-date-display" style="display: none;">
                                            <i class="fas fa-check-circle"></i> 
                                            <span>Selected: <strong id="selected-date-text"></strong></span>
                            </div>
                        </div>
                                    <div class="form-group appointment-time-group">
                            <label class="form-label">
                                <i class="fas fa-clock"></i> Preferred Time
                            </label>
                            <select name="appointment_time" class="form-select" required>
                                <option value="">-- Select Time Slot --</option>
                                <?php
                                for ($hour = 6; $hour <= 23; $hour++) {
                                    $formatted = date("g:i A", strtotime("$hour:00"));
                                    echo "<option value='$formatted'>$formatted</option>";
                                    if ($hour < 23) {
                                        $formatted = date("g:i A", strtotime("$hour:30"));
                                        echo "<option value='$formatted'>$formatted</option>";
                                    }
                                }
                                ?>
                            </select>
                                        <small class="form-hint">
                                            <i class="fas fa-info-circle"></i> Available time slots from 6:00 AM to 11:30 PM
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Property Type Section -->
                        <div class="form-section">
                            <div class="form-section-header">
                                <i class="fas fa-building"></i>
                                <h3>Property Type</h3>
                                <span class="required-badge">Required</span>
                            </div>
                            <div class="form-section-body">
                            <div class="structure-options">
                                <?php
                                $options = ['Residential', 'Commercial', 'Restaurant', 'Plant', 'Warehouse', 'Building', 'Bank', 'School'];
                                foreach ($options as $opt) {
                                    echo '<div class="structure-option">
                                            <input type="radio" name="structure_type" value="' . htmlspecialchars($opt) . '" id="' . htmlspecialchars($opt) . '">
                                                <label class="structure-option-label" for="' . htmlspecialchars($opt) . '">
                                                    <i class="fas fa-' . ($opt === 'Residential' ? 'home' : ($opt === 'Commercial' ? 'store' : ($opt === 'Restaurant' ? 'utensils' : ($opt === 'Plant' ? 'industry' : ($opt === 'Warehouse' ? 'warehouse' : ($opt === 'Building' ? 'building' : ($opt === 'Bank' ? 'university' : 'school'))))))) . '"></i>
                                                    ' . htmlspecialchars($opt) . '
                                                </label>
                                          </div>';
                                }
                                ?>
                                <div class="structure-option">
                                    <input type="radio" id="otherCheckbox" name="structure_type" value="Other">
                                        <label class="structure-option-label" for="otherCheckbox">
                                            <i class="fas fa-ellipsis-h"></i> Other
                                        </label>
                                </div>
                            </div>
                                <div class="other-input-wrapper" id="otherInputDiv" style="display: none;">
                                <input type="text" class="form-control" name="structure_type_other" id="structureTypeOther"
                                       placeholder="Please specify the property type">
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="form-submit-wrapper">
                            <button type="submit" name="submit_booking" class="btn btn-primary btn-submit">
                                <i class="fas fa-paper-plane"></i> Submit Booking Request
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <div id="serviceDetails"></div>
            </div>
        </div>

        <?php if (empty($bookingHistory)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                You have no previous bookings. Start by booking your first service above!
            </div>
        <?php endif; ?>
    </div>

    <!-- Booking Confirmation Modal -->
    <?php if ($bookingConfirmation): ?>
        <div id="bookingConfirmationModal" class="confirmation-modal" style="display: block;">
            <div class="confirmation-modal-content">
                <div class="confirmation-modal-header">
                    <div class="success-icon-wrapper">
                        <i class="fas fa-check-circle success-icon"></i>
                    </div>
                    <h2>
                        <i class="fas fa-check-circle"></i> Booking Confirmed!
                    </h2>
                    <p class="confirmation-subtitle">Your service request has been successfully submitted</p>
                    <button class="close-confirmation" onclick="closeConfirmationModal()" title="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="confirmation-modal-body">
                    <div class="reference-code-banner">
                        <i class="fas fa-hashtag"></i>
                        <span class="reference-code-text">Reference Code: <strong><?= htmlspecialchars($bookingConfirmation['reference_code']) ?></strong></span>
                    </div>

                    <div class="booking-summary">
                        <h3>
                            <i class="fas fa-receipt"></i> Booking Summary
                        </h3>
                        <div class="booking-details-grid">
                            <div class="detail-section">
                                <h4><i class="fas fa-user"></i> Customer Information</h4>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-user-circle"></i> Customer Name</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['customer_name']) ?></div>
                            </div>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-envelope"></i> Email</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['email'] ?? 'N/A') ?></div>
                            </div>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-phone"></i> Contact Number</div>
                                    <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['phone_number']) ?></div>
                            </div>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-map-marker-alt"></i> Service Address</div>
                                    <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['address']) ?></div>
                            </div>
                            </div>

                            <div class="detail-section">
                                <h4><i class="fas fa-bug"></i> Service Details</h4>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-list"></i> Service Name</div>
                                    <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['service_name']) ?></div>
                            </div>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-tag"></i> Service Type</div>
                                    <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['service_type']) ?></div>
                            </div>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-building"></i> Property Type</div>
                                    <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['structure_types']) ?></div>
                            </div>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-peso-sign"></i> Price Range</div>
                                    <div class="detail-value price-highlight"><?= htmlspecialchars(formatPriceRangeDisplay($bookingConfirmation['price_range'], $bookingConfirmation['service_id'] ?? null, $pdo, $bookingConfirmation['service_name'] ?? null)) ?></div>
                            </div>
                            </div>

                            <div class="detail-section">
                                <h4><i class="fas fa-calendar-alt"></i> Appointment Schedule</h4>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-calendar"></i> Appointment Date</div>
                                    <div class="detail-value date-highlight"><?= htmlspecialchars($bookingConfirmation['appointment_date']) ?></div>
                            </div>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-clock"></i> Appointment Time</div>
                                    <div class="detail-value time-highlight"><?= htmlspecialchars($bookingConfirmation['appointment_time']) ?></div>
                            </div>
                            <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-info-circle"></i> Status</div>
                                <div class="detail-value">
                                        <span class="badge badge-pending">
                                        <i class="fas fa-clock"></i> <?= htmlspecialchars($bookingConfirmation['status']) ?>
                                    </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="confirmation-message">
                        <i class="fas fa-info-circle"></i>
                        <p>You will receive a confirmation email shortly. Please keep your reference code for future reference.</p>
                    </div>

                    <div class="confirmation-actions">
                        <button onclick="downloadReceipt('<?= htmlspecialchars($bookingConfirmation['reference_code']) ?>')" class="btn-download">
                            <i class="fas fa-download"></i> Download Receipt
                        </button>
                        <button onclick="closeConfirmationModal()" class="btn-confirm">
                            <i class="fas fa-check"></i> Continue
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Booking History Modal -->
    <div id="bookingHistoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-history"></i> Your Booking History</h2>
                <span class="close" onclick="closeBookingHistoryModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-hashtag"></i> Reference</th>
                                <th><i class="fas fa-bug"></i> Service</th>
                                <th><i class="fas fa-tag"></i> Type</th>
                                <th><i class="fas fa-map-marker-alt"></i> Location</th>
                                <th><i class="fas fa-envelope"></i> Email</th>
                                <th><i class="fas fa-peso-sign"></i> Price</th>
                                <th><i class="fas fa-calendar"></i> Date</th>
                                <th><i class="fas fa-clock"></i> Time</th>
                                <th><i class="fas fa-building"></i> Property</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                                <th><i class="fas fa-cog"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookingHistory as $booking): ?>
                                <tr>
                                    <td><?= htmlspecialchars($booking['reference_code']) ?></td>
                                    <td><?= htmlspecialchars($booking['service_name']) ?></td>
                                    <td><?= htmlspecialchars($booking['service_type']) ?></td>
                                    <td><?= htmlspecialchars($booking['address']) ?></td>
                                    <td><?= htmlspecialchars($booking['email'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars(formatPriceRangeDisplay($booking['price_range'], $booking['service_id'] ?? null, $pdo, $booking['service_name'] ?? null)) ?></td>
                                    <td><?= htmlspecialchars($booking['appointment_date']) ?></td>
                                    <td><?= htmlspecialchars($booking['appointment_time']) ?></td>
                                    <td><?= htmlspecialchars($booking['structure_types'] ?: 'N/A') ?></td>
                                    <td>
                                        <?php
                                        $status = $booking['status'];
                                        $badge_class = '';
                                        $icon = '';
                                        switch (strtolower($status)) {
                                            case 'pending':
                                                $badge_class = 'badge-pending';
                                                $icon = 'fa-clock';
                                                break;
                                            case 'confirmed':
                                                $badge_class = 'badge-confirmed';
                                                $icon = 'fa-check-circle';
                                                break;
                                            case 'cancelled':
                                                $badge_class = 'badge-cancelled';
                                                $icon = 'fa-times-circle';
                                                break;
                                            case 'completed':
                                                $badge_class = 'badge-completed';
                                                $icon = 'fa-check-double';
                                                break;
                                            default:
                                                $badge_class = 'badge-pending';
                                                $icon = 'fa-info-circle';
                                                break;
                                        }
                                        ?>
                                        <span class="badge <?= $badge_class ?>">
                                            <i class="fas <?= $icon ?>"></i> <?= htmlspecialchars($status) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($booking['status'] === 'Pending'): ?>
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="reference_code" value="<?= htmlspecialchars($booking['reference_code']) ?>">
                                                    <button type="submit" name="cancel_booking" class="btn btn-small btn-danger">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($booking['status'] !== 'Cancelled'): ?>
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="reference_code" value="<?= htmlspecialchars($booking['reference_code']) ?>">
                                                    <button type="submit" name="download_receipt" class="btn btn-small btn-success">
                                                        <i class="fas fa-download"></i> Receipt
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Floating Particles Animation
        const particlesContainer = document.getElementById('particles');
        const particleCount = 50;
        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.left = `${Math.random() * 100}vw`;
            particle.style.animationDelay = `${Math.random() * 20}s`;
            particle.style.opacity = Math.random() * 0.5 + 0.2;
            particle.style.transform = `scale(${Math.random() * 0.5 + 0.5})`;
            particlesContainer.appendChild(particle);
        }

        // Calendar with Colors and Labels
        const scheduledBookings = <?= json_encode($scheduledBookings ?? []) ?>;
        const announcements = <?= json_encode($announcements ?? []) ?>;
        const allAnnouncementsData = <?= json_encode($allAnnouncementsData ?? []) ?>;
        const maxBookingsPerDay = <?= $maxBookingsPerDay ?? 10 ?>;
        let currentMonth = new Date().getMonth();
        let currentYear = new Date().getFullYear();
        let selectedDate = null;

        function renderCalendar() {
            const calendarContainer = document.getElementById('calendar-container');
            if (!calendarContainer) {
                console.error('Calendar container not found');
                return;
            }

            const today = new Date();
            today.setHours(0, 0, 0, 0);
            today.setMinutes(0, 0, 0);

            const firstDay = new Date(currentYear, currentMonth, 1).getDay();
            const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

            let html = `
                <div class="calendar-header">
                    <button type="button" class="calendar-nav-btn" onclick="prevMonth()">&lt; Prev</button>
                    <div class="calendar-month-year">${monthNames[currentMonth]} ${currentYear}</div>
                    <button type="button" class="calendar-nav-btn" onclick="nextMonth()">Next &gt;</button>
                </div>
                <div class="calendar-weekdays">
                    ${dayNames.map(day => `<div class="calendar-weekday">${day}</div>`).join('')}
                </div>
                <div class="calendar-days">
            `;

            // Empty cells for days before the first day of the month
            for (let i = 0; i < firstDay; i++) {
                html += '<div class="calendar-day disabled"></div>';
            }

            // Days of the month
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const dateObj = new Date(currentYear, currentMonth, day);
                dateObj.setHours(0, 0, 0, 0);
                const isPast = dateObj < today;
                const isToday = dateObj.getTime() === today.getTime();
                const bookings = scheduledBookings[dateStr] || [];
                const bookingCount = bookings.length;
                const isFull = bookingCount >= maxBookingsPerDay;

                let dayClass = 'calendar-day';
                if (isPast) dayClass += ' past';
                if (isToday) dayClass += ' today';
                if (isFull && !isPast) dayClass += ' disabled';
                if (dateStr === selectedDate) dayClass += ' selected';

                let labelsHtml = '';
                let statusCounts = {Pending: 0, Confirmed: 0, Completed: 0};
                const dayAnnouncements = announcements[dateStr] || [];
                const hasAnnouncements = dayAnnouncements && dayAnnouncements.length > 0;

                // Add announcement labels first (so they appear at the top)
                if (hasAnnouncements) {
                    dayAnnouncements.forEach((ann) => {
                        const title = ann.title || 'Announcement';
                        const shortTitle = title.length > 12 ? title.substring(0, 12) + '...' : title;
                        const color = ann.color || '#ff6b6b';
                        const annId = ann.announcement_id;
                        labelsHtml += `<div class="calendar-day-label announcement" style="background: ${color}; color: white; border: 2px dashed rgba(255,255,255,0.5);" onclick="event.stopPropagation(); showAnnouncementDetails(${annId})" title="${title.replace(/"/g, '&quot;')}">📢 ${shortTitle}</div>`;
                    });
                }

                if (bookings && bookings.length > 0) {
                    bookings.forEach(booking => {
                        const status = booking.status || '';
                        if (status === 'Pending') statusCounts.Pending++;
                        else if (status === 'Confirmed') statusCounts.Confirmed++;
                        else if (status === 'Completed') statusCounts.Completed++;
                    });
                }

                if (statusCounts.Pending > 0) {
                    labelsHtml += `<div class="calendar-day-label pending">${statusCounts.Pending} Pending</div>`;
                }
                if (statusCounts.Confirmed > 0) {
                    labelsHtml += `<div class="calendar-day-label confirmed">${statusCounts.Confirmed} Confirmed</div>`;
                }
                if (statusCounts.Completed > 0) {
                    labelsHtml += `<div class="calendar-day-label completed">${statusCounts.Completed} Completed</div>`;
                }
                if (isFull && !isPast) {
                    labelsHtml = `<div class="calendar-day-label full">FULL</div>`;
                }

                // Disable dates with announcements
                if (hasAnnouncements && !isPast) {
                    dayClass += ' disabled announcement-day';
                }

                const onClick = (isPast || isFull || hasAnnouncements) ? '' : `onclick="selectDate('${dateStr}')"`;

                html += `
                    <div class="${dayClass}" ${onClick}>
                        <div class="calendar-day-number">${day}</div>
                        ${labelsHtml ? `<div class="calendar-day-labels">${labelsHtml}</div>` : ''}
                        ${bookingCount > 0 ? `<div class="calendar-day-bookings-count">${bookingCount}/${maxBookingsPerDay} bookings</div>` : ''}
                    </div>
                `;
            }

            html += `
                </div>
                <div class="calendar-legend">
                    <div class="calendar-legend-item">
                        <div class="calendar-legend-color" style="background: #fef3c7;"></div>
                        <span>Pending</span>
                    </div>
                    <div class="calendar-legend-item">
                        <div class="calendar-legend-color" style="background: #dcfce7;"></div>
                        <span>Confirmed</span>
                    </div>
                    <div class="calendar-legend-item">
                        <div class="calendar-legend-color" style="background: #dbeafe;"></div>
                        <span>Completed</span>
                    </div>
                    <div class="calendar-legend-item">
                        <div class="calendar-legend-color" style="background: #fee2e2;"></div>
                        <span>Full</span>
                    </div>
                    <div class="calendar-legend-item">
                        <div class="calendar-legend-color" style="background: #ff6b6b; border: 2px dashed rgba(255,255,255,0.5);"></div>
                        <span>📢 Announcement</span>
                    </div>
                </div>
            `;

            calendarContainer.innerHTML = html;

            // Update selected date display if date is already selected
            if (selectedDate) {
                const selectedDateDisplay = document.getElementById('selected-date-display');
                const selectedDateText = document.getElementById('selected-date-text');
                if (selectedDateDisplay && selectedDateText) {
                    const dateObj = new Date(selectedDate);
                    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                    selectedDateText.textContent = dateObj.toLocaleDateString('en-US', options);
                    selectedDateDisplay.style.display = 'block';
                }
            } else {
                const selectedDateDisplay = document.getElementById('selected-date-display');
                if (selectedDateDisplay) {
                    selectedDateDisplay.style.display = 'none';
                }
            }
        }

        function prevMonth() {
            currentMonth--;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            renderCalendar();
        }

        function nextMonth() {
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            renderCalendar();
        }

        function selectDate(dateStr) {
            // Check if date has announcements
            const dayAnnouncements = announcements[dateStr] || [];
            if (dayAnnouncements && dayAnnouncements.length > 0) {
                alert('This date has announcements and cannot be selected for booking. Please choose a different date.');
                return;
            }

            selectedDate = dateStr;
            const dateInput = document.getElementById('appointment_date');
            if (dateInput) {
                dateInput.value = dateStr;
            }

            // Display selected date
            const selectedDateDisplay = document.getElementById('selected-date-display');
            const selectedDateText = document.getElementById('selected-date-text');
            if (selectedDateDisplay && selectedDateText) {
                const dateObj = new Date(dateStr);
                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                selectedDateText.textContent = dateObj.toLocaleDateString('en-US', options);
                selectedDateDisplay.style.display = 'block';
            }

            // Fetch and update booked time slots
            updateTimeSlots(dateStr);

            renderCalendar();
        }

        function updateTimeSlots(selectedDate) {
            const timeSelect = document.querySelector('select[name="appointment_time"]');
            if (!timeSelect) return;

            // Show loading state
            timeSelect.disabled = true;
            timeSelect.innerHTML = '<option value="">Loading available time slots...</option>';

            // Fetch booked time slots
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_booked_times&appointment_date=${encodeURIComponent(selectedDate)}`
            })
                .then(response => response.json())
                .then(data => {
                    const bookedTimes = data.booked_times || [];
                    
                    // Generate all time slots
                    const allTimeSlots = [];
                    for (let hour = 6; hour <= 23; hour++) {
                        const formatted1 = formatTime(hour, 0);
                        allTimeSlots.push(formatted1);
                        if (hour < 23) {
                            const formatted2 = formatTime(hour, 30);
                            allTimeSlots.push(formatted2);
                        }
                    }

                    // Populate time select with available slots
                    timeSelect.innerHTML = '<option value="">-- Select Time Slot --</option>';
                    allTimeSlots.forEach(time => {
                        const isBooked = bookedTimes.includes(time);
                        const option = document.createElement('option');
                        option.value = time;
                        option.textContent = time + (isBooked ? ' (Booked)' : '');
                        option.disabled = isBooked;
                        if (isBooked) {
                            option.style.color = '#999';
                            option.style.backgroundColor = '#f3f4f6';
                        }
                        timeSelect.appendChild(option);
                    });

                    timeSelect.disabled = false;

                    // Update hint message
                    const hintElement = timeSelect.nextElementSibling;
                    if (hintElement && hintElement.classList.contains('form-hint')) {
                        const availableCount = allTimeSlots.length - bookedTimes.length;
                        hintElement.innerHTML = `<i class="fas fa-info-circle"></i> ${availableCount} available time slots from 6:00 AM to 11:30 PM`;
                        if (bookedTimes.length > 0) {
                            hintElement.innerHTML += ` (${bookedTimes.length} already booked)`;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching booked times:', error);
                    timeSelect.innerHTML = '<option value="">Error loading time slots. Please refresh the page.</option>';
                    timeSelect.disabled = false;
                });
        }

        function formatTime(hour, minute) {
            const date = new Date();
            date.setHours(hour, minute, 0, 0);
            return date.toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        }

        // Show announcement details
        function showAnnouncementDetails(announcementId) {
            const announcement = allAnnouncementsData[announcementId];
            if (!announcement) {
                alert('Announcement not found');
                return;
            }

            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.display = 'block';
            modal.style.zIndex = '2000';
            const color = announcement.color || '#ff6b6b';
            const title = (announcement.title || 'Announcement').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const description = (announcement.description || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
            const date = new Date(announcement.announcement_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            const time = announcement.announcement_time ? (announcement.announcement_time.replace(/</g, '&lt;').replace(/>/g, '&gt;')) : 'All Day';

            modal.innerHTML = `
                <div class="modal-content" style="max-width: 600px;">
                    <div class="modal-header" style="background: ${color};">
                        <h2><i class="fas fa-bullhorn"></i> ${title}</h2>
                        <span class="close" onclick="this.closest('.modal').style.display='none'; this.closest('.modal').remove();">&times;</span>
                    </div>
                    <div class="modal-body" style="padding: 2rem;">
                        ${description ? `<p style="font-size: 1.1rem; line-height: 1.6; color: #1f2937; margin-bottom: 1.5rem;">${description}</p>` : ''}
                        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #e5e7eb;">
                            <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-calendar"></i> <strong>Date:</strong> ${date}
                            </p>
                            <p style="color: #6b7280; font-size: 0.9rem;">
                                <i class="fas fa-clock"></i> <strong>Time:</strong> ${time}
                            </p>
                        </div>
                        <div style="margin-top: 1.5rem; text-align: center;">
                            <button onclick="this.closest('.modal').style.display='none'; this.closest('.modal').remove();" class="btn btn-primary" style="padding: 0.75rem 2rem;">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            // Close on outside click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                    document.body.removeChild(modal);
                }
            });
        }

        // Initialize calendar on page load
        document.addEventListener('DOMContentLoaded', function() {
            renderCalendar();
        });

        // Toggle Other Structure Type Input
        document.querySelectorAll('input[name="structure_type"]').forEach(radio => {
            radio.addEventListener('change', function () {
                const otherInputDiv = document.getElementById('otherInputDiv');
                const otherInput = document.getElementById('structureTypeOther');
                if (this.value === 'Other') {
                    otherInputDiv.style.display = 'block';
                    otherInput.required = true;
                } else {
                    otherInputDiv.style.display = 'none';
                    otherInput.required = false;
                    otherInput.value = '';
                }
            });
        });

        // Address Auto-Capitalization
        function capitalizeWords(str) {
            return str.replace(/\b\w/g, function(char) {
                return char.toUpperCase();
            });
        }

        const addressFields = ['street', 'barangay', 'city', 'province'];
        addressFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('input', function() {
                    const cursorPosition = this.selectionStart;
                    const originalValue = this.value;
                    const capitalizedValue = capitalizeWords(originalValue);

                    if (originalValue !== capitalizedValue) {
                        this.value = capitalizedValue;
                        // Restore cursor position
                        const lengthDiff = capitalizedValue.length - originalValue.length;
                        this.setSelectionRange(cursorPosition + lengthDiff, cursorPosition + lengthDiff);
                    }
                });

                field.addEventListener('blur', function() {
                    this.value = capitalizeWords(this.value);
                });
            }
        });

        // Phone Number Validation - 11 digits only
        const phoneInput = document.getElementById('phone_number');
        if (phoneInput) {
            phoneInput.addEventListener('input', function() {
                // Remove any non-numeric characters
                this.value = this.value.replace(/[^0-9]/g, '');

                // Limit to 11 digits
                if (this.value.length > 11) {
                    this.value = this.value.slice(0, 11);
                }

                // Visual feedback
                const isValid = this.value.length === 11;
                if (this.value.length > 0) {
                    if (isValid) {
                        this.style.borderColor = '#22c55e';
                        this.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.1)';
                    } else {
                        this.style.borderColor = '#ef4444';
                        this.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                    }
                } else {
                    this.style.borderColor = '';
                    this.style.boxShadow = '';
                }
            });

            phoneInput.addEventListener('blur', function() {
                if (this.value.length > 0 && this.value.length !== 11) {
                    this.setCustomValidity('Contact number must be exactly 11 digits');
                } else {
                    this.setCustomValidity('');
                }
            });

            // Form submission validation
            document.getElementById('bookingForm')?.addEventListener('submit', function(e) {
                const phone = phoneInput.value.replace(/[^0-9]/g, '');
                if (phone.length !== 11) {
                    e.preventDefault();
                    alert('Contact number must be exactly 11 digits (e.g., 09123456789)');
                    phoneInput.focus();
                    return false;
                }
            });
        }

        // Fetch Service Details
        function fetchServiceDetails(serviceName) {
            if (!serviceName) {
                document.getElementById('serviceDetails').innerHTML = '';
                document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                document.getElementById('price_range_message').innerHTML = '';
                return;
            }

            const serviceDetailsDiv = document.getElementById('serviceDetails');
            serviceDetailsDiv.innerHTML = '<div class="card loading"><div class="p-4">Loading service details...</div></div>';

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_service_details&service_name=${encodeURIComponent(serviceName)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        serviceDetailsDiv.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> ${data.error}</div>`;
                        document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                        document.getElementById('price_range_message').innerHTML = '';
                        return;
                    }

                    let html = `
                        <div class="card">
                            <h3 class="text-lg font-bold mb-4 text-gray-800">
                                <i class="fas fa-info-circle"></i> Service Details
                            </h3>
                            <p><strong>Service Name:</strong> ${data.service_name}</p>
                            <p><strong>Service Type:</strong> ${data.service_type}</p>
                            <p><strong>Details:</strong> ${data.service_details || 'No additional details available.'}</p>
                            <p><strong>Active Ingredients:</strong> ${data.active_ingredients.length > 0 ? data.active_ingredients.join(', ') : 'None'}</p>
                            ${data.is_rotated ? '<p class="text-green-600 font-semibold"><i class="fas fa-check-circle"></i> This service uses the currently recommended ingredient!</p>' : ''}
                        </div>
                    `;
                    serviceDetailsDiv.innerHTML = html;

                    const priceSelect = document.getElementById('price_range_select');
                    priceSelect.innerHTML = '<option value="">-- Select Price Range --</option>';
                    data.price_ranges.forEach(range => {
                        const priceFormatted = range.price.toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
                        priceSelect.innerHTML += `<option value="${range.price_range} SQM = ${priceFormatted} PHP">${range.price_range} SQM = ${priceFormatted}</option>`;
                    });

                    const priceMessage = document.getElementById('price_range_message');
                    if (data.price_ranges.length === 0) {
                        priceMessage.innerHTML = '<span class="text-red-500"><i class="fas fa-exclamation-triangle"></i> No price ranges available for this service.</span>';
                    } else {
                        priceMessage.innerHTML = '<span class="text-gray-500"><i class="fas fa-info-circle"></i> Select a price range based on your property size.</span>';
                    }
                })
                .catch(error => {
                    serviceDetailsDiv.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to load service details. Please try again.</div>';
                    document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                    document.getElementById('price_range_message').innerHTML = '';
                    console.error('Error:', error);
                });
        }

        // Modal Controls
        function openBookingHistoryModal() {
            document.getElementById('bookingHistoryModal').style.display = 'block';
        }

        function closeBookingHistoryModal() {
            document.getElementById('bookingHistoryModal').style.display = 'none';
        }

        function closeConfirmationModal() {
            document.getElementById('bookingConfirmationModal').style.display = 'none';
        }

        function downloadReceipt(referenceCode) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'download_receipt';
            input.value = referenceCode;
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            const bookingHistoryModal = document.getElementById('bookingHistoryModal');
            const confirmationModal = document.getElementById('bookingConfirmationModal');
            if (event.target === bookingHistoryModal) {
                closeBookingHistoryModal();
            }
            if (event.target === confirmationModal) {
                closeConfirmationModal();
            }
        };

        // Initialize structure type based on form state
        document.addEventListener('DOMContentLoaded', function () {
            const otherCheckbox = document.getElementById('otherCheckbox');
            const otherInputDiv = document.getElementById('otherInputDiv');
            const otherInput = document.getElementById('structureTypeOther');
            if (otherCheckbox.checked) {
                otherInputDiv.style.display = 'block';
                otherInput.required = true;
            }
        });
    </script>
</body>
</html>
