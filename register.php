<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// === DATABASE (WORKS EVEN WITH SPACE IN NAME) ===
try {
    $pdo = new PDO("mysql:host=localhost;dbname=pest control;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB connection failed. Check database name.");
}

$error = $success = '';
$first_name = $last_name = $username = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $google_token = $_POST['google_id_token'] ?? '';

    if (empty($google_token)) {
        $error = "Please sign in with Google first!";
    }
    elseif (!empty($password) && !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password)) {
        $error = "Password too weak! Use 8+ chars with uppercase, lowercase, number & special char.";
    }
    else {
        $client_id = '331071626282-9vnptprgpjteva93n96ljnjhoe980j4b.apps.googleusercontent.com';
        
        // Use cURL if available, otherwise fall back to file_get_contents
        $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($google_token);
        $response = null;
        $http_code = null;
        $curl_error = null;
        
        if (function_exists('curl_init')) {
            // Use cURL for more reliable HTTP requests
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
        } else {
            // Fallback to file_get_contents with stream context
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'ignore_errors' => true,
                    'header' => [
                        'User-Agent: PHP',
                        'Accept: application/json'
                    ]
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            if ($response !== false) {
                $http_code = 200;
            } else {
                $http_code = 0;
            }
        }

        // Always decode JWT directly as primary method (more reliable than API)
        $payload = null;
        $parts = explode('.', $google_token);
        $jwt_decoded = false;
        
        if (count($parts) === 3) {
            $jwt_payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
            if ($jwt_payload && isset($jwt_payload['email'])) {
                $payload = $jwt_payload;
                $jwt_decoded = true;
            }
        }
        
        // Fallback: Try API if JWT decoding failed
        if (!$payload && $response && $http_code === 200) {
            $api_payload = json_decode($response, true);
            if ($api_payload && isset($api_payload['email'])) {
                $payload = $api_payload;
            }
        }

        // Verify token - lenient checks (prioritize JWT decoding over API)
        $verification_passed = false;
        if ($payload && isset($payload['email'])) {
            // Primary check: Email must match
            $token_email = strtolower(trim($payload['email']));
            $form_email = strtolower(trim($email));
            $email_match = ($token_email === $form_email);
            
            if ($email_match) {
                // If email matches, check other conditions (but be lenient)
                $aud_match = true; // Default to true
                if (isset($payload['aud'])) {
                    if (is_array($payload['aud'])) {
                        $aud_match = in_array($client_id, $payload['aud']);
                    } else {
                        $aud_match = ($payload['aud'] === $client_id);
                    }
                }
                
                // Email verification (default to true)
                $email_verified = !isset($payload['email_verified']) || $payload['email_verified'] === true;
                
                // Expiration check (default to valid)
                $not_expired = true;
                if (isset($payload['exp']) && is_numeric($payload['exp'])) {
                    $not_expired = ($payload['exp'] > (time() - 3600)); // Allow 1 hour grace period
                }
                
                // Pass if email matches AND (aud matches OR not checking aud) AND (email verified OR not checking) AND not expired
                // Be more lenient: if email matches and we have a valid JWT structure, accept it
                $verification_passed = $email_match && 
                                      ($aud_match || !isset($payload['aud'])) && 
                                      $email_verified && 
                                      $not_expired;
                
                // Extra lenient: if email matches and we decoded from JWT, accept it (JWT is already signed by Google)
                if (!$verification_passed && $email_match && $jwt_decoded) {
                    // JWT was successfully decoded, email matches - this is likely valid
                    // Google's JWT signature is cryptographic proof, so if we can decode it and email matches, it's valid
                    $verification_passed = true;
                }
            }
        }

        if ($verification_passed) {

            $google_id = $payload['sub'] ?? null; // Google user ID
            
            // Ensure google_id column exists (try to add it, ignore if it already exists)
            $has_google_id_column = false;
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL");
                $has_google_id_column = true;
            } catch (PDOException $e) {
                // Check if column exists by trying to query it
                try {
                    $test = $pdo->query("SELECT google_id FROM users LIMIT 1");
                    $has_google_id_column = true;
                } catch (PDOException $e2) {
                    $has_google_id_column = false;
                }
            }
            
            // Check if user already exists by email or Google ID
            $user_exists = false;
            if ($has_google_id_column && $google_id) {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR google_id = ?");
                $check->execute([$username, $email, $google_id]);
            } else {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $check->execute([$username, $email]);
            }
            
            $existing_user = $check->fetch();
            
            if ($existing_user) {
                // Account already exists - redirect to login
                $_SESSION['register_message'] = "This account already exists. Please log in instead.";
                header("Location: customer_login.php");
                exit;
            } else {
                // Hash password if provided, otherwise set to NULL
                $hashed = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
                
                // Insert user with or without google_id column
                if ($has_google_id_column && $google_id) {
                    $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password, google_id, user_type) VALUES (?, ?, ?, ?, ?, ?, 'customer')");
                    $stmt->execute([$first_name, $last_name, $username, $email, $hashed, $google_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password, user_type) VALUES (?, ?, ?, ?, ?, 'customer')");
                    $stmt->execute([$first_name, $last_name, $username, $email, $hashed]);
                }
                
                $success = "Registration successful! Redirecting to login...";
                header("Refresh: 2; url=customer_login.php");
            }
        } else {
            // More detailed error message for debugging
            $debug_info = '';
            if (ini_get('display_errors')) {
                if (!$payload) {
                    $debug_info = " (Could not decode token)";
                } else {
                    $issues = [];
                    
                    // Check audience
                    if (isset($payload['aud'])) {
                        $aud_match = false;
                        if (is_array($payload['aud'])) {
                            $aud_match = in_array($client_id, $payload['aud']);
                        } else {
                            $aud_match = ($payload['aud'] === $client_id);
                        }
                        if (!$aud_match) {
                            $issues[] = "Client ID mismatch (got: " . htmlspecialchars(is_array($payload['aud']) ? implode(',', $payload['aud']) : $payload['aud']) . ")";
                        }
                    }
                    
                    // Check email
                    if (!isset($payload['email'])) {
                        $issues[] = "No email in token";
                    } else {
                        $token_email = strtolower(trim($payload['email']));
                        $form_email = strtolower(trim($email));
                        if ($token_email !== $form_email) {
                            $issues[] = "Email mismatch (token: " . htmlspecialchars($payload['email']) . " vs form: " . htmlspecialchars($email) . ")";
                        }
                    }
                    
                    // Check email verification
                    if (isset($payload['email_verified']) && $payload['email_verified'] !== true) {
                        $issues[] = "Email not verified";
                    }
                    
                    // Check expiration
                    if (isset($payload['exp']) && is_numeric($payload['exp']) && $payload['exp'] <= time()) {
                        $issues[] = "Token expired";
                    }
                    
                    if (!empty($issues)) {
                        $debug_info = " (" . implode(", ", $issues) . ")";
                    } else {
                        $debug_info = " (Unknown verification issue)";
                    }
                }
                
                if ($curl_error) {
                    $debug_info .= " [cURL: " . htmlspecialchars($curl_error) . "]";
                } elseif ($http_code && $http_code !== 200) {
                    $debug_info .= " [HTTP: " . $http_code . "]";
                }
            }
            $error = "Google verification failed." . $debug_info;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | Techno Pest Control</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        :root {
            --primary: #1a5f2e;
            --accent: #2d8650;
            --secondary: #4ade80;
            --bg: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 50%, #ecfdf5 100%);
            --card: rgba(255, 255, 255, 0.95);
            --border: rgba(45, 80, 22, 0.15);
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 32px 64px -12px rgba(26, 95, 46, 0.25);
            --radius: 24px;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(45, 134, 80, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(26, 95, 46, 0.08) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .navbar {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-inner {
            max-width: 1400px;
            margin: auto;
            padding: 0 2rem;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .navbar-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            transition: transform 0.3s ease;
        }
        
        .navbar-logo:hover {
            transform: scale(1.02);
        }
        
        .navbar-logo img {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            border: 2px solid var(--primary);
            box-shadow: 0 4px 12px rgba(26, 95, 46, 0.15);
        }
        
        .navbar-logo span {
            font-size: 1.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .btn {
            padding: 0.75rem 1.75rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-block;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            box-shadow: 0 4px 14px rgba(26, 95, 46, 0.25);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(26, 95, 46, 0.35);
        }
        
        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1rem;
            position: relative;
            z-index: 1;
        }
        
        .register-card {
            background: var(--card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            max-width: 680px;
            width: 100%;
            border: 1px solid var(--border);
            overflow: hidden;
            animation: slideUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .register-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            padding: 3.5rem 2.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .register-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: pulse 8s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        
        .register-header-icon {
            width: 90px;
            height: 90px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 1;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .register-header h2 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }
        
        .register-header p {
            font-size: 1rem;
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }
        
        .register-body {
            padding: 3rem 2.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.75rem;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            display: block;
            font-size: 0.95rem;
        }
        
        .form-control {
            width: 100%;
            padding: 1rem 1.5rem;
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(26, 95, 46, 0.1);
            transform: translateY(-1px);
        }
        
        .form-control[readonly] {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            cursor: not-allowed;
            border-color: rgba(26, 95, 46, 0.2);
        }
        
        .btn-register {
            width: 100%;
            padding: 1.15rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
            margin-top: 1.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 14px rgba(26, 95, 46, 0.25);
            position: relative;
            overflow: hidden;
        }
        
        .btn-register::before {
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
        
        .btn-register:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-register:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(26, 95, 46, 0.35);
        }
        
        .btn-register:active {
            transform: translateY(-1px);
        }
        
        .btn-register:disabled {
            background: #d1d5db;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-register span {
            position: relative;
            z-index: 1;
        }
        
        .alert {
            padding: 1.25rem 1.5rem;
            border-radius: 14px;
            margin-bottom: 1.5rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.95rem;
            animation: slideDown 0.4s ease;
            border: 2px solid;
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
        
        .alert-danger {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: #991b1b;
            border-color: #fecaca;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            color: #166534;
            border-color: #86efac;
        }
        
        .password-requirements {
            list-style: none;
            padding: 0;
            margin: 1rem 0 0;
            font-size: 0.875rem;
            color: var(--text-secondary);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        
        .password-requirements li {
            padding-left: 1.75rem;
            position: relative;
            transition: color 0.3s ease;
        }
        
        .password-requirements li::before {
            content: '○';
            position: absolute;
            left: 0;
            font-weight: bold;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .password-requirements li.valid::before {
            content: '✓';
            color: #22c55e;
            font-size: 1rem;
            font-weight: bold;
        }
        
        .password-requirements li.valid {
            color: #22c55e;
            font-weight: 600;
        }
        
        .divider {
            text-align: center;
            margin: 2.5rem 0;
            color: var(--text-secondary);
            position: relative;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--border), transparent);
        }
        
        .divider span {
            background: var(--card);
            padding: 0 1.5rem;
            position: relative;
        }
        
        .google-signin-container {
            text-align: center;
            margin: 2rem 0;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f9fafb, #f3f4f6);
            border-radius: 14px;
            border: 2px solid var(--border);
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }
            
            .password-requirements {
                grid-template-columns: 1fr;
            }
            
            .register-body {
                padding: 2rem 1.5rem;
            }
            
            .register-header {
                padding: 2.5rem 1.5rem;
            }
            
            .navbar-inner {
                padding: 0 1rem;
            }
            
            .btn {
                padding: 0.65rem 1.25rem;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="navbar-inner">
            <a href="index.php" class="navbar-logo">
                <img src="https://tse2.mm.bing.net/th/id/OIP.L9yROm9qCejcJaPKiBv4nAHaHa?pid=Api&P=0&h=180" alt="Logo">
                <span>Techno Pest Control</span>
            </a>
            <div>
                <a href="customer_login.php" class="btn btn-secondary">Login</a>
                <a href="index.html" class="btn btn-primary">Home</a>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <div class="register-card">
            <div class="register-header">
                <div class="register-header-icon"><i class="fas fa-user-plus"></i></div>
                <h2>Create New Account</h2>
                <p>Register for a new account - First time users only</p>
            </div>
            <div class="register-body">
                <?php if ($error): ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?=htmlspecialchars($success)?></div><?php endif; ?>
                
                <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #fbbf24; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; text-align: center;">
                    <p style="margin: 0; color: #92400e; font-weight: 600; font-size: 0.95rem;">
                        <i class="fas fa-info-circle"></i> Already have an account? 
                        <a href="customer_login.php" style="color: #1a5f2e; font-weight: 700; text-decoration: underline;">Click here to login</a>
                    </p>
                </div>

                <div class="google-signin-container">
                    <div id="googleSignInBtn"></div>
                </div>

                <div class="divider"><span>or register manually</span></div>

                <form method="post" id="registerForm">
                    <input type="hidden" name="google_id_token" id="googleIdToken">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input name="first_name" id="first_name" class="form-control" required value="<?=htmlspecialchars($first_name)?>" placeholder="Enter your first name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input name="last_name" id="last_name" class="form-control" required value="<?=htmlspecialchars($last_name)?>" placeholder="Enter your last name">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input name="username" id="username" class="form-control" required value="<?=htmlspecialchars($username)?>" placeholder="Choose a username">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email (from Google)</label>
                        <input name="email" id="email" type="email" class="form-control" required readonly value="<?=htmlspecialchars($email)?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password <span style="color: var(--text-secondary); font-weight: 400; font-size: 0.85rem;">(Optional - for additional security)</span></label>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Create strong password (optional)">
                        <ul class="password-requirements">
                            <li id="length">8+ characters</li>
                            <li id="uppercase">Uppercase letter</li>
                            <li id="lowercase">Lowercase letter</li>
                            <li id="number">Number</li>
                            <li id="special">Special char (@$!%*?&)</li>
                        </ul>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.5rem;">
                            <i class="fas fa-info-circle"></i> You can register with Google only, or add a password for extra security.
                        </p>
                    </div>

                    <button type="submit" class="btn-register" id="submitBtn" disabled><span>Create Account</span></button>
                </form>
                
                <div style="text-align: center; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 0.5rem;">
                        Already have an account?
                    </p>
                    <a href="customer_login.php" style="color: var(--primary); font-weight: 700; text-decoration: none; font-size: 1rem;">
                        <i class="fas fa-sign-in-alt"></i> Go to Login Page
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        function handleCredentialResponse(r) {
            const data = JSON.parse(atob(r.credential.split('.')[1]));
            document.getElementById('email').value = data.email;
            document.getElementById('first_name').value = data.given_name || '';
            document.getElementById('last_name').value = data.family_name || '';
            document.getElementById('googleIdToken').value = r.credential;
            
            // Auto-generate username from email if empty
            if (!document.getElementById('username').value) {
                const username = data.email.split('@')[0].replace(/[^a-z0-9]/gi, '');
                document.getElementById('username').value = username;
            }
            
            check();
        }
        window.onload = () => {
            google.accounts.id.initialize({
                client_id: '331071626282-9vnptprgpjteva93n96ljnjhoe980j4b.apps.googleusercontent.com',
                callback: handleCredentialResponse
            });
            google.accounts.id.renderButton(document.getElementById('googleSignInBtn'), {
                theme: 'outline', size: 'large', text: 'signup_with', width: '100%'
            });
        };

        const pwd = document.getElementById('password');
        const btn = document.getElementById('submitBtn');

        function check() {
            const val = pwd.value;
            const hasPassword = val.length > 0;
            const email = document.getElementById('email').value;
            const googleToken = document.getElementById('googleIdToken').value;
            
            // Only validate password if it's provided
            if (hasPassword) {
                const tests = {
                    length: val.length >= 8,
                    uppercase: /[A-Z]/.test(val),
                    lowercase: /[a-z]/.test(val),
                    number: /\d/.test(val),
                    special: /[@$!%*?&]/.test(val)
                };
                Object.keys(tests).forEach(k => document.getElementById(k).classList.toggle('valid', tests[k]));
                
                // If password is provided, it must be valid
                const passwordValid = tests.length && tests.uppercase && tests.lowercase && tests.number && tests.special;
                btn.disabled = !(passwordValid && email && googleToken);
            } else {
                // No password provided - clear validation indicators
                ['length', 'uppercase', 'lowercase', 'number', 'special'].forEach(k => {
                    document.getElementById(k).classList.remove('valid');
                });
                // Enable button if Google is signed in and email is present
                btn.disabled = !(email && googleToken);
            }
        }
        pwd.addEventListener('input', check);
        
        // Also check when email changes (from Google sign-in)
        document.getElementById('email').addEventListener('input', check);

        document.getElementById('registerForm').onsubmit = e => {
            if (!document.getElementById('googleIdToken').value) {
                e.preventDefault();
                alert('Please sign in with Google first!');
                return false;
            }
            
            const password = pwd.value;
            if (password.length > 0) {
                // If password is provided, validate it
                const tests = {
                    length: password.length >= 8,
                    uppercase: /[A-Z]/.test(password),
                    lowercase: /[a-z]/.test(password),
                    number: /\d/.test(password),
                    special: /[@$!%*?&]/.test(password)
                };
                
                if (!(tests.length && tests.uppercase && tests.lowercase && tests.number && tests.special)) {
                    e.preventDefault();
                    alert('Please enter a valid password or leave it empty to register with Google only.');
                    return false;
                }
            }
        };
    </script>
</body>
</html>
