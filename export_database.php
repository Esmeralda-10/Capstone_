<?php
/**
 * Database Export Script for Laragon
 * Run this file in your browser: http://localhost/capstone/export_database.php
 */

// Database configuration
$host = "localhost";
$dbname = "pest control";
$username = "root";
$password = "";

// Output file
$backupFile = __DIR__ . '/backup_' . date('Y-m-d_His') . '.sql';

try {
    // Method 1: Try using mysqldump command (most reliable)
    $mysqlPath = '';
    
    // Common Laragon MySQL paths
    $possiblePaths = [
        'C:\\laragon\\bin\\mysql\\mysql-8.0.30\\bin\\mysqldump.exe',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.31\\bin\\mysqldump.exe',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.32\\bin\\mysqldump.exe',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.33\\bin\\mysqldump.exe',
        'C:\\laragon\\bin\\mysql\\mysql-5.7\\bin\\mysqldump.exe',
        'mysqldump' // If in PATH
    ];
    
    foreach ($possiblePaths as $path) {
        if ($path === 'mysqldump' || file_exists($path)) {
            $mysqlPath = $path;
            break;
        }
    }
    
    if ($mysqlPath) {
        // Escape database name for command line (spaces need quotes)
        $dbnameEscaped = escapeshellarg($dbname);
        $backupFileEscaped = escapeshellarg($backupFile);
        
        // Build command
        if ($password === '') {
            $command = "\"$mysqlPath\" -u $username --password= $dbnameEscaped > $backupFileEscaped 2>&1";
        } else {
            $passwordEscaped = escapeshellarg($password);
            $command = "\"$mysqlPath\" -u $username -p$passwordEscaped $dbnameEscaped > $backupFileEscaped 2>&1";
        }
        
        // Execute command
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
            $success = true;
            $method = 'mysqldump';
        } else {
            $error = "mysqldump failed. Output: " . implode("\n", $output);
            $success = false;
        }
    } else {
        $error = "mysqldump.exe not found. Trying PHP method...";
        $success = false;
    }
    
    // Method 2: Fallback to PHP-based export
    if (!$success) {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $output = "-- Database Export\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Database: $dbname\n\n";
        $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $output .= "SET AUTOCOMMIT = 0;\n";
        $output .= "START TRANSACTION;\n";
        $output .= "SET time_zone = \"+00:00\";\n\n";
        
        // Get all tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($tables as $table) {
            $output .= "\n-- Table structure for `$table`\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            
            $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $output .= $createTable['Create Table'] . ";\n\n";
            
            // Get table data
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                $output .= "-- Data for table `$table`\n";
                $output .= "INSERT INTO `$table` VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = 'NULL';
                        } else {
                            $rowValues[] = $pdo->quote($value);
                        }
                    }
                    $values[] = '(' . implode(',', $rowValues) . ')';
                }
                $output .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $output .= "COMMIT;\n";
        
        file_put_contents($backupFile, $output);
        $success = true;
        $method = 'PHP';
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $success = false;
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
    $success = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Export</title>
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
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .success {
            color: #28a745;
            background: #d4edda;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .error {
            color: #dc3545;
            background: #f8d7da;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Database Export Tool</h1>
        
        <?php if ($success): ?>
            <div class="success">
                <h3>✓ Export Successful!</h3>
                <p><strong>Method used:</strong> <?php echo htmlspecialchars($method); ?></p>
                <p><strong>File saved to:</strong> <code><?php echo htmlspecialchars($backupFile); ?></code></p>
                <p><strong>File size:</strong> <?php echo number_format(filesize($backupFile) / 1024, 2); ?> KB</p>
                <p><a href="<?php echo basename($backupFile); ?>" download>Download Backup File</a></p>
            </div>
        <?php else: ?>
            <div class="error">
                <h3>✗ Export Failed</h3>
                <p><?php echo htmlspecialchars($error ?? 'Unknown error occurred'); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="info">
            <h3>Alternative Export Methods:</h3>
            <ol>
                <li><strong>phpMyAdmin:</strong> Go to <a href="http://localhost/phpmyadmin" target="_blank">http://localhost/phpmyadmin</a></li>
                <li><strong>Command Line:</strong> Use mysqldump from Laragon MySQL bin folder</li>
                <li><strong>Laragon Tool:</strong> Click "Database" button in Laragon toolbar</li>
            </ol>
        </div>
        
        <p><a href="export_database_guide.md">View Detailed Export Guide</a></p>
    </div>
</body>
</html>

