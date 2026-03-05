<?php

namespace App\Controllers\AdminCandidate;

use App\Controllers\BaseController;
use App\Models\CandidateProfile;
use App\Models\ResumeVersion;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Admin - Candidates",
 *     description="Admin candidate management endpoints"
 * )
 */
class AdminCandidates extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/admin/candidates",
     *     tags={"Admin - Candidates"},
     *     summary="Get all candidates with filters and pagination",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="domainId", in="query", required=false, @OA\Schema(type="string"), description="Filter by domain ID"),
     *     @OA\Parameter(name="skillId", in="query", required=false, @OA\Schema(type="string"), description="Filter by skill ID"),
     *     @OA\Parameter(name="openToWork", in="query", required=false, @OA\Schema(type="boolean"), description="Filter by open to work status"),
     *     @OA\Parameter(name="location", in="query", required=false, @OA\Schema(type="string"), description="Filter by location"),
     *     @OA\Parameter(name="minExperience", in="query", required=false, @OA\Schema(type="integer"), description="Minimum experience in months"),
     *     @OA\Parameter(name="maxExperience", in="query", required=false, @OA\Schema(type="integer"), description="Maximum experience in months"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(response="200", description="Candidates retrieved")
     * )
     */
    public function getAllCandidates()
    {
        try {
            // Get pagination parameters
            $page = max(1, (int) ($this->request->getGet('page') ?? 1));
            $limit = max(1, min(100, (int) ($this->request->getGet('limit') ?? 20)));
            $skip = ($page - 1) * $limit;

            // Get filters
            $domainId = $this->request->getGet('domainId');
            $skillId = $this->request->getGet('skillId');
            $openToWork = $this->request->getGet('openToWork');
            $location = $this->request->getGet('location');
            $search = $this->request->getGet('search');
            $hasResume = $this->request->getGet('hasResume');
            $minExperience = $this->request->getGet('minExperience');
            $maxExperience = $this->request->getGet('maxExperience');
            $sortBy = $this->request->getGet('sortBy') ?? 'createdAt';
            $sortOrder = strtoupper($this->request->getGet('sortOrder') ?? 'DESC');

            // Validate sort order
            if (!in_array($sortOrder, ['ASC', 'DESC'])) {
                $sortOrder = 'DESC';
            }

            // Use Query Builder
            $db = \Config\Database::connect();
            $builder = $db->table('candidate_profiles');

            // ✅ Select candidate_profiles columns including totalExperienceMonths
            $builder->select('candidate_profiles.*, candidate_profiles.totalExperienceMonths');

            // Apply filters
            if ($openToWork !== null) {
                $openToWorkValue = filter_var($openToWork, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($openToWorkValue !== null) {
                    $builder->where('candidate_profiles.openToWork', $openToWorkValue);
                }
            }

            if ($location) {
                $builder->like('candidate_profiles.location', $location, 'both');
            }

            if ($hasResume !== null) {
                $hasResumeValue = filter_var($hasResume, FILTER_VALIDATE_BOOLEAN);
                if ($hasResumeValue) {
                    $builder->where('candidate_profiles.resumeUrl IS NOT NULL');
                } else {
                    $builder->where('candidate_profiles.resumeUrl IS NULL');
                }
            }

            if ($minExperience !== null && $minExperience !== '') {
                $minExp = (int) $minExperience;
                $builder->where('candidate_profiles.totalExperienceMonths >=', $minExp);
            }

            if ($maxExperience !== null && $maxExperience !== '') {
                $maxExp = (int) $maxExperience;
                $builder->where('candidate_profiles.totalExperienceMonths <=', $maxExp);
            }

            // Search filter
            $hasSearchJoin = false;
            if ($search) {
                $builder->join('users', 'users.id = candidate_profiles.userId', 'inner');
                $hasSearchJoin = true;
                $builder->groupStart()
                    ->like('users.firstName', $search, 'both')
                    ->orLike('users.lastName', $search, 'both')
                    ->orLike('users.email', $search, 'both')
                    ->orLike('candidate_profiles.title', $search, 'both')
                    ->orLike('candidate_profiles.location', $search, 'both')
                    ->groupEnd();
            }

            // Domain filter
            if ($domainId) {
                $builder->join('candidate_domains', 'candidate_domains.candidateId = candidate_profiles.id', 'inner')
                    ->where('candidate_domains.domainId', $domainId);
            }

            // Skill filter
            if ($skillId) {
                $builder->join('candidate_skills', 'candidate_skills.candidateId = candidate_profiles.id', 'inner')
                    ->where('candidate_skills.skillId', $skillId);
            }

            // Group by to avoid duplicates
            if ($domainId || $skillId) {
                $builder->groupBy('candidate_profiles.id');
            }

            // Clone builder for count
            $countBuilder = clone $builder;
            $total = $countBuilder->countAllResults(false);

            // Apply sorting
            if (in_array($sortBy, ['firstName', 'lastName'])) {
                if (!$hasSearchJoin) {
                    $builder->join('users', 'users.id = candidate_profiles.userId', 'inner');
                }
                $builder->orderBy('users.' . $sortBy, $sortOrder);
            } elseif ($sortBy === 'totalExperienceMonths') {

                $builder->orderBy('candidate_profiles.totalExperienceMonths', $sortOrder);
            } else {
                $allowedSortFields = ['createdAt', 'updatedAt', 'title', 'location', 'openToWork'];
                if (in_array($sortBy, $allowedSortFields)) {
                    $builder->orderBy('candidate_profiles.' . $sortBy, $sortOrder);
                } else {
                    $builder->orderBy('candidate_profiles.createdAt', 'DESC');
                }
            }

            // Get paginated results
            $candidates = $builder->limit($limit, $skip)->get()->getResultArray();

            // Format candidates
            $formattedCandidates = $this->formatCandidates($candidates);

            $totalPages = (int) ceil($total / $limit);

            return $this->respond([
                'success' => true,
                'message' => 'Candidates retrieved successfully',
                'data' => [
                    'candidates' => $formattedCandidates,
                    'pagination' => [
                        'total' => (int) $total,
                        'page' => $page,
                        'limit' => $limit,
                        'totalPages' => $totalPages,
                        'hasNext' => $page < $totalPages,
                        'hasPrev' => $page > 1
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get candidates error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve candidates',
                'data' => null
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/candidates/stats",
     *     tags={"Admin - Candidates"},
     *     summary="Get candidate statistics for dashboard",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Statistics retrieved successfully")
     * )
     */
    public function getCandidateStats()
    {
        try {
            $candidateModel = new CandidateProfile();
            $db = \Config\Database::connect();

            // Total candidates
            $totalCandidates = $candidateModel->countAllResults(false);

            // Active candidates (logged in within 30 days)
            $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
            $activeCandidates = $db->table('candidate_profiles')
                ->join('users', 'users.id = candidate_profiles.userId', 'inner')
                ->where('users.lastLoginAt >=', $thirtyDaysAgo)
                ->countAllResults();

            // Candidates with resume
            $candidatesWithResume = $candidateModel->where('resumeUrl IS NOT NULL')
                ->countAllResults(false);

            // Candidates open to work
            $candidatesOpenToWork = $candidateModel->where('openToWork', true)
                ->countAllResults(false);

            // Recent candidates (last 7 days)
            $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
            $recentCandidates = $candidateModel->where('createdAt >=', $sevenDaysAgo)
                ->countAllResults(false);

            // Top skills
            $topSkillsRaw = $db->table('candidate_skills')
                ->select('skillId, COUNT(*) as count')
                ->groupBy('skillId')
                ->orderBy('count', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();

            $skillIds = array_column($topSkillsRaw, 'skillId');
            $skillsMap = [];
            if (!empty($skillIds)) {
                $skillModel = new \App\Models\Skill();
                $skills = $skillModel->whereIn('id', $skillIds)->findAll();
                foreach ($skills as $skill) {
                    $skillsMap[$skill['id']] = $skill;
                }
            }

            $topSkills = [];
            foreach ($topSkillsRaw as $stat) {
                $skill = $skillsMap[$stat['skillId']] ?? null;
                $topSkills[] = [
                    'skillId' => $stat['skillId'],
                    'skillName' => $skill ? $skill['name'] : 'Unknown',
                    'count' => (int) $stat['count']
                ];
            }

            // Top domains
            $topDomainsRaw = $db->table('candidate_domains')
                ->select('domainId, COUNT(*) as count')
                ->groupBy('domainId')
                ->orderBy('count', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();

            $domainIds = array_column($topDomainsRaw, 'domainId');
            $domainsMap = [];
            if (!empty($domainIds)) {
                $domainModel = new \App\Models\Domain();
                $domains = $domainModel->whereIn('id', $domainIds)->findAll();
                foreach ($domains as $domain) {
                    $domainsMap[$domain['id']] = $domain;
                }
            }

            $topDomains = [];
            foreach ($topDomainsRaw as $stat) {
                $domain = $domainsMap[$stat['domainId']] ?? null;
                $topDomains[] = [
                    'domainId' => $stat['domainId'],
                    'domainName' => $domain ? $domain['name'] : 'Unknown',
                    'count' => (int) $stat['count']
                ];
            }

            return $this->respond([
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data' => [
                    'overview' => [
                        'totalCandidates' => (int) $totalCandidates,
                        'activeCandidates' => (int) $activeCandidates,
                        'candidatesWithResume' => (int) $candidatesWithResume,
                        'candidatesOpenToWork' => (int) $candidatesOpenToWork,
                        'recentCandidates' => (int) $recentCandidates
                    ],
                    'topSkills' => $topSkills,
                    'topDomains' => $topDomains
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get stats error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'data' => null
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/candidates/{candidateProfileId}",
     *     tags={"Admin - Candidates"},
     *     summary="Get single candidate full details by profile ID",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="candidateProfileId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Candidate retrieved successfully"),
     *     @OA\Response(response="404", description="Candidate not found")
     * )
     */
    public function getCandidateById($candidateProfileId = null)
    {
        // Log the received parameter for debugging
        log_message('debug', 'getCandidateById called with param: ' . var_export($candidateProfileId, true));

        if (empty($candidateProfileId)) {
            log_message('error', 'Candidate profile ID is empty or null');
            return $this->respond([
                'success' => false,
                'message' => 'Candidate profile ID is required',
                'data' => null
            ], 400);
        }

        try {
            $candidateModel = new CandidateProfile();
            $candidate = $candidateModel->find($candidateProfileId);

            if (!$candidate) {
                log_message('error', 'Candidate not found: ' . $candidateProfileId);
                return $this->respond([
                    'success' => false,
                    'message' => 'Candidate not found',
                    'data' => null
                ], 404);
            }

            // Format candidate with all relations
            $formattedCandidate = $this->formatSingleCandidate($candidate);

            if (!$formattedCandidate) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Candidate profile has no associated user',
                    'data' => null
                ], 404);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Candidate retrieved successfully',
                'data' => $formattedCandidate
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get candidate error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve candidate',
                'data' => null
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/candidates/{candidateProfileId}/applications",
     *     tags={"Admin - Candidates"},
     *     summary="Get all applications by candidate profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="candidateProfileId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Applications retrieved successfully"),
     *     @OA\Response(response="404", description="Candidate not found")
     * )
     */
    public function getCandidateApplications($candidateProfileId = null)
    {
        // Log the received parameter for debugging
        log_message('debug', 'getCandidateApplications called with param: ' . var_export($candidateProfileId, true));

        if (empty($candidateProfileId)) {
            log_message('error', 'Candidate profile ID is empty or null');
            return $this->respond([
                'success' => false,
                'message' => 'Candidate profile ID is required',
                'data' => null
            ], 400);
        }

        try {
            $candidateModel = new CandidateProfile();
            $candidate = $candidateModel->find($candidateProfileId);

            if (!$candidate) {
                log_message('error', 'Candidate profile not found: ' . $candidateProfileId);
                return $this->respond([
                    'success' => false,
                    'message' => 'Candidate profile not found',
                    'data' => null
                ], 404);
            }

            // Get applications for this candidate
            $applicationModel = new \App\Models\Application();
            $applications = $applicationModel->where('candidateId', $candidate['userId'])
                ->orderBy('appliedAt', 'DESC')
                ->findAll();

            // Format applications with job details
            $formattedApplications = $this->formatApplications($applications);

            return $this->respond([
                'success' => true,
                'message' => 'Applications retrieved successfully',
                'data' => $formattedApplications
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get candidate applications error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve applications',
                'data' => null
            ], 500);
        }
    }

    // ============================================
    // PRIVATE HELPER METHODS
    // ============================================

    private function formatCandidates(array $candidates): array
    {
        $userModel = new \App\Models\User();
        $candidateSkillModel = new \App\Models\CandidateSkill();
        $candidateDomainModel = new \App\Models\CandidateDomain();
        $skillModel = new \App\Models\Skill();
        $domainModel = new \App\Models\Domain();
        $educationModel = new \App\Models\Education();
        $experienceModel = new \App\Models\Experience();
        $certificationModel = new \App\Models\Certification();

        $formattedCandidates = [];

        foreach ($candidates as $candidate) {
            if (empty($candidate['userId'])) {
                continue;
            }

            $user = $userModel->select('id, email, firstName, lastName, phone, avatar, emailVerified, status, createdAt')
                ->find($candidate['userId']);

            if (!$user) {
                continue;
            }

            $candidate['user'] = $user;

            // Get skills (limit 10)
            $candidateSkills = $candidateSkillModel->where('candidateId', $candidate['id'])
                ->orderBy('createdAt', 'DESC')
                ->limit(10)
                ->findAll();

            $skills = [];
            foreach ($candidateSkills as $cs) {
                $skill = $skillModel->find($cs['skillId']);
                if ($skill) {
                    $skills[] = [
                        'id' => $cs['id'],
                        'candidateId' => $cs['candidateId'],
                        'skillId' => $cs['skillId'],
                        'level' => $cs['level'] ?? null,
                        'yearsOfExp' => $cs['yearsOfExp'] ?? null,
                        'createdAt' => $cs['createdAt'] ?? null,
                        'skill' => $skill
                    ];
                }
            }
            $candidate['skills'] = $skills;

            // Get domains
            $candidateDomains = $candidateDomainModel->where('candidateId', $candidate['id'])->findAll();
            $domains = [];
            foreach ($candidateDomains as $cd) {
                $domain = $domainModel->find($cd['domainId']);
                if ($domain) {
                    $domains[] = [
                        'id' => $cd['id'],
                        'candidateId' => $cd['candidateId'],
                        'domainId' => $cd['domainId'],
                        'isPrimary' => $cd['isPrimary'] ?? false,
                        'createdAt' => $cd['createdAt'] ?? null,
                        'domain' => $domain
                    ];
                }
            }
            $candidate['domains'] = $domains;

            // Get counts
            $candidate['_count'] = [
                'educations' => (int) $educationModel->where('candidateId', $candidate['id'])->countAllResults(false),
                'experiences' => (int) $experienceModel->where('candidateId', $candidate['id'])->countAllResults(false),
                'certifications' => (int) $certificationModel->where('candidateId', $candidate['id'])->countAllResults(false)
            ];

            $formattedCandidates[] = $candidate;
        }

        return $formattedCandidates;
    }

    private function formatSingleCandidate(array $candidate): ?array
    {
        if (empty($candidate['userId'])) {
            return null;
        }

        $userModel = new \App\Models\User();
        $user = $userModel->select('id, firstName, lastName, email, phone, avatar, emailVerified, status, role, createdAt, lastLoginAt')
            ->find($candidate['userId']);

        if (!$user) {
            return null;
        }

        $candidate['user'] = $user;

        // Get skills
        $candidateSkillModel = new \App\Models\CandidateSkill();
        $candidateSkills = $candidateSkillModel->where('candidateId', $candidate['id'])
            ->orderBy('createdAt', 'DESC')
            ->findAll();

        $skillModel = new \App\Models\Skill();
        $skills = [];
        foreach ($candidateSkills as $cs) {
            $skill = $skillModel->find($cs['skillId']);
            if ($skill) {
                $skills[] = [
                    'id' => $cs['id'],
                    'candidateId' => $cs['candidateId'],
                    'skillId' => $cs['skillId'],
                    'level' => $cs['level'] ?? null,
                    'yearsOfExp' => $cs['yearsOfExp'] ?? null,
                    'createdAt' => $cs['createdAt'] ?? null,
                    'skill' => $skill
                ];
            }
        }
        $candidate['skills'] = $skills;

        // Get domains
        $candidateDomainModel = new \App\Models\CandidateDomain();
        $candidateDomains = $candidateDomainModel->where('candidateId', $candidate['id'])->findAll();

        $domainModel = new \App\Models\Domain();
        $domains = [];
        foreach ($candidateDomains as $cd) {
            $domain = $domainModel->find($cd['domainId']);
            if ($domain) {
                $domains[] = [
                    'id' => $cd['id'],
                    'candidateId' => $cd['candidateId'],
                    'domainId' => $cd['domainId'],
                    'isPrimary' => $cd['isPrimary'] ?? false,
                    'createdAt' => $cd['createdAt'] ?? null,
                    'domain' => $domain
                ];
            }
        }
        $candidate['domains'] = $domains;

        // Get educations
        $educationModel = new \App\Models\Education();
        $candidate['educations'] = $educationModel->where('candidateId', $candidate['id'])
            ->orderBy('startDate', 'DESC')
            ->findAll();

        // Get experiences
        $experienceModel = new \App\Models\Experience();
        $candidate['experiences'] = $experienceModel->where('candidateId', $candidate['id'])
            ->orderBy('startDate', 'DESC')
            ->findAll();

        // Get certifications
        $certificationModel = new \App\Models\Certification();
        $candidate['certifications'] = $certificationModel->where('candidateId', $candidate['id'])
            ->orderBy('issueDate', 'DESC')
            ->findAll();

        // Get languages
        $candidateLanguageModel = new \App\Models\CandidateLanguage();
        $candidateLanguages = $candidateLanguageModel->where('candidateId', $candidate['id'])->findAll();

        $languageModel = new \App\Models\Language();
        $languages = [];
        foreach ($candidateLanguages as $cl) {
            $language = $languageModel->find($cl['languageId']);
            if ($language) {
                $languages[] = [
                    'id' => $cl['id'],
                    'candidateId' => $cl['candidateId'],
                    'languageId' => $cl['languageId'],
                    'proficiency' => $cl['proficiency'] ?? null,
                    'createdAt' => $cl['createdAt'] ?? null,
                    'language' => $language
                ];
            }
        }
        $candidate['languages'] = $languages;

        // Get resumes
        $resumeModel = new \App\Models\ResumeVersion();
        $candidate['resumes'] = $resumeModel->where('candidateId', $candidate['id'])
            ->orderBy('version', 'DESC')
            ->findAll();

        return $candidate;
    }

    private function formatApplications(array $applications): array
    {
        $jobModel = new \App\Models\Job();
        $companyModel = new \App\Models\Company();
        $categoryModel = new \App\Models\JobCategory();
        $statusHistoryModel = new \App\Models\ApplicationStatusHistory();

        $formattedApplications = [];

        foreach ($applications as $application) {
            $job = $jobModel->find($application['jobId']);

            if ($job) {
                $company = $companyModel->select('id, name, logo, location')
                    ->find($job['companyId']);
                $job['company'] = $company;

                $category = $categoryModel->find($job['categoryId']);
                $job['category'] = $category;

                $application['job'] = $job;
            }

            // Get status history
            $statusHistory = $statusHistoryModel->where('applicationId', $application['id'])
                ->orderBy('changedAt', 'DESC')
                ->findAll();
            $application['statusHistory'] = $statusHistory;

            $formattedApplications[] = $application;
        }

        return $formattedApplications;
    }

    public function previewResumeCleanup()
    {
        try {
            $resumeModel = new ResumeVersion();
            $profileModel = new CandidateProfile();
            $userModel = new \App\Models\User();

            $profiles = $profileModel->findAll();

            $candidatesAffected = 0;
            $resumesToDelete = 0;
            $bytesToFree = 0;
            $details = [];

            foreach ($profiles as $profile) {
                $resumes = $resumeModel->where('candidateId', $profile['id'])
                    ->orderBy('version', 'DESC')
                    ->findAll();

                if (count($resumes) <= 1)
                    continue;

                $toDelete = array_slice($resumes, 1);

                $candidateBytes = 0;
                $deleteList = [];

                foreach ($toDelete as $old) {
                    $physicalPath = WRITEPATH . ltrim(
                        str_replace('/uploads/', 'uploads/', $old['fileUrl']),
                        '/'
                    );
                    $fileSize = is_file($physicalPath) ? filesize($physicalPath) : 0;
                    $candidateBytes += $fileSize;
                    $bytesToFree += $fileSize;
                    $resumesToDelete++;

                    $deleteList[] = [
                        'id' => $old['id'],
                        'fileName' => $old['fileName'] ?? basename($old['fileUrl']),
                        'fileSize' => $fileSize,
                        'createdAt' => $old['createdAt'] ?? null,
                    ];
                }

                $kept = $resumes[0];
                $user = $userModel->select('firstName, lastName, email')->find($profile['userId']);

                $details[] = [
                    'candidateId' => $profile['id'],
                    'candidateName' => $user ? trim($user['firstName'] . ' ' . $user['lastName']) : 'Unknown',
                    'email' => $user['email'] ?? null,
                    'keeping' => [
                        'fileName' => $kept['fileName'] ?? basename($kept['fileUrl']),
                        'fileSize' => (function () use ($kept) {
                            $p = WRITEPATH . ltrim(str_replace('/uploads/', 'uploads/', $kept['fileUrl']), '/');
                            return is_file($p) ? filesize($p) : 0;
                        })(),
                    ],
                    'toDelete' => $deleteList,
                    'bytesToFree' => $candidateBytes,
                ];

                $candidatesAffected++;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Preview generated',
                'data' => [
                    'candidatesAffected' => $candidatesAffected,
                    'resumesToDelete' => $resumesToDelete,
                    'bytesToFree' => $bytesToFree,
                    'bytesToFreeHuman' => $this->formatBytes($bytesToFree),
                    'details' => $details,
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Preview resume cleanup error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to generate preview: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    public function previewDuplicateFileCleanup()
    {
        try {
            $fileModel = new \App\Models\CandidateFile();
            $userModel = new \App\Models\User();
            $profileModel = new CandidateProfile();

            $allFiles = $fileModel->orderBy('createdAt', 'DESC')->findAll();

            if (empty($allFiles)) {
                return $this->respond([
                    'success' => true,
                    'message' => 'Nothing to clean up',
                    'data' => [
                        'candidatesAffected' => 0,
                        'filesToDelete' => 0,
                        'bytesToFree' => 0,
                        'bytesToFreeHuman' => '0 B',
                        'details' => [],
                    ]
                ]);
            }

            // Group by candidate → category::filename (same file uploaded twice = duplicate)
            $grouped = [];
            foreach ($allFiles as $file) {
                $key = $file['category'] . '::' . $file['fileName'];
                $grouped[$file['candidateId']][$key][] = $file;
            }

            $candidatesAffected = 0;
            $filesToDelete = 0;
            $bytesToFree = 0;
            $details = [];

            foreach ($grouped as $candidateId => $groups) {
                $candidateDeleteList = [];
                $candidateBytes = 0;

                foreach ($groups as $key => $files) {
                    if (count($files) <= 1)
                        continue;

                    // Index 0 = newest (kept), everything after = duplicates
                    $toDelete = array_slice($files, 1);

                    foreach ($toDelete as $old) {
                        $physicalPath = WRITEPATH . ltrim(
                            str_replace('/uploads/', 'uploads/', $old['fileUrl']),
                            '/'
                        );
                        $fileSize = is_file($physicalPath) ? filesize($physicalPath) : 0;
                        $candidateBytes += $fileSize;
                        $bytesToFree += $fileSize;
                        $filesToDelete++;

                        $candidateDeleteList[] = [
                            'id' => $old['id'],
                            'category' => $old['category'],
                            'fileName' => $old['fileName'],
                            'fileSize' => $fileSize,
                            'createdAt' => $old['createdAt'] ?? null,
                        ];
                    }
                }

                if (empty($candidateDeleteList))
                    continue;

                $profile = $profileModel->find($candidateId);
                $user = $profile
                    ? $userModel->select('firstName, lastName, email')->find($profile['userId'])
                    : null;

                $details[] = [
                    'candidateId' => $candidateId,
                    'candidateName' => $user ? trim($user['firstName'] . ' ' . $user['lastName']) : 'Unknown',
                    'email' => $user['email'] ?? null,
                    'toDelete' => $candidateDeleteList,
                    'bytesToFree' => $candidateBytes,
                ];

                $candidatesAffected++;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Preview generated',
                'data' => [
                    'candidatesAffected' => $candidatesAffected,
                    'filesToDelete' => $filesToDelete,
                    'bytesToFree' => $bytesToFree,
                    'bytesToFreeHuman' => $this->formatBytes($bytesToFree),
                    'details' => $details,
                ]
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Preview file cleanup error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to generate preview: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    // ── Shared helper ──────────────────────────────────────────────────────────
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824)
            return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)
            return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)
            return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
    public function cleanupOldResumes()
    {
        try {
            $body = $this->request->getJSON(true);
            $selectedIds = $body['selectedIds'] ?? [];

            // If nothing selected, nothing to do
            if (empty($selectedIds)) {
                return $this->respond([
                    'success' => true,
                    'message' => 'No files selected.',
                    'data' => ['candidatesProcessed' => 0, 'resumesDeleted' => 0]
                ]);
            }

            $resumeModel = new ResumeVersion();
            $profileModel = new CandidateProfile();

            $totalDeleted = 0;
            $candidatesProcessed = 0;

            // Fetch only the selected resume records
            $toDeleteRecords = $resumeModel->whereIn('id', $selectedIds)->findAll();

            if (empty($toDeleteRecords)) {
                return $this->respond([
                    'success' => true,
                    'message' => 'No matching records found.',
                    'data' => ['candidatesProcessed' => 0, 'resumesDeleted' => 0]
                ]);
            }

            // Group by candidateId so we can update the profile after deletion
            $byCandidateId = [];
            foreach ($toDeleteRecords as $record) {
                $byCandidateId[$record['candidateId']][] = $record;
            }

            foreach ($byCandidateId as $candidateId => $records) {
                foreach ($records as $record) {
                    // Delete physical file
                    $physicalPath = WRITEPATH . ltrim(
                        str_replace('/uploads/', 'uploads/', $record['fileUrl']),
                        '/'
                    );
                    if (is_file($physicalPath)) {
                        @unlink($physicalPath);
                    }
                    $resumeModel->delete($record['id']);
                    $totalDeleted++;
                }

                // After deletion, get the surviving resume and sync profile
                $surviving = $resumeModel->where('candidateId', $candidateId)
                    ->orderBy('version', 'DESC')
                    ->first();

                if ($surviving) {
                    // Reset version to 1 and sync profile URL
                    $resumeModel->update($surviving['id'], ['version' => 1]);
                    $profileModel->where('id', $candidateId)->set([
                        'resumeUrl' => $surviving['fileUrl'],
                        'resumeUpdatedAt' => date('Y-m-d H:i:s'),
                    ])->update();
                }

                $candidatesProcessed++;
            }

            return $this->respond([
                'success' => true,
                'message' => "Cleanup complete. Processed {$candidatesProcessed} candidates, deleted {$totalDeleted} resume(s).",
                'data' => [
                    'candidatesProcessed' => $candidatesProcessed,
                    'resumesDeleted' => $totalDeleted,
                ]
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Resume cleanup error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Cleanup failed: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    public function cleanupDuplicateCandidateFiles()
    {
        try {
            $body = $this->request->getJSON(true);
            $selectedIds = $body['selectedIds'] ?? [];

            // If nothing selected, nothing to do
            if (empty($selectedIds)) {
                return $this->respond([
                    'success' => true,
                    'message' => 'No files selected.',
                    'data' => ['candidatesProcessed' => 0, 'filesDeleted' => 0]
                ]);
            }

            $fileModel = new \App\Models\CandidateFile();

            // Fetch only the selected file records
            $toDeleteRecords = $fileModel->whereIn('id', $selectedIds)->findAll();

            if (empty($toDeleteRecords)) {
                return $this->respond([
                    'success' => true,
                    'message' => 'No matching records found.',
                    'data' => ['candidatesProcessed' => 0, 'filesDeleted' => 0]
                ]);
            }

            $totalDeleted = 0;
            $candidatesAffected = [];

            foreach ($toDeleteRecords as $file) {
                // Delete physical file
                $physicalPath = WRITEPATH . ltrim(
                    str_replace('/uploads/', 'uploads/', $file['fileUrl']),
                    '/'
                );
                try {
                    if (is_file($physicalPath)) {
                        @unlink($physicalPath);
                    }
                } catch (\Exception $e) {
                    // ignore physical delete errors, continue with DB
                }

                $fileModel->delete($file['id']);
                $totalDeleted++;
                $candidatesAffected[$file['candidateId']] = true;
            }

            $candidatesProcessed = count($candidatesAffected);

            return $this->respond([
                'success' => true,
                'message' => "Cleanup complete. Processed {$candidatesProcessed} candidates, deleted {$totalDeleted} duplicate file(s).",
                'data' => [
                    'candidatesProcessed' => $candidatesProcessed,
                    'filesDeleted' => $totalDeleted,
                ]
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Duplicate file cleanup error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Cleanup failed: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

}
