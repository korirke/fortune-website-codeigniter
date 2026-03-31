<?php

/**
 * Description: Centralised service for creating in-app notifications.
 * Currently handles APPLICATION_RECEIVED events. Inserts rows into the
 *  `notifications` table  Notifies the job
 * poster + every HR_MANAGER / SUPER_ADMIN + all employer users of the
 * same company. Designed to be called from JobApplicationPipeline after
 * a successful application commit.
 */

namespace App\Services;

class NotificationService
{
    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fires an APPLICATION_RECEIVED notification to all relevant recipients.
     *
     * @param string $jobId
     * @param string $applicationId
     * @param string $candidateFirstName
     * @param string $candidateLastName
     * @param string $jobTitle
     * @param string $companyId
     * @param string $postedById  User ID of the job poster
     */
    public function createApplicationNotification(
        string $jobId,
        string $applicationId,
        string $candidateFirstName,
        string $candidateLastName,
        string $jobTitle,
        string $companyId,
        string $postedById
    ): void {
        try {
            $db   = \Config\Database::connect();
            $now  = date('Y-m-d H:i:s');

            $candidateName = trim("{$candidateFirstName} {$candidateLastName}");
            $title         = 'New Application Received';
            $message       = "{$candidateName} applied for \"{$jobTitle}\"";
            $link          = "/recruitment-portal/jobs/applications?jobId={$jobId}";

            $metadata = json_encode([
                'applicationId' => $applicationId,
                'jobId'         => $jobId,
                'jobTitle'      => $jobTitle,
                'candidateName' => $candidateName,
                'companyId'     => $companyId,
            ]);

            $recipients = $this->resolveRecipients($db, $postedById, $companyId);

            $batch = [];
            foreach ($recipients as $userId) {
                $batch[] = [
                    'id'        => uniqid('notif_'),
                    'userId'    => $userId,
                    'type'      => 'APPLICATION_RECEIVED',
                    'title'     => $title,
                    'message'   => $message,
                    'link'      => $link,
                    'isRead'    => 0,
                    'readAt'    => null,
                    'metadata'  => $metadata,
                    'createdAt' => $now,
                ];
            }

            if (!empty($batch)) {
                $db->table('notifications')->insertBatch($batch);
            }
        } catch (\Throwable $e) {
            // Non-fatal — log and continue so the application itself isn't affected.
            log_message('error', '[NotificationService] createApplicationNotification error: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Builds a deduplicated list of user IDs that should receive the notification:
     *  1. The job poster
     *  2. All ACTIVE HR_MANAGER / SUPER_ADMIN users
     *  3. All EMPLOYER users linked to the same company via employer_profiles
     */
    private function resolveRecipients($db, string $postedById, string $companyId): array
    {
        $ids = [$postedById];

        // HR managers + super admins
        $admins = $db->table('users')
            ->select('id')
            ->whereIn('role', ['HR_MANAGER', 'SUPER_ADMIN'])
            ->whereIn('status', ['ACTIVE'])
            ->get()
            ->getResultArray();

        foreach ($admins as $row) {
            $ids[] = $row['id'];
        }

        // Employer users for this company
        $employers = $db->table('employer_profiles')
            ->select('userId')
            ->where('companyId', $companyId)
            ->get()
            ->getResultArray();

        foreach ($employers as $row) {
            $ids[] = $row['userId'];
        }

        return array_values(array_unique($ids));
    }
}