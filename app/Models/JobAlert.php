<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * JobAlert Model
 * Represents a saved search / subscription a candidate has set up.

 */
class JobAlert extends Model
{
    protected $table            = 'job_alerts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id', 'userId', 'name', 'keyword', 'location',
        'jobType', 'categoryId', 'frequency', 'isActive',
        'lastSentAt', 'createdAt', 'updatedAt',
    ];

    protected $useTimestamps = false;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Find all active alerts for a user.
     */
    public function getUserAlerts(string $userId): array
    {
        return $this->where('userId', $userId)
                    ->orderBy('createdAt', 'DESC')
                    ->findAll();
    }

    /**
     * Find all active INSTANT alerts that match a given job.
     */
    public function getInstantMatchesForJob(array $job): array
    {
        $builder = $this->where('isActive', 1)
                        ->where('frequency', 'INSTANT');

        return array_filter($builder->findAll(), fn($alert) => $this->matches($job, $alert));
    }

    /**
     * Find all active DAILY alerts.
     */
    public function getDailyAlerts(): array
    {
        return $this->where('isActive', 1)
                    ->where('frequency', 'DAILY')
                    ->findAll();
    }

    /**
     * Find all active WEEKLY alerts.
     */
    public function getWeeklyAlerts(): array
    {
        return $this->where('isActive', 1)
                    ->where('frequency', 'WEEKLY')
                    ->findAll();
    }

    /**
     * Simple matching: job title / description must contain keyword, AND
     * location / type must match if the alert specifies them.
     */
    public function matches(array $job, array $alert): bool
    {
        // keyword check
        if (!empty($alert['keyword'])) {
            $kw = strtolower(trim($alert['keyword']));
            $haystack = strtolower(($job['title'] ?? '') . ' ' . ($job['description'] ?? ''));
            if (strpos($haystack, $kw) === false) {
                return false;
            }
        }

        // location check
        if (!empty($alert['location'])) {
            $alertLoc = strtolower(trim($alert['location']));
            $jobLoc   = strtolower($job['location'] ?? '');
            if (strpos($jobLoc, $alertLoc) === false) {
                return false;
            }
        }

        // job type check
        if (!empty($alert['jobType']) && !empty($job['type'])) {
            if (strtoupper($alert['jobType']) !== strtoupper($job['type'])) {
                return false;
            }
        }

        // category check
        if (!empty($alert['categoryId']) && !empty($job['categoryId'])) {
            if ($alert['categoryId'] !== $job['categoryId']) {
                return false;
            }
        }

        return true;
    }
}
