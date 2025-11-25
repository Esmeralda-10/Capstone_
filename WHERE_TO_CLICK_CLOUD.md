# ☁️ Where to Click for Cloud Backup

## Step-by-Step Guide

### Step 1: Open Data Storage Manager
1. Open your web browser
2. Go to: `http://localhost/capstone/data_storage_manager.php`
3. Make sure you're logged in as admin

### Step 2: Find the Cloud Backup Button

Look for the **"⚡ Quick Actions"** section on the page.

You'll see **4 buttons** in a row:

1. 💾 **Export Database (SQL)** - Purple button
2. 📄 **Export All Tables (CSV)** - Pink/Blue button  
3. 📋 **Export All Tables (JSON)** - Pink/Blue button
4. ☁️ **Backup to Cloud** - **GREEN BUTTON** ← **CLICK THIS ONE!**

### Step 3: Click the Green Button

Click the button that says:
```
☁️ Backup to Cloud
```

It's the **GREEN button** (with gradient from teal to green).

### What Happens Next?

1. A confirmation popup will appear asking "This will backup your entire database and files to cloud storage. Continue?"
2. Click **"OK"** to confirm
3. You'll see a progress bar
4. The button will show "Backing up to cloud..." with a loading spinner
5. When done, you'll see a success message: "✅ Backup uploaded to cloud successfully"

## Visual Layout

```
┌─────────────────────────────────────────┐
│   📦 Data Storage Manager              │
├─────────────────────────────────────────┤
│                                         │
│   📊 Database Statistics                │
│   [Stats cards showing your data]       │
│                                         │
│   ⚡ Quick Actions                      │
│   ┌─────────────┐ ┌─────────────┐     │
│   │ 💾 Export   │ │ 📄 Export   │     │
│   │ Database    │ │ All Tables  │     │
│   │ (SQL)       │ │ (CSV)       │     │
│   └─────────────┘ └─────────────┘     │
│   ┌─────────────┐ ┌─────────────┐     │
│   │ 📋 Export   │ │ ☁️ Backup   │ ← CLICK THIS!
│   │ All Tables  │ │ to Cloud    │     │
│   │ (JSON)      │ │             │     │
│   └─────────────┘ └─────────────┘     │
│                                         │
└─────────────────────────────────────────┘
```

## Alternative: Quick Backup Page

You can also use the simpler quick backup page:

1. Go to: `http://localhost/capstone/quick_backup.php`
2. The page will automatically start backing up
3. It will show progress and upload to cloud if configured

## Important: Configure Cloud First!

**Before clicking the cloud backup button**, make sure you've configured your cloud storage:

1. Open `cloud_config.php`
2. Choose your provider (Dropbox is easiest)
3. Add your credentials:
   - For Dropbox: Add your access token
   - For AWS: Add Access Key, Secret Key, Bucket name
4. Save the file

If cloud is not configured, the backup will still work but will only save locally (not upload to cloud).

## Troubleshooting

**Button doesn't work?**
- Make sure you're logged in as admin
- Check browser console for errors (F12)
- Verify `cloud_backup.php` file exists

**Backup fails?**
- Check `cloud_config.php` has correct credentials
- Verify cloud storage account is active
- Check `backups/backup_log.txt` for error details

**No cloud button visible?**
- Make sure you're on the correct page: `data_storage_manager.php`
- Refresh the page (F5)
- Check if JavaScript is enabled in your browser

## Quick Reference

- **Page URL**: `http://localhost/capstone/data_storage_manager.php`
- **Button Location**: "Quick Actions" section, 4th button (green)
- **Button Text**: "☁️ Backup to Cloud"
- **Button Color**: Green gradient

---

**That's it!** Just click the green "☁️ Backup to Cloud" button in the Quick Actions section! 🎉

