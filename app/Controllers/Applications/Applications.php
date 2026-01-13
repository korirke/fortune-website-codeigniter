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
     * Get applications for a specific job with stats and pagination
     */
    public function getApplicationsForJob($jobId = null)
    {
        try {
            if ($jobId === null) {
                $jobId = $this->request->getUri()->getSegment(4);
            }

            if (!$jobId) {
                return $this->fail('Job ID is required', 400);
            }

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

            if ($status) {
                $builder->where('applications.status', $status);
            }
            if ($jobId) {
                $builder->where('applications.jobId', $jobId);
            }
            if ($candidateId) {
                $builder->where('applications.candidateId', $candidateId);
            }
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
                $id = $this->request->getUri()->getSegment(3);
            }

            if (!$id) {
                return $this->fail('Application ID is required', 400);
            }

            $db = \Config\Database::connect();
            $builder = $db->table('applications');

            // Get application with relationships
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

            // Format application data
            $formattedApplication = $this->formatApplicationData($application);

            // Get complete candidate profile
            $formattedApplication['candidate']['candidateProfile']['domains'] = $this->getCandidateDomains($formattedApplication['candidate']['id']);
            $formattedApplication['candidate']['candidateProfile']['skills'] = $this->getCandidateSkills($formattedApplication['candidate']['id']);
            $formattedApplication['candidate']['candidateProfile']['educations'] = $this->getCandidateEducation($formattedApplication['candidate']['id']);
            $formattedApplication['candidate']['candidateProfile']['experiences'] = $this->getCandidateExperience($formattedApplication['candidate']['id']);

            // Get status history
            $formattedApplication['statusHistory'] = $this->getStatusHistory($id);

            return $this->respond([
                'success' => true,
                'message' => 'Application retrieved successfully',
                'data' => $formattedApplication
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to get application details: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve application',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update application status
     * ✅ NEW: When status becomes REJECTED -> send rejection email (from headhunting@fortunekenya.com)
     * ✅ KEEP application active for reporting (no isActive changes)
     */
    public function updateApplicationStatus($id = null)
    {
        try {
            if ($id === null) {
                $id = $this->request->getUri()->getSegment(3);
            }

            if (!$id) {
                return $this->fail('Application ID is required', 400);
            }

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

                // ✅ Send rejection email (only on transition)
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
            $userId = $this->request->user->id ?? null;

            if (!$userId) {
                return $this->fail('Unauthorized', 401);
            }

            // Get user's company
            $companyModel = new \App\Models\Company();
            $company = $companyModel->where('ownerId', $userId)->first();

            if (!$company) {
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

            $db = \Config\Database::connect();
            $builder = $db->table('applications');

            // Get all applications for company jobs
            $applications = $builder
                ->select('applications.*')
                ->join('jobs', 'jobs.id = applications.jobId')
                ->where('jobs.companyId', $company['id'])
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
                ->where('jobs.companyId', $company['id'])
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
            $jobId = $this->request->getGet('jobId');

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

    /**
     * Add internal note to application
     */
    public function addInternalNote($id = null)
    {
        try {
            if ($id === null) {
                $id = $this->request->getUri()->getSegment(3);
            }

            if (!$id) {
                return $this->fail('Application ID is required', 400);
            }

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
                $candidateId = $this->request->getUri()->getSegment(4);
            }

            if (!$candidateId) {
                return $this->fail('Candidate ID is required', 400);
            }

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
}
