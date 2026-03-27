<?php

namespace App\Controllers\Alerts;

use App\Controllers\BaseController;
use App\Models\JobAlert;
use App\Models\User;
use App\Services\SettingService;
use App\Traits\NormalizedResponseTrait;

/**
 * AlertsController
 * CRUD for job alerts + unsubscribe endpoint.
 *
 *
 * Routes:
 *   GET    /api/alerts              → getAlerts      (auth: candidate)
 *   POST   /api/alerts              → createAlert    (auth: candidate)
 *   PUT    /api/alerts/:id          → updateAlert    (auth: candidate)
 *   DELETE /api/alerts/:id          → deleteAlert    (auth: candidate)
 *   PATCH  /api/alerts/:id/toggle   → toggleAlert    (auth: candidate)
 *
 *   GET    /api/alerts/preferences  → getPreferences (auth: user)
 *   PUT    /api/alerts/preferences  → updatePreferences (auth: user)
 *
 *   GET    /unsubscribe?token=...   → unsubscribe    (public)
 */
class AlertsController extends BaseController
{
    use NormalizedResponseTrait;

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/alerts
    // ──────────────────────────────────────────────────────────────────────────
    public function getAlerts()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $model  = new JobAlert();
            $alerts = $model->getUserAlerts($user->id);

            // Enrich with category name
            $catModel = new \App\Models\JobCategory();
            foreach ($alerts as &$alert) {
                if (!empty($alert['categoryId'])) {
                    $cat = $catModel->find($alert['categoryId']);
                    $alert['category'] = $cat ? ['id' => $cat['id'], 'name' => $cat['name']] : null;
                } else {
                    $alert['category'] = null;
                }
            }

            return $this->respond([
                'success' => true,
                'data'    => $alerts,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'GetAlerts error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to retrieve alerts'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /api/alerts
    // Body: { name, keyword, location, jobType, categoryId, frequency }
    // ──────────────────────────────────────────────────────────────────────────
    public function createAlert()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $data = $this->request->getJSON(true) ?? [];

            // Validate at least one filter
            if (empty($data['keyword']) && empty($data['location']) && empty($data['categoryId'])) {
                return $this->fail('At least one of keyword, location, or category is required.', 400);
            }

            // Enforce max alerts per user
            $maxAlerts = SettingService::int('MAX_ALERTS_PER_USER', 5);
            $model     = new JobAlert();
            $current   = count($model->getUserAlerts($user->id));

            if ($current >= $maxAlerts) {
                return $this->fail("You can have at most {$maxAlerts} job alerts.", 400);
            }

            $alertData = [
                'id'         => uniqid('alert_'),
                'userId'     => $user->id,
                'name'       => trim($data['name'] ?? ''),
                'keyword'    => trim($data['keyword'] ?? '') ?: null,
                'location'   => trim($data['location'] ?? '') ?: null,
                'jobType'    => trim($data['jobType'] ?? '') ?: null,
                'categoryId' => trim($data['categoryId'] ?? '') ?: null,
                'frequency'  => in_array($data['frequency'] ?? '', ['INSTANT','DAILY','WEEKLY'], true)
                                    ? $data['frequency'] : 'DAILY',
                'isActive'   => 1,
                'createdAt'  => date('Y-m-d H:i:s'),
                'updatedAt'  => date('Y-m-d H:i:s'),
            ];

            // Auto-name if blank
            if (empty($alertData['name'])) {
                $alertData['name'] = implode(', ', array_filter([
                    $alertData['keyword'],
                    $alertData['location'],
                ])) ?: 'Job Alert';
            }

            $model->insert($alertData);
            $created = $model->find($alertData['id']);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Job alert created successfully',
                'data'    => $created,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'CreateAlert error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to create alert'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUT /api/alerts/:id
    // ──────────────────────────────────────────────────────────────────────────
    public function updateAlert(string $id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $model    = new JobAlert();
            $existing = $model->where('id', $id)->where('userId', $user->id)->first();
            if (!$existing) return $this->failNotFound('Alert not found');

            $data = $this->request->getJSON(true) ?? [];

            $allowed = ['name','keyword','location','jobType','categoryId','frequency','isActive'];
            $update  = ['updatedAt' => date('Y-m-d H:i:s')];

            foreach ($allowed as $f) {
                if (array_key_exists($f, $data)) {
                    $update[$f] = $f === 'isActive' ? (int)(bool)$data[$f] : ($data[$f] === '' ? null : $data[$f]);
                }
            }

            if (isset($update['frequency']) && !in_array($update['frequency'], ['INSTANT','DAILY','WEEKLY'], true)) {
                return $this->fail('Invalid frequency value.', 400);
            }

            $model->update($id, $update);

            return $this->respond([
                'success' => true,
                'message' => 'Alert updated',
                'data'    => $model->find($id),
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed to update alert'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DELETE /api/alerts/:id
    // ──────────────────────────────────────────────────────────────────────────
    public function deleteAlert(string $id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $model    = new JobAlert();
            $existing = $model->where('id', $id)->where('userId', $user->id)->first();
            if (!$existing) return $this->failNotFound('Alert not found');

            $model->delete($id);

            return $this->respond(['success' => true, 'message' => 'Alert deleted']);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed to delete alert'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PATCH /api/alerts/:id/toggle
    // ──────────────────────────────────────────────────────────────────────────
    public function toggleAlert(string $id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $model    = new JobAlert();
            $existing = $model->where('id', $id)->where('userId', $user->id)->first();
            if (!$existing) return $this->failNotFound('Alert not found');

            $newState = $existing['isActive'] ? 0 : 1;
            $model->update($id, ['isActive' => $newState, 'updatedAt' => date('Y-m-d H:i:s')]);

            return $this->respond([
                'success' => true,
                'message' => $newState ? 'Alert activated' : 'Alert paused',
                'data'    => ['isActive' => (bool) $newState],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed to toggle alert'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/alerts/preferences
    // ──────────────────────────────────────────────────────────────────────────
    public function getPreferences()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $userModel = new User();
            $userData  = $userModel->select('emailNotifications')->find($user->id);

            return $this->respond([
                'success' => true,
                'data'    => [
                    'emailNotifications' => (bool)($userData['emailNotifications'] ?? 1),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUT /api/alerts/preferences
    // Body: { emailNotifications: true|false }
    // ──────────────────────────────────────────────────────────────────────────
    public function updatePreferences()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $data      = $this->request->getJSON(true) ?? [];
            $userModel = new User();
            $userModel->update($user->id, [
                'emailNotifications' => isset($data['emailNotifications']) ? (int)(bool)$data['emailNotifications'] : 1,
                'updatedAt'          => date('Y-m-d H:i:s'),
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Preferences updated',
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /unsubscribe?token=abc123
    // Public endpoint – no auth required
    // ──────────────────────────────────────────────────────────────────────────
    public function unsubscribe()
    {
        $token = $this->request->getGet('token');

        if (empty($token)) {
            return $this->respond(['success' => false, 'message' => 'Invalid unsubscribe link.'], 400);
        }

        $userModel = new User();
        $user      = $userModel->where('unsubscribeToken', $token)->first();

        if (!$user) {
            return $this->respond(['success' => false, 'message' => 'Invalid or expired unsubscribe link.'], 404);
        }

        // Disable all email notifications for this user
        $userModel->update($user['id'], [
            'emailNotifications' => 0,
            'updatedAt'          => date('Y-m-d H:i:s'),
        ]);

        // Also deactivate all their job alerts
        $alertModel = new JobAlert();
        $alertModel->where('userId', $user['id'])->set(['isActive' => 0, 'updatedAt' => date('Y-m-d H:i:s')])->update();

        $appName     = SettingService::get('APP_NAME', 'Fortune Kenya Recruitment');
        $frontendUrl = SettingService::get('FRONTEND_URL', base_url());

        return $this->respond([
            'success'     => true,
            'message'     => 'You have been unsubscribed from all email notifications.',
            'appName'     => $appName,
            'resubscribeLink' => $frontendUrl . '/careers-portal/alerts?resubscribe=1',
        ]);
    }
}
