<?php

namespace App\Controllers\Interviews;

use App\Controllers\BaseController;
use App\Models\Interview;
use App\Models\InterviewHistory;
use App\Models\Application;
use App\Models\User;
use App\Models\Notification;
use App\Libraries\EmailHelper;
use App\Helpers\DataTypeHelper;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Interviews",
 *     description="Interview management endpoints"
 * )
 */
class Interviews extends BaseController
{
    use NormalizedResponseTrait;

    protected $interviewModel;
    protected $historyModel;
    protected $applicationModel;
    protected $userModel;
    protected $notificationModel;
    protected $emailHelper;

    public function __construct()
    {
        $this->interviewModel     = new Interview();
        $this->historyModel       = new InterviewHistory();
        $this->applicationModel   = new Application();
        $this->userModel          = new User();
        $this->notificationModel  = new Notification();
        $this->emailHelper        = new EmailHelper();
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
     * employer_profiles has userId -> companyId relationship.
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
     * Enforce that an interview belongs to employer company via job.companyId (unless admin).
     */
    private function authorizeInterviewAccess($interviewId)
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

        // Check interview -> job -> company ownership
        $row = $db->table('interviews')
            ->select('interviews.id, jobs.companyId')
            ->join('jobs', 'jobs.id = interviews.jobId', 'inner')
            ->where('interviews.id', $interviewId)
            ->get()
            ->getRowArray();

        if (!$row) {
            return [false, $this->failNotFound('Interview not found')];
        }

        if (($row['companyId'] ?? null) !== $companyId) {
            return [false, $this->fail('Forbidden: not your company interview', 403)];
        }

        return [true, null];
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

        $db = \Config\Database::connect();
        $job = $db->table('jobs')->where('id', $jobId)->get()->getRowArray();
        
        if (!$job) {
            return [false, $this->failNotFound('Job not found')];
        }

        if (($job['companyId'] ?? null) !== $companyId) {
            return [false, $this->fail('Forbidden: not your company job', 403)];
        }

        return [true, null];
    }

    /**
     * @OA\Post(
     *     path="/api/interviews",
     *     tags={"Interviews"},
     *     summary="Schedule a new interview",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="201", description="Interview scheduled successfully")
     * )
     */
    public function createInterview()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        if (!in_array($user->role, ['EMPLOYER', 'HR_MANAGER', 'SUPER_ADMIN'])) {
            return $this->fail('You do not have permission to schedule interviews', 403);
        }

        try {
            $data = $this->request->getJSON(true);

            $data = $this->sanitizeInterviewData($data);

            $rules = [
                'applicationId' => 'required|string',
                'jobId'         => 'required|string',
                'candidateId'   => 'required|string',
                'scheduledAt'   => 'required',
                'type'          => 'required|in_list[PHONE,VIDEO,IN_PERSON,TECHNICAL,HR_SCREENING,PANEL]'
            ];

            if (!$this->validate($rules)) {
                return $this->fail($this->validator->getErrors(), 400);
            }

            // ✅ AUTHZ: Ensure job belongs to employer's company
            [$ok, $resp] = $this->authorizeJobAccess($data['jobId']);
            if (!$ok)
                return $resp;

            $application = $this->applicationModel->find($data['applicationId']);
            if (!$application) {
                return $this->fail('Application not found', 404);
            }

            $candidate = $this->userModel->find($data['candidateId']);
            if (!$candidate) {
                return $this->fail('Candidate not found', 404);
            }

            $scheduledAtClean = $this->emailHelper->normalizeDateTimeString((string)$data['scheduledAt']);

            $conflict = $this->interviewModel
                ->where('candidateId', $data['candidateId'])
                ->where('scheduledAt', $scheduledAtClean)
                ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
                ->first();

            if ($conflict) {
                return $this->fail('Candidate already has an interview scheduled at this time', 400);
            }

            $interviewData = [
                'id'               => uniqid('interview_'),
                'applicationId'    => $data['applicationId'],
                'jobId'            => $data['jobId'],
                'candidateId'      => $data['candidateId'],
                'scheduledAt'      => $scheduledAtClean,
                'duration'         => $data['duration'] ?? 60,
                'status'           => 'SCHEDULED',
                'type'             => $data['type'],
                'location'         => $data['location'] ?? null,
                'meetingLink'      => $data['meetingLink'] ?? null,
                'meetingId'        => $data['meetingId'] ?? null,
                'meetingPassword'  => $data['meetingPassword'] ?? null,
                'interviewerName'  => $data['interviewerName'] ?? null,
                'interviewerId'    => $data['interviewerId'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'reminderSent'     => false,
                'createdBy'        => $user->id
            ];

            $this->interviewModel->insert($interviewData);

            $this->applicationModel->update($data['applicationId'], [
                'status' => 'INTERVIEW'
            ]);

            $interview = $this->getInterviewWithDetailsScoped($interviewData['id'], $user);

            $this->sendInterviewNotification($interview, 'scheduled');

            $this->createNotification(
                $data['candidateId'],
                'INTERVIEW_SCHEDULED',
                'Interview Scheduled',
                'Your interview has been scheduled for ' . date('F j, Y \a\t g:i A', strtotime($scheduledAtClean))
            );

            $response = [
                'success' => true,
                'message' => 'Interview scheduled successfully',
                'data'    => $interview
            ];

            return $this->respondCreated(DataTypeHelper::normalizeResponse($response));
        } catch (\Exception $e) {
            log_message('error', 'Interview creation failed: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return $this->fail('Failed to schedule interview: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/interviews",
     *     tags={"Interviews"},
     *     summary="Search interviews with filters",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Interviews retrieved")
     * )
     */
    public function searchInterviews()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $filters = [
                'query'         => $this->request->getGet('query'),
                'status'        => $this->request->getGet('status'),
                'type'          => $this->request->getGet('type'),
                'jobId'         => $this->request->getGet('jobId'),
                'candidateId'   => $this->request->getGet('candidateId'),
                'interviewerId' => $this->request->getGet('interviewerId'),
                'startDate'     => $this->request->getGet('startDate'),
                'endDate'       => $this->request->getGet('endDate'),
                'page'          => $this->request->getGet('page') ?? 1,
                'limit'         => $this->request->getGet('limit') ?? 20
            ];

            // ✅ COMPANY SCOPING: Get companyId for non-admin users
            $companyId = null;
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId) {
                    return $this->respond([
                        'success' => true,
                        'message' => 'Interviews retrieved successfully',
                        'data'    => [
                            'interviews' => [],
                            'pagination' => [
                                'total' => 0,
                                'page' => 1,
                                'limit' => $filters['limit'],
                                'totalPages' => 0
                            ]
                        ]
                    ]);
                }
            }

            $result = $this->searchInterviewsScoped($filters, $user, $companyId);

            $response = [
                'success' => true,
                'message' => 'Interviews retrieved successfully',
                'data'    => $result
            ];

            return $this->respond(DataTypeHelper::normalizeResponse($response));
        } catch (\Exception $e) {
            log_message('error', 'Interview search failed: ' . $e->getMessage());
            return $this->fail('Failed to retrieve interviews', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/interviews/{id}",
     *     tags={"Interviews"},
     *     summary="Get interview by ID",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Interview details")
     * )
     */
    public function getInterviewById($id = null)
    {
        if (!$id) {
            $id = $this->request->getUri()->getSegment(3);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            // ✅ AUTHZ
            [$ok, $resp] = $this->authorizeInterviewAccess($id);
            if (!$ok)
                return $resp;

            $interview = $this->getInterviewWithDetailsScoped($id, $user);

            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            $response = [
                'success' => true,
                'message' => 'Interview retrieved successfully',
                'data'    => $interview
            ];

            return $this->respond(DataTypeHelper::normalizeResponse($response));
        } catch (\Exception $e) {
            log_message('error', 'Get interview failed: ' . $e->getMessage());
            return $this->fail('Failed to retrieve interview', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/interviews/{id}",
     *     tags={"Interviews"},
     *     summary="Update interview",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Interview updated")
     * )
     */
    public function updateInterview($id = null)
    {
        if (!$id) {
            $id = $this->request->getUri()->getSegment(3);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            // ✅ AUTHZ
            [$ok, $resp] = $this->authorizeInterviewAccess($id);
            if (!$ok)
                return $resp;

            $interview = $this->interviewModel->find($id);
            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            $data = $this->request->getJSON(true);
            
            $data = $this->sanitizeInterviewData($data);
            
            $oldStatus = $interview['status'];

            if (isset($data['scheduledAt']) && $data['scheduledAt'] !== null) {
                $data['scheduledAt'] = $this->emailHelper->normalizeDateTimeString((string)$data['scheduledAt']);
            }

            $updateData = array_filter($data, function ($value) {
                return $value !== null;
            });

            $this->interviewModel->update($id, $updateData);

            if (isset($data['status']) && $data['status'] !== $oldStatus) {
                $this->historyModel->insert([
                    'interviewId' => $id,
                    'fromStatus'  => $oldStatus,
                    'toStatus'    => $data['status'],
                    'changedBy'   => $user->id,
                    'notes'       => "Status changed from {$oldStatus} to {$data['status']}"
                ]);

                $interview = $this->getInterviewWithDetailsScoped($id, $user);
                $this->sendInterviewNotification($interview, 'status_changed');
            }

            $updated = $this->getInterviewWithDetailsScoped($id, $user);

            $response = [
                'success' => true,
                'message' => 'Interview updated successfully',
                'data'    => $updated
            ];

            return $this->respond(DataTypeHelper::normalizeResponse($response));
        } catch (\Exception $e) {
            log_message('error', 'Interview update failed: ' . $e->getMessage());
            return $this->fail('Failed to update interview', 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/interviews/{id}",
     *     tags={"Interviews"},
     *     summary="Delete interview",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Interview deleted")
     * )
     */
    public function deleteInterview($id = null)
    {
        if (!$id) {
            $id = $this->request->getUri()->getSegment(3);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            // ✅ AUTHZ
            [$ok, $resp] = $this->authorizeInterviewAccess($id);
            if (!$ok)
                return $resp;

            $interview = $this->interviewModel->find($id);
            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            $this->interviewModel->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Interview deleted successfully'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Interview deletion failed: ' . $e->getMessage());
            return $this->fail('Failed to delete interview', 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/interviews/bulk-update",
     *     tags={"Interviews"},
     *     summary="Bulk update interview status",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Interviews updated")
     * )
     */
    public function bulkUpdateStatus()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $data = $this->request->getJSON(true);

            if (empty($data['interviewIds']) || !is_array($data['interviewIds'])) {
                return $this->fail('Interview IDs are required', 400);
            }

            if (empty($data['newStatus'])) {
                return $this->fail('New status is required', 400);
            }

            $updated = 0;

            foreach ($data['interviewIds'] as $interviewId) {
                // ✅ AUTHZ per interview
                [$ok, $resp] = $this->authorizeInterviewAccess($interviewId);
                if (!$ok) {
                    // skip unauthorized IDs silently
                    continue;
                }

                $interview = $this->interviewModel->find($interviewId);
                if (!$interview) {
                    continue;
                }

                $oldStatus = $interview['status'];

                $this->interviewModel->update($interviewId, ['status' => $data['newStatus']]);

                $this->historyModel->insert([
                    'interviewId' => $interviewId,
                    'fromStatus'  => $oldStatus,
                    'toStatus'    => $data['newStatus'],
                    'changedBy'   => $user->id,
                    'reason'      => $data['reason'] ?? "Bulk status update to {$data['newStatus']}"
                ]);

                $updated++;
            }

            return $this->respond([
                'success' => true,
                'message' => count($data['interviewIds']) . ' interview(s) processed, ' . $updated . ' updated successfully',
                'data'    => ['count' => $updated]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Bulk update failed: ' . $e->getMessage());
            return $this->fail('Failed to update interviews', 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/interviews/{id}/send-reminder",
     *     tags={"Interviews"},
     *     summary="Send interview reminder",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Reminder sent")
     * )
     */
    public function sendReminder($id = null)
    {
        if (!$id) {
            $id = $this->request->getUri()->getSegment(3);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            // ✅ AUTHZ
            [$ok, $resp] = $this->authorizeInterviewAccess($id);
            if (!$ok)
                return $resp;

            $interview = $this->getInterviewWithDetailsScoped($id, $user);
            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            $this->sendInterviewNotification($interview, 'reminder');

            $this->interviewModel->update($id, [
                'reminderSent'   => true,
                'reminderSentAt' => date('Y-m-d H:i:s')
            ]);

            $cleanScheduled = $this->emailHelper->normalizeDateTimeString((string)($interview['scheduledAt'] ?? ''));

            $this->createNotification(
                $interview['candidateId'],
                'INTERVIEW_REMINDER',
                'Interview Reminder',
                'Reminder: Your interview is scheduled for ' . date('F j, Y \a\t g:i A', strtotime($cleanScheduled))
            );

            return $this->respond([
                'success' => true,
                'message' => 'Reminder sent successfully'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Send reminder failed: ' . $e->getMessage());
            return $this->fail('Failed to send reminder', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/interviews/upcoming",
     *     tags={"Interviews"},
     *     summary="Get upcoming interviews",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Upcoming interviews")
     * )
     */
    public function getUpcomingInterviews()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            // ✅ COMPANY SCOPING
            $companyId = null;
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId) {
                    return $this->respond([
                        'success' => true,
                        'message' => 'Upcoming interviews retrieved successfully',
                        'data'    => []
                    ]);
                }
            }

            $interviews = $this->getUpcomingInterviewsScoped($user, $companyId);

            $response = [
                'success' => true,
                'message' => 'Upcoming interviews retrieved successfully',
                'data'    => $interviews
            ];

            return $this->respond(DataTypeHelper::normalizeResponse($response));
        } catch (\Exception $e) {
            log_message('error', 'Get upcoming interviews failed: ' . $e->getMessage());
            return $this->fail('Failed to retrieve upcoming interviews', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/interviews/statistics",
     *     tags={"Interviews"},
     *     summary="Get interview statistics",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Interview statistics")
     * )
     */
    public function getStatistics()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            // ✅ COMPANY SCOPING
            $companyId = null;
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId) {
                    return $this->respond([
                        'success' => true,
                        'message' => 'Statistics retrieved successfully',
                        'data'    => [
                            'total' => 0,
                            'scheduled' => 0,
                            'completed' => 0,
                            'cancelled' => 0,
                            'in_progress' => 0
                        ]
                    ]);
                }
            }

            $stats = $this->getStatisticsScoped($user, $companyId);

            $response = [
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data'    => $stats
            ];

            return $this->respond(DataTypeHelper::normalizeResponse($response));
        } catch (\Exception $e) {
            log_message('error', 'Get statistics failed: ' . $e->getMessage());
            return $this->fail('Failed to retrieve statistics', 500);
        }
    }

    // ============================================================
    // COMPANY-SCOPED DATA RETRIEVAL METHODS
    // ============================================================

    /**
     * Get interview with details (company-scoped)
     */
    private function getInterviewWithDetailsScoped($interviewId, $user)
    {
        try {
            $db = \Config\Database::connect();
            $builder = $db->table('interviews');

            $builder->select('interviews.*, 
                users.id as candidate_id, users.firstName as candidate_firstName, users.lastName as candidate_lastName, 
                users.email as candidate_email, users.phone as candidate_phone, users.avatar as candidate_avatar,
                jobs.id as job_id, jobs.title as job_title,
                companies.id as company_id, companies.name as company_name, companies.logo as company_logo')
                ->join('users', 'users.id = interviews.candidateId', 'left')
                ->join('jobs', 'jobs.id = interviews.jobId', 'left')
                ->join('companies', 'companies.id = jobs.companyId', 'left')
                ->where('interviews.id', $interviewId);

            // ✅ Company filter for non-admins
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId)
                    return null;
                $builder->where('jobs.companyId', $companyId);
            }

            $row = $builder->get()->getRowArray();

            if (!$row)
                return null;

            return [
                'id' => $row['id'],
                'applicationId' => $row['applicationId'],
                'jobId' => $row['jobId'],
                'candidateId' => $row['candidateId'],
                'scheduledAt' => $row['scheduledAt'],
                'duration' => $row['duration'],
                'status' => $row['status'],
                'type' => $row['type'],
                'location' => $row['location'] ?? null,
                'meetingLink' => $row['meetingLink'] ?? null,
                'meetingId' => $row['meetingId'] ?? null,
                'meetingPassword' => $row['meetingPassword'] ?? null,
                'interviewerName' => $row['interviewerName'] ?? null,
                'interviewerId' => $row['interviewerId'] ?? null,
                'notes' => $row['notes'] ?? null,
                'reminderSent' => (bool)($row['reminderSent'] ?? false),
                'createdBy' => $row['createdBy'] ?? null,
                'createdAt' => $row['createdAt'] ?? null,
                'updatedAt' => $row['updatedAt'] ?? null,
                'candidate' => [
                    'id' => $row['candidate_id'],
                    'firstName' => $row['candidate_firstName'] ?? '',
                    'lastName' => $row['candidate_lastName'] ?? '',
                    'email' => $row['candidate_email'] ?? '',
                    'phone' => $row['candidate_phone'] ?? null,
                    'avatar' => $row['candidate_avatar'] ?? null
                ],
                'job' => [
                    'id' => $row['job_id'],
                    'title' => $row['job_title'] ?? '',
                    'company' => [
                        'id' => $row['company_id'] ?? '',
                        'name' => $row['company_name'] ?? '',
                        'logo' => $row['company_logo'] ?? null
                    ]
                ]
            ];
        } catch (\Exception $e) {
            log_message('error', 'getInterviewWithDetailsScoped error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Search interviews (company-scoped)
     */
    private function searchInterviewsScoped($filters, $user, $companyId = null)
    {
        try {
            $db = \Config\Database::connect();
            $builder = $db->table('interviews');

            $builder->select('interviews.*, 
                users.id as candidate_id, users.firstName as candidate_firstName, users.lastName as candidate_lastName, 
                users.email as candidate_email, users.phone as candidate_phone,
                jobs.id as job_id, jobs.title as job_title,
                companies.id as company_id, companies.name as company_name')
                ->join('users', 'users.id = interviews.candidateId', 'left')
                ->join('jobs', 'jobs.id = interviews.jobId', 'left')
                ->join('companies', 'companies.id = jobs.companyId', 'left');

            // ✅ Company filter
            if ($companyId) {
                $builder->where('jobs.companyId', $companyId);
            }

            // Apply filters
            if (!empty($filters['status'])) {
                $builder->where('interviews.status', $filters['status']);
            }

            if (!empty($filters['type'])) {
                $builder->where('interviews.type', $filters['type']);
            }

            if (!empty($filters['jobId'])) {
                $builder->where('interviews.jobId', $filters['jobId']);
            }

            if (!empty($filters['candidateId'])) {
                $builder->where('interviews.candidateId', $filters['candidateId']);
            }

            if (!empty($filters['interviewerId'])) {
                $builder->where('interviews.interviewerId', $filters['interviewerId']);
            }

            if (!empty($filters['startDate'])) {
                $builder->where('interviews.scheduledAt >=', $filters['startDate']);
            }

            if (!empty($filters['endDate'])) {
                $builder->where('interviews.scheduledAt <=', $filters['endDate']);
            }

            if (!empty($filters['query'])) {
                $builder->groupStart()
                    ->like('users.firstName', $filters['query'])
                    ->orLike('users.lastName', $filters['query'])
                    ->orLike('users.email', $filters['query'])
                    ->orLike('jobs.title', $filters['query'])
                    ->groupEnd();
            }

            // Get total count
            $total = $builder->countAllResults(false);

            // Get paginated results
            $page = (int)($filters['page'] ?? 1);
            $limit = (int)($filters['limit'] ?? 20);
            $skip = ($page - 1) * $limit;

            $interviews = $builder
                ->orderBy('interviews.scheduledAt', 'DESC')
                ->limit($limit, $skip)
                ->get()
                ->getResultArray();

            $formattedInterviews = array_map(function($row) {
                return [
                    'id' => $row['id'],
                    'applicationId' => $row['applicationId'],
                    'jobId' => $row['jobId'],
                    'candidateId' => $row['candidateId'],
                    'scheduledAt' => $row['scheduledAt'],
                    'duration' => $row['duration'],
                    'status' => $row['status'],
                    'type' => $row['type'],
                    'location' => $row['location'] ?? null,
                    'meetingLink' => $row['meetingLink'] ?? null,
                    'interviewerName' => $row['interviewerName'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'reminderSent' => (bool)($row['reminderSent'] ?? false),
                    'createdAt' => $row['createdAt'] ?? null,
                    'candidate' => [
                        'id' => $row['candidate_id'],
                        'firstName' => $row['candidate_firstName'] ?? '',
                        'lastName' => $row['candidate_lastName'] ?? '',
                        'email' => $row['candidate_email'] ?? '',
                        'phone' => $row['candidate_phone'] ?? null
                    ],
                    'job' => [
                        'id' => $row['job_id'],
                        'title' => $row['job_title'] ?? ''
                    ],
                    'company' => [
                        'id' => $row['company_id'] ?? '',
                        'name' => $row['company_name'] ?? ''
                    ]
                ];
            }, $interviews);

            return [
                'interviews' => $formattedInterviews,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => ceil($total / $limit)
                ]
            ];
        } catch (\Exception $e) {
            log_message('error', 'searchInterviewsScoped error: ' . $e->getMessage());
            return [
                'interviews' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => 1,
                    'limit' => 20,
                    'totalPages' => 0
                ]
            ];
        }
    }

    /**
     * Get upcoming interviews (company-scoped)
     */
    private function getUpcomingInterviewsScoped($user, $companyId = null)
    {
        try {
            $db = \Config\Database::connect();
            $builder = $db->table('interviews');

            $builder->select('interviews.*, 
                users.id as candidate_id, users.firstName as candidate_firstName, users.lastName as candidate_lastName, 
                users.email as candidate_email,
                jobs.id as job_id, jobs.title as job_title')
                ->join('users', 'users.id = interviews.candidateId', 'left')
                ->join('jobs', 'jobs.id = interviews.jobId', 'left');

            // ✅ Company filter
            if ($companyId) {
                $builder->join('companies', 'companies.id = jobs.companyId', 'left')
                    ->where('jobs.companyId', $companyId);
            }

            $builder->where('interviews.scheduledAt >=', date('Y-m-d H:i:s'))
                ->whereIn('interviews.status', ['SCHEDULED', 'IN_PROGRESS'])
                ->orderBy('interviews.scheduledAt', 'ASC')
                ->limit(10);

            $rows = $builder->get()->getResultArray();

            return array_map(function($row) {
                return [
                    'id' => $row['id'],
                    'scheduledAt' => $row['scheduledAt'],
                    'duration' => $row['duration'],
                    'status' => $row['status'],
                    'type' => $row['type'],
                    'location' => $row['location'] ?? null,
                    'meetingLink' => $row['meetingLink'] ?? null,
                    'candidate' => [
                        'id' => $row['candidate_id'],
                        'firstName' => $row['candidate_firstName'] ?? '',
                        'lastName' => $row['candidate_lastName'] ?? '',
                        'email' => $row['candidate_email'] ?? ''
                    ],
                    'job' => [
                        'id' => $row['job_id'],
                        'title' => $row['job_title'] ?? ''
                    ]
                ];
            }, $rows);
        } catch (\Exception $e) {
            log_message('error', 'getUpcomingInterviewsScoped error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get statistics (company-scoped)
     */
    private function getStatisticsScoped($user, $companyId = null)
    {
        try {
            $db = \Config\Database::connect();
            $builder = $db->table('interviews');

            $builder->select('interviews.status, COUNT(*) as count')
                ->join('jobs', 'jobs.id = interviews.jobId', 'inner');

            // ✅ Company filter
            if ($companyId) {
                $builder->where('jobs.companyId', $companyId);
            }

            $builder->groupBy('interviews.status');

            $rows = $builder->get()->getResultArray();

            $stats = [
                'total' => 0,
                'scheduled' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'in_progress' => 0,
                'no_show' => 0
            ];

            foreach ($rows as $row) {
                $status = strtolower($row['status'] ?? '');
                $count = (int)($row['count'] ?? 0);
                $stats['total'] += $count;

                if (isset($stats[$status])) {
                    $stats[$status] = $count;
                }
            }

            return $stats;
        } catch (\Exception $e) {
            log_message('error', 'getStatisticsScoped error: ' . $e->getMessage());
            return [
                'total' => 0,
                'scheduled' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'in_progress' => 0,
                'no_show' => 0
            ];
        }
    }

    // ============================================================
    // DATA SANITIZATION
    // ============================================================

    /**
     * Sanitize all text fields to prevent encoding issues
     */
    private function sanitizeInterviewData(array $data): array
    {
        $textFields = [
            'notes', 'location', 'interviewerName', 'meetingLink', 
            'meetingPassword', 'meetingId', 'type', 'status'
        ];

        foreach ($textFields as $field) {
            if (isset($data[$field]) && $data[$field] !== null) {
                $data[$field] = $this->cleanUtf8((string)$data[$field]);
            }
        }

        return $data;
    }

    /**
     * Ensure clean UTF-8 string
     */
    private function cleanUtf8(string $text): string
    {
        // Remove null bytes
        $text = str_replace("\0", '', $text);
        
        // Convert to UTF-8 if needed
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
        
        // Remove invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        return trim($text);
    }

    // ============================================================
    // EMAIL NOTIFICATIONS
    // ============================================================

    private function sendInterviewNotification($interview, $type = 'scheduled')
    {
        try {
            $candidate = $interview['candidate'] ?? [];
            $job       = $interview['job'] ?? [];

            if (empty($candidate['email']) || empty($job['title'])) {
                return;
            }

            $candidateName = trim(($candidate['firstName'] ?? '') . ' ' . ($candidate['lastName'] ?? ''));
            if ($candidateName === '') {
                $candidateName = 'Candidate';
            }

            $scheduledAt = $interview['scheduledAt'] ?? null;
            if (!$scheduledAt) {
                return;
            }

            $scheduledAtClean   = $this->emailHelper->normalizeDateTimeString((string)$scheduledAt);
            $scheduledDateHuman = date('F j, Y \a\t g:i A', strtotime($scheduledAtClean));

            switch ($type) {
                case 'scheduled':
                    $this->emailHelper->sendInterviewInvitation(
                        $candidate['email'],
                        $candidateName,
                        $job['title'],
                        $scheduledAtClean,
                        [
                            'type'        => $interview['type'] ?? 'VIDEO',
                            'duration'    => $interview['duration'] ?? 60,
                            'meetingLink' => $interview['meetingLink'] ?? null,
                            'location'    => $interview['location'] ?? null,
                            'notes'       => $interview['notes'] ?? null,
                            'timezone'    => 'Africa/Nairobi'
                        ]
                    );
                    break;

                case 'reminder':
                    $subject = 'Interview Reminder - ' . $job['title'];
                    $message = $this->buildReminderEmail($candidateName, $job['title'], $scheduledDateHuman, $interview);
                    $this->emailHelper->sendRecruitmentEmail($candidate['email'], $subject, $message, true);
                    break;

                case 'status_changed':
                    $subject = 'Interview Status Update - ' . $job['title'];
                    $message = $this->buildStatusChangeEmail($candidateName, $job['title'], $interview['status'] ?? 'UNKNOWN');
                    $this->emailHelper->sendRecruitmentEmail($candidate['email'], $subject, $message, true);
                    break;
            }
        } catch (\Exception $e) {
            log_message('error', 'Failed to send interview notification: ' . $e->getMessage());
        }
    }

    // ============================================================
    // EMAIL BUILDERS
    // ============================================================

    private function buildReminderEmail($candidateName, $jobTitle, $scheduledDate, $interview)
    {
        $safeJobTitle      = htmlspecialchars($jobTitle);
        $safeScheduledDate = htmlspecialchars($scheduledDate);

        $type     = $interview['type'] ?? 'VIDEO';
        $isOnline = in_array($type, ['VIDEO', 'PHONE'], true);
        $category = $isOnline ? 'Online Interview' : 'Physical Interview';

        $meetingLink = $interview['meetingLink'] ?? null;
        $location    = $interview['location'] ?? null;

        $linkHtml = ($isOnline && !empty($meetingLink))
            ? "<p><strong>Meeting Link:</strong> <a href='" . htmlspecialchars($meetingLink) . "'>" . htmlspecialchars($meetingLink) . "</a></p>"
            : "";

        $locationHtml = (!$isOnline && !empty($location))
            ? "<p><strong>Location:</strong> " . htmlspecialchars($location) . "</p>"
            : "";

        $inner = "
<p>Dear " . htmlspecialchars($candidateName) . ",</p>

<p>This is a friendly reminder about your upcoming interview:</p>

<p><strong>Interview Details</strong></p>
<p><strong>Category:</strong> " . htmlspecialchars($category) . "</p>
<p><strong>Position:</strong> {$safeJobTitle}</p>
<p><strong>Date &amp; Time:</strong> {$safeScheduledDate}</p>
{$linkHtml}
{$locationHtml}

<p style='margin-top:16px;'><strong>Important Reminders</strong></p>
<ul>
  <li>Please join at least <strong>5 minutes early</strong>.</li>" .
            ($isOnline
                ? "<li>Ensure you have <strong>stable internet</strong> and working <strong>microphone/camera</strong>.</li>"
                : "<li>Plan your route and arrive on time.</li>"
            ) . "
</ul>

<p>Kind regards,<br>
<strong>Fortune Kenya Recruitment Team</strong><br>
headhunting@fortunekenya.com</p>
";

        return $this->emailHelper->wrapEmailHtml('Interview Reminder', $inner);
    }

    private function buildStatusChangeEmail($candidateName, $jobTitle, $newStatus)
    {
        $safeStatus   = htmlspecialchars(str_replace('_', ' ', (string)$newStatus));
        $safeJobTitle = htmlspecialchars($jobTitle);

        $inner = "
<p>Dear " . htmlspecialchars($candidateName) . ",</p>

<p>The status of your interview for <strong>{$safeJobTitle}</strong> has been updated.</p>

<p><strong>New Status:</strong> <span style='color:#1a73e8; font-weight:700;'>{$safeStatus}</span></p>

<p>If you have any questions, please contact us at <strong>headhunting@fortunekenya.com</strong>.</p>

<p>Kind regards,<br>
<strong>Fortune Kenya Recruitment Team</strong><br>
headhunting@fortunekenya.com</p>
";

        return $this->emailHelper->wrapEmailHtml('Interview Status Update', $inner);
    }

    // ============================================================
    // NOTIFICATION
    // ============================================================

    private function createNotification($userId, $type, $title, $message)
    {
        try {
            $this->notificationModel->insert([
                'id'      => uniqid('notif_'),
                'userId'  => $userId,
                'type'    => $type,
                'title'   => $title,
                'message' => $message,
                'isRead'  => false
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to create notification: ' . $e->getMessage());
        }
    }
}
