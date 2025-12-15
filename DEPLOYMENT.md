# CodeIgniter 4 Deployment Guide - cPanel Production Setup

## Table of Contents
1. [Pre-Deployment Checklist](#pre-deployment-checklist)
2. [cPanel Deployment Steps](#cpanel-deployment-steps)
3. [Production Configuration](#production-configuration)
4. [Security Hardening](#security-hardening)
5. [Performance Optimization](#performance-optimization)
6. [Database Setup](#database-setup)
7. [Environment Variables](#environment-variables)
8. [File Permissions](#file-permissions)
9. [SSL/HTTPS Configuration](#sslhttps-configuration)
10. [Monitoring & Logging](#monitoring--logging)
11. [Troubleshooting](#troubleshooting)

---

## Pre-Deployment Checklist

### Requirements
- ✅ PHP 8.1 or higher
- ✅ MySQL 5.7+ or MariaDB 10.3+
- ✅ Composer installed
- ✅ cPanel access with SSH enabled
- ✅ Domain/subdomain configured
- ✅ SSL certificate installed

### Pre-Deployment Tasks
1. **Code Review**
   - Remove all debug code
   - Remove test files
   - Review error handling
   - Check for hardcoded credentials

2. **Dependencies**
   - Run `composer install --no-dev --optimize-autoloader`
   - Verify all required PHP extensions are installed

3. **Database Backup**
   - Export current database (if migrating)
   - Document current schema

4. **Environment Preparation**
   - Prepare production `.env` file
   - Document all environment variables

---

## cPanel Deployment Steps

### Step 1: Access cPanel and Prepare Directory

1. **Login to cPanel**
   - Navigate to your cPanel account
   - Go to **File Manager**

2. **Navigate to Public HTML**
   - Go to `public_html` (or your domain's document root)
   - Create a backup of existing files if any

3. **Create Application Directory** (Optional)
   ```
   public_html/
   └── api/          (or your preferred directory name)
       └── (CodeIgniter files)
   ```

### Step 2: Upload Files via FTP/SFTP or File Manager

**Option A: Using File Manager**
1. Navigate to target directory in File Manager
2. Click **Upload** button
3. Upload all project files (except `.env` and `writable/`)

**Option B: Using FTP/SFTP Client**
1. Connect using FileZilla or similar
2. Upload entire project structure
3. Maintain directory structure

**Option C: Using Git (Recommended)**
```bash
# SSH into cPanel
cd ~/public_html/api  # or your directory

# Clone repository
git clone https://your-repo-url.git .

# Or pull latest changes
git pull origin main
```

### Step 3: Install Dependencies

```bash
# SSH into cPanel
cd ~/public_html/api  # or your directory

# Install production dependencies
composer install --no-dev --optimize-autoloader
```

### Step 4: Set Up Directory Structure

Ensure the following directories exist and are writable:
```
writable/
├── cache/
├── logs/
├── session/
└── uploads/
```

### Step 5: Configure Document Root

**Important:** CodeIgniter 4 requires the `public/` directory as document root.

**Option A: Point Domain to `public/` Directory**
1. In cPanel, go to **Domains** → **Addon Domains** or **Subdomains**
2. Set document root to: `public_html/api/public`

**Option B: Use `.htaccess` Redirect** (if you can't change document root)
Create `.htaccess` in root directory:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/public/
    RewriteRule ^(.*)$ /public/$1 [L]
</IfModule>
```

---

## Production Configuration

### Step 1: Environment File Setup

1. **Copy Environment Template**
   ```bash
   cp env .env
   ```

2. **Configure Production Settings**
   Edit `.env` file with production values:

```ini
#--------------------------------------------------------------------
# ENVIRONMENT
#--------------------------------------------------------------------
CI_ENVIRONMENT = production

#--------------------------------------------------------------------
# APP
#--------------------------------------------------------------------
app.baseURL = 'https://yourdomain.com/api/'
app.indexPage = ''
app.forceGlobalSecureRequests = true

#--------------------------------------------------------------------
# DATABASE
#--------------------------------------------------------------------
database.default.hostname = 'localhost'
database.default.database = 'your_database_name'
database.default.username = 'your_database_user'
database.default.password = 'your_secure_password'
database.default.DBDriver = 'MySQLi'
database.default.port = 3306

#--------------------------------------------------------------------
# SESSION
#--------------------------------------------------------------------
session.driver = 'CodeIgniter'
session.cookieName = 'ci_session'
session.expiration = 7200
session.savePath = WRITEPATH . 'session'
session.matchIP = false
session.timeToUpdate = 300
session.regenerateDestroy = false

#--------------------------------------------------------------------
# SECURITY
#--------------------------------------------------------------------
security.csrfProtection = 'session'
security.tokenRandomize = true
security.tokenName = 'csrf_token_name'
security.headerName = 'X-CSRF-TOKEN'
security.cookieName = 'csrf_cookie_name'
security.expires = 7200
security.regenerate = true
security.redirect = true
security.samesite = 'Lax'

#--------------------------------------------------------------------
# LOGGING
#--------------------------------------------------------------------
logger.threshold = 4  # 0=Emergency, 1=Alert, 2=Critical, 3=Error, 4=Warning, 5=Notice, 6=Info, 7=Debug

#--------------------------------------------------------------------
# EMAIL CONFIGURATION
#--------------------------------------------------------------------
email_host = 'smtp.yourdomain.com'
email_port = 587
email_user = 'noreply@yourdomain.com'
email_password = 'your_email_password'
email_from = 'noreply@yourdomain.com'
email_fromName = 'Fortune Technologies'
email_adminEmail = 'admin@yourdomain.com'
email_adminUrl = 'https://yourdomain.com/admin'
email_fromAuth = 'noreply@yourdomain.com'
email_fromAuthName = 'Fortune Technologies'

#--------------------------------------------------------------------
# FRONTEND URL
#--------------------------------------------------------------------
FRONTEND_URL = 'https://yourdomain.com'

#--------------------------------------------------------------------
# JWT SECRET (Generate a strong random string)
#--------------------------------------------------------------------
JWT_SECRET = 'your-very-long-random-secret-key-here-minimum-32-characters'

#--------------------------------------------------------------------
# CORS (if needed)
#--------------------------------------------------------------------
CORS_ALLOWED_ORIGINS = 'https://yourdomain.com,https://www.yourdomain.com'
```

### Step 2: Update Base URL

Edit `app/Config/App.php`:
```php
public $baseURL = 'https://yourdomain.com/api/';
public $indexPage = '';
public $forceGlobalSecureRequests = true;
```

### Step 3: Configure Database

1. **Create Database in cPanel**
   - Go to **MySQL Databases**
   - Create new database: `your_database_name`
   - Create database user: `your_database_user`
   - Set strong password
   - Add user to database with ALL PRIVILEGES

2. **Import Database Schema**
   ```bash
   # Via SSH
   mysql -u your_database_user -p your_database_name < database_schema.sql
   
   # Or via phpMyAdmin
   # 1. Go to phpMyAdmin in cPanel
   # 2. Select your database
   # 3. Click Import
   # 4. Choose your SQL file
   # 5. Click Go
   ```

3. **Run Migrations** (if using CodeIgniter migrations)
   ```bash
   php spark migrate
   ```

### Step 4: Configure PHP Settings

1. **Check PHP Version**
   - In cPanel, go to **Select PHP Version**
   - Select PHP 8.1 or higher

2. **Required PHP Extensions**
   Ensure these are enabled:
   - `mysqli` or `pdo_mysql`
   - `mbstring`
   - `openssl`
   - `curl`
   - `json`
   - `zip`
   - `gd` (for image processing if needed)

3. **PHP Settings** (via `.htaccess` or `php.ini`)
   ```apache
   # In public/.htaccess or via cPanel PHP Selector
   php_value memory_limit 256M
   php_value max_execution_time 300
   php_value upload_max_filesize 50M
   php_value post_max_size 50M
   ```

---

## Security Hardening

### 1. File Permissions

Set correct file permissions:
```bash
# SSH into server
cd ~/public_html/api

# Set directory permissions
find . -type d -exec chmod 755 {} \;

# Set file permissions
find . -type f -exec chmod 644 {} \;

# Make writable directory writable
chmod -R 775 writable/
chown -R your_cpanel_user:your_cpanel_user writable/

# Protect .env file
chmod 600 .env
chown your_cpanel_user:your_cpanel_user .env
```

### 2. Protect Sensitive Files

Create/update `public/.htaccess`:
```apache
# Deny access to .env file
<FilesMatch "^\.env">
    Order allow,deny
    Deny from all
</FilesMatch>

# Deny access to writable directory
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^(.*/)writable/
    RewriteRule ^(.*)$ index.php/$1 [R=403,L]
</IfModule>

# Deny access to app directory
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^(.*/)app/
    RewriteRule ^(.*)$ index.php/$1 [R=403,L]
</IfModule>

# Deny access to system directory
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^(.*/)system/
    RewriteRule ^(.*)$ index.php/$1 [R=403,L]
</IfModule>
```

### 3. Update Security Configuration

Edit `app/Config/Security.php`:
```php
public $csrfProtection = 'session';
public $tokenRandomize = true;
public $tokenName = 'csrf_token_name';
public $headerName = 'X-CSRF-TOKEN';
public $cookieName = 'csrf_cookie_name';
public $expires = 7200;
public $regenerate = true;
public $redirect = true;
public $samesite = 'Lax';
```

### 4. Disable Error Display

Edit `app/Config/Boot/production.php`:
```php
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_WARNING);
```

Or in `.env`:
```ini
CI_ENVIRONMENT = production
```

### 5. Generate Encryption Key

```bash
php spark key:generate
```

This will update your `.env` file with a secure encryption key.

### 6. JWT Secret Key

Generate a strong JWT secret:
```bash
# Generate random string
openssl rand -base64 32

# Or use online generator
# Add to .env as JWT_SECRET
```

---

## Performance Optimization

### 1. Enable Opcode Caching

In `public/.htaccess` or via cPanel PHP Selector:
```apache
# Enable OPcache (if available)
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
```

### 2. Enable Gzip Compression

In `public/.htaccess`:
```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>
```

### 3. Browser Caching

In `public/.htaccess`:
```apache
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    ExpiresByType application/json "access plus 0 seconds"
</IfModule>
```

### 4. Database Optimization

- Enable query caching
- Add indexes to frequently queried columns
- Regular database optimization via phpMyAdmin

### 5. CodeIgniter Caching

Enable caching in `app/Config/Cache.php`:
```php
public $handler = 'file';
public $storePath = WRITEPATH . 'cache/';
```

---

## Database Setup

### 1. Create Database and User

Via cPanel MySQL Databases:
1. Create database: `fortune_api_db`
2. Create user: `fortune_api_user`
3. Grant ALL PRIVILEGES
4. Note credentials for `.env`

### 2. Import Schema

**Option A: Via phpMyAdmin**
1. Login to phpMyAdmin
2. Select your database
3. Click **Import**
4. Choose your SQL file
5. Click **Go**

**Option B: Via SSH**
```bash
mysql -u fortune_api_user -p fortune_api_db < schema.sql
```

### 3. Seed Initial Data (if needed)

```bash
php spark db:seed InitialDataSeeder
```

---

## Environment Variables

### Complete `.env` Template for Production

```ini
#--------------------------------------------------------------------
# ENVIRONMENT
#--------------------------------------------------------------------
CI_ENVIRONMENT = production

#--------------------------------------------------------------------
# APP
#--------------------------------------------------------------------
app.baseURL = 'https://api.yourdomain.com/'
app.indexPage = ''
app.forceGlobalSecureRequests = true
app.sessionDriver = 'CodeIgniter'
app.sessionCookieName = 'ci_session'
app.sessionExpiration = 7200
app.sessionSavePath = WRITEPATH . 'session'
app.sessionMatchIP = false
app.sessionTimeToUpdate = 300
app.sessionRegenerateDestroy = false

#--------------------------------------------------------------------
# DATABASE
#--------------------------------------------------------------------
database.default.hostname = 'localhost'
database.default.database = 'fortune_api_db'
database.default.username = 'fortune_api_user'
database.default.password = 'your_secure_password_here'
database.default.DBDriver = 'MySQLi'
database.default.port = 3306
database.default.DBPrefix = ''
database.default.foreignKeys = false

#--------------------------------------------------------------------
# SECURITY
#--------------------------------------------------------------------
security.csrfProtection = 'session'
security.tokenRandomize = true
security.tokenName = 'csrf_token_name'
security.headerName = 'X-CSRF-TOKEN'
security.cookieName = 'csrf_cookie_name'
security.expires = 7200
security.regenerate = true
security.redirect = true
security.samesite = 'Lax'

#--------------------------------------------------------------------
# ENCRYPTION
#--------------------------------------------------------------------
encryption.key = 'your-encryption-key-from-spark-key-generate'
encryption.driver = 'OpenSSL'

#--------------------------------------------------------------------
# LOGGING
#--------------------------------------------------------------------
logger.threshold = 4

#--------------------------------------------------------------------
# EMAIL
#--------------------------------------------------------------------
email_host = 'smtp.yourdomain.com'
email_port = 587
email_user = 'noreply@yourdomain.com'
email_password = 'your_email_password'
email_from = 'noreply@yourdomain.com'
email_fromName = 'Fortune Technologies'
email_adminEmail = 'admin@yourdomain.com'
email_adminUrl = 'https://yourdomain.com/admin'
email_fromAuth = 'noreply@yourdomain.com'
email_fromAuthName = 'Fortune Technologies'

#--------------------------------------------------------------------
# FRONTEND URL
#--------------------------------------------------------------------
FRONTEND_URL = 'https://yourdomain.com'

#--------------------------------------------------------------------
# JWT
#--------------------------------------------------------------------
JWT_SECRET = 'your-very-long-random-secret-key-minimum-32-characters'
JWT_EXPIRATION = 86400

#--------------------------------------------------------------------
# CORS
#--------------------------------------------------------------------
CORS_ALLOWED_ORIGINS = 'https://yourdomain.com,https://www.yourdomain.com'
```

---

## File Permissions

### Recommended Permissions

```bash
# Directories
chmod 755 app/
chmod 755 public/
chmod 755 system/
chmod 755 writable/
chmod 755 writable/cache/
chmod 755 writable/logs/
chmod 755 writable/session/
chmod 755 writable/uploads/

# Files
chmod 644 .env
chmod 644 composer.json
chmod 644 composer.lock

# Executable files
chmod 755 public/index.php
chmod 755 spark

# Writable directories (must be writable by web server)
chmod 775 writable/
chmod 775 writable/cache/
chmod 775 writable/logs/
chmod 775 writable/session/
chmod 775 writable/uploads/
```

### Set Ownership

```bash
# Set ownership to your cPanel user
chown -R your_cpanel_user:your_cpanel_user .

# For writable directory, ensure web server can write
# Usually cPanel user and web server are same, but verify
```

---

## SSL/HTTPS Configuration

### 1. Install SSL Certificate

Via cPanel:
1. Go to **SSL/TLS Status**
2. Install SSL certificate (Let's Encrypt recommended)
3. Enable **Force HTTPS Redirect**

### 2. Update CodeIgniter Configuration

In `app/Config/App.php`:
```php
public $baseURL = 'https://yourdomain.com/api/';
public $forceGlobalSecureRequests = true;
```

In `.env`:
```ini
app.forceGlobalSecureRequests = true
```

### 3. Force HTTPS in .htaccess

In `public/.htaccess`:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>
```

---

## Monitoring & Logging

### 1. Enable Logging

Logs are stored in `writable/logs/`

Check logs regularly:
```bash
tail -f writable/logs/log-YYYY-MM-DD.log
```

### 2. Error Monitoring

- Set up error email notifications
- Monitor `writable/logs/` directory
- Set up log rotation

### 3. Performance Monitoring

- Monitor response times
- Check database query performance
- Monitor server resources (CPU, Memory, Disk)

### 4. Backup Strategy

**Automated Backups via cPanel:**
1. Go to **Backup** in cPanel
2. Schedule automatic backups
3. Download backups regularly

**Manual Backup Script:**
```bash
#!/bin/bash
# backup.sh
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/home/user/backups"
mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u user -p database > $BACKUP_DIR/db_$DATE.sql

# Backup files
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /home/user/public_html/api

echo "Backup completed: $DATE"
```

---

## Troubleshooting

### Common Issues

#### 1. 500 Internal Server Error

**Check:**
- File permissions
- `.env` file exists and is configured
- PHP error logs in cPanel
- `writable/` directory permissions

**Solution:**
```bash
chmod -R 775 writable/
chmod 600 .env
```

#### 2. Database Connection Error

**Check:**
- Database credentials in `.env`
- Database user has proper permissions
- Database exists
- MySQL service is running

**Solution:**
- Verify credentials in cPanel MySQL Databases
- Test connection via phpMyAdmin

#### 3. Route Not Found (404)

**Check:**
- `.htaccess` file exists in `public/` directory
- `mod_rewrite` is enabled
- Base URL is correct

**Solution:**
- Verify `.htaccess` in `public/` directory
- Check Apache modules in cPanel

#### 4. Permission Denied Errors

**Check:**
- File ownership
- Directory permissions
- `writable/` directory permissions

**Solution:**
```bash
chown -R your_user:your_user writable/
chmod -R 775 writable/
```

#### 5. Composer Autoload Issues

**Solution:**
```bash
composer dump-autoload --optimize
```

#### 6. Session Issues

**Check:**
- `writable/session/` directory exists and is writable
- Session configuration in `.env`

**Solution:**
```bash
mkdir -p writable/session
chmod 775 writable/session
```

### Debug Mode (Development Only)

**Never enable in production!**

To debug temporarily:
1. Change `CI_ENVIRONMENT = development` in `.env`
2. Check `writable/logs/` for detailed errors
3. **Revert to production immediately after debugging**

---

## Post-Deployment Checklist

- [ ] All environment variables configured
- [ ] Database imported and tested
- [ ] File permissions set correctly
- [ ] SSL certificate installed and HTTPS working
- [ ] Error logging enabled
- [ ] Security settings configured
- [ ] Performance optimizations applied
- [ ] Backup strategy in place
- [ ] Monitoring set up
- [ ] API endpoints tested
- [ ] Email functionality tested
- [ ] File uploads working (if applicable)
- [ ] Session management working
- [ ] CORS configured (if needed)
- [ ] Documentation updated

---

## Maintenance

### Regular Tasks

1. **Weekly:**
   - Check error logs
   - Review security logs
   - Monitor disk space

2. **Monthly:**
   - Update dependencies: `composer update`
   - Review and optimize database
   - Check backup integrity
   - Review performance metrics

3. **Quarterly:**
   - Security audit
   - Code updates
   - Dependency updates
   - Full system backup

---

## Support & Resources

- **CodeIgniter 4 Documentation:** https://codeigniter.com/user_guide/
- **cPanel Documentation:** https://docs.cpanel.net/
- **PHP Documentation:** https://www.php.net/docs.php

---

## Quick Reference Commands

```bash
# Navigate to application
cd ~/public_html/api

# Install dependencies
composer install --no-dev --optimize-autoloader

# Run migrations
php spark migrate

# Clear cache
php spark cache:clear

# Generate encryption key
php spark key:generate

# Check routes
php spark routes

# View logs
tail -f writable/logs/log-$(date +%Y-%m-%d).log
```

---

**Last Updated:** [Current Date]
**Version:** 1.0
**Maintained By:** Development Team
