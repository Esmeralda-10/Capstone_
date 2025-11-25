<?php
/**
 * Data Storage Manager
 * Complete solution to store and backup all your website data
 * 
 * This tool helps you:
 * - Backup entire database
 * - Export data to multiple formats (SQL, CSV, JSON)
 * - Backup uploaded files
 * - Store everything to cloud
 * - View backup history
 */

session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

require_once __DIR__ . '/cloud_config.php';

class DataStorageManager {
    private $config;
    private $pdo;
    private $backupDir;
    
    public function __construct() {
        $this->config = require __DIR__ . '/cloud_config.php';
        $this->backupDir = $this->config['backup']['backup_directory'];
        
        // Create backup directory
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        
        // Database connection
        $db = $this->config['database'];
        $this->pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['dbname']};charset=utf8mb4",
            $db['username'],
            $db['password']
        );
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    /**
     * Get all tables in database
     */
    public function getAllTables() {
        $stmt = $this->pdo->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Get table row count
     */
    public function getTableCount($table) {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM `{$table}`");
        return $stmt->fetchColumn();
    }
    
    /**
     * Get database size
     */
    public function getDatabaseSize() {
        $stmt = $this->pdo->query("
            SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'size_mb'
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()
        ");
        return $stmt->fetchColumn();
    }
    
    /**
     * Export table to CSV
     */
    public function exportTableToCSV($table) {
        $filename = $this->backupDir . "/{$table}_" . date('Y-m-d_H-i-s') . ".csv";
        $file = fopen($filename, 'w');
        
        // Get column headers
        $stmt = $this->pdo->query("DESCRIBE `{$table}`");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        fputcsv($file, $columns);
        
        // Get data
        $stmt = $this->pdo->query("SELECT * FROM `{$table}`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($file, $row);
        }
        
        fclose($file);
        return $filename;
    }
    
    /**
     * Export table to JSON
     */
    public function exportTableToJSON($table) {
        $filename = $this->backupDir . "/{$table}_" . date('Y-m-d_H-i-s') . ".json";
        
        $stmt = $this->pdo->query("SELECT * FROM `{$table}`");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
        return $filename;
    }
    
    /**
     * Export entire database to SQL
     */
    public function exportDatabaseToSQL() {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = $this->backupDir . "/full_database_backup_{$timestamp}.sql";
        
        $db = $this->config['database'];
        $command = sprintf(
            'mysqldump -h %s -u %s %s %s > %s',
            escapeshellarg($db['host']),
            escapeshellarg($db['username']),
            !empty($db['password']) ? '-p' . escapeshellarg($db['password']) : '',
            escapeshellarg($db['dbname']),
            escapeshellarg($filename)
        );
        
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0 && file_exists($filename)) {
            // Compress if enabled
            if ($this->config['backup']['compress']) {
                $compressed = $filename . '.gz';
                $fp_in = fopen($filename, 'rb');
                $fp_out = gzopen($compressed, 'wb9');
                
                while (!feof($fp_in)) {
                    gzwrite($fp_out, fread($fp_in, 8192));
                }
                
                fclose($fp_in);
                gzclose($fp_out);
                unlink($filename);
                
                return $compressed;
            }
            
            return $filename;
        }
        
        return false;
    }
    
    /**
     * Export all tables to CSV
     */
    public function exportAllTablesToCSV() {
        $tables = $this->getAllTables();
        $files = [];
        
        foreach ($tables as $table) {
            $files[] = $this->exportTableToCSV($table);
        }
        
        return $files;
    }
    
    /**
     * Export all tables to JSON
     */
    public function exportAllTablesToJSON() {
        $tables = $this->getAllTables();
        $files = [];
        
        foreach ($tables as $table) {
            $files[] = $this->exportTableToJSON($table);
        }
        
        return $files;
    }
    
    /**
     * Get backup files list
     */
    public function getBackupFiles() {
        $files = [];
        $pattern = $this->backupDir . '/*.{sql,sql.gz,csv,json,tar.gz}';
        $globFiles = glob($pattern, GLOB_BRACE);
        
        foreach ($globFiles as $file) {
            $files[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'size_formatted' => $this->formatBytes(filesize($file)),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'type' => $this->getFileType($file)
            ];
        }
        
        // Sort by date (newest first)
        usort($files, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $files;
    }
    
    /**
     * Get file type
     */
    private function getFileType($file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'gz') {
            $ext = pathinfo(pathinfo($file, PATHINFO_FILENAME), PATHINFO_EXTENSION);
        }
        
        $types = [
            'sql' => 'Database',
            'csv' => 'CSV Export',
            'json' => 'JSON Export',
            'tar' => 'Files Archive'
        ];
        
        return $types[$ext] ?? 'Unknown';
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Upload to cloud storage
     */
    public function uploadToCloud($filepath) {
        require_once __DIR__ . '/cloud_backup.php';
        $backup = new CloudBackup();
        return $backup->uploadToCloud($filepath);
    }
    
    /**
     * Get storage statistics
     */
    public function getStorageStats() {
        $tables = $this->getAllTables();
        $stats = [
            'total_tables' => count($tables),
            'total_rows' => 0,
            'database_size_mb' => $this->getDatabaseSize(),
            'tables' => []
        ];
        
        foreach ($tables as $table) {
            $count = $this->getTableCount($table);
            $stats['total_rows'] += $count;
            $stats['tables'][] = [
                'name' => $table,
                'rows' => $count
            ];
        }
        
        // Get backup files info
        $backupFiles = $this->getBackupFiles();
        $stats['backup_files_count'] = count($backupFiles);
        $stats['backup_files_size'] = array_sum(array_column($backupFiles, 'size'));
        $stats['backup_files_size_formatted'] = $this->formatBytes($stats['backup_files_size']);
        
        return $stats;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $manager = new DataStorageManager();
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'export_sql':
                $file = $manager->exportDatabaseToSQL();
                if ($file) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Database exported successfully',
                        'file' => basename($file)
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Export failed']);
                }
                break;
                
            case 'export_csv':
                $files = $manager->exportAllTablesToCSV();
                echo json_encode([
                    'success' => true,
                    'message' => 'All tables exported to CSV',
                    'files' => array_map('basename', $files),
                    'count' => count($files)
                ]);
                break;
                
            case 'export_json':
                $files = $manager->exportAllTablesToJSON();
                echo json_encode([
                    'success' => true,
                    'message' => 'All tables exported to JSON',
                    'files' => array_map('basename', $files),
                    'count' => count($files)
                ]);
                break;
                
            case 'backup_to_cloud':
                // Run full backup
                require_once __DIR__ . '/cloud_backup.php';
                $backup = new CloudBackup();
                $result = $backup->backup();
                
                echo json_encode([
                    'success' => $result,
                    'message' => $result ? 'Backup uploaded to cloud successfully' : 'Backup failed'
                ]);
                break;
                
            case 'get_stats':
                echo json_encode([
                    'success' => true,
                    'stats' => $manager->getStorageStats()
                ]);
                break;
                
            case 'get_backups':
                echo json_encode([
                    'success' => true,
                    'backups' => $manager->getBackupFiles()
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

// Get initial data
$manager = new DataStorageManager();
$tables = $manager->getAllTables();
$stats = $manager->getStorageStats();
$backups = $manager->getBackupFiles();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Storage Manager</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 1.1em;
        }
        
        .content {
            padding: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            color: #667eea;
            font-size: 0.9em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 2.5em;
            font-weight: bold;
            color: #333;
        }
        
        .section {
            margin-bottom: 40px;
        }
        
        .section h2 {
            color: #667eea;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .btn {
            padding: 15px 25px;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(17, 153, 142, 0.4);
        }
        
        .btn-info {
            background: linear-gradient(135deg, #3494E6 0%, #EC6EAD 100%);
            color: white;
        }
        
        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 148, 230, 0.4);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .tables-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .table-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .table-item strong {
            color: #667eea;
            display: block;
            margin-bottom: 5px;
        }
        
        .backup-list {
            margin-top: 20px;
        }
        
        .backup-item {
            background: #f8f9fa;
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid #667eea;
        }
        
        .backup-info {
            flex: 1;
        }
        
        .backup-info strong {
            color: #667eea;
            display: block;
            margin-bottom: 5px;
            font-size: 1.1em;
        }
        
        .backup-meta {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }
        
        .message.show {
            display: block;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #e0e0e0;
            border-radius: 15px;
            overflow: hidden;
            margin: 20px 0;
            display: none;
        }
        
        .progress-bar.show {
            display: block;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            width: 0%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 Data Storage Manager</h1>
            <p>Store and backup all your website data securely</p>
        </div>
        
        <div class="content">
            <div id="message" class="message"></div>
            <div id="progress" class="progress-bar">
                <div class="progress-fill" id="progress-fill">0%</div>
            </div>
            
            <!-- Statistics -->
            <div class="section">
                <h2>📊 Database Statistics</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Total Tables</h3>
                        <div class="value" id="stat-tables"><?= $stats['total_tables'] ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Total Records</h3>
                        <div class="value" id="stat-rows"><?= number_format($stats['total_rows']) ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Database Size</h3>
                        <div class="value" id="stat-size"><?= $stats['database_size_mb'] ?> MB</div>
                    </div>
                    <div class="stat-card">
                        <h3>Backup Files</h3>
                        <div class="value" id="stat-backups"><?= $stats['backup_files_count'] ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="section">
                <h2>⚡ Quick Actions</h2>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="exportSQL()">
                        💾 Export Database (SQL)
                    </button>
                    <button class="btn btn-info" onclick="exportCSV()">
                        📄 Export All Tables (CSV)
                    </button>
                    <button class="btn btn-info" onclick="exportJSON()">
                        📋 Export All Tables (JSON)
                    </button>
                    <button class="btn btn-success" onclick="backupToCloud()">
                        ☁️ Backup to Cloud
                    </button>
                </div>
            </div>
            
            <!-- Database Tables -->
            <div class="section">
                <h2>🗄️ Database Tables</h2>
                <div class="tables-list">
                    <?php foreach ($stats['tables'] as $table): ?>
                        <div class="table-item">
                            <strong><?= htmlspecialchars($table['name']) ?></strong>
                            <span><?= number_format($table['rows']) ?> records</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Backup Files -->
            <div class="section">
                <h2>💿 Backup Files</h2>
                <button class="btn btn-info" onclick="refreshBackups()" style="margin-bottom: 15px;">
                    🔄 Refresh List
                </button>
                <div id="backup-list" class="backup-list">
                    <?php if (empty($backups)): ?>
                        <p style="color: #666; padding: 20px; text-align: center;">
                            No backup files found. Create a backup to get started!
                        </p>
                    <?php else: ?>
                        <?php foreach ($backups as $backup): ?>
                            <div class="backup-item">
                                <div class="backup-info">
                                    <strong><?= htmlspecialchars($backup['name']) ?></strong>
                                    <div class="backup-meta">
                                        <span>Type: <?= $backup['type'] ?></span> | 
                                        <span>Size: <?= $backup['size_formatted'] ?></span> | 
                                        <span>Date: <?= $backup['date'] ?></span>
                                    </div>
                                </div>
                                <a href="backups/<?= urlencode($backup['name']) ?>" class="btn btn-primary" download>
                                    ⬇️ Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function showMessage(text, type) {
            const msg = document.getElementById('message');
            msg.textContent = text;
            msg.className = 'message show ' + type;
            setTimeout(() => {
                msg.className = 'message';
            }, 5000);
        }
        
        function showProgress(percent) {
            const progress = document.getElementById('progress');
            const fill = document.getElementById('progress-fill');
            progress.classList.add('show');
            fill.style.width = percent + '%';
            fill.textContent = percent + '%';
        }
        
        function hideProgress() {
            const progress = document.getElementById('progress');
            progress.classList.remove('show');
        }
        
        function exportSQL() {
            showProgress(10);
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Exporting...';
            
            fetch('data_storage_manager.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=export_sql'
            })
            .then(r => r.json())
            .then(data => {
                showProgress(100);
                if (data.success) {
                    showMessage('✅ ' + data.message + ': ' + data.file, 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showMessage('❌ ' + data.message, 'error');
                }
                btn.disabled = false;
                btn.innerHTML = '💾 Export Database (SQL)';
                setTimeout(hideProgress, 2000);
            })
            .catch(err => {
                showMessage('❌ Error: ' + err.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '💾 Export Database (SQL)';
                hideProgress();
            });
        }
        
        function exportCSV() {
            showProgress(10);
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Exporting...';
            
            fetch('data_storage_manager.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=export_csv'
            })
            .then(r => r.json())
            .then(data => {
                showProgress(100);
                if (data.success) {
                    showMessage('✅ ' + data.message + ' (' + data.count + ' files created)', 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showMessage('❌ ' + data.message, 'error');
                }
                btn.disabled = false;
                btn.innerHTML = '📄 Export All Tables (CSV)';
                setTimeout(hideProgress, 2000);
            })
            .catch(err => {
                showMessage('❌ Error: ' + err.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '📄 Export All Tables (CSV)';
                hideProgress();
            });
        }
        
        function exportJSON() {
            showProgress(10);
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Exporting...';
            
            fetch('data_storage_manager.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=export_json'
            })
            .then(r => r.json())
            .then(data => {
                showProgress(100);
                if (data.success) {
                    showMessage('✅ ' + data.message + ' (' + data.count + ' files created)', 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showMessage('❌ ' + data.message, 'error');
                }
                btn.disabled = false;
                btn.innerHTML = '📋 Export All Tables (JSON)';
                setTimeout(hideProgress, 2000);
            })
            .catch(err => {
                showMessage('❌ Error: ' + err.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '📋 Export All Tables (JSON)';
                hideProgress();
            });
        }
        
        function backupToCloud() {
            if (!confirm('This will backup your entire database and files to cloud storage. Continue?')) {
                return;
            }
            
            showProgress(10);
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Backing up to cloud...';
            
            fetch('data_storage_manager.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=backup_to_cloud'
            })
            .then(r => r.json())
            .then(data => {
                showProgress(100);
                if (data.success) {
                    showMessage('✅ ' + data.message, 'success');
                } else {
                    showMessage('❌ ' + data.message, 'error');
                }
                btn.disabled = false;
                btn.innerHTML = '☁️ Backup to Cloud';
                setTimeout(hideProgress, 2000);
            })
            .catch(err => {
                showMessage('❌ Error: ' + err.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '☁️ Backup to Cloud';
                hideProgress();
            });
        }
        
        function refreshBackups() {
            fetch('data_storage_manager.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_backups'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const list = document.getElementById('backup-list');
                    if (data.backups.length === 0) {
                        list.innerHTML = '<p style="color: #666; padding: 20px; text-align: center;">No backup files found.</p>';
                    } else {
                        list.innerHTML = data.backups.map(b => `
                            <div class="backup-item">
                                <div class="backup-info">
                                    <strong>${b.name}</strong>
                                    <div class="backup-meta">
                                        <span>Type: ${b.type}</span> | 
                                        <span>Size: ${b.size_formatted}</span> | 
                                        <span>Date: ${b.date}</span>
                                    </div>
                                </div>
                                <a href="backups/${encodeURIComponent(b.name)}" class="btn btn-primary" download>
                                    ⬇️ Download
                                </a>
                            </div>
                        `).join('');
                    }
                    showMessage('✅ Backup list refreshed', 'success');
                }
            });
        }
    </script>
</body>
</html>

