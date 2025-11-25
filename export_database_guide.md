# How to Export Database in Laragon

## Method 1: Using phpMyAdmin (Easiest)

1. **Start Laragon** - Make sure MySQL is running (green icon)
2. **Open phpMyAdmin**:
   - Click "Database" button in Laragon toolbar, OR
   - Go to: `http://localhost/phpmyadmin`
3. **Select your database**: Click on "pest control" in the left sidebar
4. **Export**:
   - Click the "Export" tab at the top
   - Choose "Quick" or "Custom" export method
   - For Custom: Select "SQL" format
   - Click "Go" button
   - Save the file (usually saves as `.sql` file)

## Method 2: Using Command Line (mysqldump)

### Find MySQL Path:
Laragon MySQL is usually located at:
- `C:\laragon\bin\mysql\mysql-[version]\bin\mysqldump.exe`

### Export Command:
```powershell
# Navigate to MySQL bin directory
cd C:\laragon\bin\mysql\mysql-8.0.30\bin

# Export database (note: database name with space needs quotes)
.\mysqldump.exe -u root -p "pest control" > "C:\laragon\www\capstone\backup.sql"
```

**OR** if MySQL is in your PATH:
```powershell
mysqldump -u root -p "pest control" > "C:\laragon\www\capstone\backup.sql"
```

**Note**: The `-p` flag will prompt for password (usually empty/blank for Laragon root user)

### Export without password prompt (if password is empty):
```powershell
.\mysqldump.exe -u root --password= "pest control" > "C:\laragon\www\capstone\backup.sql"
```

## Method 3: Using Laragon Database Tool

1. Click "Database" button in Laragon
2. Select your database
3. Use the export/backup feature if available

## Common Errors and Solutions

### Error: "Access denied for user 'root'@'localhost'"
**Solution**: 
- Check if MySQL service is running in Laragon
- Try with empty password: `-p` then press Enter when prompted
- Or use: `--password=` (with equals sign)

### Error: "Unknown database 'pest control'"
**Solution**: 
- Make sure database name is in quotes: `"pest control"`
- Check database name spelling (case-sensitive in some MySQL versions)

### Error: "mysqldump: command not found"
**Solution**:
- Use full path to mysqldump.exe
- Or add MySQL bin directory to your PATH environment variable

### Error: "Cannot create file" or "Permission denied"
**Solution**:
- Check if you have write permissions to the destination folder
- Try saving to a different location (e.g., Desktop)
- Run PowerShell/Command Prompt as Administrator

### Error: "The system cannot find the path specified"
**Solution**:
- Verify MySQL installation path in Laragon
- Check Laragon settings for MySQL path
- MySQL version might be different (check `C:\laragon\bin\mysql\` folder)

## Quick Export Script

Save this as `export_db.bat` in your project folder:

```batch
@echo off
cd C:\laragon\bin\mysql\mysql-8.0.30\bin
mysqldump.exe -u root --password= "pest control" > "C:\laragon\www\capstone\backup_%date:~-4,4%%date:~-10,2%%date:~-7,2%.sql"
echo Database exported successfully!
pause
```

## Alternative: Export via PHP Script

You can also create a PHP script to export the database programmatically.

