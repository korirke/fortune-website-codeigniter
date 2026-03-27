<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Services\SettingService;
use App\Services\EmailService;
use App\Models\Setting;
use App\Traits\NormalizedResponseTrait;

/**
 * SettingsController
 * REST API for reading and writing system settings.
 *
 *
 * Routes:
 *   GET    /api/settings              → getAllSettings
 *   GET    /api/settings/group/:group → getByGroup
 *   GET    /api/settings/:key         → getSetting
 *   PUT    /api/settings              → bulkUpdate
 *   POST   /api/settings/test-email   → testEmailConfig
 */
class SettingsController extends BaseController
{
    use NormalizedResponseTrait;

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/settings
    // Returns all settings grouped (passwords masked)
    // ──────────────────────────────────────────────────────────────────────────
    public function getAllSettings()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $rows = SettingService::allByGroup();
            $result = $this->maskPasswords($rows);

            // Group by groupName for frontend
            $grouped = [];
            foreach ($result as $row) {
                $grouped[$row['groupName']][] = $row;
            }

            return $this->respond([
                'success' => true,
                'data'    => $grouped,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'GetAllSettings error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to load settings'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/settings/group/:group
    // ──────────────────────────────────────────────────────────────────────────
    public function getByGroup(string $group = '')
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $rows   = SettingService::allByGroup($group ?: null);
            $result = $this->maskPasswords($rows);

            return $this->respond(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/settings/:key
    // ──────────────────────────────────────────────────────────────────────────
    public function getSetting(string $key = '')
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $model = new Setting();
            $row   = $model->where('settingKey', strtoupper($key))->first();
            if (!$row) return $this->failNotFound('Setting not found');

            if (($row['type'] ?? '') === 'password') {
                $row['settingValue'] = '';
            }

            return $this->respond(['success' => true, 'data' => $row]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUT /api/settings
    // Body: { settings: { KEY: value, KEY2: value2 } }
    // ──────────────────────────────────────────────────────────────────────────
    public function bulkUpdate()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $data     = $this->request->getJSON(true) ?? [];
            $settings = $data['settings'] ?? [];

            if (empty($settings) || !is_array($settings)) {
                return $this->fail('No settings provided', 400);
            }

            // Never let the frontend clear a password field unless it has a value
            $model = new Setting();
            $clean = [];
            foreach ($settings as $k => $v) {
                $key = strtoupper($k);
                $row = $model->where('settingKey', $key)->first();
                if ($row && ($row['type'] ?? '') === 'password' && trim((string)$v) === '') {
                    // Skip — keep existing password
                    continue;
                }
                $clean[$key] = $v;
            }

            SettingService::bulkSet($clean);
            Setting::clearCache();

            // Log audit
            $this->logAudit($user->id, 'SETTINGS_UPDATED', 'Settings', 'bulk', [
                'keys' => array_keys($clean),
            ]);

            return $this->respond([
                'success' => true,
                'message' => count($clean) . ' setting(s) updated successfully',
            ]);
        } catch (\Exception $e) {
            log_message('error', 'BulkUpdate settings error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to update settings'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /api/settings/test-email
    // Body: { to: "email@example.com" }
    // ──────────────────────────────────────────────────────────────────────────
    public function testEmailConfig()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $data = $this->request->getJSON(true) ?? [];
            $to   = trim($data['to'] ?? $user->email ?? '');

            if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return $this->fail('A valid recipient email is required', 400);
            }

            $emailService = new EmailService();
            $result       = $emailService->sendTestEmail($to);

            return $this->respond([
                'success' => $result['success'],
                'message' => $result['success']
                    ? "Test email sent to {$to}"
                    : 'Test email failed: ' . ($result['error'] ?? 'Unknown error'),
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/settings/public
    // Returns only isPublic=1 settings (no auth required)
    // ──────────────────────────────────────────────────────────────────────────
    public function getPublicSettings()
    {
        try {
            $model = new Setting();
            $rows  = $model->where('isPublic', 1)->findAll();
            $map   = [];
            foreach ($rows as $r) {
                $map[$r['settingKey']] = $r['settingValue'];
            }
            return $this->respond(['success' => true, 'data' => $map]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'data' => []], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function maskPasswords(array $rows): array
    {
        return array_map(function ($row) {
            if (($row['type'] ?? '') === 'password') {
                $row['settingValue'] = '';
            }
            return $row;
        }, $rows);
    }

    private function logAudit(string $userId, string $action, string $resource, string $resourceId, array $details): void
    {
        try {
            $db = \Config\Database::connect();
            $db->table('audit_logs')->insert([
                'id'         => uniqid('audit_'),
                'userId'     => $userId,
                'action'     => $action,
                'resource'   => $resource,
                'resourceId' => $resourceId,
                'module'     => 'SYSTEM',
                'details'    => json_encode($details),
                'createdAt'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) { /* non-fatal */ }
    }
}
