<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * NotificationQueue Model
 * Outgoing email queue – all mail passes through here.
 */
class NotificationQueue extends Model
{
    protected $table            = 'notification_queue';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id', 'userId', 'jobId', 'alertId', 'type', 'status',
        'subject', 'bodyHtml', 'toEmail', 'fromEmail', 'fromName',
        'retryCount', 'errorMessage', 'scheduledAt', 'sentAt',
        'createdAt', 'updatedAt',
    ];

    protected $useTimestamps = false;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enqueue a single email.
     */
    public function enqueue(array $data): string
    {
        $id = uniqid('nq_');
        $this->insert([
            'id'          => $id,
            'userId'      => $data['userId'],
            'jobId'       => $data['jobId']       ?? null,
            'alertId'     => $data['alertId']     ?? null,
            'type'        => $data['type']         ?? 'JOB_ALERT',
            'status'      => 'PENDING',
            'subject'     => $data['subject']      ?? '',
            'bodyHtml'    => $data['bodyHtml']     ?? '',
            'toEmail'     => $data['toEmail'],
            'fromEmail'   => $data['fromEmail']    ?? null,
            'fromName'    => $data['fromName']     ?? null,
            'scheduledAt' => $data['scheduledAt']  ?? null,
            'createdAt'   => date('Y-m-d H:i:s'),
            'updatedAt'   => date('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /**
     * Fetch a batch of pending emails ready to send.
     */
    public function getPendingBatch(int $limit = 50): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->where('status', 'PENDING')
                    ->groupStart()
                        ->where('scheduledAt IS NULL')
                        ->orWhere('scheduledAt <=', $now)
                    ->groupEnd()
                    ->orderBy('createdAt', 'ASC')
                    ->findAll($limit);
    }

    /**
     * Mark as sent.
     */
    public function markSent(string $id): void
    {
        $this->update($id, [
            'status'    => 'SENT',
            'sentAt'    => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Mark as failed (increment retry counter).
     */
    public function markFailed(string $id, string $error, int $maxRetries = 3): void
    {
        $item = $this->find($id);
        if (!$item) return;

        $retryCount = (int)($item['retryCount'] ?? 0) + 1;
        $newStatus  = $retryCount >= $maxRetries ? 'FAILED' : 'PENDING';

        $this->update($id, [
            'status'       => $newStatus,
            'retryCount'   => $retryCount,
            'errorMessage' => $error,
            'updatedAt'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Queue stats for admin dashboard.
     */
    public function getStats(): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('notification_queue')
                   ->select('status, COUNT(*) as cnt')
                   ->groupBy('status')
                   ->get()->getResultArray();

        $stats = ['PENDING' => 0, 'PROCESSING' => 0, 'SENT' => 0, 'FAILED' => 0];
        foreach ($rows as $r) {
            $stats[$r['status']] = (int) $r['cnt'];
        }
        return $stats;
    }

    /**
     * History for admin panel (paginated).
     */
    public function getHistory(int $page = 1, int $limit = 20): array
    {
        $skip = ($page - 1) * $limit;
        $total = $this->countAllResults(false);
        $items = $this->orderBy('createdAt', 'DESC')->findAll($limit, $skip);
        return ['items' => $items, 'total' => $total];
    }
}
