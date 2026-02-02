<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\Job;

class CheckExpiredJobs extends BaseCommand
{
    protected $group       = 'Jobs';
    protected $name        = 'jobs:check-expired';
    protected $description = 'Check and close expired job postings';
    protected $usage       = 'jobs:check-expired';

    public function run(array $params)
    {
        // Ensure consistent timezone handling
        $timezone = 'Africa/Nairobi';
        date_default_timezone_set($timezone);

        CLI::write('========================================', 'cyan');
        CLI::write('Job Expiration Check Started', 'cyan');
        CLI::write('Time: ' . date('Y-m-d H:i:s T'), 'cyan');
        CLI::write('Timezone: ' . $timezone, 'cyan');
        CLI::write('========================================', 'cyan');

        try {
            $jobModel = new Job();
            
            // Use current timestamp with proper timezone
            $now = date('Y-m-d H:i:s');
            
            CLI::write("\nSearching for expired jobs...", 'yellow');
            CLI::write("Current server time: {$now}", 'yellow');
            
            // Find all ACTIVE jobs where expiresAt is not null and is in the past
            $expiredJobs = $jobModel
                ->where('status', 'ACTIVE')
                ->where('expiresAt IS NOT NULL')
                ->where('expiresAt <=', $now)
                ->findAll();

            if (empty($expiredJobs)) {
                CLI::write("\n✓ No expired jobs found.", 'green');
                CLI::write('========================================', 'cyan');
                
                // Log the check
                log_message('info', 'Job expiration check completed: No expired jobs found');
                return;
            }

            CLI::write("\nFound " . count($expiredJobs) . " expired job(s)", 'yellow');
            CLI::write('----------------------------------------', 'cyan');

            $count = 0;
            $failed = 0;
            
            foreach ($expiredJobs as $job) {
                try {
                    // Update job status to CLOSED
                    $jobModel->update($job['id'], [
                        'status' => 'CLOSED',
                        'closedAt' => $now,
                        'updatedAt' => $now
                    ]);
                    
                    CLI::write("✓ Closed: {$job['title']}", 'green');
                    CLI::write("  ID: {$job['id']}", 'dark_gray');
                    CLI::write("  Company: " . $this->getCompanyName($job['companyId']), 'dark_gray');
                    CLI::write("  Expired At: {$job['expiresAt']}", 'dark_gray');
                    CLI::write("  Closed At: {$now}", 'dark_gray');
                    CLI::write('', 'white');
                    
                    $count++;
                    
                    // Log audit trail
                    $this->logAudit($job['id'], $job['title'], $job['expiresAt'], $job['companyId']);
                    
                } catch (\Exception $e) {
                    CLI::write("✗ Failed: {$job['title']} (ID: {$job['id']})", 'red');
                    CLI::write("  Error: " . $e->getMessage(), 'red');
                    CLI::write('', 'white');
                    $failed++;
                    
                    log_message('error', "Failed to close expired job {$job['id']}: " . $e->getMessage());
                }
            }

            CLI::write('----------------------------------------', 'cyan');
            CLI::write("\nExecution Summary:", 'cyan');
            CLI::write("  Total Found: " . count($expiredJobs), 'yellow');
            CLI::write("  Successfully Closed: {$count}", 'green');
            
            if ($failed > 0) {
                CLI::write("  Failed: {$failed}", 'red');
            }
            
            CLI::write("  Execution Time: " . date('Y-m-d H:i:s'), 'cyan');
            CLI::write('========================================', 'cyan');
            
            // Log summary
            log_message('info', "Job expiration check completed: {$count} jobs closed, {$failed} failed");
            
        } catch (\Exception $e) {
            CLI::write("\n✗ CRITICAL ERROR: " . $e->getMessage(), 'red');
            CLI::write('========================================', 'cyan');
            
            log_message('error', 'Critical error in job expiration check: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get company name by ID
     */
    private function getCompanyName(string $companyId): string
    {
        try {
            $companyModel = new \App\Models\Company();
            $company = $companyModel->find($companyId);
            return $company ? $company['name'] : 'Unknown Company';
        } catch (\Exception $e) {
            return 'Unknown Company';
        }
    }

    /**
     * Log audit trail for closed jobs
     */
    private function logAudit(string $jobId, string $jobTitle, ?string $expiredAt, ?string $companyId): void
    {
        try {
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => 'SYSTEM',
                'action' => 'JOB_AUTO_CLOSED',
                'resource' => 'Job',
                'resourceId' => $jobId,
                'module' => 'RECRUITMENT',
                'details' => json_encode([
                    'reason' => 'Automatic expiration',
                    'title' => $jobTitle,
                    'companyId' => $companyId,
                    'expiredAt' => $expiredAt,
                    'closedAt' => date('Y-m-d H:i:s'),
                    'closedBy' => 'Automated hourly cron job',
                    'timezone' => 'Africa/Nairobi (EAT)',
                    'serverTime' => date('Y-m-d H:i:s T')
                ]),
                'createdAt' => date('Y-m-d H:i:s')
            ]);
            
            CLI::write("  ✓ Audit log created", 'dark_gray');
            
        } catch (\Exception $e) {
            CLI::write("  ⚠ Warning: Could not create audit log", 'yellow');
            log_message('error', "Audit log error for job {$jobId}: " . $e->getMessage());
        }
    }
}