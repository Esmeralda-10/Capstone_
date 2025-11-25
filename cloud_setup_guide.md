# Cloud Backup Setup Guide

This guide will help you set up automated cloud backups for your website's database and files.

## Overview

The cloud backup system automatically:
- Backs up your MySQL database
- Backs up uploaded files (if enabled)
- Compresses backups to save space
- Uploads to cloud storage (AWS S3, Google Cloud, Azure, or Dropbox)
- Cleans old backups automatically
- Sends email notifications

## Step 1: Choose a Cloud Provider

### Option A: AWS S3 (Recommended)

1. **Create an AWS Account**
   - Go to https://aws.amazon.com/
   - Sign up for an account

2. **Create an S3 Bucket**
   - Log into AWS Console
   - Go to S3 service
   - Click "Create bucket"
   - Choose a unique bucket name (e.g., `pest-control-backups-2024`)
   - Select a region close to you
   - Click "Create bucket"

3. **Create IAM User for Backups**
   - Go to IAM service in AWS Console
   - Click "Users" → "Add users"
   - Username: `backup-user`
   - Select "Programmatic access"
   - Click "Next: Permissions"
   - Click "Attach existing policies directly"
   - Search and select "AmazonS3FullAccess" (or create a custom policy with only PutObject permission)
   - Click "Next" → "Create user"
   - **IMPORTANT**: Save the Access Key ID and Secret Access Key

4. **Install AWS CLI (Optional but Recommended)**
   ```bash
   # Windows (using Chocolatey)
   choco install awscli
   
   # Or download from: https://aws.amazon.com/cli/
   ```

5. **Configure AWS CLI**
   ```bash
   aws configure
   # Enter your Access Key ID
   # Enter your Secret Access Key
   # Enter your region (e.g., us-east-1)
   # Enter output format (json)
   ```

### Option B: Dropbox (Easiest)

1. **Create Dropbox App**
   - Go to https://www.dropbox.com/developers/apps
   - Click "Create app"
   - Choose "Scoped access"
   - Choose "Full Dropbox"
   - Name your app (e.g., "Pest Control Backups")
   - Click "Create app"

2. **Generate Access Token**
   - In your app settings, go to "Permissions"
   - Enable "files.content.write"
   - Go to "Settings" → "Generate access token"
   - Copy the access token

### Option C: Google Cloud Storage

1. **Create Google Cloud Project**
   - Go to https://console.cloud.google.com/
   - Create a new project

2. **Create Storage Bucket**
   - Go to Cloud Storage
   - Create a bucket

3. **Create Service Account**
   - Go to IAM & Admin → Service Accounts
   - Create service account
   - Download JSON key file

### Option D: Azure Blob Storage

1. **Create Azure Account**
   - Go to https://azure.microsoft.com/
   - Sign up for account

2. **Create Storage Account**
   - Create a storage account
   - Create a container named "backups"
   - Get account name and access key

## Step 2: Configure Cloud Backup

1. **Edit `cloud_config.php`**
   - Open `cloud_config.php` in your project
   - Update database credentials if needed
   - Choose your provider: `'provider' => 'aws'` (or 'dropbox', 'google', 'azure')
   - Fill in your cloud credentials:
     - For AWS: Access Key, Secret Key, Bucket name, Region
     - For Dropbox: Access Token
     - For Google: Project ID, Key file path
     - For Azure: Account name, Account key

2. **Update Backup Settings**
   ```php
   'backup' => [
       'retention_days' => 30, // Keep backups for 30 days
       'compress' => true, // Compress backups
       'include_files' => true, // Backup uploaded files
       'files_directory' => __DIR__ . '/uploads', // Your uploads directory
   ],
   ```

3. **Configure Email Notifications**
   ```php
   'notifications' => [
       'enabled' => true,
       'email' => 'your-email@example.com',
       'on_success' => false,
       'on_failure' => true,
   ],
   ```

## Step 3: Test the Backup

### Manual Test

1. **Via Browser**
   - Navigate to: `http://localhost/capstone/cloud_backup.php?run_backup=1`
   - Check the output

2. **Via Command Line**
   ```bash
   cd c:\laragon\www\capstone
   php cloud_backup.php
   ```

3. **Check Results**
   - Check the `backups/` directory for local backup files
   - Check your cloud storage to verify uploads
   - Check `backups/backup_log.txt` for logs

## Step 4: Schedule Automatic Backups

### Windows Task Scheduler

1. **Open Task Scheduler**
   - Press `Win + R`, type `taskschd.msc`, press Enter

2. **Create Basic Task**
   - Click "Create Basic Task"
   - Name: "Pest Control Backup"
   - Trigger: Daily at 2:00 AM
   - Action: Start a program
   - Program: `C:\laragon\bin\php\php-8.x.x\php.exe` (your PHP path)
   - Arguments: `C:\laragon\www\capstone\cloud_backup.php`
   - Start in: `C:\laragon\www\capstone`

3. **Save and Test**
   - Right-click the task → Run
   - Check if backup executes

### Using Cron (if using WSL or Linux)

Add to crontab:
```bash
crontab -e
```

Add this line (runs daily at 2 AM):
```
0 2 * * * /usr/bin/php /path/to/capstone/cloud_backup.php >> /path/to/capstone/backups/cron.log 2>&1
```

## Step 5: Restore from Backup

### Restore Database

1. **Download backup from cloud**
2. **If compressed, extract:**
   ```bash
   gunzip database_backup_2024-01-15_02-00-00.sql.gz
   ```

3. **Restore to MySQL:**
   ```bash
   mysql -u root -p "pest control" < database_backup_2024-01-15_02-00-00.sql
   ```

### Restore Files

1. **Download files backup from cloud**
2. **Extract:**
   ```bash
   tar -xzf files_backup_2024-01-15_02-00-00.tar.gz -C /path/to/uploads/
   ```

## Security Best Practices

1. **Protect Configuration File**
   - Never commit `cloud_config.php` to version control
   - Add to `.gitignore`:
     ```
     cloud_config.php
     backups/
     ```

2. **Secure Cloud Credentials**
   - Use IAM roles with minimal permissions
   - Rotate access keys regularly
   - Never share credentials

3. **Backup Encryption**
   - Consider encrypting backups before upload
   - Use cloud provider's encryption features

4. **Access Control**
   - Restrict access to `cloud_backup.php` via `.htaccess`:
     ```apache
     <Files "cloud_backup.php">
         Require ip 127.0.0.1
         # Or use authentication
         AuthType Basic
         AuthName "Backup Access"
         AuthUserFile /path/to/.htpasswd
         Require valid-user
     </Files>
     ```

## Troubleshooting

### Backup Fails

1. **Check PHP Error Log**
   - Look for PHP errors in your error log

2. **Check Backup Log**
   - Review `backups/backup_log.txt`

3. **Verify Credentials**
   - Double-check cloud credentials in `cloud_config.php`

4. **Check Permissions**
   - Ensure PHP can write to `backups/` directory
   - Ensure PHP can read database and files

5. **Test Database Connection**
   ```php
   php -r "require 'cloud_config.php'; \$c = require 'cloud_config.php'; \$pdo = new PDO('mysql:host='.\$c['database']['host'].';dbname='.\$c['database']['dbname'], \$c['database']['username'], \$c['database']['password']); echo 'Connected!';"
   ```

### Upload Fails

1. **Check Internet Connection**
2. **Verify Cloud Credentials**
3. **Check Cloud Storage Quota**
4. **Verify Bucket/Container Exists**

## Advanced: Using AWS SDK (Optional)

For better error handling and features, install AWS SDK:

```bash
composer require aws/aws-sdk-php
```

The script will automatically use the SDK if available.

## Support

For issues or questions:
- Check backup logs in `backups/backup_log.txt`
- Review cloud provider documentation
- Check PHP error logs

