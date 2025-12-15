<?php

namespace App\Controllers\Jobs;

use App\Controllers\BaseController;
use App\Models\Job;
use App\Models\JobCategory;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Jobs",
 *     description="Job management endpoints"
 * )
 */
class Jobs extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/jobs",
     *     tags={"Jobs"},
     *     summary="Create job posting",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "description", "type", "experienceLevel", "location", "salaryType", "companyId", "categoryId"},
     *             @OA\Property(property="title", type="string", description="Job title", example="Senior Software Engineer"),
     *             @OA\Property(property="description", type="string", description="Job description", example="We are looking for an experienced software engineer..."),
     *             @OA\Property(property="responsibilities", type="string", description="Job responsibilities", example="Develop and maintain web applications..."),
     *             @OA\Property(property="requirements", type="string", description="Job requirements", example="5+ years of experience in PHP..."),
     *             @OA\Property(property="benefits", type="string", description="Job benefits", example="Health insurance, flexible hours..."),
     *             @OA\Property(property="niceToHave", type="string", description="Nice to have skills", example="Experience with React..."),
     *             @OA\Property(property="type", type="string", enum={"FULL_TIME", "PART_TIME", "CONTRACT", "INTERNSHIP", "FREELANCE"}, description="Job type", example="FULL_TIME"),
     *             @OA\Property(property="experienceLevel", type="string", enum={"ENTRY", "JUNIOR", "MID", "SENIOR", "LEAD", "EXECUTIVE"}, description="Experience level", example="SENIOR"),
     *             @OA\Property(property="location", type="string", description="Job location", example="Nairobi, Kenya"),
     *             @OA\Property(property="isRemote", type="boolean", description="Remote work option", example=false),
     *             @OA\Property(property="salaryType", type="string", enum={"RANGE", "SPECIFIC", "NEGOTIABLE"}, description="Salary type", example="RANGE"),
     *             @OA\Property(property="salaryMin", type="number", description="Minimum salary (for RANGE type)", example=80000),
     *             @OA\Property(property="salaryMax", type="number", description="Maximum salary (for RANGE type)", example=120000),
     *             @OA\Property(property="specificSalary", type="number", description="Specific salary (for SPECIFIC type)", example=100000),
     *             @OA\Property(property="currency", type="string", description="Currency code", example="KES"),
     *             @OA\Property(property="companyId", type="string", description="Company ID", example="company_123"),
     *             @OA\Property(property="categoryId", type="string", description="Job category ID", example="category_123"),
     *             @OA\Property(property="expiresAt", type="string", format="date-time", description="Job expiration date", example="2025-12-31T23:59:59Z"),
     *             @OA\Property(property="skillIds", type="array", @OA\Items(type="string"), description="Required skill IDs", example={"skill_1", "skill_2"}),
     *             @OA\Property(property="domainIds", type="array", @OA\Items(type="string"), description="Related domain IDs", example={"domain_1"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Job created"
     *     )
     * )
     */
    public function createJob()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            $data = $this->request->getJSON(true);
            $jobModel = new Job();
            
            // Get user role
            $userModel = new \App\Models\User();
            $userData = $userModel->select('role')->find($user->id);
            
            // FOR EMPLOYERS: Auto-assign their company (matching Node.js)
            $companyId = $data['companyId'] ?? null;
            if ($userData && $userData['role'] === 'EMPLOYER') {
                $employerModel = new \App\Models\EmployerProfile();
                $employer = $employerModel->where('userId', $user->id)->first();
                if (!$employer) {
                    return $this->fail('Employer profile not found. Please complete company setup.', 400);
                }
                $companyId = $employer['companyId'];
            }
            
            if (!$companyId) {
                return $this->fail('Company ID is required', 400);
            }
            
            // Verify company exists
            $companyModel = new \App\Models\Company();
            $company = $companyModel->find($companyId);
            if (!$company) {
                return $this->failNotFound('Company not found');
            }
            
            // Verify category exists
            $categoryModel = new JobCategory();
            $category = $categoryModel->find($data['categoryId']);
            if (!$category) {
                return $this->failNotFound('Category not found');
            }
            
            // Generate slug from title (matching Node.js)
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $data['title']));
            $slug = preg_replace('/^-|-$/', '', $slug) . '-' . time();
            
            // Extract skillIds and domainIds
            $skillIds = $data['skillIds'] ?? [];
            $domainIds = $data['domainIds'] ?? [];
            unset($data['skillIds'], $data['domainIds']);
            
            // Create job
            $jobData = [
                'id' => uniqid('job_'),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'responsibilities' => $data['responsibilities'] ?? null,
                'requirements' => $data['requirements'] ?? null,
                'benefits' => $data['benefits'] ?? null,
                'niceToHave' => $data['niceToHave'] ?? null,
                'type' => $data['type'],
                'experienceLevel' => $data['experienceLevel'],
                'location' => $data['location'],
                'isRemote' => $data['isRemote'] ?? false,
                'salaryType' => $data['salaryType'],
                'salaryMin' => $data['salaryMin'] ?? null,
                'salaryMax' => $data['salaryMax'] ?? null,
                'specificSalary' => $data['specificSalary'] ?? null,
                'currency' => $data['currency'] ?? null,
                'companyId' => $companyId,
                'categoryId' => $data['categoryId'],
                'postedById' => $user->id,
                'slug' => $slug,
                'status' => 'DRAFT',
                'expiresAt' => isset($data['expiresAt']) ? date('Y-m-d H:i:s', strtotime($data['expiresAt'])) : null
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
                        'required' => true
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
                        'domainId' => $domainId
                    ]);
                }
            }
            
            // Get created job with all relations (matching Node.js)
            $createdJob = $jobModel->find($jobData['id']);
            $createdJob['company'] = $company;
            $createdJob['category'] = $category;
            
            // Get postedBy user
            $postedBy = $userModel->select('firstName, lastName, email')->find($user->id);
            $createdJob['postedBy'] = $postedBy;
            
            // Get skills
            if (!empty($skillIds)) {
                $jobSkillModel = new \App\Models\JobSkill();
                $jobSkills = $jobSkillModel->where('jobId', $jobData['id'])->findAll();
                $skills = [];
                foreach ($jobSkills as $js) {
                    $skillModel = new \App\Models\Skill();
                    $skill = $skillModel->find($js['skillId']);
                    if ($skill) {
                        $skills[] = ['skill' => $skill, 'required' => $js['required']];
                    }
                }
                $createdJob['skills'] = $skills;
            } else {
                $createdJob['skills'] = [];
            }
            
            // Get domains
            if (!empty($domainIds)) {
                $jobDomainModel = new \App\Models\JobDomain();
                $jobDomains = $jobDomainModel->where('jobId', $jobData['id'])->findAll();
                $domains = [];
                foreach ($jobDomains as $jd) {
                    $domainModel = new \App\Models\Domain();
                    $domain = $domainModel->find($jd['domainId']);
                    if ($domain) {
                        $domains[] = ['domain' => $domain];
                    }
                }
                $createdJob['domains'] = $domains;
            } else {
                $createdJob['domains'] = [];
            }
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $user->id,
                'action' => 'JOB_CREATED',
                'resource' => 'Job',
                'resourceId' => $jobData['id'],
                'module' => 'RECRUITMENT',
                'details' => json_encode(['title' => $jobData['title'], 'status' => $jobData['status']])
            ]);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Job created successfully',
                'data' => $createdJob
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to create job',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/{id}",
     *     tags={"Jobs"},
     *     summary="Get job details",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Job retrieved"
     *     )
     * )
     */
    public function getJobById($id = null)
    {
        try {
            // Extract ID from route parameter or URI segment
            if ($id === null) {
                $segments = $this->request->getUri()->getSegments();
                // Segments: [0]=api, [1]=jobs, [2]=id
                $id = $segments[2] ?? null;
            }
            
            if (!$id) {
                return $this->failNotFound('Job not found');
            }
            
            $jobModel = new Job();
            $job = $jobModel->find($id);
            
            if (!$job) {
                return $this->failNotFound('Job not found');
            }
            
            // Get user role if authenticated (matching Node.js)
            $userRole = null;
            $user = $this->request->user ?? null;
            if ($user) {
                $userModel = new \App\Models\User();
                $userData = $userModel->select('role')->find($user->id);
                $userRole = $userData['role'] ?? null;
            }
            
            // Only show non-active jobs to admins (matching Node.js)
            if ($job['status'] !== 'ACTIVE') {
                $isAdmin = $userRole && in_array($userRole, ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR']);
                if (!$isAdmin) {
                    return $this->failNotFound('Job not found');
                }
            }
            
            // Increment view count (matching Node.js - happens before return)
            $currentViews = (int) ($job['views'] ?? 0);
            $jobModel->update($id, ['views' => $currentViews + 1]);
            
            // Refetch job to get updated view count in response
            $job = $jobModel->find($id);
            
            // Format job with all nested data (matching Node.js)
            $companyModel = new \App\Models\Company();
            $company = $companyModel->select('id, name, slug, logo, description, website, location, industry, companySize')
                ->find($job['companyId']);
            
            $categoryModel = new JobCategory();
            $category = $categoryModel->find($job['categoryId']);
            
            $userModel = new \App\Models\User();
            $postedBy = $userModel->select('firstName, lastName')
                ->find($job['postedById']);
            
            // Get skills with skill nested (matching Node.js structure)
            $jobSkillModel = new \App\Models\JobSkill();
            $jobSkills = $jobSkillModel->where('jobId', $job['id'])->findAll();
            $skills = [];
            foreach ($jobSkills as $js) {
                $skillModel = new \App\Models\Skill();
                $skill = $skillModel->find($js['skillId']);
                if ($skill) {
                    // Match Node.js structure: JobSkill with nested skill object
                    $skills[] = [
                        'id' => $js['id'],
                        'jobId' => $js['jobId'],
                        'skillId' => $js['skillId'],
                        'required' => (bool) ($js['required'] ?? false),
                        'createdAt' => $js['createdAt'] ?? null,
                        'skill' => $skill
                    ];
                }
            }
            
            // Get domains with domain nested (matching Node.js structure)
            $jobDomainModel = new \App\Models\JobDomain();
            $jobDomains = $jobDomainModel->where('jobId', $job['id'])->findAll();
            $domains = [];
            foreach ($jobDomains as $jd) {
                $domainModel = new \App\Models\Domain();
                $domain = $domainModel->find($jd['domainId']);
                if ($domain) {
                    // Match Node.js structure: JobDomain with nested domain object
                    $domains[] = [
                        'id' => $jd['id'],
                        'jobId' => $jd['jobId'],
                        'domainId' => $jd['domainId'],
                        'createdAt' => $jd['createdAt'] ?? null,
                        'domain' => $domain
                    ];
                }
            }
            
            // Build response matching Node.js structure exactly
            $formattedJob = $job;
            $formattedJob['company'] = $company;
            $formattedJob['category'] = $category;
            $formattedJob['postedBy'] = $postedBy ? [
                'firstName' => $postedBy['firstName'],
                'lastName' => $postedBy['lastName']
            ] : null;
            $formattedJob['skills'] = $skills;
            $formattedJob['domains'] = $domains;
            
            return $this->respond([
                'success' => true,
                'message' => 'Job retrieved successfully',
                'data' => $formattedJob
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get job by ID error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve job',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/jobs/{id}",
     *     tags={"Jobs"},
     *     summary="Update job",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Job updated"
     *     )
     * )
     */
    public function updateJob($id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            $data = $this->request->getJSON(true);
            $jobModel = new Job();
            
            // Get job with company (matching Node.js)
            $job = $jobModel->find($id);
            if (!$job) {
                return $this->failNotFound('Job not found');
            }
            
            // Authorization check (matching Node.js)
            $isJobOwner = $job['postedById'] === $user->id;
            $isAdmin = false;
            
            $userModel = new \App\Models\User();
            $userData = $userModel->select('role')->find($user->id);
            if ($userData && in_array($userData['role'], ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR'])) {
                $isAdmin = true;
            }
            
            // Check if user is company member
            $isCompanyMember = false;
            if ($job['companyId']) {
                $employerModel = new \App\Models\EmployerProfile();
                $employer = $employerModel->where('userId', $user->id)
                    ->where('companyId', $job['companyId'])
                    ->first();
                $isCompanyMember = $employer !== null;
            }
            
            if (!$isJobOwner && !$isCompanyMember && !$isAdmin) {
                return $this->fail('You do not have permission to update this job', 403);
            }
            
            // Extract skillIds and domainIds if present
            $skillIds = $data['skillIds'] ?? null;
            $domainIds = $data['domainIds'] ?? null;
            unset($data['skillIds'], $data['domainIds']);
            
            // Update job
            $updateData = $data;
            if (isset($updateData['expiresAt'])) {
                $updateData['expiresAt'] = date('Y-m-d H:i:s', strtotime($updateData['expiresAt']));
            }
            $jobModel->update($id, $updateData);
            
            // Update skills if provided
            if ($skillIds !== null) {
                $jobSkillModel = new \App\Models\JobSkill();
                $jobSkillModel->where('jobId', $id)->delete();
                foreach ($skillIds as $skillId) {
                    $jobSkillModel->insert([
                        'id' => uniqid('jobskill_'),
                        'jobId' => $id,
                        'skillId' => $skillId,
                        'required' => true
                    ]);
                }
            }
            
            // Update domains if provided
            if ($domainIds !== null) {
                $jobDomainModel = new \App\Models\JobDomain();
                $jobDomainModel->where('jobId', $id)->delete();
                foreach ($domainIds as $domainId) {
                    $jobDomainModel->insert([
                        'id' => uniqid('jobdomain_'),
                        'jobId' => $id,
                        'domainId' => $domainId
                    ]);
                }
            }
            
            // Get updated job with all relations (matching Node.js)
            $updatedJob = $jobModel->find($id);
            $companyModel = new \App\Models\Company();
            $updatedJob['company'] = $companyModel->find($updatedJob['companyId']);
            $categoryModel = new JobCategory();
            $updatedJob['category'] = $categoryModel->find($updatedJob['categoryId']);
            
            // Get skills
            $jobSkillModel = new \App\Models\JobSkill();
            $jobSkills = $jobSkillModel->where('jobId', $id)->findAll();
            $skills = [];
            foreach ($jobSkills as $js) {
                $skillModel = new \App\Models\Skill();
                $skill = $skillModel->find($js['skillId']);
                if ($skill) {
                    $skills[] = ['skill' => $skill, 'required' => $js['required']];
                }
            }
            $updatedJob['skills'] = $skills;
            
            // Get domains
            $jobDomainModel = new \App\Models\JobDomain();
            $jobDomains = $jobDomainModel->where('jobId', $id)->findAll();
            $domains = [];
            foreach ($jobDomains as $jd) {
                $domainModel = new \App\Models\Domain();
                $domain = $domainModel->find($jd['domainId']);
                if ($domain) {
                    $domains[] = ['domain' => $domain];
                }
            }
            $updatedJob['domains'] = $domains;
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $user->id,
                'action' => 'JOB_UPDATED',
                'resource' => 'Job',
                'resourceId' => $id,
                'module' => 'RECRUITMENT',
                'details' => json_encode(['changes' => $data])
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Job updated successfully',
                'data' => $updatedJob
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update job',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/jobs/{id}",
     *     tags={"Jobs"},
     *     summary="Delete job",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Job deleted"
     *     )
     * )
     */
    public function deleteJob($id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            $jobModel = new Job();
            $job = $jobModel->find($id);
            
            if (!$job) {
                return $this->failNotFound('Job not found');
            }
            
            // Authorization check (matching Node.js)
            $isJobOwner = $job['postedById'] === $user->id;
            $isAdmin = false;
            
            $userModel = new \App\Models\User();
            $userData = $userModel->select('role')->find($user->id);
            if ($userData && in_array($userData['role'], ['SUPER_ADMIN', 'HR_MANAGER'])) {
                $isAdmin = true;
            }
            
            // Check if user is company member
            $isCompanyMember = false;
            if ($job['companyId']) {
                $employerModel = new \App\Models\EmployerProfile();
                $employer = $employerModel->where('userId', $user->id)
                    ->where('companyId', $job['companyId'])
                    ->first();
                $isCompanyMember = $employer !== null;
            }
            
            if (!$isJobOwner && !$isCompanyMember && !$isAdmin) {
                return $this->fail('You do not have permission to delete this job', 403);
            }
            
            $jobModel->delete($id);
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $user->id,
                'action' => 'JOB_DELETED',
                'resource' => 'Job',
                'resourceId' => $id,
                'module' => 'RECRUITMENT',
                'details' => json_encode(['title' => $job['title']])
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Job deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete job',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/employer/my-jobs",
     *     tags={"Jobs"},
     *     summary="Get my jobs",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"DRAFT", "PENDING", "ACTIVE", "REJECTED", "CLOSED"}), description="Filter by status"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(
     *         response=200,
     *         description="Jobs retrieved"
     *     )
     * )
     */
    public function getMyJobs()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            $jobModel = new Job();
            
            // Get user role (matching Node.js)
            $userModel = new \App\Models\User();
            $userData = $userModel->select('role')->find($user->id);
            $userRole = $userData['role'] ?? null;
            
            // Build where clause (matching Node.js)
            if ($userRole === 'EMPLOYER') {
                // Get employer's company
                $employerModel = new \App\Models\EmployerProfile();
                $employer = $employerModel->where('userId', $user->id)->first();
                if (!$employer) {
                    return $this->failNotFound('Employer profile not found');
                }
                $jobModel->where('companyId', $employer['companyId']);
            } elseif (in_array($userRole, ['SUPER_ADMIN', 'HR_MANAGER'])) {
                // Admins see all jobs - no filter
            } else {
                $jobModel->where('postedById', $user->id);
            }
            
            // Node.js doesn't paginate this endpoint - returns all jobs
            $jobs = $jobModel
                ->orderBy('createdAt', 'DESC')
                ->findAll();
            
            // Format jobs with company, category, _count.applications (matching Node.js)
            $companyModel = new \App\Models\Company();
            $categoryModel = new JobCategory();
            $applicationModel = new \App\Models\Application();
            
            $formattedJobs = [];
            foreach ($jobs as $job) {
                $formattedJob = $job;
                
                // Get company (full object, matching Node.js)
                $company = $companyModel->find($job['companyId']);
                $formattedJob['company'] = $company;
                
                // Get category (full object, matching Node.js)
                $category = $categoryModel->find($job['categoryId']);
                $formattedJob['category'] = $category;
                
                // Get application count (matching Node.js _count structure)
                $appCount = $applicationModel->where('jobId', $job['id'])->countAllResults(false);
                $formattedJob['_count'] = [
                    'applications' => $appCount
                ];
                
                $formattedJobs[] = $formattedJob;
            }

            // Node.js returns: { success: true, message: "...", data: jobs[] }
            // No pagination - returns all jobs directly
            return $this->respond([
                'success' => true,
                'message' => 'Jobs retrieved successfully',
                'data' => $formattedJobs
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve jobs',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/admin/moderation-queue",
     *     tags={"Jobs"},
     *     summary="Get moderation queue",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(
     *         response=200,
     *         description="Queue retrieved"
     *     )
     * )
     */
    public function getModerationQueue()
    {
        try {
            $jobModel = new Job();
            
            // Node.js doesn't paginate this endpoint - returns all pending jobs
            $jobs = $jobModel->where('status', 'PENDING')
                ->orderBy('createdAt', 'ASC')
                ->findAll();
            
            // Format jobs with company, category, postedBy (matching Node.js)
            $companyModel = new \App\Models\Company();
            $categoryModel = new JobCategory();
            $userModel = new \App\Models\User();
            
            $formattedJobs = [];
            foreach ($jobs as $job) {
                $formattedJob = $job;
                
                // Get company
                $company = $companyModel->select('id, name, logo')
                    ->find($job['companyId']);
                $formattedJob['company'] = $company;
                
                // Get category
                $category = $categoryModel->find($job['categoryId']);
                $formattedJob['category'] = $category;
                
                // Get postedBy
                $postedBy = $userModel->select('firstName, lastName, email')
                    ->find($job['postedById']);
                $formattedJob['postedBy'] = $postedBy;
                
                $formattedJobs[] = $formattedJob;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Moderation queue retrieved successfully',
                'data' => $formattedJobs
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve moderation queue',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/jobs/{id}/moderate",
     *     tags={"Jobs"},
     *     summary="Moderate job (approve/reject)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"ACTIVE", "REJECTED", "PENDING"}, description="New status", example="ACTIVE"),
     *             @OA\Property(property="rejectionReason", type="string", description="Rejection reason (required if status is REJECTED)", example="Does not meet requirements")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Job moderated"
     *     )
     * )
     */
    public function moderateJob($id = null)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }
            
            // Extract ID from route parameter or URI segment
            // Route: /api/jobs/:id/moderate
            // If $id is null, extract from URI segment 3 (api/jobs/{id}/moderate)
            if ($id === null) {
                $segments = $this->request->getUri()->getSegments();
                // Segments: [0]=api, [1]=jobs, [2]=id, [3]=moderate
                $id = $segments[2] ?? null;
            }
            
            if (!$id) {
                return $this->fail('Job ID is required', 400);
            }
            
            $data = $this->request->getJSON(true);
            $jobModel = new Job();
            
            $job = $jobModel->find($id);
            if (!$job) {
                return $this->failNotFound('Job not found');
            }
            
            if ($job['status'] !== 'PENDING') {
                return $this->fail('Only pending jobs can be moderated', 400);
            }
            
            $status = $data['status'];
            $updateData = ['status' => $status];
            
            if ($status === 'ACTIVE') {
                $updateData['publishedAt'] = date('Y-m-d H:i:s');
            } elseif ($status === 'REJECTED') {
                $updateData['rejectedAt'] = date('Y-m-d H:i:s');
                $updateData['rejectionReason'] = $data['rejectionReason'] ?? null;
            }
            
            $jobModel->update($id, $updateData);
            
            // Get updated job with company and category (matching Node.js)
            $updatedJob = $jobModel->find($id);
            $companyModel = new \App\Models\Company();
            $updatedJob['company'] = $companyModel->find($updatedJob['companyId']);
            $categoryModel = new JobCategory();
            $updatedJob['category'] = $categoryModel->find($updatedJob['categoryId']);
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $action = $status === 'ACTIVE' ? 'JOB_APPROVED' : 'JOB_REJECTED';
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $user->id,
                'action' => $action,
                'resource' => 'Job',
                'resourceId' => $id,
                'module' => 'RECRUITMENT',
                'details' => json_encode(['status' => $status, 'rejectionReason' => $data['rejectionReason'] ?? null])
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Job ' . ($status === 'ACTIVE' ? 'approved' : 'rejected') . ' successfully',
                'data' => $updatedJob
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to moderate job',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/jobs/{id}/status/{newStatus}",
     *     tags={"Jobs"},
     *     summary="Change job status",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="newStatus", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Status updated"
     *     )
     * )
     */
    public function changeJobStatus($id = null, $newStatus = null)
    {
        try {
            // Extract parameters from route or URI segments
            // Route: /api/jobs/:id/status/:newStatus
            // Segments: [0]=api, [1]=jobs, [2]=id, [3]=status, [4]=newStatus
            if ($id === null || $newStatus === null) {
                $segments = $this->request->getUri()->getSegments();
                $id = $id ?? $segments[2] ?? null;
                $newStatus = $newStatus ?? $segments[4] ?? null;
            }
            
            if (!$id || !$newStatus) {
                return $this->fail('Job ID and new status are required', 400);
            }
            
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }
            
            $jobModel = new Job();
            $job = $jobModel->find($id);
            
            if (!$job) {
                return $this->failNotFound('Job not found');
            }
            
            // Authorization check (matching Node.js)
            $isJobOwner = $job['postedById'] === $user->id;
            $isAdmin = false;
            
            $userModel = new \App\Models\User();
            $userData = $userModel->select('role')->find($user->id);
            if ($userData && in_array($userData['role'], ['SUPER_ADMIN', 'HR_MANAGER'])) {
                $isAdmin = true;
            }
            
            // Check if user is company member
            $isCompanyMember = false;
            if ($job['companyId']) {
                $employerModel = new \App\Models\EmployerProfile();
                $employer = $employerModel->where('userId', $user->id)
                    ->where('companyId', $job['companyId'])
                    ->first();
                $isCompanyMember = $employer !== null;
            }
            
            if (!$isJobOwner && !$isCompanyMember && !$isAdmin) {
                return $this->fail('You do not have permission to change job status', 403);
            }
            
            $updateData = ['status' => $newStatus];
            if ($newStatus === 'CLOSED') {
                $updateData['closedAt'] = date('Y-m-d H:i:s');
            }
            
            $jobModel->update($id, $updateData);
            
            // Get updated job with company and category (matching Node.js)
            $updatedJob = $jobModel->find($id);
            $companyModel = new \App\Models\Company();
            $updatedJob['company'] = $companyModel->find($updatedJob['companyId']);
            $categoryModel = new JobCategory();
            $updatedJob['category'] = $categoryModel->find($updatedJob['categoryId']);

            return $this->respond([
                'success' => true,
                'message' => 'Job status updated successfully',
                'data' => $updatedJob
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update job status',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/admin/all",
     *     tags={"Jobs"},
     *     summary="Get all jobs (admin)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"DRAFT", "PENDING", "ACTIVE", "REJECTED", "CLOSED"}), description="Filter by status"),
     *     @OA\Parameter(name="companyId", in="query", required=false, @OA\Schema(type="string"), description="Filter by company ID"),
     *     @OA\Parameter(name="categoryId", in="query", required=false, @OA\Schema(type="string"), description="Filter by category ID"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(
     *         response=200,
     *         description="Jobs retrieved"
     *     )
     * )
     */
    public function getAllJobsAdmin()
    {
        try {
            $jobModel = new Job();
            
            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;
            
            // Get filters
            $status = $this->request->getGet('status');
            $companyId = $this->request->getGet('companyId');
            $categoryId = $this->request->getGet('categoryId');
            
            if ($status) {
                $jobModel->where('status', $status);
            }
            if ($companyId) {
                $jobModel->where('companyId', $companyId);
            }
            if ($categoryId) {
                $jobModel->where('categoryId', $categoryId);
            }
            
            // Get total count
            $total = $jobModel->countAllResults(false);
            
            // Get paginated results
            $jobs = $jobModel
                ->orderBy('createdAt', 'DESC')
                ->findAll($limit, $skip);

            return $this->respond($this->paginatedResponse(
                $jobs ?: [],
                $total,
                $page,
                $limit,
                'Jobs retrieved successfully',
                'jobs'
            ));
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve jobs',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/jobs/admin/bulk-status",
     *     tags={"Jobs"},
     *     summary="Bulk update job status",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"jobIds", "newStatus"},
     *             @OA\Property(property="jobIds", type="array", @OA\Items(type="string"), description="Array of job IDs", example={"job_1", "job_2"}),
     *             @OA\Property(property="newStatus", type="string", enum={"DRAFT", "PENDING", "ACTIVE", "REJECTED", "CLOSED"}, description="New status for all jobs", example="ACTIVE")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Jobs updated"
     *     )
     * )
     */
    public function bulkUpdateStatus()
    {
        $data = $this->request->getJSON(true);
        $jobModel = new Job();
        
        foreach ($data['jobIds'] as $jobId) {
            $jobModel->update($jobId, ['status' => $data['newStatus']]);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Jobs updated successfully'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/search",
     *     tags={"Jobs"},
     *     summary="Search jobs (public)",
     *     @OA\Parameter(name="query", in="query", required=false, @OA\Schema(type="string"), description="Search query"),
     *     @OA\Parameter(name="location", in="query", required=false, @OA\Schema(type="string"), description="Location filter"),
     *     @OA\Parameter(name="type", in="query", required=false, @OA\Schema(type="string"), description="Job type filter"),
     *     @OA\Parameter(name="experienceLevel", in="query", required=false, @OA\Schema(type="string"), description="Experience level filter"),
     *     @OA\Parameter(name="categoryId", in="query", required=false, @OA\Schema(type="string"), description="Category ID filter"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=20),
     *     @OA\Response(
     *         response=200,
     *         description="Jobs retrieved"
     *     )
     * )
     */
    public function searchJobs()
    {
        try {
            $jobModel = new Job();
            
            // Get query parameters
            $query = $this->request->getGet('query');
            $location = $this->request->getGet('location');
            $type = $this->request->getGet('type');
            $experienceLevel = $this->request->getGet('experienceLevel');
            $categoryId = $this->request->getGet('categoryId');
            $status = $this->request->getGet('status') ?? 'ACTIVE';
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;
            
            // Use Query Builder for complex queries with joins (matching Node.js)
            $db = \Config\Database::connect();
            $builder = $db->table('jobs');
            
            // Build where clause (matching Node.js)
            $builder->where('jobs.status', $status);
            
            if ($query) {
                $builder->groupStart()
                    ->like('jobs.title', $query)
                    ->orLike('jobs.description', $query)
                    ->groupEnd();
                
                // Also search in company name - need to join companies table
                $builder->orGroupStart()
                    ->join('companies', 'companies.id = jobs.companyId', 'left')
                    ->like('companies.name', $query)
                    ->groupEnd();
            }
            
            if ($location) {
                $builder->like('jobs.location', $location);
            }
            
            if ($type) {
                $builder->where('jobs.type', $type);
            }
            
            if ($experienceLevel) {
                $builder->where('jobs.experienceLevel', $experienceLevel);
            }
            
            if ($categoryId) {
                $builder->where('jobs.categoryId', $categoryId);
            }
            
            // Get total count
            $total = $builder->countAllResults(false);
            
            // Rebuild query for data fetch
            $builder = $db->table('jobs');
            
            if ($query) {
                $builder->join('companies', 'companies.id = jobs.companyId', 'left');
                $builder->groupStart()
                    ->like('jobs.title', $query)
                    ->orLike('jobs.description', $query)
                    ->orLike('companies.name', $query)
                    ->groupEnd();
            }
            
            $builder->where('jobs.status', $status);
            
            if ($location) {
                $builder->like('jobs.location', $location);
            }
            
            if ($type) {
                $builder->where('jobs.type', $type);
            }
            
            if ($experienceLevel) {
                $builder->where('jobs.experienceLevel', $experienceLevel);
            }
            
            if ($categoryId) {
                $builder->where('jobs.categoryId', $categoryId);
            }
            
            // Get paginated results (matching Node.js)
            $jobs = $builder->select('jobs.*')
                ->orderBy('jobs.publishedAt', 'DESC')
                ->limit($limit, $skip)
                ->get()
                ->getResultArray();
            
            // Format jobs with company and category (matching Node.js)
            $companyModel = new \App\Models\Company();
            $categoryModel = new JobCategory();
            
            $formattedJobs = [];
            foreach ($jobs as $job) {
                $formattedJob = $job;
                
                // Get company with selected fields (matching Node.js)
                $company = $companyModel->select('id, name, logo, location')
                    ->find($job['companyId']);
                $formattedJob['company'] = $company;
                
                // Get category (full object, matching Node.js)
                $category = $categoryModel->find($job['categoryId']);
                $formattedJob['category'] = $category;
                
                $formattedJobs[] = $formattedJob;
            }
            
            // Node.js returns { success: true, message: '...', data: { jobs: [], pagination: {...} } }
            return $this->respond([
                'success' => true,
                'message' => 'Jobs retrieved successfully',
                'data' => [
                    'jobs' => $formattedJobs,
                    'pagination' => [
                        'total' => (int) $total,
                        'page' => (int) $page,
                        'limit' => (int) $limit,
                        'totalPages' => (int) ceil($total / $limit)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Search jobs error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to search jobs',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/categories",
     *     tags={"Jobs"},
     *     summary="Get job categories",
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved"
     *     )
     * )
     */
    public function getCategories()
    {
        try {
            $categoryModel = new JobCategory();
            
            // Get active categories ordered by sortOrder (matching Node.js)
            $categories = $categoryModel->where('isActive', true)
                ->orderBy('sortOrder', 'ASC')
                ->findAll();
            
            // Format categories with job count (matching Node.js)
            $jobModel = new Job();
            $formattedCategories = [];
            
            foreach ($categories as $category) {
                // Get count of active jobs in this category (matching Node.js _count.jobs)
                $jobCount = $jobModel->where('categoryId', $category['id'])
                    ->where('status', 'ACTIVE')
                    ->countAllResults(false);
                
                $formattedCategories[] = [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'description' => $category['description'] ?? null,
                    'icon' => $category['icon'] ?? null,
                    'isActive' => (bool) ($category['isActive'] ?? true),
                    'jobCount' => (int) $jobCount
                ];
            }
            
            // Node.js returns { success: true, message: '...', data: categories[] }
            return $this->respond([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $formattedCategories
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get categories error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/newest",
     *     tags={"Jobs"},
     *     summary="Get newest jobs (public)",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Number of jobs to return", example=8),
     *     @OA\Response(
     *         response=200,
     *         description="Jobs retrieved"
     *     )
     * )
     */
    public function getNewestJobs()
    {
        try {
            $limit = (int) ($this->request->getGet('limit') ?? 8);
            
            $jobModel = new Job();
            
            // Get active jobs with publishedAt not null (matching Node.js)
            $jobs = $jobModel->where('status', 'ACTIVE')
                ->where('publishedAt IS NOT NULL')
                ->orderBy('publishedAt', 'DESC')
                ->findAll($limit);
            
            // Format jobs with company, category, and skills (matching Node.js)
            $companyModel = new \App\Models\Company();
            $categoryModel = new JobCategory();
            $jobSkillModel = new \App\Models\JobSkill();
            
            $formattedJobs = [];
            foreach ($jobs as $job) {
                $formattedJob = $job;
                
                // Get company with selected fields (matching Node.js)
                $company = $companyModel->select('id, name, slug, logo, location, verified')
                    ->find($job['companyId']);
                $formattedJob['company'] = $company;
                
                // Get category with selected fields (matching Node.js)
                $category = $categoryModel->select('id, name, slug')
                    ->find($job['categoryId']);
                $formattedJob['category'] = $category;
                
                // Get skills (limit 3, matching Node.js)
                $jobSkills = $jobSkillModel->where('jobId', $job['id'])->findAll(3);
                $skills = [];
                foreach ($jobSkills as $js) {
                    $skillModel = new \App\Models\Skill();
                    $skill = $skillModel->select('id, name')->find($js['skillId']);
                    if ($skill) {
                        $skills[] = [
                            'id' => $js['id'],
                            'jobId' => $js['jobId'],
                            'skillId' => $js['skillId'],
                            'required' => (bool) ($js['required'] ?? false),
                            'skill' => $skill
                        ];
                    }
                }
                $formattedJob['skills'] = $skills;
                
                $formattedJobs[] = $formattedJob;
            }
            
            // Node.js returns { success: true, message: '...', data: jobs[] }
            return $this->respond([
                'success' => true,
                'message' => 'Newest jobs retrieved successfully',
                'data' => $formattedJobs
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get newest jobs error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch newest jobs',
                'data' => []
            ], 500);
        }
    }
}
