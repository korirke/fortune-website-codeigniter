<?php

namespace App\Controllers\AdminCandidate;

use App\Controllers\BaseController;
use App\Models\CandidateProfile;
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
     *     path="/admin/candidates",
     *     tags={"Admin - Candidates"},
     *     summary="Get all candidates with filters and pagination",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="domainId", in="query", required=false, @OA\Schema(type="string"), description="Filter by domain ID"),
     *     @OA\Parameter(name="skillId", in="query", required=false, @OA\Schema(type="string"), description="Filter by skill ID"),
     *     @OA\Parameter(name="openToWork", in="query", required=false, @OA\Schema(type="boolean"), description="Filter by open to work status"),
     *     @OA\Parameter(name="location", in="query", required=false, @OA\Schema(type="string"), description="Filter by location"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(response="200", description="Candidates retrieved")
     * )
     */
    public function getAllCandidates()
    {
        try {
            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;
            
            // Get filters
            $domainId = $this->request->getGet('domainId');
            $skillId = $this->request->getGet('skillId');
            $openToWork = $this->request->getGet('openToWork');
            $location = $this->request->getGet('location');
            $search = $this->request->getGet('search');
            $hasResume = $this->request->getGet('hasResume');
            $sortBy = $this->request->getGet('sortBy') ?? 'createdAt';
            $sortOrder = $this->request->getGet('sortOrder') ?? 'desc';
            
            // Use Query Builder for complex queries with multiple joins
            $db = \Config\Database::connect();
            $builder = $db->table('candidate_profiles');
            
            // Select all candidate_profiles columns
            $builder->select('candidate_profiles.*');
            
            // Open to work filter (matching Node.js)
            if ($openToWork !== null) {
                $openToWorkValue = $openToWork === 'true' || $openToWork === true || $openToWork === '1' || $openToWork === 1;
                $builder->where('candidate_profiles.openToWork', $openToWorkValue);
            }
            
            // Location filter (matching Node.js)
            if ($location) {
                $builder->like('candidate_profiles.location', $location);
            }
            
            // Has resume filter (matching Node.js)
            if ($hasResume !== null) {
                if ($hasResume === 'true' || $hasResume === true || $hasResume === '1' || $hasResume === 1) {
                    $builder->where('candidate_profiles.resumeUrl IS NOT NULL');
                } else {
                    $builder->where('candidate_profiles.resumeUrl IS NULL');
                }
            }
            
            // Search filter - search in user fields (matching Node.js)
            $hasSearchJoin = false;
            if ($search) {
                $builder->join('users', 'users.id = candidate_profiles.userId', 'inner');
                $hasSearchJoin = true;
                $builder->groupStart()
                    ->like('users.firstName', $search)
                    ->orLike('users.lastName', $search)
                    ->orLike('users.email', $search)
                    ->orLike('candidate_profiles.title', $search)
                    ->orLike('candidate_profiles.location', $search)
                    ->groupEnd();
            }
            
            // Domain filter (matching Node.js - requires join, uses candidateId)
            if ($domainId) {
                $builder->join('candidate_domains', 'candidate_domains.candidateId = candidate_profiles.id', 'inner')
                    ->where('candidate_domains.domainId', $domainId);
            }
            
            // Skill filter (matching Node.js - requires join, uses candidateId)
            if ($skillId) {
                $builder->join('candidate_skills', 'candidate_skills.candidateId = candidate_profiles.id', 'inner')
                    ->where('candidate_skills.skillId', $skillId);
            }
            
            // Group by to avoid duplicates from joins
            if ($domainId || $skillId) {
                $builder->groupBy('candidate_profiles.id');
            }
            
            // Build count query separately to avoid affecting main query
            $countBuilder = $db->table('candidate_profiles');
            if ($openToWork !== null) {
                $openToWorkValue = $openToWork === 'true' || $openToWork === true || $openToWork === '1' || $openToWork === 1;
                $countBuilder->where('candidate_profiles.openToWork', $openToWorkValue);
            }
            if ($location) {
                $countBuilder->like('candidate_profiles.location', $location);
            }
            if ($hasResume !== null) {
                if ($hasResume === 'true' || $hasResume === true || $hasResume === '1' || $hasResume === 1) {
                    $countBuilder->where('candidate_profiles.resumeUrl IS NOT NULL');
                } else {
                    $countBuilder->where('candidate_profiles.resumeUrl IS NULL');
                }
            }
            if ($search) {
                $countBuilder->join('users', 'users.id = candidate_profiles.userId', 'inner')
                    ->groupStart()
                    ->like('users.firstName', $search)
                    ->orLike('users.lastName', $search)
                    ->orLike('users.email', $search)
                    ->orLike('candidate_profiles.title', $search)
                    ->orLike('candidate_profiles.location', $search)
                    ->groupEnd();
            }
            if ($domainId) {
                $countBuilder->join('candidate_domains', 'candidate_domains.candidateId = candidate_profiles.id', 'inner')
                    ->where('candidate_domains.domainId', $domainId);
            }
            if ($skillId) {
                $countBuilder->join('candidate_skills', 'candidate_skills.candidateId = candidate_profiles.id', 'inner')
                    ->where('candidate_skills.skillId', $skillId);
            }
            if ($domainId || $skillId) {
                $countBuilder->groupBy('candidate_profiles.id');
            }
            $total = $countBuilder->countAllResults(false);
            
            // Apply sorting (matching Node.js)
            // If sorting by firstName or lastName, need to join users table
            if ($sortBy === 'firstName' || $sortBy === 'lastName') {
                if (!$hasSearchJoin) {
                    $builder->join('users', 'users.id = candidate_profiles.userId', 'inner');
                }
                $builder->orderBy('users.' . $sortBy, strtoupper($sortOrder));
            } else {
                $builder->orderBy('candidate_profiles.' . $sortBy, strtoupper($sortOrder));
            }
            
            // Get paginated results
            $candidates = $builder->limit($limit, $skip)->get()->getResultArray();
            
            // Format candidates with user, skills, domains (matching Node.js)
            $userModel = new \App\Models\User();
            $candidateSkillModel = new \App\Models\CandidateSkill();
            $candidateDomainModel = new \App\Models\CandidateDomain();
            $skillModel = new \App\Models\Skill();
            $domainModel = new \App\Models\Domain();
            
            $formattedCandidates = [];
            foreach ($candidates as $candidate) {
                // Skip candidates without userId
                if (empty($candidate['userId'])) {
                    continue;
                }
                
                $formattedCandidate = $candidate;
                
                // Get user data (matching Node.js - includes emailVerified, status, createdAt)
                $user = $userModel->select('id, email, firstName, lastName, phone, avatar, emailVerified, status, createdAt')
                    ->find($candidate['userId']);
                
                // Skip if user not found (orphaned candidate profile)
                if (!$user) {
                    continue;
                }
                
                $formattedCandidate['user'] = $user;
                
                // Get skills (matching Node.js - limit to 10, wrapped in { skill: ... })
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
                $formattedCandidate['skills'] = $skills;
                
                // Get domains (matching Node.js - wrapped in { domain: ... })
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
                $formattedCandidate['domains'] = $domains;
                
                // Get _count for educations, experiences, certifications (matching Node.js)
                $educationModel = new \App\Models\Education();
                $experienceModel = new \App\Models\Experience();
                $certificationModel = new \App\Models\Certification();
                
                $formattedCandidate['_count'] = [
                    'educations' => (int) $educationModel->where('candidateId', $candidate['id'])->countAllResults(false),
                    'experiences' => (int) $experienceModel->where('candidateId', $candidate['id'])->countAllResults(false),
                    'certifications' => (int) $certificationModel->where('candidateId', $candidate['id'])->countAllResults(false)
                ];
                
                $formattedCandidates[] = $formattedCandidate;
            }
            
            // Node.js returns { success: true, message: '...', data: { candidates, pagination } } (matching Node.js)
            $totalPages = (int) ceil($total / $limit);
            return $this->respond([
                'success' => true,
                'message' => 'Candidates retrieved successfully',
                'data' => [
                    'candidates' => $formattedCandidates,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'totalPages' => $totalPages,
                        'hasNext' => $page < $totalPages,
                        'hasPrev' => $page > 1
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve candidates',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/candidates/stats",
     *     tags={"Admin - Candidates"},
     *     summary="Get candidate statistics for dashboard",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Statistics retrieved")
     * )
     */
    public function getCandidateStats()
    {
        try {
            $candidateModel = new CandidateProfile();
            $userModel = new \App\Models\User();
            $candidateSkillModel = new \App\Models\CandidateSkill();
            $candidateDomainModel = new \App\Models\CandidateDomain();
            $skillModel = new \App\Models\Skill();
            $domainModel = new \App\Models\Domain();
            $educationModel = new \App\Models\Education();
            $experienceModel = new \App\Models\Experience();
            
            // Calculate stats (matching Node.js)
            $totalCandidates = $candidateModel->countAllResults(false);
            
            // Active candidates (logged in within 30 days)
            $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
            // Use Query Builder for more control
            $db = \Config\Database::connect();
            $activeCandidates = $db->table('candidate_profiles')
                ->select('candidate_profiles.id')
                ->join('users', 'users.id = candidate_profiles.userId', 'inner')
                ->where('users.lastLoginAt >=', $thirtyDaysAgo)
                ->distinct()
                ->countAllResults(false);
            
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
            
            // Top skills (matching Node.js - use groupBy approach)
            $db = \Config\Database::connect();
            $topSkillsRaw = $db->table('candidate_skills')
                ->select('skillId, COUNT(*) as _count')
                ->groupBy('skillId')
                ->orderBy('_count', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();
            
            // Enrich with skill names (matching Node.js)
            $skillIds = array_column($topSkillsRaw, 'skillId');
            $skills = $skillModel->whereIn('id', $skillIds)->findAll();
            $skillsMap = [];
            foreach ($skills as $skill) {
                $skillsMap[$skill['id']] = $skill;
            }
            
            $topSkills = [];
            foreach ($topSkillsRaw as $stat) {
                $skill = $skillsMap[$stat['skillId']] ?? null;
                $topSkills[] = [
                    'skillId' => $stat['skillId'],
                    'skillName' => $skill ? $skill['name'] : 'Unknown',
                    'count' => (int) $stat['_count']
                ];
            }
            
            // Top domains (matching Node.js - use groupBy approach)
            $topDomainsRaw = $db->table('candidate_domains')
                ->select('domainId, COUNT(*) as _count')
                ->groupBy('domainId')
                ->orderBy('_count', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();
            
            // Enrich with domain names (matching Node.js)
            $domainIds = array_column($topDomainsRaw, 'domainId');
            $domains = $domainModel->whereIn('id', $domainIds)->findAll();
            $domainsMap = [];
            foreach ($domains as $domain) {
                $domainsMap[$domain['id']] = $domain;
            }
            
            $topDomains = [];
            foreach ($topDomainsRaw as $stat) {
                $domain = $domainsMap[$stat['domainId']] ?? null;
                $topDomains[] = [
                    'domainId' => $stat['domainId'],
                    'domainName' => $domain ? $domain['name'] : 'Unknown',
                    'count' => (int) $stat['_count']
                ];
            }
            
            // Node.js returns { success: true, message: '...', data: { overview: {...}, topSkills: [...], topDomains: [...] } }
            return $this->respond([
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data' => [
                    'overview' => [
                        'totalCandidates' => $totalCandidates,
                        'activeCandidates' => $activeCandidates,
                        'candidatesWithResume' => $candidatesWithResume,
                        'candidatesOpenToWork' => $candidatesOpenToWork,
                        'recentCandidates' => $recentCandidates
                    ],
                    'topSkills' => $topSkills,
                    'topDomains' => $topDomains
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'data' => []
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
     *     @OA\Response(response="200", description="Candidate retrieved")
     * )
     */
    public function getCandidateById($candidateProfileId = null)
    {
        // Get candidateProfileId from route parameter or URI segment
        if ($candidateProfileId === null) {
            $candidateProfileId = $this->request->getUri()->getSegment(3);
        }
        
        if (!$candidateProfileId) {
            return $this->fail('Candidate profile ID is required', 400);
        }
        
        try {
            $candidateModel = new CandidateProfile();
            $candidate = $candidateModel->find($candidateProfileId);

            if (!$candidate) {
                return $this->failNotFound('Candidate profile not found');
            }
            
            // Format candidate with user, skills, domains, educations, experiences (matching Node.js)
            $userModel = new \App\Models\User();
            
            // Skip if no userId or user not found
            if (empty($candidate['userId'])) {
                return $this->failNotFound('Candidate profile has no associated user');
            }
            
            $user = $userModel->select('id, firstName, lastName, email, phone, avatar, emailVerified, status, role, createdAt, lastLoginAt')
                ->find($candidate['userId']);
            
            if (!$user) {
                return $this->failNotFound('User not found for this candidate profile');
            }
            
            $candidate['user'] = $user;
            
            // Get skills (matching Node.js - wrapped in { skill: ... })
            $candidateSkillModel = new \App\Models\CandidateSkill();
            $candidateSkills = $candidateSkillModel->where('candidateId', $candidateProfileId)
                ->orderBy('createdAt', 'DESC')
                ->findAll();
            $skills = [];
            foreach ($candidateSkills as $cs) {
                $skillModel = new \App\Models\Skill();
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
            
            // Get domains (matching Node.js - wrapped in { domain: ... })
            $candidateDomainModel = new \App\Models\CandidateDomain();
            $candidateDomains = $candidateDomainModel->where('candidateId', $candidateProfileId)->findAll();
            $domains = [];
            foreach ($candidateDomains as $cd) {
                $domainModel = new \App\Models\Domain();
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
            
            // Get educations (matching Node.js)
            $educationModel = new \App\Models\Education();
            $candidate['educations'] = $educationModel->where('candidateId', $candidateProfileId)
                ->orderBy('startDate', 'DESC')
                ->findAll();
            
            // Get experiences (matching Node.js)
            $experienceModel = new \App\Models\Experience();
            $candidate['experiences'] = $experienceModel->where('candidateId', $candidateProfileId)
                ->orderBy('startDate', 'DESC')
                ->findAll();
            
            // Get certifications (matching Node.js)
            $certificationModel = new \App\Models\Certification();
            $candidate['certifications'] = $certificationModel->where('candidateId', $candidateProfileId)
                ->orderBy('issueDate', 'DESC')
                ->findAll();
            
            // Get languages (matching Node.js - wrapped in { language: ... })
            $candidateLanguageModel = new \App\Models\CandidateLanguage();
            $candidateLanguages = $candidateLanguageModel->where('candidateId', $candidateProfileId)->findAll();
            $languages = [];
            foreach ($candidateLanguages as $cl) {
                $languageModel = new \App\Models\Language();
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
            
            // Get resumes (matching Node.js)
            $resumeModel = new \App\Models\ResumeVersion();
            $candidate['resumes'] = $resumeModel->where('candidateId', $candidateProfileId)
                ->orderBy('version', 'DESC')
                ->findAll();

            return $this->respond([
                'success' => true,
                'message' => 'Candidate retrieved successfully',
                'data' => $candidate
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve candidate',
                'data' => []
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
     *     @OA\Response(response="200", description="Applications retrieved")
     * )
     */
    public function getCandidateApplications($candidateProfileId = null)
    {
        // Get candidateProfileId from route parameter or URI segment
        if ($candidateProfileId === null) {
            $candidateProfileId = $this->request->getUri()->getSegment(3);
        }
        
        if (!$candidateProfileId) {
            return $this->fail('Candidate profile ID is required', 400);
        }
        
        try {
            $candidateModel = new CandidateProfile();
            $candidate = $candidateModel->find($candidateProfileId);
            
            if (!$candidate) {
                return $this->failNotFound('Candidate profile not found');
            }
            
            // Get applications for this candidate (matching Node.js - order by appliedAt)
            $applicationModel = new \App\Models\Application();
            $applications = $applicationModel->where('candidateId', $candidate['userId'])
                ->orderBy('appliedAt', 'DESC')
                ->findAll();
            
            // Format applications with job details and statusHistory (matching Node.js)
            $jobModel = new \App\Models\Job();
            $companyModel = new \App\Models\Company();
            $categoryModel = new \App\Models\JobCategory();
            $statusHistoryModel = new \App\Models\ApplicationStatusHistory();
            
            $formattedApplications = [];
            foreach ($applications as $application) {
                $formattedApp = $application;
                
                // Get job details
                $job = $jobModel->find($application['jobId']);
                if ($job) {
                    $company = $companyModel->select('id, name, logo, location')
                        ->find($job['companyId']);
                    $job['company'] = $company;
                    $job['category'] = $categoryModel->find($job['categoryId']);
                    $formattedApp['job'] = $job;
                }
                
                // Get status history (matching Node.js)
                $statusHistory = $statusHistoryModel->where('applicationId', $application['id'])
                    ->orderBy('changedAt', 'DESC')
                    ->findAll();
                $formattedApp['statusHistory'] = $statusHistory;
                
                $formattedApplications[] = $formattedApp;
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Applications retrieved successfully',
                'data' => $formattedApplications
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve applications',
                'data' => []
            ], 500);
        }
    }
}
