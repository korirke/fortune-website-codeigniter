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
        $this->interviewModel = new Interview();
        $this->historyModel = new InterviewHistory();
        $this->applicationModel = new Application();
        $this->userModel = new User();
        $this->notificationModel = new Notification();
        $this->emailHelper = new EmailHelper();
    }

    /**
     * @OA\Post(
     *     path="/api/interviews",
     *     tags={"Interviews"},
     *     summary="Schedule a new interview",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"applicationId", "jobId", "candidateId", "scheduledAt", "type"},
     *             @OA\Property(property="applicationId", type="string", example="app_123"),
     *             @OA\Property(property="jobId", type="string", example="job_456"),
     *             @OA\Property(property="candidateId", type="string", example="user_789"),
     *             @OA\Property(property="scheduledAt", type="string", format="date-time", example="2024-12-25T10:00:00"),
     *             @OA\Property(property="duration", type="integer", example=60),
     *             @OA\Property(property="type", type="string", enum={"PHONE", "VIDEO", "IN_PERSON", "TECHNICAL", "HR_SCREENING", "PANEL"}),
     *             @OA\Property(property="location", type="string", example="Office Room 301"),
     *             @OA\Property(property="meetingLink", type="string", example="https://meet.google.com/abc-defg-hij"),
     *             @OA\Property(property="meetingId", type="string", example="123-456-789"),
     *             @OA\Property(property="meetingPassword", type="string", example="secret123"),
     *             @OA\Property(property="interviewerName", type="string", example="John Smith"),
     *             @OA\Property(property="interviewerId", type="string", example="user_999"),
     *             @OA\Property(property="notes", type="string", example="Technical interview for backend position")
     *         )
     *     ),
     *     @OA\Response(response="201", description="Interview scheduled successfully")
     * )
     */
    public function createInterview()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        // Authorization: Only EMPLOYER, HR_MANAGER, SUPER_ADMIN can create interviews
        if (!in_array($user->role, ['EMPLOYER', 'HR_MANAGER', 'SUPER_ADMIN'])) {
            return $this->fail('You do not have permission to schedule interviews', 403);
        }

        try {
            $data = $this->request->getJSON(true);

            // Validate required fields
            $rules = [
                'applicationId' => 'required|string',
                'jobId' => 'required|string',
                'candidateId' => 'required|string',
                'scheduledAt' => 'required',
                'type' => 'required|in_list[PHONE,VIDEO,IN_PERSON,TECHNICAL,HR_SCREENING,PANEL]'
            ];

            if (!$this->validate($rules)) {
                return $this->fail($this->validator->getErrors(), 400);
            }

            // Verify application exists
            $application = $this->applicationModel->find($data['applicationId']);
            if (!$application) {
                return $this->fail('Application not found', 404);
            }

            // Verify candidate exists
            $candidate = $this->userModel->find($data['candidateId']);
            if (!$candidate) {
                return $this->fail('Candidate not found', 404);
            }

            // Check for scheduling conflicts
            $conflict = $this->interviewModel
                ->where('candidateId', $data['candidateId'])
                ->where('scheduledAt', $data['scheduledAt'])
                ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
                ->first();

            if ($conflict) {
                return $this->fail('Candidate already has an interview scheduled at this time', 400);
            }

            // Create interview
            $interviewData = [
                'id' => uniqid('interview_'),
                'applicationId' => $data['applicationId'],
                'jobId' => $data['jobId'],
                'candidateId' => $data['candidateId'],
                'scheduledAt' => $data['scheduledAt'],
                'duration' => $data['duration'] ?? 60,
                'status' => 'SCHEDULED',
                'type' => $data['type'],
                'location' => $data['location'] ?? null,
                'meetingLink' => $data['meetingLink'] ?? null,
                'meetingId' => $data['meetingId'] ?? null,
                'meetingPassword' => $data['meetingPassword'] ?? null,
                'interviewerName' => $data['interviewerName'] ?? null,
                'interviewerId' => $data['interviewerId'] ?? null,
                'notes' => $data['notes'] ?? null,
                'reminderSent' => false,
                'createdBy' => $user->id
            ];

            $this->interviewModel->insert($interviewData);

            // Update application status to INTERVIEW
            $this->applicationModel->update($data['applicationId'], [
                'status' => 'INTERVIEW'
            ]);

            // Get full interview details
            $interview = $this->interviewModel->getInterviewWithDetails($interviewData['id'], $user->id, $user->role);

            // Send email notification to candidate
            $this->sendInterviewNotification($interview, 'scheduled');

            // Create notification
            $this->createNotification(
                $data['candidateId'],
                'INTERVIEW_SCHEDULED',
                'Interview Scheduled',
                'Your interview has been scheduled for ' . date('F j, Y \a\t g:i A', strtotime($data['scheduledAt']))
            );

            // Normalize response
            $response = [
                'success' => true,
                'message' => 'Interview scheduled successfully',
                'data' => $interview
            ];

            return $this->respondCreated(DataTypeHelper::normalizeResponse($response));
        } catch (\Exception $e) {
            log_message('error', 'Interview creation failed: ' . $e->getMessage());
            return $this->fail('Failed to schedule interview: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/interviews",
     *     tags={"Interviews"},
     *     summary="Search interviews with filters",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="query", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="type", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer")),
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
                'query' => $this->request->getGet('query'),
                'status' => $this->request->getGet('status'),
                'type' => $this->request->getGet('type'),
                'jobId' => $this->request->getGet('jobId'),
                'candidateId' => $this->request->getGet('candidateId'),
                'interviewerId' => $this->request->getGet('interviewerId'),
                'startDate' => $this->request->getGet('startDate'),
                'endDate' => $this->request->getGet('endDate'),
                'page' => $this->request->getGet('page') ?? 1,
                'limit' => $this->request->getGet('limit') ?? 20
            ];

            $result = $this->interviewModel->searchInterviews($filters, $user->id, $user->role);

            $response = [
                'success' => true,
                'message' => 'Interviews retrieved successfully',
                'data' => $result
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
            $interview = $this->interviewModel->getInterviewWithDetails($id, $user->id, $user->role);

            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            $response = [
                'success' => true,
                'message' => 'Interview retrieved successfully',
                'data' => $interview
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
            $interview = $this->interviewModel->find($id);
            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            // Authorization check
            $canUpdate = false;
            if (in_array($user->role, ['SUPER_ADMIN', 'HR_MANAGER'])) {
                $canUpdate = true;
            } elseif ($user->role === 'EMPLOYER') {
                // Check if user belongs to the company
                $employerProfile = $this->db->table('employer_profiles')
                    ->where('userId', $user->id)
                    ->get()
                    ->getRowArray();

                if ($employerProfile) {
                    $job = $this->db->table('jobs')->where('id', $interview['jobId'])->get()->getRowArray();
                    if ($job && $job['companyId'] === $employerProfile['companyId']) {
                        $canUpdate = true;
                    }
                }
            }

            if (!$canUpdate) {
                return $this->fail('You do not have permission to update this interview', 403);
            }

            $data = $this->request->getJSON(true);
            $oldStatus = $interview['status'];

            // Update interview
            $updateData = array_filter($data, function ($value) {
                return $value !== null;
            });

            $this->interviewModel->update($id, $updateData);

            // Track status change
            if (isset($data['status']) && $data['status'] !== $oldStatus) {
                $this->historyModel->insert([
                    'interviewId' => $id,
                    'fromStatus' => $oldStatus,
                    'toStatus' => $data['status'],
                    'changedBy' => $user->id,
                    'notes' => "Status changed from {$oldStatus} to {$data['status']}"
                ]);

                // Send notification if status changed
                $interview = $this->interviewModel->getInterviewWithDetails($id, $user->id, $user->role);
                $this->sendInterviewNotification($interview, 'status_changed');
            }

            $updated = $this->interviewModel->getInterviewWithDetails($id, $user->id, $user->role);

            $response = [
                'success' => true,
                'message' => 'Interview updated successfully',
                'data' => $updated
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
            $interview = $this->interviewModel->find($id);
            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            // Authorization check (same as update)
            $canDelete = false;
            if (in_array($user->role, ['SUPER_ADMIN', 'HR_MANAGER'])) {
                $canDelete = true;
            } elseif ($user->role === 'EMPLOYER') {
                $employerProfile = $this->db->table('employer_profiles')
                    ->where('userId', $user->id)
                    ->get()
                    ->getRowArray();

                if ($employerProfile) {
                    $job = $this->db->table('jobs')->where('id', $interview['jobId'])->get()->getRowArray();
                    if ($job && $job['companyId'] === $employerProfile['companyId']) {
                        $canDelete = true;
                    }
                }
            }

            if (!$canDelete) {
                return $this->fail('You do not have permission to delete this interview', 403);
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

            $interviews = $this->interviewModel->whereIn('id', $data['interviewIds'])->findAll();
            if (count($interviews) !== count($data['interviewIds'])) {
                return $this->fail('Some interviews not found', 404);
            }

            // Update all interviews
            foreach ($data['interviewIds'] as $interviewId) {
                $interview = $this->interviewModel->find($interviewId);
                $oldStatus = $interview['status'];

                $this->interviewModel->update($interviewId, ['status' => $data['newStatus']]);

                // Track history
                $this->historyModel->insert([
                    'interviewId' => $interviewId,
                    'fromStatus' => $oldStatus,
                    'toStatus' => $data['newStatus'],
                    'changedBy' => $user->id,
                    'reason' => $data['reason'] ?? "Bulk status update to {$data['newStatus']}"
                ]);
            }

            return $this->respond([
                'success' => true,
                'message' => count($data['interviewIds']) . ' interview(s) updated successfully',
                'data' => ['count' => count($data['interviewIds'])]
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
            $interview = $this->interviewModel->getInterviewWithDetails($id, $user->id, $user->role);
            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            // Send reminder email
            $this->sendInterviewNotification($interview, 'reminder');

            // Update reminder status
            $this->interviewModel->update($id, [
                'reminderSent' => true,
                'reminderSentAt' => date('Y-m-d H:i:s')
            ]);

            // Create notification
            $this->createNotification(
                $interview['candidateId'],
                'INTERVIEW_REMINDER',
                'Interview Reminder',
                'Reminder: Your interview is scheduled for ' . date('F j, Y \a\t g:i A', strtotime($interview['scheduledAt']))
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
            $interviews = $this->interviewModel->getUpcomingInterviews($user->id, $user->role);

            $response = [
                'success' => true,
                'message' => 'Upcoming interviews retrieved successfully',
                'data' => $interviews
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
            $stats = $this->interviewModel->getStatistics($user->id, $user->role);

            $response = [
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data' => $stats
            ];

            return $this->respond(DataTypeHelper::normalizeResponse($response));
        } catch (\Exception $e) {
            log_message('error', 'Get statistics failed: ' . $e->getMessage());
            return $this->fail('Failed to retrieve statistics', 500);
        }
    }

    /**
     * Send interview notification email
     */
    private function sendInterviewNotification($interview, $type = 'scheduled')
    {
        try {
            $candidate = $interview['candidate'];
            $job = $interview['job'];

            $candidateName = $candidate['firstName'] . ' ' . $candidate['lastName'];
            $scheduledDate = date('F j, Y \a\t g:i A', strtotime($interview['scheduledAt']));

            $subject = '';
            $message = '';

            switch ($type) {
                case 'scheduled':
                    $subject = 'Interview Scheduled - ' . $job['title'];
                    $message = $this->buildScheduledEmail($candidateName, $job['title'], $scheduledDate, $interview);
                    break;
                case 'reminder':
                    $subject = 'Interview Reminder - ' . $job['title'];
                    $message = $this->buildReminderEmail($candidateName, $job['title'], $scheduledDate, $interview);
                    break;
                case 'status_changed':
                    $subject = 'Interview Status Update - ' . $job['title'];
                    $message = $this->buildStatusChangeEmail($candidateName, $job['title'], $interview['status']);
                    break;
            }

            $this->emailHelper->sendEmail(
                $candidate['email'],
                $subject,
                $message
            );
        } catch (\Exception $e) {
            log_message('error', 'Failed to send interview notification: ' . $e->getMessage());
        }
    }

    /**
     * Build scheduled interview email
     */
    private function buildScheduledEmail($candidateName, $jobTitle, $scheduledDate, $interview)
    {
        $interviewType = str_replace('_', ' ', $interview['type']);

        $html = "<h2>Interview Scheduled</h2>";
        $html .= "<p>Dear {$candidateName},</p>";
        $html .= "<p>We are pleased to inform you that your interview has been scheduled.</p>";
        $html .= "<div style='background: #f5f5f5; padding: 15px; margin: 20px 0; border-left: 4px solid #1b90ba;'>";
        $html .= "<p><strong>Position:</strong> {$jobTitle}</p>";
        $html .= "<p><strong>Date & Time:</strong> {$scheduledDate}</p>";
        $html .= "<p><strong>Interview Type:</strong> {$interviewType}</p>";
        $html .= "<p><strong>Duration:</strong> {$interview['duration']} minutes</p>";

        if ($interview['type'] === 'VIDEO' && $interview['meetingLink']) {
            $html .= "<p><strong>Meeting Link:</strong> <a href='{$interview['meetingLink']}'>{$interview['meetingLink']}</a></p>";
            if ($interview['meetingId']) {
                $html .= "<p><strong>Meeting ID:</strong> {$interview['meetingId']}</p>";
            }
            if ($interview['meetingPassword']) {
                $html .= "<p><strong>Password:</strong> {$interview['meetingPassword']}</p>";
            }
        }

        if ($interview['type'] === 'IN_PERSON' && $interview['location']) {
            $html .= "<p><strong>Location:</strong> {$interview['location']}</p>";
        }

        if ($interview['interviewerName']) {
            $html .= "<p><strong>Interviewer:</strong> {$interview['interviewerName']}</p>";
        }

        if ($interview['notes']) {
            $html .= "<p><strong>Notes:</strong> {$interview['notes']}</p>";
        }

        $html .= "</div>";
        $html .= "<p>Please be prepared and join on time. If you need to reschedule, please contact us as soon as possible.</p>";
        $html .= "<p>Good luck!</p>";

        return $html;
    }

    /**
     * Build reminder email
     */
    private function buildReminderEmail($candidateName, $jobTitle, $scheduledDate, $interview)
    {
        $html = "<h2>Interview Reminder</h2>";
        $html .= "<p>Dear {$candidateName},</p>";
        $html .= "<p>This is a friendly reminder about your upcoming interview:</p>";
        $html .= "<div style='background: #fff3cd; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107;'>";
        $html .= "<p><strong>Position:</strong> {$jobTitle}</p>";
        $html .= "<p><strong>Date & Time:</strong> {$scheduledDate}</p>";

        if ($interview['type'] === 'VIDEO' && $interview['meetingLink']) {
            $html .= "<p><strong>Meeting Link:</strong> <a href='{$interview['meetingLink']}'>{$interview['meetingLink']}</a></p>";
        }

        if ($interview['type'] === 'IN_PERSON' && $interview['location']) {
            $html .= "<p><strong>Location:</strong> {$interview['location']}</p>";
        }

        $html .= "</div>";
        $html .= "<p>We look forward to speaking with you!</p>";

        return $html;
    }

    /**
     * Build status change email
     */
    private function buildStatusChangeEmail($candidateName, $jobTitle, $newStatus)
    {
        $html = "<h2>Interview Status Update</h2>";
        $html .= "<p>Dear {$candidateName},</p>";
        $html .= "<p>The status of your interview for <strong>{$jobTitle}</strong> has been updated to: <strong>{$newStatus}</strong></p>";
        $html .= "<p>If you have any questions, please don't hesitate to contact us.</p>";

        return $html;
    }

    /**
     * Create notification
     */
    private function createNotification($userId, $type, $title, $message)
    {
        try {
            $this->notificationModel->insert([
                'id' => uniqid('notif_'),
                'userId' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'isRead' => false
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to create notification: ' . $e->getMessage());
        }
    }
}
