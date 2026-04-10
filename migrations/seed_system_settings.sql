-- ============================================================================
-- Description: Inserts DB-backed settings rows for Cloudflare Turnstile
-- bot protection, general security policies, backup/R2 cloud storage
-- config, and general platform configuration. Uses INSERT IGNORE to
-- avoid duplicates on re-run. Passwords are stored with type='password'
-- so the API masks them on GET and skips empty values on PUT.
-- ============================================================================

USE fortunek_web_db;

-- ── Turnstile Settings ────────────────────────────────────────────────────────

INSERT IGNORE INTO `settings`
  (`id`, `settingKey`, `settingValue`, `type`, `groupName`, `label`, `description`, `isPublic`, `isEncrypted`, `createdAt`, `updatedAt`)
VALUES
  ('set_turnstile_enabled', 'TURNSTILE_ENABLED', 'false', 'boolean', 'turnstile', 'Enable Turnstile', 'Enable Cloudflare Turnstile bot protection on login, register, contact, and password reset forms', 1, 0, NOW(), NOW()),
  ('set_turnstile_site_key', 'TURNSTILE_SITE_KEY', '', 'string', 'turnstile', 'Turnstile Site Key', 'Public site key from Cloudflare Turnstile dashboard (visible to frontend)', 1, 0, NOW(), NOW()),
  ('set_turnstile_secret_key', 'TURNSTILE_SECRET_KEY', '', 'password', 'turnstile', 'Turnstile Secret Key', 'Secret key for server-side verification (never exposed to frontend)', 0, 0, NOW(), NOW());

-- ── General Security Settings ─────────────────────────────────────────────────

INSERT IGNORE INTO `settings`
  (`id`, `settingKey`, `settingValue`, `type`, `groupName`, `label`, `description`, `isPublic`, `isEncrypted`, `createdAt`, `updatedAt`)
VALUES
  ('set_session_timeout', 'SESSION_TIMEOUT_MINUTES', '30', 'number', 'security', 'Session Timeout (minutes)', 'JWT token expiry / session timeout in minutes', 0, 0, NOW(), NOW()),
  ('set_max_login_attempts', 'MAX_LOGIN_ATTEMPTS', '5', 'number', 'security', 'Max Login Attempts', 'Maximum failed login attempts before temporary lockout', 0, 0, NOW(), NOW()),
  ('set_password_min_length', 'PASSWORD_MIN_LENGTH', '8', 'number', 'security', 'Password Min Length', 'Minimum number of characters for user passwords', 0, 0, NOW(), NOW()),
  ('set_require_strong_pw', 'REQUIRE_STRONG_PASSWORD', 'true', 'boolean', 'security', 'Require Strong Passwords', 'Enforce uppercase, lowercase, numbers, and special characters', 0, 0, NOW(), NOW()),
  ('set_enable_2fa', 'ENABLE_TWO_FACTOR', 'false', 'boolean', 'security', 'Two-Factor Authentication', 'Require 2FA for admin and super admin accounts', 0, 0, NOW(), NOW()),
  ('set_allowed_domains', 'ALLOWED_DOMAINS', '', 'string', 'security', 'Allowed Email Domains', 'Comma-separated list of allowed email domains for registration (blank = all)', 0, 0, NOW(), NOW()),
  ('set_ip_whitelist', 'IP_WHITELIST', '', 'string', 'security', 'IP Whitelist', 'Comma-separated IP addresses allowed to access admin panel (blank = all)', 0, 0, NOW(), NOW());

-- ── General Platform Settings ─────────────────────────────────────────────────

INSERT IGNORE INTO `settings`
  (`id`, `settingKey`, `settingValue`, `type`, `groupName`, `label`, `description`, `isPublic`, `isEncrypted`, `createdAt`, `updatedAt`)
VALUES
  ('set_platform_name', 'PLATFORM_NAME', 'Fortune Technologies', 'string', 'general', 'Platform Name', 'Displayed in emails, browser title, and admin panel', 1, 0, NOW(), NOW()),
  ('set_platform_url', 'PLATFORM_URL', '', 'string', 'general', 'Platform URL', 'Base URL of the frontend application', 1, 0, NOW(), NOW()),
  ('set_frontend_url', 'FRONTEND_URL', '', 'string', 'general', 'Frontend URL', 'Frontend URL used in email links and redirects', 1, 0, NOW(), NOW()),
  ('set_timezone', 'SYSTEM_TIMEZONE', 'Africa/Nairobi', 'string', 'general', 'System Timezone', 'Default timezone for dates and scheduled tasks', 0, 0, NOW(), NOW()),
  ('set_maintenance', 'MAINTENANCE_MODE', 'false', 'boolean', 'general', 'Maintenance Mode', 'Temporarily disable all public-facing pages for maintenance', 1, 0, NOW(), NOW()),
  ('set_debug_mode', 'DEBUG_MODE', 'false', 'boolean', 'general', 'Debug Mode', 'Enable detailed error logging and stack traces (production: off)', 0, 0, NOW(), NOW()),
  ('set_log_level', 'LOG_LEVEL', 'info', 'string', 'general', 'Log Level', 'Minimum log level: debug, info, warning, error', 0, 0, NOW(), NOW()),
  ('set_app_name', 'APP_NAME', 'Fortune Technologies', 'string', 'general', 'Application Name', 'Internal app name used in emails and system messages', 1, 0, NOW(), NOW());

-- ── Backup / R2 Settings ──────────────────────────────────────────────────────

INSERT IGNORE INTO `settings`
  (`id`, `settingKey`, `settingValue`, `type`, `groupName`, `label`, `description`, `isPublic`, `isEncrypted`, `createdAt`, `updatedAt`)
VALUES
  ('set_r2_enabled', 'R2_BACKUP_ENABLED', 'false', 'boolean', 'backup', 'Enable R2 Cloud Backup', 'Upload backups to Cloudflare R2 for offsite disaster recovery', 0, 0, NOW(), NOW()),
  ('set_r2_account_id', 'R2_ACCOUNT_ID', '', 'string', 'backup', 'R2 Account ID', 'Cloudflare account ID from the dashboard', 0, 0, NOW(), NOW()),
  ('set_r2_access_key', 'R2_ACCESS_KEY_ID', '', 'string', 'backup', 'R2 Access Key ID', 'S3-compatible access key for R2', 0, 0, NOW(), NOW()),
  ('set_r2_secret_key', 'R2_SECRET_ACCESS_KEY', '', 'password', 'backup', 'R2 Secret Access Key', 'S3-compatible secret key (stored securely, never displayed)', 0, 0, NOW(), NOW()),
  ('set_r2_bucket', 'R2_BUCKET_NAME', '', 'string', 'backup', 'R2 Bucket Name', 'Name of the R2 bucket for storing backups', 0, 0, NOW(), NOW()),
  ('set_r2_endpoint', 'R2_ENDPOINT_URL', '', 'string', 'backup', 'R2 Endpoint URL', 'S3-compatible endpoint, e.g. https://<account-id>.r2.cloudflarestorage.com', 0, 0, NOW(), NOW()),
  ('set_r2_region', 'R2_REGION', 'auto', 'string', 'backup', 'R2 Region', 'Region for R2 bucket (usually "auto")', 0, 0, NOW(), NOW()),
  ('set_backup_retention', 'BACKUP_RETENTION_DAYS', '90', 'number', 'backup', 'Retention Days', 'Number of days to keep backups before auto-deletion', 0, 0, NOW(), NOW()),
  ('set_backup_max', 'BACKUP_MAX_COUNT', '30', 'number', 'backup', 'Max Backup Count', 'Maximum number of backups to keep (oldest deleted first)', 0, 0, NOW(), NOW()),
  ('set_backup_auto', 'AUTO_BACKUP_ENABLED', 'false', 'boolean', 'backup', 'Enable Auto Backup', 'Run automatic backups on a schedule', 0, 0, NOW(), NOW()),
  ('set_backup_frequency', 'BACKUP_FREQUENCY', 'daily', 'string', 'backup', 'Backup Frequency', 'How often to run automatic backups: hourly, daily, weekly, monthly', 0, 0, NOW(), NOW()),
  ('set_backup_time', 'BACKUP_TIME', '02:00', 'string', 'backup', 'Backup Time', 'Time of day to run scheduled backups (24h format)', 0, 0, NOW(), NOW());

-- ── Email/SMTP Settings (if not already seeded) ──────────────────────────────

INSERT IGNORE INTO `settings`
  (`id`, `settingKey`, `settingValue`, `type`, `groupName`, `label`, `description`, `isPublic`, `isEncrypted`, `createdAt`, `updatedAt`)
VALUES
  ('set_email_provider', 'EMAIL_PROVIDER', 'smtp', 'string', 'email', 'Email Provider', 'Email sending provider: smtp, sendgrid, mailgun', 0, 0, NOW(), NOW()),
  ('set_smtp_host', 'SMTP_HOST', '', 'string', 'email', 'SMTP Host', 'SMTP server hostname, e.g. smtp.gmail.com', 0, 0, NOW(), NOW()),
  ('set_smtp_port', 'SMTP_PORT', '587', 'number', 'email', 'SMTP Port', 'SMTP port (587 for TLS, 465 for SSL)', 0, 0, NOW(), NOW()),
  ('set_smtp_user', 'SMTP_USER', '', 'string', 'email', 'SMTP Username', 'SMTP login username / email', 0, 0, NOW(), NOW()),
  ('set_smtp_pass', 'SMTP_PASS', '', 'password', 'email', 'SMTP Password', 'SMTP password or app password (never displayed)', 0, 0, NOW(), NOW()),
  ('set_smtp_encryption', 'SMTP_ENCRYPTION', 'tls', 'string', 'email', 'Encryption', 'SMTP encryption: tls, ssl, or none', 0, 0, NOW(), NOW()),
  ('set_email_from', 'EMAIL_FROM_DEFAULT', '', 'string', 'email', 'Default From Email', 'Default sender email address', 0, 0, NOW(), NOW()),
  ('set_email_from_name', 'EMAIL_FROM_NAME', 'Fortune Technologies', 'string', 'email', 'Default From Name', 'Default sender display name', 0, 0, NOW(), NOW()),
  ('set_email_from_recruit', 'EMAIL_FROM_RECRUITMENT', '', 'string', 'email', 'Recruitment From Email', 'Sender email for recruitment notifications', 0, 0, NOW(), NOW()),
  ('set_email_from_recruit_n', 'EMAIL_FROM_RECRUITMENT_NAME', 'Fortune Kenya Recruitment', 'string', 'email', 'Recruitment From Name', 'Sender name for recruitment emails', 0, 0, NOW(), NOW()),
  ('set_email_from_auth', 'EMAIL_FROM_AUTH', '', 'string', 'email', 'Auth From Email', 'Sender email for verification and password reset', 0, 0, NOW(), NOW()),
  ('set_email_admin_inbox', 'EMAIL_ADMIN_INBOX', '', 'string', 'email', 'Admin Inbox', 'Email that receives admin notifications (quotes, contacts)', 0, 0, NOW(), NOW()),
  ('set_email_sales', 'EMAIL_SALES', '', 'string', 'email', 'Sales Inbox', 'Email that receives sales/quote notifications', 0, 0, NOW(), NOW());

-- ── Alert Toggle Settings ─────────────────────────────────────────────────────

INSERT IGNORE INTO `settings`
  (`id`, `settingKey`, `settingValue`, `type`, `groupName`, `label`, `description`, `isPublic`, `isEncrypted`, `createdAt`, `updatedAt`)
VALUES
  ('set_notif_enabled', 'EMAIL_NOTIFICATIONS_ENABLED', 'true', 'boolean', 'alerts', 'Master Email Switch', 'Global on/off for ALL outgoing emails from the system', 0, 0, NOW(), NOW()),
  ('set_instant_alerts', 'INSTANT_ALERTS_ENABLED', 'true', 'boolean', 'alerts', 'Instant Job Alerts', 'Send immediate email when a new job matches a user alert', 0, 0, NOW(), NOW()),
  ('set_daily_digest', 'DAILY_DIGEST_ENABLED', 'true', 'boolean', 'alerts', 'Daily Digest', 'Batch daily job alerts into a single email at 8 AM', 0, 0, NOW(), NOW()),
  ('set_weekly_digest', 'WEEKLY_DIGEST_ENABLED', 'true', 'boolean', 'alerts', 'Weekly Digest', 'Batch weekly job alerts into a single email every Monday', 0, 0, NOW(), NOW()),
  ('set_notify_contact', 'NOTIFY_ON_CONTACT', 'true', 'boolean', 'alerts', 'Contact Form Alerts', 'Email admin when a contact form is submitted', 0, 0, NOW(), NOW()),
  ('set_notify_quote', 'NOTIFY_ON_QUOTE', 'true', 'boolean', 'alerts', 'Quote Request Alerts', 'Email admin when a pricing/quote request is submitted', 0, 0, NOW(), NOW()),
  ('set_notify_application', 'NOTIFY_ON_APPLICATION', 'true', 'boolean', 'alerts', 'Application Alerts', 'Email recruiter when a new job application is received', 0, 0, NOW(), NOW());
