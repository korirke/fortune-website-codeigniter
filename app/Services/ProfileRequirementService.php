<?php

namespace App\Services;

use App\Models\JobProfileRequirement;
use App\Models\CandidateProfile;
use App\Models\JobApplicationConfig;
use App\Libraries\ProfileRequirementKeys;

class ProfileRequirementService
{
    /**
     * Returns requirement keys for a job.
     */
    public function getJobRequirementKeys(string $jobId): array
    {
        $m = new JobProfileRequirement();
        $rows = $m->where('jobId', $jobId)->where('isRequired', 1)->findAll();

        log_message('debug', 'JobProfileRequirement rows: ' . json_encode($rows));

        return array_values(array_map(fn($r) => $r['requirementKey'], $rows));
    }

    /**
     * Get job application config (with defaults if not set).
     */
    public function getJobApplicationConfig(string $jobId): array
    {
        $configModel = new JobApplicationConfig();
        $row = $configModel->where('jobId', $jobId)->first();

        if (!$row) {
            return [
                'refereesRequired' => 0,
                'requiredEducationLevels' => [],
                'showGeneralExperience' => false,
                'showSpecificExperience' => false,
                'sectionOrder' => ProfileRequirementKeys::defaultSectionOrder(),
                'showDescription' => true,
                'generalExperienceText' => '',
                'specificExperienceText' => '',
            ];
        }

        return [
            'refereesRequired' => (int) ($row['refereesRequired'] ?? 0),
            'requiredEducationLevels' => json_decode($row['requiredEducationLevels'] ?? '[]', true) ?: [],
            'showGeneralExperience' => (bool) ($row['showGeneralExperience'] ?? false),
            'showSpecificExperience' => (bool) ($row['showSpecificExperience'] ?? false),
            'sectionOrder' => json_decode($row['sectionOrder'] ?? 'null', true) ?: ProfileRequirementKeys::defaultSectionOrder(),
            'showDescription' => (bool) ($row['showDescription'] ?? true),
            'generalExperienceText' => $row['generalExperienceText'] ?? '',
            'specificExperienceText' => $row['specificExperienceText'] ?? '',
        ];
    }

    /**
     * Evaluates candidate completion vs job requirements.
     */
    public function evaluateCandidateForJob(string $candidateUserId, string $jobId): array
    {
        $requiredKeys = $this->getJobRequirementKeys($jobId);
        $jobConfig = $this->getJobApplicationConfig($jobId);

        if (count($requiredKeys) === 0) {
            return [
                'isEligible' => true,
                'completedCount' => 0,
                'totalRequired' => 0,
                'missingKeys' => [],
                'completionPercentage' => 100,
            ];
        }

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

        $userModel = new \App\Models\User();
        $userRow = $userModel->select('phone')->find($candidateUserId);
        $userPhone = $userRow['phone'] ?? null;

        $db = \Config\Database::connect();

        $checks = [];
        foreach ($requiredKeys as $k) {
            $checks[$k] = $this->checkOne($db, $profile, $k, $userPhone, $jobConfig);
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

    // ─────────────────────────────────────────────────────────────────────────
    // Private: check a single requirement key
    // ─────────────────────────────────────────────────────────────────────────

    private function checkOne($db, array $profile, string $key, ?string $userPhone = null, array $jobConfig = []): bool
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
                $hasAny = $db->table('educations')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

                if (!$hasAny)
                    return false;

                $requiredLevels = $jobConfig['requiredEducationLevels'] ?? [];
                if (empty($requiredLevels))
                    return true;

                $dbMap = ProfileRequirementKeys::educationLevelToDbMap();
                foreach ($requiredLevels as $level) {
                    if (!$this->candidateHasEducationLevel($db, $profile['id'], $level, $dbMap)) {
                        return false;
                    }
                }
                return true;

            case 'PERSONAL_INFO':
                return $db->table('candidate_personal_info')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'CLEARANCES':
                return $db->table('candidate_clearances')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults() > 0;

            case 'MEMBERSHIPS':
                return $db->table('candidate_professional_memberships')
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
                $count = $db->table('candidate_referees')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults();

                if ($count <= 0)
                    return false;

                $minRequired = (int) ($jobConfig['refereesRequired'] ?? 0);
                if ($minRequired > 0 && $count < $minRequired)
                    return false;
                return true;

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

            case 'DOCUMENT_DRIVING_LICENSE':
                return $db->table('candidate_files')
                    ->where('candidateId', $profile['id'])
                    ->where('category', 'DRIVING_LICENSE')
                    ->countAllResults() > 0;

            default:
                return false;
        }
    }

    /**
     * Check if a candidate has a specific education level.
     *
     * Strategy (in order):
     *  1. Direct match on `degreeLevel` column using the canonical key
     *     (works for all new records after varchar migration).
     *  2. Legacy match using the old enum value from dbMap
     *     (e.g. POST_GRAD_DIPLOMA for rows inserted before migration).
     *  3. Keyword search in `degree` text column
     *     (backward compat for KCPE/KCSE/A_LEVEL records entered as free-text).
     */
    private function candidateHasEducationLevel($db, string $candidateProfileId, string $level, array $dbMap): bool
    {
        // ── 1. Direct degreeLevel match (canonical key, new records) ──────────
        $directCount = $db->table('educations')
            ->where('candidateId', $candidateProfileId)
            ->where('degreeLevel', $level)
            ->countAllResults();

        if ($directCount > 0)
            return true;

        // ── 2. Legacy enum value from dbMap ───
        $legacyValue = $dbMap[$level] ?? null;
        if ($legacyValue !== null && $legacyValue !== $level) {
            $legacyCount = $db->table('educations')
                ->where('candidateId', $candidateProfileId)
                ->where('degreeLevel', $legacyValue)
                ->countAllResults();

            if ($legacyCount > 0)
                return true;
        }

        // ── 3. Keyword search in `degree` text column (legacy free-text) ──────
        $keywords = [
            'KCPE' => ['KCPE', 'Kenya Certificate of Primary'],
            'KCSE' => ['KCSE', 'Kenya Certificate of Secondary'],
            'A_LEVEL' => ['A-Level', 'A Level', 'Form 6', 'Form Six', 'Higher School Certificate'],
        ];

        $searchTerms = $keywords[$level] ?? [];
        if (empty($searchTerms))
            return false;

        $builder = $db->table('educations')->where('candidateId', $candidateProfileId);
        $builder->groupStart();
        foreach ($searchTerms as $term) {
            $builder->orLike('degree', $term, 'both', true, true);
        }
        $builder->groupEnd();

        return $builder->countAllResults() > 0;
    }
}
