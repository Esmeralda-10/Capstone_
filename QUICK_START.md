# Quick Start: Cloud Backup Setup

## 🚀 5-Minute Setup

### Step 1: Choose Your Cloud Provider

**Easiest Option: Dropbox**
1. Go to https://www.dropbox.com/developers/apps
2. Create app → Get access token
3. Copy token

**Recommended: AWS S3**
1. Sign up at https://aws.amazon.com/
2. Create S3 bucket
3. Create IAM user with S3 access
4. Get Access Key & Secret Key

### Step 2: Configure

1. Open `cloud_config.php`
2. Set your provider: `'provider' => 'dropbox'` or `'aws'`
3. Fill in your credentials:
   - **Dropbox**: Paste access token
   - **AWS**: Enter Access Key, Secret Key, Bucket name, Region

### Step 3: Test

Run in browser:
```
http://localhost/capstone/cloud_backup.php?run_backup=1
```

Or via command line:
```bash
cd c:\laragon\www\capstone
php cloud_backup.php
```

### Step 4: Schedule (Windows)

1. Open Task Scheduler
2. Create Basic Task
3. Daily at 2 AM
4. Program: `C:\laragon\bin\php\php-8.x.x\php.exe`
5. Arguments: `C:\laragon\www\capstone\cloud_backup.php`

## 📋 What Gets Backed Up?

- ✅ MySQL Database (compressed)
- ✅ Uploaded files (if enabled)
- ✅ Automatic cleanup (old backups removed)
- ✅ Email notifications (on failure)

## 📁 Files Created

- `cloud_config.php` - Configuration file
- `cloud_backup.php` - Main backup script
- `backup_restore.php` - Restore utility (web interface)
- `backups/` - Local backup storage
- `cloud_setup_guide.md` - Detailed guide

## 🔒 Security

- Never commit `cloud_config.php` to git (already in .gitignore)
- Protect `backup_restore.php` with authentication
- Use strong cloud credentials

## ❓ Need Help?

See `cloud_setup_guide.md` for detailed instructions.

