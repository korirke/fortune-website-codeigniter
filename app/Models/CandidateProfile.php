<?php

namespace App\Models;

use CodeIgniter\Model;

class CandidateProfile extends Model
{
    protected $table            = 'candidate_profiles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'userId',
        'title',
        'bio',
        'location',
        'currentCompany',
        'experienceYears',
        'totalExperienceMonths',
        'expectedSalary',
        'currency',
        'openToWork',
        'availableFrom',
        'websiteUrl',
        'linkedinUrl',
        'githubUrl',
        'portfolioUrl',
        'resumeUrl',
        'resumeUpdatedAt',
        'profileViews',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';

    /**
     * Calculate total months from experiences
     * Handles overlapping date ranges
     */
    public function calculateExperienceMonths($candidateId)
    {
        $experienceModel = new \App\Models\Experience();

        $experiences = $experienceModel
            ->where('candidateId', $candidateId)
            ->orderBy('startDate', 'ASC')
            ->findAll();

        if (empty($experiences)) {
            return 0;
        }

        $ranges = [];

        // Convert experiences to date ranges
        foreach ($experiences as $exp) {
            try {
                $start = new \DateTime($exp['startDate']);
                $end = $exp['isCurrent']
                    ? new \DateTime()
                    : new \DateTime($exp['endDate']);

                // Ensure end date is after start date
                if ($end < $start) {
                    continue;
                }

                $ranges[] = [$start, $end];
            } catch (\Exception $e) {
                log_message('error', 'Invalid date in experience: ' . $e->getMessage());
                continue;
            }
        }

        if (empty($ranges)) {
            return 0;
        }

        // Merge overlapping ranges
        $merged = [];
        foreach ($ranges as $range) {
            if (empty($merged)) {
                $merged[] = $range;
                continue;
            }

            $lastIndex = count($merged) - 1;
            $lastRange = $merged[$lastIndex];

            // Check if ranges overlap or touch
            if ($range[0] <= $lastRange[1]) {
                // Overlapping → extend end date
                if ($range[1] > $lastRange[1]) {
                    $merged[$lastIndex][1] = $range[1];
                }
            } else {
                // No overlap → add as new range
                $merged[] = $range;
            }
        }

        // Calculate total months from merged ranges
        $totalMonths = 0;

        foreach ($merged as $range) {
            $interval = $range[0]->diff($range[1]);
            $totalMonths += ($interval->y * 12) + $interval->m;
            
            // Add 1 month if there are significant days (>=15)
            if ($interval->d >= 15) {
                $totalMonths += 1;
            }
        }

        return max(0, $totalMonths);
    }

    /**
     * Format months as human-readable string
     */
    public function formatExperience($months)
    {
        if (!$months || $months <= 0) {
            return "0 years";
        }

        $years = floor($months / 12);
        $remainingMonths = $months % 12;

        if ($years > 0 && $remainingMonths > 0) {
            return "{$years} years {$remainingMonths} months";
        } elseif ($years > 0) {
            return "{$years} years";
        } else {
            return "{$remainingMonths} months";
        }
    }

    /**
     * Recalculate and cache experience for a candidate
     * Call this whenever experiences are added/updated/deleted
     */
    public function recalculateExperience($candidateId)
    {
        $totalMonths = $this->calculateExperienceMonths($candidateId);
        $formatted = $this->formatExperience($totalMonths);

        // Get candidate profile by candidate_profiles.id (NOT userId)
        $profile = $this->find($candidateId);
        
        if (!$profile) {
            log_message('error', 'Profile not found for candidateId: ' . $candidateId);
            return false;
        }

        return $this->update($candidateId, [
            'totalExperienceMonths' => $totalMonths,
            'experienceYears' => $formatted
        ]);
    }

    /**
     * Recalculate experience by userId (for backward compatibility)
     */
    public function recalculateExperienceByUserId($userId)
    {
        $profile = $this->where('userId', $userId)->first();
        
        if (!$profile) {
            log_message('error', 'Profile not found for userId: ' . $userId);
            return false;
        }

        return $this->recalculateExperience($profile['id']);
    }

}
