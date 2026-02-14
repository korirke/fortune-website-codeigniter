<?php

namespace App\Services;

use App\Models\JobProfileRequirement;
use App\Models\CandidateProfile;

class ProfileRequirementService
{
    /**
     * Returns requirement keys for a job.
     */
    public function getJobRequirementKeys(string $jobId): array
    {
        $m = new JobProfileRequirement();
        $rows = $m->where('jobId', $jobId)->where('isRequired', 1)->findAll();

        return array_values(array_map(fn($r) => $r['requirementKey'], $rows));
    }

    /**
     * Evaluates candidate completion vs job requirements.
     * Returns: [isEligible, completedCount, totalRequired, missingKeys, missingLabels, completionPercentage]
     */
    public function evaluateCandidateForJob(string $candidateUserId, string $jobId): array
    {
        $requiredKeys = $this->getJobRequirementKeys($jobId);

        // If no strict requirements configured → allow apply
        if (count($requiredKeys) === 0) {
            return [
                'isEligible' => true,
                'completedCount' => 0,
                'totalRequired' => 0,
                'missingKeys' => [],
                'completionPercentage' => 100,
            ];
        }

        // Load candidate profile + related blocks efficiently
        // NOTE: your Candidate::getProfile already assembles everything,
        // but for enforcement we do lighter targeted checks.
        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $candidateUserId)->first();

        if (!$profile) {
            // No profile at all => missing all
            return [
                'isEligible' => false,
                'completedCount' => 0,
                'totalRequired' => count($requiredKeys),
                'missingKeys' => $requiredKeys,
                'completionPercentage' => 0,
            ];
        }

        // load phone from USERS table
        $userModel = new \App\Models\User();
        $userRow = $userModel->select('phone')->find($candidateUserId);
        $userPhone = $userRow['phone'] ?? null;

        $db = \Config\Database::connect();

        $checks = [];
        foreach ($requiredKeys as $k) {
            $checks[$k] = $this->checkOne($db, $profile, $k, $userPhone);
        }

        $missingKeys = [];
        $completedCount = 0;
        foreach ($checks as $k => $ok) {
            if ($ok)
                $completedCount++;
            else
                $missingKeys[] = $k;
        }

        $total = count($requiredKeys);
        $pct = $total > 0 ? (int) round(($completedCount / $total) * 100) : 100;

        return [
            'isEligible' => count($missingKeys) === 0,
            'completedCount' => $completedCount,
            'totalRequired' => $total,
            'missingKeys' => $missingKeys,
            'completionPercentage' => $pct,
        ];
    }

    private function checkOne($db, array $profile, string $key, ?string $userPhone = null): bool
    {
        switch ($key) {
            case 'BASIC_PHONE':
                return !empty(trim((string) ($userPhone ?? '')));

            case 'BASIC_LOCATION':
                return !empty(trim((string) ($profile['location'] ?? '')));

            case 'BASIC_TITLE':
                return !empty(trim((string) ($profile['title'] ?? '')));

            case 'BASIC_BIO':
                $bio = trim((string) ($profile['bio'] ?? ''));
                return strlen($bio) >= 50 && $bio !== ($profile['title'] ?? null);

            case 'RESUME':
                return !empty($profile['resumeUrl'] ?? null);

            case 'SKILLS':
                return $db->table('candidate_skills')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'EXPERIENCE':
                return $db->table('experiences')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'EDUCATION':
                return $db->table('educations')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'PERSONAL_INFO':
                return $db->table('candidate_personal_info')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'CLEARANCES':
                return $db->table('candidate_clearances')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'MEMBERSHIPS':
                return $db->table('candidate_memberships')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'PUBLICATIONS':
                return $db->table('candidate_publications')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'COURSES':
                return $db->table('candidate_courses')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'REFEREES':
                return $db->table('candidate_referees')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'DOCUMENT_NATIONAL_ID':
                return $db->table('candidate_files')
                    ->where('candidateId', $profile['id'])
                    ->where('category', 'NATIONAL_ID')
                    ->countAllResults() > 0;

            case 'DOCUMENT_ACADEMIC_CERT':
                return $db->table('candidate_files')
                    ->where('candidateId', $profile['id'])
                    ->where('category', 'ACADEMIC_CERT')
                    ->countAllResults() > 0;

            case 'DOCUMENT_PROFESSIONAL_CERT':
                return $db->table('candidate_files')
                    ->where('candidateId', $profile['id'])
                    ->where('category', 'PROFESSIONAL_CERT')
                    ->countAllResults() > 0;

            default:
                // Unknown key => treat as not satisfied
                return false;
        }
    }
}