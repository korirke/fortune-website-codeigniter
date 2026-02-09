<?php

namespace App\Services;

use Config\Database;
use App\Models\JobShortlistCriteria;
use App\Models\ShortlistResult;
use App\Models\Application;

class ShortlistService
{
    protected $db;
    protected $criteriaModel;
    protected $resultModel;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->criteriaModel = new JobShortlistCriteria();
        $this->resultModel = new ShortlistResult();
    }

    /**
     * Get or create default criteria for a job
     */
    public function getJobCriteria(string $jobId): array
    {
        $criteria = $this->criteriaModel->where('jobId', $jobId)->first();
        
        if (!$criteria) {
            $criteria = [
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
                'requireDCIClearance' => false,
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
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s')
            ];
            $this->criteriaModel->insert($criteria);
        }

        return $criteria;
    }

    /**
     * Update job shortlist criteria
     */
    public function updateJobCriteria(string $jobId, array $data): bool
    {
        $existing = $this->criteriaModel->where('jobId', $jobId)->first();

        if ($existing) {
            return $this->criteriaModel->update($existing['id'], $data);
        } else {
            $data['id'] = uniqid('criteria_');
            $data['jobId'] = $jobId;
            return $this->criteriaModel->insert($data);
        }
    }

    /**
     * Generate shortlist for a job
     */
    public function generateShortlist(string $jobId): array
    {
        $criteria = $this->getJobCriteria($jobId);

        $applicationModel = new Application();
        $applications = $applicationModel->where('jobId', $jobId)->findAll();

        if (empty($applications)) {
            return ['success' => false, 'message' => 'No applications found', 'count' => 0];
        }

        $results = [];

        foreach ($applications as $app) {
            $candidateId = $app['candidateId'];
            
            $profile = $this->db->table('candidate_profiles')
                ->where('userId', $candidateId)
                ->get()->getRowArray();

            if (!$profile) continue;

            $profileId = $profile['id'];

            // Score candidate
            $score = $this->scoreCandidate($profileId, $candidateId, $app, $criteria);

            // Save result
            $resultData = [
                'jobId' => $jobId,
                'applicationId' => $app['id'],
                'candidateId' => $candidateId,
                'totalScore' => $score['totalScore'],
                'educationScore' => $score['educationScore'],
                'experienceScore' => $score['experienceScore'],
                'skillsScore' => $score['skillsScore'],
                'clearanceScore' => $score['clearanceScore'],
                'professionalScore' => $score['professionalScore'],
                'matchedCriteria' => $score['matched'],
                'missedCriteria' => $score['missed'],
                'bonusCriteria' => $score['bonus'],
                'hasAllMandatory' => $score['hasAllMandatory'],
                'hasDisqualifyingFactor' => $score['hasDisqualifyingFactor'],
                'disqualificationReasons' => $score['disqualificationReasons']
            ];

            $existing = $this->resultModel
                ->where('jobId', $jobId)
                ->where('applicationId', $app['id'])
                ->first();

            if ($existing) {
                $this->resultModel->update($existing['id'], $resultData);
            } else {
                $resultData['id'] = uniqid('shortlist_');
                $this->resultModel->insert($resultData);
            }

            $results[] = $resultData;
        }

        // Calculate ranks and percentiles
        $this->calculateRanks($jobId);

        return [
            'success' => true,
            'message' => 'Shortlist generated successfully',
            'count' => count($results),
            'results' => $results
        ];
    }

    /**
     * MAIN SCORING ENGINE
     */
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
            'disqualificationReasons' => []
        ];

        // Get all candidate data
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

        // ========================================
        // 1. PERSONAL INFO FILTERS (DISQUALIFYING)
        // ========================================
        $this->evaluatePersonalInfo($personalInfo, $application, $criteria, $score);

        // ========================================
        // 2. EDUCATION SCORING
        // ========================================
        $this->evaluateEducation($educations, $criteria, $score, $criteria['educationWeight']);

        // ========================================
        // 3. EXPERIENCE SCORING
        // ========================================
        $this->evaluateExperience($experiences, $profile, $criteria, $score, $criteria['experienceWeight']);

        // ========================================
        // 4. SKILLS SCORING
        // ========================================
        $this->evaluateSkills($candidateSkills, $criteria, $score, $criteria['skillsWeight']);

        // ========================================
        // 5. CLEARANCE SCORING
        // ========================================
        $this->evaluateClearances($clearances, $criteria, $score, $criteria['clearanceWeight']);

        // ========================================
        // 6. PROFESSIONAL SCORING
        // ========================================
        $this->evaluateProfessional($memberships, $certifications, $courses, $publications, $referees, $profile, $criteria, $score, $criteria['professionalWeight']);

        // Calculate total score
        $score['totalScore'] = round(
            $score['educationScore'] + 
            $score['experienceScore'] + 
            $score['skillsScore'] + 
            $score['clearanceScore'] + 
            $score['professionalScore'],
            2
        );

        return $score;
    }

    /**
     * Personal Info Evaluation (Age, Gender, Nationality, Location, PLWD)
     */
    protected function evaluatePersonalInfo(?array $personalInfo, array $application, array $criteria, array &$score): void
    {
        // Age Check
        if (!empty($criteria['minAge']) || !empty($criteria['maxAge'])) {
            $age = null;
            if (!empty($personalInfo['dob'])) {
                try {
                    $dob = new \DateTime($personalInfo['dob']);
                    $age = (new \DateTime())->diff($dob)->y;
                } catch (\Exception $e) {}
            }

            if ($age !== null) {
                $ageOk = true;
                if (!empty($criteria['minAge']) && $age < $criteria['minAge']) {
                    $ageOk = false;
                }
                if (!empty($criteria['maxAge']) && $age > $criteria['maxAge']) {
                    $ageOk = false;
                }

                if ($ageOk) {
                    $score['matched'][] = "Age: {$age} years";
                } else {
                    $score['hasDisqualifyingFactor'] = true;
                    $score['disqualificationReasons'][] = "Age {$age} outside range ({$criteria['minAge']}-{$criteria['maxAge']})";
                }
            }
        }

        // Gender Check
        if (!empty($criteria['requiredGender']) && $criteria['requiredGender'] !== 'ANY') {
            $gender = strtoupper($personalInfo['gender'] ?? '');
            if ($gender === strtoupper($criteria['requiredGender'])) {
                $score['matched'][] = "Gender: {$criteria['requiredGender']}";
            } else {
                $score['hasDisqualifyingFactor'] = true;
                $score['disqualificationReasons'][] = "Gender mismatch (required: {$criteria['requiredGender']})";
            }
        }

        // Nationality Check
        if (!empty($criteria['requiredNationality']) && $criteria['requiredNationality'] !== 'ANY') {
            $nationality = $personalInfo['nationality'] ?? '';
            if (stripos($nationality, $criteria['requiredNationality']) !== false) {
                $score['matched'][] = "Nationality: {$criteria['requiredNationality']}";
            } else {
                $score['hasDisqualifyingFactor'] = true;
                $score['disqualificationReasons'][] = "Nationality mismatch (required: {$criteria['requiredNationality']})";
            }
        }

        // County Check
        if (!empty($criteria['specificCounties']) && is_array($criteria['specificCounties']) && count($criteria['specificCounties']) > 0) {
            $county = $personalInfo['countyOfOrigin'] ?? '';
            $countyMatch = false;
            foreach ($criteria['specificCounties'] as $reqCounty) {
                if (stripos($county, $reqCounty) !== false) {
                    $countyMatch = true;
                    break;
                }
            }

            if ($countyMatch) {
                $score['matched'][] = "County: {$county}";
            } else {
                $score['missed'][] = "County not in: " . implode(', ', $criteria['specificCounties']);
            }
        }

        // PLWD Check
        $isPLWD = !empty($personalInfo['plwd']);
        if ($criteria['requirePLWD'] && !$isPLWD) {
            $score['hasDisqualifyingFactor'] = true;
            $score['disqualificationReasons'][] = "Person with disability required";
        } elseif (!$criteria['acceptPLWD'] && $isPLWD) {
            $score['hasDisqualifyingFactor'] = true;
            $score['disqualificationReasons'][] = "PLWD not accepted for this role";
        } elseif ($isPLWD) {
            $score['bonus'][] = "Person with disability";
        }

        // Salary Check
        if (!empty($criteria['maxExpectedSalary'])) {
            $expectedSalary = (float)($application['expectedSalary'] ?? 0);
            if ($expectedSalary > 0) {
                if ($expectedSalary <= $criteria['maxExpectedSalary']) {
                    $score['matched'][] = "Expected salary within budget";
                } else {
                    $score['missed'][] = "Expected salary exceeds budget";
                }
            }
        }

        if (!empty($criteria['minExpectedSalary'])) {
            $expectedSalary = (float)($application['expectedSalary'] ?? 0);
            if ($expectedSalary > 0 && $expectedSalary >= $criteria['minExpectedSalary']) {
                $score['matched'][] = "Expected salary meets minimum";
            }
        }
    }

    /**
     * Education Evaluation
     */
    protected function evaluateEducation(array $educations, array $criteria, array &$score, int $weight): void
    {
        if ($weight == 0) return;

        $points = 0;
        $maxPoints = 0;

        $hasDoctorate = false;
        $hasMasters = false;
        $hasBachelors = false;

        foreach ($educations as $edu) {
            $degree = strtoupper($edu['degree'] ?? '');
            if (strpos($degree, 'PHD') !== false || strpos($degree, 'DOCTOR') !== false) {
                $hasDoctorate = true;
            } elseif (strpos($degree, 'MASTER') !== false || strpos($degree, 'MSC') !== false || strpos($degree, 'MBA') !== false) {
                $hasMasters = true;
            } elseif (strpos($degree, 'BACHELOR') !== false || strpos($degree, 'BSC') !== false || strpos($degree, 'BA') !== false) {
                $hasBachelors = true;
            }
        }

        if ($criteria['requireDoctorate']) {
            $maxPoints += 40;
            if ($hasDoctorate) {
                $points += 40;
                $score['matched'][] = "Doctorate degree";
            } else {
                $score['missed'][] = "Doctorate degree (REQUIRED)";
                $score['hasAllMandatory'] = false;
            }
        } elseif ($hasDoctorate) {
            $score['bonus'][] = "Doctorate degree (exceeds requirements)";
            $points += 10; // Bonus
        }

        if ($criteria['requireMasters']) {
            $maxPoints += 30;
            if ($hasMasters) {
                $points += 30;
                $score['matched'][] = "Masters degree";
            } else {
                $score['missed'][] = "Masters degree (REQUIRED)";
                $score['hasAllMandatory'] = false;
            }
        } elseif ($hasMasters && !$hasDoctorate) {
            $score['bonus'][] = "Masters degree (exceeds requirements)";
            $points += 8;
        }

        if ($criteria['requireBachelors']) {
            $maxPoints += 30;
            if ($hasBachelors) {
                $points += 30;
                $score['matched'][] = "Bachelors degree";
            } else {
                $score['missed'][] = "Bachelors degree (REQUIRED)";
                $score['hasAllMandatory'] = false;
            }
        }

        // Specific fields check
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
            if (!$fieldMatch) {
                $score['missed'][] = "Degree field not in: " . implode(', ', $criteria['specificDegreeFields']);
            }
        }

        // Institutions check
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
            if (!$instMatch) {
                $score['missed'][] = "Institution not in: " . implode(', ', $criteria['specificInstitutions']);
            }
        }

        // Normalize to weight
        if ($maxPoints > 0) {
            $score['educationScore'] = round(($points / $maxPoints) * $weight, 2);
        } else {
            $score['educationScore'] = $hasBachelors ? $weight * 0.5 : 0;
        }
    }

    /**
     * Experience Evaluation
     */
    protected function evaluateExperience(array $experiences, array $profile, array $criteria, array &$score, int $weight): void
    {
        if ($weight == 0) return;

        $points = 0;
        $maxPoints = 0;

        $generalExp = (int)($profile['experienceYears'] ?? 0);

        // General Experience
        if (!empty($criteria['minGeneralExperience'])) {
            $maxPoints += 30;
            if ($generalExp >= $criteria['minGeneralExperience']) {
                $points += 30;
                $score['matched'][] = "General experience: {$generalExp} years (min: {$criteria['minGeneralExperience']})";
            } else {
                $score['missed'][] = "General experience: {$generalExp} years (min: {$criteria['minGeneralExperience']}) - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        if (!empty($criteria['maxGeneralExperience'])) {
            if ($generalExp > $criteria['maxGeneralExperience']) {
                $score['missed'][] = "Experience exceeds maximum ({$criteria['maxGeneralExperience']} years)";
            }
        }

        // Senior Experience
        $seniorYears = $this->calculateSeniorExperience($experiences);
        if (!empty($criteria['minSeniorExperience'])) {
            $maxPoints += 30;
            if ($seniorYears >= $criteria['minSeniorExperience']) {
                $points += 30;
                $score['matched'][] = "Senior experience: {$seniorYears} years (min: {$criteria['minSeniorExperience']})";
            } else {
                $score['missed'][] = "Senior experience: {$seniorYears} years (min: {$criteria['minSeniorExperience']}) - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        // Management Experience
        if ($criteria['requireManagementExperience']) {
            $hasManagement = $this->hasManagementExperience($experiences);
            $maxPoints += 20;
            if ($hasManagement) {
                $points += 20;
                $score['matched'][] = "Management experience";
            } else {
                $score['missed'][] = "Management experience - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        // Current Employment Status
        $currentlyEmployed = false;
        foreach ($experiences as $exp) {
            if (!empty($exp['isCurrent'])) {
                $currentlyEmployed = true;
                break;
            }
        }

        if ($criteria['requireCurrentlyEmployed'] && !$currentlyEmployed) {
            $score['missed'][] = "Currently employed - REQUIRED";
            $score['hasAllMandatory'] = false;
        }

        if ($criteria['excludeCurrentlyEmployed'] && $currentlyEmployed) {
            $score['hasDisqualifyingFactor'] = true;
            $score['disqualificationReasons'][] = "Currently employed candidates excluded";
        }

        // Company Types (MNC, Startup, NGO, Government)
        $companyTypes = $this->detectCompanyTypes($experiences);
        if ($criteria['requireMNCExperience'] && !$companyTypes['mnc']) {
            $score['missed'][] = "MNC experience";
        } elseif ($companyTypes['mnc']) {
            $score['matched'][] = "MNC experience";
        }

        if ($criteria['requireStartupExperience'] && !$companyTypes['startup']) {
            $score['missed'][] = "Startup experience";
        } elseif ($companyTypes['startup']) {
            $score['matched'][] = "Startup experience";
        }

        // Normalize
        if ($maxPoints > 0) {
            $score['experienceScore'] = round(($points / $maxPoints) * $weight, 2);
        } else {
            $score['experienceScore'] = $generalExp > 0 ? $weight * 0.5 : 0;
        }
    }

    /**
     * Skills Evaluation
     */
    protected function evaluateSkills(array $candidateSkills, array $criteria, array &$score, int $weight): void
    {
        if ($weight == 0) return;

        $points = 0;
        $maxPoints = 0;

        $candidateSkillIds = array_column($candidateSkills, 'skillId');

        // Required Skills (MUST have ALL)
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
                    $score['missed'][] = "Required skill: {$skillName} - MISSING";
                    $score['hasAllMandatory'] = false;
                }
            }

            $points += ($matchedCount / count($criteria['requiredSkills'])) * 60;
        }

        // Preferred Skills (Bonus)
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

        // Normalize
        if ($maxPoints > 0) {
            $score['skillsScore'] = round(($points / $maxPoints) * $weight, 2);
        } else {
            $score['skillsScore'] = count($candidateSkills) > 0 ? $weight * 0.5 : 0;
        }
    }

    /**
     * Clearances Evaluation
     */
    protected function evaluateClearances(array $clearances, array $criteria, array &$score, int $weight): void
    {
        if ($weight == 0) return;

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
            'requireDCIClearance' => 'DCI',
            'requireCRBClearance' => 'CRB',
            'requireEACCClearance' => 'EACC'
        ];

        foreach ($clearanceTypes as $criteriaKey => $clearanceType) {
            if ($criteria[$criteriaKey]) {
                $maxPoints += 20;
                if (isset($clearanceMap[$clearanceType])) {
                    $points += 20;
                    $score['matched'][] = "{$clearanceType} clearance";
                } else {
                    $score['missed'][] = "{$clearanceType} clearance - REQUIRED";
                    $score['hasAllMandatory'] = false;
                }
            }
        }

        if ($maxPoints > 0) {
            $score['clearanceScore'] = round(($points / $maxPoints) * $weight, 2);
        } else {
            $score['clearanceScore'] = $weight * 0.5;
        }
    }

    /**
     * Professional Evaluation
     */
    protected function evaluateProfessional(array $memberships, array $certifications, array $courses, array $publications, array $referees, array $profile, array $criteria, array &$score, int $weight): void
    {
        if ($weight == 0) return;

        $points = 0;
        $maxPoints = 0;

        // Memberships
        if ($criteria['requireProfessionalMembership']) {
            $maxPoints += 20;
            if (count($memberships) > 0) {
                $points += 20;
                $score['matched'][] = "Professional membership";
            } else {
                $score['missed'][] = "Professional membership - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        if ($criteria['requireGoodStanding']) {
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
                $score['missed'][] = "Good standing - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        // Certifications
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
                    $score['missed'][] = "Certification: {$reqCert} - REQUIRED";
                    $score['hasAllMandatory'] = false;
                }
            }

            $points += ($matchedCount / count($criteria['requiredCertifications'])) * 25;
        }

        // Leadership Course
        if ($criteria['requireLeadershipCourse']) {
            $maxPoints += 15;
            $hasLeadershipCourse = false;
            foreach ($courses as $course) {
                $duration = (int)($course['durationWeeks'] ?? 0);
                if ($duration >= ($criteria['minLeadershipCourseDuration'] ?? 4)) {
                    $hasLeadershipCourse = true;
                    break;
                }
            }

            if ($hasLeadershipCourse) {
                $points += 15;
                $score['matched'][] = "Leadership course";
            } else {
                $score['missed'][] = "Leadership course - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        // Publications
        if (!empty($criteria['minPublications'])) {
            $maxPoints += 15;
            $pubCount = count($publications);
            if ($pubCount >= $criteria['minPublications']) {
                $points += 15;
                $score['matched'][] = "Publications: {$pubCount} (min: {$criteria['minPublications']})";
            } else {
                $score['missed'][] = "Publications: {$pubCount} (min: {$criteria['minPublications']}) - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        // Referees
        if ($criteria['requireReferees'] || !empty($criteria['minRefereeCount'])) {
            $minReferees = max($criteria['minRefereeCount'], $criteria['requireReferees'] ? 1 : 0);
            if ($minReferees > 0) {
                $maxPoints += 10;
                $refCount = count($referees);
                if ($refCount >= $minReferees) {
                    $points += 10;
                    $score['matched'][] = "Referees: {$refCount} (min: {$minReferees})";
                } else {
                    $score['missed'][] = "Referees: {$refCount} (min: {$minReferees}) - REQUIRED";
                    $score['hasAllMandatory'] = false;
                }
            }
        }

        // Portfolio
        if ($criteria['requirePortfolio']) {
            $portfolioUrl = $profile['portfolioUrl'] ?? '';
            if (!empty($portfolioUrl)) {
                $score['matched'][] = "Portfolio URL provided";
            } else {
                $score['missed'][] = "Portfolio URL - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        if ($criteria['requireGitHubProfile']) {
            $githubUrl = $profile['githubUrl'] ?? '';
            if (!empty($githubUrl)) {
                $score['matched'][] = "GitHub profile provided";
            } else {
                $score['missed'][] = "GitHub profile - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        if ($criteria['requireLinkedInProfile']) {
            $linkedinUrl = $profile['linkedinUrl'] ?? '';
            if (!empty($linkedinUrl)) {
                $score['matched'][] = "LinkedIn profile provided";
            } else {
                $score['missed'][] = "LinkedIn profile - REQUIRED";
                $score['hasAllMandatory'] = false;
            }
        }

        if ($maxPoints > 0) {
            $score['professionalScore'] = round(($points / $maxPoints) * $weight, 2);
        } else {
            $score['professionalScore'] = $weight * 0.5;
        }
    }

    /**
     * Calculate ranks and percentiles
     */
    protected function calculateRanks(string $jobId): void
    {
        $results = $this->db->table('shortlist_results')
            ->where('jobId', $jobId)
            ->orderBy('totalScore', 'DESC')
            ->get()->getResultArray();

        $totalCount = count($results);
        $currentRank = 1;

        foreach ($results as $result) {
            $percentile = $totalCount > 1 
                ? round((($totalCount - $currentRank + 1) / $totalCount) * 100, 2) 
                : 100;

            $this->resultModel->update($result['id'], [
                'candidateRank' => $currentRank,
                'percentile' => $percentile
            ]);

            $currentRank++;
        }
    }

    /**
     * Get shortlist results
     */
    public function getShortlistResults(string $jobId, array $filters = []): array
    {
        $builder = $this->db->table('shortlist_results');
        $builder->select('shortlist_results.*, 
            users.firstName, users.lastName, users.email, users.phone,
            candidate_profiles.title, candidate_profiles.experienceYears,
            applications.status as applicationStatus, applications.expectedSalary')
            ->join('users', 'users.id = shortlist_results.candidateId', 'left')
            ->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left')
            ->join('applications', 'applications.id = shortlist_results.applicationId', 'left')
            ->where('shortlist_results.jobId', $jobId);

        if (!empty($filters['minScore'])) {
            $builder->where('shortlist_results.totalScore >=', (float)$filters['minScore']);
        }

        if (!empty($filters['status'])) {
            $builder->where('applications.status', $filters['status']);
        }

        if (isset($filters['hasAllMandatory']) && $filters['hasAllMandatory']) {
            $builder->where('shortlist_results.hasAllMandatory', true);
        }

        if (isset($filters['flaggedForReview']) && $filters['flaggedForReview']) {
            $builder->where('shortlist_results.flaggedForReview', true);
        }

        $builder->orderBy('shortlist_results.candidateRank', 'ASC');

        $results = $builder->get()->getResultArray();

        foreach ($results as &$result) {
            if (!empty($result['matchedCriteria']) && is_string($result['matchedCriteria'])) {
                $result['matchedCriteria'] = json_decode($result['matchedCriteria'], true);
            }
            if (!empty($result['missedCriteria']) && is_string($result['missedCriteria'])) {
                $result['missedCriteria'] = json_decode($result['missedCriteria'], true);
            }
            if (!empty($result['bonusCriteria']) && is_string($result['bonusCriteria'])) {
                $result['bonusCriteria'] = json_decode($result['bonusCriteria'], true);
            }
            if (!empty($result['disqualificationReasons']) && is_string($result['disqualificationReasons'])) {
                $result['disqualificationReasons'] = json_decode($result['disqualificationReasons'], true);
            }
        }

        return $results;
    }

    /**
     * Export to Excel-ready format
     */
    public function buildShortlistExportData(string $jobId): array
    {
        $results = $this->getShortlistResults($jobId);

        $rows = [];
        foreach ($results as $result) {
            $rows[] = [
                'Rank' => $result['candidateRank'] ?? '',
                'Name' => trim(($result['firstName'] ?? '') . ' ' . ($result['lastName'] ?? '')),
                'Email' => $result['email'] ?? '',
                'Phone' => $result['phone'] ?? '',
                'Title' => $result['title'] ?? '',
                'Experience' => $result['experienceYears'] ?? '',
                'Total Score' => $result['totalScore'] ?? 0,
                'Percentile' => $result['percentile'] ?? 0,
                'Education Score' => $result['educationScore'] ?? 0,
                'Experience Score' => $result['experienceScore'] ?? 0,
                'Skills Score' => $result['skillsScore'] ?? 0,
                'Clearance Score' => $result['clearanceScore'] ?? 0,
                'Professional Score' => $result['professionalScore'] ?? 0,
                'All Mandatory Met' => ($result['hasAllMandatory'] ?? false) ? 'Yes' : 'No',
                'Disqualified' => ($result['hasDisqualifyingFactor'] ?? false) ? 'Yes' : 'No',
                'Application Status' => $result['applicationStatus'] ?? '',
                'Expected Salary' => $result['expectedSalary'] ?? '',
                'Matched Criteria' => is_array($result['matchedCriteria']) 
                    ? implode('; ', $result['matchedCriteria']) : '',
                'Missed Criteria' => is_array($result['missedCriteria']) 
                    ? implode('; ', $result['missedCriteria']) : '',
                'Bonus Points' => is_array($result['bonusCriteria']) 
                    ? implode('; ', $result['bonusCriteria']) : ''
            ];
        }

        return $rows;
    }

    // ==================== HELPER METHODS ====================

    protected function calculateSeniorExperience(array $experiences): int
    {
        $seniorYears = 0;
        $seniorKeywords = ['director', 'manager', 'head', 'chief', 'executive', 'vp', 'president', 'ceo', 'cfo', 'cto', 'coo', 'lead'];

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
                $startYear = (int)date('Y', strtotime($exp['startDate']));
                $endYear = !empty($exp['isCurrent']) 
                    ? (int)date('Y') 
                    : (int)date('Y', strtotime($exp['endDate'] ?? 'now'));
                $seniorYears += max(0, $endYear - $startYear);
            }
        }

        return $seniorYears;
    }

    protected function hasManagementExperience(array $experiences): bool
    {
        $managementKeywords = ['manager', 'management', 'director', 'head', 'lead', 'supervisor', 'chief'];

        foreach ($experiences as $exp) {
            $title = strtolower($exp['title'] ?? '');
            foreach ($managementKeywords as $keyword) {
                if (strpos($title, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function detectCompanyTypes(array $experiences): array
    {
        $types = ['mnc' => false, 'startup' => false, 'ngo' => false, 'government' => false];

        $mncKeywords = ['multinational', 'google', 'microsoft', 'amazon', 'facebook', 'apple', 'ibm', 'oracle', 'sap'];
        $startupKeywords = ['startup', 'tech startup', 'co-founder'];
        $ngoKeywords = ['ngo', 'non-profit', 'foundation', 'charity', 'unicef', 'red cross'];
        $govKeywords = ['government', 'ministry', 'county', 'national', 'public service'];

        foreach ($experiences as $exp) {
            $company = strtolower($exp['company'] ?? '');
            $description = strtolower($exp['description'] ?? '');

            foreach ($mncKeywords as $keyword) {
                if (strpos($company, $keyword) !== false || strpos($description, $keyword) !== false) {
                    $types['mnc'] = true;
                }
            }

            foreach ($startupKeywords as $keyword) {
                if (strpos($company, $keyword) !== false || strpos($description, $keyword) !== false) {
                    $types['startup'] = true;
                }
            }

            foreach ($ngoKeywords as $keyword) {
                if (strpos($company, $keyword) !== false || strpos($description, $keyword) !== false) {
                    $types['ngo'] = true;
                }
            }

            foreach ($govKeywords as $keyword) {
                if (strpos($company, $keyword) !== false || strpos($description, $keyword) !== false) {
                    $types['government'] = true;
                }
            }
        }

        return $types;
    }

    protected function getSkillName(string $skillId): string
    {
        $skill = $this->db->table('skills')->where('id', $skillId)->get()->getRowArray();
        return $skill['name'] ?? "Skill #{$skillId}";
    }
}