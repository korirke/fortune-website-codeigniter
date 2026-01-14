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

            $interview = $this->interviewModel->getInterviewWithDetails($interviewData['id'], $user->id, $user->role);

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

            $result = $this->interviewModel->searchInterviews($filters, $user->id, $user->role);

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
            $interview = $this->interviewModel->getInterviewWithDetails($id, $user->id, $user->role);

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
            $interview = $this->interviewModel->find($id);
            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

            $canUpdate = false;
            if (in_array($user->role, ['SUPER_ADMIN', 'HR_MANAGER'])) {
                $canUpdate = true;
            } elseif ($user->role === 'EMPLOYER') {
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

                $interview = $this->interviewModel->getInterviewWithDetails($id, $user->id, $user->role);
                $this->sendInterviewNotification($interview, 'status_changed');
            }

            $updated = $this->interviewModel->getInterviewWithDetails($id, $user->id, $user->role);

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
            $interview = $this->interviewModel->find($id);
            if (!$interview) {
                return $this->failNotFound('Interview not found');
            }

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

            foreach ($data['interviewIds'] as $interviewId) {
                $interview = $this->interviewModel->find($interviewId);
                $oldStatus = $interview['status'];

                $this->interviewModel->update($interviewId, ['status' => $data['newStatus']]);

                $this->historyModel->insert([
                    'interviewId' => $interviewId,
                    'fromStatus'  => $oldStatus,
                    'toStatus'    => $data['newStatus'],
                    'changedBy'   => $user->id,
                    'reason'      => $data['reason'] ?? "Bulk status update to {$data['newStatus']}"
                ]);
            }

            return $this->respond([
                'success' => true,
                'message' => count($data['interviewIds']) . ' interview(s) updated successfully',
                'data'    => ['count' => count($data['interviewIds'])]
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
            $interviews = $this->interviewModel->getUpcomingInterviews($user->id, $user->role);

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
            $stats = $this->interviewModel->getStatistics($user->id, $user->role);

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
    // SIMPLE PROFESSIONAL EMAILS (NO BLOCKS) BUT SAME TEXT
    // ============================================================

    private function buildReminderEmail($candidateName, $jobTitle, $scheduledDate, $interview)
    {
        $safeJobTitle      = htmlspecialchars($jobTitle);
        $safeScheduledDate = htmlspecialchars($scheduledDate);

        $type     = $interview['type'] ?? 'VIDEO';
        $isOnline = in_array($type, ['VIDEO', 'PHONE'], true);
        $category = $isOnline ? 'Online Interview' : ' Physical Interview';

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
