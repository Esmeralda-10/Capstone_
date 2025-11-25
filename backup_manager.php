<?php
/**
 * Backup Manager - View and manage website backups
 */

session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

require_once __DIR__ . '/cloud_backup.php';

$backupDir = __DIR__ . '/backups';
$cloudStorageDir = __DIR__ . '/cloud_storage/backups';

// Get all backup files
function getBackupFiles($dir) {
    $files = [];
    if (is_dir($dir)) {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $filepath = $dir . '/' . $item;
            if (is_file($filepath)) {
                $files[] = [
                    'name' => $item,
                    'path' => $filepath,
                    'size' => filesize($filepath),
                    'size_formatted' => formatBytes(filesize($filepath)),
                    'modified' => date('Y-m-d H:i:s', filemtime($filepath)),
                    'date' => filemtime($filepath),
                ];
            }
        }
    }
    // Sort by date (newest first)
    usort($files, function($a, $b) {
        return $b['date'] - $a['date'];
    });
    return $files;
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Handle backup action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'run_backup') {
        try {
            // Set execution time limit for large backups
            set_time_limit(600); // 10 minutes
            ini_set('memory_limit', '512M'); // Increase memory limit
            
            // Ensure backup directory exists
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            // Ensure cloud storage backup directory exists
            if (!is_dir($cloudStorageDir)) {
                mkdir($cloudStorageDir, 0755, true);
            }
            
            $backup = new CloudBackup();
            $result = $backup->backup();
            
            // Get backup log for details
            $logLines = $backup->getLastLog(20);
            $logOutput = implode('', $logLines);
            
            if ($result) {
                // Get list of created backup files
                $backupFiles = getBackupFiles($backupDir);
                $newBackups = array_slice($backupFiles, 0, 2); // Get 2 most recent (database + files)
                
                $backupList = '';
                foreach ($newBackups as $backupFile) {
                    $backupList .= "\n- " . $backupFile['name'] . " (" . $backupFile['size_formatted'] . ")";
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Backup completed successfully! All website data has been backed up.' . $backupList,
                    'log' => $logOutput
                ]);
            } else {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Backup process completed but may have encountered issues. Check logs for details.',
                    'log' => $logOutput
                ]);
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $trace = $e->getTraceAsString();
            
            echo json_encode([
                'success' => false, 
                'message' => 'Backup failed: ' . $errorMessage,
                'log' => "Error: " . $errorMessage . "\n\nTrace:\n" . substr($trace, 0, 500)
            ]);
        } catch (Error $e) {
            $errorMessage = $e->getMessage();
            
            echo json_encode([
                'success' => false, 
                'message' => 'Backup error: ' . $errorMessage,
                'log' => "Fatal Error: " . $errorMessage
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'delete_backup') {
        $filepath = $_POST['filepath'] ?? '';
        $fullPath = $backupDir . '/' . basename($filepath);
        
        // Security check
        $realPath = realpath($fullPath);
        $realBackupDir = realpath($backupDir);
        
        if ($realPath && $realBackupDir && strpos($realPath, $realBackupDir) === 0) {
            if (file_exists($fullPath) && unlink($fullPath)) {
                // Also delete from cloud storage if exists
                $cloudPath = $cloudStorageDir . '/' . basename($filepath);
                if (file_exists($cloudPath)) {
                    unlink($cloudPath);
                }
                echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete backup']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid file path']);
        }
        exit;
    }
}

// Handle download
if (isset($_GET['download'])) {
    $filepath = $_GET['download'];
    $fullPath = $backupDir . '/' . basename($filepath);
    
    // Security check
    $realPath = realpath($fullPath);
    $realBackupDir = realpath($backupDir);
    
    if ($realPath && $realBackupDir && strpos($realPath, $realBackupDir) === 0 && file_exists($fullPath) && is_file($fullPath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    } else {
        header("HTTP/1.0 404 Not Found");
        exit;
    }
}

$localBackups = getBackupFiles($backupDir);
$cloudBackups = getBackupFiles($cloudStorageDir);

// Calculate total backup size
$totalSize = 0;
foreach ($localBackups as $backup) {
    $totalSize += $backup['size'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Manager • Techno Pest Control</title>
    <link rel="icon" href="https://static.wixstatic.com/media/8149e3_4b1ff979b44047f88b69d87b70d6f202~mv2.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 25%, #f0f9ff 50%, #f0fdf4 75%, #ecfdf5 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
            padding: 2rem 0;
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .container-custom {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }
        .backup-header {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(240, 253, 244, 0.4) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.15);
            border: 2px solid rgba(16, 185, 129, 0.2);
        }
        .backup-header h1 {
            color: #1e293b;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .backup-header h1 i {
            color: #10b981;
            font-size: 2rem;
        }
        .backup-stats {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
        }
        .stat-item i {
            color: #10b981;
        }
        .btn-backup {
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            margin-top: 1.5rem;
        }
        .btn-backup:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5);
        }
        .btn-backup:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .backup-table {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.15);
            border: 2px solid rgba(16, 185, 129, 0.2);
            overflow-x: auto;
        }
        .table {
            margin-bottom: 0;
        }
        .table thead th {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            font-weight: 600;
            padding: 1rem;
        }
        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }
        .table tbody tr:hover {
            background: rgba(16, 185, 129, 0.05);
        }
        .btn-action {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            margin: 0 0.25rem;
        }
        .btn-download {
            background: #10b981;
            color: white;
        }
        .btn-delete {
            background: #ef4444;
            color: white;
        }
        .btn-action:hover {
            transform: scale(1.1);
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #10b981;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .back-link:hover {
            color: #059669;
        }
        .badge-type {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-database {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        .badge-files {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <a href="dashboard.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
        
        <div class="backup-header">
            <h1>
                <i class="bi bi-shield-check"></i>
                Backup Manager
            </h1>
            <p style="color: #64748b; margin-bottom: 0;">Manage and monitor your website backups</p>
            <div class="backup-stats">
                <div class="stat-item">
                    <i class="bi bi-hdd"></i>
                    <span>Total Backups: <?= count($localBackups) ?></span>
                </div>
                <div class="stat-item">
                    <i class="bi bi-file-earmark-zip"></i>
                    <span>Total Size: <?= formatBytes($totalSize) ?></span>
                </div>
                <div class="stat-item">
                    <i class="bi bi-clock"></i>
                    <span>Last Backup: <?= !empty($localBackups) ? $localBackups[0]['modified'] : 'Never' ?></span>
                </div>
            </div>
            <button class="btn-backup" id="runBackupBtn" onclick="runBackup()">
                <i class="bi bi-cloud-upload"></i>
                <span>Create New Backup</span>
            </button>
        </div>

        <div class="backup-table">
            <h3 style="margin-bottom: 1.5rem; color: #1e293b;">Backup Files</h3>
            <?php if (empty($localBackups)): ?>
                <div style="text-align: center; padding: 3rem; color: #64748b;">
                    <i class="bi bi-inbox" style="font-size: 4rem; margin-bottom: 1rem; display: block;"></i>
                    <p>No backups found. Click "Create New Backup" to create your first backup.</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Backup Name</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Date Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($localBackups as $backup): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($backup['name']) ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $type = 'files';
                                    if (strpos($backup['name'], 'database') !== false) {
                                        $type = 'database';
                                    }
                                    ?>
                                    <span class="badge-type badge-<?= $type ?>">
                                        <?= ucfirst($type) ?>
                                    </span>
                                </td>
                                <td><?= $backup['size_formatted'] ?></td>
                                <td><?= $backup['modified'] ?></td>
                                <td>
                                    <button class="btn-action btn-download" onclick="downloadBackup('<?= htmlspecialchars($backup['name']) ?>')">
                                        <i class="bi bi-download"></i> Download
                                    </button>
                                    <button class="btn-action btn-delete" onclick="deleteBackup('<?= htmlspecialchars($backup['name']) ?>')">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function runBackup() {
            if (!confirm('This will create a complete backup of your website (database + all files). This may take a few minutes. Continue?')) {
                return;
            }
            
            const btn = document.getElementById('runBackupBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Creating Backup... Please wait';
            
            // Show progress indicator
            const progressDiv = document.createElement('div');
            progressDiv.id = 'backupProgress';
            progressDiv.style.cssText = 'margin-top: 1rem; padding: 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 8px; display: none;';
            progressDiv.innerHTML = '<i class="bi bi-info-circle"></i> <span id="progressText">Starting backup process...</span>';
            btn.parentElement.appendChild(progressDiv);
            progressDiv.style.display = 'block';
            
            const formData = new FormData();
            formData.append('action', 'run_backup');
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'backup_manager.php', true);
            
            // Update progress
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    document.getElementById('progressText').textContent = 'Uploading: ' + Math.round(percentComplete) + '%';
                }
            };
            
            xhr.onload = function() {
                try {
                    const response = JSON.parse(xhr.responseText);
                    progressDiv.style.display = 'none';
                    
                    if (response.success) {
                        document.getElementById('progressText').textContent = 'Backup completed successfully!';
                        progressDiv.style.background = 'rgba(16, 185, 129, 0.2)';
                        progressDiv.style.display = 'block';
                        
                        // Show detailed success message
                        let message = '✓ Backup completed successfully!\n\n';
                        message += response.message || 'All website data has been backed up.';
                        message += '\n\nBackups are saved to:\n';
                        message += '• /backups/ folder\n';
                        message += '• /cloud_storage/backups/ folder\n\n';
                        message += 'You can download them from the Backup Manager.';
                        
                        setTimeout(() => {
                            alert(message);
                            location.reload();
                        }, 500);
                    } else {
                        let message = 'Backup completed with warnings:\n\n';
                        message += response.message || 'The backup process completed but may have encountered issues.';
                        if (response.log) {
                            message += '\n\nLog details:\n' + response.log.substring(0, 500);
                        }
                        message += '\n\nPlease check the backup files to verify.';
                        
                        alert(message);
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        progressDiv.style.background = 'rgba(245, 158, 11, 0.2)';
                        progressDiv.style.display = 'block';
                        document.getElementById('progressText').textContent = response.message || 'Backup completed with warnings';
                    }
                } catch (e) {
                    progressDiv.style.display = 'none';
                    alert('Error parsing response. The backup may have completed. Please refresh the page to check.');
                    location.reload();
                }
            };
            
            xhr.onerror = function() {
                progressDiv.style.display = 'none';
                alert('An error occurred while creating backup. Please try again.');
                btn.disabled = false;
                btn.innerHTML = originalText;
            };
            
            xhr.ontimeout = function() {
                progressDiv.style.display = 'none';
                alert('Backup is taking longer than expected. It may still be processing. Please wait a moment and refresh the page.');
                btn.disabled = false;
                btn.innerHTML = originalText;
            };
            
            xhr.timeout = 600000; // 10 minutes timeout
            
            xhr.send(formData);
        }
        
        function downloadBackup(filename) {
            window.location.href = 'backup_manager.php?download=' + encodeURIComponent(filename);
        }
        
        function deleteBackup(filename) {
            if (!confirm('Are you sure you want to delete this backup?\n\n' + filename)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_backup');
            formData.append('filepath', filename);
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'backup_manager.php', true);
            
            xhr.onload = function() {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    alert('Backup deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            };
            
            xhr.send(formData);
        }
    </script>
</body>
</html>

