<?php

namespace App\Controllers\Jobs;

use App\Controllers\BaseController;
use App\Models\Job;
use App\Models\JobQuestion;
use App\Models\JobQuestionnaire as JobQuestionnaireModel;
use App\Traits\NormalizedResponseTrait;

class JobQuestionnaire extends BaseController
{
    use NormalizedResponseTrait;

    private function isUserAdmin(string $userId): bool
    {
        $userModel = new \App\Models\User();
        $userData = $userModel->select('role')->find($userId);
        return $userData && in_array($userData['role'], ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR'], true);
    }

    private function canManageJob(string $userId, array $job): bool
    {
        if (!empty($job['postedById']) && $job['postedById'] === $userId)
            return true;

        if ($this->isUserAdmin($userId))
            return true;

        if (!empty($job['companyId'])) {
            $employerModel = new \App\Models\EmployerProfile();
            $employer = $employerModel
                ->where('userId', $userId)
                ->where('companyId', $job['companyId'])
                ->first();
            return $employer !== null;
        }

        return false;
    }

    public function get(string $jobId)
    {
        try {
            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job)
                return $this->failNotFound('Job not found');

            $user = $this->request->user ?? null;
            $isManager = false;

            if ($user) {
                $isManager = $this->canManageJob($user->id, $job);
            }

            $qModel = new JobQuestionnaireModel();
            $questionnaire = $qModel->where('jobId', $jobId)->first();

            if (!$questionnaire) {
                return $this->respond([
                    'success' => true,
                    'data' => [
                        'enabled' => false,
                        'questionnaire' => null,
                        'questions' => [],
                    ],
                ]);
            }

            $active = (bool) ($questionnaire['isActive'] ?? false);

            // Candidates/public should not see inactive questionnaires
            if (!$active && !$isManager) {
                return $this->respond([
                    'success' => true,
                    'data' => [
                        'enabled' => false,
                        'questionnaire' => null,
                        'questions' => [],
                    ],
                ]);
            }

            $questions = (new JobQuestion())
                ->where('jobId', $jobId)
                ->orderBy('sortOrder', 'ASC')
                ->findAll();

            return $this->respond([
                'success' => true,
                'data' => [
                    'enabled' => $active,
                    'questionnaire' => [
                        'id' => $questionnaire['id'],
                        'jobId' => $questionnaire['jobId'],
                        'title' => $questionnaire['title'],
                        'description' => $questionnaire['description'] ?? null,
                        'isActive' => (bool) $questionnaire['isActive'],
                    ],
                    'questions' => array_map(function ($q) {
                        return [
                            'id' => $q['id'],
                            'jobId' => $q['jobId'],
                            'questionnaireId' => $q['questionnaireId'],
                            'questionText' => $q['questionText'],
                            'type' => $q['type'],
                            'isRequired' => (bool) $q['isRequired'],
                            'placeholder' => $q['placeholder'] ?? null,
                            'sortOrder' => (int) $q['sortOrder'],
                        ];
                    }, $questions),
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'JobQuestionnaire::get error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to load questionnaire', 'data' => []], 500);
        }
    }

    public function upsert(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user)
                return $this->fail('Unauthorized', 401);

            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job)
                return $this->failNotFound('Job not found');

            if (!$this->canManageJob($user->id, $job)) {
                return $this->fail('You do not have permission to manage this job', 403);
            }

            $payload = $this->request->getJSON(true) ?? [];
            $isActive = (bool) ($payload['isActive'] ?? false);
            $title = trim($payload['title'] ?? 'Screening Questions');
            $description = isset($payload['description']) ? trim((string) $payload['description']) : null;
            $questions = $payload['questions'] ?? [];

            if (!is_array($questions))
                $questions = [];

            // Basic validation
            foreach ($questions as $i => $q) {
                $qt = trim($q['questionText'] ?? '');
                if ($qt === '') {
                    return $this->fail(['message' => 'Validation failed', 'errors' => ["questions.$i.questionText" => "Question text is required"]], 400);
                }

                $type = $q['type'] ?? 'OPEN_ENDED';
                if (!in_array($type, ['YES_NO', 'OPEN_ENDED'], true)) {
                    return $this->fail(['message' => 'Validation failed', 'errors' => ["questions.$i.type" => "Invalid question type"]], 400);
                }
            }

            $db = \Config\Database::connect();
            $db->transStart();

            $qModel = new JobQuestionnaireModel();
            $existing = $qModel->where('jobId', $jobId)->first();

            $now = date('Y-m-d H:i:s');

            $questionnaireId = $existing['id'] ?? uniqid('jq_');

            if ($existing) {
                $qModel->update($existing['id'], [
                    'title' => $title,
                    'description' => $description ?: null,
                    'isActive' => (int) $isActive,
                    'updatedAt' => $now,
                ]);
            } else {
                $qModel->insert([
                    'id' => $questionnaireId,
                    'jobId' => $jobId,
                    'title' => $title,
                    'description' => $description ?: null,
                    'isActive' => (int) $isActive,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]);
            }

            // Replace questions (simple + reliable)
            $questionModel = new JobQuestion();
            $questionModel->where('jobId', $jobId)->delete();

            $sort = 0;
            foreach ($questions as $q) {
                $qid = $q['id'] ?? null;
                if (!$qid)
                    $qid = uniqid('jqst_');

                $questionModel->insert([
                    'id' => $qid,
                    'jobId' => $jobId,
                    'questionnaireId' => $questionnaireId,
                    'questionText' => trim($q['questionText']),
                    'type' => $q['type'] ?? 'OPEN_ENDED',
                    'isRequired' => (int) ($q['isRequired'] ?? 1),
                    'placeholder' => isset($q['placeholder']) ? trim((string) $q['placeholder']) : null,
                    'sortOrder' => (int) ($q['sortOrder'] ?? $sort),
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]);
                $sort++;
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->respond(['success' => false, 'message' => 'Failed to save questionnaire'], 500);
            }

            // Return fresh
            return $this->get($jobId);
        } catch (\Exception $e) {
            log_message('error', 'JobQuestionnaire::upsert error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to save questionnaire', 'data' => []], 500);
        }
    }
}
