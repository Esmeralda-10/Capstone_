<?php
session_start();

// Include audit logger and log logout before destroying session
if (isset($_SESSION['username']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    require_once 'audit_logger.php';
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=pest control;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $userId = $_SESSION['id'] ?? null;
        logLogout($pdo, $_SESSION['username'], $userId);
    } catch (PDOException $e) {
        // Silently fail if logging doesn't work
        error_log("Logout audit log error: " . $e->getMessage());
    }
}

// Destroy session
session_unset();
session_destroy();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="3;url=index.html">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out...</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 25%, #2d7a47 50%, #22c55e 75%, #16a34a 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Floating Particles */
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

        .logout-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 60px 50px;
            border-radius: 32px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 500px;
            width: 90%;
            position: relative;
            z-index: 1;
            animation: fadeInUp 0.8s ease;
            border: 2px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
        }

        .logout-container::before {
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

        @keyframes gradientMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logout-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(34, 197, 94, 0.4);
            animation: pulse 2s ease-in-out infinite;
            position: relative;
        }

        .logout-icon::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            animation: ripple 2s ease-out infinite;
        }

        .logout-icon i {
            font-size: 3rem;
            color: white;
            position: relative;
            z-index: 1;
            animation: checkmark 0.6s ease 0.3s both;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        @keyframes ripple {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            100% {
                transform: scale(1.5);
                opacity: 0;
            }
        }

        @keyframes checkmark {
            0% {
                transform: scale(0) rotate(-45deg);
                opacity: 0;
            }
            50% {
                transform: scale(1.2) rotate(-45deg);
            }
            100% {
                transform: scale(1) rotate(0deg);
                opacity: 1;
            }
        }

        .logout-container h2 {
            color: #0f4c3a;
            margin-bottom: 15px;
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logout-container p {
            color: #6b7280;
            margin-bottom: 30px;
            font-size: 1.1rem;
            font-weight: 500;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: progressAnimation 3s linear, gradientMove 3s ease infinite;
            border-radius: 10px;
            width: 0%;
        }

        @keyframes progressAnimation {
            0% {
                width: 0%;
            }
            100% {
                width: 100%;
            }
        }

        .redirect-note {
            font-size: 0.95rem;
            color: #9ca3af;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .redirect-note i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .countdown {
            color: #0f4c3a;
            font-weight: 700;
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .logout-container {
                padding: 40px 30px;
                border-radius: 24px;
            }

            .logout-icon {
                width: 80px;
                height: 80px;
            }

            .logout-icon i {
                font-size: 2.5rem;
            }

            .logout-container h2 {
                font-size: 1.5rem;
            }

            .logout-container p {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Floating Particles -->
    <div class="particles" id="particles"></div>

    <div class="logout-container">
        <div class="logout-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h2>Successfully Logged Out</h2>
        <p>Your session has been ended securely.</p>
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        <div class="redirect-note">
            <i class="fas fa-spinner"></i>
            <span>Redirecting to login page in <span class="countdown" id="countdown">3</span> seconds...</span>
        </div>
    </div>

    <script>
        // Create floating particles
        const particlesContainer = document.getElementById('particles');
        const particleCount = 30;
        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.left = `${Math.random() * 100}vw`;
            particle.style.animationDelay = `${Math.random() * 20}s`;
            particle.style.opacity = Math.random() * 0.5 + 0.2;
            particle.style.transform = `scale(${Math.random() * 0.5 + 0.5})`;
            particlesContainer.appendChild(particle);
        }

        // Countdown timer
        let countdown = 3;
        const countdownElement = document.getElementById('countdown');
        const countdownInterval = setInterval(() => {
            countdown--;
            if (countdownElement) {
                countdownElement.textContent = countdown;
            }
            if (countdown <= 0) {
                clearInterval(countdownInterval);
            }
        }, 1000);
    </script>
</body>
</html>
