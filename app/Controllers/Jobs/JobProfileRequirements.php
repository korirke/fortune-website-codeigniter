<?php

namespace App\Controllers\Jobs;

use App\Controllers\BaseController;
use App\Models\Job;
use App\Models\JobProfileRequirement;
use App\Traits\NormalizedResponseTrait;
use App\Libraries\ProfileRequirementKeys;

class JobProfileRequirements extends BaseController
{
    use NormalizedResponseTrait;

    public function upsert($jobId = null)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            if (!$jobId) return $this->fail('Job ID is required', 400);

            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job) return $this->failNotFound('Job not found');

            // Permission: reuse Jobs controller logic if you want; here minimal:
            // You should ideally call the same canManageJob() logic used in Jobs controller.
            // For now, enforce postedById = user OR admin roles.
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

            // Validate keys
            $allowed = ProfileRequirementKeys::all();
            $keys = array_values(array_unique(array_filter($keys, fn($k) => in_array($k, $allowed, true))));

            $m = new JobProfileRequirement();

            // Replace strategy (simple + safe)
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

            $db->transComplete();
            if ($db->transStatus() === false) {
                return $this->fail('Failed to save requirements', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Job profile requirements saved',
                'data' => [
                    'jobId' => $jobId,
                    'requirementKeys' => $keys,
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Upsert job profile requirements error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to save requirements', 'data' => []], 500);
        }
    }

    public function get($jobId = null)
    {
        try {
            if (!$jobId) return $this->fail('Job ID is required', 400);

            $m = new JobProfileRequirement();
            $rows = $m->where('jobId', $jobId)->where('isRequired', 1)->findAll();
            $keys = array_values(array_map(fn($r) => $r['requirementKey'], $rows));

            return $this->respond([
                'success' => true,
                'message' => 'Job profile requirements',
                'data' => [
                    'jobId' => $jobId,
                    'requirementKeys' => $keys,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed', 'data' => []], 500);
        }
    }
}
