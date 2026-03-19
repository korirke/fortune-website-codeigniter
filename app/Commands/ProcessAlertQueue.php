<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\NotificationQueue;
use App\Services\EmailService;
use App\Services\SettingService;

/**
 * ProcessAlertQueue CRON Command
 * Processes pending emails in the notification_queue.
 *
 * Setup in server crontab (run every minute):
 *   * * * * * php /path/to/project/spark alerts:process >> /var/log/alerts.log 2>&1
 */
class ProcessAlertQueue extends BaseCommand
{
    protected $group       = 'Alerts';
    protected $name        = 'alerts:process';
    protected $description = 'Process pending emails in the notification queue.';
    protected $usage       = 'alerts:process [--batch=50]';
    protected $options     = [
        '--batch' => 'Number of emails to process per run (default: 50)',
    ];

    public function run(array $params)
    {
        if (!SettingService::bool('EMAIL_NOTIFICATIONS_ENABLED', true)) {
            CLI::write('[alerts:process] Email notifications disabled. Skipping.', 'yellow');
            return;
        }

        $batchSize = (int)(CLI::getOption('batch') ?? SettingService::int('QUEUE_BATCH_SIZE', 50));
        $maxRetry  = SettingService::int('QUEUE_MAX_RETRIES', 3);

        $queueModel   = new NotificationQueue();
        $emailService = new EmailService();

        $pending = $queueModel->getPendingBatch($batchSize);

        if (empty($pending)) {
            CLI::write('[alerts:process] No pending emails. Done.', 'green');
            return;
        }

        CLI::write("[alerts:process] Processing " . count($pending) . " email(s)...", 'cyan');

        $sent   = 0;
        $failed = 0;

        foreach ($pending as $item) {
            // Mark as processing to prevent parallel runs from re-grabbing it
            $queueModel->update($item['id'], [
                'status'    => 'PROCESSING',
                'updatedAt' => date('Y-m-d H:i:s'),
            ]);

            try {
                $result = $emailService->send(
                    $item['toEmail'],
                    $item['subject'] ?? '(no subject)',
                    $item['bodyHtml'] ?? '',
                    $item['fromEmail'] ?? null,
                    $item['fromName']  ?? null
                );

                if ($result['success']) {
                    $queueModel->markSent($item['id']);
                    $sent++;
                    CLI::write("  ✓ Sent to {$item['toEmail']}", 'green');
                } else {
                    $queueModel->markFailed($item['id'], $result['error'] ?? 'Unknown error', $maxRetry);
                    $failed++;
                    CLI::write("  ✗ Failed to {$item['toEmail']}: " . ($result['error'] ?? ''), 'red');
                }
            } catch (\Throwable $e) {
                $queueModel->markFailed($item['id'], $e->getMessage(), $maxRetry);
                $failed++;
                CLI::write("  ✗ Exception for {$item['toEmail']}: " . $e->getMessage(), 'red');
            }

            // Small delay to avoid hammering SMTP
            usleep(100000); // 100ms
        }

        CLI::write("[alerts:process] Done. Sent: {$sent}, Failed: {$failed}", 'cyan');
    }
}
