<?php

/**
 * Description: Eloquently maps the `job_application_read_markers` table.
 * Records the timestamp and total application count at the moment a user
 * last "reviewed" (opened the applications list for) a given job.
 * Queried by Jobs::getMyJobs() to compute newApplicationCount per job.
 */

namespace App\Models;

use CodeIgniter\Model;

class JobApplicationReadMarker extends Model
{
    protected $table            = 'job_application_read_markers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'userId',
        'jobId',
        'lastReadAt',
        'lastSeenCount',
        'createdAt',
        'updatedAt',
    ];

    protected $useTimestamps = false;

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert a read-marker for the given user / job pair.
     * Sets lastReadAt = now and lastSeenCount = current application count.
     */
    public function touch(string $userId, string $jobId, int $currentCount): void
    {
        $now      = date('Y-m-d H:i:s');
        $existing = $this->where('userId', $userId)->where('jobId', $jobId)->first();

        if ($existing) {
            $this->update($existing['id'], [
                'lastReadAt'    => $now,
                'lastSeenCount' => $currentCount,
                'updatedAt'     => $now,
            ]);
        } else {
            $this->insert([
                'id'            => uniqid('jarm_'),
                'userId'        => $userId,
                'jobId'         => $jobId,
                'lastReadAt'    => $now,
                'lastSeenCount' => $currentCount,
                'createdAt'     => $now,
                'updatedAt'     => $now,
            ]);
        }
    }

    /**
     * Bulk-fetch markers for a user across many jobs.
     * Returns an associative array: [ jobId => markerRow ]
     */
    public function getMarkersForUser(string $userId, array $jobIds): array
    {
        if (empty($jobIds)) {
            return [];
        }

        $rows = $this->where('userId', $userId)
                     ->whereIn('jobId', $jobIds)
                     ->findAll();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['jobId']] = $row;
        }
        return $indexed;
    }
}