<?php
/*
 * EMAIL CONFIGURATION INSTRUCTIONS:
 * 
 * To enable email functionality, you need to configure the email settings below.
 * 
 * Option 1: Using Gmail SMTP (Recommended)
 * 1. Enable 2-Step Verification on your Google account
 * 2. Generate an App Password: https://myaccount.google.com/apppasswords
 * 3. Update the constants below with your Gmail and app password
 * 
 * Option 2: Using PHP mail() function
 * 1. Set EMAIL_USE_SMTP to false
 * 2. Ensure your server has mail() function configured
 * 
 * Option 3: Using PHPMailer (Best for production)
 * 1. Install PHPMailer: composer require phpmailer/phpmailer
 * 2. Configure SMTP settings below
 * 
 * For local testing, you can use services like Mailtrap or MailHog
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: log_in_page.php");
    exit();
}

function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

// ==================== EMAIL CONFIGURATION ====================
// Configure your email settings here
define('EMAIL_USE_SMTP', true); // Set to false to use PHP mail() function
define('SMTP_HOST', 'smtp.gmail.com'); // Your SMTP server
define('SMTP_PORT', 587); // SMTP port (587 for TLS, 465 for SSL)
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your email address
define('SMTP_PASSWORD', 'your-app-password'); // Your email password or app password
define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl'
define('EMAIL_FROM', 'noreply@technopestcontrol.com'); // From email address
define('EMAIL_FROM_NAME', 'Techno Pest Control'); // From name

// Email sending function
function sendEmail($to, $subject, $message, $isHTML = false) {
    // Validate email address
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address: $to");
        return false;
    }
    
    // Try PHPMailer if available and SMTP is enabled
    if (EMAIL_USE_SMTP && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = (SMTP_ENCRYPTION === 'ssl') ? 
                PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : 
                PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = 0; // Set to 2 for debugging
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP Debug: $str");
            };
            
            $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            
            if ($isHTML) {
                $mail->isHTML(true);
                $mail->Body = $message;
                // Plain text alternative
                $mail->AltBody = strip_tags($message);
            } else {
                $mail->Body = $message;
            }
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            // Fall through to mail() function
        }
    }
    
    // Fallback to mail() function with improved headers
    $headers = [];
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: " . ($isHTML ? "text/html" : "text/plain") . "; charset=UTF-8";
    $headers[] = "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM . ">";
    $headers[] = "Reply-To: " . EMAIL_FROM;
    $headers[] = "X-Mailer: PHP/" . phpversion();
    $headers[] = "X-Priority: 3";
    
    $headers_string = implode("\r\n", $headers);
    
    // Try to send email
    $result = @mail($to, $subject, $message, $headers_string);
    
    // Log email attempt
    if (!$result) {
        error_log("Failed to send email to: $to using mail() function");
    } else {
        error_log("Email sent successfully to: $to");
    }
    
    return $result;
}

function formatPriceRangeDisplay(?string $priceRange, $price = null): string {
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
    } elseif (preg_match('/(\d+)/', $rangePart, $matches)) {
        $range = strtolower($matches[1]) . 'sqm';
    } else {
        $range = '0sqm';
    }

    // Use provided price if available, otherwise extract from amount part
    if ($price !== null) {
        $value = (float)$price;
    } else {
    // Remove all ₱, P, p symbols from amount part and extract number
    $amountRaw = preg_replace('/[₱Pp\s]/u', '', $amountPart);
    $amountRaw = preg_replace('/[^0-9.]/', '', str_replace(',', '', $amountRaw));
    $value = $amountRaw === '' ? 0 : (float)$amountRaw;
    }
    
    $formattedAmount = number_format($value, 0, '', ',');

    return "{$range}={$formattedAmount}";
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=pest control;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Create announcements table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `announcements` (
        `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `description` text,
        `announcement_date` date NOT NULL,
        `announcement_time` varchar(50) DEFAULT NULL,
        `color` varchar(20) DEFAULT '#10b981',
        `created_by` varchar(100) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`announcement_id`),
        KEY `announcement_date` (`announcement_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Ensure service_bookings.status column is large enough to handle all status values
    try {
        // Check current column type
        $stmt = $pdo->query("SHOW COLUMNS FROM `service_bookings` LIKE 'status'");
        $column = $stmt->fetch();
        
        if ($column) {
            $columnType = $column['Type'];
            // If it's VARCHAR and smaller than 50, or if it's ENUM, alter it
            if (preg_match('/varchar\((\d+)\)/i', $columnType, $matches)) {
                $currentSize = (int)$matches[1];
                if ($currentSize < 50) {
                    $pdo->exec("ALTER TABLE `service_bookings` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'Pending'");
                }
            } elseif (stripos($columnType, 'enum') !== false) {
                // If it's ENUM, change to VARCHAR to allow flexibility
                $pdo->exec("ALTER TABLE `service_bookings` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'Pending'");
            }
        }
    } catch (PDOException $e) {
        // Column might not exist or already be correct size, log but don't fail
        error_log("Status column check/update failed: " . $e->getMessage());
    }
} catch (PDOException $e) {
    die("Connection failed: " . h($e->getMessage()));
}

/* ==================== CALENDAR EVENTS ==================== */
if (isset($_GET['calendar']) && $_GET['calendar'] === 'events') {
    header('Content-Type: application/json');
    
    // Fetch bookings - Order by when booking was created (newest to oldest)
    // Try multiple possible timestamp fields (created_at, or use booking_id DESC as proxy)
    // Note: If no timestamp field exists, booking_id DESC is used (newer bookings have higher IDs)
    $stmt = $pdo->query("SELECT sb.*, s.service_name, 
        COALESCE(sb.created_at, CONCAT(sb.appointment_date, ' ', COALESCE(sb.appointment_time, '00:00:00'))) AS booking_created_date,
        sb.booking_id AS booking_order_id
        FROM service_bookings sb 
        LEFT JOIN services s ON sb.service_id = s.service_id 
        WHERE sb.appointment_date IS NOT NULL 
        ORDER BY 
            CASE 
                WHEN sb.created_at IS NOT NULL THEN sb.created_at
                ELSE CONCAT(sb.appointment_date, ' ', COALESCE(sb.appointment_time, '00:00:00'))
            END DESC,
            sb.booking_id DESC");
    $events = [];
    
    foreach ($stmt as $row) {
        $timeRaw = trim($row['appointment_time'] ?? '');
        $timeDisplay = $timeRaw && $timeRaw !== 'All Day' ? $timeRaw : 'All Day';
        $allDay = ($timeRaw === '' || $timeRaw === 'All Day');

        $start = $row['appointment_date'];
        if (!$allDay && $timeRaw) {
            $start .= 'T' . date('H:i:s', strtotime($timeRaw));
        }

        $color = match($row['status'] ?? 'Pending') {
            'Completed' => '#10b981',
            'In Progress' => '#f59e0b',
            'Cancelled' => '#ef4444',
            default => '#22c55e',
        };

        // Get booking creation timestamp for ordering (newest first)
        // Try to get actual creation timestamp, fallback to appointment date + time, or use booking_id
        $bookingCreatedDate = $row['booking_created_date'] ?? null;
        if (empty($bookingCreatedDate) && isset($row['appointment_date'])) {
            $timePart = isset($row['appointment_time']) && !empty($row['appointment_time']) && $row['appointment_time'] !== 'All Day' 
                ? $row['appointment_time'] 
                : '00:00:00';
            $bookingCreatedDate = $row['appointment_date'] . ' ' . $timePart;
        }
        if (empty($bookingCreatedDate)) {
            $bookingCreatedDate = date('Y-m-d H:i:s');
        }
        
        // Convert to timestamp for ordering
        $createdTimestamp = strtotime($bookingCreatedDate);
        if ($createdTimestamp === false) {
            // If timestamp conversion fails, use booking_id as proxy (higher ID = newer)
            $createdTimestamp = 9999999999 - (int)$row['booking_id'];
        }
        
        // Use timestamp for displayOrder - newer bookings (higher timestamp) get lower numbers (appear first)
        // Maximum timestamp is around 2147483647 (year 2038), so we'll use a large number minus timestamp
        $displayOrder = 9999999999 - $createdTimestamp; // Newer bookings = lower order = appear first

        $events[] = [
            'id' => 'booking_' . $row['booking_id'],
            'title' => $row['customer_name'],
            'start' => $start,
            'allDay' => $allDay,
            'backgroundColor' => $color,
            'borderColor' => $color,
            'textColor' => '#fff',
            'displayOrder' => $displayOrder,
            'extendedProps' => [
                'type' => 'booking',
                'booking_id' => (int)$row['booking_id'],
                'booking_created_date' => $bookingCreatedDate, // Store creation date for ordering
                'customer' => $row['customer_name'],
                'reference' => $row['reference_code'],
                'time' => $timeDisplay,
                'service' => $row['service_name'],
                'phone' => $row['phone_number'],
                'address' => $row['address'] ?? 'Not provided',
                'status' => $row['status'] ?? 'Pending'
            ]
        ];
    }
    
    // Fetch announcements for calendar
    $stmt = $pdo->query("SELECT * FROM announcements ORDER BY announcement_date, announcement_time");
    foreach ($stmt as $row) {
        $start = $row['announcement_date'];
        $timeRaw = trim($row['announcement_time'] ?? '');
        $allDay = ($timeRaw === '' || $timeRaw === 'All Day' || $timeRaw === null);
        
        if (!$allDay && $timeRaw) {
            $start .= 'T' . date('H:i:s', strtotime($timeRaw));
        }

        $events[] = [
            'id' => 'announcement_' . $row['announcement_id'],
            'title' => $row['title'],
            'start' => $start,
            'allDay' => $allDay,
            'backgroundColor' => $row['color'] ?? '#10b981',
            'borderColor' => $row['color'] ?? '#10b981',
            'textColor' => '#fff',
            'extendedProps' => [
                'type' => 'announcement',
                'description' => $row['description'] ?? '',
                'time' => $timeRaw ?: 'All Day',
                'created_by' => $row['created_by'] ?? 'Admin'
            ]
        ];
    }
    
    // Sort events: Newest bookings first (by creation date/timestamp), announcements by date
    usort($events, function($a, $b) {
        // Separate bookings and announcements
        $propsA = $a['extendedProps'] ?? [];
        $propsB = $b['extendedProps'] ?? [];
        
        // Both are bookings - sort by creation date (newest first)
        if ($propsA['type'] === 'booking' && $propsB['type'] === 'booking') {
            // Use displayOrder if available (calculated from creation timestamp)
            if (isset($a['displayOrder']) && isset($b['displayOrder'])) {
                // Lower displayOrder = newer = appears first
                $result = $a['displayOrder'] - $b['displayOrder'];
                if ($result !== 0) {
                    return $result;
                }
            }
            
            // Fallback: sort by creation date timestamp
            $dateA = $propsA['booking_created_date'] ?? $a['start'] ?? '';
            $dateB = $propsB['booking_created_date'] ?? $b['start'] ?? '';
            
            // Convert to timestamps for comparison
            $timestampA = strtotime($dateA) ?: 0;
            $timestampB = strtotime($dateB) ?: 0;
            
            // Newer date (higher timestamp) should come first
            return $timestampB - $timestampA;
        }
        
        // Bookings appear before announcements
        if ($propsA['type'] === 'booking' && $propsB['type'] === 'announcement') {
            return -1;
        }
        if ($propsA['type'] === 'announcement' && $propsB['type'] === 'booking') {
            return 1;
        }
        
        // Both announcements or same type - sort by start date
        $dateA = $a['start'] ?? '';
        $dateB = $b['start'] ?? '';
        return strcmp($dateA, $dateB);
    });
    
    echo json_encode($events);
    exit;
}

/* ==================== GET BOOKING PICTURES ==================== */
if (isset($_GET['booking_pictures']) && isset($_GET['booking_id'])) {
    header('Content-Type: application/json');
    
    $booking_id = (int)$_GET['booking_id'];
    try {
        $stmt = $pdo->prepare("SELECT picture_id, picture_path, uploaded_at FROM booking_pictures WHERE booking_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$booking_id]);
        $pictures = $stmt->fetchAll();
        
        echo json_encode($pictures);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* ==================== DELETE PICTURE ==================== */
if (isset($_POST['delete_picture'])) {
    header('Content-Type: application/json');
    
    $picture_id = (int)$_POST['picture_id'];
    try {
        // Get picture path before deleting
        $stmt = $pdo->prepare("SELECT picture_path FROM booking_pictures WHERE picture_id = ?");
        $stmt->execute([$picture_id]);
        $picture = $stmt->fetch();
        
        if ($picture) {
            // Delete from database
            $pdo->prepare("DELETE FROM booking_pictures WHERE picture_id = ?")->execute([$picture_id]);
            
            // Delete file if it exists
            $file_path = __DIR__ . '/' . $picture['picture_path'];
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
            
            echo json_encode(['success' => true, 'message' => 'Picture deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Picture not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

/* ==================== ALL POST ACTIONS ==================== */
$success = $error = '';

// Check for success message from URL parameter (after redirect)
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_booking'])) {
            $ref = 'REF' . date('Y') . strtoupper(substr(uniqid(), -6));
            $pdo->prepare("INSERT INTO service_bookings (customer_name,email,phone_number,address,service_id,structure_types,price_range,appointment_date,appointment_time,reference_code,status) VALUES (?,?,?,?,?,?,?,?,?,?,'Pending')")
                ->execute([$_POST['customer_name'], $_POST['email'], $_POST['phone_number'], $_POST['address'], $_POST['service_id'], $_POST['structure_type'], $_POST['price_range'], $_POST['appointment_date'], $_POST['appointment_time'], $ref]);
            $success = "Booking created! Ref: <strong>$ref</strong>";
        }
        if (isset($_POST['delete_booking'])) {
            $pdo->prepare("DELETE FROM service_bookings WHERE booking_id = ?")->execute([$_POST['booking_id']]);
            $success = "Booking deleted!";
        }
        if (isset($_POST['update_status'])) {
            $booking_id = $_POST['booking_id'];
            $new_status = trim($_POST['new_status'] ?? '');
            $admin_message = trim($_POST['admin_message'] ?? '');
            
            // Validate status value
            $allowed_statuses = ['Pending', 'In Progress', 'Completed', 'Cancelled'];
            if (empty($new_status) || !in_array($new_status, $allowed_statuses)) {
                $error = "Invalid status value. Please select a valid status.";
            } else {
                // Get booking details for email (join with services to get service name)
                $stmt = $pdo->prepare("SELECT sb.*, s.service_name FROM service_bookings sb LEFT JOIN services s ON sb.service_id = s.service_id WHERE sb.booking_id = ?");
                $stmt->execute([$booking_id]);
                $booking = $stmt->fetch();
                
                if (!$booking) {
                    $error = "Booking not found!";
                } else {
                    // Update status - ensure it's exactly one of the allowed values
                    $pdo->prepare("UPDATE service_bookings SET status = ? WHERE booking_id = ?")->execute([$new_status, $booking_id]);
            
            // Send email notification
            $emailSent = false;
            if ($booking && !empty($booking['email'])) {
                $to = $booking['email'];
                $subject = "Booking Status Update - " . ($booking['reference_code'] ?? 'N/A');
                
                // Default messages based on status
                $defaultMessages = [
                    'Pending' => 'Your booking is currently pending and will be reviewed by our team shortly.',
                    'In Progress' => 'Your booking is now in progress. Our team is working on your service request.',
                    'Completed' => 'Your booking has been completed. Thank you for choosing our services!',
                    'Cancelled' => 'Your booking has been cancelled. If you need to reschedule, please contact us.'
                ];
                
                // Use admin message if provided, otherwise use default
                $statusMessage = !empty($admin_message) ? nl2br(h($admin_message)) : $defaultMessages[$new_status] ?? '';
                
                // Create HTML email message
                $message = "<!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                        .content { background: #f8fafc; padding: 30px; border-radius: 0 0 8px 8px; }
                        .info-box { background: white; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #6366f1; }
                        .message-box { background: #e0e7ff; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #6366f1; }
                        .status-badge { display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: bold; margin-top: 10px; }
                        .status-pending { background: #fbbf24; color: #78350f; }
                        .status-progress { background: #f97316; color: #7c2d12; }
                        .status-completed { background: #10b981; color: #064e3b; }
                        .status-cancelled { background: #ef4444; color: #7f1d1d; }
                        .footer { text-align: center; margin-top: 30px; color: #64748b; font-size: 12px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>Techno Pest Control</h2>
                            <p>Booking Status Update</p>
                        </div>
                        <div class='content'>
                            <p>Dear " . h($booking['customer_name']) . ",</p>
                            <p>Your booking status has been updated.</p>
                            
                            <div class='info-box'>
                                <strong>Reference:</strong> " . h($booking['reference_code'] ?? 'N/A') . "<br>
                                <strong>Service:</strong> " . h($booking['service_name'] ?? 'N/A') . "<br>
                                <strong>Appointment Date:</strong> " . h($booking['appointment_date'] ?? 'N/A') . "<br>
                                <strong>Appointment Time:</strong> " . h($booking['appointment_time'] ?? 'N/A') . "<br>
                                <strong>New Status:</strong> 
                                <span class='status-badge status-" . strtolower(str_replace(' ', '-', $new_status)) . "'>" . h($new_status) . "</span>
                            </div>";
                
                if (!empty($statusMessage)) {
                    $message .= "<div class='message-box'>
                                <strong>Message from our team:</strong><br>
                                " . $statusMessage . "
                            </div>";
                }
                
                $message .= "<p>Thank you for choosing Techno Pest Control.</p>
                            <p>Best regards,<br>Techno Pest Control Team</p>
                        </div>
                        <div class='footer'>
                            <p>This is an automated email. Please do not reply to this message.</p>
                        </div>
                    </div>
                </body>
                </html>";
                
                    $emailSent = sendEmail($to, $subject, $message, true);
                }
                
                if ($emailSent) {
                    $success = "Status updated and email with message sent successfully!";
                } else {
                    $success = "Status updated! (Email could not be sent - please check email configuration)";
                }
            }
        }
        if (isset($_POST['reschedule_booking'])) {
            $booking_id = $_POST['booking_id'];
            $new_date = trim($_POST['new_appointment_date'] ?? '');
            $new_time = trim($_POST['new_appointment_time'] ?? '');
            
            if (empty($new_date) && empty($new_time)) {
                $error = "Please provide a new appointment date or time to reschedule.";
            } else {
                // Get current booking details
                $stmt = $pdo->prepare("SELECT sb.*, s.service_name FROM service_bookings sb LEFT JOIN services s ON sb.service_id = s.service_id WHERE sb.booking_id = ?");
                $stmt->execute([$booking_id]);
                $booking = $stmt->fetch();
                
                if (!$booking) {
                    $error = "Booking not found!";
                } else {
                    // Update only provided fields
                    $updateFields = [];
                    $updateValues = [];
                    
                    if (!empty($new_date)) {
                        $updateFields[] = "appointment_date = ?";
                        $updateValues[] = $new_date;
                    }
                    
                    if (!empty($new_time)) {
                        $updateFields[] = "appointment_time = ?";
                        $updateValues[] = $new_time;
                    }
                    
                    if (!empty($updateFields)) {
                        $updateValues[] = $booking_id;
                        $sql = "UPDATE service_bookings SET " . implode(", ", $updateFields) . " WHERE booking_id = ?";
                        $pdo->prepare($sql)->execute($updateValues);
                        
                        // Get updated booking details for email
                        $stmt = $pdo->prepare("SELECT sb.*, s.service_name FROM service_bookings sb LEFT JOIN services s ON sb.service_id = s.service_id WHERE sb.booking_id = ?");
                        $stmt->execute([$booking_id]);
                        $updatedBooking = $stmt->fetch();
                        
                        // Send email notification
                        $emailSent = false;
                        if ($updatedBooking && !empty($updatedBooking['email'])) {
                            $to = $updatedBooking['email'];
                            $subject = "Booking Rescheduled - " . ($updatedBooking['reference_code'] ?? 'N/A');
                            
                            $oldDate = $booking['appointment_date'] ?? 'N/A';
                            $oldTime = $booking['appointment_time'] ?? 'N/A';
                            $newDateDisplay = $updatedBooking['appointment_date'] ?? $oldDate;
                            $newTimeDisplay = $updatedBooking['appointment_time'] ?? $oldTime;
                            
                            // Create HTML email message
                            $message = "<!DOCTYPE html>
                            <html>
                            <head>
                                <meta charset='UTF-8'>
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                    .header { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                                    .content { background: #f8fafc; padding: 30px; border-radius: 0 0 8px 8px; }
                                    .info-box { background: white; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #10b981; }
                                    .change-box { background: #d1fae5; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #10b981; }
                                    .footer { text-align: center; margin-top: 30px; color: #64748b; font-size: 12px; }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <div class='header'>
                                        <h2>Techno Pest Control</h2>
                                        <p>Booking Rescheduled</p>
                                    </div>
                                    <div class='content'>
                                        <p>Dear " . h($updatedBooking['customer_name']) . ",</p>
                                        <p>Your booking appointment has been rescheduled.</p>
                                        
                                        <div class='info-box'>
                                            <strong>Reference:</strong> " . h($updatedBooking['reference_code'] ?? 'N/A') . "<br>
                                            <strong>Service:</strong> " . h($updatedBooking['service_name'] ?? 'N/A') . "<br>
                                        </div>
                                        
                                        <div class='change-box'>
                                            <strong>Previous Appointment:</strong><br>
                                            Date: " . h($oldDate) . "<br>
                                            Time: " . h($oldTime) . "<br><br>
                                            <strong>New Appointment:</strong><br>
                                            Date: " . h($newDateDisplay) . "<br>
                                            Time: " . h($newTimeDisplay) . "
                                        </div>
                                        
                                        <p>Thank you for choosing Techno Pest Control.</p>
                                        <p>Best regards,<br>Techno Pest Control Team</p>
                                    </div>
                                    <div class='footer'>
                                        <p>This is an automated email. Please do not reply to this message.</p>
                                    </div>
                                </div>
                            </body>
                            </html>";
                            
                            $emailSent = sendEmail($to, $subject, $message, true);
                            
                            // Log email to audit logs
                            if ($emailSent && function_exists('logEmail')) {
                                logEmail($pdo, $to, $subject, [
                                    'booking_id' => $booking_id,
                                    'reference_code' => $updatedBooking['reference_code'] ?? 'N/A',
                                    'customer_name' => $updatedBooking['customer_name'] ?? 'N/A',
                                    'type' => 'Reschedule'
                                ]);
                            }
                        }
                        
                        if ($emailSent) {
                            $success = "Booking rescheduled and email notification sent successfully!";
                        } else {
                            $success = "Booking rescheduled! (Email could not be sent - please check email configuration)";
                        }
                        
                        // Redirect to stay on bookings page
                        header("Location: ?success=" . urlencode($success));
                        exit();
                    }
                }
            }
        }
        if (isset($_POST['upload_pictures'])) {
            $booking_id = $_POST['booking_id'];
            
            // Create upload directory if it doesn't exist
            $upload_dir = __DIR__ . '/uploads/bookings/' . $booking_id . '/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $uploaded_files = [];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 20 * 1024 * 1024; // 20MB
            
            if (isset($_FILES['pictures']) && !empty($_FILES['pictures']['name'][0])) {
                foreach ($_FILES['pictures']['name'] as $key => $filename) {
                    if ($_FILES['pictures']['error'][$key] === UPLOAD_ERR_OK) {
                        $tmp_name = $_FILES['pictures']['tmp_name'][$key];
                        $file_size = $_FILES['pictures']['size'][$key];
                        $file_type = $_FILES['pictures']['type'][$key];
                        
                        // Validate file type
                        if (!in_array($file_type, $allowed_types)) {
                            $error = "Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.";
                            continue;
                        }
                        
                        // Validate file size
                        if ($file_size > $max_size) {
                            $error = "File size exceeds 20MB limit.";
                            continue;
                        }
                        
                        // Generate unique filename
                        $ext = pathinfo($filename, PATHINFO_EXTENSION);
                        $new_filename = uniqid('img_') . '_' . time() . '.' . $ext;
                        $upload_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($tmp_name, $upload_path)) {
                            $uploaded_files[] = 'uploads/bookings/' . $booking_id . '/' . $new_filename;
                            
                            // Store in database (create table if needed)
                            try {
                                $pdo->exec("CREATE TABLE IF NOT EXISTS booking_pictures (
                                    picture_id int(11) NOT NULL AUTO_INCREMENT,
                                    booking_id int(11) NOT NULL,
                                    picture_path varchar(500) NOT NULL,
                                    uploaded_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                    PRIMARY KEY (picture_id),
                                    KEY booking_id (booking_id)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                
                                $pdo->prepare("INSERT INTO booking_pictures (booking_id, picture_path) VALUES (?, ?)")
                                    ->execute([$booking_id, $uploaded_files[count($uploaded_files)-1]]);
                            } catch (Exception $e) {
                                // Table creation or insert failed, but file is uploaded
                            }
                        }
                    }
                }
                
                if (!empty($uploaded_files)) {
                    // Get booking details for email (join with services to get service name)
                    $stmt = $pdo->prepare("SELECT sb.*, s.service_name FROM service_bookings sb LEFT JOIN services s ON sb.service_id = s.service_id WHERE sb.booking_id = ?");
                    $stmt->execute([$booking_id]);
                    $booking = $stmt->fetch();
                    
                    // Send email notification
                    $emailSent = false;
                    if ($booking && !empty($booking['email'])) {
                        $to = $booking['email'];
                        $subject = "Pictures Uploaded - Booking " . ($booking['reference_code'] ?? 'N/A');
                        
                        // Create HTML email message
                        $message = "<!DOCTYPE html>
                        <html>
                        <head>
                            <meta charset='UTF-8'>
                            <style>
                                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                .header { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                                .content { background: #f8fafc; padding: 30px; border-radius: 0 0 8px 8px; }
                                .info-box { background: white; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #6366f1; }
                                .pictures-count { background: #10b981; color: white; padding: 10px 20px; border-radius: 8px; display: inline-block; margin: 10px 0; font-weight: bold; }
                                .footer { text-align: center; margin-top: 30px; color: #64748b; font-size: 12px; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h2>Techno Pest Control</h2>
                                    <p>Pictures Uploaded</p>
                                </div>
                                <div class='content'>
                                    <p>Dear " . h($booking['customer_name']) . ",</p>
                                    <p>Pictures have been uploaded for your booking.</p>
                                    
                                    <div class='info-box'>
                                        <strong>Reference:</strong> " . h($booking['reference_code'] ?? 'N/A') . "<br>
                                        <strong>Service:</strong> " . h($booking['service_name'] ?? 'N/A') . "<br>
                                        <div class='pictures-count'>" . count($uploaded_files) . " Picture(s) Uploaded</div>
                                    </div>
                                    
                                    <p>You can view these pictures in your booking details.</p>
                                    <p>Thank you for choosing Techno Pest Control.</p>
                                    <p>Best regards,<br>Techno Pest Control Team</p>
                                </div>
                                <div class='footer'>
                                    <p>This is an automated email. Please do not reply to this message.</p>
                                </div>
                            </div>
                        </body>
                        </html>";
                        
                        $emailSent = sendEmail($to, $subject, $message, true);
                    }
                    
                    if ($emailSent) {
                        $success = count($uploaded_files) . " picture(s) uploaded and email sent successfully!";
                    } else {
                        $success = count($uploaded_files) . " picture(s) uploaded! (Email could not be sent - please check email configuration)";
                    }
                } else {
                    // No files uploaded - user might just be viewing existing pictures
                    // Don't show error, just allow them to view
                }
            }
            // If no files were selected, that's okay - user can just view existing pictures
        }
        if (isset($_POST['add_announcement'])) {
            $pdo->prepare("INSERT INTO announcements (title, description, announcement_date, announcement_time, color, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$_POST['announcement_title'], $_POST['announcement_description'] ?? '', $_POST['announcement_date'], $_POST['announcement_time'] ?? null, $_POST['announcement_color'] ?? '#ff6b6b', $_SESSION['username']]);
            $success = "Announcement added to calendar!";
        }
        if (isset($_POST['edit_announcement'])) {
            $pdo->prepare("UPDATE announcements SET title=?, description=?, announcement_date=?, announcement_time=?, color=? WHERE announcement_id=?")
                ->execute([$_POST['announcement_title'], $_POST['announcement_description'] ?? '', $_POST['announcement_date'], $_POST['announcement_time'] ?? null, $_POST['announcement_color'] ?? '#ff6b6b', $_POST['announcement_id']]);
            $success = "Announcement updated!";
        }
        if (isset($_POST['delete_announcement'])) {
            $pdo->prepare("DELETE FROM announcements WHERE announcement_id = ?")->execute([$_POST['announcement_id']]);
            $success = "Announcement deleted!";
        }
        if (isset($_POST['add_service'])) {
            // Check if service name already exists
            $checkStmt = $pdo->prepare("SELECT service_id FROM services WHERE service_name = ?");
            $checkStmt->execute([$_POST['service_name']]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                $error = "A service with the name '" . h($_POST['service_name']) . "' already exists. Please use a different name.";
            } else {
                try {
                    $pdo->prepare("INSERT INTO services (service_name,service_type,service_details,active_ingredient) VALUES (?,?,?,?)")
                        ->execute([$_POST['service_name'], $_POST['service_type'], $_POST['service_details'], $_POST['active_ingredient'] ?? '']);
            $success = "Service added!";
                    // Redirect to stay on services tab
                    header("Location: ?tab=services&success=" . urlencode($success));
                    exit();
                } catch (PDOException $e) {
                    if ($e->getCode() == '23000') {
                        // Duplicate entry error
                        $error = "A service with the name '" . h($_POST['service_name']) . "' already exists. Please use a different name.";
                    } else {
                        $error = "Error adding service: " . h($e->getMessage());
                    }
                }
            }
        }
        if (isset($_POST['edit_service'])) {
            // Check if service name already exists (excluding current service)
            $checkStmt = $pdo->prepare("SELECT service_id FROM services WHERE service_name = ? AND service_id != ?");
            $checkStmt->execute([$_POST['service_name'], $_POST['service_id']]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                $error = "A service with the name '" . h($_POST['service_name']) . "' already exists. Please use a different name.";
            } else {
                try {
            $pdo->prepare("UPDATE services SET service_name=?,service_type=?,service_details=? WHERE service_id=?")
                ->execute([$_POST['service_name'], $_POST['service_type'], $_POST['service_details'], $_POST['service_id']]);
            $success = "Service updated!";
                    // Redirect to stay on services tab
                    header("Location: ?tab=services&success=" . urlencode($success));
                    exit();
                } catch (PDOException $e) {
                    if ($e->getCode() == '23000') {
                        // Duplicate entry error
                        $error = "A service with the name '" . h($_POST['service_name']) . "' already exists. Please use a different name.";
                    } else {
                        $error = "Error updating service: " . h($e->getMessage());
                    }
                }
            }
        }
        if (isset($_POST['delete_service'])) {
            $id = $_POST['service_id'];
            $pdo->prepare("DELETE FROM services WHERE service_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM service_price_ranges WHERE service_id = ?")->execute([$id]);
            $success = "Service deleted!";
            // Redirect to stay on services tab
            header("Location: ?tab=services&success=" . urlencode($success));
            exit();
        }
        if (isset($_POST['add_price'])) {
            $pdo->prepare("INSERT INTO service_price_ranges (service_id,price_range,price) VALUES (?,?,?)")
                ->execute([$_POST['service_id'], $_POST['price_range'], $_POST['price']]);
            $success = "Price added!";
            // Redirect to stay on services tab
            header("Location: ?tab=services&success=" . urlencode($success));
            exit();
        }
        if (isset($_POST['edit_price'])) {
            $pdo->prepare("UPDATE service_price_ranges SET price_range=?,price=? WHERE price_range_id=?")
                ->execute([$_POST['price_range'], $_POST['price'], $_POST['price_range_id']]);
            $success = "Price updated!";
            // Redirect to stay on services tab
            header("Location: ?tab=services&success=" . urlencode($success));
            exit();
        }
        if (isset($_POST['delete_price'])) {
            $pdo->prepare("DELETE FROM service_price_ranges WHERE price_range_id = ?")->execute([$_POST['price_range_id']]);
            $success = "Price deleted!";
            // Redirect to stay on services tab
            header("Location: ?tab=services&success=" . urlencode($success));
            exit();
        }
    } catch (Exception $e) {
        $error = "Error: " . h($e->getMessage());
    }
}

/* ==================== FETCH DATA ==================== */
$search = $_GET['search'] ?? '';
$filter = $_GET['filter_status'] ?? '';
$query = "SELECT sb.*, s.service_name FROM service_bookings sb LEFT JOIN services s ON sb.service_id = s.service_id WHERE 1=1";
$params = [];
if ($search) {
    $like = "%$search%";
    $query .= " AND (sb.customer_name LIKE ? OR sb.reference_code LIKE ? OR sb.phone_number LIKE ? OR sb.email LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($filter) {
    $query .= " AND sb.status = ?";
    $params[] = $filter;
}
$query .= " ORDER BY 
    CASE 
        WHEN sb.created_at IS NOT NULL THEN sb.created_at
        ELSE CONCAT(sb.appointment_date, ' ', COALESCE(sb.appointment_time, '00:00:00'))
    END DESC,
    sb.booking_id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$services = $pdo->query("SELECT * FROM services ORDER BY service_name")->fetchAll();
$announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetchAll();
$prices = [];
foreach ($services as $s) {
    // Sort by numeric price value (lowest to highest)
    $stmt = $pdo->prepare("SELECT * FROM service_price_ranges WHERE service_id = ? ORDER BY CAST(price AS DECIMAL(10,2)) ASC");
    $stmt->execute([$s['service_id']]);
    $prices[$s['service_id']] = $stmt->fetchAll();
}

// Fetch inventory items for scan modal - matches actual inventory table structure
try {
    // Query inventory table with joins to services and active_ingredients tables
    $inventory = $pdo->query("
        SELECT 
            i.inventory_id,
            i.service_id,
            i.ai_id,
            i.barcode,
            i.stocks,
            i.expiry_date,
            s.service_name,
            a.name AS active_ingredient,
            a.name AS item_name
        FROM inventory i
        JOIN services s ON i.service_id = s.service_id
        LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
        WHERE i.barcode IS NOT NULL AND i.barcode != ''
        ORDER BY a.name, s.service_name
    ")->fetchAll();
} catch (PDOException $e) {
    // If inventory table doesn't exist, use empty array
    // The form will show "No inventory items found" message
    $inventory = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Techno Pest Control • Admin Dashboard</title>
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap');
        :root { 
            --primary: #10b981; 
            --primary-dark: #059669;
            --primary-light: #34d399;
            --primary-lighter: #6ee7b7;
            --secondary: #059669;
            --secondary-light: #10b981;
            --accent: #34d399;
            --success: #10b981;
            --success-dark: #059669;
            --success-light: #34d399;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --light: #f0fdf4;
            --border: #d1fae5;
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(34, 197, 94, 0.1));
            border-radius: 12px;
            margin-bottom: 1rem;
            border: 1px solid rgba(110, 231, 183, 0.2);
            box-shadow: 0 4px 15px rgba(5, 150, 105, 0.2);
        }
        .user-info i {
            font-size: 2rem;
            color: var(--green-300);
            text-shadow: 0 0 20px rgba(110, 231, 183, 0.6);
        }
        .user-info strong {
            display: block;
            color: white;
        }
        .user-info small {
            color: rgba(255,255,255,0.5);
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
        .page-title {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin: 0;
        }
        @media (max-width: 768px) {
            .page-title {
                font-size: 1.5rem;
            }
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
        @keyframes gradient-flow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
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
        .btn-danger-modern {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: white;
            box-shadow: 0 4px 20px rgba(239, 68, 68, 0.4);
        }
        .btn-danger-modern:hover {
            box-shadow: 0 6px 30px rgba(239, 68, 68, 0.6);
        }
        .btn-warning-modern {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
            box-shadow: 0 4px 20px rgba(245, 158, 11, 0.4);
        }
        .btn-warning-modern:hover {
            box-shadow: 0 6px 30px rgba(245, 158, 11, 0.6);
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
        .table-responsive table {
            min-width: 1000px;
            width: 100%;
        }
        @media (min-width: 1400px) {
            .table-responsive table {
                min-width: auto;
            }
        }
        .badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .search-bar .form-control-modern,
        .search-bar .form-select {
            flex: 1;
            min-width: 200px;
        }
        .search-bar button {
            white-space: nowrap;
        }
        .form-control-modern {
            padding: 0.875rem 1.5rem;
            border-radius: 12px;
            border: 2px solid var(--border);
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .form-control-modern:focus {
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
            outline: none;
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
        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
        }
        .stat-card p {
            opacity: 0.9;
            margin-top: 0.5rem;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
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
        }
        .table thead { background: linear-gradient(135deg, var(--green-600), var(--green-700)); color: white; box-shadow: 0 4px 15px rgba(5, 150, 105, 0.3); }
        
        /* ============================================
           NEW MODERN CALENDAR DESIGN - MINIMALIST & ELEGANT
           ============================================ */
        
        #calendar { 
            background: #ffffff;
            border-radius: 24px; 
            padding: 2.5rem; 
            box-shadow: 0 12px 50px rgba(16, 185, 129, 0.2), 0 4px 15px rgba(5, 150, 105, 0.15);
            width: 100%;
            max-width: 100%;
            border: 2px solid var(--green-100);
            overflow: hidden;
            min-height: 700px;
            position: relative;
        }
        
        #calendar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--green-400), var(--green-500), var(--green-600), var(--green-500), var(--green-400));
            background-size: 200% 100%;
            animation: gradientFlow 4s ease infinite;
            z-index: 1;
            box-shadow: 0 2px 10px rgba(16, 185, 129, 0.4);
        }
        
        @keyframes gradientFlow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        /* Calendar View Container */
        .fc-view-harness {
            border-radius: 16px;
            overflow: hidden;
            background: #ffffff;
            margin-top: 1rem;
            width: 100%;
            border: 1px solid #e2e8f0;
        }
        
        .fc-daygrid-body,
        .fc-timegrid-body {
            border-radius: 0 0 16px 16px;
            width: 100%;
        }
        
        .fc-daygrid-day-frame {
            min-height: 100px;
        }
        
        .fc-daygrid-day {
            min-width: 0;
        }
        
        .fc-scroller-liquid-absolute {
            width: 100% !important;
        }
        
        /* Clean Header Toolbar */
        .fc-header-toolbar {
            margin-bottom: 2rem !important;
            padding: 1.25rem 0 !important;
            background: transparent !important;
            border: none !important;
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 1.5rem !important;
            align-items: center !important;
        }
        
        .fc-toolbar-title {
            font-size: 1.875rem !important;
            font-weight: 800 !important;
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-transform: capitalize;
            letter-spacing: -0.5px;
        }
        
        /* Minimal Navigation Buttons */
        .fc-button {
            background: var(--green-50) !important;
            border: 2px solid var(--green-200) !important;
            color: var(--green-700) !important;
            padding: 0.625rem 1.25rem !important;
            border-radius: 10px !important;
            font-weight: 600 !important;
            font-size: 0.875rem !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1) !important;
        }
        
        .fc-button:hover {
            background: linear-gradient(135deg, var(--green-500), var(--green-600)) !important;
            border-color: var(--green-500) !important;
            color: white !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
        }
        
        .fc-button:active,
        .fc-button:focus {
            background: linear-gradient(135deg, var(--green-600), var(--green-700)) !important;
            border-color: var(--green-600) !important;
            color: white !important;
            outline: none !important;
            box-shadow: 0 4px 15px rgba(5, 150, 105, 0.5) !important;
        }
        
        .fc-button-primary:not(:disabled):active,
        .fc-button-primary:not(:disabled).fc-button-active {
            background: linear-gradient(135deg, var(--green-600), var(--green-700)) !important;
            border-color: var(--green-600) !important;
            color: white !important;
            box-shadow: 0 4px 15px rgba(5, 150, 105, 0.5) !important;
        }
        
        .fc-button:disabled {
            opacity: 0.3 !important;
            cursor: not-allowed !important;
            transform: none !important;
        }
        
        /* Clean Day Cells */
        .fc-daygrid-day {
            border: 1px solid #f1f5f9 !important;
            transition: all 0.2s ease !important;
            background: #ffffff !important;
            position: relative;
        }
        
        .fc-daygrid-day:hover {
            background: #f8fafc !important;
            border-color: #e2e8f0 !important;
        }
        
        .fc-daygrid-day-top {
            padding: 0.875rem 0.625rem 0.5rem !important;
        }
        
        .fc-daygrid-day-number {
            font-weight: 700 !important;
            color: #334155 !important;
            font-size: 0.95rem !important;
            padding: 0.5rem 0.625rem !important;
            transition: all 0.2s ease !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2rem;
            min-height: 2rem;
            border-radius: 8px;
        }
        
        .fc-daygrid-day-number:hover {
            background: var(--green-100) !important;
            color: var(--green-600) !important;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2) !important;
        }
        
        /* Other Month Days */
        .fc-day-other {
            background: #fafbfc !important;
            opacity: 0.6;
        }
        
        .fc-day-other .fc-daygrid-day-number {
            color: #cbd5e1 !important;
        }
        
        /* Clean Today Highlighting */
        .fc-day-today {
            background: linear-gradient(135deg, var(--green-50), var(--green-100)) !important;
            border: 3px solid var(--green-400) !important;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.2) !important;
        }
        
        .fc-day-today .fc-daygrid-day-number {
            color: white !important;
            font-weight: 800 !important;
            background: linear-gradient(135deg, var(--green-500), var(--green-600)) !important;
            border-radius: 50% !important;
            width: 2.5rem !important;
            height: 2.5rem !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.5), 0 0 20px rgba(34, 197, 94, 0.3) !important;
            animation: pulse-today 2s ease-in-out infinite;
        }
        @keyframes pulse-today {
            0%, 100% { box-shadow: 0 4px 15px rgba(16, 185, 129, 0.5), 0 0 20px rgba(34, 197, 94, 0.3); }
            50% { box-shadow: 0 4px 20px rgba(16, 185, 129, 0.7), 0 0 30px rgba(34, 197, 94, 0.5); }
        }
        
        /* Compact Rectangular Event Styling */
        .fc-event { 
            white-space: normal !important; 
            text-align: left !important; 
            font-weight: 600 !important; 
            border-radius: 4px !important;
            padding: 0.375rem 0.5rem !important;
            margin: 0.125rem 0.1rem !important;
            border: none !important;
            font-size: 0.75rem !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1) !important;
            overflow: hidden !important;
            position: relative;
            line-height: 1.3 !important;
        }
        
        .fc-event::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .fc-event:hover {
            transform: translateX(3px) scale(1.01) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            z-index: 10 !important;
        }
        
        .fc-event-title {
            font-weight: 600 !important;
            padding: 0 !important;
            line-height: 1.3 !important;
            display: block !important;
            margin-bottom: 0.1rem !important;
            font-size: 0.75rem !important;
        }
        
        .fc-event-time {
            font-weight: 500 !important;
            font-size: 0.6875rem !important;
            opacity: 0.95 !important;
            display: block !important;
        }
        
        .fc-event.announcement-event { 
            border-left: 3px solid white !important;
        }
        
        .fc-event.announcement-event::before {
            width: 3px;
        }
        
        /* Status-based Event Colors - Fallback if inline styles don't apply */
        .fc-event.status-pending {
            background-color: #22c55e;
            border-color: #22c55e;
        }
        
        .fc-event.status-in-progress {
            background-color: #f59e0b;
            border-color: #f59e0b;
        }
        
        .fc-event.status-completed {
            background-color: #10b981;
            border-color: #10b981;
        }
        
        .fc-event.status-cancelled {
            background-color: #ef4444;
            border-color: #ef4444;
        }
        
        /* Ensure status colors are visible and not overridden */
        .fc-event[style*="background-color"] {
            /* FullCalendar applies colors via inline styles, which is correct */
        }
        
        /* Event Dot */
        .fc-daygrid-event-dot {
            border-color: inherit !important;
            border-width: 4px !important;
        }
        
        /* Clean Column Headers */
        .fc-col-header {
            border-radius: 0;
            overflow: hidden;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .fc-col-header-cell {
            background: #f8fafc !important;
            color: #475569 !important;
            padding: 1rem 0.75rem !important;
            font-weight: 700 !important;
            font-size: 0.8125rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border-right: 1px solid #e2e8f0 !important;
            transition: all 0.2s ease !important;
            position: relative;
        }
        
        .fc-col-header-cell:last-child {
            border-right: none !important;
        }
        
        .fc-col-header-cell:hover {
            background: var(--green-50) !important;
            color: var(--green-700) !important;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.15) !important;
        }
        
        .fc-col-header-cell-cushion {
            color: inherit !important;
            font-weight: 700 !important;
        }
        
        /* Weekend Styling */
        .fc-col-header-cell.fc-day-sat,
        .fc-col-header-cell.fc-day-sun {
            background: var(--green-50) !important;
            color: var(--green-700) !important;
        }
        
        .fc-day-sat,
        .fc-day-sun {
            background: var(--green-50) !important;
        }
        
        /* Time Grid View */
        .fc-timegrid-slot {
            border-color: #f1f5f9 !important;
            height: 2.5rem !important;
        }
        
        .fc-timegrid-slot-label {
            font-size: 0.8125rem !important;
            color: #64748b !important;
            font-weight: 600 !important;
        }
        
        .fc-timegrid-col {
            border-color: #e2e8f0 !important;
        }
        
        /* Clean Scrollbar */
        .fc-scroller {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }
        
        .fc-scroller::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .fc-scroller::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 8px;
        }
        
        .fc-scroller::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 8px;
        }
        
        .fc-scroller::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Clean More Link */
        .fc-more-link {
            color: var(--green-700) !important;
            font-weight: 600 !important;
            font-size: 0.8125rem !important;
            padding: 0.375rem 0.75rem !important;
            border-radius: 6px !important;
            background: var(--green-50) !important;
            transition: all 0.3s ease !important;
            border: 2px solid var(--green-200);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1);
        }
        
        .fc-more-link:hover {
            color: white !important;
            background: linear-gradient(135deg, var(--green-500), var(--green-600)) !important;
            text-decoration: none !important;
            border-color: var(--green-500);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            transform: translateY(-1px);
        }
        
        /* Clean Popover */
        .fc-popover {
            border-radius: 12px !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15) !important;
            border: 1px solid #e2e8f0 !important;
            overflow: hidden;
        }
        
        .fc-popover-header {
            background: #f8fafc !important;
            color: #1e293b !important;
            padding: 1rem 1.25rem !important;
            font-weight: 700 !important;
            border-radius: 12px 12px 0 0 !important;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .fc-popover-body {
            padding: 1rem 1.25rem !important;
            background: white !important;
        }
        
        .fc-popover-close {
            color: #64748b !important;
            opacity: 0.7 !important;
            font-size: 1.25rem !important;
            transition: all 0.2s ease !important;
            font-weight: 700;
        }
        
        .fc-popover-close:hover {
            opacity: 1 !important;
            color: #1e293b !important;
        }
        
        /* List View */
        .fc-list-event:hover td {
            background: #f8fafc !important;
        }
        
        .fc-list-day-cushion {
            background: #f8fafc !important;
            color: #1e293b !important;
            font-weight: 700 !important;
            padding: 1rem 1.25rem !important;
            border-left: 3px solid #6366f1;
        }
        
        .fc-list-event-title {
            font-weight: 600 !important;
            color: #1e293b !important;
        }
        
        .fc-list-event-time {
            color: #6366f1 !important;
            font-weight: 600 !important;
        }
        
        /* Week Numbers */
        .fc-daygrid-week-number {
            background: #f8fafc !important;
            color: #6366f1 !important;
            font-weight: 700 !important;
            border-right: 1px solid #e2e8f0;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            #calendar {
                padding: 1.5rem;
                min-height: 600px;
            }
            
            .fc-header-toolbar {
                flex-direction: column !important;
                gap: 1rem !important;
                align-items: flex-start !important;
            }
            
            .fc-toolbar-title {
                font-size: 1.5rem !important;
            }
            
            .fc-button {
                padding: 0.5rem 1rem !important;
                font-size: 0.8125rem !important;
            }
            
            .fc-event {
                font-size: 0.6875rem !important;
                padding: 0.25rem 0.5rem !important;
            }
            
            .fc-event-title {
                font-size: 0.6875rem !important;
            }
            
            .fc-event-time {
                font-size: 0.625rem !important;
            }
            
            .fc-col-header-cell {
                padding: 0.875rem 0.5rem !important;
                font-size: 0.75rem !important;
            }
            
            .fc-daygrid-day-number {
                font-size: 0.875rem !important;
                padding: 0.375rem 0.5rem !important;
            }
            
            .announcement-card-header {
                padding: 1.25rem 1.25rem 0.875rem;
            }
            
            .announcement-card-title {
                font-size: 1rem;
            }
            
            .announcement-card-icon {
                width: 2.25rem;
                height: 2.25rem;
                font-size: 1rem;
            }
            
            .announcement-card-body {
                padding: 0 1.25rem 1.25rem;
            }
            
            .announcement-card-footer {
                padding: 0.875rem 1.25rem;
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 480px) {
            #calendar {
                padding: 1rem;
                min-height: 500px;
            }
            
            .fc-toolbar-title {
                font-size: 1.25rem !important;
            }
            
            .fc-button {
                padding: 0.5rem 0.875rem !important;
                font-size: 0.75rem !important;
            }
            
            .announcement-card-header {
                flex-direction: column;
                gap: 0.75rem;
            }
        }
        .table td { white-space: normal; }
        
        /* ============================================
           NEW MODERN ANNOUNCEMENTS DESIGN
           ============================================ */
        
        .announcement-badge { 
            background: linear-gradient(135deg, #ff6b6b, #ee5a52); 
            color: white; 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-size: 0.75rem; 
            font-weight: bold; 
        }
        
        /* Announcement Cards */
        .announcement-card {
            background: white;
            border-radius: 16px;
            padding: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.04);
            border: 1px solid #f1f5f9;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        
        .announcement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--announcement-color, #ff6b6b);
            transition: height 0.3s ease;
        }
        
        .announcement-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 4px 8px rgba(0, 0, 0, 0.08);
            border-color: #e2e8f0;
        }
        
        .announcement-card:hover::before {
            height: 5px;
        }
        
        .announcement-card-header {
            padding: 1.5rem 1.5rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }
        
        .announcement-card-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
            line-height: 1.4;
            flex: 1;
        }
        
        .announcement-card-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.125rem;
            flex-shrink: 0;
            background: var(--announcement-color-light, rgba(255, 107, 107, 0.1));
            color: var(--announcement-color, #ff6b6b);
        }
        
        .announcement-card-body {
            padding: 0 1.5rem 1.5rem;
            flex: 1;
        }
        
        .announcement-card-description {
            color: #64748b;
            font-size: 0.9375rem;
            line-height: 1.6;
            margin-bottom: 1.25rem;
            min-height: 3rem;
        }
        
        .announcement-card-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            background: #fafbfc;
        }
        
        .announcement-card-meta {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }
        
        .announcement-card-date,
        .announcement-card-time {
            font-size: 0.8125rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .announcement-card-date i,
        .announcement-card-time i {
            font-size: 0.875rem;
            color: #94a3b8;
        }
        
        .announcement-card-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .announcement-card-actions .btn {
            padding: 0.5rem 0.875rem;
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 600;
            border: none;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .announcement-card-actions .btn-warning-modern {
            background: #fef3c7;
            color: #92400e;
        }
        
        .announcement-card-actions .btn-warning-modern:hover {
            background: #fde68a;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(251, 191, 36, 0.3);
        }
        
        .announcement-card-actions .btn-danger-modern {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .announcement-card-actions .btn-danger-modern:hover {
            background: #fecaca;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.3);
        }
        
        .announcements-empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .announcements-empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1.5rem;
            display: block;
        }
        
        .announcements-empty-state p {
            color: #94a3b8;
            font-size: 1.0625rem;
        }
        
        /* Modal Styling */
        .modal-content {
            border-radius: 20px !important;
            border: none !important;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3) !important;
        }
        .modal-header {
            border-radius: 20px 20px 0 0 !important;
            border-bottom: 2px solid rgba(255,255,255,0.1) !important;
        }
        .modal-footer {
            border-top: 2px solid var(--border) !important;
            border-radius: 0 0 20px 20px !important;
        }
        .form-control, .form-select {
            border-radius: 12px !important;
            border: 2px solid var(--border) !important;
            padding: 0.75rem 1rem !important;
            transition: all 0.3s ease !important;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1) !important;
        }
        
        /* Scan Modal Enhanced Styles */
        #scanModal .form-select:focus,
        #scanModal .form-control:focus {
            border-color: #f59e0b !important;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15) !important;
        }
        
        #scanModal .card {
            transition: all 0.3s ease;
        }
        
        #scanModal .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1) !important;
        }
        
        #scanModal .input-group-text {
            transition: all 0.3s ease;
        }
        
        #scanModal .input-group:focus-within .input-group-text {
            background: linear-gradient(135deg, #f59e0b, #d97706) !important;
            transform: scale(1.05);
        }
        
        #scannedIngredientDisplay.alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(34, 197, 94, 0.05) 100%) !important;
            border-color: #10b981 !important;
            color: #059669;
        }
        
        #scannedIngredientDisplay.alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.05) 100%) !important;
            border-color: #ef4444 !important;
            color: #dc2626;
        }
        
        @keyframes gradientFlow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        /* Existing Pictures Gallery Styling */
        #existingPicturesContainer .position-relative {
            transition: all 0.3s ease;
        }
        
        #existingPicturesContainer .position-relative:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            border-color: var(--primary) !important;
        }
        
        #existingPicturesContainer img {
            transition: transform 0.3s ease;
        }
        
        #existingPicturesContainer img:hover {
            transform: scale(1.05);
        }
        
        #imageViewModal .modal-body {
            background: #1e293b;
        }
        
        #imageViewModal img {
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }
        
        /* Delete Button Styling */
        #existingPicturesContainer .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626) !important;
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        
        #existingPicturesContainer .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }
        
        #existingPicturesContainer .btn-light:hover {
            transform: scale(1.1);
        }
        
        /* Email Link Styling */
        #upload_customer_email_link,
        #status_customer_email_link {
            transition: all 0.3s ease;
            border-bottom: 2px solid transparent;
        }
        
        #upload_customer_email_link:hover,
        #status_customer_email_link:hover {
            color: var(--primary-dark) !important;
            border-bottom-color: var(--primary);
            transform: translateX(3px);
        }
        
        #upload_customer_email_link i,
        #status_customer_email_link i {
            transition: transform 0.3s ease;
        }
        
        #upload_customer_email_link:hover .bi-box-arrow-up-right,
        #status_customer_email_link:hover .bi-box-arrow-up-right {
            transform: translate(2px, -2px);
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
            <p>Admin Dashboard</p>
        </div>
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="#" class="nav-link active" data-tab="bookings">
                    <i class="bi bi-calendar-check"></i>
                    <span>Bookings</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="#" class="nav-link" data-tab="calendar-tab">
                    <i class="bi bi-calendar3"></i>
                    <span>Calendar</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="#" class="nav-link" data-tab="announcements">
                    <i class="bi bi-megaphone"></i>
                    <span>Announcements</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="#" class="nav-link" data-tab="services">
                    <i class="bi bi-tools"></i>
                    <span>Services & Prices</span>
                </a>
            </div>
        </nav>
        <div class="user-section">
            <div class="user-info">
                <i class="bi bi-person-circle"></i>
                <div>
                    <strong><?= h($_SESSION['username']) ?></strong>
                    <small>Administrator</small>
                </div>
            </div>
            <a href="dashboard.php" class="btn btn-modern btn-primary-modern w-100 mb-2">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="logout.php" class="btn btn-modern btn-danger-modern w-100">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <div class="top-bar">
            <h1 class="page-title" id="pageTitle">Bookings Management</h1>
            <div id="topBarActions"></div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 mb-4 shadow-sm">
                <i class="bi bi-check-circle me-2"></i><?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-4 mb-4 shadow-sm">
                <i class="bi bi-exclamation-circle me-2"></i><?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- BOOKINGS TAB -->
        <div class="tab-content active" id="bookings">
            <div class="content-card">
                <div class="mb-3">
                    <h2 class="mb-0" style="font-size: 1.5rem;"><i class="bi bi-calendar-check text-primary me-2"></i>All Bookings</h2>
                </div>
                <form method="get" class="search-bar">
                    <input type="text" name="search" class="form-control form-control-modern flex-grow-1" placeholder="Search by name, reference, email, or phone..." value="<?= h($search) ?>">
                    <select name="filter_status" class="form-select form-control-modern" style="max-width: 200px;">
                        <option value="">All Status</option>
                        <option value="Pending" <?= $filter==='Pending'?'selected':'' ?>>Pending</option>
                        <option value="In Progress" <?= $filter==='In Progress'?'selected':'' ?>>In Progress</option>
                        <option value="Completed" <?= $filter==='Completed'?'selected':'' ?>>Completed</option>
                        <option value="Cancelled" <?= $filter==='Cancelled'?'selected':'' ?>>Cancelled</option>
                    </select>
                    <button type="submit" class="btn btn-modern btn-primary-modern">
                        <i class="bi bi-search"></i> Filter
                    </button>
                </form>
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th style="min-width: 100px;">Reference</th>
                                <th style="min-width: 120px;">Customer</th>
                                <th style="min-width: 150px;">Email</th>
                                <th style="min-width: 110px;">Phone</th>
                                <th style="min-width: 100px;">Structure</th>
                                <th style="min-width: 120px;">Service</th>
                                <th style="min-width: 100px;">Price Range</th>
                                <th style="min-width: 120px;">Date & Time</th>
                                <th style="min-width: 130px;">Status</th>
                                <th style="min-width: 100px;">Upload</th>
                                <th style="min-width: 100px;">Scan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $b): 
                                $statusClass = match($b['status'] ?? 'Pending') {
                                    'Completed' => 'success',
                                    'In Progress' => 'warning',
                                    'Cancelled' => 'danger',
                                    default => 'secondary'
                                };
                            ?>
                            <tr>
                                <td><strong class="text-primary" style="font-size: 0.85rem;"><?= h($b['reference_code'] ?? 'N/A') ?></strong></td>
                                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= h($b['customer_name'] ?? 'Unknown') ?>"><?= h($b['customer_name'] ?? 'Unknown') ?></td>
                                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= h($b['email'] ?? '—') ?>"><?= h($b['email'] ?? '—') ?></td>
                                <td style="font-size: 0.85rem;"><?= h($b['phone_number'] ?? '—') ?></td>
                                <td><span class="badge badge-modern bg-<?= $statusClass ?>" style="font-size: 0.75rem; padding: 0.35rem 0.65rem;"><?= h($b['structure_types'] ?? '—') ?></span></td>
                                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= h($b['service_name'] ?? '—') ?>"><?= h($b['service_name'] ?? '—') ?></td>
                                <td style="font-size: 0.85rem;"><?= h(formatPriceRangeDisplay($b['price_range'] ?? null)) ?></td>
                                <td style="font-size: 0.85rem;">
                                    <strong><?= h($b['appointment_date'] ?? '') ?></strong><br>
                                    <small class="text-muted" style="font-size: 0.75rem;"><?= h($b['appointment_time'] ?? '') ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-modern bg-<?= $statusClass ?>" style="font-size: 0.75rem; padding: 0.35rem 0.65rem; margin-bottom: 0.25rem; display: block;">
                                        <?= h($b['status'] ?? 'Pending') ?>
                                    </span>
                                    <button class="btn btn-sm" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: linear-gradient(135deg, var(--success), #059669); color: white; border: none; border-radius: 6px; width: 100%;" onclick="openStatusModal(<?= $b['booking_id'] ?>, <?= htmlspecialchars(json_encode($b['status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($b['reference_code'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($b['customer_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($b['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($b['appointment_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($b['appointment_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)" title="Update Status & Reschedule">
                                        <i class="bi bi-send"></i> Update
                                    </button>
                                </td>
                                <td>
                                    <button class="btn btn-sm" style="padding: 0.4rem 0.75rem; font-size: 0.8rem; background: linear-gradient(135deg, var(--green-600), var(--green-700)); color: white; border: none; border-radius: 8px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);" onclick="openUploadModal(<?= $b['booking_id'] ?>, '<?= h($b['reference_code']) ?>', '<?= h($b['customer_name']) ?>', '<?= h($b['email']) ?>')" title="Upload Pictures">
                                        <i class="bi bi-image"></i> Upload
                                    </button>
                                </td>
                                <td>
                                    <button class="btn btn-sm" style="padding: 0.4rem 0.75rem; font-size: 0.8rem; background: linear-gradient(135deg, #f59e0b, #d97706); color: white; border: none; border-radius: 8px;" data-bs-toggle="modal" data-bs-target="#scanModal" onclick="openScanModal(<?= $b['booking_id'] ?>, '<?= h($b['reference_code']) ?>', '<?= h($b['customer_name']) ?>')" title="Scan & Deduct Inventory">
                                        <i class="bi bi-upc-scan"></i> Scan
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($bookings)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                    No bookings found
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- CALENDAR TAB -->
        <div class="tab-content" id="calendar-tab">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0"><i class="bi bi-calendar3 text-primary me-2"></i>Booking Schedules & Announcements</h2>
                </div>
                <div id="calendar"></div>
            </div>
        </div>

        <!-- ANNOUNCEMENTS TAB -->
        <div class="tab-content" id="announcements">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0"><i class="bi bi-megaphone text-primary me-2"></i>Calendar Announcements</h2>
                </div>
                <div class="row g-4">
                    <?php foreach ($announcements as $ann): 
                        $annColor = $ann['color'] ?? '#10b981';
                        // Convert hex to rgba for lighter background
                        $rgb = sscanf($annColor, '#%02x%02x%02x');
                        $rgbStr = $rgb ? implode(', ', $rgb) : '16, 185, 129';
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="announcement-card" style="--announcement-color: <?= h($annColor) ?>; --announcement-color-light: rgba(<?= $rgbStr ?>, 0.1);">
                            <div class="announcement-card-header">
                                <h5 class="announcement-card-title"><?= h($ann['title']) ?></h5>
                                <div class="announcement-card-icon">
                                    <i class="bi bi-megaphone"></i>
                                </div>
                            </div>
                            <div class="announcement-card-body">
                                <?php if ($ann['description']): ?>
                                <div class="announcement-card-description">
                                    <?= nl2br(h($ann['description'])) ?>
                                </div>
                                <?php else: ?>
                                <div class="announcement-card-description text-muted" style="font-style: italic;">
                                    No description provided
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="announcement-card-footer">
                                <div class="announcement-card-meta">
                                    <div class="announcement-card-date">
                                        <i class="bi bi-calendar3"></i>
                                        <span><?= date('M d, Y', strtotime($ann['announcement_date'])) ?></span>
                                    </div>
                                    <div class="announcement-card-time">
                                        <i class="bi bi-clock"></i>
                                        <span><?= $ann['announcement_time'] ? h($ann['announcement_time']) : 'All Day' ?></span>
                                    </div>
                                </div>
                                <div class="announcement-card-actions">
                                    <button class="btn btn-warning-modern" onclick="editAnnouncement(<?= htmlspecialchars(json_encode($ann)) ?>)" title="Edit announcement">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this announcement?')">
                                        <input type="hidden" name="announcement_id" value="<?= $ann['announcement_id'] ?>">
                                        <button type="submit" name="delete_announcement" class="btn btn-danger-modern" title="Delete announcement">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($announcements)): ?>
                    <div class="col-12">
                        <div class="announcements-empty-state">
                            <i class="bi bi-megaphone"></i>
                            <p>No announcements yet. Add one to get started!</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- SERVICES & PRICES TAB -->
        <div class="tab-content" id="services">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0"><i class="bi bi-tools text-primary me-2"></i>Services & Prices</h2>
                </div>
                <div class="row g-4">
                    <?php foreach ($services as $s): ?>
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-lg h-100" style="border-left: 5px solid var(--primary) !important; border-radius: 16px;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <h5 class="text-primary fw-bold mb-0"><?= h($s['service_name']) ?></h5>
                                    <span class="badge badge-modern bg-primary"><?= h($s['service_type']) ?></span>
                                </div>
                                <p class="text-muted mb-3"><?= nl2br(h($s['service_details'])) ?></p>
                                <hr>
                                <h6 class="fw-bold mb-3"><i class="bi bi-currency-dollar me-2"></i>Pricing:</h6>
                                <div class="mb-3">
                                    <?php foreach ($prices[$s['service_id']] ?? [] as $p): ?>
                                    <div class="d-flex justify-content-between align-items-center py-2 px-3 mb-2" style="background: rgba(16, 185, 129, 0.05); border-radius: 8px;">
                                        <span class="fw-semibold text-dark flex-grow-1"><?= h(formatPriceRangeDisplay($p['price_range'] ?? '', $p['price'] ?? null)) ?></span>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-warning-modern" onclick="editPrice(<?= htmlspecialchars(json_encode($p)) ?>)" title="Edit price">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this price?')">
                                                <input type="hidden" name="price_range_id" value="<?= $p['price_range_id'] ?>">
                                                <button type="submit" name="delete_price" class="btn btn-sm btn-danger-modern" title="Delete price">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($prices[$s['service_id']] ?? [])): ?>
                                    <p class="text-muted text-center py-3">No pricing set yet</p>
                                    <?php endif; ?>
                                </div>
                                <button class="btn btn-modern btn-primary-modern w-100" onclick="openPriceModal(<?= $s['service_id'] ?>, '<?= h(addslashes($s['service_name'])) ?>')">
                                    <i class="bi bi-plus-circle"></i> Add Price
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($services)): ?>
                    <div class="col-12 text-center py-5">
                        <i class="bi bi-tools fs-1 text-muted d-block mb-3"></i>
                        <p class="text-muted">No services yet. Add one to get started!</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
</div>

<!-- ALL MODALS -->
<div class="modal fade" id="addAnnouncementModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--green-600), var(--green-700));">
                    <h5><i class="bi bi-megaphone me-2"></i>Add Announcement</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="announcement_id" id="edit_announcement_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Title</label>
                        <input type="text" name="announcement_title" id="edit_announcement_title" class="form-control" placeholder="Announcement Title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="announcement_description" id="edit_announcement_description" class="form-control" rows="4" placeholder="Announcement details..."></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date</label>
                            <input type="date" name="announcement_date" id="edit_announcement_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Time (optional)</label>
                            <input type="text" name="announcement_time" id="edit_announcement_time" class="form-control" placeholder="10:00 AM or leave blank for All Day">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-bold">Color</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <input type="color" name="announcement_color" id="edit_announcement_color" class="form-control form-control-color" value="#10b981" title="Choose color">
                            <span class="badge" style="background: #10b981;">Green</span>
                            <span class="badge" style="background: #22c55e;">Light Green</span>
                            <span class="badge" style="background: #059669;">Dark Green</span>
                            <span class="badge" style="background: #34d399;">Mint</span>
                            <span class="badge" style="background: #f59e0b;">Orange</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-modern" style="background: var(--border); color: var(--dark);" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_announcement" id="submit_announcement_btn" class="btn btn-modern btn-success-modern">
                        <i class="bi bi-megaphone me-2"></i>Add Announcement
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="uploadPicturesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="post" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--green-600), var(--green-700));">
                    <h5><i class="bi bi-image me-2"></i>Upload Pictures & View Contracts</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="booking_id" id="upload_booking_id">
                    <div class="mb-3">
                        <p class="mb-2"><strong>Booking Reference:</strong> <span id="upload_reference_code" class="text-primary"></span></p>
                        <p class="mb-2"><strong>Customer:</strong> <span id="upload_customer_name"></span></p>
                        <p class="mb-3">
                            <strong>Email:</strong> 
                            <a href="#" id="upload_customer_email_link" class="text-primary text-decoration-none" onclick="openGmailCompose(event, 'upload')">
                                <i class="bi bi-envelope me-1"></i><span id="upload_customer_email"></span>
                                <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.75rem;"></i>
                            </a>
                        </p>
                    </div>
                    
                    <!-- Existing Pictures Section -->
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-images me-2"></i>Existing Contracts/Pictures</h6>
                        <div id="existingPicturesContainer" class="row g-3">
                            <div class="col-12 text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="text-muted mt-2">Loading pictures...</p>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <!-- Upload New Pictures Section -->
                    <div class="mb-3">
                        <h6 class="fw-bold mb-3"><i class="bi bi-cloud-upload me-2"></i>Upload New Pictures</h6>
                        <label class="form-label fw-bold">Select Pictures (Multiple files allowed)</label>
                        <input type="file" name="pictures[]" id="pictures_input" class="form-control" multiple accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                        <small class="text-muted">Allowed formats: JPEG, PNG, GIF, WebP. Max size: 20MB per file. Leave empty if you only want to view existing pictures.</small>
                    </div>
                    <div id="imagePreview" class="row g-2 mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-modern" style="background: var(--border); color: var(--dark);" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="upload_pictures" class="btn btn-modern btn-primary-modern">
                        <i class="bi bi-upload me-2"></i>Upload New Pictures
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addServiceModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--success), #059669);"><h5><i class="bi bi-plus-circle me-2"></i>Add Service</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="text" name="service_name" class="form-control mb-3" placeholder="Service Name" required>
                    <input type="text" name="service_type" class="form-control mb-3" placeholder="Type" required>
                    <textarea name="service_details" class="form-control" rows="4" placeholder="Details"></textarea>
                </div>
                <div class="modal-footer"><button name="add_service" class="btn btn-modern btn-success-modern"><i class="bi bi-check-circle me-2"></i>Add</button></div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editServiceModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--warning), #d97706);"><h5><i class="bi bi-pencil me-2"></i>Edit Service</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="service_id" id="edit_service_id">
                    <input type="text" name="service_name" id="edit_service_name" class="form-control mb-3" required>
                    <input type="text" name="service_type" id="edit_service_type" class="form-control mb-3" required>
                    <textarea name="service_details" id="edit_service_details" class="form-control" rows="4"></textarea>
                </div>
                <div class="modal-footer"><button name="edit_service" class="btn btn-modern btn-warning-modern"><i class="bi bi-check-circle me-2"></i>Update</button></div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="priceModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--green-600), var(--green-700));"><h5 id="priceModalLabel"><i class="bi bi-currency-dollar me-2"></i>Add Price</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="service_id" id="price_service_id">
                    <input type="text" name="price_range" class="form-control mb-3" placeholder="Range (e.g. 50-70)" required>
                    <input type="number" step="0.01" name="price" class="form-control" placeholder="Price" required>
                </div>
                <div class="modal-footer"><button name="add_price" class="btn btn-modern btn-primary-modern"><i class="bi bi-check-circle me-2"></i>Add</button></div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--warning), #d97706);"><h5><i class="bi bi-pencil me-2"></i>Edit Price</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="price_range_id" id="edit_price_range_id">
                    <input type="text" name="price_range" id="edit_price_range" class="form-control mb-3" required>
                    <input type="number" step="0.01" name="price" id="edit_price" class="form-control" required>
                </div>
                <div class="modal-footer"><button name="edit_price" class="btn btn-modern btn-warning-modern"><i class="bi bi-check-circle me-2"></i>Update</button></div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="statusUpdateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--success), #059669);">
                    <h5><i class="bi bi-send me-2"></i>Update Status & Send Message</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="booking_id" id="status_booking_id">
                    <input type="hidden" name="update_status" value="1">
                    
                    <div class="mb-3">
                        <p class="mb-2"><strong>Booking Reference:</strong> <span id="status_reference_code" class="text-primary"></span></p>
                        <p class="mb-2"><strong>Customer:</strong> <span id="status_customer_name"></span></p>
                        <p class="mb-3">
                            <strong>Email:</strong> 
                            <a href="#" id="status_customer_email_link" class="text-primary text-decoration-none" onclick="openGmailCompose(event, 'status')">
                                <i class="bi bi-envelope me-1"></i><span id="status_customer_email"></span>
                                <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.75rem;"></i>
                            </a>
                        </p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Update Status</label>
                        <select name="new_status" id="status_new_status" class="form-select">
                            <option value="">Select Status</option>
                            <option value="Pending">Pending</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                        <small class="text-muted">Required only when updating status (not for rescheduling)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            Message to Customer <small class="text-muted">(Optional)</small>
                        </label>
                        <textarea name="admin_message" id="status_admin_message" class="form-control" rows="6" placeholder="Add a custom message to include in the email notification. If left blank, a default message will be sent based on the selected status."></textarea>
                        <small class="text-muted">This message will be included in the email sent to the customer.</small>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="mb-3">
                        <h6 class="fw-bold mb-3"><i class="bi bi-calendar-check me-2"></i>Reschedule Appointment</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">New Appointment Date</label>
                                <input type="date" name="new_appointment_date" id="status_new_appointment_date" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">New Appointment Time</label>
                                <input type="text" name="new_appointment_time" id="status_new_appointment_time" class="form-control" placeholder="e.g., 10:00 AM or All Day">
                            </div>
                        </div>
                        <small class="text-muted">Leave empty to keep current appointment date and time.</small>
                        <div class="mt-3">
                            <button type="submit" name="reschedule_booking" class="btn btn-modern btn-warning-modern">
                                <i class="bi bi-calendar-event me-2"></i>Reschedule Appointment
                            </button>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> An email notification will be sent to the customer when you update the status or reschedule.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-modern" style="background: var(--border); color: var(--dark);" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_status" id="update_status_btn" class="btn btn-modern btn-success-modern">
                        <i class="bi bi-send me-2"></i>Update Status & Send Email
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Handle form validation for status update modal
document.addEventListener('DOMContentLoaded', function() {
    const statusUpdateForm = document.querySelector('#statusUpdateModal form');
    const statusField = document.getElementById('status_new_status');
    const updateStatusBtn = document.getElementById('update_status_btn');
    const rescheduleBtn = document.querySelector('button[name="reschedule_booking"]');
    
    if (statusUpdateForm && updateStatusBtn && rescheduleBtn) {
        // When Update Status button is clicked, make status field required
        updateStatusBtn.addEventListener('click', function(e) {
            if (!statusField.value) {
                e.preventDefault();
                statusField.setAttribute('required', 'required');
                statusField.focus();
                alert('Please select a status to update.');
                return false;
            }
            statusField.setAttribute('required', 'required');
        });
        
        // When Reschedule button is clicked, remove required from status field
        rescheduleBtn.addEventListener('click', function(e) {
            const newDate = document.getElementById('status_new_appointment_date').value;
            const newTime = document.getElementById('status_new_appointment_time').value;
            
            if (!newDate && !newTime) {
                e.preventDefault();
                alert('Please provide a new appointment date or time to reschedule.');
                return false;
            }
            statusField.removeAttribute('required');
        });
    }
});
</script>

<!-- Scan & Deduct Modal -->
<div class="modal fade" id="scanModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content overflow-hidden border-0" style="border-radius: 24px; box-shadow: 0 30px 80px rgba(0,0,0,.7);">
            <!-- Enhanced Header -->
            <div class="position-relative">
                <div class="text-white text-center py-5 px-4" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%); border-radius: 24px 24px 0 0; position: relative; overflow: hidden;">
                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 6px; background: linear-gradient(90deg, #f59e0b, #d97706, #b45309, #d97706, #f59e0b); background-size: 200% 100%; animation: gradientFlow 3s ease infinite;"></div>
                    <div class="mb-3" style="font-size: 4rem; opacity: 0.9;">
                        <i class="bi bi-upc-scan"></i>
                    </div>
                    <h2 class="fw-black mb-2" style="font-size: 2rem; text-shadow: 0 2px 10px rgba(0,0,0,0.3);">
                        <i class="bi bi-upc-scan me-2"></i> Scan & Deduct Inventory
                    </h2>
                    <p class="lead mb-0 opacity-95" style="font-size: 1.1rem;">Scan barcode or select item to deduct from inventory</p>
                    <div class="mt-3">
                        <span class="badge bg-white text-warning px-3 py-2" style="font-size: 0.85rem; font-weight: 600;">
                            <i class="bi bi-info-circle me-1"></i> Plug & Play Scanner Ready
                        </span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-4" style="background: rgba(255,255,255,0.2); border-radius: 50%; padding: 0.75rem;" data-bs-dismiss="modal"></button>
            </div>
            
            <!-- Modal Body -->
            <div class="p-4 p-md-5" style="background: linear-gradient(135deg, #fefefe 0%, #f9fafb 100%);">
                <!-- Booking Info Card -->
                <div class="alert alert-light border-2 border-warning mb-4" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-radius: 16px;">
                    <div class="d-flex align-items-center">
                        <div class="me-3" style="font-size: 2rem; color: #f59e0b;">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div>
                            <strong class="d-block mb-1" style="color: #92400e;">Booking Information</strong>
                            <div class="text-muted" style="font-size: 0.9rem;">
                                <span id="scanBookingInfo">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <form id="scanForm">
                    <!-- Service Selection Section -->
                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: white;">
                        <div class="card-header bg-transparent border-0 pb-2 pt-4 px-4">
                            <h5 class="mb-0" style="color: #1e293b; font-weight: 700;">
                                <i class="bi bi-list-check me-2" style="color: #f59e0b;"></i>Service Selection
                            </h5>
                        </div>
                        <div class="card-body px-4 pb-4">
                            <label class="form-label fw-bold mb-2" style="color: #334155;">
                                <i class="bi bi-tools me-1"></i>Service Type <span class="text-danger">*</span>
                            </label>
                            <select id="scanServiceType" class="form-select form-select-lg" required style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 0.875rem 1.25rem; transition: all 0.3s ease;">
                                <option value="">-- Select Service Type --</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?= h($service['service_id']) ?>" data-service-name="<?= h($service['service_name']) ?>">
                                        <?= h($service['service_name']) ?> - <?= h($service['service_type']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted mt-1 d-block">
                                <i class="bi bi-info-circle me-1"></i>Select the service type for this booking
                            </small>
                        </div>
                    </div>

                    <!-- Barcode Scanning Section -->
                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: white;">
                        <div class="card-header bg-transparent border-0 pb-2 pt-4 px-4">
                            <h5 class="mb-0" style="color: #1e293b; font-weight: 700;">
                                <i class="bi bi-upc-scan me-2" style="color: #f59e0b;"></i>Barcode Scanning
                            </h5>
                        </div>
                        <div class="card-body px-4 pb-4">
                            <div class="mb-4">
                                <label class="form-label fw-bold mb-2" style="color: #334155;">
                                    <i class="bi bi-upc me-1"></i>Scan Barcode or Enter Manually
                                </label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-warning text-white border-0" style="border-radius: 12px 0 0 12px;">
                                        <i class="bi bi-upc-scan fs-4"></i>
                                    </span>
                                    <input type="text" id="manualBarcode" class="form-control border-0" placeholder="Scan barcode here or type manually..." autocomplete="off" style="border-radius: 0 12px 12px 0; padding: 1rem 1.25rem; background: #f9fafb; font-size: 1.1rem;">
                                </div>
                                <small class="text-muted mt-2 d-block">
                                    <i class="bi bi-info-circle me-1"></i>The scanner will automatically populate when a barcode is detected
                                </small>
                            </div>

                            <!-- Scanned Ingredient Display -->
                            <div id="scannedIngredientDisplay" class="alert mb-0" style="display: none; border-radius: 12px; border: 2px solid;">
                                <div class="d-flex align-items-start">
                                    <div class="me-3" style="font-size: 2rem;">
                                        <i class="bi bi-check-circle-fill"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="fw-bold mb-3">
                                            <i class="bi bi-check-circle me-2"></i>Scanned Ingredient Found
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="p-3 rounded" style="background: rgba(16, 185, 129, 0.1); border-left: 4px solid #10b981;">
                                                    <small class="text-muted d-block mb-1">Ingredient Name</small>
                                                    <strong id="scannedIngredientName" style="color: #059669;">-</strong>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="p-3 rounded" style="background: rgba(99, 102, 241, 0.1); border-left: 4px solid #6366f1;">
                                                    <small class="text-muted d-block mb-1">Barcode</small>
                                                    <strong id="scannedIngredientBarcode" class="font-monospace" style="color: #4f46e5;">-</strong>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="p-3 rounded" style="background: rgba(245, 158, 11, 0.1); border-left: 4px solid #f59e0b;">
                                                    <small class="text-muted d-block mb-1">Available Stock</small>
                                                    <strong id="scannedIngredientStock" class="badge bg-warning text-dark px-3 py-2" style="font-size: 1rem;">-</strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ingredient Selection Section -->
                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: white;">
                        <div class="card-header bg-transparent border-0 pb-2 pt-4 px-4">
                            <h5 class="mb-0" style="color: #1e293b; font-weight: 700;">
                                <i class="bi bi-box-seam me-2" style="color: #f59e0b;"></i>Ingredient Selection
                            </h5>
                        </div>
                        <div class="card-body px-4 pb-4">
                            <label class="form-label fw-bold mb-2" style="color: #334155;">
                                <i class="bi bi-box me-1"></i>Ingredient/Item <span class="text-danger">*</span>
                            </label>
                            <select id="scanIngredient" class="form-select form-select-lg" required style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 0.875rem 1.25rem; transition: all 0.3s ease;">
                                <option value="">-- Select Ingredient from Inventory --</option>
                                <?php foreach ($inventory as $item): 
                                    $itemId = $item['inventory_id'] ?? '';
                                    $barcode = $item['barcode'] ?? '';
                                    $itemName = $item['active_ingredient'] ?? $item['item_name'] ?? '';
                                    $stocks = $item['stocks'] ?? 0;
                                    $serviceName = $item['service_name'] ?? '';
                                ?>
                                    <option value="<?= h($itemId) ?>" 
                                            data-barcode="<?= h($barcode) ?>"
                                            data-item-name="<?= h($itemName) ?>"
                                            data-quantity="<?= h($stocks) ?>"
                                            data-service-name="<?= h($serviceName) ?>">
                                        <?= h($itemName ?: 'Unknown') ?> 
                                        <?php if ($serviceName): ?>
                                            (<?= h($serviceName) ?>)
                                        <?php endif; ?>
                                        <?php if ($stocks !== null): ?>
                                            — Stock: <?= number_format((float)$stocks, 1) ?> bottles
                                        <?php endif; ?>
                                        <?php if ($barcode): ?>
                                            — Barcode: <?= h($barcode) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (empty($inventory)): ?>
                                    <option value="" disabled>No inventory items found</option>
                                <?php endif; ?>
                            </select>
                            <small class="text-muted mt-1 d-block">
                                <i class="bi bi-info-circle me-1"></i>Select ingredient from inventory or scan barcode above
                            </small>
                        </div>
                    </div>

                    <!-- Deduction Details Section -->
                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: white;">
                        <div class="card-header bg-transparent border-0 pb-2 pt-4 px-4">
                            <h5 class="mb-0" style="color: #1e293b; font-weight: 700;">
                                <i class="bi bi-calculator me-2" style="color: #f59e0b;"></i>Deduction Details
                            </h5>
                        </div>
                        <div class="card-body px-4 pb-4">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold mb-2" style="color: #334155;">
                                        <i class="bi bi-123 me-1"></i>Quantity to Deduct <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text bg-warning text-white border-0" style="border-radius: 12px 0 0 12px;">
                                            <i class="bi bi-dash-circle"></i>
                                        </span>
                                        <input type="number" id="deductQuantity" class="form-control border-0" value="1" min="0.1" step="0.1" required style="border-radius: 0 12px 12px 0; padding: 1rem 1.25rem; background: #f9fafb; font-size: 1.1rem;">
                                    </div>
                                    <small class="text-muted mt-1 d-block">
                                        <i class="bi bi-info-circle me-1"></i>Enter the quantity to deduct from inventory
                                    </small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold mb-2" style="color: #334155;">
                                        <i class="bi bi-sticky me-1"></i>Notes <small class="text-muted">(Optional)</small>
                                    </label>
                                    <textarea id="deductNotes" class="form-control" rows="3" placeholder="Add any notes about this deduction..." style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 0.875rem 1.25rem; resize: none;"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Result Display -->
                    <div id="scanResult" class="alert mb-4" style="display: none; border-radius: 12px;"></div>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-3 justify-content-end mt-4">
                        <button type="button" class="btn btn-lg px-4 py-3" style="background: #f3f4f6; color: #1e293b; border: none; border-radius: 12px; font-weight: 600;" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-warning btn-lg px-5 py-3 shadow-lg" style="border-radius: 12px; font-weight: 700; background: linear-gradient(135deg, #f59e0b, #d97706); border: none;">
                            <i class="bi bi-check-circle me-2"></i>Deduct from Inventory
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script>
let calendar = null;
let calendarInitialized = false;

document.addEventListener('DOMContentLoaded', function () {
    // Tab Navigation
    const navLinks = document.querySelectorAll('.nav-link[data-tab]');
    const tabContents = document.querySelectorAll('.tab-content');
    const pageTitle = document.getElementById('pageTitle');
    const topBarActions = document.getElementById('topBarActions');
    
    const pageTitles = {
        'bookings': 'Bookings Management',
        'calendar-tab': 'Calendar View',
        'announcements': 'Announcements',
        'services': 'Services & Prices'
    };
    
    // Check for tab parameter in URL and activate that tab
    // After processing, remove tab from URL so refresh goes to bookings
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    const successParam = urlParams.get('success');
    const errorParam = urlParams.get('error');
    
    // Default to bookings if no tab parameter
    let targetTab = tabParam || 'bookings';
    
    // Activate the target tab
    const targetLink = document.querySelector(`.nav-link[data-tab="${targetTab}"]`);
    const targetContent = document.getElementById(targetTab);
    
    if (targetLink && targetContent) {
        // Update active nav link
        navLinks.forEach(nl => nl.classList.remove('active'));
        targetLink.classList.add('active');
        
        // Update active tab content
        tabContents.forEach(tc => tc.classList.remove('active'));
        targetContent.classList.add('active');
        
        // Update page title
        if (pageTitle) {
            pageTitle.textContent = pageTitles[targetTab] || 'Dashboard';
        }
        
        // Update top bar actions
        if (topBarActions) {
            topBarActions.innerHTML = '';
            if (targetTab === 'bookings') {
                topBarActions.innerHTML = '';
            } else if (targetTab === 'announcements') {
                topBarActions.innerHTML = '<button class="btn btn-modern btn-success-modern" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal"><i class="bi bi-megaphone me-2"></i>Add Announcement</button>';
            } else if (targetTab === 'calendar-tab') {
                topBarActions.innerHTML = '';
            } else if (targetTab === 'services') {
                topBarActions.innerHTML = '<button class="btn btn-modern btn-success-modern" data-bs-toggle="modal" data-bs-target="#addServiceModal"><i class="bi bi-plus-circle me-2"></i>Add Service</button>';
            }
        }
        
        // Initialize calendar if calendar tab is selected
        if (targetTab === 'calendar-tab') {
            setTimeout(() => {
                initCalendar();
            }, 300);
        }
    } else {
        // Fallback: ensure bookings is active
        const bookingsLink = document.querySelector('.nav-link[data-tab="bookings"]');
        const bookingsContent = document.getElementById('bookings');
        if (bookingsLink && bookingsContent) {
            navLinks.forEach(nl => nl.classList.remove('active'));
            tabContents.forEach(tc => tc.classList.remove('active'));
            bookingsLink.classList.add('active');
            bookingsContent.classList.add('active');
            if (pageTitle) {
                pageTitle.textContent = pageTitles['bookings'] || 'Dashboard';
            }
            if (topBarActions) {
                topBarActions.innerHTML = '';
            }
        }
    }
    
    // Remove tab parameter from URL after processing (but keep success/error messages)
    // This ensures that when user refreshes, they go back to bookings
    if (tabParam) {
        setTimeout(() => {
            const newParams = new URLSearchParams();
            if (successParam) newParams.set('success', successParam);
            if (errorParam) newParams.set('error', errorParam);
            const newUrl = window.location.pathname + (newParams.toString() ? '?' + newParams.toString() : '');
            window.history.replaceState({}, '', newUrl);
        }, 500);
    }
    
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetTab = this.getAttribute('data-tab');
            
            // Update active nav link
            navLinks.forEach(nl => nl.classList.remove('active'));
            this.classList.add('active');
            
            // Update active tab content
            tabContents.forEach(tc => tc.classList.remove('active'));
            const targetContent = document.getElementById(targetTab);
            if (targetContent) {
                targetContent.classList.add('active');
            }
            
            // Update page title
            if (pageTitle) {
                pageTitle.textContent = pageTitles[targetTab] || 'Dashboard';
            }
            
            // Update top bar actions
            if (topBarActions) {
                topBarActions.innerHTML = '';
                if (targetTab === 'bookings') {
                    topBarActions.innerHTML = '';
                } else if (targetTab === 'announcements') {
                    topBarActions.innerHTML = '<button class="btn btn-modern btn-success-modern" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal"><i class="bi bi-megaphone me-2"></i>Add Announcement</button>';
                } else if (targetTab === 'calendar-tab') {
                    topBarActions.innerHTML = ''; // No add button here to avoid redundancy - use Announcements tab instead
                } else if (targetTab === 'services') {
                    topBarActions.innerHTML = '<button class="btn btn-modern btn-success-modern" data-bs-toggle="modal" data-bs-target="#addServiceModal"><i class="bi bi-plus-circle me-2"></i>Add Service</button>';
                }
            }
            
            // Initialize calendar if calendar tab is selected
            if (targetTab === 'calendar-tab') {
                setTimeout(() => {
                    initCalendar();
                }, 300);
            }
        });
    });
    
    // Function to initialize calendar
    const calendarEl = document.getElementById('calendar');
    
    function initCalendar() {
        if (calendarInitialized) {
            // Calendar already initialized, just update size
            if (calendar) {
                setTimeout(() => {
                    calendar.updateSize();
                    calendar.render();
                }, 100);
            }
            return;
        }
        
        // Check if calendar tab is active
        const calendarTab = document.getElementById('calendar-tab');
        if (!calendarEl || !calendarTab || !calendarTab.classList.contains('active')) {
            return;
        }
        
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
            events: '?calendar=events',
            eventContent: function(arg) {
                const p = arg.event.extendedProps || {};
                const isAnnouncement = p.type === 'announcement';
                
                // Helper function to escape HTML and handle null/undefined
                const escapeHtml = (text) => {
                    if (!text) return '';
                    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                };
                
                // Helper function to safely truncate text
                const truncate = (text, maxLength) => {
                    if (!text || typeof text !== 'string') return '';
                    return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
                };
                
                if (isAnnouncement) {
                    const title = escapeHtml(p.title || arg.event.title || '');
                    const description = p.description ? truncate(escapeHtml(p.description), 40) : '';
                    
                    return {
                        html: `
                        <div style="padding:6px 8px; color:white; font-weight:600; text-align:left; line-height:1.3;">
                            <div style="font-size:11px; margin-bottom:2px; font-weight:700;">📢 ${title}</div>
                            ${description ? `<div style="font-size:9px; opacity:0.9; margin:2px 0;">${description}</div>` : ''}
                            <div style="background:rgba(255,255,255,0.3); border-radius:3px; padding:2px 6px; font-size:9px; display:inline-block; margin-top:3px; font-weight:600;">
                                ANNOUNCEMENT
                            </div>
                        </div>`
                    };
                } else {
                    const customer = escapeHtml(p.customer || '');
                    const time = escapeHtml(p.time || '');
                    const reference = escapeHtml(p.reference || '');
                    
                    return {
                        html: `
                        <div style="padding:6px 8px; color:white; font-weight:600; text-align:left; line-height:1.3;">
                            <div style="font-size:11px; margin-bottom:2px; font-weight:700;">${customer || 'Customer'}</div>
                            ${time ? `<div style="font-size:10px; margin:2px 0; opacity:0.95;">${time}</div>` : ''}
                            ${reference ? `<div style="background:rgba(255,255,255,0.35); border-radius:3px; padding:2px 6px; font-size:9px; display:inline-block; margin-top:3px; font-weight:700;">
                                ${reference}
                            </div>` : ''}
                        </div>`
                    };
                }
            },
            eventDidMount: function(info) {
                const isAnnouncement = info.event.extendedProps.type === 'announcement';
                const props = info.event.extendedProps;
                
                // Compact rectangular styling
                info.el.style.borderRadius = '4px';
                info.el.style.border = 'none';
                info.el.style.boxShadow = '0 1px 4px rgba(0,0,0,0.1)';
                info.el.style.overflow = 'hidden';
                info.el.style.transition = 'all 0.2s ease';
                info.el.style.cursor = 'pointer';
                
                // Add status class for booking events
                if (!isAnnouncement && props.status) {
                    const statusClass = 'status-' + props.status.toLowerCase().replace(/\s+/g, '-');
                    info.el.classList.add(statusClass);
                }
                
                // Compact hover effect
                info.el.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(3px) scale(1.01)';
                    this.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
                    this.style.zIndex = '10';
                });
                
                info.el.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0) scale(1)';
                    this.style.boxShadow = '0 1px 4px rgba(0,0,0,0.1)';
                    this.style.zIndex = '1';
                });
                
                // Enhanced tooltip with status
                const statusText = !isAnnouncement && props.status ? `\n📊 Status: ${props.status}` : '';
                info.el.setAttribute('title', isAnnouncement 
                    ? `📢 ${info.event.title || props.title || ''}\n${props.description || ''}\n⏰ Time: ${props.time || 'All Day'}\n👤 By: ${props.created_by || 'Admin'}`
                    : `👤 ${props.customer || ''}\n🔖 Ref: ${props.reference || ''}\n🛠️ Service: ${props.service || ''}\n⏰ Time: ${props.time || ''}${statusText}\n📍 ${props.address || 'Not provided'}`
                );
                
                if (isAnnouncement) {
                    info.el.classList.add('announcement-event');
                }
            },
            eventOrder: function(eventA, eventB) {
                // Order events: Newest bookings first (based on when booking was created, not ID)
                // Lower return value = appears first
                const propsA = eventA.extendedProps || {};
                const propsB = eventB.extendedProps || {};
                
                // Use displayOrder if available (lower number appears first)
                // displayOrder is calculated based on booking creation timestamp
                const orderA = eventA.displayOrder !== undefined ? eventA.displayOrder : 9999999999;
                const orderB = eventB.displayOrder !== undefined ? eventB.displayOrder : 9999999999;
                
                if (orderA !== orderB) {
                    return orderA - orderB; // Lower number appears first (newer bookings have lower displayOrder)
                }
                
                // Fallback: Order by booking creation date for bookings (newer date = appears first)
                if (propsA.type === 'booking' && propsB.type === 'booking') {
                    const dateA = propsA.booking_created_date ? new Date(propsA.booking_created_date).getTime() : 0;
                    const dateB = propsB.booking_created_date ? new Date(propsB.booking_created_date).getTime() : 0;
                    return dateB - dateA; // Newer date (higher timestamp) first
                }
                
                // Default: order by start time
                if (eventA.start && eventB.start) {
                    return eventA.start - eventB.start;
                }
                
                return 0;
            },
            height: 'auto',
            dayMaxEventRows: 4,
            moreLinkClick: 'popover',
            nowIndicator: true,
            firstDay: 1, // Start week on Monday
            weekNumbers: false,
            navLinks: true,
            editable: false,
            selectable: false,
            dayMaxEvents: 3,
            eventDisplay: 'block',
            eventTextColor: '#ffffff',
            eventBackgroundColor: 'inherit',
            eventBorderColor: 'inherit'
        });
        
        calendar.render();
        calendarInitialized = true;
    }
    
    // Initialize top bar actions on page load based on active tab
    // This runs after tab initialization above, so it respects the active tab
    if (topBarActions) {
        // Check URL parameter first
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        const activeTabId = tabParam || 'bookings'; // Default to bookings if no tab parameter
        
            if (activeTabId === 'bookings') {
                topBarActions.innerHTML = '';
            } else if (activeTabId === 'announcements') {
            topBarActions.innerHTML = '<button class="btn btn-modern btn-success-modern" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal"><i class="bi bi-megaphone me-2"></i>Add Announcement</button>';
            } else if (activeTabId === 'calendar-tab') {
            topBarActions.innerHTML = ''; // No add button here to avoid redundancy - use Announcements tab instead
            } else if (activeTabId === 'services') {
                topBarActions.innerHTML = '<button class="btn btn-modern btn-success-modern" data-bs-toggle="modal" data-bs-target="#addServiceModal"><i class="bi bi-plus-circle me-2"></i>Add Service</button>';
        }
    }
    
});

function editAnnouncement(ann) {
    document.getElementById('edit_announcement_id').value = ann.announcement_id;
    document.getElementById('edit_announcement_title').value = ann.title || '';
    document.getElementById('edit_announcement_description').value = ann.description || '';
    document.getElementById('edit_announcement_date').value = ann.announcement_date || '';
    document.getElementById('edit_announcement_time').value = ann.announcement_time || '';
    document.getElementById('edit_announcement_color').value = ann.color || '#10b981';
    
    // Change form to edit mode
    const form = document.querySelector('#addAnnouncementModal form');
    const submitBtn = document.getElementById('submit_announcement_btn');
    const modalHeader = document.querySelector('#addAnnouncementModal .modal-header');
    submitBtn.name = 'edit_announcement';
    submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Update Announcement';
    submitBtn.className = 'btn btn-modern btn-success-modern';
    
    // Update modal header
    modalHeader.className = 'modal-header text-white';
    modalHeader.style.background = 'linear-gradient(135deg, var(--green-600), var(--green-700))';
    modalHeader.querySelector('h5').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Announcement';
    
    new bootstrap.Modal(document.getElementById('addAnnouncementModal')).show();
    
    // Reset form on modal close
    document.getElementById('addAnnouncementModal').addEventListener('hidden.bs.modal', function() {
        submitBtn.name = 'add_announcement';
        submitBtn.innerHTML = '<i class="bi bi-megaphone me-2"></i>Add Announcement';
        submitBtn.className = 'btn btn-modern btn-success-modern';
        modalHeader.className = 'modal-header text-white';
        modalHeader.style.background = 'linear-gradient(135deg, var(--green-600), var(--green-700))';
        modalHeader.querySelector('h5').innerHTML = '<i class="bi bi-megaphone me-2"></i>Add Announcement';
        form.reset();
    }, { once: true });
}

// Store booking data for Gmail compose
let currentBookingData = {};

function openUploadModal(bookingId, referenceCode, customerName, customerEmail) {
    document.getElementById('upload_booking_id').value = bookingId;
    document.getElementById('upload_reference_code').textContent = referenceCode;
    document.getElementById('upload_customer_name').textContent = customerName;
    document.getElementById('upload_customer_email').textContent = customerEmail;
    
    // Store booking data for Gmail compose
    currentBookingData.upload = {
        bookingId: bookingId,
        referenceCode: referenceCode,
        customerName: customerName,
        customerEmail: customerEmail,
        type: 'upload'
    };
    
    // Clear previous preview
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('pictures_input').value = '';
    
    // Load existing pictures
    loadExistingPictures(bookingId);
    
    // Image preview functionality
    const input = document.getElementById('pictures_input');
    // Remove existing event listeners by cloning the element
    const newInput = input.cloneNode(true);
    input.parentNode.replaceChild(newInput, input);
    
    document.getElementById('pictures_input').addEventListener('change', function(e) {
        const preview = document.getElementById('imagePreview');
        preview.innerHTML = '';
        
        if (this.files && this.files.length > 0) {
            Array.from(this.files).forEach((file, index) => {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const col = document.createElement('div');
                        col.className = 'col-md-4';
                        col.innerHTML = `
                            <div class="position-relative" style="border: 2px solid var(--border); border-radius: 8px; padding: 8px; background: #f8fafc;">
                                <img src="${e.target.result}" class="img-fluid" style="max-height: 150px; width: 100%; object-fit: cover; border-radius: 4px;">
                                <small class="d-block mt-2 text-muted text-truncate" title="${file.name}">${file.name}</small>
                                <small class="text-muted">${(file.size / 1024 / 1024).toFixed(2)} MB</small>
                            </div>
                        `;
                        preview.appendChild(col);
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
    });
    
    new bootstrap.Modal(document.getElementById('uploadPicturesModal')).show();
}

function loadExistingPictures(bookingId) {
    const container = document.getElementById('existingPicturesContainer');
    container.innerHTML = '<div class="col-12 text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="text-muted mt-2">Loading pictures...</p></div>';
    
    fetch(`?booking_pictures=1&booking_id=${bookingId}`)
        .then(response => response.json())
        .then(data => {
            container.innerHTML = '';
            
            if (data.error) {
                container.innerHTML = `<div class="col-12"><div class="alert alert-danger">Error loading pictures: ${data.error}</div></div>`;
                return;
            }
            
            if (data.length === 0) {
                container.innerHTML = `
                    <div class="col-12 text-center py-4">
                        <i class="bi bi-image fs-1 text-muted d-block mb-2"></i>
                        <p class="text-muted">No pictures uploaded yet for this booking.</p>
                    </div>
                `;
                return;
            }
            
            data.forEach(picture => {
                const col = document.createElement('div');
                col.className = 'col-md-4 col-lg-3';
                const fileName = picture.picture_path.split('/').pop();
                const uploadDate = new Date(picture.uploaded_at).toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                col.innerHTML = `
                    <div class="position-relative" style="border: 2px solid var(--border); border-radius: 12px; padding: 12px; background: #f8fafc; transition: all 0.3s ease;">
                        <div class="position-relative" style="overflow: hidden; border-radius: 8px; background: #e2e8f0; min-height: 180px; display: flex; align-items: center; justify-content: center;">
                            <img src="${picture.picture_path}" 
                                 class="img-fluid" 
                                 style="max-height: 180px; width: 100%; object-fit: contain; cursor: pointer;"
                                 onclick="openImageModal('${picture.picture_path}', '${fileName.replace(/'/g, "\\'")}')"
                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23e2e8f0\' width=\'200\' height=\'200\'/%3E%3Ctext fill=\'%239ca3af\' font-family=\'sans-serif\' font-size=\'14\' x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dominant-baseline=\'middle\'%3EImage not found%3C/text%3E%3C/svg%3E'">
                            <div class="position-absolute top-0 end-0 m-2 d-flex gap-1">
                                <a href="${picture.picture_path}" target="_blank" class="btn btn-sm btn-light" style="box-shadow: 0 2px 8px rgba(0,0,0,0.15);" title="Open in new tab">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-danger" style="box-shadow: 0 2px 8px rgba(0,0,0,0.15);" title="Delete image" onclick="event.stopPropagation(); deletePicture(${picture.picture_id}, ${bookingId}, '${fileName.replace(/'/g, "\\'")}')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mt-2">
                            <small class="d-block text-muted text-truncate" title="${fileName}">
                                <i class="bi bi-file-image me-1"></i>${fileName}
                            </small>
                            <small class="text-muted">
                                <i class="bi bi-clock me-1"></i>${uploadDate}
                            </small>
                        </div>
                    </div>
                `;
                container.appendChild(col);
            });
        })
        .catch(error => {
            container.innerHTML = `<div class="col-12"><div class="alert alert-danger">Error loading pictures: ${error.message}</div></div>`;
        });
}

function deletePicture(pictureId, bookingId, fileName) {
    if (!confirm(`Are you sure you want to delete "${fileName}"?\n\nThis action cannot be undone.`)) {
        return;
    }
    
    // Show loading state
    const container = document.getElementById('existingPicturesContainer');
    const originalContent = container.innerHTML;
    
    // Create form data
    const formData = new FormData();
    formData.append('delete_picture', '1');
    formData.append('picture_id', pictureId);
    
    // Send delete request
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message temporarily
            container.innerHTML = `<div class="col-12"><div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>${data.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div></div>`;
            
            // Reload pictures after a short delay
            setTimeout(() => {
                loadExistingPictures(bookingId);
            }, 1000);
        } else {
            // Show error message
            container.innerHTML = `<div class="col-12"><div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i>${data.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div></div>`;
            
            // Restore original content after 3 seconds
            setTimeout(() => {
                loadExistingPictures(bookingId);
            }, 3000);
        }
    })
    .catch(error => {
        container.innerHTML = `<div class="col-12"><div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i>Error deleting picture: ${error.message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div></div>`;
        
        // Restore original content after 3 seconds
        setTimeout(() => {
            loadExistingPictures(bookingId);
        }, 3000);
    });
}

function openImageModal(imageSrc, imageName) {
    // Create modal if it doesn't exist
    let imageModal = document.getElementById('imageViewModal');
    if (!imageModal) {
        imageModal = document.createElement('div');
        imageModal.id = 'imageViewModal';
        imageModal.className = 'modal fade';
        imageModal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="imageModalTitle"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center p-0">
                        <img id="imageModalImg" src="" class="img-fluid" style="max-height: 80vh; width: auto;">
                    </div>
                    <div class="modal-footer">
                        <a id="imageModalDownload" href="" download class="btn btn-modern btn-primary-modern">
                            <i class="bi bi-download me-2"></i>Download
                        </a>
                        <button type="button" class="btn btn-modern" style="background: var(--border); color: var(--dark);" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(imageModal);
    }
    
    document.getElementById('imageModalTitle').textContent = imageName;
    document.getElementById('imageModalImg').src = imageSrc;
    document.getElementById('imageModalDownload').href = imageSrc;
    document.getElementById('imageModalDownload').download = imageName;
    
    const modal = new bootstrap.Modal(imageModal);
    modal.show();
}

function openStatusModal(bookingId, currentStatus, referenceCode, customerName, customerEmail, appointmentDate, appointmentTime) {
    document.getElementById('status_booking_id').value = bookingId;
    document.getElementById('status_reference_code').textContent = referenceCode;
    document.getElementById('status_customer_name').textContent = customerName;
    document.getElementById('status_customer_email').textContent = customerEmail;
    document.getElementById('status_new_status').value = currentStatus;
    document.getElementById('status_admin_message').value = '';
    
    // Populate reschedule fields with current appointment date and time
    if (appointmentDate) {
        document.getElementById('status_new_appointment_date').value = appointmentDate;
    } else {
        document.getElementById('status_new_appointment_date').value = '';
    }
    if (appointmentTime) {
        document.getElementById('status_new_appointment_time').value = appointmentTime;
    } else {
        document.getElementById('status_new_appointment_time').value = '';
    }
    
    // Store booking data for Gmail compose
    currentBookingData.status = {
        bookingId: bookingId,
        referenceCode: referenceCode,
        customerName: customerName,
        customerEmail: customerEmail,
        currentStatus: currentStatus,
        appointmentDate: appointmentDate || '',
        appointmentTime: appointmentTime || '',
        type: 'status'
    };
    
    // Set default messages based on status (as placeholders)
    const defaultMessages = {
        'Pending': 'Your booking is currently pending and will be reviewed by our team shortly.',
        'In Progress': 'Your booking is now in progress. Our team is working on your service request.',
        'Completed': 'Your booking has been completed. Thank you for choosing our services!',
        'Cancelled': 'Your booking has been cancelled. If you need to reschedule, please contact us.'
    };
    
    // Update placeholder when status changes
    const statusSelect = document.getElementById('status_new_status');
    const messageTextarea = document.getElementById('status_admin_message');
    
    statusSelect.addEventListener('change', function() {
        const selectedStatus = this.value;
        if (selectedStatus && defaultMessages[selectedStatus] && !messageTextarea.value.trim()) {
            messageTextarea.placeholder = 'Default message: ' + defaultMessages[selectedStatus];
        }
    });
    
    new bootstrap.Modal(document.getElementById('statusUpdateModal')).show();
}

function openGmailCompose(event, modalType) {
    event.preventDefault();
    
    const bookingData = currentBookingData[modalType];
    if (!bookingData) {
        alert('Booking data not found. Please close and reopen the modal.');
        return;
    }
    
    const email = bookingData.customerEmail;
    const customerName = bookingData.customerName;
    const referenceCode = bookingData.referenceCode;
    
    // Generate subject based on modal type
    let subject = '';
    let body = '';
    
    if (modalType === 'upload') {
        subject = `Pictures Uploaded - Booking ${referenceCode}`;
        body = `Dear ${customerName},\n\n` +
               `Pictures have been uploaded for your booking.\n\n` +
               `Booking Reference: ${referenceCode}\n\n` +
               `You can view these pictures in your booking details.\n\n` +
               `Thank you for choosing Techno Pest Control.\n\n` +
               `Best regards,\n` +
               `Techno Pest Control Team`;
    } else if (modalType === 'status') {
        const currentStatus = bookingData.currentStatus || 'Pending';
        const statusMessages = {
            'Pending': 'Your booking is currently pending and will be reviewed by our team shortly.',
            'In Progress': 'Your booking is now in progress. Our team is working on your service request.',
            'Completed': 'Your booking has been completed. Thank you for choosing our services!',
            'Cancelled': 'Your booking has been cancelled. If you need to reschedule, please contact us.'
        };
        
        subject = `Booking Status Update - ${referenceCode}`;
        body = `Dear ${customerName},\n\n` +
               `Your booking status has been updated.\n\n` +
               `Booking Reference: ${referenceCode}\n` +
               `Status: ${currentStatus}\n\n` +
               `${statusMessages[currentStatus] || 'Your booking status has been updated.'}\n\n` +
               `Thank you for choosing Techno Pest Control.\n\n` +
               `Best regards,\n` +
               `Techno Pest Control Team`;
    }
    
    // Encode subject and body for URL
    const encodedSubject = encodeURIComponent(subject);
    const encodedBody = encodeURIComponent(body);
    
    // Create Gmail compose URL
    const gmailUrl = `https://mail.google.com/mail/?view=cm&to=${encodeURIComponent(email)}&su=${encodedSubject}&body=${encodedBody}`;
    
    // Open Gmail compose in new tab
    window.open(gmailUrl, '_blank');
}

function editService(s) {
    document.getElementById('edit_service_id').value = s.service_id;
    document.getElementById('edit_service_name').value = s.service_name;
    document.getElementById('edit_service_type').value = s.service_type;
    document.getElementById('edit_service_details').value = s.service_details || '';
    new bootstrap.Modal(document.getElementById('editServiceModal')).show();
}

function openPriceModal(id, name) {
    document.getElementById('price_service_id').value = id;
    document.getElementById('priceModalLabel').innerText = 'Add Price - ' + name;
    new bootstrap.Modal(document.getElementById('priceModal')).show();
}

function editPrice(p) {
    document.getElementById('edit_price_range_id').value = p.price_range_id;
    document.getElementById('edit_price_range').value = p.price_range;
    document.getElementById('edit_price').value = p.price;
    new bootstrap.Modal(document.getElementById('editPriceModal')).show();
}

// Scan & Deduct functionality
let currentScanBooking = null;
let barcodeInput = null;
let scanTimeout = null;

function openScanModal(bookingId, referenceCode, customerName) {
    currentScanBooking = {
        bookingId: bookingId,
        referenceCode: referenceCode,
        customerName: customerName
    };
    
    // Update booking info display
    const bookingInfoElement = document.getElementById('scanBookingInfo');
    if (bookingInfoElement) {
        bookingInfoElement.innerHTML = `
            <strong>Reference:</strong> <span class="text-primary">${referenceCode}</span> | 
            <strong>Customer:</strong> <span class="text-primary">${customerName}</span>
        `;
    }
    
    // Reset form
    document.getElementById('scanForm').reset();
    document.getElementById('scanResult').style.display = 'none';
    
    // Reset dropdowns
    document.getElementById('scanServiceType').value = '';
    document.getElementById('scanIngredient').value = '';
    document.getElementById('manualBarcode').value = '';
    document.getElementById('deductQuantity').value = '1';
    
    // Clear scanned ingredient display
    document.getElementById('scannedIngredientDisplay').style.display = 'none';
    
    // Focus barcode input when modal opens
    setTimeout(() => {
        const barcodeInput = document.getElementById('manualBarcode');
        if (barcodeInput) {
            barcodeInput.focus();
        }
    }, 300);
}

// Auto-focus input when modal opens
document.addEventListener('DOMContentLoaded', function() {
    const scanModal = document.getElementById('scanModal');
    const scanIngredient = document.getElementById('scanIngredient');
    const manualBarcode = document.getElementById('manualBarcode');
    
    if (scanModal) {
        scanModal.addEventListener('shown.bs.modal', function() {
            barcodeInput = document.getElementById('manualBarcode');
            if (barcodeInput) {
                barcodeInput.focus();
                barcodeInput.value = '';
            }
        });
        
        // Function to display scanned ingredient information
        function displayScannedIngredient(option) {
            const displayDiv = document.getElementById('scannedIngredientDisplay');
            const nameSpan = document.getElementById('scannedIngredientName');
            const barcodeSpan = document.getElementById('scannedIngredientBarcode');
            const stockSpan = document.getElementById('scannedIngredientStock');
            
            if (option && option.dataset.barcode) {
                nameSpan.textContent = option.dataset.itemName || option.textContent || 'Unknown';
                barcodeSpan.textContent = option.dataset.barcode;
                stockSpan.textContent = option.dataset.quantity || '0';
                displayDiv.style.display = 'block';
                displayDiv.classList.remove('alert-danger');
                displayDiv.classList.add('alert-success');
            } else {
                displayDiv.style.display = 'none';
            }
        }
        
        // Function to find and match ingredient by barcode
        function matchIngredientByBarcode(barcode) {
            if (!barcode || !scanIngredient) return false;
            
            for (let i = 0; i < scanIngredient.options.length; i++) {
                const option = scanIngredient.options[i];
                if (option.dataset.barcode && option.dataset.barcode === barcode) {
                    scanIngredient.value = option.value;
                    displayScannedIngredient(option);
                    return true;
                }
            }
            
            // If no match found, show error
            const displayDiv = document.getElementById('scannedIngredientDisplay');
            if (barcode.length >= 3) { // Only show error if barcode seems complete
                displayDiv.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-4 text-warning"></i>
                        <div>
                            <strong>Barcode not found in inventory:</strong> <span class="font-monospace">${barcode}</span><br>
                            <small>Please make sure the barcode is correct or select an ingredient manually from the dropdown.</small>
                        </div>
                    </div>
                `;
                displayDiv.style.display = 'block';
                displayDiv.classList.remove('alert-success');
                displayDiv.classList.add('alert-danger');
            }
            return false;
        }
        
        // Auto-select ingredient when barcode is entered/scanned
        if (manualBarcode) {
            // Handle manual input or barcode scanning
            manualBarcode.addEventListener('input', function(e) {
                const barcode = this.value.trim();
                if (scanTimeout) {
                    clearTimeout(scanTimeout);
                }
                
                if (barcode) {
                    // For rapid input (barcode scanner), wait a bit before processing
                    if (barcode.length >= 3) {
                        scanTimeout = setTimeout(() => {
                            matchIngredientByBarcode(barcode);
                        }, 150);
                    } else {
                        // Immediate match for shorter codes
                        matchIngredientByBarcode(barcode);
                    }
                } else {
                    // Clear display if barcode is empty
                    document.getElementById('scannedIngredientDisplay').style.display = 'none';
                    if (scanIngredient) {
                        scanIngredient.value = '';
                    }
                }
            });
            
            // Handle barcode scanner input (plug-and-play scanners send Enter after barcode)
            manualBarcode.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const barcode = this.value.trim();
                    if (barcode) {
                        // Process the barcode immediately when Enter is pressed
                        matchIngredientByBarcode(barcode);
                    }
                }
            });
        }
        
        // Auto-populate barcode and display when ingredient is selected from dropdown
        if (scanIngredient) {
            scanIngredient.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (selectedOption && selectedOption.dataset.barcode) {
                    manualBarcode.value = selectedOption.dataset.barcode;
                    displayScannedIngredient(selectedOption);
                } else {
                    document.getElementById('scannedIngredientDisplay').style.display = 'none';
                }
            });
        }
        
        // Handle form submission
        document.getElementById('scanForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!currentScanBooking) {
                alert('Booking information not found. Please close and reopen the modal.');
                return;
            }
            
            const serviceType = document.getElementById('scanServiceType').value;
            const ingredient = document.getElementById('scanIngredient').value;
            const barcode = document.getElementById('manualBarcode').value.trim();
            const quantity = parseFloat(document.getElementById('deductQuantity').value);
            const notes = document.getElementById('deductNotes').value.trim();
            const resultDiv = document.getElementById('scanResult');
            
            // Validate required fields
            if (!serviceType) {
                resultDiv.innerHTML = '<i class="bi bi-exclamation-circle text-danger"></i> Please select a service type';
                resultDiv.className = 'alert alert-danger';
                resultDiv.style.display = 'block';
                document.getElementById('scanServiceType').focus();
                return;
            }
            
            if (!ingredient && !barcode) {
                resultDiv.innerHTML = '<i class="bi bi-exclamation-circle text-danger"></i> Please select an ingredient from inventory or scan/enter a barcode';
                resultDiv.className = 'alert alert-danger';
                resultDiv.style.display = 'block';
                if (!ingredient) {
                    document.getElementById('scanIngredient').focus();
                } else {
                    document.getElementById('manualBarcode').focus();
                }
                return;
            }
            
            // Use barcode from ingredient dropdown if barcode field is empty
            const finalBarcode = barcode || (ingredient ? document.getElementById('scanIngredient').options[document.getElementById('scanIngredient').selectedIndex]?.dataset.barcode : '');
            
            if (!finalBarcode) {
                resultDiv.innerHTML = '<i class="bi bi-exclamation-circle text-danger"></i> Please scan or enter a barcode, or select an ingredient with a barcode';
                resultDiv.className = 'alert alert-danger';
                resultDiv.style.display = 'block';
                return;
            }
            
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const origText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> PROCESSING...';
            submitBtn.disabled = true;
            
            // Get selected service name for display
            const selectedService = document.getElementById('scanServiceType').options[document.getElementById('scanServiceType').selectedIndex];
            const serviceName = selectedService ? selectedService.dataset.serviceName || selectedService.text : '';
            
            // Get selected ingredient name for display
            const selectedIngredient = document.getElementById('scanIngredient').options[document.getElementById('scanIngredient').selectedIndex];
            const ingredientName = selectedIngredient ? selectedIngredient.dataset.itemName || selectedIngredient.text : '';
            
            const formData = new FormData();
            formData.append('action', 'scan_deduct');
            formData.append('service_id', serviceType);
            formData.append('service_name', serviceName);
            formData.append('ingredient_id', ingredient);
            formData.append('ingredient_name', ingredientName);
            formData.append('barcode', finalBarcode);
            formData.append('quantity', quantity);
            formData.append('source_page', 'dashboard_bookings');
            formData.append('booking_id', currentScanBooking.bookingId);
            formData.append('booking_reference', currentScanBooking.referenceCode);
            formData.append('notes', notes + ' | Booking: ' + currentScanBooking.referenceCode);
            
            try {
                const response = await fetch('scan_deduct.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    resultDiv.innerHTML = `
                        <i class="bi bi-check-circle text-success"></i> <strong>Success!</strong><br>
                        ${result.message}<br>
                        <small>
                            <strong>Booking:</strong> ${currentScanBooking.referenceCode} | 
                            <strong>Customer:</strong> ${currentScanBooking.customerName}<br>
                            <strong>Service:</strong> ${serviceName} | 
                            <strong>Ingredient:</strong> ${ingredientName} | 
                            <strong>Quantity:</strong> ${quantity} | 
                            <strong>Barcode:</strong> ${finalBarcode}
                        </small>
                    `;
                    resultDiv.className = 'alert alert-success';
                    resultDiv.style.display = 'block';
                    
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    resultDiv.innerHTML = '<i class="bi bi-exclamation-circle text-danger"></i> ' + result.message;
                    resultDiv.className = 'alert alert-danger';
                    resultDiv.style.display = 'block';
                    submitBtn.innerHTML = origText;
                    submitBtn.disabled = false;
                }
            } catch (error) {
                resultDiv.innerHTML = '<i class="bi bi-exclamation-circle text-danger"></i> Error: ' + error.message;
                resultDiv.className = 'alert alert-danger';
                resultDiv.style.display = 'block';
                submitBtn.innerHTML = origText;
                submitBtn.disabled = false;
            }
        });
        
        // Reset form when modal is closed
        scanModal.addEventListener('hidden.bs.modal', function() {
            if (scanTimeout) {
                clearTimeout(scanTimeout);
                scanTimeout = null;
            }
            document.getElementById('scanForm').reset();
            document.getElementById('scanResult').style.display = 'none';
            document.getElementById('scannedIngredientDisplay').style.display = 'none';
            barcodeInput = null;
            currentScanBooking = null;
        });
    }
});
</script>
</body>
</html>



