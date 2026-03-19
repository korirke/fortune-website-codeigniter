<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\AlertService;

/**
 * SendDailyDigest CRON Command
 * Queues daily digest emails for all DAILY-frequency alerts.
 *
 * crontab (runs at 8am UTC daily):
 *   0 8 * * * php /path/to/project/spark alerts:daily >> /var/log/alerts.log 2>&1
 */
class SendDailyDigest extends BaseCommand
{
    protected $group       = 'Alerts';
    protected $name        = 'alerts:daily';
    protected $description = 'Queue daily job alert digest emails.';

    public function run(array $params)
    {
        CLI::write('[alerts:daily] Building daily digests...', 'cyan');

        try {
            $service = new AlertService();
            $service->sendDailyDigest();
            CLI::write('[alerts:daily] Daily digest queued successfully.', 'green');
        } catch (\Throwable $e) {
            CLI::write('[alerts:daily] Error: ' . $e->getMessage(), 'red');
            log_message('error', 'SendDailyDigest error: ' . $e->getMessage());
        }
    }
}
