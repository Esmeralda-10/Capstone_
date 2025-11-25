<?php
/**
 * Cloud Storage - Local File Management System
 * Manage files stored on your server
 */

session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

// Storage configuration
$storageRoot = __DIR__ . '/cloud_storage';
$maxFileSize = 1024 * 1024 * 1024; // 1GB - increased limit
// Allow ALL file types - no restrictions
$allowedExtensions = []; // Empty array means all file types are allowed

// Create storage directory structure if it doesn't exist
if (!is_dir($storageRoot)) {
    mkdir($storageRoot, 0755, true);
}

// Create default folders
$defaultFolders = ['backups', 'documents', 'images', 'uploads', 'database', 'config', 'other'];
foreach ($defaultFolders as $folder) {
    $folderPath = $storageRoot . '/' . $folder;
    if (!is_dir($folderPath)) {
        mkdir($folderPath, 0755, true);
    }
}

// Auto-sync important website directories to cloud storage
function autoSyncDirectories() {
    $storageRoot = __DIR__ . '/cloud_storage';
    $syncDirs = [
        __DIR__ . '/uploads' => $storageRoot . '/uploads',
        __DIR__ . '/images' => $storageRoot . '/images',
        __DIR__ . '/documents' => $storageRoot . '/documents',
        __DIR__ . '/backups' => $storageRoot . '/backups',
    ];
    
    foreach ($syncDirs as $source => $target) {
        if (is_dir($source) && is_dir($target)) {
            syncDirectory($source, $target);
        }
    }
}

function syncDirectory($source, $target) {
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
            // Only copy if file doesn't exist or source is newer
            if (!file_exists($targetPath) || filemtime($item) > filemtime($targetPath)) {
                copy($item, $targetPath);
            }
        }
    }
}

// Run auto-sync in background (only once per session to avoid performance issues)
if (!isset($_SESSION['cloud_storage_synced'])) {
    @autoSyncDirectories(); // Suppress errors if directories don't exist
    $_SESSION['cloud_storage_synced'] = true;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'upload') {
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            exit;
        }
        
        $file = $_FILES['file'];
        $folder = $_POST['folder'] ?? 'other';
        $folderPath = $storageRoot . '/' . $folder;
        
        // Validate folder - allow any folder name (sanitized)
        $folder = preg_replace('/[^a-zA-Z0-9_-]/', '', $folder);
        if (empty($folder)) {
            $folder = 'other';
        }
        $folderPath = $storageRoot . '/' . $folder;
        
        // Create folder if it doesn't exist
        if (!is_dir($folderPath)) {
            mkdir($folderPath, 0755, true);
        }
        
        // Validate file size
        if ($file['size'] > $maxFileSize) {
            echo json_encode(['success' => false, 'message' => 'File size exceeds ' . formatBytes($maxFileSize) . ' limit']);
            exit;
        }
        
        // Validate extension (only if restrictions are set)
        if (!empty($allowedExtensions)) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions)) {
                echo json_encode(['success' => false, 'message' => 'File type not allowed']);
                exit;
            }
        }
        
        // Generate unique filename
        $filename = time() . '_' . basename($file['name']);
        $filepath = $folderPath . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            echo json_encode([
                'success' => true,
                'message' => 'File uploaded successfully',
                'filename' => $filename,
                'original_name' => $file['name'],
                'size' => $file['size'],
                'folder' => $folder
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'delete') {
        $filepath = $_POST['filepath'] ?? '';
        $fullPath = $storageRoot . '/' . $filepath;
        
        // Security check - ensure file is within storage root
        $realPath = realpath($fullPath);
        $realRoot = realpath($storageRoot);
        
        if ($realPath && $realRoot && strpos($realPath, $realRoot) === 0) {
            if (file_exists($fullPath) && is_file($fullPath)) {
                if (unlink($fullPath)) {
                    echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid file path']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'sync') {
        // Manual sync of all website data
        autoSyncDirectories();
        
        // Also sync backups
        $backupDir = __DIR__ . '/backups';
        $cloudBackupDir = $storageRoot . '/backups';
        if (is_dir($backupDir) && is_dir($cloudBackupDir)) {
            syncDirectory($backupDir, $cloudBackupDir);
        }
        
        echo json_encode(['success' => true, 'message' => 'All data synced successfully']);
        exit;
    }
    
    if ($_POST['action'] === 'create_folder') {
        $folderName = $_POST['folder_name'] ?? '';
        $parentFolder = $_POST['parent_folder'] ?? '';
        
        if (empty($folderName)) {
            echo json_encode(['success' => false, 'message' => 'Folder name is required']);
            exit;
        }
        
        // Sanitize folder name
        $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '', $folderName);
        if (empty($folderName)) {
            echo json_encode(['success' => false, 'message' => 'Invalid folder name']);
            exit;
        }
        
        $folderPath = $storageRoot;
        if ($parentFolder) {
            $folderPath .= '/' . $parentFolder;
        }
        $folderPath .= '/' . $folderName;
        
        // Security check
        $realPath = realpath(dirname($folderPath));
        $realRoot = realpath($storageRoot);
        
        if ($realPath && $realRoot && strpos($realPath, $realRoot) === 0) {
            if (!is_dir($folderPath)) {
                if (mkdir($folderPath, 0755, true)) {
                    echo json_encode(['success' => true, 'message' => 'Folder created successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to create folder']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Folder already exists']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid folder path']);
        }
        exit;
    }
}

// Handle file download
if (isset($_GET['download'])) {
    $filepath = $_GET['download'];
    $fullPath = $storageRoot . '/' . $filepath;
    
    // Security check
    $realPath = realpath($fullPath);
    $realRoot = realpath($storageRoot);
    
    if ($realPath && $realRoot && strpos($realPath, $realRoot) === 0 && file_exists($fullPath) && is_file($fullPath)) {
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

// Get files and folders
function getStorageContents($dir, $baseDir) {
    $contents = ['files' => [], 'folders' => []];
    
    if (!is_dir($dir)) {
        return $contents;
    }
    
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $itemPath = $dir . '/' . $item;
        $relativePath = str_replace($baseDir . '/', '', $itemPath);
        
        if (is_dir($itemPath)) {
            $contents['folders'][] = [
                'name' => $item,
                'path' => $relativePath,
                'size' => getFolderSize($itemPath),
                'modified' => date('Y-m-d H:i:s', filemtime($itemPath))
            ];
        } else {
            $contents['files'][] = [
                'name' => $item,
                'path' => $relativePath,
                'size' => filesize($itemPath),
                'size_formatted' => formatBytes(filesize($itemPath)),
                'modified' => date('Y-m-d H:i:s', filemtime($itemPath)),
                'extension' => strtolower(pathinfo($item, PATHINFO_EXTENSION))
            ];
        }
    }
    
    // Sort folders and files
    usort($contents['folders'], function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
    usort($contents['files'], function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
    return $contents;
}

function getFolderSize($dir) {
    $size = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    }
    return $size;
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Get current folder
$currentFolder = $_GET['folder'] ?? '';
$currentPath = $storageRoot;
if ($currentFolder) {
    $currentPath = $storageRoot . '/' . $currentFolder;
    // Security check
    $realPath = realpath($currentPath);
    $realRoot = realpath($storageRoot);
    if (!$realPath || !$realRoot || strpos($realPath, $realRoot) !== 0) {
        $currentPath = $storageRoot;
        $currentFolder = '';
    }
}

$storageContents = getStorageContents($currentPath, $storageRoot);
$totalSize = getFolderSize($storageRoot);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloud Storage • Techno Pest Control</title>
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
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .storage-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }
        .storage-header {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(240, 253, 244, 0.4) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.15);
            border: 2px solid rgba(16, 185, 129, 0.2);
        }
        .storage-header h1 {
            color: #1e293b;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .storage-header h1 i {
            color: #10b981;
            font-size: 2rem;
        }
        .storage-stats {
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
        .storage-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }
        .btn-upload, .btn-folder {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-upload {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }
        .btn-upload:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5);
        }
        .btn-folder {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        .btn-folder:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.5);
        }
        .breadcrumb-nav {
            background: rgba(255, 255, 255, 0.8);
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .breadcrumb-item {
            color: #64748b;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .breadcrumb-item:hover {
            color: #10b981;
        }
        .breadcrumb-item.active {
            color: #1e293b;
            font-weight: 600;
        }
        .storage-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.15);
            border: 2px solid rgba(16, 185, 129, 0.2);
        }
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        .file-item, .folder-item {
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(16, 185, 129, 0.1);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .file-item:hover, .folder-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.2);
            border-color: #10b981;
        }
        .file-icon, .folder-icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }
        .folder-icon {
            color: #3b82f6;
        }
        .file-icon {
            color: #10b981;
        }
        .file-name, .folder-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            word-break: break-word;
            font-size: 0.9rem;
        }
        .file-size, .folder-size {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        .file-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin-top: 0.5rem;
        }
        .btn-action {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s ease;
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
        .upload-area {
            border: 2px dashed #10b981;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            background: rgba(16, 185, 129, 0.05);
            margin-bottom: 2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .upload-area:hover {
            background: rgba(16, 185, 129, 0.1);
            border-color: #059669;
        }
        .upload-area.dragover {
            background: rgba(16, 185, 129, 0.2);
            border-color: #059669;
        }
        .modal-content {
            border-radius: 16px;
            border: none;
        }
        .modal-header {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-radius: 16px 16px 0 0;
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
    </style>
</head>
<body>
    <div class="storage-container">
        <a href="dashboard.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
        
        <div class="storage-header">
            <h1>
                <i class="bi bi-cloud"></i>
                Cloud Storage
            </h1>
            <p style="color: #64748b; margin-bottom: 0;">Manage your files stored on the server</p>
            <div class="storage-stats">
                <div class="stat-item">
                    <i class="bi bi-hdd"></i>
                    <span>Total Storage: <?= formatBytes($totalSize) ?></span>
                </div>
                <div class="stat-item">
                    <i class="bi bi-folder"></i>
                    <span>Folders: <?= count($storageContents['folders']) ?></span>
                </div>
                <div class="stat-item">
                    <i class="bi bi-file-earmark"></i>
                    <span>Files: <?= count($storageContents['files']) ?></span>
                </div>
            </div>
            <div class="storage-actions">
                <button class="btn-upload" onclick="document.getElementById('fileInput').click()">
                    <i class="bi bi-cloud-upload"></i> Upload File(s)
                </button>
                <button class="btn-folder" onclick="showCreateFolderModal()">
                    <i class="bi bi-folder-plus"></i> Create Folder
                </button>
                <button class="btn-folder" onclick="syncAllData()" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="bi bi-arrow-repeat"></i> Sync All Data
                </button>
                <input type="file" id="fileInput" style="display: none;" multiple onchange="uploadFiles(this.files)">
            </div>
            <div style="margin-top: 1rem;">
                <input type="text" id="searchInput" class="form-control" placeholder="Search files and folders..." style="max-width: 400px;" onkeyup="searchFiles(this.value)">
            </div>
        </div>

        <div class="breadcrumb-nav">
            <a href="cloud_storage.php" class="breadcrumb-item">
                <i class="bi bi-house"></i> Home
            </a>
            <?php if ($currentFolder): ?>
                <i class="bi bi-chevron-right" style="color: #94a3b8;"></i>
                <span class="breadcrumb-item active"><?= htmlspecialchars($currentFolder) ?></span>
            <?php endif; ?>
        </div>

        <div class="storage-content">
            <?php if (empty($storageContents['folders']) && empty($storageContents['files'])): ?>
                <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                    <i class="bi bi-cloud-upload" style="font-size: 4rem; color: #10b981; margin-bottom: 1rem;"></i>
                    <h3>No files yet</h3>
                    <p>Click here or use the upload button to add files</p>
                </div>
            <?php else: ?>
                <div class="file-grid">
                    <?php foreach ($storageContents['folders'] as $folder): ?>
                        <div class="folder-item" onclick="openFolder('<?= htmlspecialchars($folder['path']) ?>')">
                            <div class="folder-icon">
                                <i class="bi bi-folder-fill"></i>
                            </div>
                            <div class="folder-name"><?= htmlspecialchars($folder['name']) ?></div>
                            <div class="folder-size"><?= formatBytes($folder['size']) ?></div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php foreach ($storageContents['files'] as $file): ?>
                        <div class="file-item">
                            <div class="file-icon">
                                <?php
                                $icon = 'bi-file-earmark';
                                if (in_array($file['extension'], ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'bi-image';
                                elseif (in_array($file['extension'], ['pdf'])) $icon = 'bi-file-pdf';
                                elseif (in_array($file['extension'], ['doc', 'docx'])) $icon = 'bi-file-word';
                                elseif (in_array($file['extension'], ['xls', 'xlsx'])) $icon = 'bi-file-excel';
                                elseif (in_array($file['extension'], ['zip', 'rar', 'gz', 'tar'])) $icon = 'bi-file-zip';
                                ?>
                                <i class="bi <?= $icon ?>-fill"></i>
                            </div>
                            <div class="file-name"><?= htmlspecialchars($file['name']) ?></div>
                            <div class="file-size"><?= $file['size_formatted'] ?></div>
                            <div class="file-actions">
                                <button class="btn-action btn-download" onclick="downloadFile('<?= htmlspecialchars($file['path']) ?>')">
                                    <i class="bi bi-download"></i>
                                </button>
                                <button class="btn-action btn-delete" onclick="deleteFile('<?= htmlspecialchars($file['path']) ?>', '<?= htmlspecialchars($file['name']) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create Folder Modal -->
    <div class="modal fade" id="createFolderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Folder</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="form-control" id="folderName" placeholder="Enter folder name">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="createFolder()">Create</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function uploadFile(file) {
            if (!file) return;
            uploadFiles([file]);
        }
        
        function uploadFiles(files) {
            if (!files || files.length === 0) return;
            
            let uploaded = 0;
            let failed = 0;
            const total = files.length;
            
            Array.from(files).forEach((file, index) => {
                const formData = new FormData();
                formData.append('action', 'upload');
                formData.append('file', file);
                formData.append('folder', '<?= $currentFolder ?>');
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'cloud_storage.php', true);
                
                xhr.onload = function() {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        uploaded++;
                    } else {
                        failed++;
                        console.error('Failed to upload:', file.name, response.message);
                    }
                    
                    if (uploaded + failed === total) {
                        if (failed === 0) {
                            alert('All ' + total + ' file(s) uploaded successfully!');
                        } else {
                            alert('Uploaded: ' + uploaded + ', Failed: ' + failed);
                        }
                        location.reload();
                    }
                };
                
                xhr.onerror = function() {
                    failed++;
                    if (uploaded + failed === total) {
                        alert('Uploaded: ' + uploaded + ', Failed: ' + failed);
                        location.reload();
                    }
                };
                
                xhr.send(formData);
            });
        }
        
        function syncAllData() {
            if (!confirm('This will sync all website data to cloud storage. Continue?')) return;
            
            const formData = new FormData();
            formData.append('action', 'sync');
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'cloud_storage.php', true);
            
            xhr.onload = function() {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    alert('All data synced successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            };
            
            xhr.send(formData);
        }
        
        function searchFiles(query) {
            const items = document.querySelectorAll('.file-item, .folder-item');
            const searchTerm = query.toLowerCase();
            
            items.forEach(item => {
                const name = item.querySelector('.file-name, .folder-name')?.textContent.toLowerCase() || '';
                if (name.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        function downloadFile(filepath) {
            window.location.href = 'cloud_storage.php?download=' + encodeURIComponent(filepath);
        }
        
        function deleteFile(filepath, filename) {
            if (!confirm('Are you sure you want to delete "' + filename + '"?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('filepath', filepath);
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'cloud_storage.php', true);
            
            xhr.onload = function() {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    alert('File deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            };
            
            xhr.send(formData);
        }
        
        function openFolder(folderPath) {
            window.location.href = 'cloud_storage.php?folder=' + encodeURIComponent(folderPath);
        }
        
        function showCreateFolderModal() {
            const modal = new bootstrap.Modal(document.getElementById('createFolderModal'));
            modal.show();
        }
        
        function createFolder() {
            const folderName = document.getElementById('folderName').value.trim();
            if (!folderName) {
                alert('Please enter a folder name');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'create_folder');
            formData.append('folder_name', folderName);
            formData.append('parent_folder', '<?= $currentFolder ?>');
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'cloud_storage.php', true);
            
            xhr.onload = function() {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createFolderModal')).hide();
                    document.getElementById('folderName').value = '';
                    alert('Folder created successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            };
            
            xhr.send(formData);
        }
        
        // Drag and drop
        const uploadArea = document.querySelector('.upload-area');
        if (uploadArea) {
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('dragover');
            });
            
            uploadArea.addEventListener('dragleave', () => {
                uploadArea.classList.remove('dragover');
            });
            
            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    uploadFile(files[0]);
                }
            });
        }
    </script>
</body>
</html>

