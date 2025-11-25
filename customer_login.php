<?php
session_start();
/* ====== CONFIGURATION ====== */
$DB_HOST = 'localhost';
$DB_NAME = 'pest control';
$DB_USER = 'root';
$DB_PASS = '';
$GOOGLE_CLIENT_ID = '331071626282-9vnptprgpjteva93n96ljnjhoe980j4b.apps.googleusercontent.com';
$AFTER_LOGIN_URL = 'booking_form.php';

if (!empty($_SESSION['user_id']) && $_SESSION['user_type'] === 'customer' && !isset($_SESSION['otp_required'])) {
    header("Location: $AFTER_LOGIN_URL");
    exit;
}

$error = '';
$success = '';
$pdo = null;
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {}

// Email sending function
function sendOTPEmail($to, $otp, $pdo) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    // Try to get email config from database
    $smtp_host = 'smtp.gmail.com';
    $smtp_port = 587;
    $smtp_username = '';
    $smtp_password = '';
    $smtp_from_email = 'noreply@technopestcontrol.com';
    $smtp_from_name = 'Techno Pest Control';
    $smtp_secure = 'tls';
    
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM email_config LIMIT 1");
            $emailConfig = $stmt->fetch();
            if ($emailConfig) {
                $smtp_host = $emailConfig['smtp_host'] ?? $smtp_host;
                $smtp_port = $emailConfig['smtp_port'] ?? $smtp_port;
                $smtp_username = $emailConfig['smtp_username'] ?? $smtp_username;
                $smtp_password = $emailConfig['smtp_password'] ?? $smtp_password;
                $smtp_from_email = $emailConfig['smtp_from_email'] ?? $smtp_from_email;
                $smtp_from_name = $emailConfig['smtp_from_name'] ?? $smtp_from_name;
                $smtp_secure = $emailConfig['smtp_secure'] ?? $smtp_secure;
            }
        } catch (PDOException $e) {
            // Use defaults if table doesn't exist
        }
    }
    
    // Try PHPMailer if available
    if (file_exists('vendor/autoload.php') && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        require_once 'vendor/autoload.php';
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = !empty($smtp_username);
            $mail->Username = $smtp_username;
            $mail->Password = $smtp_password;
            $mail->SMTPSecure = ($smtp_secure === 'ssl') ? 
                PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : 
                PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtp_port;
            $mail->CharSet = 'UTF-8';
            
            $mail->setFrom($smtp_from_email, $smtp_from_name);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = 'Your Login Verification Code - Techno Pest Control';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f8fafc;'>
                    <div style='background: linear-gradient(135deg, #1a5f2e 0%, #2d8650 50%, #4ade80 100%); padding: 30px; border-radius: 16px 16px 0 0; text-align: center;'>
                        <h2 style='color: white; margin: 0; font-size: 24px;'>Techno Pest Control</h2>
                    </div>
                    <div style='background: white; padding: 30px; border-radius: 0 0 16px 16px;'>
                        <h3 style='color: #1a5f2e; margin-top: 0;'>Login Verification Code</h3>
                        <p style='color: #64748b; font-size: 16px; line-height: 1.6;'>Your verification code is:</p>
                        <div style='background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #1a5f2e; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0;'>
                            <h1 style='color: #1a5f2e; font-size: 36px; letter-spacing: 8px; margin: 0; font-weight: 900;'>$otp</h1>
                        </div>
                        <p style='color: #64748b; font-size: 14px; line-height: 1.6;'>This code will expire in 5 minutes. If you didn't request this code, please ignore this email.</p>
                        <p style='color: #64748b; font-size: 14px; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 20px;'>© 2025 Techno Pest Control. All rights reserved.</p>
                    </div>
                </div>
            ";
            $mail->AltBody = "Your verification code is: $otp\n\nThis code will expire in 5 minutes.";
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
        }
    }
    
    // Fallback to mail() function
    $subject = 'Your Login Verification Code - Techno Pest Control';
    $message = "
        Your verification code is: $otp
        
        This code will expire in 5 minutes.
        
        If you didn't request this code, please ignore this email.
        
        © 2025 Techno Pest Control. All rights reserved.
    ";
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $smtp_from_name <$smtp_from_email>\r\n";
    $headers .= "Reply-To: $smtp_from_email\r\n";
    
    return @mail($to, $subject, $message, $headers);
}

// Handle OTP verification
if (isset($_POST['verify_otp']) && isset($_SESSION['otp_required'])) {
    $entered_otp = trim($_POST['otp'] ?? '');
    $stored_otp = $_SESSION['otp_code'] ?? '';
    $otp_expiry = $_SESSION['otp_expiry'] ?? 0;
    
    if (empty($entered_otp)) {
        $error = "Please enter the verification code.";
    } elseif (time() > $otp_expiry) {
        $error = "Verification code has expired. Please request a new one.";
        unset($_SESSION['otp_code'], $_SESSION['otp_expiry'], $_SESSION['otp_required']);
    } elseif ($entered_otp !== $stored_otp) {
        $error = "Invalid verification code. Please try again.";
    } else {
        // OTP verified successfully
        unset($_SESSION['otp_code'], $_SESSION['otp_expiry'], $_SESSION['otp_required']);
        header("Location: $AFTER_LOGIN_URL");
        exit;
    }
}

// Handle OTP resend
if (isset($_POST['resend_otp']) && isset($_SESSION['otp_required'])) {
    $user_email = $_SESSION['email'] ?? '';
    if ($user_email) {
        $new_otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['otp_code'] = $new_otp;
        $_SESSION['otp_expiry'] = time() + 300; // 5 minutes
        
        if (sendOTPEmail($user_email, $new_otp, $pdo)) {
            $success = "A new verification code has been sent to your email.";
        } else {
            $error = "Failed to send verification code. Please try again.";
        }
    }
}

// Handle cancel OTP
if (isset($_GET['cancel_otp']) && $_GET['cancel_otp'] == '1') {
    unset($_SESSION['otp_code'], $_SESSION['otp_expiry'], $_SESSION['otp_required'], $_SESSION['user_id'], $_SESSION['id'], $_SESSION['username'], $_SESSION['email'], $_SESSION['user_type']);
    header("Location: customer_login.php");
    exit;
}


if (isset($_POST['google_token'])) {
    // Check if privacy agreement is checked
    if (!isset($_POST['privacy_agreement']) || $_POST['privacy_agreement'] !== '1') {
        $error = "You must agree to the Data Privacy Policy to continue.";
    } else {
        $token = $_POST['google_token'];
        
        // Use cURL if available, otherwise fall back to file_get_contents
        $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($token);
        $response = null;
        
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'ignore_errors' => true,
                    'header' => ['User-Agent: PHP', 'Accept: application/json']
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
            ]);
            $response = @file_get_contents($url, false, $context);
        }
        
        // Fallback: Decode JWT directly if API fails
        if (!$response) {
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
            } else {
                $data = null;
            }
        } else {
            $data = json_decode($response, true);
        }
        
        if ($data && ($data['aud'] ?? '') === $GOOGLE_CLIENT_ID && 
            (in_array($data['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com']) || !isset($data['iss'])) &&
            ($data['email_verified'] ?? true) && 
            (($data['exp'] ?? 0) > time() || !isset($data['exp']))) {
            
            $email = $data['email'];
            $google_id = $data['sub'] ?? null;
            $first_name = $data['given_name'] ?? '';
            $last_name = $data['family_name'] ?? '';
            $name = $data['name'] ?? ($first_name . ' ' . $last_name) ?? explode('@', $email)[0];

            if ($pdo) {
                // First, try to find user by Google ID
                $user = null;
                if ($google_id) {
                    try {
                        $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name FROM users WHERE google_id = ? AND user_type = 'customer' LIMIT 1");
                        $stmt->execute([$google_id]);
                        $user = $stmt->fetch();
                    } catch (PDOException $e) {
                        // google_id column might not exist, continue with email lookup
                    }
                }
                
                // If not found by Google ID, try by email
                if (!$user) {
                    $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name FROM users WHERE email = ? AND user_type = 'customer' LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();
                    
                    // If user exists but doesn't have Google ID, update it
                    if ($user && $google_id) {
                        try {
                            // Try to add google_id column if it doesn't exist
                            $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL");
                        } catch (PDOException $e) {
                            // Column might already exist, ignore
                        }
                        try {
                            $updateStmt = $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?");
                            $updateStmt->execute([$google_id, $user['id']]);
                        } catch (PDOException $e) {
                            // Ignore if update fails
                        }
                    }
                }
                
                // If user still doesn't exist, create new one
                if (!$user) {
                    $username = explode('@', $email)[0];
                    // Make username unique
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $username .= rand(10, 99);
                    }
                    
                    try {
                        // Try to insert with google_id
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, google_id, first_name, last_name, user_type) VALUES (?, ?, NULL, ?, ?, ?, 'customer')");
                        $stmt->execute([$username, $email, $google_id, $first_name, $last_name]);
                        $user_id = $pdo->lastInsertId();
                        $username = $username;
                    } catch (PDOException $e) {
                        // If google_id column doesn't exist, insert without it
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, user_type) VALUES (?, ?, NULL, ?, ?, 'customer')");
                        $stmt->execute([$username, $email, $first_name, $last_name]);
                        $user_id = $pdo->lastInsertId();
                        $username = $username;
                    }
                } else {
                    $user_id = $user['id'];
                    $username = $user['username'];
                }
            } else {
                $user_id = 'temp_' . substr(md5($email.time()),0,12);
                $username = explode('@', $email)[0];
            }

            $_SESSION['user_id'] = $_SESSION['id'] = $user_id;
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['user_type'] = 'customer';
            header("Location: $AFTER_LOGIN_URL");
            exit;
        }
        $error = "Google Sign-In failed. Please try again.";
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['username']) && !empty($_POST['password']) && $pdo) {
    // Check if privacy agreement is checked
    if (!isset($_POST['privacy_agreement']) || $_POST['privacy_agreement'] !== '1') {
        $error = "You must agree to the Data Privacy Policy to continue.";
    } else {
        $input = trim($_POST['username']);
        $pass = $_POST['password'];
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND user_type='customer' LIMIT 1");
        $stmt->execute([$input, $input]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Check if user has a password
            if (empty($user['password'])) {
                $error = "This account was registered with Google. Please use Google Sign-In to log in.";
            } elseif (password_verify($pass, $user['password'])) {
                // Generate OTP
                $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION['otp_code'] = $otp;
                $_SESSION['otp_expiry'] = time() + 300; // 5 minutes
                $_SESSION['otp_required'] = true;
                $_SESSION['user_id'] = $_SESSION['id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_type'] = 'customer';
                
                // Send OTP email
                if (sendOTPEmail($user['email'], $otp, $pdo)) {
                    $success = "A verification code has been sent to your email. Please check your inbox.";
                } else {
                    $error = "Password verified, but failed to send verification code. Please try again or contact support.";
                    unset($_SESSION['otp_code'], $_SESSION['otp_expiry'], $_SESSION['otp_required']);
                }
            } else {
                $error = "Wrong username/email or password.";
            }
        } else {
            $error = "Wrong username/email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login • Techno Pest Control</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        :root {
            --primary: #1a5f2e;
            --primary-dark: #0f4a1f;
            --primary-light: #2d8650;
            --secondary: #4ade80;
            --accent: #fbbf24;
            --dark: #0f172a;
            --darker: #020617;
            --light: #ffffff;
            --light-bg: #f8fafc;
            --border: #e2e8f0;
            --text: #1e293b;
            --text-light: #64748b;
            --error: #dc2626;
            --error-light: #fee2e2;
            --success: #16a34a;
            --success-light: #dcfce7;
        }
        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 30%, #334155 70%, #475569 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 15% 25%, rgba(26, 95, 46, 0.2) 0%, transparent 45%),
                radial-gradient(circle at 85% 75%, rgba(45, 134, 80, 0.15) 0%, transparent 45%),
                radial-gradient(circle at 50% 50%, rgba(74, 222, 128, 0.1) 0%, transparent 60%);
            pointer-events: none;
            z-index: 0;
            animation: gradientShift 15s ease infinite;
        }
        @keyframes gradientShift {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        .home-btn {
            position: fixed;
            top: 30px;
            right: 30px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            color: white;
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            border: 2px solid rgba(255,255,255,0.25);
            box-shadow: 0 8px 32px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.2);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .home-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 12px 48px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.4);
        }
        .box {
            width: 95vw;
            max-width: 1200px;
            background: rgba(255,255,255,0.99);
            backdrop-filter: blur(30px);
            -webkit-backdrop-filter: blur(30px);
            border-radius: 40px;
            overflow: hidden;
            box-shadow: 
                0 25px 80px rgba(0,0,0,0.4),
                0 0 0 1px rgba(255,255,255,0.2),
                inset 0 1px 0 rgba(255,255,255,0.3);
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            position: relative;
            z-index: 1;
            animation: slideUp 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes slideUp {
            from { 
                opacity: 0; 
                transform: translateY(40px) scale(0.95); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
            }
        }
        @media (max-width: 968px) {
            .box {
                grid-template-columns: 1fr;
                max-width: 600px;
                border-radius: 32px;
            }
            .right-side { display: none; }
            .home-btn { 
                top: 20px; 
                right: 20px; 
                padding: 12px 20px; 
                font-size: 0.9rem; 
            }
        }
        .left-side {
            padding: 70px 60px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }
        .left-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light), var(--secondary));
        }
        .logo {
            width: 110px;
            height: 110px;
            margin: 0 auto 35px;
            padding: 18px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 50%, var(--secondary) 100%);
            border-radius: 28px;
            box-shadow: 
                0 12px 40px rgba(26, 95, 46, 0.35),
                0 0 0 4px rgba(26, 95, 46, 0.1),
                inset 0 1px 0 rgba(255,255,255,0.3);
            transform: scale(1);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .logo:hover {
            transform: scale(1.08) rotate(8deg);
            box-shadow: 
                0 16px 50px rgba(26, 95, 46, 0.45),
                0 0 0 4px rgba(26, 95, 46, 0.15),
                inset 0 1px 0 rgba(255,255,255,0.4);
        }
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }
        .head {
            text-align: center;
            margin-bottom: 40px;
        }
        .head h2 {
            font-size: 2.75rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 50%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 12px;
            letter-spacing: -1.5px;
            line-height: 1.1;
        }
        .head p {
            font-size: 1.15rem;
            color: var(--text-light);
            font-weight: 500;
            letter-spacing: -0.3px;
        }
        .err {
            background: linear-gradient(135deg, var(--error-light), #fef2f2);
            color: var(--error);
            padding: 18px 22px;
            border-radius: 16px;
            margin: 20px 0;
            border: 2px solid rgba(220, 38, 38, 0.2);
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);
            animation: slideDown 0.4s ease;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .err::before {
            content: '⚠';
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .privacy-checkbox {
            margin: 28px 0;
            padding: 24px;
            background: linear-gradient(135deg, var(--light-bg), #ffffff);
            border-radius: 20px;
            border: 2px solid var(--border);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .privacy-checkbox:has(input:checked) {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(26, 95, 46, 0.1), 0 4px 16px rgba(26, 95, 46, 0.15);
            transform: translateY(-2px);
        }
        .privacy-checkbox input[type="checkbox"] {
            width: 24px;
            height: 24px;
            margin: 0;
            cursor: pointer;
            accent-color: var(--primary);
            flex-shrink: 0;
            margin-top: 2px;
            border-radius: 8px;
        }
        .privacy-checkbox label {
            font-size: 0.95rem;
            color: var(--text);
            line-height: 1.7;
            cursor: pointer;
            flex: 1;
        }
        .privacy-checkbox label a {
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
            border-bottom: 2px solid var(--primary);
            transition: all 0.3s ease;
        }
        .privacy-checkbox label a:hover {
            color: var(--primary-dark);
            border-bottom-color: var(--primary-dark);
        }
        .google-btn {
            margin: 30px 0;
            display: flex;
            justify-content: center;
        }
        .divider {
            margin: 32px 0;
            color: var(--text-light);
            font-weight: 600;
            font-size: 0.9rem;
            position: relative;
            text-align: center;
        }
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0; 
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--border), transparent);
        }
        .divider span {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            padding: 0 24px;
            position: relative;
        }
        .google-btn {
            margin: 32px 0;
            display: flex;
            justify-content: center;
            padding: 8px;
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            border-radius: 16px;
            border: 2px solid var(--border);
        }
        .form-group {
            margin-bottom: 20px;
        }
        input[type="text"],
        input[type="password"],
        input[type="email"] {
            width: 100%;
            padding: 18px 22px;
            border: 2px solid var(--border);
            border-radius: 16px;
            font-size: 1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: var(--light);
            font-family: inherit;
            color: var(--text);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        input[type="text"]:focus,
        input[type="password"]:focus,
        input[type="email"]:focus {
            border-color: var(--primary);
            outline: none;
            background: white;
            box-shadow: 
                0 0 0 4px rgba(26, 95, 46, 0.1),
                0 4px 12px rgba(26, 95, 46, 0.15);
            transform: translateY(-2px);
        }
        .verification-code-section {
            margin-bottom: 32px;
            padding: 0;
            background: transparent;
            border-radius: 0;
            border: none;
            box-shadow: none;
        }
        .otp-verification-section {
            margin-top: 20px;
        }
        .otp-input-group {
            margin-bottom: 24px;
        }
        #otp {
            width: 100%;
            padding: 24px 22px;
            border: 2px solid var(--border);
            border-radius: 16px;
            font-size: 1.5rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: var(--light);
            font-family: 'Courier New', monospace;
            color: var(--primary);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        #otp:focus {
            border-color: var(--primary);
            outline: none;
            background: white;
            box-shadow: 
                0 0 0 4px rgba(26, 95, 46, 0.1),
                0 4px 12px rgba(26, 95, 46, 0.15);
            transform: translateY(-2px);
        }
        #resendBtn:hover {
            background: var(--primary) !important;
            color: white !important;
            transform: translateY(-2px);
        }
        
        button[type="submit"] {
            width: 100%;
            padding: 18px 24px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 50%, var(--secondary) 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 12px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 
                0 6px 20px rgba(26, 95, 46, 0.35),
                0 0 0 1px rgba(255,255,255,0.1) inset;
            font-family: inherit;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        button[type="submit"]::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        button[type="submit"]:hover:not(:disabled)::before {
            width: 300px;
            height: 300px;
        }
        button[type="submit"]:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 
                0 10px 30px rgba(26, 95, 46, 0.45),
                0 0 0 1px rgba(255,255,255,0.15) inset;
        }
        button[type="submit"]:active:not(:disabled) {
            transform: translateY(-1px);
        }
        button[type="submit"]:disabled {
            background: linear-gradient(135deg, #d1d5db, #9ca3af);
            cursor: not-allowed;
            opacity: 0.7;
            box-shadow: none;
        }
        .right-side {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 40%, var(--secondary) 100%);
            color: white;
            padding: 70px 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        .right-side::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 60%),
                radial-gradient(circle at 30% 70%, rgba(74,222,128,0.2) 0%, transparent 50%);
            animation: pulse 10s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1) rotate(0deg); opacity: 0.6; }
            50% { transform: scale(1.15) rotate(5deg); opacity: 0.8; }
        }
        .right-side h3 {
            font-size: 2.75rem;
            font-weight: 900;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
            letter-spacing: -1.5px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .right-side p {
            font-size: 1.15rem;
            opacity: 0.98;
            line-height: 1.9;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
            font-weight: 500;
        }
        .ra173 {
            background: rgba(255,255,255,0.25);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            padding: 18px 36px;
            border-radius: 16px;
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: 4px;
            display: inline-block;
            margin: 28px 0;
            box-shadow: 
                0 10px 40px rgba(0,0,0,0.3),
                inset 0 1px 0 rgba(255,255,255,0.3);
            border: 2px solid rgba(255,255,255,0.4);
            position: relative;
            z-index: 1;
            transition: all 0.4s ease;
        }
        .ra173:hover {
            transform: scale(1.05);
            background: rgba(255,255,255,0.3);
        }
        .dpa-notice {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            padding: 28px;
            border-radius: 20px;
            margin-top: 35px;
            font-size: 0.95rem;
            line-height: 1.9;
            border: 2px solid rgba(255,255,255,0.3);
            position: relative;
            z-index: 1;
            box-shadow: 
                0 8px 32px rgba(0,0,0,0.2),
                inset 0 1px 0 rgba(255,255,255,0.2);
        }
        .dpa-notice strong {
            font-size: 1.3rem;
            display: block;
            margin-bottom: 10px;
            font-weight: 800;
        }
        .dpa-notice em {
            font-size: 1.05rem;
            opacity: 0.95;
            font-style: normal;
            font-weight: 600;
        }
        footer {
            margin-top: 45px;
            text-align: center;
            color: var(--text-light);
            font-size: 0.875rem;
            padding-top: 24px;
            border-top: 2px solid var(--border);
        }
        .privacy-text {
            margin: 18px 0;
            line-height: 1.9;
            font-size: 0.9rem;
        }
        footer a {
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
            border-bottom: 2px solid var(--primary);
            transition: all 0.3s ease;
        }
        footer a:hover {
            color: var(--primary-dark);
            border-bottom-color: var(--primary-dark);
        }
        #g_id_signin {
            pointer-events: none;
            opacity: 0.5;
            transition: opacity 0.3s ease;
        }
        #g_id_signin.enabled {
            pointer-events: auto;
            opacity: 1;
        }
        .feature-list {
            list-style: none;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }
        .feature-list li {
            padding: 10px 0;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .feature-list li::before {
            content: '✓';
            background: rgba(255,255,255,0.3);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <a href="index.html" class="home-btn">🏠 Home</a>
    <div class="box">
        <!-- LEFT: LOGIN FORM + LOGO -->
        <div class="left-side">
            <div class="logo">
                <img src="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" alt="Techno Pest Control Logo">
            </div>
            <div class="head">
                <h2>Welcome Back!</h2>
                <p>Sign in to book your pest control service</p>
            </div>

            <?php 
            // Check for redirect message from registration
            if (isset($_SESSION['register_message'])) {
                $register_msg = $_SESSION['register_message'];
                unset($_SESSION['register_message']);
                echo '<div class="err" style="background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e; border-color: #fbbf24;">';
                echo '<i class="fas fa-info-circle"></i> ' . htmlspecialchars($register_msg);
                echo '</div>';
            }
            ?>
            
            <?php if ($error): ?>
                <div class="err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="err" style="background: linear-gradient(135deg, var(--success-light), #dcfce7); color: var(--success); border-color: rgba(22, 163, 74, 0.2);">
                    <span style="font-size: 1.3rem; flex-shrink: 0;">✓</span>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <div class="privacy-checkbox">
                <input type="checkbox" id="privacy_agreement" name="privacy_agreement" value="1" required>
                <label for="privacy_agreement">
                    I agree to the <a href="#" onclick="showPrivacyPolicy(); return false;">Data Privacy Policy</a>
                    and consent to the processing of my personal information in accordance with
                    <strong>Republic Act No. 10173 (Data Privacy Act of 2012)</strong>.
                </label>
            </div>

            <div class="google-btn">
                <div id="g_id_onload" data-client_id="<?= htmlspecialchars($GOOGLE_CLIENT_ID) ?>" data-callback="onGoogleSignIn"></div>
                <div class="g_id_signin" id="g_id_signin" data-type="standard" data-size="large" data-theme="outline" data-text="continue_with" data-shape="pill"></div>
            </div>

            <div class="divider"><span>or use username/email</span></div>

            <?php if (isset($_SESSION['otp_required']) && $_SESSION['otp_required']): ?>
                <!-- OTP Verification Form -->
                <div class="otp-verification-section">
                    <div class="head" style="margin-bottom: 30px;">
                        <h2 style="font-size: 2rem;">Verify Your Identity</h2>
                        <p style="font-size: 1rem;">Enter the 6-digit code sent to<br><strong><?= htmlspecialchars($_SESSION['email'] ?? 'your email') ?></strong></p>
                    </div>
                    
                    <form method="post" id="otpForm">
                        <div class="otp-input-group">
                            <input type="text" name="otp" id="otp" placeholder="Enter 6-digit code" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="off" style="text-align: center; font-size: 1.5rem; letter-spacing: 8px; font-weight: 700;">
                        </div>
                        <button type="submit" name="verify_otp" id="verifyBtn">VERIFY CODE</button>
                    </form>
                    <div style="text-align: center; margin-top: 20px;">
                        <form method="post" style="display: inline;">
                            <button type="submit" name="resend_otp" id="resendBtn" style="background: transparent; color: var(--primary); border: 2px solid var(--primary); padding: 12px 24px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                                Resend Code
                            </button>
                        </form>
                    </div>
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="?cancel_otp=1" style="color: var(--text-light); text-decoration: none; font-size: 0.9rem;">Cancel and go back</a>
                    </div>
                </div>
            <?php elseif ($pdo): ?>
                <form method="post" id="loginForm">
                    <input type="hidden" name="privacy_agreement" id="form_privacy_agreement" value="0">
                    <input type="text" name="username" id="username" placeholder="Username or Email" required autofocus>
                    <input type="password" name="password" id="password" placeholder="Password" required>
                    <button type="submit" id="loginBtn">LOGIN WITH CREDENTIALS</button>
                </form>
            <?php else: ?>
                <p style="color:#dc2626;background:#fef2f2;padding:20px;border-radius:16px;border:2px solid #fca5a5;font-weight:600;">
                    Database temporarily unavailable.<br>Only Google Sign-In is working.
                </p>
            <?php endif; ?>

            <footer>
                <div class="privacy-text" style="margin-bottom: 1rem;">
                    Don't have an account? <a href="register.php" style="color: var(--primary); font-weight: 700; text-decoration: underline;">Create a new account</a>
                </div>
                <div class="privacy-text">
                    By signing in, you agree to our
                    <a href="#" onclick="showPrivacyPolicy(); return false;">Privacy Policy</a> and
                    <a href="#" onclick="showTermsOfService(); return false;">Terms of Service</a>.
                </div>
                © 2025 Techno Pest Control. All rights reserved.
            </footer>
        </div>

        <!-- RIGHT: BRANDING + COMPLIANCE -->
        <div class="right-side">
            <h3>Techno Pest Control</h3>
            <p>Professional • Reliable • Licensed</p>
            <div class="ra173">RA173 2012</div>
            <p>Trusted by thousands of homes and businesses across the Philippines.</p>
            <ul class="feature-list">
                <li>Eco-Friendly Solutions</li>
                <li>100% Guaranteed Results</li>
                <li>24/7 Customer Support</li>
                <li>Fully Licensed & Insured</li>
            </ul>
            <div class="dpa-notice">
                <strong>Republic Act No. 10173</strong>
                <em>Data Privacy Act of 2012 (DPA)</em><br><br>
                We are fully compliant with the <strong>Data Privacy Act of 2012</strong> — the primary law in the Philippines that protects personal information.<br><br>
                Your personal data is safe, secure, and used only to deliver excellent service.
            </div>
        </div>
    </div>

    <script>
        const privacyCheckbox = document.getElementById('privacy_agreement');
        const formPrivacyAgreement = document.getElementById('form_privacy_agreement');
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const googleSignIn = document.getElementById('g_id_signin');

        // Enable/disable Google Sign-In button based on checkbox
        function updateButtons() {
            const isChecked = privacyCheckbox.checked;
            if (formPrivacyAgreement) {
                formPrivacyAgreement.value = isChecked ? '1' : '0';
            }
            if (loginBtn) {
                loginBtn.disabled = !isChecked;
            }
            if (googleSignIn) {
                if (isChecked) {
                    googleSignIn.classList.add('enabled');
                } else {
                    googleSignIn.classList.remove('enabled');
                }
            }
        }

        // Initial state
        updateButtons();

        // Listen for checkbox changes
        if (privacyCheckbox) {
            privacyCheckbox.addEventListener('change', updateButtons);
        }

        // Validate form submission
        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                if (!privacyCheckbox.checked) {
                    e.preventDefault();
                    alert('Please agree to the Data Privacy Policy to continue.');
                    return false;
                }
            });
        }

        function onGoogleSignIn(r) {
            if (!privacyCheckbox.checked) {
                alert('Please agree to the Data Privacy Policy to continue.');
                return;
            }

            if (r?.credential) {
                const f = document.createElement('form');
                f.method = 'POST';

                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = 'google_token';
                tokenInput.value = r.credential;
                f.appendChild(tokenInput);

                const privacyInput = document.createElement('input');
                privacyInput.type = 'hidden';
                privacyInput.name = 'privacy_agreement';
                privacyInput.value = '1';
                f.appendChild(privacyInput);

                document.body.appendChild(f);
                f.submit();
            }
        }

        function showPrivacyPolicy() {
            alert('DATA PRIVACY POLICY\n\n' +
                  'Republic Act No. 10173 - Data Privacy Act of 2012\n\n' +
                  'Techno Pest Control is committed to protecting your personal information. We collect and process your data only for the purpose of providing pest control services.\n\n' +
                  'We will:\n' +
                  '• Keep your information secure and confidential\n' +
                  '• Use your data only for service delivery\n' +
                  '• Never share your information without your consent\n' +
                  '• Comply with all DPA requirements\n\n' +
                  'For more information, please contact us.');
        }

        function showTermsOfService() {
            alert('TERMS OF SERVICE\n\n' +
                  'Professional service with guaranteed results.\n\n' +
                  'By using our services, you agree to:\n' +
                  '• Provide accurate information\n' +
                  '• Allow us to process your data for service delivery\n' +
                  '• Follow safety guidelines during service\n\n' +
                  'We guarantee professional, eco-friendly pest control solutions.');
        }

        // OTP input handling
        const otpInput = document.getElementById('otp');
        if (otpInput) {
            // Only allow numbers
            otpInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
            
            // Auto-submit when 6 digits are entered
            otpInput.addEventListener('input', function(e) {
                if (this.value.length === 6) {
                    // Small delay to show the last digit
                    setTimeout(() => {
                        document.getElementById('otpForm').submit();
                    }, 300);
                }
            });
            
            // Paste handling
            otpInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasted = (e.clipboardData || window.clipboardData).getData('text');
                const numbers = pasted.replace(/[^0-9]/g, '').substring(0, 6);
                this.value = numbers;
                if (numbers.length === 6) {
                    setTimeout(() => {
                        document.getElementById('otpForm').submit();
                    }, 300);
                }
            });
        }
    </script>
</body>
</html>
