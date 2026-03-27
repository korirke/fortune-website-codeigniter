<?php

namespace App\Services;

use Config\Services;

/**
 * EmailService
 * Sends emails using SMTP config loaded from DB settings.
 * Wraps CodeIgniter email library with dynamic configuration.
 */
class EmailService
{
    /**
     * Send an email.
     * All config is read from SettingService (DB-backed).
     */
    public function send(
        string $to,
        string $subject,
        string $bodyHtml,
        ?string $fromEmail = null,
        ?string $fromName  = null
    ): array {
        if (!SettingService::bool('EMAIL_NOTIFICATIONS_ENABLED', true)) {
            log_message('info', 'EmailService: notifications disabled, skipping send to ' . $to);
            return ['success' => false, 'error' => 'Email notifications disabled'];
        }

        try {
            $emailConfig = SettingService::getEmailConfig();

            // Re-initialise email with fresh DB config each time
            $email = Services::email();
            $email->initialize($emailConfig);
            $email->clear(true);

            $from     = $fromEmail ?? SettingService::get('EMAIL_FROM_DEFAULT', 'noreply@fortunekenya.com');
            $name     = $fromName  ?? SettingService::get('EMAIL_FROM_NAME',    'Fortune Technologies');

            $email->setFrom($from, $name);
            $email->setTo($to);
            $email->setSubject($this->cleanHeader($subject));
            $email->setMailType('html');
            $email->setMessage($bodyHtml);
            $email->setAltMessage(strip_tags($bodyHtml));

            if ($email->send()) {
                $this->logEmail($to, $subject, 'SENT');
                return ['success' => true];
            }

            $debug = $email->printDebugger(['headers', 'subject', 'body']);
            log_message('error', 'EmailService send failed: ' . $debug);
            $this->logEmail($to, $subject, 'FAILED', $debug);
            return ['success' => false, 'error' => 'Send failed', 'debug' => $debug];

        } catch (\Throwable $e) {
            log_message('error', 'EmailService exception: ' . $e->getMessage());
            $this->logEmail($to, $subject, 'FAILED', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send via the queue (fire-and-forget; does NOT immediately call SMTP).
     * Use this inside controllers to be non-blocking.
     */
    public function enqueue(
        string $userId,
        string $to,
        string $subject,
        string $bodyHtml,
        string $type       = 'SYSTEM',
        ?string $fromEmail = null,
        ?string $fromName  = null,
        ?string $scheduledAt = null
    ): string {
        $queueModel = new \App\Models\NotificationQueue();
        return $queueModel->enqueue([
            'userId'      => $userId,
            'type'        => $type,
            'subject'     => $subject,
            'bodyHtml'    => $bodyHtml,
            'toEmail'     => $to,
            'fromEmail'   => $fromEmail,
            'fromName'    => $fromName,
            'scheduledAt' => $scheduledAt,
        ]);
    }

    /**
     * Test current SMTP config by sending a test email.
     */
    public function sendTestEmail(string $to): array
    {
        return $this->send(
            $to,
            'SMTP Test – ' . SettingService::get('APP_NAME', 'Portal'),
            '<p>This is a test email confirming your SMTP configuration is working correctly.</p><p>Sent at: ' . date('Y-m-d H:i:s') . '</p>',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function cleanHeader(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    private function logEmail(string $to, string $subject, string $status, ?string $error = null): void
    {
        try {
            $db = \Config\Database::connect();
            $db->table('email_logs')->insert([
                'id'           => uniqid('elog_'),
                'to'           => $to,
                'subject'      => mb_substr($subject, 0, 191),
                'status'       => $status,
                'errorMessage' => $error ? mb_substr($error, 0, 500) : null,
                'sentAt'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}
