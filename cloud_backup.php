<?php
/**
 * Cloud Backup Script
 * Automatically backs up database and files to cloud storage
 * 
 * Usage:
 * - Run manually: php cloud_backup.php
 * - Schedule with cron: 0 2 * * * php /path/to/cloud_backup.php
 */

require_once __DIR__ . '/cloud_config.php';

class CloudBackup {
    private $config;
    private $backupDir;
    private $logFile;
    private $errors = [];

    public function __construct() {
        $this->config = require __DIR__ . '/cloud_config.php';
        $this->backupDir = $this->config['backup']['backup_directory'];
        $this->logFile = $this->backupDir . '/backup_log.txt';
        
        // Create backup directory if it doesn't exist
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        
        // Check for ZipArchive extension (helpful warning)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && !class_exists('ZipArchive')) {
            $this->log("NOTE: ZipArchive extension not found. Using alternative compression methods.");
            $this->log("To enable ZipArchive in Laragon: php.ini -> uncomment 'extension=zip'");
        }
    }

    /**
     * Main backup function
     */
    public function backup() {
        $this->log("=== Backup started at " . date('Y-m-d H:i:s') . " ===");
        $this->log("Backing up all website data...");
        
        try {
            // Backup database (all tables and data)
            $this->log("Step 1/3: Backing up database...");
            $dbFile = $this->backupDatabase();
            $this->log("✓ Database backup completed");
            
            // Backup all website files if enabled
            $filesArchive = null;
            if ($this->config['backup']['include_files']) {
                $this->log("Step 2/3: Backing up website files...");
                $filesArchive = $this->backupAllFiles();
                $this->log("✓ Files backup completed");
            }
            
            // Copy backups to local cloud storage for easy access
            $this->log("Step 3/3: Copying backups to cloud storage...");
            $this->copyToLocalCloudStorage($dbFile);
            if ($filesArchive) {
                $this->copyToLocalCloudStorage($filesArchive);
            }
            $this->log("✓ Backups copied to cloud storage");
            
            // Upload to cloud (may skip if not configured)
            try {
                $this->uploadToCloud($dbFile);
            } catch (Exception $e) {
                $this->log("WARNING: Cloud upload failed for database backup: " . $e->getMessage());
                $this->log("Backup file saved locally at: " . $dbFile);
            }
            
            if ($filesArchive) {
                try {
                    $this->uploadToCloud($filesArchive);
                } catch (Exception $e) {
                    $this->log("WARNING: Cloud upload failed for files backup: " . $e->getMessage());
                    $this->log("Backup file saved locally at: " . $filesArchive);
                }
            }
            
            // Clean old backups
            $this->cleanOldBackups();
            
            $this->log("=== Backup completed successfully ===");
            $this->sendNotification(true, "Backup completed successfully");
            
            return true;
        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
            $this->errors[] = $e->getMessage();
            $this->sendNotification(false, "Backup failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find mysqldump executable path
     */
    private function findMysqldumpPath() {
        // Common paths for mysqldump on Windows (Laragon, XAMPP, WAMP)
        $commonPaths = [
            'C:\\laragon\\bin\\mysql\\mysql-8.0.30\\bin\\mysqldump.exe',
            'C:\\laragon\\bin\\mysql\\mysql-8.0.24\\bin\\mysqldump.exe',
            'C:\\laragon\\bin\\mysql\\mysql-5.7.30\\bin\\mysqldump.exe',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql8.0.27\\bin\\mysqldump.exe',
            'C:\\wamp\\bin\\mysql\\mysql8.0.27\\bin\\mysqldump.exe',
        ];
        
        // Check if mysqldump is in PATH
        $output = [];
        exec('where mysqldump 2>nul', $output, $returnVar);
        if ($returnVar === 0 && !empty($output)) {
            return trim($output[0]);
        }
        
        // Check common paths
        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        // Fallback to just 'mysqldump' (if in PATH)
        return 'mysqldump';
    }

    /**
     * Backup MySQL database
     */
    private function backupDatabase() {
        $this->log("Backing up database...");
        
        $db = $this->config['database'];
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "database_backup_{$timestamp}.sql";
        $filepath = $this->backupDir . '/' . $filename;
        
        // Try to find mysqldump path (common locations for Laragon/XAMPP/WAMP)
        $mysqldumpPath = $this->findMysqldumpPath();
        
        // Build mysqldump command
        // On Windows, we need to handle the command differently
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: Build command array for better escaping
            $cmdParts = [
                escapeshellarg($mysqldumpPath),
                '-h', escapeshellarg($db['host']),
                '-u', escapeshellarg($db['username'])
            ];
            
            // Add password if provided (no space between -p and password)
            if (!empty($db['password'])) {
                $cmdParts[] = '-p' . escapeshellarg($db['password']);
            }
            
            // Add database name (properly escaped for names with spaces)
            $cmdParts[] = escapeshellarg($db['dbname']);
            
            $command = implode(' ', $cmdParts) . ' > ' . escapeshellarg($filepath) . ' 2>&1';
        } else {
            // Unix/Linux: standard command
            $command = sprintf(
                '%s -h %s -u %s',
                escapeshellarg($mysqldumpPath),
                escapeshellarg($db['host']),
                escapeshellarg($db['username'])
            );
            
            if (!empty($db['password'])) {
                $command .= ' -p' . escapeshellarg($db['password']);
            }
            
            $command .= ' ' . escapeshellarg($db['dbname']);
            $command .= ' > ' . escapeshellarg($filepath) . ' 2>&1';
        }
        
        $this->log("Executing: " . str_replace($db['password'] ?? '', '***', $command));
        
        // Execute command
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: Use cmd /c for proper redirection
            $command = 'cmd /c "' . $command . '"';
        }
        
        exec($command, $output, $returnVar);
        
        // Log output for debugging
        if (!empty($output)) {
            $this->log("Command output: " . implode("\n", $output));
        }
        
        if ($returnVar !== 0 || !file_exists($filepath) || filesize($filepath) == 0) {
            $this->log("mysqldump failed, trying PDO fallback method...");
            // Fallback to PDO method if mysqldump fails
            return $this->backupDatabasePDO();
        }
        
        // Compress if enabled
        if ($this->config['backup']['compress']) {
            $compressedFile = $filepath . '.gz';
            $fp_in = fopen($filepath, 'rb');
            $fp_out = gzopen($compressedFile, 'wb9');
            
            while (!feof($fp_in)) {
                gzwrite($fp_out, fread($fp_in, 8192));
            }
            
            fclose($fp_in);
            gzclose($fp_out);
            unlink($filepath); // Delete uncompressed file
            
            $this->log("Database backup compressed: " . basename($compressedFile));
            return $compressedFile;
        }
        
        $this->log("Database backup created: " . basename($filepath));
        return $filepath;
    }

    /**
     * Backup MySQL database using PDO (fallback method)
     */
    private function backupDatabasePDO() {
        $this->log("Backing up database using PDO method...");
        
        $db = $this->config['database'];
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "database_backup_{$timestamp}.sql";
        $filepath = $this->backupDir . '/' . $filename;
        
        try {
            // Connect to database
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=utf8mb4",
                $db['host'],
                $db['dbname']
            );
            $pdo = new PDO($dsn, $db['username'], $db['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $output = fopen($filepath, 'w');
            if (!$output) {
                throw new Exception("Cannot create backup file: $filepath");
            }
            
            // Write SQL header
            fwrite($output, "-- MySQL Database Backup\n");
            fwrite($output, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($output, "-- Database: {$db['dbname']}\n\n");
            fwrite($output, "SET FOREIGN_KEY_CHECKS=0;\n\n");
            
            // Get all tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                $this->log("Backing up table: $table");
                
                // Get table structure
                $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                fwrite($output, "\n-- Table structure for `$table`\n");
                fwrite($output, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($output, $createTable['Create Table'] . ";\n\n");
                
                // Get table data
                $allRows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                $rowCount = count($allRows);
                
                if ($rowCount > 0) {
                    fwrite($output, "-- Data for table `$table`\n");
                    fwrite($output, "INSERT INTO `$table` VALUES\n");
                    
                    foreach ($allRows as $index => $row) {
                        $values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = $pdo->quote($value);
                            }
                        }
                        
                        fwrite($output, "(" . implode(',', $values) . ")");
                        
                        // Add comma if not last row, semicolon if last
                        if ($index < $rowCount - 1) {
                            fwrite($output, ",\n");
                        } else {
                            fwrite($output, ";\n\n");
                        }
                    }
                } else {
                    fwrite($output, "-- No data in table `$table`\n\n");
                }
            }
            
            fwrite($output, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($output);
            
            $this->log("Database backup created using PDO: " . basename($filepath));
            return $filepath;
            
        } catch (PDOException $e) {
            if (isset($output) && is_resource($output)) {
                fclose($output);
            }
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            throw new Exception("PDO backup failed: " . $e->getMessage());
        }
    }

    /**
     * Backup all website files (directories and important files)
     */
    private function backupAllFiles() {
        $this->log("Backing up all website files and directories...");
        
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "website_files_backup_{$timestamp}.tar.gz";
        $filepath = $this->backupDir . '/' . $filename;
        
        $backupConfig = $this->config['backup'];
        $directories = $backupConfig['directories'] ?? [];
        $files = $backupConfig['files'] ?? [];
        
        // Create temporary directory for backup
        $tempDir = sys_get_temp_dir() . '/backup_' . $timestamp;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $backedUp = false;
        
        // Backup directories
        foreach ($directories as $dir) {
            if (is_dir($dir)) {
                $dirName = basename($dir);
                $this->log("Backing up directory: $dirName");
                
                // Create directory structure in temp folder
                $targetDir = $tempDir . '/' . $dirName;
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                // Copy directory contents
                $this->copyDirectory($dir, $targetDir);
                $backedUp = true;
            } else {
                $this->log("Directory not found, skipping: $dir");
            }
        }
        
        // Backup important files
        if (!empty($files)) {
            $filesDir = $tempDir . '/config_files';
            if (!is_dir($filesDir)) {
                mkdir($filesDir, 0755, true);
            }
            
            foreach ($files as $file) {
                if (file_exists($file)) {
                    $fileName = basename($file);
                    $this->log("Backing up file: $fileName");
                    copy($file, $filesDir . '/' . $fileName);
                    $backedUp = true;
                }
            }
        }
        
        if (!$backedUp) {
            $this->log("No files or directories to backup");
            rmdir($tempDir);
            return null;
        }
        
        // Create tar.gz archive
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: Try ZipArchive first, then fallback to manual zip creation
            $zipFile = $this->backupDir . '/' . str_replace('.tar.gz', '.zip', $filename);
            
            if (class_exists('ZipArchive')) {
                // Use ZipArchive if available
                $zip = new ZipArchive();
                if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                    $this->addDirectoryToZip($tempDir, $zip, '');
                    $zip->close();
                    
                    // Clean up temp directory
                    $this->deleteDirectory($tempDir);
                    
                    $this->log("Website files backup created: " . basename($zipFile));
                    return $zipFile;
                } else {
                    throw new Exception("Failed to create ZIP archive");
                }
            } else {
                // Fallback: Use manual zip creation with exec or create uncompressed archive
                $this->log("ZipArchive not available, using alternative method...");
                
                // Try using Windows built-in compression
                $zipCommand = sprintf(
                    'powershell -Command "Compress-Archive -Path %s\* -DestinationPath %s -Force"',
                    escapeshellarg($tempDir),
                    escapeshellarg($zipFile)
                );
                
                exec($zipCommand, $output, $returnVar);
                
                if ($returnVar === 0 && file_exists($zipFile)) {
                    // Clean up temp directory
                    $this->deleteDirectory($tempDir);
                    $this->log("Website files backup created: " . basename($zipFile));
                    return $zipFile;
                } else {
                    // Last resort: Create uncompressed tar archive or copy files directly
                    $this->log("Compression failed, creating uncompressed archive...");
                    $tarFile = $this->backupDir . '/' . str_replace('.tar.gz', '.tar', $filename);
                    
                    // Use PHP to create a simple tar-like archive
                    $this->createSimpleTar($tempDir, $tarFile);
                    
                    // Clean up temp directory
                    $this->deleteDirectory($tempDir);
                    
                    $this->log("Website files backup created (uncompressed): " . basename($tarFile));
                    return $tarFile;
                }
            }
        } else {
            // Unix/Linux: Use tar
            $command = sprintf(
                'tar -czf %s -C %s .',
                escapeshellarg($filepath),
                escapeshellarg($tempDir)
            );
            
            exec($command, $output, $returnVar);
            
            // Clean up temp directory
            $this->deleteDirectory($tempDir);
            
            if ($returnVar !== 0 || !file_exists($filepath)) {
                throw new Exception("Files backup failed");
            }
            
            $this->log("Website files backup created: " . basename($filepath));
            return $filepath;
        }
    }
    
    /**
     * Copy directory recursively
     */
    private function copyDirectory($source, $destination) {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item, $target);
            }
        }
    }
    
    /**
     * Add directory to ZIP archive
     */
    private function addDirectoryToZip($dir, $zip, $zipPath) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = $dir . '/' . $file;
            $zipFilePath = $zipPath . ($zipPath ? '/' : '') . $file;
            
            if (is_dir($filePath)) {
                $zip->addEmptyDir($zipFilePath);
                $this->addDirectoryToZip($filePath, $zip, $zipFilePath);
            } else {
                $zip->addFile($filePath, $zipFilePath);
            }
        }
    }
    
    /**
     * Delete directory recursively
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $dir . '/' . $file;
            is_dir($filePath) ? $this->deleteDirectory($filePath) : unlink($filePath);
        }
        rmdir($dir);
    }
    
    /**
     * Create simple TAR archive (uncompressed) as fallback
     */
    private function createSimpleTar($sourceDir, $outputFile) {
        $fp = fopen($outputFile, 'wb');
        if (!$fp) {
            throw new Exception("Cannot create archive file");
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath); // Normalize path separators
            
            if ($item->isFile()) {
                $fileSize = filesize($item);
                $fileContent = file_get_contents($item);
                
                // Simple TAR header (512 bytes)
                $header = str_pad($relativePath, 100, "\0");
                $header .= str_pad(sprintf("%07o", $fileSize), 7, "0", STR_PAD_LEFT) . "\0";
                $header .= str_pad("", 8, "\0"); // mtime
                $header .= "        "; // typeflag (regular file)
                $header .= str_pad("", 100, "\0"); // linkname
                $header .= str_pad("", 8, "\0"); // magic
                $header .= str_pad("", 32, "\0"); // uname/gname
                $header .= str_pad("", 16, "\0"); // devmajor/devminor
                $header .= str_pad("", 155, "\0"); // prefix
                $header .= str_pad("", 12, "\0"); // padding
                
                fwrite($fp, $header);
                fwrite($fp, $fileContent);
                
                // Pad to 512-byte boundary
                $padding = 512 - ($fileSize % 512);
                if ($padding < 512) {
                    fwrite($fp, str_repeat("\0", $padding));
                }
            }
        }
        
        // Write two empty blocks to mark end of archive
        fwrite($fp, str_repeat("\0", 1024));
        fclose($fp);
    }

    /**
     * Upload file to cloud storage
     */
    private function uploadToCloud($filepath) {
        $provider = $this->config['provider'];
        
        switch ($provider) {
            case 'aws':
                $this->uploadToAWS($filepath);
                break;
            case 'google':
                $this->uploadToGoogle($filepath);
                break;
            case 'azure':
                $this->uploadToAzure($filepath);
                break;
            case 'dropbox':
                $this->uploadToDropbox($filepath);
                break;
            case 'firebase':
                $this->uploadToFirebase($filepath);
                break;
            case 'backblaze':
                $this->uploadToBackblaze($filepath);
                break;
            default:
                throw new Exception("Unknown cloud provider: {$provider}");
        }
    }

    /**
     * Upload to AWS S3
     */
    private function uploadToAWS($filepath) {
        $this->log("Uploading to AWS S3...");
        
        $aws = $this->config['aws'];
        
        // Check if AWS is enabled
        if (isset($aws['enabled']) && !$aws['enabled']) {
            $this->log("AWS S3 upload is disabled in configuration");
            return;
        }
        
        // Check if credentials are configured
        if (empty($aws['access_key']) || $aws['access_key'] === 'YOUR_AWS_ACCESS_KEY' ||
            empty($aws['secret_key']) || $aws['secret_key'] === 'YOUR_AWS_SECRET_KEY' ||
            empty($aws['bucket']) || $aws['bucket'] === 'your-backup-bucket-name') {
            $this->log("WARNING: AWS S3 credentials not configured. Skipping cloud upload.");
            $this->log("Please configure AWS credentials in cloud_config.php to enable cloud uploads.");
            return;
        }
        
        // Check if AWS SDK is available
        if (!class_exists('Aws\S3\S3Client')) {
            // Fallback to using AWS CLI
            $key = basename($filepath);
            $s3Path = $aws['folder'] . '/' . $key;
            
            // Check if AWS CLI is available
            $awsCliCheck = [];
            exec('aws --version 2>&1', $awsCliCheck, $awsCliReturn);
            if ($awsCliReturn !== 0) {
                $this->log("WARNING: AWS CLI not found. Skipping cloud upload.");
                $this->log("Install AWS CLI or configure AWS SDK to enable cloud uploads.");
                return;
            }
            
            $command = sprintf(
                'aws s3 cp %s s3://%s/%s --region %s',
                escapeshellarg($filepath),
                escapeshellarg($aws['bucket']),
                escapeshellarg($s3Path),
                escapeshellarg($aws['region'])
            );
            
            // Set credentials via environment variables
            putenv('AWS_ACCESS_KEY_ID=' . $aws['access_key']);
            putenv('AWS_SECRET_ACCESS_KEY=' . $aws['secret_key']);
            
            $this->log("Executing AWS CLI command...");
            exec($command, $output, $returnVar);
            
            if ($returnVar !== 0) {
                $errorMsg = "AWS S3 upload failed (exit code: $returnVar)";
                if (!empty($output)) {
                    $errorMsg .= ": " . implode("; ", array_slice($output, -3));
                }
                $this->log("ERROR: " . $errorMsg);
                throw new Exception($errorMsg);
            }
            
            $this->log("Uploaded to S3: {$s3Path}");
        } else {
            // Use AWS SDK
            require_once __DIR__ . '/vendor/autoload.php';
            
            $aws = $this->config['aws'];
            $s3 = new Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $aws['region'],
                'credentials' => [
                    'key' => $aws['access_key'],
                    'secret' => $aws['secret_key'],
                ],
            ]);
            
            $key = $aws['folder'] . '/' . basename($filepath);
            $s3->putObject([
                'Bucket' => $aws['bucket'],
                'Key' => $key,
                'SourceFile' => $filepath,
            ]);
            
            $this->log("Uploaded to S3: {$key}");
        }
    }

    /**
     * Upload to Google Cloud Storage
     */
    private function uploadToGoogle($filepath) {
        $this->log("Uploading to Google Cloud Storage...");
        // Implementation for Google Cloud Storage
        // Requires Google Cloud Storage PHP library
        throw new Exception("Google Cloud Storage upload not yet implemented");
    }

    /**
     * Upload to Azure Blob Storage
     */
    private function uploadToAzure($filepath) {
        $this->log("Uploading to Azure Blob Storage...");
        // Implementation for Azure Blob Storage
        // Requires Azure Storage PHP library
        throw new Exception("Azure Blob Storage upload not yet implemented");
    }

    /**
     * Upload to Dropbox
     */
    private function uploadToDropbox($filepath) {
        $this->log("Uploading to Dropbox...");
        
        $dropbox = $this->config['dropbox'];
        $remotePath = $dropbox['folder'] . '/' . basename($filepath);
        
        $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $dropbox['access_token'],
            'Content-Type: application/octet-stream',
            'Dropbox-API-Arg: ' . json_encode([
                'path' => $remotePath,
                'mode' => 'add',
                'autorename' => true,
            ]),
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filepath));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Dropbox upload failed: " . $response);
        }
        
        $this->log("Uploaded to Dropbox: {$remotePath}");
    }

    /**
     * Upload to Firebase Storage
     */
    private function uploadToFirebase($filepath) {
        $this->log("Uploading to Firebase Storage...");
        
        $firebase = $this->config['firebase'];
        
        // Check if Firebase is enabled
        if (isset($firebase['enabled']) && !$firebase['enabled']) {
            $this->log("Firebase Storage upload is disabled in configuration");
            return;
        }
        
        // Check if credentials are configured
        if (empty($firebase['project_id']) || $firebase['project_id'] === 'YOUR_FIREBASE_PROJECT_ID' ||
            empty($firebase['storage_bucket']) || $firebase['storage_bucket'] === 'YOUR_FIREBASE_PROJECT_ID.appspot.com' ||
            !file_exists($firebase['service_account_path'])) {
            $this->log("WARNING: Firebase credentials not configured. Skipping cloud upload.");
            $this->log("Please configure Firebase credentials in cloud_config.php to enable cloud uploads.");
            return;
        }
        
        // Check if Firebase Admin SDK is available
        if (class_exists('Google\Cloud\Storage\StorageClient')) {
            // Use Firebase Admin SDK
            try {
                require_once __DIR__ . '/vendor/autoload.php';
                
                $storage = new \Google\Cloud\Storage\StorageClient([
                    'keyFilePath' => $firebase['service_account_path'],
                    'projectId' => $firebase['project_id'],
                ]);
                
                $bucket = $storage->bucket($firebase['storage_bucket']);
                $remotePath = ($firebase['folder'] ? $firebase['folder'] . '/' : '') . basename($filepath);
                
                $options = [
                    'name' => $remotePath,
                    'metadata' => [
                        'contentType' => mime_content_type($filepath),
                    ],
                ];
                
                $bucket->upload(fopen($filepath, 'r'), $options);
                $this->log("Uploaded to Firebase Storage: {$remotePath}");
                
            } catch (Exception $e) {
                $this->log("Firebase SDK upload failed, trying REST API method...");
                $this->uploadToFirebaseREST($filepath);
            }
        } else {
            // Fallback to REST API
            $this->uploadToFirebaseREST($filepath);
        }
    }

    /**
     * Upload to Firebase Storage using REST API (fallback method)
     */
    private function uploadToFirebaseREST($filepath) {
        $firebase = $this->config['firebase'];
        
        // Load service account JSON
        $serviceAccount = json_decode(file_get_contents($firebase['service_account_path']), true);
        if (!$serviceAccount) {
            throw new Exception("Invalid Firebase service account JSON file");
        }
        
        // Get access token using service account
        $token = $this->getFirebaseAccessToken($serviceAccount);
        if (!$token) {
            throw new Exception("Failed to get Firebase access token");
        }
        
        $remotePath = ($firebase['folder'] ? $firebase['folder'] . '/' : '') . basename($filepath);
        $bucket = $firebase['storage_bucket'];
        $fileContent = file_get_contents($filepath);
        $fileSize = filesize($filepath);
        $mimeType = mime_content_type($filepath);
        
        // Firebase Storage REST API endpoint
        $url = sprintf(
            'https://storage.googleapis.com/upload/storage/v1/b/%s/o?uploadType=media&name=%s',
            urlencode($bucket),
            urlencode($remotePath)
        );
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . $fileSize,
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $errorMsg = "Firebase Storage upload failed (HTTP $httpCode)";
            if ($error) {
                $errorMsg .= ": " . $error;
            }
            if ($response) {
                $errorData = json_decode($response, true);
                if (isset($errorData['error']['message'])) {
                    $errorMsg .= ": " . $errorData['error']['message'];
                }
            }
            throw new Exception($errorMsg);
        }
        
        $this->log("Uploaded to Firebase Storage: {$remotePath}");
    }

    /**
     * Get Firebase access token using service account
     */
    private function getFirebaseAccessToken($serviceAccount) {
        $now = time();
        $jwt = $this->createFirebaseJWT($serviceAccount, $now);
        
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    /**
     * Create JWT for Firebase service account authentication
     */
    private function createFirebaseJWT($serviceAccount, $now) {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];
        
        $claim = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ];
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $claimEncoded = $this->base64UrlEncode(json_encode($claim));
        
        $signature = '';
        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        openssl_sign($headerEncoded . '.' . $claimEncoded, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKey);
        
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return $headerEncoded . '.' . $claimEncoded . '.' . $signatureEncoded;
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Upload to Backblaze B2 (S3-compatible)
     */
    private function uploadToBackblaze($filepath) {
        $this->log("Uploading to Backblaze B2...");
        
        $b2 = $this->config['backblaze'];
        
        // Check if Backblaze is enabled
        if (isset($b2['enabled']) && !$b2['enabled']) {
            $this->log("Backblaze B2 upload is disabled in configuration");
            return;
        }
        
        // Check if credentials are configured
        if (empty($b2['access_key']) || $b2['access_key'] === 'YOUR_BACKBLAZE_KEY_ID' ||
            empty($b2['secret_key']) || $b2['secret_key'] === 'YOUR_BACKBLAZE_APPLICATION_KEY' ||
            empty($b2['bucket']) || $b2['bucket'] === 'your-bucket-name') {
            $this->log("WARNING: Backblaze B2 credentials not configured. Skipping cloud upload.");
            $this->log("Please configure Backblaze credentials in cloud_config.php to enable cloud uploads.");
            return;
        }
        
        // Backblaze B2 uses S3-compatible API
        $key = basename($filepath);
        $s3Path = ($b2['folder'] ? $b2['folder'] . '/' : '') . $key;
        $endpoint = $b2['endpoint'] ?? 'https://s3.' . $b2['region'] . '.backblazeb2.com';
        
        $fileSize = filesize($filepath);
        $mimeType = mime_content_type($filepath);
        
        // S3-compatible PUT request
        $url = $endpoint . '/' . $b2['bucket'] . '/' . $s3Path;
        
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $stringToSign = "PUT\n\n{$mimeType}\n{$date}\n/{$b2['bucket']}/{$s3Path}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $b2['secret_key'], true));
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: AWS ' . $b2['access_key'] . ':' . $signature,
            'Date: ' . $date,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . $fileSize,
        ]);
        
        $fileHandle = fopen($filepath, 'r');
        curl_setopt($ch, CURLOPT_INFILE, $fileHandle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($fileHandle) fclose($fileHandle);
        
        if ($httpCode !== 200 && $httpCode !== 204) {
            $errorMsg = "Backblaze B2 upload failed (HTTP $httpCode)";
            if ($error) {
                $errorMsg .= ": " . $error;
            }
            if ($response) {
                $errorData = @simplexml_load_string($response);
                if ($errorData && isset($errorData->Message)) {
                    $errorMsg .= ": " . (string)$errorData->Message;
                } else {
                    $errorMsg .= ": " . substr($response, 0, 200);
                }
            }
            throw new Exception($errorMsg);
        }
        
        $this->log("Uploaded to Backblaze B2: {$s3Path}");
    }

    /**
     * Copy backup to local cloud storage and ensure all data is stored
     */
    private function copyToLocalCloudStorage($filepath) {
        $localCloudStorage = __DIR__ . '/cloud_storage/backups';
        
        // Create directory if it doesn't exist
        if (!is_dir($localCloudStorage)) {
            mkdir($localCloudStorage, 0755, true);
        }
        
        $destination = $localCloudStorage . '/' . basename($filepath);
        
        if (copy($filepath, $destination)) {
            $this->log("Backup copied to local cloud storage: " . basename($filepath));
            
            // Also ensure all website data directories are synced
            $this->syncAllWebsiteData();
        } else {
            $this->log("WARNING: Failed to copy backup to local cloud storage");
        }
    }
    
    /**
     * Sync all website data to cloud storage - ensures nothing is left behind
     */
    private function syncAllWebsiteData() {
        $this->log("Syncing all website data to cloud storage...");
        
        $storageRoot = __DIR__ . '/cloud_storage';
        $syncDirs = [
            __DIR__ . '/uploads' => $storageRoot . '/uploads',
            __DIR__ . '/images' => $storageRoot . '/images',
            __DIR__ . '/documents' => $storageRoot . '/documents',
            __DIR__ . '/backups' => $storageRoot . '/backups',
        ];
        
        foreach ($syncDirs as $source => $target) {
            if (is_dir($source)) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                $this->syncDirectoryRecursive($source, $target);
                $this->log("Synced: " . basename($source));
            }
        }
        
        // Sync important config files
        $configFiles = [
            __DIR__ . '/cloud_config.php' => $storageRoot . '/config/cloud_config.php',
        ];
        
        foreach ($configFiles as $source => $target) {
            if (file_exists($source)) {
                $targetDir = dirname($target);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                if (!file_exists($target) || filemtime($source) > filemtime($target)) {
                    copy($source, $target);
                }
            }
        }
        
        $this->log("All website data synced to cloud storage");
    }
    
    /**
     * Recursively sync directory
     */
    private function syncDirectoryRecursive($source, $target) {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                // Copy file if it doesn't exist or source is newer
                if (!file_exists($targetPath) || filemtime($item) > filemtime($targetPath)) {
                    @copy($item, $targetPath);
                }
            }
        }
    }

    /**
     * Clean old backups (local and cloud)
     */
    private function cleanOldBackups() {
        $this->log("Cleaning old backups...");
        
        $retentionDays = $this->config['backup']['retention_days'];
        $cutoffDate = date('Y-m-d', strtotime("-{$retentionDays} days"));
        
        // Clean local backups
        $files = glob($this->backupDir . '/*.{sql,sql.gz,tar.gz}', GLOB_BRACE);
        foreach ($files as $file) {
            if (filemtime($file) < strtotime($cutoffDate)) {
                unlink($file);
                $this->log("Deleted old backup: " . basename($file));
            }
        }
        
        // Clean cloud backups (provider-specific)
        // This would need to be implemented per provider
    }

    /**
     * Send email notification
     */
    private function sendNotification($success, $message) {
        if (!$this->config['notifications']['enabled']) {
            return;
        }
        
        if ($success && !$this->config['notifications']['on_success']) {
            return;
        }
        
        if (!$success && !$this->config['notifications']['on_failure']) {
            return;
        }
        
        $email = $this->config['notifications']['email'];
        $subject = $success ? "Backup Successful" : "Backup Failed";
        $body = "Backup Status: " . $message . "\n\n";
        $body .= "Time: " . date('Y-m-d H:i:s') . "\n";
        
        if (!empty($this->errors)) {
            $body .= "\nErrors:\n" . implode("\n", $this->errors);
        }
        
        mail($email, $subject, $body);
    }

    /**
     * Log message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        // Only echo if running in CLI or direct execution
        if (php_sapi_name() === 'cli' || isset($_GET['run_backup'])) {
            echo $logMessage;
        }
    }
    
    /**
     * Get last backup log
     */
    public function getLastLog($lines = 50) {
        if (!file_exists($this->logFile)) {
            return [];
        }
        $logContent = file($this->logFile);
        return array_slice($logContent, -$lines);
    }
}

// Run backup if executed directly
if (php_sapi_name() === 'cli' || isset($_GET['run_backup'])) {
    $backup = new CloudBackup();
    $backup->backup();
}

