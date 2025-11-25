<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$DB_NAME = "pest control";

// Include audit logger
require_once 'audit_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = new mysqli('localhost', 'root', '', $DB_NAME);
    if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

    // Create PDO connection for audit logging
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=$DB_NAME;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        $pdo = null;
    }

    $error = "";

    if (!empty($_POST['username']) && !empty($_POST['password'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ? AND user_type = 'admin'");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_type'] = 'admin';

            // Log successful login
            if ($pdo) {
                logLogin($pdo, $user['username'], true, $user['id']);
            }

            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid credentials.";
            // Log failed login attempt
            if ($pdo) {
                logLogin($pdo, !empty($username) ? $username : 'Unknown', false);
            }
        }
    }
    elseif (!empty($_POST['google_id_token'])) {
        $token = $_POST['google_id_token'];
        $client_id = '331071626282-9vnptprgpjteva93n96ljnjhoe980j4b.apps.googleusercontent.com';
        $payload = json_decode(file_get_contents("https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($token)), true);

        if ($payload && $payload['aud'] === $client_id && $payload['email_verified']) {
            $email = $payload['email'];
            $stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ? AND user_type = 'admin'");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $_SESSION['id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_type'] = 'admin';

                // Log successful Google login
                if ($pdo) {
                    logLogin($pdo, $user['username'], true, $user['id']);
                }

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "No admin account linked.";
                // Log failed Google login
                if ($pdo) {
                    logLogin($pdo, !empty($email) ? $email : 'Unknown', false);
                }
            }
        } else {
            $error = "Google sign-in failed.";
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Techno Pest Control | Manager Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        :root {
            --primary: #16a34a;
            --primary-dark: #15803d;
            --primary-light: #22c55e;
            --accent: #10b981;
            --accent-hover: #059669;
            --success: #10b981;
            --bg: #f0fdf4;
            --bg-light: #ffffff;
            --text: #0f172a;
            --text-light: #64748b;
            --border: #bbf7d0;
            --border-light: #dcfce7;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05), 0 1px 2px rgba(0, 0, 0, 0.1);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.05), 0 10px 15px rgba(22, 163, 74, 0.1);
            --shadow-md: 0 10px 15px rgba(0, 0, 0, 0.1), 0 4px 6px rgba(22, 163, 74, 0.1);
            --shadow-lg: 0 20px 25px rgba(0, 0, 0, 0.1), 0 10px 10px rgba(22, 163, 74, 0.1);
            --shadow-xl: 0 25px 50px rgba(0, 0, 0, 0.15), 0 10px 20px rgba(22, 163, 74, 0.15);
            --shadow-2xl: 0 30px 60px rgba(22, 163, 74, 0.2);
            --radius: 14px;
            --radius-lg: 18px;
            --radius-xl: 24px;
            --radius-2xl: 32px;
            --radius-full: 9999px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #16a34a 0%, #10b981 50%, #22c55e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            color: var(--text);
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: ''; position: absolute; inset: 0;
            background:
                radial-gradient(circle at 25% 25%, rgba(255,255,255,0.15), transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(16, 185, 129, 0.25), transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(34, 197, 94, 0.1), transparent 70%);
            animation: pulse 8s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 0.6; }
            50% { opacity: 0.8; }
        }
        body::after {
            content: ''; position: absolute; inset: 0;
            background:
                linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.05) 100%),
                url('data:image/svg+xml,<svg width="60" height="60" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="dots" width="60" height="60" patternUnits="userSpaceOnUse"><circle cx="30" cy="30" r="1.5" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="60" height="60" fill="url(%23dots)"/></svg>');
            opacity: 0.4;
        }

        .home-btn {
            position: fixed;
            top: 24px;
            right: 24px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            color: var(--primary);
            padding: 0.75rem 1.75rem;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            border: 2px solid var(--primary);
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(22, 163, 74, 0.2);
        }
        .home-btn:hover {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(22, 163, 74, 0.3);
        }

        .container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            width: 100%;
            max-width: 1200px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(24px) saturate(200%);
            -webkit-backdrop-filter: blur(24px) saturate(200%);
            border-radius: var(--radius-2xl);
            overflow: hidden;
            box-shadow: var(--shadow-2xl);
            border: 1px solid rgba(255, 255, 255, 0.4);
            position: relative;
            z-index: 1;
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.96);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .left {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            padding: 5rem 3.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .left::before {
            content: ''; position: absolute; inset: 0;
            background:
                radial-gradient(circle at 20% 30%, rgba(255,255,255,0.15), transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(16, 185, 129, 0.25), transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(34, 197, 94, 0.1), transparent 70%);
            opacity: 0.7;
            animation: pulse 8s ease-in-out infinite;
        }
        .left::after {
            content: ''; position: absolute; inset: 0;
            background: url('data:image/svg+xml,<svg width="60" height="60" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="dots" width="60" height="60" patternUnits="userSpaceOnUse"><circle cx="30" cy="30" r="1.5" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="60" height="60" fill="url(%23dots)"/></svg>');
            opacity: 0.3;
        }

        .left img {
            width: 160px;
            height: 160px;
            border-radius: var(--radius-xl);
            padding: 20px;
            background: rgba(255,255,255,0.25);
            border: 4px solid rgba(255,255,255,0.5);
            box-shadow: 0 15px 50px rgba(0,0,0,0.4), 0 0 0 0 rgba(255,255,255,0.3);
            margin-bottom: 2.5rem;
            position: relative;
            z-index: 1;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .left:hover img {
            transform: scale(1.08) rotate(8deg) translateY(-5px);
            box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 30px rgba(255,255,255,0.4);
            border-color: rgba(255,255,255,0.7);
        }

        .left h1 {
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 900;
            background: linear-gradient(180deg, #ffffff 0%, rgba(255,255,255,0.9) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
            letter-spacing: -2px;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.8s ease 0.2s both;
        }

        .left p {
            font-size: 1.2rem;
            opacity: 0.95;
            max-width: 320px;
            line-height: 1.7;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.8s ease 0.4s both;
        }

        .right {
            padding: 5rem 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: white;
        }

        .right h2 {
            font-size: clamp(2rem, 4vw, 2.5rem);
            font-weight: 900;
            margin-bottom: 0.75rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -1px;
        }

        .right .subtitle {
            color: var(--text-light);
            margin-bottom: 2.5rem;
            font-size: 1.05rem;
            line-height: 1.6;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .right .subtitle::before {
            content: '\f023';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: var(--accent);
            font-size: 0.9rem;
        }

        .error {
            background: linear-gradient(135deg, rgba(239,68,68,0.1), rgba(220,38,38,0.1));
            border: 2px solid #f87171;
            color: #dc2626;
            padding: 1rem 1.25rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
            font-weight: 600;
            font-size: 0.95rem;
            box-shadow: 0 2px 8px rgba(239,68,68,0.1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: shake 0.5s ease;
        }
        .error::before {
            content: '\f06a';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 1.1rem;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }

        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .input-group i {
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 1.1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 2;
            pointer-events: none;
        }
        .input-group input {
            width: 100%;
            padding: 1.1rem 1.5rem 1.1rem 3.25rem;
            background: var(--bg);
            border: 2px solid var(--border-light);
            border-radius: var(--radius-lg);
            color: var(--text);
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
        }
        .input-group input::placeholder {
            color: var(--text-light);
        }
        .input-group input:focus {
            outline: none;
            border-color: var(--accent);
            background: white;
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.1), 0 4px 12px rgba(22, 163, 74, 0.15);
            transform: translateY(-2px);
        }
        .input-group input:focus ~ i,
        .input-group input:not(:placeholder-shown) ~ i {
            color: var(--accent);
            transform: translateY(-50%) scale(1.15);
        }
        input {
            width: 100%;
            padding: 1.1rem 1.5rem;
            background: var(--bg);
            border: 2px solid var(--border-light);
            border-radius: var(--radius-lg);
            color: var(--text);
            font-size: 1rem;
            margin-bottom: 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
        }
        input::placeholder {
            color: var(--text-light);
        }
        input:focus {
            outline: none;
            border-color: var(--accent);
            background: white;
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.1), 0 4px 12px rgba(22, 163, 74, 0.15);
            transform: translateY(-2px);
        }

        button {
            width: 100%;
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-lg);
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            margin: 1.5rem 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(22, 163, 74, 0.35);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        button i {
            transition: transform 0.3s ease;
        }
        button::before {
            content: ''; position: absolute; top: 50%; left: 50%;
            width: 0; height: 0; border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        button:hover::before {
            width: 400px; height: 400px;
        }
        button:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(22, 163, 74, 0.45);
        }
        button:hover i {
            transform: translateX(3px);
        }
        button:active {
            transform: translateY(-2px);
        }

        .divider {
            text-align: center;
            margin: 2rem 0;
            color: var(--text-light);
            position: relative;
            font-size: 0.95rem;
            font-weight: 500;
        }
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-light), transparent);
        }
        .divider span {
            background: white;
            padding: 0 1.5rem;
            position: relative;
            z-index: 1;
            color: var(--text-light);
        }

        #googleSignInBtn {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        #googleSignInBtn iframe {
            border-radius: var(--radius-lg) !important;
        }

        footer {
            text-align: center;
            margin-top: 2.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
            line-height: 1.6;
            padding-top: 2rem;
            border-top: 1px solid var(--border-light);
        }
        footer::before {
            content: '\f1ad';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: var(--accent);
            margin-right: 0.5rem;
            font-size: 0.85rem;
        }

        /* Mobile: Stack vertically */
        @media (max-width: 992px) {
            .container {
                grid-template-columns: 1fr;
                border-radius: var(--radius-xl);
            }
            .left {
                padding: 3.5rem 2.5rem;
                order: 2;
            }
            .left img {
                width: 140px;
                height: 140px;
                margin-bottom: 2rem;
            }
            .left h1 {
                font-size: 3rem;
            }
            .left p {
                font-size: 1.1rem;
            }
            .right {
                padding: 3.5rem 2.5rem;
                order: 1;
            }
            .home-btn {
                top: 16px;
                right: 16px;
                padding: 0.65rem 1.5rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 1rem;
            }
            .container {
                border-radius: var(--radius-lg);
            }
            .right {
                padding: 2.5rem 2rem;
            }
            .left {
                padding: 3rem 2rem;
            }
            .left h1 {
                font-size: 2.5rem;
            }
            .left img {
                width: 120px;
                height: 120px;
            }
            .right h2 {
                font-size: 1.75rem;
            }
            input {
                padding: 1rem 1.25rem;
                font-size: 0.95rem;
            }
            button {
                padding: 1.1rem;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

    <a href="index.html" class="home-btn">Home</a>

    <div class="container">
        <!-- LEFT: Branding -->
        <div class="left">
            <img src="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" alt="Techno Pest Control">
            <h1>TECHNO PEST</h1>
            <p>Professional • Reliable • Trusted<br>Pest Control Solutions</p>
        </div>

        <!-- RIGHT: Login Form -->
        <div class="right">
            <h2>Manager Portal</h2>
            <div class="subtitle">Secure access to management system</div>

            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                <input type="text" name="username" placeholder="Username" required autocomplete="username">
                    <i class="fas fa-user"></i>
                </div>
                <div class="input-group">
                <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                    <i class="fas fa-lock"></i>
                </div>
                <button type="submit">
                    <i class="fas fa-sign-in-alt" style="margin-right: 0.5rem;"></i>
                    LOGIN
                </button>
            </form>

            <div class="divider"><span>or</span></div>
            <div id="googleSignInBtn"></div>

            <form method="POST" id="googleForm" style="display:none;">
                <input type="hidden" name="google_id_token" id="googleToken">
            </form>

            <footer>© 2025 Techno Pest Control • RA 10173 Compliant</footer>
        </div>
    </div>

    <script>
        function handleGoogleSignIn(response) {
            if (response.credential) {
                document.getElementById("googleToken").value = response.credential;
                document.getElementById("googleForm").submit();
            }
        }
        window.onload = () => {
            google.accounts.id.initialize({
                client_id: '331071626282-9vnptprgpjteva93n96ljnjhoe980j4b.apps.googleusercontent.com',
                callback: handleGoogleSignIn
            });
            google.accounts.id.renderButton(document.getElementById("googleSignInBtn"), {
                theme: "outline", size: "large", shape: "pill", text: "continue_with"
            });
        };
    </script>
</body>
</html>
