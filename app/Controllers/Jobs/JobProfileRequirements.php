<?php

namespace App\Controllers\Jobs;

use App\Controllers\BaseController;
use App\Models\Job;
use App\Models\JobProfileRequirement;
use App\Models\JobApplicationConfig;
use App\Traits\NormalizedResponseTrait;
use App\Libraries\ProfileRequirementKeys;

class JobProfileRequirements extends BaseController
{
    use NormalizedResponseTrait;

    // =========================================================
    // Upsert requirement keys + config
    // =========================================================
    public function upsert($jobId = null)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user)
                return $this->fail('Unauthorized', 401);
            if (!$jobId)
                return $this->fail('Job ID is required', 400);

            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job)
                return $this->failNotFound('Job not found');

            $role = $user->role ?? null;
            $isAdmin = in_array($role, ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR'], true);
            if (!$isAdmin && ($job['postedById'] ?? null) !== $user->id) {
                return $this->fail('Forbidden', 403);
            }

            $payload = $this->request->getJSON(true) ?? [];
            $keys = $payload['requirementKeys'] ?? [];

            if (!is_array($keys)) {
                return $this->fail('requirementKeys must be an array', 400);
            }

            $allowed = ProfileRequirementKeys::all();
            $keys = array_values(array_unique(array_filter($keys, fn($k) => in_array($k, $allowed, true))));

            $m = new JobProfileRequirement();
            $db = \Config\Database::connect();

            $db->transStart();

            $m->where('jobId', $jobId)->delete();
            foreach ($keys as $k) {
                $m->insert([
                    'id' => uniqid('jpr_'),
                    'jobId' => $jobId,
                    'requirementKey' => $k,
                    'isRequired' => 1,
                    'createdAt' => date('Y-m-d H:i:s'),
                ]);
            }

            if (isset($payload['config']) && is_array($payload['config'])) {
                $this->saveConfig($db, $jobId, $payload['config']);
            }

            $db->transComplete();
            if ($db->transStatus() === false) {
                return $this->fail('Failed to save requirements', 500);
            }

            $configData = $this->buildConfigResponse($jobId);

            return $this->respond([
                'success' => true,
                'message' => 'Job profile requirements saved',
                'data' => [
                    'jobId' => $jobId,
                    'requirementKeys' => $keys,
                    'config' => $configData,
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Upsert job profile requirements error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to save requirements', 'data' => []], 500);
        }
    }

    // =========================================================
    // Get requirement keys
    // =========================================================
    public function get($jobId = null)
    {
        try {
            if (!$jobId)
                return $this->fail('Job ID is required', 400);

            $m = new JobProfileRequirement();
            $rows = $m->where('jobId', $jobId)->where('isRequired', 1)->findAll();
            $keys = array_values(array_map(fn($r) => $r['requirementKey'], $rows));

            $configData = $this->buildConfigResponse($jobId);

            return $this->respond([
                'success' => true,
                'message' => 'Job profile requirements',
                'data' => [
                    'jobId' => $jobId,
                    'requirementKeys' => $keys,
                    'config' => $configData,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed', 'data' => []], 500);
        }
    }

    // =========================================================
    // Upsert job application config only
    // =========================================================
    public function upsertConfig($jobId = null)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user)
                return $this->fail('Unauthorized', 401);
            if (!$jobId)
                return $this->fail('Job ID is required', 400);

            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job)
                return $this->failNotFound('Job not found');

            $role = $user->role ?? null;
            $isAdmin = in_array($role, ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR'], true);
            if (!$isAdmin && ($job['postedById'] ?? null) !== $user->id) {
                return $this->fail('Forbidden', 403);
            }

            $payload = $this->request->getJSON(true) ?? [];
            $db = \Config\Database::connect();
            $this->saveConfig($db, $jobId, $payload);

            $configData = $this->buildConfigResponse($jobId);

            return $this->respond([
                'success' => true,
                'message' => 'Job application config saved',
                'data' => ['jobId' => $jobId, 'config' => $configData],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'upsertConfig error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to save config', 'data' => []], 500);
        }
    }

    // =========================================================
    // Get job application config only
    // =========================================================
    public function getConfig($jobId = null)
    {
        try {
            if (!$jobId)
                return $this->fail('Job ID is required', 400);

            $configData = $this->buildConfigResponse($jobId);

            return $this->respond([
                'success' => true,
                'message' => 'Job application config',
                'data' => ['jobId' => $jobId, 'config' => $configData],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'getConfig error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed', 'data' => []], 500);
        }
    }

    // =========================================================
    // PRIVATE: Save config to job_application_config
    // =========================================================
    private function saveConfig($db, string $jobId, array $data): void
    {
        $configModel = new JobApplicationConfig();
        $existing = $configModel->where('jobId', $jobId)->first();

        // ── Validate education levels (static + DB) ──────────────────────────
        $requiredEduLevels = [];
        if (!empty($data['requiredEducationLevels']) && is_array($data['requiredEducationLevels'])) {
            $requiredEduLevels = ProfileRequirementKeys::validateEducationLevels(
                $data['requiredEducationLevels']
            );
        }

        // ── Validate section order ────────────────────────────────────────────
        $validSectionKeys = ProfileRequirementKeys::sectionKeys();
        $sectionOrder = [];
        if (!empty($data['sectionOrder']) && is_array($data['sectionOrder'])) {
            $sectionOrder = array_values(array_filter(
                $data['sectionOrder'],
                fn($s) => in_array($s, $validSectionKeys, true)
            ));
        }
        foreach ($validSectionKeys as $key) {
            if (!in_array($key, $sectionOrder, true)) {
                $sectionOrder[] = $key;
            }
        }

        $payload = [
            'jobId' => $jobId,
            'refereesRequired' => (int) ($data['refereesRequired'] ?? 0),
            'requiredEducationLevels' => json_encode($requiredEduLevels),
            'generalExperienceText' => isset($data['generalExperienceText']) ? trim((string) $data['generalExperienceText']) : null,
            'specificExperienceText' => isset($data['specificExperienceText']) ? trim((string) $data['specificExperienceText']) : null,
            'showGeneralExperience' => (int) !!($data['showGeneralExperience'] ?? false),
            'showSpecificExperience' => (int) !!($data['showSpecificExperience'] ?? false),
            'sectionOrder' => json_encode($sectionOrder),
            'showDescription' => isset($data['showDescription']) ? (int) !!$data['showDescription'] : 1,
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $configModel->update($existing['id'], $payload);
        } else {
            $payload['id'] = uniqid('jac_');
            $payload['createdAt'] = date('Y-m-d H:i:s');
            $configModel->insert($payload);
        }
    }

    // =========================================================
    // PRIVATE: Build config response
    // =========================================================
    private function buildConfigResponse(string $jobId): array
    {
        $configModel = new JobApplicationConfig();
        $row = $configModel->where('jobId', $jobId)->first();

        if (!$row) {
            return [
                'refereesRequired' => 0,
                'requiredEducationLevels' => [],
                'generalExperienceText' => '',
                'specificExperienceText' => '',
                'showGeneralExperience' => false,
                'showSpecificExperience' => false,
                'sectionOrder' => ProfileRequirementKeys::defaultSectionOrder(),
                'showDescription' => true,
            ];
        }

        return [
            'refereesRequired' => (int) ($row['refereesRequired'] ?? 0),
            'requiredEducationLevels' => json_decode($row['requiredEducationLevels'] ?? '[]', true) ?: [],
            'generalExperienceText' => $row['generalExperienceText'] ?? '',
            'specificExperienceText' => $row['specificExperienceText'] ?? '',
            'showGeneralExperience' => (bool) ($row['showGeneralExperience'] ?? false),
            'showSpecificExperience' => (bool) ($row['showSpecificExperience'] ?? false),
            'sectionOrder' => json_decode($row['sectionOrder'] ?? 'null', true) ?: ProfileRequirementKeys::defaultSectionOrder(),
            'showDescription' => (bool) ($row['showDescription'] ?? true),
        ];
    }
}
