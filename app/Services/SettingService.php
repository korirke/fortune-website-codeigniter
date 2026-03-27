<?php

namespace App\Services;

use App\Models\Setting;

/**
 * SettingService
 * Central config reader – use this everywhere instead of env().
 * Falls back to env() if DB is unavailable or key is not found.
 *
 * Usage:
 *   $value = SettingService::get('SMTP_HOST');
 *   $bool  = SettingService::bool('EMAIL_NOTIFICATIONS_ENABLED');
 *   SettingService::set('APP_NAME', 'My Portal');
 */
class SettingService
{
    private static ?Setting $model = null;
    private static bool $dbAvailable = true;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get a setting value.
     * Priority: DB → env() → $default
     */
    public static function get(string $key, $default = null)
    {
        $key = strtoupper($key);

        if (self::$dbAvailable) {
            try {
                $model = self::model();
                $value = $model->get($key);
                if ($value !== null) {
                    return $value;
                }
            } catch (\Throwable $e) {
                log_message('warning', 'SettingService DB unavailable: ' . $e->getMessage());
                self::$dbAvailable = false;
            }
        }

        // Fallback to environment
        $envMap = self::envFallbackMap();
        $envKey = $envMap[$key] ?? strtolower($key);
        $envVal = env($envKey);
        return $envVal !== null ? $envVal : $default;
    }

    /**
     * Get as boolean.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key, $default);
        if (is_bool($val)) return $val;
        return in_array(strtolower((string) $val), ['true', '1', 'yes'], true);
    }

    /**
     * Get as integer.
     */
    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key, $default);
        return (int) $val;
    }

    /**
     * Set a value.
     */
    public static function set(string $key, $value): bool
    {
        if (!self::$dbAvailable) return false;
        try {
            return self::model()->set($key, $value);
        } catch (\Throwable $e) {
            log_message('error', 'SettingService::set error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk set.
     */
    public static function bulkSet(array $pairs): void
    {
        if (!self::$dbAvailable) return;
        try {
            self::model()->bulkSet($pairs);
            Setting::clearCache();
        } catch (\Throwable $e) {
            log_message('error', 'SettingService::bulkSet error: ' . $e->getMessage());
        }
    }

    /**
     * Get all settings grouped.
     */
    public static function allByGroup(?string $group = null): array
    {
        if (!self::$dbAvailable) return [];
        try {
            return self::model()->getByGroup($group);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build a CodeIgniter Email config array from DB settings.
     * Used in EmailHelper to configure SMTP dynamically.
     */
    public static function getEmailConfig(): array
    {
        return [
            'protocol'    => self::get('EMAIL_PROVIDER', 'smtp'),
            'SMTPHost'    => self::get('SMTP_HOST', env('email_host', 'smtp.gmail.com')),
            'SMTPUser'    => self::get('SMTP_USER', env('email_username', '')),
            'SMTPPass'    => self::get('SMTP_PASS', env('email_password', '')),
            'SMTPPort'    => self::int('SMTP_PORT', 587),
            'SMTPCrypto'  => self::get('SMTP_ENCRYPTION', 'tls'),
            'mailType'    => 'html',
            'charset'     => 'utf-8',
            'newline'     => "\r\n",
            'validate'    => true,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function model(): Setting
    {
        if (self::$model === null) {
            self::$model = new Setting();
        }
        return self::$model;
    }

    /**
     * Map setting keys → old env() keys for graceful fallback.
     */
    private static function envFallbackMap(): array
    {
        return [
            'SMTP_HOST'                    => 'email_host',
            'SMTP_USER'                    => 'email_username',
            'SMTP_PASS'                    => 'email_password',
            'SMTP_PORT'                    => 'email_port',
            'SMTP_ENCRYPTION'              => 'email_crypto',
            'EMAIL_FROM_DEFAULT'           => 'email_from',
            'EMAIL_FROM_NAME'              => 'email_fromName',
            'EMAIL_FROM_RECRUITMENT'       => 'email_fromRecruitment',
            'EMAIL_FROM_RECRUITMENT_NAME'  => 'email_fromRecruitmentName',
            'EMAIL_FROM_AUTH'              => 'email_fromAuth',
            'EMAIL_ADMIN_INBOX'            => 'email_adminEmail',
            'FRONTEND_URL'                 => 'FRONTEND_URL',
        ];
    }
}
