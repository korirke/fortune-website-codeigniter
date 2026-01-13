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
     * Interview emails from recruitment inbox with congratulations message
     */
    private function sendInterviewNotification($interview, $type = 'scheduled')
    {
        try {
            $candidate = $interview['candidate'] ?? [];
            $job = $interview['job'] ?? [];

            // Debug logging
            log_message('info', 'sendInterviewNotification - Type: ' . $type);
            log_message('info', 'Interview data: ' . json_encode($interview));
            log_message('info', 'Candidate email: ' . ($candidate['email'] ?? 'MISSING'));
            log_message('info', 'Job title: ' . ($job['title'] ?? 'MISSING'));

            // Validate required data
            if (empty($candidate['email'])) {
                log_message('error', 'Candidate email missing in interview notification');
                return;
            }

            if (empty($job['title'])) {
                log_message('error', 'Job title missing in interview notification');
                return;
            }

            $candidateName = trim(($candidate['firstName'] ?? '') . ' ' . ($candidate['lastName'] ?? ''));
            if ($candidateName === '') {
                $candidateName = 'Candidate';
            }

            $scheduledAt = $interview['scheduledAt'] ?? null;
            if (!$scheduledAt) {
                log_message('error', 'Scheduled date missing in interview notification');
                return;
            }

            $scheduledDateHuman = date('F j, Y \a\t g:i A', strtotime($scheduledAt));

            switch ($type) {
                case 'scheduled':
                    log_message('info', 'Sending interview invitation email to: ' . $candidate['email']);

                    // ✅ Calendar invite (.ics) handled by EmailHelper
                    $emailResult = $this->emailHelper->sendInterviewInvitation(
                        $candidate['email'],
                        $candidateName,
                        $job['title'],
                        $scheduledAt,
                        [
                            'type' => $interview['type'] ?? 'VIDEO',
                            'duration' => $interview['duration'] ?? 60,
                            'meetingLink' => $interview['meetingLink'] ?? null,
                            'location' => $interview['location'] ?? null,
                            'notes' => $interview['notes'] ?? null,
                            'timezone' => 'Africa/Nairobi'
                        ]
                    );

                    log_message('info', 'Interview invitation result: ' . json_encode($emailResult));
                    break;

                case 'reminder':
                    log_message('info', 'Sending reminder email to: ' . $candidate['email']);

                    $subject = 'Interview Reminder - ' . $job['title'];
                    $message = $this->buildReminderEmail($candidateName, $job['title'], $scheduledDateHuman, $interview);

                    $emailResult = $this->emailHelper->sendRecruitmentEmail($candidate['email'], $subject, $message, true);

                    log_message('info', 'Reminder email result: ' . json_encode($emailResult));
                    break;

                case 'status_changed':
                    log_message('info', 'Sending status change email to: ' . $candidate['email']);

                    $subject = 'Interview Status Update - ' . $job['title'];
                    $message = $this->buildStatusChangeEmail($candidateName, $job['title'], $interview['status'] ?? 'UNKNOWN');

                    $emailResult = $this->emailHelper->sendRecruitmentEmail($candidate['email'], $subject, $message, true);

                    log_message('info', 'Status change email result: ' . json_encode($emailResult));
                    break;

                default:
                    log_message('warning', 'Unknown notification type: ' . $type);
            }
        } catch (\Exception $e) {
            log_message('error', 'Failed to send interview notification: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
        }
    }

    private function buildReminderEmail($candidateName, $jobTitle, $scheduledDate, $interview)
    {
        $safeJobTitle = htmlspecialchars($jobTitle);
        $safeScheduledDate = htmlspecialchars($scheduledDate);
        
        // Determine if online or physical
        $type = $interview['type'] ?? 'VIDEO';
        $isOnline = in_array($type, ['VIDEO', 'PHONE']);
        $category = $isOnline ? 'Online Interview' : ' Physical Interview';

        $html = "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #222; }
                .container { max-width: 700px; margin: 0 auto; padding: 22px; }
                .header { background: #ffc107; color: #000; padding: 18px 20px; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; }
                .card { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; margin: 14px 0; }
                .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; margin-bottom: 10px; background: " . ($isOnline ? "#d1ecf1; color: #0c5460;" : "#fff3cd; color: #856404;") . " }
                .footer { margin-top: 18px; font-size: 12px; color: #6c757d; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin:0;'>⏰ Interview Reminder</h2>
                    <p style='margin:6px 0 0 0;'>Fortune Kenya Recruitment</p>
                </div>

                <div class='content'>
                    <p>Dear {$candidateName},</p>

                    <p>This is a friendly reminder about your upcoming interview:</p>

                    <div class='card'>
                        <span class='badge'>{$category}</span>
                        <h3 style='margin-top:10px;'>Interview Details</h3>
                        <p><strong>Position:</strong> {$safeJobTitle}</p>
                        <p><strong>Date & Time:</strong> {$safeScheduledDate}</p>";

        if ($isOnline && !empty($interview['meetingLink'])) {
            $html .= "<p><strong>Meeting Link:</strong> <a href='{$interview['meetingLink']}' style='color: #0b5ed7;'>{$interview['meetingLink']}</a></p>";
        }

        if (!$isOnline && !empty($interview['location'])) {
            $html .= "<p><strong>Location:</strong> " . htmlspecialchars($interview['location']) . "</p>";
        }

        $html .= "</div>

                    <div class='card'>
                        <h3 style='margin-top:0;'>Important Reminders</h3>
                        <ul>
                            <li>Please join at least <strong>5 minutes early</strong>.</li>";
        
        if ($isOnline) {
            $html .= "<li>Ensure you have <strong>stable internet</strong> and working <strong>microphone/camera</strong>.</li>";
        } else {
            $html .= "<li>Plan your route and arrive on time.</li>";
        }

        $html .= "
                        </ul>
                    </div>

                    <p>Kind regards,<br>
                    <strong>Fortune Kenya Recruitment Team</strong><br>
                    headhunting@fortunekenya.com</p>

                    <div class='footer'>
                        <p>This is an automated email. Please do not reply directly to this message.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";

        return $html;
    }

    private function buildStatusChangeEmail($candidateName, $jobTitle, $newStatus)
    {
        $safeStatus = htmlspecialchars(str_replace('_', ' ', (string) $newStatus));
        $safeJobTitle = htmlspecialchars($jobTitle);

        $html = "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #222; }
                .container { max-width: 700px; margin: 0 auto; padding: 22px; }
                .header { background: #6c757d; color: #fff; padding: 18px 20px; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; }
                .card { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; margin: 14px 0; }
                .footer { margin-top: 18px; font-size: 12px; color: #6c757d; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin:0;'>Interview Status Update</h2>
                    <p style='margin:6px 0 0 0;'>Fortune Kenya Recruitment</p>
                </div>

                <div class='content'>
                    <p>Dear {$candidateName},</p>

                    <p>The status of your interview for <strong>{$safeJobTitle}</strong> has been updated.</p>

                    <div class='card'>
                        <h3 style='margin-top:0;'>New Status</h3>
                        <p style='font-size: 18px; font-weight: bold; color: #0b5ed7;'>{$safeStatus}</p>
                    </div>

                    <p>If you have any questions, please contact us at <strong>headhunting@fortunekenya.com</strong>.</p>

                    <p>Kind regards,<br>
                    <strong>Fortune Kenya Recruitment Team</strong><br>
                    headhunting@fortunekenya.com</p>

                    <div class='footer'>
                        <p>This is an automated email. Please do not reply directly to this message.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";

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
