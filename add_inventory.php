<?php
session_start();

try {
    $pdo = new PDO("mysql:host=localhost;dbname=pest control;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// === AJAX: Search Active Ingredients ===
if (isset($_GET['search_chem'])) {
    header('Content-Type: application/json');
    $q = '%' . strtolower(trim($_GET['search_chem'] ?? '')) . '%';
    $stmt = $pdo->prepare("SELECT ai_id, name FROM active_ingredients WHERE LOWER(name) LIKE ? ORDER BY name LIMIT 15");
    $stmt->execute([$q]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// === AJAX: Add New Active Ingredient ===
if (isset($_GET['add_chem'])) {
    header('Content-Type: application/json');
    $name = trim($_GET['add_chem']);
    if (strlen($name) < 2) {
        echo json_encode(['error' => 'Name too short']);
        exit;
    }
    $formatted = ucfirst(strtolower($name));

    $stmt = $pdo->prepare("SELECT ai_id FROM active_ingredients WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$formatted]);
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("SELECT ai_id, name FROM active_ingredients WHERE LOWER(name) = LOWER(?)");
        $stmt->execute([$formatted]);
        echo json_encode($stmt->fetch());
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO active_ingredients (name) VALUES (?)");
    $stmt->execute([$formatted]);
    echo json_encode(['ai_id' => $pdo->lastInsertId(), 'name' => $formatted]);
    exit;
}

// === FORM SUBMISSION – THIS IS THE FIXED PART ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_id      = (int)($_POST['service_id'] ?? 0);
    $ai_id           = (int)($_POST['ai_id'] ?? 0);
    $stocks          = (int)round((float)($_POST['stocks'] ?? 0));
    $expiry_date     = empty($_POST['expiry_date']) ? null : $_POST['expiry_date'];
    $barcode         = trim($_POST['barcode'] ?? '') ?: null;

    // Validation
    if ($service_id <= 0) {
        $_SESSION['error'] = "Please select a service type.";
    } elseif ($ai_id <= 0) {
        $_SESSION['error'] = "Please select or add a chemical.";
    } elseif ($stocks <= 0) {
        $_SESSION['error'] = "Stock must be greater than zero.";
    } else {
        try {
            // THIS IS THE CORRECT INSERT FOR YOUR CURRENT TABLE STRUCTURE
            $sql = "INSERT INTO inventory
                    (service_id, active_ingredient, ingredient_id, ai_id, stocks, expiry_date, barcode, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $pdo->prepare($sql);
            // We fill all three columns with the same ai_id so no more “field doesn't have default” errors
            $stmt->execute([
                $service_id,
                $ai_id,      // active_ingredient  ← your column name
                $ai_id,      // ingredient_id      ← your column name (if it exists)
                $ai_id,      // ai_id
                $stocks,
                $expiry_date,
                $barcode
            ]);

            // Get the name for success message
            $stmtName = $pdo->prepare("SELECT name FROM active_ingredients WHERE ai_id = ?");
            $stmtName->execute([$ai_id]);
            $aiName = $stmtName->fetchColumn() ?: 'Unknown';

            $_SESSION['success'] = "Bottle added successfully for <strong>$aiName</strong>!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to add bottle: " . $e->getMessage();
        }
    }
    header("Location: add_inventory.php");
    exit;
}

// Fetch services for dropdown
$services = $pdo->query("SELECT service_id, service_name FROM services ORDER BY service_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Inventory - TECHNO PEST</title>
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
        }
        .user-section {
            padding: 1.5rem;
            border-top: 2px solid rgba(110, 231, 183, 0.2);
            margin-top: auto;
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
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--green-500);
            margin-bottom: 2rem;
        }
        .form-label {
            font-weight: 600;
            color: var(--green-700);
            margin-bottom: 0.75rem;
        }
        .form-control-modern, .form-select-modern {
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
            color: var(--dark);
            font-size: 1rem;
        }
        .form-control-modern:focus, .form-select-modern:focus {
            border-color: var(--green-500);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
            outline: none;
            background-color: white;
        }
        .form-select-modern {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2316a34a' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
        }
        .live-search {
            position: relative;
        }
        #chemResults {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1050;
            background: white;
            border: 2px solid var(--green-300);
            border-top: none;
            border-radius: 0 0 12px 12px;
            max-height: 250px;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,.15);
        }
        .result-item {
            padding: 0.875rem 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border-bottom: 1px solid var(--border);
        }
        .result-item:hover {
            background: var(--green-50);
            transform: translateX(5px);
        }
        .result-item:last-child {
            border-bottom: none;
        }
        .add-new {
            color: var(--green-600);
            font-weight: 600;
            text-align: center;
            border-top: 2px dashed var(--green-300);
            background: var(--green-50);
        }
        .add-new:hover {
            background: var(--green-100);
        }
        .btn-modern {
            padding: 0.875rem 2rem;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .btn-success-modern {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);
        }
        .btn-success-modern:hover {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            box-shadow: 0 6px 30px rgba(16, 185, 129, 0.6);
            transform: translateY(-2px);
            color: white;
        }
        .back-btn-modern {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        .back-btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
            color: white;
        }
        .page-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--green-700);
            margin-bottom: 2rem;
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
                <p>Inventory Management</p>
            </div>
            <nav class="nav-menu">
              <div class="user-section">
                  <a href="inventory.php" class="btn back-btn-modern w-100">
                      <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                  </a>
              </div>
                <div class="nav-item">
                    <a href="inventory.php" class="nav-link">
                        <i class="bi bi-box-seam"></i>
                        <span>Inventory</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="add_inventory.php" class="nav-link active">
                        <i class="bi bi-plus-circle"></i>
                        <span>Add Inventory</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="inventory_report.php" class="nav-link">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                        <span>Reports</span>
                    </a>
                </div>
            </nav>

        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <h1 class="page-title">
                <i class="bi bi-plus-circle me-2"></i>Add New Inventory Item
            </h1>

            <div class="content-card">
                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle me-2"></i>
                        <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php elseif (!empty($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label">
                            <i class="bi bi-list-ul me-2"></i>Service Type
                        </label>
                        <select name="service_id" class="form-select form-select-modern" required>
                            <option value="">Choose service...</option>
                            <?php foreach ($services as $s): ?>
                                <option value="<?= $s['service_id'] ?>"><?= htmlspecialchars(ucwords(strtolower($s['service_name']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            <i class="bi bi-flask me-2"></i>Chemical
                        </label>
                        <div class="live-search">
                            <input type="text" id="chemInput" class="form-control form-control-modern" placeholder="Type to search or add..." autocomplete="off" required>
                            <div id="chemResults" class="d-none"></div>
                        </div>
                        <input type="hidden" name="ai_id" id="ai_id" required>
                        <small class="text-muted mt-1 d-block">
                            <i class="bi bi-info-circle me-1"></i>Type to search existing chemicals or add a new one
                        </small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            <i class="bi bi-box-seam me-2"></i>Stock (bottles)
                        </label>
                        <input type="number" step="1" min="1" name="stocks" class="form-control form-control-modern" placeholder="Enter stock amount" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">
                            <i class="bi bi-calendar-x me-2"></i>Expiry Date (optional)
                        </label>
                        <input type="date" name="expiry_date" class="form-control form-control-modern">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">
                            <i class="bi bi-upc-scan me-2"></i>Barcode (optional)
                        </label>
                        <input type="text" name="barcode" class="form-control form-control-modern" placeholder="Enter barcode">
                    </div>

                    <div class="col-12 mt-4">
                        <div class="d-flex justify-content-end gap-3">
                            <a href="inventory.php" class="btn btn-secondary rounded-pill px-4">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-modern btn-success-modern rounded-pill px-5">
                                <i class="bi bi-plus-circle me-2"></i>Add to Inventory
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const chemInput = document.getElementById('chemInput');
        const chemResults = document.getElementById('chemResults');
        const ai_id = document.getElementById('ai_id');
        let timeout;

        function formatName(n) {
            return n.trim().charAt(0).toUpperCase() + n.trim().slice(1).toLowerCase();
        }

        chemInput.addEventListener('input', () => {
            clearTimeout(timeout);
            const q = chemInput.value.trim();
            if (!q) {
                chemResults.classList.add('d-none');
                return;
            }

            timeout = setTimeout(() => {
                fetch(`?search_chem=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        chemResults.innerHTML = '';
                        if (data && data.length > 0) {
                            data.forEach(item => {
                                const div = document.createElement('div');
                                div.className = 'result-item';
                                div.innerHTML = `<i class="bi bi-flask me-2"></i>${item.name}`;
                                div.onclick = () => {
                                    chemInput.value = item.name;
                                    ai_id.value = item.ai_id;
                                    chemResults.classList.add('d-none');
                                };
                                chemResults.appendChild(div);
                            });
                        }
                        // Always show Add new option
                        const add = document.createElement('div');
                        add.className = 'result-item add-new';
                        add.innerHTML = `<i class="bi bi-plus-circle me-2"></i>Add "<strong>${formatName(q)}</strong>" as new chemical`;
                        add.onclick = () => {
                            fetch(`?add_chem=${encodeURIComponent(formatName(q))}`)
                                .then(r => r.json())
                                .then(d => {
                                    if (d.error) {
                                        alert('Error: ' + d.error);
                                        return;
                                    }
                                    chemInput.value = d.name;
                                    ai_id.value = d.ai_id;
                                    chemResults.classList.add('d-none');

                                    // Show success notification
                                    const notification = document.createElement('div');
                                    notification.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                                    notification.style.zIndex = '9999';
                                    notification.innerHTML = `
                                        <i class="bi bi-check-circle me-2"></i>
                                        <strong>Success!</strong> "${d.name}" has been added as a new chemical.
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    `;
                                    document.body.appendChild(notification);
                                    setTimeout(() => notification.remove(), 3000);
                                })
                                .catch(err => {
                                    alert('Error adding chemical. Please try again.');
                                });
                        };
                        chemResults.appendChild(add);
                        chemResults.classList.remove('d-none');
                    })
                    .catch(err => {
                        console.error('Search error:', err);
                    });
            }, 300);
        });

        document.addEventListener('click', e => {
            if (!chemInput.contains(e.target) && !chemResults.contains(e.target)) {
                chemResults.classList.add('d-none');
            }
        });

        // Form validation
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (!ai_id.value) {
                    e.preventDefault();
                    alert('Please select or add a chemical.');
                    chemInput.focus();
                    return false;
                }
            });
        }
    </script>
</body>
</html>
