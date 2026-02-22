<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Models\ProfileFieldSetting;
use App\Traits\NormalizedResponseTrait;

class ProfileFieldSettings extends BaseController
{
    use NormalizedResponseTrait;

    private function requireSuperAdmin()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return [null, $this->fail('Unauthorized', 401)];
        }
        if (($user->role ?? null) !== 'SUPER_ADMIN') {
            return [null, $this->fail('Only SUPER_ADMIN can manage profile settings', 403)];
        }
        return [$user, null];
    }

    /**
     * GET /api/profile-field-settings
     * Public read (candidates + frontend)
     */
    public function index()
    {
        try {
            $model = new ProfileFieldSetting();

            $rows = $model
                ->orderBy('category', 'ASC')
                ->orderBy('displayOrder', 'ASC')
                ->findAll();

            $grouped = [];
            foreach ($rows as $r) {
                $cat = $r['category'] ?? 'other';
                if (!isset($grouped[$cat])) $grouped[$cat] = [];
                $grouped[$cat][] = $r;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Profile field settings retrieved',
                'data' => [
                    'settings' => $rows,
                    'grouped' => $grouped,
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'ProfileFieldSettings index error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve profile field settings',
                'data' => [],
            ], 500);
        }
    }

    /**
     * PATCH /api/profile-field-settings/bulk
     * SUPER_ADMIN only
     * Body: { updates: [{ id, isVisible?, isRequired?, displayOrder? }, ...] }
     */
    public function bulkUpdate()
    {
        [$user, $resp] = $this->requireSuperAdmin();
        if (!$user) return $resp;

        $payload = $this->request->getJSON(true) ?? [];
        $updates = $payload['updates'] ?? null;

        if (!$updates || !is_array($updates)) {
            return $this->fail('updates must be an array', 400);
        }

        $model = new ProfileFieldSetting();
        $db = \Config\Database::connect();
        $db->transStart();

        try {
            $updatedIds = [];

            foreach ($updates as $u) {
                $id = $u['id'] ?? null;
                if (!$id) continue;

                $existing = $model->find($id);
                if (!$existing) continue;

                $updateData = [];

                if (array_key_exists('isVisible', $u)) {
                    $updateData['isVisible'] = (int) (!!$u['isVisible']);
                }
                if (array_key_exists('isRequired', $u)) {
                    $updateData['isRequired'] = (int) (!!$u['isRequired']);
                }
                if (array_key_exists('displayOrder', $u)) {
                    $updateData['displayOrder'] = (int) $u['displayOrder'];
                }

                // Hard rule: cannot be required if not visible
                if (isset($updateData['isVisible']) && $updateData['isVisible'] === 0) {
                    $updateData['isRequired'] = 0;
                }

                if (!empty($updateData)) {
                    $updateData['updatedAt'] = date('Y-m-d H:i:s');
                    $model->update($id, $updateData);
                    $updatedIds[] = $id;
                }
            }

            $db->transComplete();
            if ($db->transStatus() === false) {
                throw new \Exception('Transaction failed');
            }

            // audit
            try {
                $audit = new \App\Models\AuditLog();
                $audit->insert([
                    'id' => uniqid('audit_'),
                    'userId' => $user->id,
                    'action' => 'PROFILE_FIELD_SETTINGS_BULK_UPDATED',
                    'resource' => 'profile_field_settings',
                    'resourceId' => 'bulk',
                    'module' => 'PROFILE_SETTINGS',
                    'details' => json_encode(['count' => count($updatedIds), 'ids' => $updatedIds]),
                    'createdAt' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                // ignore audit failures
            }

            return $this->respond([
                'success' => true,
                'message' => 'Settings updated',
                'data' => [
                    'updatedCount' => count($updatedIds),
                ],
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'ProfileFieldSettings bulkUpdate error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update settings',
                'data' => [],
            ], 500);
        }
    }
}
