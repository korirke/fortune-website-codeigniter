<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Models\EducationQualificationLevel;
use App\Traits\NormalizedResponseTrait;

/**
 * EducationQualificationLevels
 *
 * Public:
 *   GET  /education-qualification-levels          → index()       (active only)
 *
 * Admin:
 *   GET  /admin/education-qualification-levels    → adminIndex()  (all levels)
 *   POST /admin/education-qualification-levels    → create()
 *   PUT  /admin/education-qualification-levels/:id→ update($id)
 *   DELETE /admin/education-qualification-levels/:id→ destroy($id)
 *   PUT  /admin/education-qualification-levels/reorder → reorder()
 */
class EducationQualificationLevels extends BaseController
{
    use NormalizedResponseTrait;

    // ── helpers 
    private function isAdmin(): bool
    {
        $user = $this->request->user ?? null;
        if (!$user) return false;
        return in_array($user->role ?? '', ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR'], true);
    }

    private function formatRow(array $row): array
    {
        return [
            'id'        => $row['id'],
            'key'       => $row['key'],
            'label'     => $row['label'],
            'sortOrder' => (int) $row['sortOrder'],
            'isActive'  => (bool) $row['isActive'],
            'isSystem'  => (bool) $row['isSystem'],
            'createdAt' => $row['createdAt'] ?? null,
            'updatedAt' => $row['updatedAt'] ?? null,
        ];
    }

    // =========================================================================
    // PUBLIC: list active levels (used by EducationSection + admin config pickers)
    // GET /education-qualification-levels
    // =========================================================================
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $model = new EducationQualificationLevel();
            $rows  = $model->active();

            return $this->respond([
                'success' => true,
                'message' => 'Education qualification levels',
                'data'    => array_map([$this, 'formatRow'], $rows),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'EducationQualificationLevels::index – ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to load levels', 'data' => []], 500);
        }
    }

    // =========================================================================
    // ADMIN: list ALL levels including inactive
    // GET /admin/education-qualification-levels
    // =========================================================================
    public function adminIndex(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            if (!$this->isAdmin()) return $this->fail('Forbidden', 403);

            $model = new EducationQualificationLevel();
            $rows  = $model->allOrdered();

            return $this->respond([
                'success' => true,
                'message' => 'All education qualification levels',
                'data'    => array_map([$this, 'formatRow'], $rows),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'EducationQualificationLevels::adminIndex – ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed', 'data' => []], 500);
        }
    }

    // =========================================================================
    // ADMIN: create a new qualification level
    // POST /admin/education-qualification-levels
    // =========================================================================
    public function create(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            if (!$this->isAdmin()) return $this->fail('Forbidden', 403);

            $payload = $this->request->getJSON(true) ?? [];

            $key   = strtoupper(trim((string) ($payload['key']   ?? '')));
            $label = trim((string) ($payload['label']            ?? ''));

            if (!$key || !$label) {
                return $this->fail('key and label are required', 400);
            }

            // Sanitise key: only alphanumerics + underscore
            $key = preg_replace('/[^A-Z0-9_]/', '_', $key);

            $model = new EducationQualificationLevel();

            // Duplicate key check
            if ($model->where('key', $key)->first()) {
                return $this->fail("A level with key '{$key}' already exists.", 409);
            }

            // Determine next sortOrder
            $maxOrder = $model->selectMax('sortOrder')->first()['sortOrder'] ?? 0;

            $id = 'eql_' . uniqid();
            $now = date('Y-m-d H:i:s.') . substr((string) microtime(), 2, 3);

            $model->insert([
                'id'        => $id,
                'key'       => $key,
                'label'     => $label,
                'sortOrder' => (int) ($payload['sortOrder'] ?? ($maxOrder + 1)),
                'isActive'  => isset($payload['isActive']) ? (int) !!$payload['isActive'] : 1,
                'isSystem'  => 0, // admin-created rows are never system
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s'),
            ]);

            $created = $model->find($id);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Qualification level created',
                'data'    => $this->formatRow($created),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'EducationQualificationLevels::create – ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to create level', 'data' => []], 500);
        }
    }

    // =========================================================================
    // ADMIN: update a qualification level
    // PUT /admin/education-qualification-levels/:id
    // =========================================================================
    public function update($id = null): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            if (!$this->isAdmin()) return $this->fail('Forbidden', 403);
            if (!$id) return $this->fail('ID required', 400);

            $model = new EducationQualificationLevel();
            $row   = $model->find($id);
            if (!$row) return $this->failNotFound('Level not found');

            $payload = $this->request->getJSON(true) ?? [];
            $update  = ['updatedAt' => date('Y-m-d H:i:s')];

            // Label is always updatable
            if (isset($payload['label'])) {
                $update['label'] = trim((string) $payload['label']);
            }

            if (isset($payload['sortOrder'])) {
                $update['sortOrder'] = (int) $payload['sortOrder'];
            }

            if (isset($payload['isActive'])) {
                $update['isActive'] = (int) !!$payload['isActive'];
            }

            // Key is only updatable for non-system rows
            if (isset($payload['key']) && !(bool) $row['isSystem']) {
                $newKey = strtoupper(preg_replace('/[^A-Z0-9_]/', '_', trim((string) $payload['key'])));
                if ($newKey && $newKey !== $row['key']) {
                    if ($model->where('key', $newKey)->where('id !=', $id)->first()) {
                        return $this->fail("Key '{$newKey}' is already in use.", 409);
                    }
                    $update['key'] = $newKey;
                }
            }

            $model->update($id, $update);
            $updated = $model->find($id);

            return $this->respond([
                'success' => true,
                'message' => 'Qualification level updated',
                'data'    => $this->formatRow($updated),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'EducationQualificationLevels::update – ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to update level', 'data' => []], 500);
        }
    }

    // =========================================================================
    // ADMIN: delete a qualification level (non-system only)
    // DELETE /admin/education-qualification-levels/:id
    // =========================================================================
    public function destroy($id = null): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            if (!$this->isAdmin()) return $this->fail('Forbidden', 403);
            if (!$id) return $this->fail('ID required', 400);

            $model = new EducationQualificationLevel();
            $row   = $model->find($id);
            if (!$row) return $this->failNotFound('Level not found');

            if ((bool) $row['isSystem']) {
                return $this->fail('System qualification levels cannot be deleted. You may deactivate them instead.', 422);
            }

            $model->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Qualification level deleted',
                'data'    => [],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'EducationQualificationLevels::destroy – ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to delete level', 'data' => []], 500);
        }
    }

    // =========================================================================
    // ADMIN: bulk reorder
    // PUT /admin/education-qualification-levels/reorder
    // Body: { "order": ["id1", "id2", ...] }
    // =========================================================================
    public function reorder(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            if (!$this->isAdmin()) return $this->fail('Forbidden', 403);

            $payload = $this->request->getJSON(true) ?? [];
            $order   = $payload['order'] ?? [];

            if (!is_array($order) || empty($order)) {
                return $this->fail('order array is required', 400);
            }

            $model = new EducationQualificationLevel();
            $db    = \Config\Database::connect();

            $db->transStart();
            foreach ($order as $index => $levelId) {
                $model->update($levelId, [
                    'sortOrder' => $index + 1,
                    'updatedAt' => date('Y-m-d H:i:s'),
                ]);
            }
            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Reorder transaction failed', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Qualification levels reordered',
                'data'    => [],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'EducationQualificationLevels::reorder – ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to reorder', 'data' => []], 500);
        }
    }
}
