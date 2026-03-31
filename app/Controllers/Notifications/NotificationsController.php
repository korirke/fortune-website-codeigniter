<?php

/**
 * Description: REST controller for in-app notification management.
 * Endpoints:
 *   GET    /api/notifications              – paginated list for current user
 *   GET    /api/notifications/unread-count – badge counter for topbar bell
 *   PATCH  /api/notifications/mark-all-read  – mark every notification read
 *   PATCH  /api/notifications/mark-job-seen  – mark a job's applications as seen
 *                                              (upserts job_application_read_markers
 *                                               and clears related notifications)
 *   PATCH  /api/notifications/:id/read     – mark single notification read
 *
 * All endpoints require authentication (set via route filter).
 */

namespace App\Controllers\Notifications;

use App\Controllers\BaseController;
use App\Models\JobApplicationReadMarker;
use CodeIgniter\API\ResponseTrait;

class NotificationsController extends BaseController
{
    use ResponseTrait;
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/notifications
    // ─────────────────────────────────────────────────────────────────────────

    public function getNotifications()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $page  = max(1, (int) ($this->request->getGet('page') ?? 1));
            $limit = min(50, max(1, (int) ($this->request->getGet('limit') ?? 20)));
            $skip  = ($page - 1) * $limit;

            $db = \Config\Database::connect();

            $total = $db->table('notifications')
                ->where('userId', $user->id)
                ->countAllResults();

            $rows = $db->table('notifications')
                ->where('userId', $user->id)
                ->orderBy('createdAt', 'DESC')
                ->limit($limit, $skip)
                ->get()
                ->getResultArray();

            foreach ($rows as &$n) {
                $n['isRead']   = (bool) $n['isRead'];
                $n['metadata'] = $n['metadata'] ? json_decode($n['metadata'], true) : null;
            }

            return $this->respond([
                'success' => true,
                'data'    => [
                    'notifications' => $rows,
                    'pagination'    => [
                        'total'      => (int) $total,
                        'page'       => $page,
                        'limit'      => $limit,
                        'totalPages' => (int) ceil($total / $limit),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[NotificationsController::getNotifications] ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to retrieve notifications'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/notifications/unread-count
    // ─────────────────────────────────────────────────────────────────────────

    public function getUnreadCount()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $db    = \Config\Database::connect();
            $count = (int) $db->table('notifications')
                ->where('userId', $user->id)
                ->where('isRead', 0)
                ->countAllResults();

            return $this->respond([
                'success' => true,
                'data'    => ['count' => $count],
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[NotificationsController::getUnreadCount] ' . $e->getMessage());
            return $this->respond(['success' => false, 'data' => ['count' => 0]], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /api/notifications/:id/read
    // ─────────────────────────────────────────────────────────────────────────

    public function markAsRead($id = null)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);
            if (!$id)   return $this->fail('Notification ID is required', 400);

            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $db->table('notifications')
                ->where('id', $id)
                ->where('userId', $user->id)
                ->update(['isRead' => 1, 'readAt' => $now]);

            return $this->respond(['success' => true, 'message' => 'Notification marked as read']);
        } catch (\Throwable $e) {
            log_message('error', '[NotificationsController::markAsRead] ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to mark as read'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /api/notifications/mark-all-read
    // ─────────────────────────────────────────────────────────────────────────

    public function markAllAsRead()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $db->table('notifications')
                ->where('userId', $user->id)
                ->where('isRead', 0)
                ->update(['isRead' => 1, 'readAt' => $now]);

            return $this->respond(['success' => true, 'message' => 'All notifications marked as read']);
        } catch (\Throwable $e) {
            log_message('error', '[NotificationsController::markAllAsRead] ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to mark all as read'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /api/notifications/mark-job-seen
    // Body: { jobId: string }
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called when a recruiter opens the applications list for a specific job.
     * 1. Upserts job_application_read_markers so the "new count" resets to 0.
     * 2. Marks APPLICATION_RECEIVED notifications for this job as read.
     */
    public function markJobApplicationsSeen()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $payload = $this->request->getJSON(true) ?? [];
            $jobId   = $payload['jobId'] ?? null;
            if (!$jobId) return $this->fail('jobId is required', 400);

            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            // Current total application count for this job
            $appCount = (int) $db->table('applications')
                ->where('jobId', $jobId)
                ->countAllResults();

            // Upsert read marker
            $markerModel = new JobApplicationReadMarker();
            $markerModel->touch($user->id, $jobId, $appCount);

            // Clear APPLICATION_RECEIVED notifications for this job
            // JSON_EXTRACT is available in MySQL 5.7+ / MariaDB 10.2+
            // LIKE --fallback for broader compatibility
            $db->table('notifications')
                ->where('userId', $user->id)
                ->where('type', 'APPLICATION_RECEIVED')
                ->where('isRead', 0)
                ->like('metadata', '"jobId":"' . $jobId . '"')
                ->update(['isRead' => 1, 'readAt' => $now]);

            return $this->respond([
                'success' => true,
                'message' => 'Job applications marked as seen',
                'data'    => ['lastReadAt' => $now, 'seenCount' => $appCount],
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[NotificationsController::markJobApplicationsSeen] ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to mark job as seen'], 500);
        }
    }
}
