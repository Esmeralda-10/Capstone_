<?php
/**
 * Backup Restore Utility
 * Restore database and files from cloud backups
 * 
 * SECURITY: Protect this file with authentication!
 */

// Simple authentication (replace with your own authentication)
session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die("Access denied. Admin login required.");
}

require_once __DIR__ . '/cloud_config.php';

class BackupRestore {
    private $config;
    private $backupDir;

    public function __construct() {
        $this->config = require __DIR__ . '/cloud_config.php';
        $this->backupDir = $this->config['backup']['backup_directory'];
    }

    /**
     * List available backups from cloud
     */
    public function listBackups() {
        $provider = $this->config['provider'];
        
        switch ($provider) {
            case 'aws':
                return $this->listAWSBackups();
            case 'dropbox':
                return $this->listDropboxBackups();
            default:
                return ['error' => 'Provider not supported for listing'];
        }
    }

    /**
     * Download backup from cloud
     */
    public function downloadBackup($backupName) {
        $provider = $this->config['provider'];
        $localPath = $this->backupDir . '/' . $backupName;
        
        switch ($provider) {
            case 'aws':
                return $this->downloadFromAWS($backupName, $localPath);
            case 'dropbox':
                return $this->downloadFromDropbox($backupName, $localPath);
            default:
                return false;
        }
    }

    /**
     * Restore database from backup file
     */
    public function restoreDatabase($backupFile) {
        $db = $this->config['database'];
        $filepath = $this->backupDir . '/' . $backupFile;
        
        // Check if file is compressed
        if (pathinfo($filepath, PATHINFO_EXTENSION) === 'gz') {
            // Decompress
            $uncompressed = str_replace('.gz', '', $filepath);
            $fp_in = gzopen($filepath, 'rb');
            $fp_out = fopen($uncompressed, 'wb');
            
            while (!gzeof($fp_in)) {
                fwrite($fp_out, gzread($fp_in, 8192));
            }
            
            gzclose($fp_in);
            fclose($fp_out);
            $filepath = $uncompressed;
        }
        
        // Restore database
        $command = sprintf(
            'mysql -h %s -u %s %s %s < %s',
            escapeshellarg($db['host']),
            escapeshellarg($db['username']),
            !empty($db['password']) ? '-p' . escapeshellarg($db['password']) : '',
            escapeshellarg($db['dbname']),
            escapeshellarg($filepath)
        );
        
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0) {
            return true;
        }
        
        return false;
    }

    /**
     * Restore files from backup archive
     */
    public function restoreFiles($backupFile) {
        $filesDir = $this->config['backup']['files_directory'];
        $filepath = $this->backupDir . '/' . $backupFile;
        
        if (!is_dir($filesDir)) {
            mkdir($filesDir, 0755, true);
        }
        
        // Extract tar.gz
        $command = sprintf(
            'tar -xzf %s -C %s',
            escapeshellarg($filepath),
            escapeshellarg($filesDir)
        );
        
        exec($command, $output, $returnVar);
        
        return $returnVar === 0;
    }

    private function listAWSBackups() {
        // Implementation for listing AWS S3 backups
        // This would require AWS SDK or CLI
        return ['error' => 'AWS listing not implemented. Use AWS Console to view backups.'];
    }

    private function listDropboxBackups() {
        $dropbox = $this->config['dropbox'];
        
        $ch = curl_init('https://api.dropboxapi.com/2/files/list_folder');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $dropbox['access_token'],
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['path' => $dropbox['folder']]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['entries'] ?? [];
        }
        
        return [];
    }

    private function downloadFromAWS($backupName, $localPath) {
        $aws = $this->config['aws'];
        $s3Path = $aws['folder'] . '/' . $backupName;
        
        $command = sprintf(
            'aws s3 cp s3://%s/%s %s --region %s',
            escapeshellarg($aws['bucket']),
            escapeshellarg($s3Path),
            escapeshellarg($localPath),
            escapeshellarg($aws['region'])
        );
        
        putenv('AWS_ACCESS_KEY_ID=' . $aws['access_key']);
        putenv('AWS_SECRET_ACCESS_KEY=' . $aws['secret_key']);
        
        exec($command, $output, $returnVar);
        
        return $returnVar === 0;
    }

    private function downloadFromDropbox($backupName, $localPath) {
        $dropbox = $this->config['dropbox'];
        $remotePath = $dropbox['folder'] . '/' . $backupName;
        
        $ch = curl_init('https://content.dropboxapi.com/2/files/download');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $dropbox['access_token'],
            'Dropbox-API-Arg: ' . json_encode(['path' => $remotePath]),
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            file_put_contents($localPath, $response);
            return true;
        }
        
        return false;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $restore = new BackupRestore();
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'list':
            echo json_encode($restore->listBackups());
            break;
            
        case 'download':
            $backupName = $_POST['backup_name'] ?? '';
            if ($restore->downloadBackup($backupName)) {
                echo json_encode(['success' => true, 'message' => 'Backup downloaded']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Download failed']);
            }
            break;
            
        case 'restore_db':
            $backupFile = $_POST['backup_file'] ?? '';
            if ($restore->restoreDatabase($backupFile)) {
                echo json_encode(['success' => true, 'message' => 'Database restored']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Restore failed']);
            }
            break;
            
        case 'restore_files':
            $backupFile = $_POST['backup_file'] ?? '';
            if ($restore->restoreFiles($backupFile)) {
                echo json_encode(['success' => true, 'message' => 'Files restored']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Restore failed']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Restore</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .backup-list {
            margin: 20px 0;
        }
        .backup-item {
            background: #f8f9fa;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        button {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background: #45a049;
        }
        button.danger {
            background: #f44336;
        }
        button.danger:hover {
            background: #da190b;
        }
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .success {
            background: #d4edda;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Backup Restore Utility</h1>
        
        <div class="warning">
            <strong>⚠️ Warning:</strong> Restoring a backup will overwrite your current database/files. 
            Make sure to create a backup before restoring!
        </div>
        
        <div id="message"></div>
        
        <h2>Available Backups</h2>
        <button onclick="loadBackups()">Refresh Backup List</button>
        <div id="backup-list" class="backup-list"></div>
        
        <h2>Local Backups</h2>
        <div id="local-backups"></div>
    </div>

    <script>
        function showMessage(text, type) {
            const msgDiv = document.getElementById('message');
            msgDiv.className = 'message ' + type;
            msgDiv.textContent = text;
            setTimeout(() => msgDiv.textContent = '', 5000);
        }

        function loadBackups() {
            fetch('backup_restore.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=list'
            })
            .then(r => r.json())
            .then(data => {
                const listDiv = document.getElementById('backup-list');
                if (data.error) {
                    listDiv.innerHTML = '<p>' + data.error + '</p>';
                } else {
                    listDiv.innerHTML = data.map(b => `
                        <div class="backup-item">
                            <span>${b.name}</span>
                            <div>
                                <button onclick="downloadBackup('${b.name}')">Download</button>
                                ${b.name.includes('database') ? 
                                    `<button class="danger" onclick="restoreDatabase('${b.name}')">Restore DB</button>` : 
                                    `<button class="danger" onclick="restoreFiles('${b.name}')">Restore Files</button>`
                                }
                            </div>
                        </div>
                    `).join('');
                }
            });
        }

        function downloadBackup(name) {
            if (!confirm('Download ' + name + '?')) return;
            
            const formData = new FormData();
            formData.append('action', 'download');
            formData.append('backup_name', name);
            
            fetch('backup_restore.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    loadLocalBackups();
                } else {
                    showMessage(data.message, 'error');
                }
            });
        }

        function restoreDatabase(file) {
            if (!confirm('⚠️ WARNING: This will overwrite your current database! Continue?')) return;
            
            const formData = new FormData();
            formData.append('action', 'restore_db');
            formData.append('backup_file', file);
            
            fetch('backup_restore.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message, 'error');
                }
            });
        }

        function restoreFiles(file) {
            if (!confirm('⚠️ WARNING: This will overwrite your current files! Continue?')) return;
            
            const formData = new FormData();
            formData.append('action', 'restore_files');
            formData.append('backup_file', file);
            
            fetch('backup_restore.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message, 'error');
                }
            });
        }

        function loadLocalBackups() {
            // This would need a PHP endpoint to list local backups
            // For now, just show a message
            document.getElementById('local-backups').innerHTML = 
                '<p>Local backups are stored in: backups/ directory</p>';
        }

        // Load backups on page load
        loadBackups();
        loadLocalBackups();
    </script>
</body>
</html>

