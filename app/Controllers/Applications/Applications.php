<?php

namespace App\Controllers\Applications;

use App\Controllers\BaseController;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Models\Job;
use App\Models\Company;
use App\Libraries\EmailHelper;
use App\Services\LonglistExportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Applications",
 *     description="Application management endpoints"
 * )
 */
class Applications extends BaseController
{
    use NormalizedResponseTrait;

    protected $applicationModel;
    protected $historyModel;
    protected $candidateModel;
    protected $emailHelper;

    public function __construct()
    {
        $this->applicationModel = new Application();
        $this->historyModel = new ApplicationStatusHistory();
        $this->candidateModel = new User();
        $this->emailHelper = new EmailHelper();
    }

    /**
     * ==========================================================
     * AUTHZ HELPERS (COMPANY SCOPING)
     * ==========================================================
     */

    private function getAuthUser()
    {
        return $this->request->user ?? null;
    }

    private function isAdminRole($role)
    {
        return in_array($role, ['SUPER_ADMIN', 'MODERATOR'], true);
    }

    /**
     * Returns employerProfile row or null.
     * employer_profiles has userId -> companyId relationship in your system.
     */
    private function getEmployerProfileByUserId($userId)
    {
        $db = \Config\Database::connect();
        return $db->table('employer_profiles')
            ->where('userId', $userId)
            ->get()
            ->getRowArray();
    }

    /**
     * Returns companyId for employer-like roles (EMPLOYER/HR_MANAGER), or null if not found.
     */
    private function getCompanyIdForEmployerUser($user)
    {
        if (!$user)
            return null;

        // Treat HR_MANAGER similar to employer for data scoping.
        if (!in_array($user->role ?? '', ['EMPLOYER', 'HR_MANAGER'], true)) {
            return null;
        }

        $employerProfile = $this->getEmployerProfileByUserId($user->id);
        return $employerProfile['companyId'] ?? null;
    }

    /**
     * Enforce that a job belongs to employer company (unless admin).
     */
    private function authorizeJobAccess($jobId)
    {
        $user = $this->getAuthUser();
        if (!$user)
            return [false, $this->fail('Unauthorized', 401)];

        if ($this->isAdminRole($user->role ?? '')) {
            return [true, null];
        }

        $companyId = $this->getCompanyIdForEmployerUser($user);
        if (!$companyId) {
            return [false, $this->fail('Employer profile not found', 404)];
        }

        $job = (new Job())->find($jobId);
        if (!$job) {
            return [false, $this->failNotFound('Job not found')];
        }

        if (($job['companyId'] ?? null) !== $companyId) {
            return [false, $this->fail('Forbidden: not your company job', 403)];
        }

        return [true, null];
    }

    /**
     * Enforce that an application belongs to employer company via job.companyId (unless admin).
     */
    private function authorizeApplicationAccess($applicationId)
    {
        $user = $this->getAuthUser();
        if (!$user)
            return [false, $this->fail('Unauthorized', 401)];

        if ($this->isAdminRole($user->role ?? '')) {
            return [true, null];
        }

        $companyId = $this->getCompanyIdForEmployerUser($user);
        if (!$companyId) {
            return [false, $this->fail('Employer profile not found', 404)];
        }

        $db = \Config\Database::connect();

        // Check application -> job -> company ownership
        $row = $db->table('applications')
            ->select('applications.id, jobs.companyId')
            ->join('jobs', 'jobs.id = applications.jobId', 'inner')
            ->where('applications.id', $applicationId)
            ->get()
            ->getRowArray();

        if (!$row) {
            return [false, $this->failNotFound('Application not found')];
        }

        if (($row['companyId'] ?? null) !== $companyId) {
            return [false, $this->fail('Forbidden: not your company application', 403)];
        }

        return [true, null];
    }

    /**
     * Enforce that candidate profile can be fetched only if candidate has at least one application
     * for employer's company jobs (unless admin).
     */
    private function authorizeCandidateProfileAccess($candidateUserId)
    {
        $user = $this->getAuthUser();
        if (!$user)
            return [false, $this->fail('Unauthorized', 401)];

        if ($this->isAdminRole($user->role ?? '')) {
            return [true, null];
        }

        $companyId = $this->getCompanyIdForEmployerUser($user);
        if (!$companyId) {
            return [false, $this->fail('Employer profile not found', 404)];
        }

        $db = \Config\Database::connect();

        // Candidate must have at least one application on a job belonging to employer company.
        $row = $db->table('applications')
            ->select('applications.id')
            ->join('jobs', 'jobs.id = applications.jobId', 'inner')
            ->where('applications.candidateId', $candidateUserId)
            ->where('jobs.companyId', $companyId)
            ->limit(1)
            ->get()
            ->getRowArray();

        if (!$row) {
            return [false, $this->fail('Forbidden: candidate not related to your company', 403)];
        }

        return [true, null];
    }

    /**
     * Get applications for a specific job with stats and pagination
     */
    public function getApplicationsForJob($jobId = null)
    {
        try {
            if ($jobId === null) {
                $jobId = $this->request->getUri()->getSegment(4); // /api/applications/job/{jobId}
            }

            if (!$jobId) {
                return $this->fail('Job ID is required', 400);
            }

            // ✅ AUTHZ
            [$ok, $resp] = $this->authorizeJobAccess($jobId);
            if (!$ok)
                return $resp;

            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;
            $status = $this->request->getGet('status');

            $db = \Config\Database::connect();
            $builder = $db->table('applications');

            // Build base query
            $builder->where('applications.jobId', $jobId);
            if ($status) {
                $builder->where('applications.status', $status);
            }

            // Get total count for pagination
            $total = $builder->countAllResults(false);

            // Get paginated applications with relationships
            $applications = $builder
                ->select('applications.*, 
                    users.id as candidate_id, users.firstName, users.lastName, users.email, users.phone, users.avatar,
                    candidate_profiles.id as profile_id, candidate_profiles.title, candidate_profiles.location, candidate_profiles.experienceYears, candidate_profiles.resumeUrl,
                    jobs.id as job_id, jobs.title as job_title,
                    companies.id as company_id, companies.name as company_name, companies.logo as company_logo')
                ->join('users', 'users.id = applications.candidateId', 'left')
                ->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left')
                ->join('jobs', 'jobs.id = applications.jobId', 'left')
                ->join('companies', 'companies.id = jobs.companyId', 'left')
                ->orderBy('applications.appliedAt', 'DESC')
                ->limit($limit, $skip)
                ->get()
                ->getResultArray();

            // Transform applications data
            $formattedApplications = array_map(function ($app) {
                return $this->formatApplicationData($app);
            }, $applications);

            // Get additional relationships (domains, skills, education, experience)
            foreach ($formattedApplications as &$app) {
                $app['candidate']['candidateProfile']['domains'] = $this->getCandidateDomains($app['candidate']['id']);
                $app['candidate']['candidateProfile']['skills'] = $this->getCandidateSkills($app['candidate']['id']);
                $app['candidate']['candidateProfile']['educations'] = $this->getCandidateEducation($app['candidate']['id']);
                $app['candidate']['candidateProfile']['experiences'] = $this->getCandidateExperience($app['candidate']['id']);
            }

            // Calculate stats for this job
            $statsBuilder = $db->table('applications');
            $allApplications = $statsBuilder->where('jobId', $jobId)->get()->getResultArray();
            $stats = $this->calculateStats($allApplications);

            return $this->respond([
                'success' => true,
                'message' => 'Applications retrieved successfully',
                'data' => [
                    'applications' => $formattedApplications,
                    'stats' => $stats,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'totalPages' => ceil($total / $limit)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to retrieve applications: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve applications',
                'error' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Filter applications across all jobs with pagination
     */
    public function filterApplications()
    {
        try {
            $user = $this->getAuthUser();
            if (!$user)
                return $this->fail('Unauthorized', 401);

            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;

            // Get filters
            $status = $this->request->getGet('status');
            $jobId = $this->request->getGet('jobId');
            $candidateId = $this->request->getGet('candidateId');
            $query = $this->request->getGet('query');
            $minRating = $this->request->getGet('minRating');

            $db = \Config\Database::connect();
            $builder = $db->table('applications');

            // Build query with joins
            $builder->select('applications.*, 
                    users.id as candidate_id, users.firstName, users.lastName, users.email, users.phone, users.avatar,
                    candidate_profiles.id as profile_id, candidate_profiles.title, candidate_profiles.location, candidate_profiles.experienceYears, candidate_profiles.resumeUrl,
                    jobs.id as job_id, jobs.title as job_title,
                    companies.id as company_id, companies.name as company_name, companies.logo as company_logo')
                ->join('users', 'users.id = applications.candidateId', 'left')
                ->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left')
                ->join('jobs', 'jobs.id = applications.jobId', 'left')
                ->join('companies', 'companies.id = jobs.companyId', 'left');

            // AUTHZ: restrict employer/hr to their company jobs
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId)
                    return $this->fail('Employer profile not found', 404);
                $builder->where('jobs.companyId', $companyId);
            }

            if ($status)
                $builder->where('applications.status', $status);
            if ($jobId)
                $builder->where('applications.jobId', $jobId);
            if ($candidateId)
                $builder->where('applications.candidateId', $candidateId);

            if ($query) {
                $builder->groupStart()
                    ->like('users.firstName', $query)
                    ->orLike('users.lastName', $query)
                    ->orLike('users.email', $query)
                    ->orLike('jobs.title', $query)
                    ->groupEnd();
            }

            if ($minRating) {
                $builder->where('applications.rating >=', $minRating);
            }

            // Get total count
            $total = $builder->countAllResults(false);

            // Get paginated results
            $applications = $builder
                ->orderBy('applications.appliedAt', 'DESC')
                ->limit($limit, $skip)
                ->get()
                ->getResultArray();

            // Transform applications data
            $formattedApplications = array_map(function ($app) {
                return $this->formatApplicationData($app);
            }, $applications);

            // Get additional relationships
            foreach ($formattedApplications as &$app) {
                $app['candidate']['candidateProfile']['domains'] = $this->getCandidateDomains($app['candidate']['id']);
                $app['candidate']['candidateProfile']['skills'] = $this->getCandidateSkills($app['candidate']['id']);
                $app['candidate']['candidateProfile']['educations'] = $this->getCandidateEducation($app['candidate']['id']);
                $app['candidate']['candidateProfile']['experiences'] = $this->getCandidateExperience($app['candidate']['id']);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Applications retrieved successfully',
                'data' => [
                    'applications' => $formattedApplications,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'totalPages' => ceil($total / $limit)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to filter applications: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to filter applications',
                'error' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Get application details with full candidate profile
     */
    public function getApplicationDetails($id = null)
    {
        try {
            if ($id === null) {
                $id = $this->request->getUri()->getSegment(3); // /api/applications/{id}
            }

            if (!$id) {
                return $this->fail('Application ID is required', 400);
            }

            // AUTHZ
            [$ok, $resp] = $this->authorizeApplicationAccess($id);
            if (!$ok)
                return $resp;

            $db = \Config\Database::connect();
            $builder = $db->table('applications');

            $application = $builder
                ->select('applications.*, 
                users.id as candidate_id, users.firstName, users.lastName, users.email, users.phone, users.avatar,
                candidate_profiles.id as profile_id, candidate_profiles.title, candidate_profiles.location, candidate_profiles.experienceYears, candidate_profiles.resumeUrl,
                jobs.id as job_id, jobs.title as job_title,
                companies.id as company_id, companies.name as company_name, companies.logo as company_logo')
                ->join('users', 'users.id = applications.candidateId', 'left')
                ->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left')
                ->join('jobs', 'jobs.id = applications.jobId', 'left')
                ->join('companies', 'companies.id = jobs.companyId', 'left')
                ->where('applications.id', $id)
                ->get()
                ->getRowArray();

            if (!$application) {
                return $this->failNotFound('Application not found');
            }

            $formatted = $this->formatApplicationData($application);

            $candidateUserId = $formatted['candidate']['id']; // users.id
            $profileId = $this->getCandidateProfileIdByUserId($candidateUserId); // candidate_profiles.id

            // Always return these keys (consistent API)
            $formatted['candidate']['candidateProfile']['personalInfo'] = null;
            $formatted['candidate']['candidateProfile']['publications'] = [];
            $formatted['candidate']['candidateProfile']['memberships'] = [];
            $formatted['candidate']['candidateProfile']['clearances'] = [];
            $formatted['candidate']['candidateProfile']['courses'] = [];
            $formatted['candidate']['candidateProfile']['referees'] = [];
            $formatted['candidate']['candidateProfile']['files'] = [];
            $formatted['candidate']['candidateProfile']['certifications'] = [];
            $formatted['candidate']['candidateProfile']['languages'] = [];

            // Existing blocks you already had
            $formatted['candidate']['candidateProfile']['domains'] = $this->getCandidateDomains($candidateUserId);
            $formatted['candidate']['candidateProfile']['skills'] = $this->getCandidateSkills($candidateUserId);
            $formatted['candidate']['candidateProfile']['educations'] = $this->getCandidateEducation($candidateUserId);
            $formatted['candidate']['candidateProfile']['experiences'] = $this->getCandidateExperience($candidateUserId);

            // Full extras (only if profile exists)
            if ($profileId) {
                $formatted['candidate']['candidateProfile']['personalInfo'] = $this->getCandidatePersonalInfoByProfileId($profileId);
                $formatted['candidate']['candidateProfile']['publications'] = $this->getCandidatePublicationsByProfileId($profileId);
                $formatted['candidate']['candidateProfile']['memberships'] = $this->getCandidateMembershipsByProfileId($profileId);
                $formatted['candidate']['candidateProfile']['clearances'] = $this->getCandidateClearancesByProfileId($profileId);
                $formatted['candidate']['candidateProfile']['courses'] = $this->getCandidateCoursesByProfileId($profileId);
                $formatted['candidate']['candidateProfile']['referees'] = $this->getCandidateRefereesByProfileId($profileId);
                $formatted['candidate']['candidateProfile']['files'] = $this->getCandidateFilesByProfileId($profileId);
                $formatted['candidate']['candidateProfile']['certifications'] = $this->getCandidateCertificationsByProfileId($profileId);
                $formatted['candidate']['candidateProfile']['languages'] = $this->getCandidateLanguagesByProfileId($profileId);
            }

            $formatted['statusHistory'] = $this->getStatusHistory($id);

            return $this->respond([
                'success' => true,
                'message' => 'Application retrieved successfully',
                'data' => $formatted
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to get application details: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());

            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve application',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update application status
     * NEW: When status becomes REJECTED -> send rejection email (from headhunting@fortunekenya.com)
     * KEEP application active for reporting (no isActive changes)
     */
    public function updateApplicationStatus($id = null)
    {
        try {
            if ($id === null) {
                $id = $this->request->getUri()->getSegment(3); // /api/applications/{id}/status
            }

            if (!$id) {
                return $this->fail('Application ID is required', 400);
            }

            // AUTHZ
            [$ok, $resp] = $this->authorizeApplicationAccess($id);
            if (!$ok)
                return $resp;

            $data = $this->request->getJSON(true);

            if (!isset($data['status'])) {
                return $this->fail('Status is required', 400);
            }

            $application = $this->applicationModel->find($id);
            if (!$application) {
                return $this->failNotFound('Application not found');
            }

            $oldStatus = $application['status'];

            // Prepare update data
            $updateData = ['status' => $data['status']];

            if (isset($data['internalNotes'])) {
                $updateData['internalNotes'] = $data['internalNotes'];
            }

            if (isset($data['rating'])) {
                $updateData['rating'] = (int) $data['rating'];
            }

            // Update application
            $this->applicationModel->update($id, $updateData);

            // Log status change in history
            $this->historyModel->insert([
                'id' => uniqid('history_'),
                'applicationId' => $id,
                'fromStatus' => $oldStatus,
                'toStatus' => $data['status'],
                'changedBy' => $this->request->user->id ?? null,
                'reason' => $data['reason'] ?? null,
                'changedAt' => date('Y-m-d H:i:s')
            ]);

            if ($data['status'] === 'REJECTED' && $oldStatus !== 'REJECTED') {
                try {
                    log_message('info', 'Attempting to send rejection email for app: ' . $id);

                    $candidate = (new User())->find($application['candidateId']);
                    $job = (new Job())->find($application['jobId']);

                    log_message('info', 'Candidate found: ' . ($candidate ? $candidate['email'] : 'NOT FOUND'));
                    log_message('info', 'Job found: ' . ($job ? $job['title'] : 'NOT FOUND'));

                    $company = null;
                    if ($job && !empty($job['companyId'])) {
                        $company = (new Company())->find($job['companyId']);
                    }

                    if ($candidate && $job) {
                        $emailResult = $this->emailHelper->sendApplicationRejectedEmail([
                            'candidate' => [
                                'firstName' => $candidate['firstName'] ?? '',
                                'lastName' => $candidate['lastName'] ?? '',
                                'email' => $candidate['email'] ?? ''
                            ],
                            'job' => [
                                'title' => $job['title'] ?? '',
                                'company' => [
                                    'name' => $company['name'] ?? 'Fortune Kenya'
                                ]
                            ]
                        ]);

                        log_message('info', 'Email result: ' . json_encode($emailResult));
                    }
                } catch (\Exception $mailEx) {
                    log_message('error', 'Rejection email exception: ' . $mailEx->getMessage());
                    log_message('error', 'Stack trace: ' . $mailEx->getTraceAsString());
                }
            }

            return $this->respond([
                'success' => true,
                'message' => 'Status updated successfully',
                'data' => [
                    'id' => $id,
                    'status' => $data['status']
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to update status: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update application status
     */
    public function bulkUpdateStatus()
    {
        try {
            $data = $this->request->getJSON(true);

            if (!isset($data['applicationIds']) || !is_array($data['applicationIds'])) {
                return $this->fail('Application IDs array is required', 400);
            }

            if (!isset($data['status'])) {
                return $this->fail('Status is required', 400);
            }

            $updated = 0;

            $userModel = new User();
            $jobModel = new Job();
            $companyModel = new Company();

            foreach ($data['applicationIds'] as $appId) {
                // AUTHZ per application
                [$ok, $resp] = $this->authorizeApplicationAccess($appId);
                if (!$ok) {
                    // skip unauthorized IDs silently
                    continue;
                }

                $application = $this->applicationModel->find($appId);
                if (!$application) {
                    continue;
                }

                $oldStatus = $application['status'];

                $updateData = ['status' => $data['status']];
                if (isset($data['internalNotes'])) {
                    $updateData['internalNotes'] = $data['internalNotes'];
                }

                $this->applicationModel->update($appId, $updateData);

                // Log status change
                $this->historyModel->insert([
                    'id' => uniqid('history_'),
                    'applicationId' => $appId,
                    'fromStatus' => $oldStatus,
                    'toStatus' => $data['status'],
                    'changedBy' => $this->request->user->id ?? null,
                    'reason' => $data['reason'] ?? 'Bulk update',
                    'changedAt' => date('Y-m-d H:i:s')
                ]);

                // Send rejection email (only on transition)
                if ($data['status'] === 'REJECTED' && $oldStatus !== 'REJECTED') {
                    try {
                        $candidate = $userModel->find($application['candidateId']);
                        $job = $jobModel->find($application['jobId']);

                        $company = null;
                        if ($job && !empty($job['companyId'])) {
                            $company = $companyModel->find($job['companyId']);
                        }

                        if ($candidate && $job) {
                            $this->emailHelper->sendApplicationRejectedEmail([
                                'candidate' => [
                                    'firstName' => $candidate['firstName'] ?? '',
                                    'lastName' => $candidate['lastName'] ?? '',
                                    'email' => $candidate['email'] ?? ''
                                ],
                                'job' => [
                                    'title' => $job['title'] ?? '',
                                    'company' => [
                                        'name' => $company['name'] ?? 'Fortune Kenya'
                                    ]
                                ]
                            ]);
                        }
                    } catch (\Exception $mailEx) {
                        log_message('error', 'Bulk rejection email failed: ' . $mailEx->getMessage());
                    }
                }

                $updated++;
            }

            return $this->respond([
                'success' => true,
                'message' => "Successfully updated {$updated} applications",
                'data' => [
                    'updated' => $updated
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to bulk update: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to bulk update applications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get employer dashboard stats
     */
    public function getDashboardStats()
    {
        try {
            $user = $this->getAuthUser();
            $userId = $user->id ?? null;

            if (!$userId) {
                return $this->fail('Unauthorized', 401);
            }

            $db = \Config\Database::connect();

            //  use employer_profiles mapping instead of companies.ownerId
            if ($this->isAdminRole($user->role ?? '')) {
                // Admin can still see global stats if desired; keep current behavior by using no company filter.
                // If you prefer admin to still be scoped, remove this block.
            }

            $employerProfile = $this->getEmployerProfileByUserId($userId);
            $companyId = $employerProfile['companyId'] ?? null;

            // If admin but no employer profile, return zeros (same as before)
            if (!$companyId) {
                return $this->respond([
                    'success' => true,
                    'data' => [
                        'stats' => [
                            'totalApplications' => 0,
                            'pending' => 0,
                            'reviewed' => 0,
                            'shortlisted' => 0,
                            'interview' => 0,
                            'offered' => 0,
                            'accepted' => 0,
                            'rejected' => 0
                        ],
                        'recentApplications' => []
                    ]
                ]);
            }

            $builder = $db->table('applications');

            // Get all applications for company jobs
            $applications = $builder
                ->select('applications.*')
                ->join('jobs', 'jobs.id = applications.jobId')
                ->where('jobs.companyId', $companyId)
                ->get()
                ->getResultArray();

            $stats = $this->calculateStats($applications);

            // Get recent applications
            $recentBuilder = $db->table('applications');
            $recentApplications = $recentBuilder
                ->select('applications.*, 
                    users.id as candidate_id, users.firstName, users.lastName, users.email, users.avatar,
                    candidate_profiles.id as profile_id, candidate_profiles.title,
                    jobs.id as job_id, jobs.title as job_title,
                    companies.name as company_name')
                ->join('users', 'users.id = applications.candidateId', 'left')
                ->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left')
                ->join('jobs', 'jobs.id = applications.jobId', 'left')
                ->join('companies', 'companies.id = jobs.companyId', 'left')
                ->where('jobs.companyId', $companyId)
                ->orderBy('applications.appliedAt', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();

            $formattedRecent = array_map(function ($app) {
                return $this->formatApplicationData($app);
            }, $recentApplications);

            return $this->respond([
                'success' => true,
                'message' => 'Dashboard stats retrieved successfully',
                'data' => [
                    'stats' => $stats,
                    'recentApplications' => $formattedRecent
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to get dashboard stats: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve dashboard stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export applications to CSV
     */
    public function exportApplications()
    {
        try {
            $user = $this->getAuthUser();
            if (!$user)
                return $this->fail('Unauthorized', 401);

            $jobId = $this->request->getGet('jobId');

            // if exporting for a specific job, ensure job belongs to employer
            if ($jobId) {
                [$ok, $resp] = $this->authorizeJobAccess($jobId);
                if (!$ok)
                    return $resp;
            }

            $db = \Config\Database::connect();
            $builder = $db->table('applications');

            $builder->select('applications.*, 
                    users.firstName, users.lastName, users.email, users.phone,
                    candidate_profiles.title, candidate_profiles.location, candidate_profiles.experienceYears,
                    jobs.title as job_title,
                    companies.name as company_name')
                ->join('users', 'users.id = applications.candidateId', 'left')
                ->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left')
                ->join('jobs', 'jobs.id = applications.jobId', 'left')
                ->join('companies', 'companies.id = jobs.companyId', 'left');

            // if employer/hr and not admin, restrict to their company
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId)
                    return $this->fail('Employer profile not found', 404);
                $builder->where('jobs.companyId', $companyId);
            }

            if ($jobId) {
                $builder->where('applications.jobId', $jobId);
            }

            $applications = $builder->get()->getResultArray();

            // Generate CSV
            $csv = "ID,Candidate Name,Email,Phone,Job Title,Company,Status,Applied At,Expected Salary,Location,Experience\n";

            foreach ($applications as $app) {
                $csv .= sprintf(
                    '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                    $app['id'],
                    ($app['firstName'] ?? '') . ' ' . ($app['lastName'] ?? ''),
                    $app['email'] ?? '',
                    $app['phone'] ?? '',
                    $app['job_title'] ?? '',
                    $app['company_name'] ?? '',
                    $app['status'] ?? '',
                    $app['appliedAt'] ?? '',
                    $app['expectedSalary'] ?? '',
                    $app['location'] ?? '',
                    $app['experienceYears'] ?? ''
                );
            }

            return $this->response
                ->setHeader('Content-Type', 'text/csv')
                ->setHeader('Content-Disposition', 'attachment; filename="applications_' . date('Y-m-d') . '.csv"')
                ->setBody($csv);
        } catch (\Exception $e) {
            log_message('error', 'Failed to export CSV: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to export applications',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function exportApplicationsCsv()
    {
        try {
            $user = $this->getAuthUser();
            if (!$user)
                return $this->fail('Unauthorized', 401);

            $jobId = $this->request->getGet('jobId');

            //     if (!$jobId) {
            //     return $this->fail('Job ID is required for export', 400);
            // }

            // AUTHZ: if jobId present, ensure job belongs to employer (or admin)
            if ($jobId) {
                [$ok, $resp] = $this->authorizeJobAccess($jobId);
                if (!$ok)
                    return $resp;
            }

            $companyId = null;
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId)
                    return $this->fail('Employer profile not found', 404);
            }

            $service = new LonglistExportService();
            $rows = $service->buildLonglistRows($jobId, $companyId);

            // Build CSV safely
            $filename = 'longlist_' . date('Y-m-d_His') . '.csv';
            $fh = fopen('php://temp', 'w+');

            if (empty($rows)) {
                fputcsv($fh, ['No data']);
            } else {
                fputcsv($fh, array_keys($rows[0]));
                foreach ($rows as $r) {
                    fputcsv($fh, array_values($r));
                }
            }

            rewind($fh);
            $csv = stream_get_contents($fh);
            fclose($fh);

            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setBody($csv);

        } catch (\Exception $e) {
            log_message('error', 'exportApplicationsCsv error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to export CSV',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export applications to XLSX with detailed information and formatting
     */
    /**
     * Export applications to XLSX matching the uploaded template structure
     */
    public function exportApplicationsXlsx()
    {
        try {
            $user = $this->getAuthUser();
            if (!$user)
                return $this->fail('Unauthorized', 401);

            $jobId = $this->request->getGet('jobId');

            // AUTHZ
            if ($jobId) {
                [$ok, $resp] = $this->authorizeJobAccess($jobId);
                if (!$ok)
                    return $resp;
            }

            $companyId = null;
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId)
                    return $this->fail('Employer profile not found', 404);
            }

            $service = new LonglistExportService();
            $dataRows = $service->buildLonglistRows($jobId, $companyId);
            $title = $service->getExportTitle($jobId, $companyId);

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Longlist');

            if (empty($dataRows)) {
                $sheet->setCellValue('A1', 'No applications found');
                $filename = 'longlist_empty_' . date('Y-m-d_His') . '.xlsx';
            } else {
                // ROW 1: Title (merged A1:D1)
                $sheet->setCellValue('A1', $title);
                $sheet->mergeCells('A1:D1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension(1)->setRowHeight(30);

                // ROW 2: Section Headers (with merged cells)
                $this->setSectionHeaders($sheet);

                // ROW 3: Sub-section Headers (with merged cells)
                $this->setSubSectionHeaders($sheet);

                // ROW 4: Column Field Names
                $this->setColumnHeaders($sheet);

                $sheet->getRowDimension(2)->setRowHeight(40);
                $sheet->getRowDimension(3)->setRowHeight(40);
                $sheet->getRowDimension(4)->setRowHeight(70);

                $sheet->getStyle('A2:AP4')->getAlignment()
                    ->setWrapText(true)
                    ->setShrinkToFit(false)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Data rows (starting from row 5)
                $this->writeDataRows($sheet, $dataRows);

                // Styling
                $this->applyStyles($sheet, count($dataRows));

                $filename = 'longlist_export_' . date('Y-m-d_His') . '.xlsx';
            }

            // Write to output
            $writer = new Xlsx($spreadsheet);
            $tmp = fopen('php://temp', 'w+b');
            $writer->save($tmp);
            rewind($tmp);
            $binary = stream_get_contents($tmp);
            fclose($tmp);

            return $this->response
                ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setHeader('Cache-Control', 'max-age=0')
                ->setBody($binary);

        } catch (\Exception $e) {
            log_message('error', 'exportApplicationsXlsx error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to export XLSX',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ROW 2: Set section headers with merged cells
     */
    private function setSectionHeaders($sheet)
    {
        // A2:A3 - SERIALISATION
        $sheet->setCellValue('A2', 'SERIALISATION');
        $sheet->mergeCells('A2:A3');

        // B2:L3 - GENERAL INFORMATION
        $sheet->setCellValue('B2', 'GENERAL INFORMATION');
        $sheet->mergeCells('B2:L3');

        // M2:W2 - PROFESSIONAL AND ACADEMIC CERTIFICATES
        $sheet->setCellValue('M2', 'PROFESSIONAL AND ACADEMIC CERTIFICATES');
        $sheet->mergeCells('M2:W2');

        // X2:AA3 - GENERAL AND SPECIFIC EXPERIENCE
        $sheet->setCellValue('X2', 'GENERAL AND SPECIFIC EXPERIENCE' . "\n" . '(MANDATORY)');
        $sheet->mergeCells('X2:AA3');

        // AB2:AF3 - CURRENT EMPLOYMENT, RENUMERATION, NOTICE PERIOD & REFEREES
        $sheet->setCellValue('AB2', 'CURRENT EMPLOYMENT, RENUMERATION, NOTICE PERIOD & REFEREES');
        $sheet->mergeCells('AB2:AF3');

        // AG2:AP2 - CHAPTER 6 REQUIREMENTS VERIFICATION
        $sheet->setCellValue('AG2', 'CHAPTER 6 REQUIREMENTS VERIFICATION');
        $sheet->mergeCells('AG2:AP2');

        // Style section headers
        $sectionRanges = ['A2:A3', 'B2:L3', 'M2:W2', 'X2:AA3', 'AB2:AF3', 'AG2:AP2'];
        foreach ($sectionRanges as $range) {
            $sheet->getStyle($range)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $sheet->getStyle($range)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFD3D3D3');
            $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
    }

    /**
     * ROW 3: Set sub-section headers with merged cells
     */
    private function setSubSectionHeaders($sheet)
    {
        // M3:N3 - Doctorate Degree (MANDATORY)
        $sheet->setCellValue('M3', 'Doctorate Degree' . "\n" . '(MANDATORY)');
        $sheet->mergeCells('M3:N3');

        // O3:P3 - Master's Degree (MANDATORY)
        $sheet->setCellValue('O3', 'Master\'s Degree' . "\n" . '(MANDATORY)');
        $sheet->mergeCells('O3:P3');

        // Q3:R3 - Bachelor's Degree (MANDATORY)
        $sheet->setCellValue('Q3', 'Bachelor\'s Degree' . "\n" . '(MANDATORY)');
        $sheet->mergeCells('Q3:R3');

        // AG3:AH3 - Tax Compliance
        $sheet->setCellValue('AG3', 'Individual Tax Compliance Certificate from the Kenya Revenue Authority (KRA)');
        $sheet->mergeCells('AG3:AH3');

        // AI3:AJ3 - HELB
        $sheet->setCellValue('AI3', 'Higher Education Loans Board (HELB) Status');
        $sheet->mergeCells('AI3:AJ3');

        // AK3:AL3 - DCI
        $sheet->setCellValue('AK3', 'Directorate of Criminal Investigation (Certificate of Good)');
        $sheet->mergeCells('AK3:AL3');

        // AM3:AN3 - CRB
        $sheet->setCellValue('AM3', 'Credit Reference Bureau Clearance Certificate');
        $sheet->mergeCells('AM3:AN3');

        // AO3:AP3 - EACC
        $sheet->setCellValue('AO3', 'Clearance Certificate from the Ethics and Anti-Corruption Commission (EACC)');
        $sheet->mergeCells('AO3:AP3');

        // Style sub-section headers
        $subRanges = ['M3:N3', 'O3:P3', 'Q3:R3', 'AG3:AH3', 'AI3:AJ3', 'AK3:AL3', 'AM3:AN3', 'AO3:AP3'];
        foreach ($subRanges as $range) {
            $sheet->getStyle($range)->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $sheet->getStyle($range)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE6E6E6');
            $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
    }

    /**
     * ROW 4: Set column field names
     */
    private function setColumnHeaders($sheet)
    {
        $headers = [
            'A4' => 'Serial Number',
            'B4' => 'Full Names of Applicant',
            'C4' => 'Applicant Address',
            'D4' => 'Telephone Number',
            'E4' => 'Email Address',
            'F4' => 'Nationality',
            'G4' => 'County of Origin (I.D)',
            'H4' => 'Date of Birth',
            'I4' => 'Age',
            'J4' => 'ID/Passport Number' . "\n" . '(Kenyan Citizen)',
            'K4' => 'Gender',
            'L4' => 'PLWD (Y/N)',
            'M4' => 'Doctorate Degree Name/Field/Institution/Year',
            'N4' => 'Doctorate in a relevant field from a recognized University (Y/N)',
            'O4' => 'Master\'s Degree Name/Field/Institution/Year',
            'P4' => 'Master\'s Degree in a relevant field from a recognized University (Y/N)',
            'Q4' => 'Bachelor\'s Degree Name/Field/Institution/Year',
            'R4' => 'Bachelor\'s Degree in a relevant field from a recognized University (Y/N)',
            'S4' => 'Active member of a professional body',
            'T4' => 'Good Standing',
            'U4' => 'leadership course lasting not less than four (4) weeks or equivalent',
            'V4' => 'Authored a minimum of eighteen (18) publications in peer reviewed journals, book and book chapters',
            'W4' => 'Details of All Non-Mandatory Certificates in the application (Title/Institution/Duration)',
            'X4' => 'Specific Number of years of General Experience',
            'Y4' => 'Fifteen (15) years\' relevant work experience',
            'Z4' => 'Specific Number of years of Senior Management Experience',
            'AA4' => 'Five (5) of which must have been at senior management level',
            'AB4' => 'Current Employer, Duration and Role',
            'AC4' => 'Expected Salary (monthly in Ksh)',
            'AD4' => 'Current Salary (monthly in Ksh)',
            'AE4' => 'Notice Period Required (months)',
            'AF4' => 'Three Referees Provided' . "\n" . '(Name and Contact Details)',
            'AG4' => 'Tax Compliance Certificate (Y/N)',
            'AH4' => 'Certificate Validity up to (indicate date)',
            'AI4' => 'HELB clearance Certificate/Valid Compliance (Y/N)',
            'AJ4' => 'Certificate Validity from (indicate date)',
            'AK4' => 'DCI clearance Certificate (Y/N)',
            'AL4' => 'Certificate Validity from (indicate date)',
            'AM4' => 'CRB clearance Certificate (Y/N)',
            'AN4' => 'Certificate Validity from (indicate date)',
            'AO4' => 'EACC completed First Schedule (s.13) and a Self-declaration Form (Y/N)',
            'AP4' => 'Certificate Validity from (indicate date)',
        ];

        foreach ($headers as $cell => $text) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $sheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($cell)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        $sheet->getRowDimension(4)->setRowHeight(50);
    }

    /**
     * Write data rows starting from row 5
     */
    private function writeDataRows($sheet, array $dataRows)
    {
        $startRow = 5;

        foreach ($dataRows as $rowIndex => $rowData) {
            $excelRow = $startRow + $rowIndex;
            $colIndex = 1;

            foreach ($rowData as $cellValue) {
                $cellCoord = $this->columnLetter($colIndex - 1) . $excelRow;
                $sheet->setCellValue($cellCoord, $cellValue);

                // Wrap text for long content
                if (strlen($cellValue) > 30) {
                    $sheet->getStyle($cellCoord)->getAlignment()->setWrapText(true);
                }

                // Border
                $sheet->getStyle($cellCoord)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                $colIndex++;
            }
        }
    }

    /**
     * Apply final styling and column widths
     */
    private function applyStyles($sheet, $dataRowCount)
    {
        // Set column widths
        $columnWidths = [
            'A' => 8,   // Serial
            'B' => 25,  // Name
            'C' => 20,  // Address
            'D' => 15,  // Phone
            'E' => 25,  // Email
            'F' => 12,  // Nationality
            'G' => 15,  // County
            'H' => 12,  // DOB
            'I' => 8,   // Age
            'J' => 15,  // ID
            'K' => 10,  // Gender
            'L' => 10,  // PLWD
            'M' => 40,  // Doctorate Name
            'N' => 10,  // Doctorate Y/N
            'O' => 40,  // Masters Name
            'P' => 10,  // Masters Y/N
            'Q' => 40,  // Bachelors Name
            'R' => 10,  // Bachelors Y/N
            'S' => 30,  // Professional body
            'T' => 20,  // Good standing
            'U' => 30,  // Leadership
            'V' => 35,  // Publications
            'W' => 35,  // Certificates
            'X' => 12,  // Years exp
            'Y' => 35,  // 15 years exp
            'Z' => 12,  // Senior years
            'AA' => 35, // Senior exp
            'AB' => 30, // Current employer
            'AC' => 15, // Expected salary
            'AD' => 15, // Current salary
            'AE' => 12, // Notice period
            'AF' => 35, // Referees
            'AG' => 10, // Tax Y/N
            'AH' => 15, // Tax validity
            'AI' => 10, // HELB Y/N
            'AJ' => 15, // HELB validity
            'AK' => 10, // DCI Y/N
            'AL' => 15, // DCI validity
            'AM' => 10, // CRB Y/N
            'AN' => 15, // CRB validity
            'AO' => 10, // EACC Y/N
            'AP' => 15, // EACC validity
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // Freeze panes (freeze first 4 rows)
        $sheet->freezePane('A5');

        // Set row height for data rows
        for ($row = 5; $row <= 4 + $dataRowCount; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(-1); // Auto height
        }
    }

    /**
     * Convert column index to Excel letter
     */
    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26) - 1;
        }
        return $letter;
    }
    /**
     * Add internal note to application
     */
    public function addInternalNote($id = null)
    {
        try {
            if ($id === null) {
                $id = $this->request->getUri()->getSegment(3); // /api/applications/{id}/notes
            }

            if (!$id) {
                return $this->fail('Application ID is required', 400);
            }

            // AUTHZ
            [$ok, $resp] = $this->authorizeApplicationAccess($id);
            if (!$ok)
                return $resp;

            $data = $this->request->getJSON(true);

            if (!isset($data['note'])) {
                return $this->fail('Note is required', 400);
            }

            $application = $this->applicationModel->find($id);
            if (!$application) {
                return $this->failNotFound('Application not found');
            }

            $userName = ($this->request->user->firstName ?? 'User') . ' ' . ($this->request->user->lastName ?? '');
            $notes = $application['internalNotes'] ?? '';
            $newNote = "\n[" . date('Y-m-d H:i:s') . " - {$userName}]: " . $data['note'];
            $notes .= $newNote;

            $this->applicationModel->update($id, ['internalNotes' => $notes]);

            return $this->respond([
                'success' => true,
                'message' => 'Note added successfully'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to add note: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to add note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get candidate full profile
     */
    public function getCandidateProfile($candidateId = null)
    {
        try {
            if ($candidateId === null) {
                $candidateId = $this->request->getUri()->getSegment(4); // /api/applications/candidate/{candidateId}/profile
            }

            if (!$candidateId) {
                return $this->fail('Candidate ID is required', 400);
            }

            // AUTHZ: prevent employers fetching random candidates
            [$ok, $resp] = $this->authorizeCandidateProfileAccess($candidateId);
            if (!$ok)
                return $resp;

            $candidate = $this->candidateModel->find($candidateId);
            if (!$candidate) {
                return $this->failNotFound('Candidate not found');
            }

            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $candidateId)->first();

            $candidateData = [
                'id' => $candidate['id'],
                'firstName' => $candidate['firstName'],
                'lastName' => $candidate['lastName'],
                'email' => $candidate['email'],
                'phone' => $candidate['phone'] ?? null,
                'avatar' => $candidate['avatar'] ?? null,
                'createdAt' => $candidate['createdAt'] ?? null,
                'candidateProfile' => [
                    'title' => $profile['title'] ?? null,
                    'location' => $profile['location'] ?? null,
                    'experienceYears' => $profile['experienceYears'] ?? null,
                    'resumeUrl' => $profile['resumeUrl'] ?? null,
                    'domains' => $this->getCandidateDomains($candidateId),
                    'skills' => $this->getCandidateSkills($candidateId),
                    'educations' => $this->getCandidateEducation($candidateId),
                    'experiences' => $this->getCandidateExperience($candidateId)
                ]
            ];

            return $this->respond([
                'success' => true,
                'message' => 'Candidate profile retrieved successfully',
                'data' => $candidateData
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to get candidate profile: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve candidate profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    private function formatApplicationData($app)
    {
        return [
            'id' => $app['id'],
            'status' => $app['status'],
            'coverLetter' => $app['coverLetter'] ?? null,
            'resumeUrl' => $app['resumeUrl'] ?? null,
            'portfolioUrl' => $app['portfolioUrl'] ?? null,

            'expectedSalary' => $app['expectedSalary'] ?? null,
            'privacyConsent' => (bool) ($app['privacyConsent'] ?? false),
            'availableStartDate' => $app['availableStartDate'] ?? null,

            'notes' => $app['notes'] ?? null,
            'internalNotes' => $app['internalNotes'] ?? null,
            'rating' => isset($app['rating']) ? (int) $app['rating'] : null,

            'answers' => $app['answers'] ?? null,

            'appliedAt' => $app['appliedAt'] ?? null,
            'reviewedAt' => $app['reviewedAt'] ?? null,
            'updatedAt' => $app['updatedAt'] ?? null,

            'candidate' => [
                'id' => $app['candidate_id'],
                'firstName' => $app['firstName'] ?? '',
                'lastName' => $app['lastName'] ?? '',
                'email' => $app['email'] ?? '',
                'phone' => $app['phone'] ?? null,
                'avatar' => $app['avatar'] ?? null,
                'candidateProfile' => [
                    'title' => $app['title'] ?? null,
                    'location' => $app['location'] ?? null,
                    'experienceYears' => $app['experienceYears'] ?? null,
                    'totalExperienceMonths' => $app['totalExperienceMonths'] ?? 0,
                    'resumeUrl' => $app['resumeUrl'] ?? null,
                    'domains' => [],
                    'skills' => [],
                    'educations' => [],
                    'experiences' => []
                ]
            ],

            'job' => [
                'id' => $app['job_id'],
                'title' => $app['job_title'] ?? '',
                'company' => [
                    'id' => $app['company_id'] ?? '',
                    'name' => $app['company_name'] ?? '',
                    'logo' => $app['company_logo'] ?? null
                ]
            ]
        ];
    }

    private function getCandidateDomains($candidateId)
    {
        try {
            $db = \Config\Database::connect();

            $profile = $db->table('candidate_profiles')
                ->select('id')
                ->where('userId', $candidateId)
                ->get()
                ->getRowArray();

            if (!$profile) {
                return [];
            }

            $domains = $db->table('candidate_domains')
                ->select('candidate_domains.*, domains.id as domain_id, domains.name as domain_name')
                ->join('domains', 'domains.id = candidate_domains.domainId')
                ->where('candidate_domains.candidateId', $profile['id'])
                ->get()
                ->getResultArray();

            return array_map(function ($d) {
                return [
                    'domain' => [
                        'id' => $d['domain_id'],
                        'name' => $d['domain_name']
                    ],
                    'isPrimary' => (bool) ($d['isPrimary'] ?? false)
                ];
            }, $domains);
        } catch (\Exception $e) {
            log_message('error', 'Failed to get candidate domains: ' . $e->getMessage());
            return [];
        }
    }

    private function getCandidateSkills($candidateId)
    {
        try {
            $db = \Config\Database::connect();

            $profile = $db->table('candidate_profiles')
                ->select('id')
                ->where('userId', $candidateId)
                ->get()
                ->getRowArray();

            if (!$profile) {
                return [];
            }

            $skills = $db->table('candidate_skills')
                ->select('candidate_skills.level, candidate_skills.yearsOfExp, skills.id as skill_id, skills.name as skill_name')
                ->join('skills', 'skills.id = candidate_skills.skillId')
                ->where('candidate_skills.candidateId', $profile['id'])
                ->get()
                ->getResultArray();

            return array_map(function ($s) {
                return [
                    'skill' => [
                        'id' => $s['skill_id'],
                        'name' => $s['skill_name']
                    ],
                    'level' => $s['level'] ?? null,
                    'yearsOfExp' => $s['yearsOfExp'] ?? null
                ];
            }, $skills);
        } catch (\Exception $e) {
            log_message('error', 'Failed to get candidate skills: ' . $e->getMessage());
            return [];
        }
    }

    private function getCandidateEducation($candidateId)
    {
        try {
            $db = \Config\Database::connect();

            $profile = $db->table('candidate_profiles')
                ->select('id')
                ->where('userId', $candidateId)
                ->get()
                ->getRowArray();

            if (!$profile) {
                return [];
            }

            return $db->table('educations')
                ->where('candidateId', $profile['id'])
                ->orderBy('startDate', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'Failed to get candidate education: ' . $e->getMessage());
            return [];
        }
    }

    private function getCandidateExperience($candidateId)
    {
        try {
            $db = \Config\Database::connect();

            $profile = $db->table('candidate_profiles')
                ->select('id')
                ->where('userId', $candidateId)
                ->get()
                ->getRowArray();

            if (!$profile) {
                return [];
            }

            return $db->table('experiences')
                ->where('candidateId', $profile['id'])
                ->orderBy('startDate', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'Failed to get candidate experience: ' . $e->getMessage());
            return [];
        }
    }

    private function getStatusHistory($applicationId)
    {
        return $this->historyModel
            ->where('applicationId', $applicationId)
            ->orderBy('changedAt', 'DESC')
            ->findAll();
    }

    private function calculateStats($applications)
    {
        $stats = [
            'total' => count($applications),
            'pending' => 0,
            'reviewed' => 0,
            'shortlisted' => 0,
            'interview' => 0,
            'offered' => 0,
            'accepted' => 0,
            'rejected' => 0,
            'withdrawn' => 0
        ];

        foreach ($applications as $app) {
            $status = strtolower($app['status'] ?? '');
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $stats;
    }

    /**
     * Get applications for current employer's jobs
     * Used for interview scheduling
     */
    public function getMyApplications()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            $status = $this->request->getGet('status');
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 100);
            $skip = ($page - 1) * $limit;

            $db = \Config\Database::connect();

            $employerProfile = $db->table('employer_profiles')
                ->where('userId', $user->id)
                ->get()
                ->getRowArray();

            if (!$employerProfile) {
                return $this->respond([
                    'success' => true,
                    'data' => [
                        'applications' => [],
                        'pagination' => [
                            'total' => 0,
                            'page' => 1,
                            'limit' => $limit,
                            'totalPages' => 0
                        ]
                    ]
                ]);
            }

            $builder = $db->table('applications');
            $builder->select('applications.id, applications.jobId, applications.candidateId,
                users.firstName as candidate_firstName, users.lastName as candidate_lastName,
                users.email as candidate_email, users.phone as candidate_phone,
                jobs.id as job_id, jobs.title as job_title')
                ->join('users', 'users.id = applications.candidateId', 'left')
                ->join('jobs', 'jobs.id = applications.jobId', 'left')
                ->where('jobs.companyId', $employerProfile['companyId']);

            if ($status) {
                $builder->where('applications.status', $status);
            }

            $total = $builder->countAllResults(false);

            $applications = $builder
                ->orderBy('applications.appliedAt', 'DESC')
                ->limit($limit, $skip)
                ->get()
                ->getResultArray();

            $formattedApplications = array_map(function ($app) {
                return [
                    'id' => $app['id'],
                    'jobId' => $app['jobId'],
                    'candidateId' => $app['candidateId'],
                    'candidate' => [
                        'id' => $app['candidateId'],
                        'firstName' => $app['candidate_firstName'],
                        'lastName' => $app['candidate_lastName'],
                        'email' => $app['candidate_email'],
                        'phone' => $app['candidate_phone']
                    ],
                    'job' => [
                        'id' => $app['job_id'],
                        'title' => $app['job_title']
                    ]
                ];
            }, $applications);

            return $this->respond([
                'success' => true,
                'message' => 'Applications retrieved successfully',
                'data' => [
                    'applications' => $formattedApplications,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'totalPages' => ceil($total / $limit)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to get my applications: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve applications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * candidate_profiles.id by users.id
     */
    private function getCandidateProfileIdByUserId(string $candidateUserId): ?string
    {
        try {
            $db = \Config\Database::connect();
            $row = $db->table('candidate_profiles')
                ->select('id')
                ->where('userId', $candidateUserId)
                ->get()
                ->getRowArray();

            return $row['id'] ?? null;
        } catch (\Exception $e) {
            log_message('error', 'getCandidateProfileIdByUserId error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * candidate_personal_info
     */
    private function getCandidatePersonalInfoByProfileId(string $profileId): ?array
    {
        try {
            $db = \Config\Database::connect();
            $row = $db->table('candidate_personal_info')
                ->where('candidateId', $profileId)
                ->get()
                ->getRowArray();

            return $row ?: null;
        } catch (\Exception $e) {
            log_message('error', 'getCandidatePersonalInfoByProfileId error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * candidate_publications
     */
    private function getCandidatePublicationsByProfileId(string $profileId): array
    {
        try {
            $db = \Config\Database::connect();
            return $db->table('candidate_publications')
                ->where('candidateId', $profileId)
                ->orderBy('year', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'getCandidatePublicationsByProfileId error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * candidate_professional_memberships (IMPORTANT: table name per schema)
     */
    private function getCandidateMembershipsByProfileId(string $profileId): array
    {
        try {
            $db = \Config\Database::connect();
            return $db->table('candidate_professional_memberships')
                ->where('candidateId', $profileId)
                ->orderBy('createdAt', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'getCandidateMembershipsByProfileId error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * candidate_clearances
     */
    private function getCandidateClearancesByProfileId(string $profileId): array
    {
        try {
            $db = \Config\Database::connect();
            return $db->table('candidate_clearances')
                ->where('candidateId', $profileId)
                ->orderBy('issueDate', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'getCandidateClearancesByProfileId error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * candidate_courses
     */
    private function getCandidateCoursesByProfileId(string $profileId): array
    {
        try {
            $db = \Config\Database::connect();
            return $db->table('candidate_courses')
                ->where('candidateId', $profileId)
                ->orderBy('year', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'getCandidateCoursesByProfileId error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * candidate_referees
     */
    private function getCandidateRefereesByProfileId(string $profileId): array
    {
        try {
            $db = \Config\Database::connect();
            return $db->table('candidate_referees')
                ->where('candidateId', $profileId)
                ->orderBy('createdAt', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'getCandidateRefereesByProfileId error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * candidate_files (attachments)
     */
    private function getCandidateFilesByProfileId(string $profileId): array
    {
        try {
            $db = \Config\Database::connect();
            return $db->table('candidate_files')
                ->where('candidateId', $profileId)
                ->orderBy('createdAt', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'getCandidateFilesByProfileId error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * certifications
     */
    private function getCandidateCertificationsByProfileId(string $profileId): array
    {
        try {
            $db = \Config\Database::connect();
            return $db->table('certifications')
                ->where('candidateId', $profileId)
                ->orderBy('issueDate', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'getCandidateCertificationsByProfileId error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * candidate_languages joined with languages
     */
    private function getCandidateLanguagesByProfileId(string $profileId): array
    {
        try {
            $db = \Config\Database::connect();
            $rows = $db->table('candidate_languages')
                ->select('candidate_languages.*, languages.id as language_id, languages.name as language_name')
                ->join('languages', 'languages.id = candidate_languages.languageId', 'left')
                ->where('candidate_languages.candidateId', $profileId)
                ->orderBy('candidate_languages.createdAt', 'DESC')
                ->get()
                ->getResultArray();

            return array_map(function ($r) {
                return [
                    'id' => $r['id'] ?? null,
                    'candidateId' => $r['candidateId'] ?? null,
                    'languageId' => $r['languageId'] ?? null,
                    'proficiency' => $r['proficiency'] ?? null,
                    'createdAt' => $r['createdAt'] ?? null,
                    'language' => [
                        'id' => $r['language_id'] ?? null,
                        'name' => $r['language_name'] ?? null
                    ]
                ];
            }, $rows);
        } catch (\Exception $e) {
            log_message('error', 'getCandidateLanguagesByProfileId error: ' . $e->getMessage());
            return [];
        }
    }
}