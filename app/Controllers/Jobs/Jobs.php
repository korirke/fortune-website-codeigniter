<?php

namespace App\Controllers\Jobs;

use App\Controllers\BaseController;
use App\Models\Job;
use App\Models\JobCategory;
use App\Traits\NormalizedResponseTrait;

class Jobs extends BaseController
{
    use NormalizedResponseTrait;

    // =========================================================
    // Helpers
    // =========================================================

    private function validateJobData(array $data, bool $isDraft = false): array
    {
        $errors = [];

        // Required fields for non-draft
        if (!$isDraft) {
            if (empty($data['title']) || strlen(trim($data['title'])) < 3) {
                $errors['title'] = 'Job title is required and must be at least 3 characters';
            }

            if (empty($data['description']) || strlen(trim($data['description'])) < 50) {
                $errors['description'] = 'Job description is required and must be at least 50 characters';
            }

            if (empty($data['categoryId'])) {
                $errors['categoryId'] = 'Category is required';
            }

            if (empty($data['location'])) {
                $errors['location'] = 'Location is required';
            }

            // Salary validation
            if (isset($data['salaryType'])) {
                if ($data['salaryType'] === 'RANGE') {
                    if (empty($data['salaryMin']) || $data['salaryMin'] <= 0) {
                        $errors['salaryMin'] = 'Minimum salary must be greater than 0';
                    }
                    if (empty($data['salaryMax']) || $data['salaryMax'] <= 0) {
                        $errors['salaryMax'] = 'Maximum salary must be greater than 0';
                    }
                    if (!empty($data['salaryMin']) && !empty($data['salaryMax']) && $data['salaryMin'] >= $data['salaryMax']) {
                        $errors['salaryMax'] = 'Maximum salary must be greater than minimum salary';
                    }
                }

                if ($data['salaryType'] === 'SPECIFIC') {
                    if (empty($data['specificSalary']) || $data['specificSalary'] <= 0) {
                        $errors['specificSalary'] = 'Specific salary must be greater than 0';
                    }
                }
            }
        } else {
            // Minimal validation for draft
            if (empty($data['title'])) {
                $errors['title'] = 'Job title is required even for draft';
            }
        }

        // Expiry date validation
        if (!empty($data['expiresAt'])) {
            $expiryDate = strtotime($data['expiresAt']);
            $tomorrow = strtotime('tomorrow midnight');
            if ($expiryDate < $tomorrow) {
                $errors['expiresAt'] = 'Expiry date must be at least tomorrow';
            }
        }

        return $errors;
    }

    private function determineJobStatus(array $data, bool $isDraft): string
    {
        if ($isDraft) return 'DRAFT';

        $requiredFields = ['title', 'description', 'categoryId', 'location'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) return 'DRAFT';
        }

        if (!empty($data['description']) && strlen(trim($data['description'])) < 50) {
            return 'DRAFT';
        }

        return 'PENDING';
    }

    private function sanitizeUpdateData(array $data, array $allowedFields): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowedFields, true)) continue;
            $out[$key] = ($value === '' || $value === null) ? null : $value;
        }
        return $out;
    }

    private function isUserAdmin(string $userId): bool
    {
        $userModel = new \App\Models\User();
        $userData = $userModel->select('role')->find($userId);
        return $userData && in_array($userData['role'], ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR'], true);
    }

    private function canManageJob(string $userId, array $job): bool
    {
        // Owner
        if (!empty($job['postedById']) && $job['postedById'] === $userId) {
            return true;
        }

        // Admin
        if ($this->isUserAdmin($userId)) {
            return true;
        }

        // Company member
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

    /**
     * Find job by primary key OR slug.
     * Returns job row array or null.
     */
    private function findJobByIdOrSlug(string $idOrSlug): ?array
    {
        $jobModel = new Job();

        // Try ID
        $job = $jobModel->find($idOrSlug);
        if ($job) return $job;

        // Try slug
        $job = $jobModel->where('slug', $idOrSlug)->first();
        return $job ?: null;
    }

    private function getJobWithRelations(string $jobId): array
    {
        $jobModel = new Job();
        $job = $jobModel->find($jobId);

        if (!$job) return [];

        // Company
        $companyModel = new \App\Models\Company();
        $job['company'] = $companyModel
            ->select('id, name, slug, logo, location, description, website, industry, companySize, verified')
            ->find($job['companyId']);

        // Category
        if (!empty($job['categoryId'])) {
            $categoryModel = new JobCategory();
            $job['category'] = $categoryModel->find($job['categoryId']);
        } else {
            $job['category'] = null;
        }

        // Posted by
        $userModel = new \App\Models\User();
        $postedBy = $userModel->select('firstName, lastName, email')->find($job['postedById']);
        $job['postedBy'] = $postedBy ? [
            'firstName' => $postedBy['firstName'],
            'lastName'  => $postedBy['lastName'],
            'email'     => $postedBy['email'],
        ] : null;

        // Skills
        $jobSkillModel = new \App\Models\JobSkill();
        $jobSkills = $jobSkillModel->where('jobId', $jobId)->findAll();
        $skills = [];
        foreach ($jobSkills as $js) {
            $skillModel = new \App\Models\Skill();
            $skill = $skillModel->find($js['skillId']);
            if ($skill) {
                $skills[] = [
                    'id' => $js['id'],
                    'jobId' => $js['jobId'],
                    'skillId' => $js['skillId'],
                    'required' => (bool)($js['required'] ?? false),
                    'createdAt' => $js['createdAt'] ?? null,
                    'skill' => $skill,
                ];
            }
        }
        $job['skills'] = $skills;

        // Domains
        $jobDomainModel = new \App\Models\JobDomain();
        $jobDomains = $jobDomainModel->where('jobId', $jobId)->findAll();
        $domains = [];
        foreach ($jobDomains as $jd) {
            $domainModel = new \App\Models\Domain();
            $domain = $domainModel->find($jd['domainId']);
            if ($domain) {
                $domains[] = [
                    'id' => $jd['id'],
                    'jobId' => $jd['jobId'],
                    'domainId' => $jd['domainId'],
                    'createdAt' => $jd['createdAt'] ?? null,
                    'domain' => $domain,
                ];
            }
        }
        $job['domains'] = $domains;

        // Application count
        $applicationModel = new \App\Models\Application();
        $appCount = $applicationModel->where('jobId', $jobId)->countAllResults();
        $job['_count'] = ['applications' => $appCount];

        return $job;
    }

    private function logAudit(string $userId, string $action, string $resource, string $resourceId, array $details): void
    {
        try {
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $userId,
                'action' => $action,
                'resource' => $resource,
                'resourceId' => $resourceId,
                'module' => 'RECRUITMENT',
                'details' => json_encode($details),
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Audit log error: ' . $e->getMessage());
        }
    }

    // =========================================================
    // PUBLIC: GET JOB (by id OR slug)
    // - ACTIVE jobs are public
    // - Non-ACTIVE require logged-in owner/admin/company-member
    // =========================================================
    public function getJobById($idOrSlug = null)
    {
        try {
            if (!$idOrSlug) return $this->failNotFound('Job not found');

            $job = $this->findJobByIdOrSlug($idOrSlug);
            if (!$job) return $this->failNotFound('Job not found');

            $user = $this->request->user ?? null;

            // If not ACTIVE, require permission
            if (($job['status'] ?? null) !== 'ACTIVE') {
                if (!$user) return $this->failNotFound('Job not found');

                $isAdmin = $this->isUserAdmin($user->id);
                $canManage = $this->canManageJob($user->id, $job);

                if (!$isAdmin && !$canManage) {
                    return $this->failNotFound('Job not found');
                }
            }

            // Views increment only for ACTIVE
            if (($job['status'] ?? null) === 'ACTIVE') {
                $jobModel = new Job();
                $jobModel->update($job['id'], ['views' => (int)($job['views'] ?? 0) + 1]);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Job retrieved successfully',
                'data' => $this->getJobWithRelations($job['id']),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get job error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to retrieve job', 'data' => []], 500);
        }
    }

    // =========================================================
    // AUTH: GET JOB FOR EDIT/MANAGE
    // - This is what your edit page MUST call.
    // - Always behind auth filter so $request->user is present.
    // =========================================================
    public function getManageJobById($id = null)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            if (!$id) return $this->failNotFound('Job not found');

            $jobModel = new Job();
            $job = $jobModel->find($id);
            if (!$job) return $this->failNotFound('Job not found');

            if (!$this->canManageJob($user->id, $job)) {
                return $this->fail('You do not have permission to view this job', 403);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Job retrieved successfully',
                'data' => $this->getJobWithRelations($id),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get manage job error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to retrieve job', 'data' => []], 500);
        }
    }

    // =========================================================
    // CREATE JOB
    // =========================================================
    public function createJob()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $data = $this->request->getJSON(true) ?? [];

            $isDraft = isset($data['isDraft']) ? (bool)$data['isDraft'] : false;
            unset($data['isDraft']);

            $validationErrors = $this->validateJobData($data, $isDraft);
            if (!empty($validationErrors)) {
                return $this->fail(['message' => 'Validation failed', 'errors' => $validationErrors], 400);
            }

            $jobModel = new Job();

            // Determine role
            $userModel = new \App\Models\User();
            $userData = $userModel->select('role')->find($user->id);

            // Handle company ID
            $companyId = $data['companyId'] ?? null;

            if ($userData && ($userData['role'] ?? null) === 'EMPLOYER') {
                $employerModel = new \App\Models\EmployerProfile();
                $employer = $employerModel->where('userId', $user->id)->first();
                if (!$employer) {
                    return $this->fail('Employer profile not found. Please complete company setup.', 400);
                }
                $companyId = $employer['companyId'];
            }

            if (!$companyId) return $this->fail('Company ID is required', 400);

            // Verify company exists
            $companyModel = new \App\Models\Company();
            if (!$companyModel->find($companyId)) return $this->failNotFound('Company not found');

            // Verify category exists if provided
            if (!empty($data['categoryId'])) {
                $categoryModel = new JobCategory();
                if (!$categoryModel->find($data['categoryId'])) return $this->failNotFound('Category not found');
            }

            // Generate slug
            $baseSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $data['title'] ?? 'job'));
            $baseSlug = preg_replace('/^-|-$/', '', $baseSlug);
            $slug = $baseSlug . '-' . uniqid();

            // Determine status
            $status = $this->determineJobStatus($data, $isDraft);

            // Extract nested
            $skillIds = $data['skillIds'] ?? [];
            $domainIds = $data['domainIds'] ?? [];
            unset($data['skillIds'], $data['domainIds']);

            // Prepare insert
            $jobData = [
                'id' => uniqid('job_'),
                'title' => $data['title'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'responsibilities' => $data['responsibilities'] ?? null,
                'requirements' => $data['requirements'] ?? null,
                'benefits' => $data['benefits'] ?? null,
                'niceToHave' => $data['niceToHave'] ?? null,
                'type' => $data['type'] ?? 'FULL_TIME',
                'experienceLevel' => $data['experienceLevel'] ?? 'MID_LEVEL',
                'location' => $data['location'] ?? null,
                'isRemote' => $data['isRemote'] ?? false,
                'salaryType' => $data['salaryType'] ?? 'NEGOTIABLE',
                'salaryMin' => $data['salaryMin'] ?? null,
                'salaryMax' => $data['salaryMax'] ?? null,
                'specificSalary' => $data['specificSalary'] ?? null,
                'currency' => $data['currency'] ?? 'KSH',
                'companyId' => $companyId,
                'categoryId' => $data['categoryId'] ?? null,
                'postedById' => $user->id,
                'status' => $status,
                'views' => 0,
                'applicationCount' => 0,
                'featured' => false,
                'sponsored' => false,
                'expiresAt' => isset($data['expiresAt']) && $data['expiresAt']
                    ? date('Y-m-d H:i:s', strtotime($data['expiresAt']))
                    : null,
                'publishedAt' => null,
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s'),
            ];

            $jobModel->insert($jobData);

            // Create job skills
            if (!empty($skillIds)) {
                $jobSkillModel = new \App\Models\JobSkill();
                foreach ($skillIds as $skillId) {
                    $jobSkillModel->insert([
                        'id' => uniqid('jobskill_'),
                        'jobId' => $jobData['id'],
                        'skillId' => $skillId,
                        'required' => true,
                        'createdAt' => date('Y-m-d H:i:s')
                    ]);
                }
            }

            // Create job domains
            if (!empty($domainIds)) {
                $jobDomainModel = new \App\Models\JobDomain();
                foreach ($domainIds as $domainId) {
                    $jobDomainModel->insert([
                        'id' => uniqid('jobdomain_'),
                        'jobId' => $jobData['id'],
                        'domainId' => $domainId,
                        'createdAt' => date('Y-m-d H:i:s')
                    ]);
                }
            }

            $createdJob = $this->getJobWithRelations($jobData['id']);

            $this->logAudit($user->id, 'JOB_CREATED', 'Job', $jobData['id'], [
                'title' => $jobData['title'],
                'status' => $status
            ]);

            return $this->respondCreated([
                'success' => true,
                'message' => $status === 'DRAFT'
                    ? 'Job saved as draft successfully'
                    : 'Job submitted for approval successfully',
                'data' => $createdJob,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Create job error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to create job', 'data' => []], 500);
        }
    }

    // =========================================================
    // UPDATE JOB
    // =========================================================
    public function updateJob($id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $jobModel = new Job();
            $job = $jobModel->find($id);
            if (!$job) return $this->failNotFound('Job not found');

            // Permission
            if (!$this->canManageJob($user->id, $job)) {
                return $this->fail('You do not have permission to update this job', 403);
            }

            $data = $this->request->getJSON(true) ?? [];

            $isDraft = isset($data['isDraft']) ? (bool)$data['isDraft'] : false;
            unset($data['isDraft']);

            // Status edit restriction
            $editableStatuses = ['DRAFT', 'PENDING', 'REJECTED'];
            $isAdmin = $this->isUserAdmin($user->id);
            if (!$isAdmin && !in_array($job['status'], $editableStatuses, true)) {
                return $this->fail('Cannot edit jobs with status: ' . $job['status'], 403);
            }

            // Validate
            $validationErrors = $this->validateJobData($data, $isDraft);
            if (!empty($validationErrors)) {
                return $this->fail(['message' => 'Validation failed', 'errors' => $validationErrors], 400);
            }

            // Extract nested
            $skillIds = $data['skillIds'] ?? null;
            $domainIds = $data['domainIds'] ?? null;
            unset($data['skillIds'], $data['domainIds']);

            $allowedFields = [
                'title', 'description', 'responsibilities', 'requirements', 'benefits', 'niceToHave',
                'type', 'experienceLevel', 'location', 'isRemote',
                'salaryType', 'salaryMin', 'salaryMax', 'specificSalary', 'currency',
                'categoryId', 'expiresAt'
            ];

            $updateData = $this->sanitizeUpdateData($data, $allowedFields);

            // expiresAt normalization
            if (isset($updateData['expiresAt']) && $updateData['expiresAt'] !== null) {
                $updateData['expiresAt'] = date('Y-m-d H:i:s', strtotime($updateData['expiresAt']));
            }

            // Status transition logic
            if (in_array($job['status'], ['DRAFT', 'REJECTED'], true)) {
                if ($isDraft) {
                    $updateData['status'] = 'DRAFT';
                } else {
                    $updateData['status'] = $this->determineJobStatus($data, $isDraft);
                    if ($job['status'] === 'REJECTED') {
                        $updateData['rejectionReason'] = null;
                        $updateData['rejectedAt'] = null;
                    }
                }
            }

            $updateData['updatedAt'] = date('Y-m-d H:i:s');

            $jobModel->update($id, $updateData);

            // Update skills if provided
            if ($skillIds !== null) {
                $jobSkillModel = new \App\Models\JobSkill();
                $jobSkillModel->where('jobId', $id)->delete();
                foreach (($skillIds ?? []) as $skillId) {
                    $jobSkillModel->insert([
                        'id' => uniqid('jobskill_'),
                        'jobId' => $id,
                        'skillId' => $skillId,
                        'required' => true,
                        'createdAt' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            // Update domains if provided
            if ($domainIds !== null) {
                $jobDomainModel = new \App\Models\JobDomain();
                $jobDomainModel->where('jobId', $id)->delete();
                foreach (($domainIds ?? []) as $domainId) {
                    $jobDomainModel->insert([
                        'id' => uniqid('jobdomain_'),
                        'jobId' => $id,
                        'domainId' => $domainId,
                        'createdAt' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $updatedJob = $this->getJobWithRelations($id);

            $this->logAudit($user->id, 'JOB_UPDATED', 'Job', $id, [
                'changes' => array_keys($updateData),
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Job updated successfully',
                'data' => $updatedJob,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Update job error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to update job', 'data' => []], 500);
        }
    }

    // =========================================================
    // DELETE JOB
    // =========================================================
    public function deleteJob($id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $jobModel = new Job();
            $job = $jobModel->find($id);
            if (!$job) return $this->failNotFound('Job not found');

            if (!$this->canManageJob($user->id, $job)) {
                return $this->fail('You do not have permission to delete this job', 403);
            }

            (new \App\Models\JobSkill())->where('jobId', $id)->delete();
            (new \App\Models\JobDomain())->where('jobId', $id)->delete();

            $jobModel->delete($id);

            $this->logAudit($user->id, 'JOB_DELETED', 'Job', $id, ['title' => $job['title']]);

            return $this->respond([
                'success' => true,
                'message' => 'Job deleted successfully'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Delete job error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to delete job'], 500);
        }
    }

    // =========================================================
    // GET MY JOBS (auth)
    // =========================================================
    public function getMyJobs()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $jobModel = new Job();

            $userModel = new \App\Models\User();
            $userData = $userModel->select('role')->find($user->id);
            $role = $userData['role'] ?? null;

            if ($role === 'EMPLOYER') {
                $employerModel = new \App\Models\EmployerProfile();
                $employer = $employerModel->where('userId', $user->id)->first();
                if (!$employer) return $this->failNotFound('Employer profile not found');
                $jobModel->where('companyId', $employer['companyId']);
            } elseif (!in_array($role, ['SUPER_ADMIN', 'HR_MANAGER'], true)) {
                $jobModel->where('postedById', $user->id);
            }

            $jobs = $jobModel->orderBy('createdAt', 'DESC')->findAll();

            $formattedJobs = [];
            foreach ($jobs as $job) {
                $formattedJob = $job;

                $companyModel = new \App\Models\Company();
                $formattedJob['company'] = $companyModel->find($job['companyId']);

                if (!empty($job['categoryId'])) {
                    $categoryModel = new JobCategory();
                    $formattedJob['category'] = $categoryModel->find($job['categoryId']);
                } else {
                    $formattedJob['category'] = null;
                }

                $applicationModel = new \App\Models\Application();
                $appCount = $applicationModel->where('jobId', $job['id'])->countAllResults();
                $formattedJob['_count'] = ['applications' => $appCount];

                $formattedJobs[] = $formattedJob;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Jobs retrieved successfully',
                'data' => $formattedJobs,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get my jobs error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to retrieve jobs', 'data' => []], 500);
        }
    }

    // =========================================================
    // CHANGE STATUS (auth)
    // =========================================================
    public function changeJobStatus($id = null, $newStatus = null)
    {
        try {
            if (!$id || !$newStatus) {
                return $this->fail('Job ID and new status are required', 400);
            }

            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $jobModel = new Job();
            $job = $jobModel->find($id);
            if (!$job) return $this->failNotFound('Job not found');

            if (!$this->canManageJob($user->id, $job)) {
                return $this->fail('You do not have permission to change job status', 403);
            }

            $updateData = [
                'status' => $newStatus,
                'updatedAt' => date('Y-m-d H:i:s')
            ];

            if ($newStatus === 'CLOSED') {
                $updateData['closedAt'] = date('Y-m-d H:i:s');
            } elseif ($newStatus === 'ACTIVE') {
                if (empty($job['publishedAt'])) {
                    $updateData['publishedAt'] = date('Y-m-d H:i:s');
                }
            }

            $jobModel->update($id, $updateData);

            $updatedJob = $this->getJobWithRelations($id);

            $this->logAudit($user->id, 'JOB_STATUS_CHANGED', 'Job', $id, [
                'oldStatus' => $job['status'],
                'newStatus' => $newStatus,
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Job status updated successfully',
                'data' => $updatedJob,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Change status error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to update job status', 'data' => []], 500);
        }
    }

    // =========================================================
    // SEARCH JOBS (public)
    // =========================================================
    public function searchJobs()
    {
        try {
            $query = $this->request->getGet('query');
            $location = $this->request->getGet('location');
            $type = $this->request->getGet('type');
            $experienceLevel = $this->request->getGet('experienceLevel');
            $categoryId = $this->request->getGet('categoryId');
            $status = $this->request->getGet('status') ?? 'ACTIVE';

            $page = (int)($this->request->getGet('page') ?? 1);
            $limit = (int)($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;

            $db = \Config\Database::connect();
            $builder = $db->table('jobs');
            $builder->where('jobs.status', $status);

            if ($query) {
                $builder->join('companies', 'companies.id = jobs.companyId', 'left');
                $builder->groupStart()
                    ->like('jobs.title', $query)
                    ->orLike('jobs.description', $query)
                    ->orLike('companies.name', $query)
                    ->groupEnd();
            }

            if ($location) $builder->like('jobs.location', $location);
            if ($type) $builder->where('jobs.type', $type);
            if ($experienceLevel) $builder->where('jobs.experienceLevel', $experienceLevel);
            if ($categoryId) $builder->where('jobs.categoryId', $categoryId);

            $total = $builder->countAllResults(false);

            $jobs = $builder->select('jobs.*')
                ->orderBy('jobs.publishedAt', 'DESC')
                ->limit($limit, $skip)
                ->get()
                ->getResultArray();

            $formattedJobs = [];
            foreach ($jobs as $job) {
                $formattedJob = $job;

                $companyModel = new \App\Models\Company();
                $formattedJob['company'] = $companyModel->select('id, name, logo, location')->find($job['companyId']);

                if (!empty($job['categoryId'])) {
                    $categoryModel = new JobCategory();
                    $formattedJob['category'] = $categoryModel->find($job['categoryId']);
                } else {
                    $formattedJob['category'] = null;
                }

                $formattedJobs[] = $formattedJob;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Jobs retrieved successfully',
                'data' => [
                    'jobs' => $formattedJobs,
                    'pagination' => [
                        'total' => (int)$total,
                        'page' => (int)$page,
                        'limit' => (int)$limit,
                        'totalPages' => (int)ceil($total / $limit),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Search jobs error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to search jobs', 'data' => []], 500);
        }
    }

    // =========================================================
    // GET CATEGORIES (public)
    // =========================================================
    public function getCategories()
    {
        try {
            $categoryModel = new JobCategory();
            $categories = $categoryModel->where('isActive', true)->orderBy('sortOrder', 'ASC')->findAll();

            $jobModel = new Job();
            $formatted = [];

            foreach ($categories as $category) {
                $jobCount = $jobModel->where('categoryId', $category['id'])
                    ->where('status', 'ACTIVE')
                    ->countAllResults(false);

                $formatted[] = [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'description' => $category['description'] ?? null,
                    'icon' => $category['icon'] ?? null,
                    'isActive' => (bool)($category['isActive'] ?? true),
                    'jobCount' => (int)$jobCount,
                ];
            }

            return $this->respond([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $formatted,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get categories error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to retrieve categories', 'data' => []], 500);
        }
    }

    // =========================================================
    // GET NEWEST JOBS (public)
    // =========================================================
    public function getNewestJobs()
    {
        try {
            $limit = (int)($this->request->getGet('limit') ?? 8);

            $jobModel = new Job();
            $jobs = $jobModel->where('status', 'ACTIVE')
                ->where('publishedAt IS NOT NULL')
                ->orderBy('publishedAt', 'DESC')
                ->findAll($limit);

            $formattedJobs = [];
            foreach ($jobs as $job) {
                $formattedJob = $job;

                $companyModel = new \App\Models\Company();
                $formattedJob['company'] = $companyModel->select('id, name, slug, logo, location, verified')->find($job['companyId']);

                if (!empty($job['categoryId'])) {
                    $categoryModel = new JobCategory();
                    $formattedJob['category'] = $categoryModel->select('id, name, slug')->find($job['categoryId']);
                } else {
                    $formattedJob['category'] = null;
                }

                $formattedJobs[] = $formattedJob;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Newest jobs retrieved successfully',
                'data' => $formattedJobs,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get newest jobs error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to fetch newest jobs', 'data' => []], 500);
        }
    }

    // =========================================================
    // GET MODERATION QUEUE (auth/admin)
    // =========================================================
    public function getModerationQueue()
    {
        try {
            $jobModel = new Job();
            $jobs = $jobModel->where('status', 'PENDING')->orderBy('createdAt', 'ASC')->findAll();

            $formattedJobs = [];
            foreach ($jobs as $job) {
                $formattedJob = $job;

                $companyModel = new \App\Models\Company();
                $formattedJob['company'] = $companyModel->select('id, name, logo')->find($job['companyId']);

                if (!empty($job['categoryId'])) {
                    $categoryModel = new JobCategory();
                    $formattedJob['category'] = $categoryModel->find($job['categoryId']);
                } else {
                    $formattedJob['category'] = null;
                }

                $userModel = new \App\Models\User();
                $formattedJob['postedBy'] = $userModel->select('firstName, lastName, email')->find($job['postedById']);

                $formattedJobs[] = $formattedJob;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Moderation queue retrieved successfully',
                'data' => $formattedJobs,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get moderation queue error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to retrieve moderation queue', 'data' => []], 500);
        }
    }

    // =========================================================
    // MODERATE JOB (auth)
    // =========================================================
    public function moderateJob($id = null)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            if (!$id) return $this->fail('Job ID is required', 400);

            $data = $this->request->getJSON(true) ?? [];

            $jobModel = new Job();
            $job = $jobModel->find($id);
            if (!$job) return $this->failNotFound('Job not found');

            if ($job['status'] !== 'PENDING') {
                return $this->fail('Only pending jobs can be moderated', 400);
            }

            $status = $data['status'] ?? null;
            if (!$status) return $this->fail('Status is required', 400);

            $updateData = [
                'status' => $status,
                'updatedAt' => date('Y-m-d H:i:s'),
            ];

            if ($status === 'ACTIVE') {
                $updateData['publishedAt'] = date('Y-m-d H:i:s');
            } elseif ($status === 'REJECTED') {
                $updateData['rejectedAt'] = date('Y-m-d H:i:s');
                $updateData['rejectionReason'] = $data['rejectionReason'] ?? null;
            }

            $jobModel->update($id, $updateData);

            $updatedJob = $this->getJobWithRelations($id);

            $this->logAudit($user->id, $status === 'ACTIVE' ? 'JOB_APPROVED' : 'JOB_REJECTED', 'Job', $id, [
                'status' => $status,
                'rejectionReason' => $data['rejectionReason'] ?? null
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Job ' . ($status === 'ACTIVE' ? 'approved' : 'rejected') . ' successfully',
                'data' => $updatedJob
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Moderate job error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to moderate job', 'data' => []], 500);
        }
    }
}