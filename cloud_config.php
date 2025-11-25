<?php
/**
 * Cloud Backup Configuration
 * Configure your cloud storage credentials here
 */

return [
    // Database Configuration
    'database' => [
        'host' => 'localhost',
        'dbname' => 'pest control',
        'username' => 'root',
        'password' => '',
    ],

    // Cloud Storage Provider (aws, google, azure, dropbox, firebase, backblaze)
    // FREE OPTIONS RECOMMENDED:
    // 1. Firebase Storage - 5GB free storage, 1GB/day downloads (BEST FOR SMALL BACKUPS)
    // 2. Backblaze B2 - 10GB free storage, 1GB/day downloads (BEST FOR BACKUPS - S3-compatible)
    // 3. Google Drive - 15GB free (requires API setup)
    // 4. Dropbox - 2GB free
    // 5. OneDrive - 5GB free
    'provider' => 'backblaze', // Recommended: 'backblaze' (10GB free) or 'firebase' (5GB free)

    // AWS S3 Configuration
    // To get your AWS credentials:
    // 1. Go to https://console.aws.amazon.com/
    // 2. Sign in or create an AWS account
    // 3. Go to IAM (Identity and Access Management)
    // 4. Click "Users" -> "Create user" or select existing user
    // 5. Attach policy "AmazonS3FullAccess" (or create custom policy for your bucket only)
    // 6. Go to "Security credentials" tab -> "Create access key"
    // 7. Download or copy the Access Key ID and Secret Access Key
    // 8. Create an S3 bucket: Go to S3 service -> "Create bucket"
    // 9. Replace the values below with your actual credentials
    'aws' => [
        'enabled' => true, // Set to false to disable AWS uploads
        'access_key' => 'YOUR_AWS_ACCESS_KEY', // Replace with your AWS Access Key ID (e.g., 'AKIAIOSFODNN7EXAMPLE')
        'secret_key' => 'YOUR_AWS_SECRET_KEY', // Replace with your AWS Secret Access Key (e.g., 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY')
        'region' => 'us-east-1', // Change to your bucket's region (e.g., 'us-west-2', 'eu-west-1', 'ap-southeast-1')
        'bucket' => 'your-backup-bucket-name', // Replace with your S3 bucket name (e.g., 'my-pest-control-backups')
        'folder' => 'pest-control-backups', // Folder path in bucket (optional, can be empty string '')
    ],

    // Google Cloud Storage Configuration
    'google' => [
        'enabled' => false,
        'project_id' => 'YOUR_PROJECT_ID',
        'key_file' => 'path/to/service-account-key.json',
        'bucket' => 'your-backup-bucket-name',
        'folder' => 'pest-control-backups',
    ],

    // Azure Blob Storage Configuration
    'azure' => [
        'enabled' => false,
        'account_name' => 'YOUR_ACCOUNT_NAME',
        'account_key' => 'YOUR_ACCOUNT_KEY',
        'container' => 'backups',
        'folder' => 'pest-control-backups',
    ],

    // Dropbox Configuration
    'dropbox' => [
        'enabled' => false,
        'access_token' => 'YOUR_DROPBOX_ACCESS_TOKEN',
        'folder' => '/backups/pest-control',
    ],

    // Firebase Storage Configuration
    // FREE TIER: 5GB storage, 1GB/day downloads
    // To set up Firebase:
    // 1. Go to https://console.firebase.google.com/
    // 2. Create a new project or select existing one
    // 3. Go to Project Settings (gear icon) -> Service Accounts
    // 4. Click "Generate new private key" to download service account JSON file
    // 5. Save the JSON file in a secure location (e.g., __DIR__ . '/firebase-service-account.json')
    // 6. Go to Storage in Firebase Console -> Get Started -> Create bucket
    // 7. Update the values below with your Firebase project details
    'firebase' => [
        'enabled' => true, // Set to false to disable Firebase uploads
        'project_id' => 'YOUR_FIREBASE_PROJECT_ID', // Your Firebase project ID (found in project settings)
        'service_account_path' => __DIR__ . '/firebase-service-account.json', // Path to your service account JSON file
        'storage_bucket' => 'YOUR_FIREBASE_PROJECT_ID.appspot.com', // Your Firebase Storage bucket (usually project-id.appspot.com)
        'folder' => 'pest-control-backups', // Folder path in Firebase Storage (optional)
    ],

    // Backblaze B2 Configuration (RECOMMENDED - BEST FREE OPTION FOR BACKUPS)
    // FREE TIER: 10GB storage, 1GB/day downloads (DOUBLE Firebase's free storage!)
    // S3-compatible API - works with AWS S3 code
    // To set up Backblaze B2:
    // 1. Go to https://www.backblaze.com/b2/sign-up.html
    // 2. Create a free account (no credit card required)
    // 3. Go to B2 Cloud Storage -> Buckets -> Create a Bucket
    // 4. Go to App Keys -> Add a New Application Key
    // 5. Select "Read and Write" permissions
    // 6. Copy the Key ID and Application Key
    // 7. Update the values below
    // Note: Use 'us-west-000' as region for free tier (or your preferred region)
    'backblaze' => [
        'enabled' => true, // Set to false to disable Backblaze uploads
        'access_key' => 'YOUR_BACKBLAZE_KEY_ID', // Your Backblaze Application Key ID
        'secret_key' => 'YOUR_BACKBLAZE_APPLICATION_KEY', // Your Backblaze Application Key
        'region' => 'us-west-000', // Backblaze region (us-west-000, us-west-001, eu-central-003, etc.)
        'bucket' => 'your-bucket-name', // Your B2 bucket name
        'folder' => 'pest-control-backups', // Folder path in bucket (optional)
        'endpoint' => 'https://s3.us-west-000.backblazeb2.com', // B2 S3-compatible endpoint (change region if needed)
    ],

    // Backup Settings
    'backup' => [
        'retention_days' => 30, // Keep backups for 30 days
        'compress' => true, // Compress backups (gzip)
        'include_files' => true, // Backup uploaded files
        'backup_directory' => __DIR__ . '/backups', // Local backup directory
        // Directories to backup (all website data)
        'directories' => [
            __DIR__ . '/uploads',           // User uploaded files
            __DIR__ . '/images',            // Website images
            __DIR__ . '/documents',         // Documents
            __DIR__ . '/cloud_storage',      // Local cloud storage
            // Add more directories as needed
        ],
        // Files to backup (important config files)
        'files' => [
            __DIR__ . '/cloud_config.php',  // Backup configuration
            // Add other important config files here
        ],
        // Automatic backup schedule (cron format: minute hour day month weekday)
        'auto_backup' => [
            'enabled' => true,              // Enable automatic backups
            'schedule' => '0 2 * * *',     // Daily at 2:00 AM (change as needed)
            // Examples:
            // '0 2 * * *' - Daily at 2:00 AM
            // '0 */6 * * *' - Every 6 hours
            // '0 0 * * 0' - Weekly on Sunday at midnight
        ],
    ],

    // Email Notifications
    'notifications' => [
        'enabled' => true,
        'email' => 'admin@example.com', // Email to send backup notifications
        'on_success' => false, // Send email on successful backup
        'on_failure' => true, // Send email on backup failure
    ],
];

