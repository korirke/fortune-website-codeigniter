<?php

namespace App\Services;

use App\Models\JobShortlistCriteria;
use App\Models\ShortlistResult;
use App\Models\Application;
use CodeIgniter\Database\BaseConnection;

class ShortlistService
{
    private BaseConnection $db;
    private JobShortlistCriteria $criteriaModel;
    private ShortlistResult $resultModel;

    private const CRITERIA_JSON_FIELDS = [
        'specificCounties',
        'specificDegreeFields',
        'specificInstitutions',
        'specificIndustries',
        'specificDomains',
        'specificJobTitles',
        'requiredSkills',
        'preferredSkills',
        'specificLanguages',
        'specificProfessionalBodies',
        'requiredCertifications',
        'preferredCertifications',
        'specificPublicationTypes',
        'specificLocations',
        'customCriteria',
    ];

    private const RESULT_JSON_FIELDS = [
        'matchedCriteria',
        'missedCriteria',
        'bonusCriteria',
        'disqualificationReasons',
    ];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->criteriaModel = new JobShortlistCriteria();
        $this->resultModel = new ShortlistResult();
        log_message('info', '[ShortlistService] Service initialized');
    }

    // =========================================================
    // ADMIN MANUAL SCORING: EFFECTIVE SCORE
    // =========================================================

    public function hasManualScores(array $r): bool
    {
        $fields = [
            'manualTotalScore',
            'manualEducationScore',
            'manualExperienceScore',
            'manualSkillsScore',
            'manualClearanceScore',
            'manualProfessionalScore',
        ];

        foreach ($fields as $f) {
            if (array_key_exists($f, $r) && $r[$f] !== null && $r[$f] !== '') {
                return true;
            }
        }
        return false;
    }

    public function getEffectiveCategoryScore(array $r, string $categoryKey): float
    {
        $map = [
            'education' => ['manual' => 'manualEducationScore', 'system' => 'educationScore'],
            'experience' => ['manual' => 'manualExperienceScore', 'system' => 'experienceScore'],
            'skills' => ['manual' => 'manualSkillsScore', 'system' => 'skillsScore'],
            'clearance' => ['manual' => 'manualClearanceScore', 'system' => 'clearanceScore'],
            'professional' => ['manual' => 'manualProfessionalScore', 'system' => 'professionalScore'],
        ];

        if (!isset($map[$categoryKey]))
            return 0.0;

        $m = $map[$categoryKey]['manual'];
        $s = $map[$categoryKey]['system'];

        if (isset($r[$m]) && $r[$m] !== null && $r[$m] !== '') {
            return (float) $r[$m];
        }

        return (float) ($r[$s] ?? 0);
    }

    public function getEffectiveTotalScore(array $r): float
    {
        // manual total has highest priority
        if (isset($r['manualTotalScore']) && $r['manualTotalScore'] !== null && $r['manualTotalScore'] !== '') {
            return (float) $r['manualTotalScore'];
        }

        // if any manual category exists, total = sum effective categories
        if ($this->hasManualScores($r)) {
            $sum =
                $this->getEffectiveCategoryScore($r, 'education') +
                $this->getEffectiveCategoryScore($r, 'experience') +
                $this->getEffectiveCategoryScore($r, 'skills') +
                $this->getEffectiveCategoryScore($r, 'clearance') +
                $this->getEffectiveCategoryScore($r, 'professional');

            return (float) round($sum, 2);
        }

        // otherwise system totalScore
        return (float) ($r['totalScore'] ?? 0);
    }

    public function isEffectivelyDisqualified(array $r): bool
    {
        $override = !empty($r['overrideDisqualification']);
        $disq = !empty($r['hasDisqualifyingFactor']);
        return $disq && !$override;
    }

    // =========================================================
    // CRITERIA: DEFAULTS + NORMALIZATION
    // =========================================================
    private function defaultCriteria(string $jobId): array
    {
        $now = date('Y-m-d H:i:s');
        return [
            'id' => uniqid('criteria_'),
            'jobId' => $jobId,
            'minAge' => null,
            'maxAge' => null,
            'requiredGender' => null,
            'requiredNationality' => null,
            'specificCounties' => [],
            'acceptPLWD' => true,
            'requirePLWD' => false,
            'requireDoctorate' => false,
            'requireMasters' => false,
            'requireBachelors' => false,
            'specificDegreeFields' => [],
            'specificInstitutions' => [],
            'minEducationGrade' => null,
            'minGeneralExperience' => 0,
            'maxGeneralExperience' => null,
            'minSeniorExperience' => 0,
            'maxSeniorExperience' => null,
            'specificIndustries' => [],
            'specificDomains' => [],
            'requireMNCExperience' => false,
            'requireStartupExperience' => false,
            'requireNGOExperience' => false,
            'requireGovernmentExperience' => false,
            'specificJobTitles' => [],
            'requireManagementExperience' => false,
            'minTeamSizeManaged' => null,
            'requireCurrentlyEmployed' => false,
            'excludeCurrentlyEmployed' => false,
            'requiredSkills' => [],
            'preferredSkills' => [],
            'minSkillLevel' => null,
            'specificLanguages' => [],
            'minLanguageProficiency' => null,
            'requireProfessionalMembership' => false,
            'specificProfessionalBodies' => [],
            'requireGoodStanding' => false,
            'requiredCertifications' => [],
            'preferredCertifications' => [],
            'requireLeadershipCourse' => false,
            'minLeadershipCourseDuration' => 4,
            'minPublications' => 0,
            'specificPublicationTypes' => [],
            'requireRecentPublications' => false,
            'publicationYearsThreshold' => null,
            'requireTaxClearance' => false,
            'requireHELBClearance' => false,

            // IMPORTANT: keep ONLY one key name consistently
            'requireDCICClearance' => false,

            'requireCRBClearance' => false,
            'requireEACCClearance' => false,
            'requireAllClearancesValid' => false,
            'maxClearanceAge' => null,
            'maxExpectedSalary' => null,
            'minExpectedSalary' => null,
            'requireImmediateAvailability' => false,
            'maxNoticePeriod' => null,
            'acceptRemoteCandidates' => true,
            'requireOnSiteCandidates' => false,
            'specificLocations' => [],
            'requireReferees' => false,
            'minRefereeCount' => 0,
            'requireSeniorReferees' => false,
            'requireAcademicReferees' => false,
            'requirePortfolio' => false,
            'requireGitHubProfile' => false,
            'requireLinkedInProfile' => false,
            'minPortfolioProjects' => null,
            'excludeInternalCandidates' => false,
            'excludePreviousApplicants' => false,
            'requireDiversityHire' => false,
            'customCriteria' => [],
            'educationWeight' => 25,
            'experienceWeight' => 30,
            'skillsWeight' => 20,
            'clearanceWeight' => 15,
            'professionalWeight' => 10,
            'isActive' => true,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
    }

    private function decodeCriteriaJson(array $criteria): array
    {
        foreach (self::CRITERIA_JSON_FIELDS as $field) {
            if (!array_key_exists($field, $criteria) || $criteria[$field] === null) {
                $criteria[$field] = [];
                continue;
            }
            if (is_array($criteria[$field]))
                continue;

            if (is_string($criteria[$field])) {
                $decoded = json_decode($criteria[$field], true);
                $criteria[$field] = is_array($decoded) ? $decoded : [];
                continue;
            }
            $criteria[$field] = [];
        }
        return $criteria;
    }

    private function encodeCriteriaJson(array $data): array
    {
        foreach (self::CRITERIA_JSON_FIELDS as $field) {
            if (!array_key_exists($field, $data))
                continue;

            if ($data[$field] === null) {
                $data[$field] = json_encode([]);
                continue;
            }

            if (is_array($data[$field])) {
                $data[$field] = json_encode(array_values($data[$field]));
                continue;
            }

            if (is_string($data[$field])) {
                $test = json_decode($data[$field], true);
                $data[$field] = is_array($test) ? $data[$field] : json_encode([]);
                continue;
            }

            $data[$field] = json_encode([]);
        }
        return $data;
    }

    private function normalizeCriteriaTypes(array $data): array
    {
        $boolFields = [
            'acceptPLWD',
            'requirePLWD',
            'requireDoctorate',
            'requireMasters',
            'requireBachelors',
            'requireMNCExperience',
            'requireStartupExperience',
            'requireNGOExperience',
            'requireGovernmentExperience',
            'requireManagementExperience',
            'requireCurrentlyEmployed',
            'excludeCurrentlyEmployed',
            'requireProfessionalMembership',
            'requireGoodStanding',
            'requireLeadershipCourse',
            'requireRecentPublications',
            'requireTaxClearance',
            'requireHELBClearance',
            'requireDCICClearance',
            'requireCRBClearance',
            'requireEACCClearance',
            'requireAllClearancesValid',
            'requireImmediateAvailability',
            'acceptRemoteCandidates',
            'requireOnSiteCandidates',
            'requireReferees',
            'requireSeniorReferees',
            'requireAcademicReferees',
            'requirePortfolio',
            'requireGitHubProfile',
            'requireLinkedInProfile',
            'excludeInternalCandidates',
            'excludePreviousApplicants',
            'requireDiversityHire',
            'isActive',
        ];

        foreach ($boolFields as $f) {
            if (array_key_exists($f, $data))
                $data[$f] = (bool) $data[$f];
        }

        $intFields = [
            'minAge',
            'maxAge',
            'minGeneralExperience',
            'maxGeneralExperience',
            'minSeniorExperience',
            'maxSeniorExperience',
            'minTeamSizeManaged',
            'minLeadershipCourseDuration',
            'minPublications',
            'publicationYearsThreshold',
            'maxClearanceAge',
            'maxExpectedSalary',
            'minExpectedSalary',
            'maxNoticePeriod',
            'minRefereeCount',
            'minPortfolioProjects',
            'educationWeight',
            'experienceWeight',
            'skillsWeight',
            'clearanceWeight',
            'professionalWeight',
        ];

        foreach ($intFields as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null && $data[$f] !== '')
                $data[$f] = (int) $data[$f];
        }

        return $data;
    }

    private function normalizeCriteriaForResponse(array $criteria): array
    {
        $criteria = $this->decodeCriteriaJson($criteria);
        $criteria = $this->normalizeCriteriaTypes($criteria);
        if (empty($criteria['requiredGender']))
            $criteria['requiredGender'] = null;
        return $criteria;
    }

    // =========================================================
    // PUBLIC: GET / UPDATE CRITERIA
    // =========================================================
    public function getJobCriteria(string $jobId): array
    {
        $criteria = $this->criteriaModel->where('jobId', $jobId)->first();
        if (!$criteria) {
            $criteria = $this->defaultCriteria($jobId);
            $toInsert = $this->encodeCriteriaJson($criteria);
            $toInsert = $this->normalizeCriteriaTypes($toInsert);
            $this->criteriaModel->insert($toInsert);
            return $this->normalizeCriteriaForResponse($criteria);
        }
        return $this->normalizeCriteriaForResponse($criteria);
    }

    public function updateJobCriteria(string $jobId, array $data): bool
    {
        $existing = $this->criteriaModel->where('jobId', $jobId)->first();
        $data['jobId'] = $jobId;
        $data['updatedAt'] = date('Y-m-d H:i:s');
        $data = $this->normalizeCriteriaTypes($data);
        $data = $this->encodeCriteriaJson($data);

        if ($existing) {
            return (bool) $this->criteriaModel->update($existing['id'], $data);
        }

        $data['id'] = uniqid('criteria_');
        $data['createdAt'] = $data['createdAt'] ?? date('Y-m-d H:i:s');
        return (bool) $this->criteriaModel->insert($data);
    }

    // =========================================================
    // STALE DETECTION
    // =========================================================
    public function isShortlistStale(string $jobId): array
    {
        $criteria = $this->criteriaModel->where('jobId', $jobId)->first();
        if (!$criteria)
            return ['isStale' => false, 'reason' => 'No criteria set'];

        $latestResult = $this->db->table('shortlist_results')
            ->where('jobId', $jobId)
            ->orderBy('updatedAt', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if (!$latestResult)
            return ['isStale' => true, 'reason' => 'No shortlist generated yet'];

        if (strtotime($criteria['updatedAt']) > strtotime($latestResult['updatedAt'])) {
            return [
                'isStale' => true,
                'reason' => 'Criteria updated after last generation',
                'criteriaUpdatedAt' => $criteria['updatedAt'],
                'shortlistGeneratedAt' => $latestResult['updatedAt']
            ];
        }

        return ['isStale' => false, 'reason' => 'Results are current'];
    }

    // =========================================================
    // SHORTLIST GENERATION
    // =========================================================
    public function generateShortlist(string $jobId): array
    {
        $criteria = $this->getJobCriteria($jobId);

        $applicationModel = new Application();
        $applications = $applicationModel->where('jobId', $jobId)->findAll();
        if (empty($applications)) {
            return ['success' => false, 'message' => 'No applications found', 'count' => 0];
        }

        $results = [];
        $generationTimestamp = date('Y-m-d H:i:s');

        foreach ($applications as $app) {
            $candidateId = $app['candidateId'];

            $profile = $this->db->table('candidate_profiles')
                ->where('userId', $candidateId)
                ->get()
                ->getRowArray();

            if (!$profile)
                continue;

            $profileId = $profile['id'];
            $score = $this->scoreCandidate($profileId, $candidateId, $app, $criteria);

            $resultData = [
                'jobId' => $jobId,
                'applicationId' => $app['id'],
                'candidateId' => $candidateId,

                // system scores only
                'totalScore' => $score['totalScore'],
                'educationScore' => $score['educationScore'],
                'experienceScore' => $score['experienceScore'],
                'skillsScore' => $score['skillsScore'],
                'clearanceScore' => $score['clearanceScore'],
                'professionalScore' => $score['professionalScore'],

                'matchedCriteria' => json_encode($score['matched']),
                'missedCriteria' => json_encode($score['missed']),
                'bonusCriteria' => json_encode($score['bonus']),
                'hasAllMandatory' => (bool) $score['hasAllMandatory'],
                'hasDisqualifyingFactor' => (bool) $score['hasDisqualifyingFactor'],
                'disqualificationReasons' => json_encode($score['disqualificationReasons']),
                'updatedAt' => $generationTimestamp,
            ];

            $existing = $this->resultModel
                ->where('jobId', $jobId)
                ->where('applicationId', $app['id'])
                ->first();

            if ($existing) {
                // Do not clear manual overrides; update only system fields
                $this->resultModel->update($existing['id'], $resultData);
            } else {
                $resultData['id'] = uniqid('shortlist_');
                $resultData['createdAt'] = $generationTimestamp;
                $this->resultModel->insert($resultData);
            }

            $results[] = [
                ...$resultData,
                'matchedCriteria' => $score['matched'],
                'missedCriteria' => $score['missed'],
                'bonusCriteria' => $score['bonus'],
                'disqualificationReasons' => $score['disqualificationReasons'],
            ];
        }

        // IMPORTANT: rank using effective score (manual overrides included)
        $this->calculateRanks($jobId);

        return [
            'success' => true,
            'message' => 'Shortlist generated successfully',
            'count' => count($results),
            'results' => $results,
            'generatedAt' => $generationTimestamp
        ];
    }

    // Used after admin scoring update
    public function rerank(string $jobId): void
    {
        $this->calculateRanks($jobId);
    }

    // =========================================================
    // MAIN SCORING ENGINE (UNCHANGED)
    // =========================================================
    protected function scoreCandidate(string $profileId, string $userId, array $application, array $criteria): array
    {
        $score = [
            'educationScore' => 0,
            'experienceScore' => 0,
            'skillsScore' => 0,
            'clearanceScore' => 0,
            'professionalScore' => 0,
            'totalScore' => 0,
            'matched' => [],
            'missed' => [],
            'bonus' => [],
            'hasAllMandatory' => true,
            'hasDisqualifyingFactor' => false,
            'disqualificationReasons' => [],
        ];

        $personalInfo = $this->db->table('candidate_personal_info')->where('candidateId', $profileId)->get()->getRowArray();
        $educations = $this->db->table('educations')->where('candidateId', $profileId)->get()->getResultArray();
        $experiences = $this->db->table('experiences')->where('candidateId', $profileId)->get()->getResultArray();
        $clearances = $this->db->table('candidate_clearances')->where('candidateId', $profileId)->get()->getResultArray();
        $memberships = $this->db->table('candidate_professional_memberships')->where('candidateId', $profileId)->get()->getResultArray();
        $courses = $this->db->table('candidate_courses')->where('candidateId', $profileId)->get()->getResultArray();
        $publications = $this->db->table('candidate_publications')->where('candidateId', $profileId)->get()->getResultArray();
        $referees = $this->db->table('candidate_referees')->where('candidateId', $profileId)->get()->getResultArray();
        $certifications = $this->db->table('certifications')->where('candidateId', $profileId)->get()->getResultArray();
        $candidateSkills = $this->db->table('candidate_skills')->where('candidateId', $profileId)->get()->getResultArray();
        $profile = $this->db->table('candidate_profiles')->where('id', $profileId)->get()->getRowArray();

        $this->evaluatePersonalInfo($personalInfo, $application, $criteria, $score);
        if ($score['hasDisqualifyingFactor']) {
            $score['totalScore'] = 0;
            return $score;
        }

        $this->evaluateEducation($educations, $criteria, $score, (int) ($criteria['educationWeight'] ?? 0));
        $this->evaluateExperience($experiences, $profile, $userId, $criteria, $score, (int) ($criteria['experienceWeight'] ?? 0));
        $this->evaluateSkills($candidateSkills, $criteria, $score, (int) ($criteria['skillsWeight'] ?? 0));
        $this->evaluateClearances($clearances, $criteria, $score, (int) ($criteria['clearanceWeight'] ?? 0));
        $this->evaluateProfessional(
            $memberships,
            $certifications,
            $courses,
            $publications,
            $referees,
            $profile,
            $criteria,
            $score,
            (int) ($criteria['professionalWeight'] ?? 0)
        );

        $score['totalScore'] = round(
            $score['educationScore'] + $score['experienceScore'] + $score['skillsScore'] + $score['clearanceScore'] + $score['professionalScore'],
            2
        );

        return $score;
    }

    // =========================================================
    // EVALUATION METHODS
    // =========================================================

    protected function evaluatePersonalInfo(?array $personalInfo, array $application, array $criteria, array &$score): void
    {
        if (!empty($criteria['minAge']) || !empty($criteria['maxAge'])) {
            $age = null;
            if (!empty($personalInfo['dob'])) {
                try {
                    $dob = new \DateTime($personalInfo['dob']);
                    $age = (new \DateTime())->diff($dob)->y;
                } catch (\Exception $e) {
                }
            }
            if ($age !== null) {
                $ageOk = true;
                $ageReason = [];
                if (!empty($criteria['minAge']) && $age < $criteria['minAge']) {
                    $ageOk = false;
                    $ageReason[] = "below minimum ({$criteria['minAge']})";
                }
                if (!empty($criteria['maxAge']) && $age > $criteria['maxAge']) {
                    $ageOk = false;
                    $ageReason[] = "above maximum ({$criteria['maxAge']})";
                }
                if ($ageOk) {
                    $score['matched'][] = "Age: {$age} years (within " . ($criteria['minAge'] ?? 'any') . "-" . ($criteria['maxAge'] ?? 'any') . ")";
                } else {
                    $score['hasDisqualifyingFactor'] = true;
                    $score['disqualificationReasons'][] = "Age {$age} is " . implode(' and ', $ageReason);
                    return;
                }
            }
        }

        if (!empty($criteria['requiredGender']) && $criteria['requiredGender'] !== 'ANY') {
            $gender = strtoupper($personalInfo['gender'] ?? '');
            if ($gender === strtoupper($criteria['requiredGender'])) {
                $score['matched'][] = "Gender: {$criteria['requiredGender']}";
            } else {
                $score['hasDisqualifyingFactor'] = true;
                $score['disqualificationReasons'][] = "Gender mismatch (required: {$criteria['requiredGender']}, got: {$gender})";
                return;
            }
        }

        if (!empty($criteria['requiredNationality']) && $criteria['requiredNationality'] !== 'ANY') {
            $nationality = $personalInfo['nationality'] ?? '';
            if (stripos($nationality, $criteria['requiredNationality']) !== false) {
                $score['matched'][] = "Nationality: {$criteria['requiredNationality']}";
            } else {
                $score['hasDisqualifyingFactor'] = true;
                $score['disqualificationReasons'][] = "Nationality mismatch (required: {$criteria['requiredNationality']}, got: {$nationality})";
                return;
            }
        }

        if (!empty($criteria['specificCounties']) && is_array($criteria['specificCounties']) && count($criteria['specificCounties']) > 0) {
            $county = $personalInfo['countyOfOrigin'] ?? '';
            $countyMatch = false;
            foreach ($criteria['specificCounties'] as $reqCounty) {
                if (stripos($county, $reqCounty) !== false) {
                    $countyMatch = true;
                    break;
                }
            }
            if ($countyMatch)
                $score['matched'][] = "County: {$county}";
            else
                $score['missed'][] = "County not in: " . implode(', ', $criteria['specificCounties']);
        }

        $isPLWD = !empty($personalInfo['plwd']);
        if (!empty($criteria['requirePLWD']) && !$isPLWD) {
            $score['hasDisqualifyingFactor'] = true;
            $score['disqualificationReasons'][] = "Person with disability required";
            return;
        } elseif (isset($criteria['acceptPLWD']) && !$criteria['acceptPLWD'] && $isPLWD) {
            $score['hasDisqualifyingFactor'] = true;
            $score['disqualificationReasons'][] = "PLWD not accepted for this role";
            return;
        } elseif ($isPLWD) {
            $score['bonus'][] = "Person with disability";
        }

        if (!empty($criteria['maxExpectedSalary'])) {
            $expectedSalary = (float) ($application['expectedSalary'] ?? 0);
            if ($expectedSalary > 0) {
                if ($expectedSalary <= $criteria['maxExpectedSalary'])
                    $score['matched'][] = "Expected salary within budget (KES " . number_format($expectedSalary) . ")";
                else
                    $score['missed'][] = "Expected salary exceeds budget (KES " . number_format($expectedSalary) . " > KES " . number_format($criteria['maxExpectedSalary']) . ")";
            }
        }
        if (!empty($criteria['minExpectedSalary'])) {
            $expectedSalary = (float) ($application['expectedSalary'] ?? 0);
            if ($expectedSalary > 0 && $expectedSalary >= $criteria['minExpectedSalary']) {
                $score['matched'][] = "Expected salary meets minimum (KES " . number_format($expectedSalary) . ")";
            }
        }
    }

    protected function evaluateEducation(array $educations, array $criteria, array &$score, int $weight): void
    {
        if ($weight === 0)
            return;

        $points = 0;
        $maxPoints = 0;
        $hasDoctorate = false;
        $hasMasters = false;
        $hasBachelors = false;

        foreach ($educations as $edu) {
            $degree = strtoupper($edu['degree'] ?? '');
            if (strpos($degree, 'PHD') !== false || strpos($degree, 'DOCTOR') !== false)
                $hasDoctorate = true;
            elseif (strpos($degree, 'MASTER') !== false || strpos($degree, 'MSC') !== false || strpos($degree, 'MBA') !== false)
                $hasMasters = true;
            elseif (strpos($degree, 'BACHELOR') !== false || strpos($degree, 'BSC') !== false || strpos($degree, 'BA') !== false)
                $hasBachelors = true;
        }

        if (!empty($criteria['requireDoctorate'])) {
            $maxPoints += 40;
            if ($hasDoctorate) {
                $points += 40;
                $score['matched'][] = "Doctorate degree";
            } else {
                $score['hasDisqualifyingFactor'] = true;
                $score['disqualificationReasons'][] = "Doctorate degree required";
                $score['hasAllMandatory'] = false;
                return;
            }
        } elseif ($hasDoctorate) {
            $score['bonus'][] = "Doctorate degree (exceeds requirements)";
            $points += 10;
        }

        if (!empty($criteria['requireMasters'])) {
            $maxPoints += 30;
            if ($hasMasters) {
                $points += 30;
                $score['matched'][] = "Masters degree";
            } else {
                $score['hasDisqualifyingFactor'] = true;
                $score['disqualificationReasons'][] = "Masters degree required";
                $score['hasAllMandatory'] = false;
                return;
            }
        } elseif ($hasMasters && !$hasDoctorate) {
            $score['bonus'][] = "Masters degree (exceeds requirements)";
            $points += 8;
        }

        if (!empty($criteria['requireBachelors'])) {
            $maxPoints += 30;
            if ($hasBachelors) {
                $points += 30;
                $score['matched'][] = "Bachelors degree";
            } else {
                $score['hasDisqualifyingFactor'] = true;
                $score['disqualificationReasons'][] = "Bachelors degree required";
                $score['hasAllMandatory'] = false;
                return;
            }
        }

        if (!empty($criteria['specificDegreeFields']) && is_array($criteria['specificDegreeFields']) && count($criteria['specificDegreeFields']) > 0) {
            $fieldMatch = false;
            foreach ($educations as $edu) {
                $field = strtolower($edu['fieldOfStudy'] ?? '');
                foreach ($criteria['specificDegreeFields'] as $reqField) {
                    if (stripos($field, strtolower($reqField)) !== false) {
                        $fieldMatch = true;
                        $score['matched'][] = "Degree field: {$reqField}";
                        break 2;
                    }
                }
            }
            if (!$fieldMatch)
                $score['missed'][] = "Degree field not in: " . implode(', ', $criteria['specificDegreeFields']);
        }

        if (!empty($criteria['specificInstitutions']) && is_array($criteria['specificInstitutions']) && count($criteria['specificInstitutions']) > 0) {
            $instMatch = false;
            foreach ($educations as $edu) {
                $institution = strtolower($edu['institution'] ?? '');
                foreach ($criteria['specificInstitutions'] as $reqInst) {
                    if (stripos($institution, strtolower($reqInst)) !== false) {
                        $instMatch = true;
                        $score['matched'][] = "Institution: {$reqInst}";
                        break 2;
                    }
                }
            }
            if (!$instMatch)
                $score['missed'][] = "Institution not in: " . implode(', ', $criteria['specificInstitutions']);
        }

        if ($maxPoints > 0)
            $score['educationScore'] = round(($points / $maxPoints) * $weight, 2);
        else
            $score['educationScore'] = $hasBachelors ? $weight * 0.5 : 0;
    }

    protected function getGeneralExperience(string $candidateId): int
    {
        $experiences = $this->db->table('experiences')
            ->where('candidateId', $candidateId)
            ->get()
            ->getResultArray();

        if (empty($experiences))
            return 0;

        $totalMonths = 0;
        foreach ($experiences as $exp) {
            if (empty($exp['startDate']))
                continue;

            try {
                $start = new \DateTime($exp['startDate']);
                if (!empty($exp['isCurrent']))
                    $end = new \DateTime();
                elseif (!empty($exp['endDate']))
                    $end = new \DateTime($exp['endDate']);
                else
                    continue;

                $interval = $start->diff($end);
                $monthsThisExp = ($interval->y * 12) + $interval->m;
                $totalMonths += $monthsThisExp;
            } catch (\Exception $e) {
                continue;
            }
        }

        return (int) floor($totalMonths / 12);
    }

    protected function evaluateExperience(array $experiences, array $profile, string $userId, array $criteria, array &$score, int $weight): void
    {
        if ($weight === 0)
            return;

        $points = 0;
        $maxPoints = 0;

        $generalExp = $this->getGeneralExperience($profile['id']);

        if (!empty($criteria['minGeneralExperience'])) {
            $maxPoints += 30;
            if ($generalExp >= $criteria['minGeneralExperience']) {
                $points += 30;
                $score['matched'][] = "General experience: {$generalExp} years (min: {$criteria['minGeneralExperience']})";
            } else {
                $score['hasDisqualifyingFactor'] = true;
                $score['disqualificationReasons'][] = "General experience: {$generalExp} years (min: {$criteria['minGeneralExperience']}) - REQUIRED";
                return;
            }
        }

        if (!empty($criteria['maxGeneralExperience']) && $generalExp > $criteria['maxGeneralExperience']) {
            $score['hasDisqualifyingFactor'] = true;
            $score['disqualificationReasons'][] = "Experience exceeds maximum ({$criteria['maxGeneralExperience']} years, got {$generalExp})";
            return;
        }

        $seniorYears = $this->calculateSeniorExperience($experiences);

        if (!empty($criteria['minSeniorExperience'])) {
            $maxPoints += 30;
            if ($seniorYears >= $criteria['minSeniorExperience']) {
                $points += 30;
                $score['matched'][] = "Senior experience: {$seniorYears} years (min: {$criteria['minSeniorExperience']})";
            } else {
                $score['missed'][] = "Senior experience: {$seniorYears} years (min: {$criteria['minSeniorExperience']})";
            }
        }

        if (!empty($criteria['requireManagementExperience'])) {
            $hasManagement = $this->hasManagementExperience($experiences);
            $maxPoints += 20;
            if ($hasManagement) {
                $points += 20;
                $score['matched'][] = "Management experience";
            } else {
                $score['missed'][] = "Management experience";
            }
        }

        $currentlyEmployed = false;
        foreach ($experiences as $exp) {
            if (!empty($exp['isCurrent'])) {
                $currentlyEmployed = true;
                break;
            }
        }

        if (!empty($criteria['requireCurrentlyEmployed']) && !$currentlyEmployed) {
            $score['missed'][] = "Currently employed";
        }

        if (!empty($criteria['excludeCurrentlyEmployed']) && $currentlyEmployed) {
            $score['hasDisqualifyingFactor'] = true;
            $score['disqualificationReasons'][] = "Currently employed candidates excluded";
            return;
        }

        $companyTypes = $this->detectCompanyTypes($experiences);

        if (!empty($criteria['requireMNCExperience']) && !$companyTypes['mnc'])
            $score['missed'][] = "MNC experience";
        elseif (!empty($criteria['requireMNCExperience']) && $companyTypes['mnc'])
            $score['matched'][] = "MNC experience";

        if (!empty($criteria['requireStartupExperience']) && !$companyTypes['startup'])
            $score['missed'][] = "Startup experience";
        elseif (!empty($criteria['requireStartupExperience']) && $companyTypes['startup'])
            $score['matched'][] = "Startup experience";

        if (!empty($criteria['requireNGOExperience']) && !$companyTypes['ngo'])
            $score['missed'][] = "NGO experience";
        elseif (!empty($criteria['requireNGOExperience']) && $companyTypes['ngo'])
            $score['matched'][] = "NGO experience";

        if (!empty($criteria['requireGovernmentExperience']) && !$companyTypes['government'])
            $score['missed'][] = "Government experience";
        elseif (!empty($criteria['requireGovernmentExperience']) && $companyTypes['government'])
            $score['matched'][] = "Government experience";

        if ($maxPoints > 0)
            $score['experienceScore'] = round(($points / $maxPoints) * $weight, 2);
        else
            $score['experienceScore'] = $generalExp > 0 ? $weight * 0.5 : 0;
    }

    protected function evaluateSkills(array $candidateSkills, array $criteria, array &$score, int $weight): void
    {
        if ($weight === 0)
            return;

        $points = 0;
        $maxPoints = 0;

        $candidateSkillIds = array_column($candidateSkills, 'skillId');

        if (!empty($criteria['requiredSkills']) && is_array($criteria['requiredSkills']) && count($criteria['requiredSkills']) > 0) {
            $maxPoints += 60;
            $matchedCount = 0;
            foreach ($criteria['requiredSkills'] as $reqSkillId) {
                if (in_array($reqSkillId, $candidateSkillIds)) {
                    $matchedCount++;
                    $skillName = $this->getSkillName($reqSkillId);
                    $score['matched'][] = "Required skill: {$skillName}";
                } else {
                    $skillName = $this->getSkillName($reqSkillId);
                    $score['missed'][] = "Required skill: {$skillName}";
                }
            }
            $points += ($matchedCount / count($criteria['requiredSkills'])) * 60;
        }

        if (!empty($criteria['preferredSkills']) && is_array($criteria['preferredSkills']) && count($criteria['preferredSkills']) > 0) {
            $maxPoints += 40;
            $matchedCount = 0;
            foreach ($criteria['preferredSkills'] as $prefSkillId) {
                if (in_array($prefSkillId, $candidateSkillIds)) {
                    $matchedCount++;
                    $skillName = $this->getSkillName($prefSkillId);
                    $score['bonus'][] = "Preferred skill: {$skillName}";
                }
            }
            $points += ($matchedCount / count($criteria['preferredSkills'])) * 40;
        }

        if ($maxPoints > 0)
            $score['skillsScore'] = round(($points / $maxPoints) * $weight, 2);
        else
            $score['skillsScore'] = count($candidateSkills) > 0 ? $weight * 0.5 : 0;
    }

    protected function evaluateClearances(array $clearances, array $criteria, array &$score, int $weight): void
    {
        if ($weight === 0)
            return;

        $points = 0;
        $maxPoints = 0;

        $clearanceMap = [];
        foreach ($clearances as $cl) {
            if (strtoupper($cl['status'] ?? '') === 'VALID') {
                $clearanceMap[$cl['type']] = $cl;
            }
        }

        $clearanceTypes = [
            'requireTaxClearance' => 'Tax',
            'requireHELBClearance' => 'HELB',
            'requireDCICClearance' => 'DCI',
            'requireCRBClearance' => 'CRB',
            'requireEACCClearance' => 'EACC',
        ];

        foreach ($clearanceTypes as $criteriaKey => $clearanceType) {
            if (!empty($criteria[$criteriaKey])) {
                $maxPoints += 20;
                if (isset($clearanceMap[$clearanceType])) {
                    $points += 20;
                    $score['matched'][] = "{$clearanceType} clearance";
                } else {
                    $score['missed'][] = "{$clearanceType} clearance";
                }
            }
        }

        if ($maxPoints > 0)
            $score['clearanceScore'] = round(($points / $maxPoints) * $weight, 2);
        else
            $score['clearanceScore'] = $weight * 0.5;
    }

    protected function evaluateProfessional(
        array $memberships,
        array $certifications,
        array $courses,
        array $publications,
        array $referees,
        array $profile,
        array $criteria,
        array &$score,
        int $weight
    ): void {
        if ($weight === 0)
            return;

        $points = 0;
        $maxPoints = 0;

        if (!empty($criteria['requireProfessionalMembership'])) {
            $maxPoints += 20;
            if (count($memberships) > 0) {
                $points += 20;
                $score['matched'][] = "Professional membership";
            } else {
                $score['missed'][] = "Professional membership";
            }
        }

        if (!empty($criteria['requireGoodStanding'])) {
            $maxPoints += 15;
            $hasGoodStanding = false;
            foreach ($memberships as $mem) {
                if (!empty($mem['isActive']) && !empty($mem['goodStanding'])) {
                    $hasGoodStanding = true;
                    break;
                }
            }
            if ($hasGoodStanding) {
                $points += 15;
                $score['matched'][] = "Good standing";
            } else {
                $score['missed'][] = "Good standing";
            }
        }

        if (!empty($criteria['requiredCertifications']) && is_array($criteria['requiredCertifications']) && count($criteria['requiredCertifications']) > 0) {
            $maxPoints += 25;
            $certNames = array_map('strtolower', array_column($certifications, 'name'));
            $matchedCount = 0;

            foreach ($criteria['requiredCertifications'] as $reqCert) {
                $found = false;
                foreach ($certNames as $certName) {
                    if (stripos($certName, strtolower($reqCert)) !== false) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    $matchedCount++;
                    $score['matched'][] = "Certification: {$reqCert}";
                } else {
                    $score['missed'][] = "Certification: {$reqCert}";
                }
            }

            $points += ($matchedCount / count($criteria['requiredCertifications'])) * 25;
        }

        if (!empty($criteria['requireLeadershipCourse'])) {
            $maxPoints += 15;
            $minWeeks = (int) ($criteria['minLeadershipCourseDuration'] ?? 4);
            $hasLeadershipCourse = false;

            foreach ($courses as $course) {
                $duration = (int) ($course['durationWeeks'] ?? 0);
                if ($duration >= $minWeeks) {
                    $hasLeadershipCourse = true;
                    break;
                }
            }

            if ($hasLeadershipCourse) {
                $points += 15;
                $score['matched'][] = "Leadership course (min {$minWeeks} weeks)";
            } else {
                $score['missed'][] = "Leadership course (min {$minWeeks} weeks)";
            }
        }

        if (!empty($criteria['minPublications'])) {
            $maxPoints += 15;
            $pubCount = count($publications);
            if ($pubCount >= $criteria['minPublications']) {
                $points += 15;
                $score['matched'][] = "Publications: {$pubCount} (min: {$criteria['minPublications']})";
            } else {
                $score['missed'][] = "Publications: {$pubCount} (min: {$criteria['minPublications']})";
            }
        }

        if (!empty($criteria['requireReferees']) || !empty($criteria['minRefereeCount'])) {
            $minReferees = max((int) ($criteria['minRefereeCount'] ?? 0), !empty($criteria['requireReferees']) ? 1 : 0);
            if ($minReferees > 0) {
                $maxPoints += 10;
                $refCount = count($referees);
                if ($refCount >= $minReferees) {
                    $points += 10;
                    $score['matched'][] = "Referees: {$refCount} (min: {$minReferees})";
                } else {
                    $score['missed'][] = "Referees: {$refCount} (min: {$minReferees})";
                }
            }
        }

        if (!empty($criteria['requirePortfolio'])) {
            $portfolioUrl = $profile['portfolioUrl'] ?? '';
            if (!empty($portfolioUrl))
                $score['matched'][] = "Portfolio URL provided";
            else
                $score['missed'][] = "Portfolio URL";
        }

        if (!empty($criteria['requireGitHubProfile'])) {
            $githubUrl = $profile['githubUrl'] ?? '';
            if (!empty($githubUrl))
                $score['matched'][] = "GitHub profile provided";
            else
                $score['missed'][] = "GitHub profile";
        }

        if (!empty($criteria['requireLinkedInProfile'])) {
            $linkedinUrl = $profile['linkedinUrl'] ?? '';
            if (!empty($linkedinUrl))
                $score['matched'][] = "LinkedIn profile provided";
            else
                $score['missed'][] = "LinkedIn profile";
        }

        if ($maxPoints > 0)
            $score['professionalScore'] = round(($points / $maxPoints) * $weight, 2);
        else
            $score['professionalScore'] = $weight * 0.5;
    }

    // =========================================================
    // RANKING —: USE EFFECTIVE SCORE + TIES + OVERRIDE DISQ
    // =========================================================
    protected function calculateRanks(string $jobId): void
    {
        $rows = $this->db->table('shortlist_results')
            ->where('jobId', $jobId)
            ->get()
            ->getResultArray();

        if (empty($rows))
            return;

        // EFFICIENT FIX: If candidate has manual scores (effective override),
        // they should be rankable regardless of original disqualification
        $qualified = [];
        $disqualified = [];

        foreach ($rows as $r) {
            $isDisq = $this->isEffectivelyDisqualified($r);
            $hasManualScores = $this->hasManualScores($r);

            // If disqualified BUT has manual scores, treat as qualified
            // (admin is explicitly overriding by setting manual scores)
            if ($isDisq && !$hasManualScores) {
                $disqualified[] = $r;
            } else {
                $qualified[] = $r;
            }
        }

        // sort by effective total DESC
        usort($qualified, function ($a, $b) {
            $sa = $this->getEffectiveTotalScore($a);
            $sb = $this->getEffectiveTotalScore($b);
            if ($sa !== $sb)
                return ($sb <=> $sa);

            // stable tie-breakers
            $ua = strtotime($a['updatedAt'] ?? '') ?: 0;
            $ub = strtotime($b['updatedAt'] ?? '') ?: 0;
            if ($ua !== $ub)
                return ($ub <=> $ua);

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        $totalCount = count($qualified);
        $currentRank = 1;

        $i = 0;
        while ($i < count($qualified)) {
            $scoreVal = $this->getEffectiveTotalScore($qualified[$i]);

            $group = [$qualified[$i]];
            $j = $i + 1;
            while ($j < count($qualified) && $this->getEffectiveTotalScore($qualified[$j]) == $scoreVal) {
                $group[] = $qualified[$j];
                $j++;
            }

            $percentile = $totalCount > 1 ? round((($totalCount - $currentRank + 1) / $totalCount) * 100, 2) : 100;

            foreach ($group as $row) {
                $this->resultModel->update($row['id'], [
                    'candidateRank' => $currentRank,
                    'percentile' => $percentile,
                    'updatedAt' => date('Y-m-d H:i:s'),
                ]);
            }

            $currentRank += count($group);
            $i = $j;
        }

        // Only truly disqualified candidates (no manual override) get ranked as X
        foreach ($disqualified as $row) {
            $this->resultModel->update($row['id'], [
                'candidateRank' => null,
                'percentile' => 0,
                'updatedAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // =========================================================
    // RESULTS FETCHING — FIXED: EFFECTIVE MIN SCORE + TOPN AFTER SORT
    // =========================================================
    public function getShortlistResults(string $jobId, array $filters = []): array
    {
        $builder = $this->db->table('shortlist_results');
        $builder->select('shortlist_results.*,
            users.firstName, users.lastName, users.email, users.phone,
            candidate_profiles.title, candidate_profiles.experienceYears, candidate_profiles.resumeUrl,
            applications.status as applicationStatus, applications.expectedSalary
        ')
            ->join('users', 'users.id = shortlist_results.candidateId', 'left')
            ->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left')
            ->join('applications', 'applications.id = shortlist_results.applicationId', 'left')
            ->where('shortlist_results.jobId', $jobId);

        if (!empty($filters['status']))
            $builder->where('applications.status', $filters['status']);
        if (!empty($filters['hasAllMandatory']))
            $builder->where('shortlist_results.hasAllMandatory', true);
        if (!empty($filters['flaggedForReview']))
            $builder->where('shortlist_results.flaggedForReview', true);

        // include disqualified (default true should be controlled from controller)
        if (!isset($filters['includeDisqualified']) || !$filters['includeDisqualified']) {
            $builder->where('shortlist_results.hasDisqualifyingFactor', false);
        }

        // ranked first, disqualified last
        $builder->orderBy('shortlist_results.candidateRank IS NULL', 'ASC', false);
        $builder->orderBy('shortlist_results.candidateRank', 'ASC');

        $results = $builder->get()->getResultArray();

        foreach ($results as &$result) {
            foreach (self::RESULT_JSON_FIELDS as $f) {
                if (isset($result[$f]) && is_string($result[$f])) {
                    $decoded = json_decode($result[$f], true);
                    $result[$f] = is_array($decoded) ? $decoded : [];
                }
            }

            // computed effective fields
            $result['effectiveTotalScore'] = $this->getEffectiveTotalScore($result);
            $result['effectiveEducationScore'] = $this->getEffectiveCategoryScore($result, 'education');
            $result['effectiveExperienceScore'] = $this->getEffectiveCategoryScore($result, 'experience');
            $result['effectiveSkillsScore'] = $this->getEffectiveCategoryScore($result, 'skills');
            $result['effectiveClearanceScore'] = $this->getEffectiveCategoryScore($result, 'clearance');
            $result['effectiveProfessionalScore'] = $this->getEffectiveCategoryScore($result, 'professional');
            $result['isEffectivelyDisqualified'] = $this->isEffectivelyDisqualified($result);
        }

        // minScore must apply to effectiveTotalScore
        if (!empty($filters['minScore'])) {
            $min = (float) $filters['minScore'];
            $results = array_values(array_filter($results, fn($r) => (float) ($r['effectiveTotalScore'] ?? 0) >= $min));
        }

        // topN applies after sorting and filtering
        if (!empty($filters['topN']) && (int) $filters['topN'] > 0) {
            $results = array_slice($results, 0, (int) $filters['topN']);
        }

        return $results;
    }

    // =========================================================
    // EXPORT BUNDLE
    // =========================================================
    public function buildShortlistExportBundle(string $jobId, array $filters = [], ?int $topN = null): array
    {
        $results = $this->getShortlistResults($jobId, $filters);

        $resultsToExport = $results;
        if ($topN && $topN > 0 && $topN < count($resultsToExport)) {
            $resultsToExport = array_slice($resultsToExport, 0, $topN);
        }

        $userIds = array_values(array_unique(array_filter(array_column($resultsToExport, 'candidateId'))));
        if (empty($userIds)) {
            return ['resultsToExport' => [], 'profilesMap' => []];
        }

        $profileIdMap = $this->getCandidateProfileIdMap($userIds);
        $profileIds = array_values(array_unique(array_filter(array_values($profileIdMap))));
        if (empty($profileIds)) {
            return ['resultsToExport' => $resultsToExport, 'profilesMap' => []];
        }

        $personalInfoMap = $this->getPersonalInfoMap($profileIds);
        $educationMap = $this->getEducationMap($profileIds);
        $experienceMap = $this->getExperienceMap($profileIds);
        $clearanceMap = $this->getClearanceMap($profileIds);
        $membershipMap = $this->getMembershipMap($profileIds);
        $courseMap = $this->getCourseMap($profileIds);
        $publicationMap = $this->getPublicationMap($profileIds);
        $certificationMap = $this->getCertificationMap($profileIds);
        $refereeMap = $this->getRefereeMap($profileIds);

        $profilesMap = [];

        foreach ($resultsToExport as $r) {
            $userId = $r['candidateId'] ?? null;
            if (!$userId)
                continue;

            $pid = $profileIdMap[$userId] ?? null;
            $pInfo = $pid ? ($personalInfoMap[$pid] ?? []) : [];

            $age = '';
            if (!empty($pInfo['dob'])) {
                try {
                    $dob = new \DateTime($pInfo['dob']);
                    $age = (new \DateTime())->diff($dob)->y;
                } catch (\Exception $e) {
                    $age = '';
                }
            }

            $eduTop = $pid ? ($educationMap[$pid]['Doctorate'] ?? '') : '';
            if (!$eduTop)
                $eduTop = $pid ? ($educationMap[$pid]['Masters'] ?? '') : '';
            if (!$eduTop)
                $eduTop = $pid ? ($educationMap[$pid]['Bachelors'] ?? '') : '';

            $expSummary = $pid ? ($experienceMap[$pid]['all'] ?? '') : '';
            $currentEmployer = $pid ? ($experienceMap[$pid]['current'] ?? '') : '';

            $clearancesSummary = $pid ? $this->formatClearancesSummary($clearanceMap[$pid] ?? []) : '';

            $profilesMap[$userId] = [
                'countyOfOrigin' => $pInfo['countyOfOrigin'] ?? '',
                'nationality' => $pInfo['nationality'] ?? '',
                'dob' => $this->formatDate($pInfo['dob'] ?? ''),
                'age' => $age,
                'gender' => $pInfo['gender'] ?? '',
                'plwd' => !empty($pInfo['plwd']) ? 'Y' : 'N',
                'educationTop' => $eduTop,
                'experienceSummary' => $expSummary,
                'currentEmployer' => $currentEmployer,
                'clearancesSummary' => $clearancesSummary,
                'memberships' => $pid ? ($membershipMap[$pid]['names'] ?? '') : '',
                'courses' => $pid ? ($courseMap[$pid] ?? '') : '',
                'publications' => $pid ? ($publicationMap[$pid] ?? '') : '',
                'certifications' => $pid ? ($certificationMap[$pid] ?? '') : '',
                'referees' => $pid ? ($refereeMap[$pid] ?? '') : '',
                'resumeUrl' => $r['resumeUrl'] ?? '',
            ];
        }

        return [
            'resultsToExport' => $resultsToExport,
            'profilesMap' => $profilesMap,
        ];
    }

    // =========================================================
    // EXPORT MAPS
    // =========================================================

    private function getCandidateProfileIdMap(array $userIds): array
    {
        if (empty($userIds))
            return [];

        $rows = $this->db->table('candidate_profiles')
            ->select('id, userId')
            ->whereIn('userId', $userIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r)
            $map[$r['userId']] = $r['id'];
        return $map;
    }

    private function getPersonalInfoMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('candidate_personal_info')
            ->select('candidateId, dob, gender, idNumber, nationality, countyOfOrigin, plwd')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['candidateId']] = [
                'dob' => $r['dob'] ?? '',
                'gender' => $r['gender'] ?? '',
                'idNumber' => $r['idNumber'] ?? '',
                'nationality' => $r['nationality'] ?? '',
                'countyOfOrigin' => $r['countyOfOrigin'] ?? '',
                'plwd' => (bool) ($r['plwd'] ?? false),
            ];
        }
        return $map;
    }

    private function getClearanceMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('candidate_clearances')
            ->select('candidateId, type, status, issueDate, expiryDate')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $type = $r['type'];

            if (!isset($map[$cid]))
                $map[$cid] = [];
            if (!isset($map[$cid][$type]))
                $map[$cid][$type] = ['status' => 'N', 'validity' => ''];

            if (strtoupper($r['status'] ?? '') === 'VALID') {
                $map[$cid][$type]['status'] = 'Y';
                $validity = '';
                if (!empty($r['expiryDate']))
                    $validity = $this->formatDate($r['expiryDate']);
                elseif (!empty($r['issueDate']))
                    $validity = $this->formatDate($r['issueDate']);
                $map[$cid][$type]['validity'] = $validity;
            }
        }
        return $map;
    }

    private function getEducationMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('educations')
            ->select('candidateId, degree, fieldOfStudy, institution, endDate, isCurrent')
            ->whereIn('candidateId', $profileIds)
            ->orderBy('endDate', 'DESC')
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $degree = $r['degree'] ?? '';
            $field = $r['fieldOfStudy'] ?? '';
            $institution = $r['institution'] ?? '';
            $year = $this->extractYear($r['endDate'] ?? '', $r['isCurrent'] ?? false);

            $text = trim("$degree $field $institution $year");
            if (!$text)
                continue;

            if (!isset($map[$cid]))
                $map[$cid] = [];

            $degreeUpper = strtoupper($degree);
            if (strpos($degreeUpper, 'PHD') !== false || strpos($degreeUpper, 'DOCTOR') !== false) {
                if (empty($map[$cid]['Doctorate']))
                    $map[$cid]['Doctorate'] = $text;
            } elseif (strpos($degreeUpper, 'MASTER') !== false || strpos($degreeUpper, 'MSC') !== false) {
                if (empty($map[$cid]['Masters']))
                    $map[$cid]['Masters'] = $text;
            } elseif (strpos($degreeUpper, 'BACHELOR') !== false || strpos($degreeUpper, 'BSC') !== false || strpos($degreeUpper, 'BA') !== false) {
                if (empty($map[$cid]['Bachelors']))
                    $map[$cid]['Bachelors'] = $text;
            }
        }

        return $map;
    }

    private function getExperienceMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('experiences')
            ->select('candidateId, title, company, startDate, endDate, isCurrent')
            ->whereIn('candidateId', $profileIds)
            ->orderBy('isCurrent', 'DESC')
            ->orderBy('startDate', 'DESC')
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $title = $r['title'] ?? '';
            $company = $r['company'] ?? '';
            $duration = $this->formatDuration($r['startDate'] ?? '', $r['endDate'] ?? '', $r['isCurrent'] ?? false);
            $text = trim("$title - $company ($duration)");

            if (!isset($map[$cid]))
                $map[$cid] = ['all' => '', 'senior' => '', 'current' => ''];

            if ($map[$cid]['all'])
                $map[$cid]['all'] .= '; ';
            $map[$cid]['all'] .= $text;

            $titleLower = strtolower($title);
            $seniorKeywords = ['director', 'manager', 'head', 'chief', 'executive', 'vp', 'president', 'ceo', 'cfo', 'cto', 'coo'];
            foreach ($seniorKeywords as $kw) {
                if (strpos($titleLower, $kw) !== false) {
                    if ($map[$cid]['senior'])
                        $map[$cid]['senior'] .= '; ';
                    $map[$cid]['senior'] .= $text;
                    break;
                }
            }

            if (!empty($r['isCurrent'])) {
                if ($map[$cid]['current'])
                    $map[$cid]['current'] .= '; ';
                $map[$cid]['current'] .= $text;
            }
        }

        return $map;
    }

    private function getMembershipMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('candidate_professional_memberships')
            ->select('candidateId, bodyName, isActive, goodStanding')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $bodyName = $r['bodyName'] ?? '';
            $isActive = !empty($r['isActive']);
            $goodStanding = !empty($r['goodStanding']);

            if (!isset($map[$cid]))
                $map[$cid] = ['names' => '', 'standing' => ''];

            if ($bodyName) {
                if ($map[$cid]['names'])
                    $map[$cid]['names'] .= ', ';
                $map[$cid]['names'] .= $bodyName;

                if ($isActive && $goodStanding) {
                    if ($map[$cid]['standing'])
                        $map[$cid]['standing'] .= ', ';
                    $map[$cid]['standing'] .= $bodyName . ' (Good Standing)';
                }
            }
        }
        return $map;
    }

    private function getCourseMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('candidate_courses')
            ->select('candidateId, name, institution, durationWeeks')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $name = $r['name'] ?? '';
            $institution = $r['institution'] ?? '';
            $duration = $r['durationWeeks'] ?? '';

            $text = trim("$name - $institution" . ($duration ? " ($duration weeks)" : ''));
            if (!isset($map[$cid]))
                $map[$cid] = '';
            if ($map[$cid])
                $map[$cid] .= ', ';
            $map[$cid] .= $text;
        }

        return $map;
    }

    private function getPublicationMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('candidate_publications')
            ->select('candidateId, title, year')
            ->whereIn('candidateId', $profileIds)
            ->orderBy('year', 'DESC')
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $title = $r['title'] ?? '';
            $year = $r['year'] ?? '';

            $text = trim("$title ($year)");
            if (!isset($map[$cid]))
                $map[$cid] = '';
            if ($map[$cid])
                $map[$cid] .= '; ';
            $map[$cid] .= $text;
        }

        return $map;
    }

    private function getCertificationMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('certifications')
            ->select('candidateId, name, issuingOrg')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $name = $r['name'] ?? '';
            $org = $r['issuingOrg'] ?? '';

            $text = trim("$name - $org");
            if (!isset($map[$cid]))
                $map[$cid] = '';
            if ($map[$cid])
                $map[$cid] .= ', ';
            $map[$cid] .= $text;
        }

        return $map;
    }

    private function getRefereeMap(array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $this->db->table('candidate_referees')
            ->select('candidateId, name, position, organization, phone, email')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $counts = [];
        $map = [];

        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $counts[$cid] = $counts[$cid] ?? 0;
            if ($counts[$cid] >= 3)
                continue;

            $name = $r['name'] ?? '';
            $pos = $r['position'] ?? '';
            $org = $r['organization'] ?? '';
            $phone = $r['phone'] ?? '';
            $email = $r['email'] ?? '';

            $text = trim("$name, $pos, $org, Tel: $phone, Email: $email");

            if (!isset($map[$cid]))
                $map[$cid] = '';
            if ($map[$cid])
                $map[$cid] .= '; ';
            $map[$cid] .= $text;

            $counts[$cid]++;
        }

        return $map;
    }

    private function formatClearancesSummary(array $clearanceMapForCandidate): string
    {
        if (empty($clearanceMapForCandidate))
            return '';

        $parts = [];
        foreach ($clearanceMapForCandidate as $type => $info) {
            $status = $info['status'] ?? 'N';
            if ($status !== 'Y')
                continue;

            $validity = $info['validity'] ?? '';
            $parts[] = $type . ($validity ? " (Valid until: {$validity})" : '');
        }
        return implode('; ', $parts);
    }

    // =========================================================
    // HELPERS
    // =========================================================
    protected function calculateSeniorExperience(array $experiences): int
    {
        $seniorYears = 0;
        $seniorKeywords = ['director', 'manager', 'head', 'chief', 'executive', 'vp', 'vice president', 'president', 'ceo', 'cfo', 'cto', 'coo', 'lead'];

        foreach ($experiences as $exp) {
            $title = strtolower($exp['title'] ?? '');
            $isSenior = false;

            foreach ($seniorKeywords as $keyword) {
                if (strpos($title, $keyword) !== false) {
                    $isSenior = true;
                    break;
                }
            }

            if ($isSenior && !empty($exp['startDate'])) {
                try {
                    $startDate = new \DateTime($exp['startDate']);
                    if (!empty($exp['isCurrent']))
                        $endDate = new \DateTime();
                    elseif (!empty($exp['endDate']))
                        $endDate = new \DateTime($exp['endDate']);
                    else
                        continue;

                    $interval = $startDate->diff($endDate);
                    $years = $interval->y;
                    $months = $interval->m;
                    $seniorYears += $years + floor($months / 12);
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return (int) $seniorYears;
    }

    protected function hasManagementExperience(array $experiences): bool
    {
        $managementKeywords = ['manager', 'management', 'director', 'head', 'lead', 'supervisor', 'chief', 'team lead'];

        foreach ($experiences as $exp) {
            $title = strtolower($exp['title'] ?? '');
            $description = strtolower($exp['description'] ?? '');

            foreach ($managementKeywords as $keyword) {
                if (strpos($title, $keyword) !== false || strpos($description, $keyword) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function detectCompanyTypes(array $experiences): array
    {
        $types = ['mnc' => false, 'startup' => false, 'ngo' => false, 'government' => false];

        $mncKeywords = ['multinational', 'google', 'microsoft', 'amazon', 'facebook', 'meta', 'apple', 'ibm', 'oracle', 'sap', 'salesforce', 'accenture', 'deloitte', 'pwc', 'kpmg', 'ey'];
        $startupKeywords = ['startup', 'start-up', 'tech startup', 'co-founder', 'founder'];
        $ngoKeywords = ['ngo', 'non-profit', 'nonprofit', 'foundation', 'charity', 'unicef', 'red cross', 'humanitarian'];
        $govKeywords = ['government', 'ministry', 'county', 'national', 'public service', 'state', 'federal', 'municipal'];

        foreach ($experiences as $exp) {
            $company = strtolower($exp['company'] ?? '');
            $description = strtolower($exp['description'] ?? '');
            $combined = $company . ' ' . $description;

            foreach ($mncKeywords as $keyword)
                if (strpos($combined, $keyword) !== false)
                    $types['mnc'] = true;
            foreach ($startupKeywords as $keyword)
                if (strpos($combined, $keyword) !== false)
                    $types['startup'] = true;
            foreach ($ngoKeywords as $keyword)
                if (strpos($combined, $keyword) !== false)
                    $types['ngo'] = true;
            foreach ($govKeywords as $keyword)
                if (strpos($combined, $keyword) !== false)
                    $types['government'] = true;
        }

        return $types;
    }

    protected function getSkillName(string $skillId): string
    {
        $skill = $this->db->table('skills')->where('id', $skillId)->get()->getRowArray();
        return $skill['name'] ?? "Skill #{$skillId}";
    }

    private function formatDate($dateString): string
    {
        if (empty($dateString) || $dateString === '0000-00-00 00:00:00.000' || $dateString === '0000-00-00')
            return '';
        try {
            return date('d/m/Y', strtotime($dateString));
        } catch (\Exception $e) {
            return '';
        }
    }

    private function extractYear($dateString, $isCurrent): string
    {
        if ($isCurrent)
            return date('Y');
        if (empty($dateString))
            return '';
        try {
            return date('Y', strtotime($dateString));
        } catch (\Exception $e) {
            return '';
        }
    }

    private function formatDuration($startDate, $endDate, $isCurrent): string
    {
        $start = $this->extractYear($startDate, false);
        $end = $isCurrent ? 'Present' : $this->extractYear($endDate, false);
        if (!$start)
            return $end;
        if (!$end)
            return $start;
        return "$start - $end";
    }
}