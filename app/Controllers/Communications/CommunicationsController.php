<?php

namespace App\Controllers\Communications;

use App\Controllers\BaseController;
use App\Models\NewsletterSend;
use App\Models\NotificationQueue;
use App\Models\User;
use App\Services\SettingService;
use App\Services\TemplateService;
use App\Traits\NormalizedResponseTrait;

/**
 * CommunicationsController
 * Admin: send newsletters, manage templates, view history, manage queue.
 *
 * Routes:
 *   POST   /api/communications/newsletter/send    → sendNewsletter
 *   GET    /api/communications/newsletter/history → getHistory
 *   GET    /api/communications/templates          → getTemplates
 *   GET    /api/communications/templates/:id      → getTemplate
 *   PUT    /api/communications/templates/:id      → updateTemplate
 *   GET    /api/communications/queue              → getQueue
 *   DELETE /api/communications/queue/:id          → cancelQueued
 *   GET    /api/communications/stats              → getStats
 */
class CommunicationsController extends BaseController
{
    use NormalizedResponseTrait;

    // ──────────────────────────────────────────────────────────────────────────
    // POST /api/communications/newsletter/send
    // Body: { subject, bodyHtml, recipientGroup }
    // recipientGroup: ALL | CANDIDATES | EMPLOYERS | ACTIVE
    // ──────────────────────────────────────────────────────────────────────────
    public function sendNewsletter()
    {
        try {
            $adminUser = $this->request->user ?? null;
            if (!$adminUser) return $this->fail('Unauthorized', 401);

            $data          = $this->request->getJSON(true) ?? [];
            $subject       = trim($data['subject'] ?? '');
            $bodyHtml      = trim($data['bodyHtml'] ?? '');
            $recipientGroup = strtoupper(trim($data['recipientGroup'] ?? 'ALL'));

            if (empty($subject)) return $this->fail('Subject is required.', 400);
            if (empty($bodyHtml)) return $this->fail('Email body is required.', 400);

            $validGroups = ['ALL', 'CANDIDATES', 'EMPLOYERS', 'ACTIVE'];
            if (!in_array($recipientGroup, $validGroups, true)) {
                return $this->fail('Invalid recipient group.', 400);
            }

            // Fetch recipients
            $recipients = $this->getRecipients($recipientGroup);
            $total      = count($recipients);

            if ($total === 0) {
                return $this->fail('No recipients found for the selected group.', 400);
            }

            // Create campaign record
            $campaignModel = new NewsletterSend();
            $campaignId    = uniqid('ns_');
            $campaignModel->insert([
                'id'             => $campaignId,
                'subject'        => $subject,
                'bodyHtml'       => $bodyHtml,
                'recipientGroup' => $recipientGroup,
                'totalCount'     => $total,
                'sentCount'      => 0,
                'failedCount'    => 0,
                'status'         => 'SENDING',
                'sentBy'         => $adminUser->id,
                'sentAt'         => date('Y-m-d H:i:s'),
                'createdAt'      => date('Y-m-d H:i:s'),
                'updatedAt'      => date('Y-m-d H:i:s'),
            ]);

            // Queue email for every recipient
            $queueModel       = new NotificationQueue();
            $templateService  = new TemplateService();
            $frontendUrl      = SettingService::get('FRONTEND_URL', base_url());
            $queued           = 0;

            foreach ($recipients as $user) {
                // Respect user unsubscribe preference
                if (empty($user['emailNotifications'])) continue;

                $personalBody = $templateService->render('newsletter', [
                    'name'             => trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? '')),
                    'subject'          => $subject,
                    'body'             => $bodyHtml,
                    'body_text'        => strip_tags($bodyHtml),
                    'unsubscribe_link' => $frontendUrl . '/unsubscribe?token=' . ($user['unsubscribeToken'] ?? ''),
                ]);

                if ($personalBody) {
                    $queueModel->enqueue([
                        'userId'   => $user['id'],
                        'type'     => 'NEWSLETTER',
                        'subject'  => $subject,
                        'bodyHtml' => $personalBody['body'],
                        'toEmail'  => $user['email'],
                    ]);
                    $queued++;
                }
            }

            // Update campaign
            $campaignModel->update($campaignId, [
                'totalCount' => $queued,
                'status'     => 'SENT',
                'updatedAt'  => date('Y-m-d H:i:s'),
            ]);

            // Log audit
            $this->logAudit($adminUser->id, 'NEWSLETTER_SENT', 'Newsletter', $campaignId, [
                'subject'        => $subject,
                'recipientGroup' => $recipientGroup,
                'queued'         => $queued,
            ]);

            return $this->respond([
                'success'    => true,
                'message'    => "Newsletter queued for {$queued} recipients.",
                'campaignId' => $campaignId,
                'queued'     => $queued,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'SendNewsletter error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to send newsletter'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/communications/newsletter/history
    // ──────────────────────────────────────────────────────────────────────────
    public function getHistory()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $page  = (int)($this->request->getGet('page') ?? 1);
            $limit = (int)($this->request->getGet('limit') ?? 20);
            $skip  = ($page - 1) * $limit;

            $model = new NewsletterSend();
            $total = $model->countAllResults(false);
            $items = $model->orderBy('createdAt', 'DESC')->findAll($limit, $skip);

            // Enrich with sender name
            $userModel = new User();
            foreach ($items as &$item) {
                $sender = $userModel->select('firstName, lastName')->find($item['sentBy'] ?? '');
                $item['sentByName'] = $sender
                    ? trim(($sender['firstName'] ?? '') . ' ' . ($sender['lastName'] ?? ''))
                    : 'Unknown';
            }

            return $this->respond([
                'success' => true,
                'data'    => $items,
                'pagination' => [
                    'total'      => (int)$total,
                    'page'       => $page,
                    'limit'      => $limit,
                    'totalPages' => (int)ceil($total / $limit),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed to load history'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/communications/templates
    // ──────────────────────────────────────────────────────────────────────────
    public function getTemplates()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $service   = new TemplateService();
            $templates = $service->getAllTemplates();

            return $this->respond(['success' => true, 'data' => $templates]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/communications/templates/:id
    // ──────────────────────────────────────────────────────────────────────────
    public function getTemplate(string $id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $model    = new \App\Models\EmailTemplate();
            $template = $model->find($id);
            if (!$template) return $this->failNotFound('Template not found');

            return $this->respond(['success' => true, 'data' => $template]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUT /api/communications/templates/:id
    // Body: { name, subject, htmlContent, textContent, isActive }
    // ──────────────────────────────────────────────────────────────────────────
    public function updateTemplate(string $id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $data = $this->request->getJSON(true) ?? [];

            if (empty(trim($data['subject'] ?? ''))) {
                return $this->fail('Subject is required.', 400);
            }
            if (empty(trim($data['htmlContent'] ?? ''))) {
                return $this->fail('HTML content is required.', 400);
            }

            $service = new TemplateService();
            $service->updateTemplate($id, $data);

            $model    = new \App\Models\EmailTemplate();
            $template = $model->find($id);

            $this->logAudit($user->id, 'TEMPLATE_UPDATED', 'EmailTemplate', $id, [
                'subject' => $data['subject'],
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Template updated successfully',
                'data'    => $template,
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed to update template'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/communications/queue
    // ──────────────────────────────────────────────────────────────────────────
    public function getQueue()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $page   = (int)($this->request->getGet('page') ?? 1);
            $limit  = (int)($this->request->getGet('limit') ?? 20);
            $status = $this->request->getGet('status');

            $model = new NotificationQueue();
            if ($status) $model->where('status', strtoupper($status));

            $skip  = ($page - 1) * $limit;
            $total = $model->countAllResults(false);
            $items = $model->orderBy('createdAt', 'DESC')->findAll($limit, $skip);

            $stats = (new NotificationQueue())->getStats();

            return $this->respond([
                'success' => true,
                'data'    => $items,
                'stats'   => $stats,
                'pagination' => [
                    'total'      => (int)$total,
                    'page'       => $page,
                    'limit'      => $limit,
                    'totalPages' => (int)ceil($total / $limit),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed to load queue'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DELETE /api/communications/queue/:id
    // ──────────────────────────────────────────────────────────────────────────
    public function cancelQueued(string $id)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $model = new NotificationQueue();
            $item  = $model->find($id);
            if (!$item) return $this->failNotFound('Queue item not found');

            if ($item['status'] !== 'PENDING') {
                return $this->fail('Only PENDING items can be cancelled.', 400);
            }

            $model->update($id, ['status' => 'FAILED', 'errorMessage' => 'Cancelled by admin', 'updatedAt' => date('Y-m-d H:i:s')]);

            return $this->respond(['success' => true, 'message' => 'Queue item cancelled']);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/communications/stats
    // ──────────────────────────────────────────────────────────────────────────
    public function getStats()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $queueStats   = (new NotificationQueue())->getStats();
            $campaignModel = new NewsletterSend();
            $totalCampaigns = $campaignModel->countAllResults();

            $db = \Config\Database::connect();
            $totalSent = (int)($db->table('email_logs')->where('status', 'SENT')->countAllResults());
            $totalFailed = (int)($db->table('email_logs')->where('status', 'FAILED')->countAllResults());

            return $this->respond([
                'success' => true,
                'data'    => [
                    'queue'          => $queueStats,
                    'totalCampaigns' => $totalCampaigns,
                    'totalSent'      => $totalSent,
                    'totalFailed'    => $totalFailed,
                    'emailEnabled'   => SettingService::bool('EMAIL_NOTIFICATIONS_ENABLED'),
                    'instantEnabled' => SettingService::bool('INSTANT_ALERTS_ENABLED'),
                    'dailyEnabled'   => SettingService::bool('DAILY_DIGEST_ENABLED'),
                    'weeklyEnabled'  => SettingService::bool('WEEKLY_DIGEST_ENABLED'),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Failed to load stats'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function getRecipients(string $group): array
    {
        $userModel = new User();

        switch ($group) {
            case 'CANDIDATES':
                return $userModel->where('role', 'CANDIDATE')->where('status', 'ACTIVE')->findAll();
            case 'EMPLOYERS':
                return $userModel->where('role', 'EMPLOYER')->where('status', 'ACTIVE')->findAll();
            case 'ACTIVE':
                return $userModel->where('status', 'ACTIVE')->findAll();
            default: // ALL
                return $userModel->findAll();
        }
    }

    private function logAudit(string $userId, string $action, string $resource, string $resourceId, array $details): void
    {
        try {
            $db = \Config\Database::connect();
            $db->table('audit_logs')->insert([
                'id'         => uniqid('audit_'),
                'userId'     => $userId,
                'action'     => $action,
                'resource'   => $resource,
                'resourceId' => $resourceId,
                'module'     => 'COMMUNICATIONS',
                'details'    => json_encode($details),
                'createdAt'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) { /* non-fatal */ }
    }
}
