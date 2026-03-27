<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\AlertService;

/**
 * SendWeeklyDigest CRON Command
 * Queues weekly digest emails for all WEEKLY-frequency alerts.
 *
 * Add to crontab (runs at 8am UTC every Monday):
 *   0 8 * * MON php /path/to/project/spark alerts:weekly >> /var/log/alerts.log 2>&1
 */
class SendWeeklyDigest extends BaseCommand
{
    protected $group       = 'Alerts';
    protected $name        = 'alerts:weekly';
    protected $description = 'Queue weekly job alert digest emails.';

    public function run(array $params)
    {
        CLI::write('[alerts:weekly] Building weekly digests...', 'cyan');

        try {
            $service = new AlertService();
            $service->sendWeeklyDigest();
            CLI::write('[alerts:weekly] Weekly digest queued successfully.', 'green');
        } catch (\Throwable $e) {
            CLI::write('[alerts:weekly] Error: ' . $e->getMessage(), 'red');
            log_message('error', 'SendWeeklyDigest error: ' . $e->getMessage());
        }
    }
}
