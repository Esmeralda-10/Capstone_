<?php
/**
 * Quick Backup Script
 * One-click backup of all your data
 * 
 * Usage: 
 * - Browser: http://localhost/capstone/quick_backup.php
 * - Command line: php quick_backup.php
 */

session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    if (php_sapi_name() !== 'cli') {
        header("Location: admin_login.php");
        exit();
    }
}

require_once __DIR__ . '/cloud_config.php';

$config = require __DIR__ . '/cloud_config.php';
$db = $config['database'];
$backupDir = $config['backup']['backup_directory'];

// Create backup directory
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Start output buffering for browser mode
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    ob_start();
}

function output($message) {
    global $isCli;
    if ($isCli) {
        echo $message . "\n";
    } else {
        echo $message . "\n";
    }
}

output("=== Starting Quick Backup ===");
output("Time: " . date('Y-m-d H:i:s'));
output("");

// Step 1: Backup Database
output("Step 1: Backing up database...");
$timestamp = date('Y-m-d_H-i-s');
$sqlFile = $backupDir . "/quick_backup_{$timestamp}.sql";

$command = sprintf(
    'mysqldump -h %s -u %s %s %s > %s',
    escapeshellarg($db['host']),
    escapeshellarg($db['username']),
    !empty($db['password']) ? '-p' . escapeshellarg($db['password']) : '',
    escapeshellarg($db['dbname']),
    escapeshellarg($sqlFile)
);

exec($command, $output, $returnVar);

if ($returnVar === 0 && file_exists($sqlFile)) {
    $size = filesize($sqlFile);
    output("✓ Database backed up: " . basename($sqlFile) . " (" . round($size/1024/1024, 2) . " MB)");
    
    // Compress if enabled
    if ($config['backup']['compress']) {
        output("Compressing backup...");
        $compressed = $sqlFile . '.gz';
        $fp_in = fopen($sqlFile, 'rb');
        $fp_out = gzopen($compressed, 'wb9');
        
        while (!feof($fp_in)) {
            gzwrite($fp_out, fread($fp_in, 8192));
        }
        
        fclose($fp_in);
        gzclose($fp_out);
        unlink($sqlFile);
        
        $size = filesize($compressed);
        output("✓ Compressed: " . basename($compressed) . " (" . round($size/1024/1024, 2) . " MB)");
        $sqlFile = $compressed;
    }
} else {
    output("✗ Database backup failed!");
    if (!$isCli) {
        $output = ob_get_clean();
        echo "<!DOCTYPE html><html><head><title>Backup Failed</title></head><body><h1>Backup Failed</h1><pre>" . htmlspecialchars($output) . "</pre></body></html>";
    }
    exit(1);
}

// Step 2: Backup Files (if enabled)
if ($config['backup']['include_files'] && is_dir($config['backup']['files_directory'])) {
    output("");
    output("Step 2: Backing up files...");
    $filesDir = $config['backup']['files_directory'];
    $filesArchive = $backupDir . "/files_backup_{$timestamp}.tar.gz";
    
    $command = sprintf(
        'tar -czf %s -C %s .',
        escapeshellarg($filesArchive),
        escapeshellarg($filesDir)
    );
    
    exec($command, $output, $returnVar);
    
    if ($returnVar === 0 && file_exists($filesArchive)) {
        $size = filesize($filesArchive);
        output("✓ Files backed up: " . basename($filesArchive) . " (" . round($size/1024/1024, 2) . " MB)");
    } else {
        output("✗ Files backup failed!");
    }
}

// Step 3: Upload to Cloud (if configured)
$cloudConfigured = false;
if ($config['provider'] === 'aws' && $config['aws']['access_key'] !== 'YOUR_AWS_ACCESS_KEY') {
    $cloudConfigured = true;
} elseif ($config['provider'] === 'dropbox' && $config['dropbox']['access_token'] !== 'YOUR_DROPBOX_ACCESS_TOKEN') {
    $cloudConfigured = true;
}

if ($cloudConfigured) {
    output("");
    output("Step 3: Uploading to cloud...");
    require_once __DIR__ . '/cloud_backup.php';
    $backup = new CloudBackup();
    
    try {
        $backup->uploadToCloud($sqlFile);
        output("✓ Uploaded to cloud: " . basename($sqlFile));
        
        if (isset($filesArchive) && file_exists($filesArchive)) {
            $backup->uploadToCloud($filesArchive);
            output("✓ Uploaded to cloud: " . basename($filesArchive));
        }
    } catch (Exception $e) {
        output("⚠ Cloud upload failed: " . $e->getMessage());
        output("  (Backup files saved locally in backups/ folder)");
    }
} else {
    output("");
    output("Step 3: Cloud not configured (backup saved locally only)");
    output("  To enable cloud backup, configure cloud_config.php");
}

// Summary
output("");
output("=== Backup Complete ===");
output("Backup location: " . $backupDir);
output("Files created:");
output("  - " . basename($sqlFile));
if (isset($filesArchive) && file_exists($filesArchive)) {
    output("  - " . basename($filesArchive));
}
$totalSize = filesize($sqlFile) + (isset($filesArchive) && file_exists($filesArchive) ? filesize($filesArchive) : 0);
output("");
output("Total backup size: " . round($totalSize / 1024 / 1024, 2) . " MB");
output("");
output("✓ All your data has been stored successfully!");

// If running from browser, show HTML output
if (!$isCli) {
    $output = ob_get_clean();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Quick Backup Complete</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 800px;
                margin: 50px auto;
                padding: 20px;
                background: #f5f5f5;
            }
            .container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .success {
                background: #d4edda;
                color: #155724;
                padding: 20px;
                border-radius: 5px;
                margin: 20px 0;
            }
            pre {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                overflow-x: auto;
            }
            .btn {
                display: inline-block;
                padding: 10px 20px;
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                margin-top: 20px;
                margin-right: 10px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>✅ Backup Complete!</h1>
            <div class="success">
                <strong>All your data has been stored successfully!</strong>
            </div>
            <pre><?= htmlspecialchars($output) ?></pre>
            <a href="data_storage_manager.php" class="btn">View All Backups</a>
            <a href="dashboard.php" class="btn">Back to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

