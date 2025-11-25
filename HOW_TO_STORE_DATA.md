# Complete Guide: How to Store All Your Website Data

This guide will help you store and backup all your website data using multiple methods.

## 📋 What Data Gets Stored?

Your website contains:
- **Database**: All tables (inventory, service_bookings, users, services, audit_logs, etc.)
- **Files**: Uploaded images, documents, customer files
- **Configuration**: Settings and preferences

## 🚀 Method 1: Using Data Storage Manager (Easiest)

### Step 1: Access the Manager
1. Open your browser
2. Go to: `http://localhost/capstone/data_storage_manager.php`
3. You'll see a dashboard with all your data statistics

### Step 2: Choose Your Storage Method

#### Option A: Export to SQL (Recommended for Full Backup)
- Click **"💾 Export Database (SQL)"**
- Creates a complete database backup file
- Can be restored to any MySQL database
- File saved in `backups/` folder

#### Option B: Export to CSV (For Spreadsheet Analysis)
- Click **"📄 Export All Tables (CSV)"**
- Each table exported as separate CSV file
- Can open in Excel, Google Sheets
- Useful for data analysis

#### Option C: Export to JSON (For API/Programming)
- Click **"📋 Export All Tables (JSON)"**
- Each table exported as JSON file
- Easy to import into other systems
- Good for data migration

#### Option D: Backup to Cloud (Automatic Storage)
- Click **"☁️ Backup to Cloud"**
- Automatically backs up database + files
- Uploads to your configured cloud storage
- Most secure option

### Step 3: Download Your Backups
- All backup files appear in the "Backup Files" section
- Click **"⬇️ Download"** to save locally
- Files are stored in `backups/` directory

## ☁️ Method 2: Cloud Storage Setup

### Quick Setup (5 minutes)

1. **Choose Cloud Provider**
   - **Dropbox** (Easiest): Get free access token
   - **AWS S3** (Recommended): More features, better for production
   - **Google Cloud**: Good integration with Google services
   - **Azure**: If you use Microsoft services

2. **Configure `cloud_config.php`**
   ```php
   'provider' => 'dropbox', // or 'aws', 'google', 'azure'
   ```

3. **Add Credentials**
   - For Dropbox: Paste access token
   - For AWS: Add Access Key, Secret Key, Bucket name

4. **Run Backup**
   - Use Data Storage Manager → "Backup to Cloud"
   - Or run: `php cloud_backup.php`

## 📅 Method 3: Automated Daily Backups

### Windows Task Scheduler

1. **Open Task Scheduler**
   - Press `Win + R`
   - Type: `taskschd.msc`
   - Press Enter

2. **Create New Task**
   - Click "Create Basic Task"
   - Name: "Pest Control Daily Backup"
   - Trigger: Daily at 2:00 AM
   - Action: Start a program
   - Program: `C:\laragon\bin\php\php-8.x.x\php.exe`
   - Arguments: `C:\laragon\www\capstone\cloud_backup.php`
   - Start in: `C:\laragon\www\capstone`

3. **Test**
   - Right-click task → Run
   - Check `backups/backup_log.txt` for results

## 📊 Understanding Your Data

### Database Tables
Your database contains these main tables:
- `inventory` - Product/chemical inventory
- `service_bookings` - Customer service appointments
- `users` - User accounts
- `services` - Available services
- `audit_logs` - System activity logs
- `announcements` - System announcements
- And more...

### File Storage
Uploaded files are typically in:
- `uploads/` - Customer uploads, images, documents
- Check `cloud_config.php` → `files_directory` setting

## 🔒 Security Best Practices

1. **Protect Backup Files**
   - Never share backup files publicly
   - Use strong passwords for cloud accounts
   - Enable 2FA on cloud storage accounts

2. **Regular Backups**
   - Daily backups recommended
   - Keep at least 30 days of backups
   - Test restore process monthly

3. **Multiple Locations**
   - Store backups in multiple places
   - Local + Cloud = Best practice
   - Consider off-site backup location

## 📥 How to Restore Data

### Restore Database from SQL

1. **Download backup file** from cloud or local
2. **If compressed**, extract: `gunzip backup.sql.gz`
3. **Restore to MySQL**:
   ```bash
   mysql -u root -p "pest control" < backup.sql
   ```

### Restore Files

1. **Download files backup** (tar.gz file)
2. **Extract**:
   ```bash
   tar -xzf files_backup.tar.gz -C uploads/
   ```

### Using Restore Utility

1. Go to: `http://localhost/capstone/backup_restore.php`
2. View available backups
3. Click "Restore DB" or "Restore Files"
4. Confirm restoration

## ✅ Verification Checklist

After storing your data, verify:

- [ ] Backup file created successfully
- [ ] File size is reasonable (not 0 bytes)
- [ ] Backup uploaded to cloud (if using cloud)
- [ ] Can download backup from cloud
- [ ] Backup log shows success
- [ ] Test restore on test database

## 🆘 Troubleshooting

### Backup Fails

**Problem**: "Database backup failed"
- **Solution**: Check MySQL is running
- Verify database credentials in `cloud_config.php`
- Check PHP has permission to write to `backups/` folder

**Problem**: "Cloud upload failed"
- **Solution**: Verify cloud credentials
- Check internet connection
- Verify bucket/container exists
- Check file size limits

### Files Not Backing Up

**Problem**: Files directory not found
- **Solution**: Check `files_directory` path in `cloud_config.php`
- Create `uploads/` directory if missing
- Set correct permissions (755)

### Large Backup Files

**Problem**: Backup files too large
- **Solution**: Enable compression in `cloud_config.php`
- Exclude unnecessary files
- Use incremental backups (advanced)

## 📈 Storage Recommendations

### For Small Websites (< 1GB)
- Daily backups
- Keep 7-14 days
- Use Dropbox or Google Drive

### For Medium Websites (1-10GB)
- Daily backups
- Keep 30 days
- Use AWS S3 or Google Cloud

### For Large Websites (> 10GB)
- Multiple daily backups
- Keep 90+ days
- Use AWS S3 with lifecycle policies
- Consider incremental backups

## 🎯 Quick Reference

| Task | Method | Time |
|------|--------|------|
| Quick backup | Data Storage Manager → Export SQL | 1 min |
| Full backup | Data Storage Manager → Backup to Cloud | 2-5 min |
| Export for analysis | Data Storage Manager → Export CSV | 1-2 min |
| Automated backup | Task Scheduler | Setup once |
| Restore data | backup_restore.php | 2-5 min |

## 📞 Need Help?

1. Check `backups/backup_log.txt` for errors
2. Review `cloud_setup_guide.md` for detailed setup
3. Verify all credentials in `cloud_config.php`
4. Test with small backup first

---

**Remember**: Regular backups are essential! Set up automated backups and test them regularly.

