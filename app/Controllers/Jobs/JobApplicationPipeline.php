<?php

namespace App\Controllers\Jobs;

use App\Controllers\BaseController;
use App\Models\Job;
use App\Models\CandidateProfile;
use App\Models\Application;
use App\Models\Education;
use App\Models\Experience;
use App\Models\CandidateSkill;
use App\Models\Skill;
use App\Models\User;
use App\Services\ProfileRequirementService;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Job Application Pipeline",
 *     description="Inline profile completion during job application with compliance"
 * )
 */
class JobApplicationPipeline extends BaseController
{
    use NormalizedResponseTrait;

    private ProfileRequirementService $requirementService;

    public function __construct()
    {
        $this->requirementService = new ProfileRequirementService();
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/{jobId}/application-pipeline",
     *     tags={"Job Application Pipeline"},
     *     summary="Get application form data with missing requirements and compliance sections",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="jobId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Application form data retrieved")
     * )
     */
    public function getApplicationFormData(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user || $user->role !== 'CANDIDATE') {
                return $this->fail('Unauthorized', 401);
            }

            // Verify job exists and is active
            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job || $job['status'] !== 'ACTIVE') {
                return $this->failNotFound('Job not found or inactive');
            }

            // Get candidate profile
            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();

            // Get eligibility evaluation (reads job_profile_requirements)
            $eligibility = $this->requirementService->evaluateCandidateForJob($user->id, $jobId);

                log_message('debug', 'Job ID: ' . $jobId);
                log_message('debug', 'Eligibility: ' . json_encode($eligibility));
                log_message('debug', 'Missing Keys: ' . json_encode($eligibility['missingKeys'] ?? []));

            // Build comprehensive profile snapshot
            $profileData = $this->buildComprehensiveProfileSnapshot($profile, $user);

            return $this->respond([
                'success' => true,
                'data' => [
                    'job' => [
                        'id' => $job['id'],
                        'title' => $job['title'],
                        'companyId' => $job['companyId'],
                    ],
                    'eligibility' => $eligibility,
                    'profileSnapshot' => $profileData,
                    'requirements' => $eligibility['missingKeys'] ?? [],
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'getApplicationFormData error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to load application form',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/jobs/{jobId}/application-pipeline/submit",
     *     tags={"Job Application Pipeline"},
     *     summary="Submit application with inline profile updates and compliance data",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="201", description="Application submitted")
     * )
     */
    public function submitWithInlineUpdates(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user || $user->role !== 'CANDIDATE') {
                return $this->fail('Unauthorized', 401);
            }

            $data = $this->request->getJSON(true);

            // Validate job
            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job || $job['status'] !== 'ACTIVE') {
                return $this->failNotFound('Job not found or inactive');
            }

            // Get profile
            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();
            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }

            // Validate required application fields
            if (empty(trim($data['coverLetter'] ?? ''))) {
                return $this->fail('Cover letter is required', 400);
            }
            if (strlen($data['coverLetter']) < 50) {
                return $this->fail('Cover letter must be at least 50 characters', 400);
            }
            if (empty(trim($data['expectedSalary'] ?? ''))) {
                return $this->fail('Expected salary is required', 400);
            }
            if (empty($data['availableStartDate'])) {
                return $this->fail('Available start date is required', 400);
            }
            if (empty($data['privacyConsent'])) {
                return $this->fail('Privacy consent is required', 400);
            }

            $db = \Config\Database::connect();
            $db->transStart();

            try {
                // 1. Update profile with all new data
                $this->processAllProfileUpdates($profile, $user, $data);

                // 2. Create application
                $applicationId = $this->createApplication($jobId, $user, $profile, $data);

                // 3. Create comprehensive snapshot
                $this->createComprehensiveSnapshot($applicationId, $profile, $user, $data);

                $db->transComplete();

                if ($db->transStatus() === false) {
                    throw new \Exception('Transaction failed');
                }

                // 4. Send emails (non-blocking)
                $this->sendApplicationEmails($applicationId, $job, $user, $profile);

                return $this->respondCreated([
                    'success' => true,
                    'message' => 'Application submitted successfully',
                    'data' => ['applicationId' => $applicationId]
                ]);

            } catch (\Exception $e) {
                $db->transRollback();
                throw $e;
            }

        } catch (\Exception $e) {
            log_message('error', 'submitWithInlineUpdates error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to submit application: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Build comprehensive profile snapshot including compliance sections
     */
    private function buildComprehensiveProfileSnapshot(?array $profile, object $user): array
    {
        if (!$profile) {
            return [
                'user' => [
                    'firstName' => $user->firstName ?? '',
                    'lastName' => $user->lastName ?? '',
                    'email' => $user->email ?? '',
                    'phone' => null,
                ],
                'basic' => [],
                'skills' => [],
                'experience' => [],
                'education' => [],
                'personalInfo' => null,
                'publications' => [],
                'memberships' => [],
                'clearances' => [],
                'courses' => [],
                'referees' => [],
                'files' => [],
            ];
        }

        $userModel = new User();
        $userData = $userModel->select('phone')->find($user->id);

        $db = \Config\Database::connect();

        // Fetch all profile sections
        $skills = $db->table('candidate_skills')
            ->select('candidate_skills.id, candidate_skills.level, candidate_skills.yearsOfExp, skills.name, skills.id as skillId')
            ->join('skills', 'skills.id = candidate_skills.skillId', 'inner')
            ->where('candidate_skills.candidateId', $profile['id'])
            ->get()->getResultArray();

        $experiences = $db->table('experiences')
            ->where('candidateId', $profile['id'])
            ->orderBy('startDate', 'DESC')
            ->get()->getResultArray();

        $educations = $db->table('educations')
            ->where('candidateId', $profile['id'])
            ->orderBy('startDate', 'DESC')
            ->get()->getResultArray();

        $personalInfo = $db->table('candidate_personal_info')
            ->where('candidateId', $profile['id'])
            ->get()->getRowArray();

        $publications = $db->table('candidate_publications')
            ->where('candidateId', $profile['id'])
            ->orderBy('year', 'DESC')
            ->get()->getResultArray();

        $memberships = $db->table('candidate_professional_memberships')
            ->where('candidateId', $profile['id'])
            ->orderBy('createdAt', 'DESC')
            ->get()->getResultArray();

        $clearances = $db->table('candidate_clearances')
            ->where('candidateId', $profile['id'])
            ->orderBy('issueDate', 'DESC')
            ->get()->getResultArray();

        $courses = $db->table('candidate_courses')
            ->where('candidateId', $profile['id'])
            ->orderBy('year', 'DESC')
            ->get()->getResultArray();

        $referees = $db->table('candidate_referees')
            ->where('candidateId', $profile['id'])
            ->orderBy('createdAt', 'DESC')
            ->get()->getResultArray();

        $files = $db->table('candidate_files')
            ->where('candidateId', $profile['id'])
            ->orderBy('createdAt', 'DESC')
            ->get()->getResultArray();

        return [
            'user' => [
                'firstName' => $user->firstName ?? '',
                'lastName' => $user->lastName ?? '',
                'email' => $user->email ?? '',
                'phone' => $userData['phone'] ?? null,
            ],
            'basic' => [
                'title' => $profile['title'] ?? null,
                'location' => $profile['location'] ?? null,
                'bio' => $profile['bio'] ?? null,
            ],
            'skills' => $skills,
            'experience' => $experiences,
            'education' => $educations,
            'personalInfo' => $personalInfo,
            'publications' => $publications,
            'memberships' => $memberships,
            'clearances' => $clearances,
            'courses' => $courses,
            'referees' => $referees,
            'files' => $files,
            'resumeUrl' => $profile['resumeUrl'] ?? null,
        ];
    }

    /**
     * Process ALL profile updates including compliance sections
     */
    private function processAllProfileUpdates(array $profile, object $user, array $data): void
    {
        $profileModel = new CandidateProfile();
        $userModel = new User();

        // Basic fields
        $basicUpdates = [];
        if (!empty($data['basic'])) {
            if (isset($data['basic']['title'])) $basicUpdates['title'] = trim($data['basic']['title']);
            if (isset($data['basic']['location'])) $basicUpdates['location'] = trim($data['basic']['location']);
            if (isset($data['basic']['bio'])) $basicUpdates['bio'] = trim($data['basic']['bio']);
        }

        if (!empty($basicUpdates)) {
            $profileModel->update($profile['id'], $basicUpdates);
        }

        // Phone (users table)
        if (!empty($data['basic']['phone'])) {
            $userModel->update($user->id, ['phone' => trim($data['basic']['phone'])]);
        }

        // Skills
        if (!empty($data['skills']) && is_array($data['skills'])) {
            $this->processSkills($profile['id'], $data['skills']);
        }

        // Experience
        if (!empty($data['experience']) && is_array($data['experience'])) {
            $this->processExperience($profile['id'], $data['experience']);
        }

        // Education
        if (!empty($data['education']) && is_array($data['education'])) {
            $this->processEducation($profile['id'], $data['education']);
        }

        // Personal Info
        if (!empty($data['personalInfo'])) {
            $this->processPersonalInfo($profile['id'], $data['personalInfo']);
        }

        // COMPLIANCE SECTIONS
        if (!empty($data['publications']) && is_array($data['publications'])) {
            $this->processPublications($profile['id'], $data['publications']);
        }

        if (!empty($data['memberships']) && is_array($data['memberships'])) {
            $this->processMemberships($profile['id'], $data['memberships']);
        }

        if (!empty($data['clearances']) && is_array($data['clearances'])) {
            $this->processClearances($profile['id'], $data['clearances']);
        }

        if (!empty($data['courses']) && is_array($data['courses'])) {
            $this->processCourses($profile['id'], $data['courses']);
        }

        if (!empty($data['referees']) && is_array($data['referees'])) {
            $this->processReferees($profile['id'], $data['referees']);
        }
    }

    // Skills
    private function processSkills(string $candidateId, array $skills): void
    {
        $skillModel = new Skill();
        $candidateSkillModel = new CandidateSkill();

        foreach ($skills as $skillData) {
            $skillName = trim($skillData['skillName'] ?? '');
            if (empty($skillName)) continue;

            $slug = strtolower(preg_replace('/\s+/', '-', preg_replace('/[^a-z0-9\s-]/', '', $skillName)));
            $skill = $skillModel->where('slug', $slug)->orWhere('name', $skillName)->first();

            if (!$skill) {
                $skillId = uniqid('skill_');
                $skillModel->insert([
                    'id' => $skillId,
                    'name' => $skillName,
                    'slug' => $slug,
                    'isActive' => true
                ]);
                $skill = $skillModel->find($skillId);
            }

            $existing = $candidateSkillModel
                ->where('candidateId', $candidateId)
                ->where('skillId', $skill['id'])
                ->first();

            if (!$existing) {
                $candidateSkillModel->insert([
                    'id' => uniqid('cskill_'),
                    'candidateId' => $candidateId,
                    'skillId' => $skill['id'],
                    'level' => $skillData['level'] ?? null,
                    'yearsOfExp' => $skillData['yearsOfExp'] ?? null,
                    'createdAt' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    // Experience 
    private function processExperience(string $candidateId, array $experiences): void
    {
        $expModel = new Experience();

        foreach ($experiences as $exp) {
            if (empty($exp['title']) || empty($exp['company']) || empty($exp['startDate'])) {
                continue;
            }

            $expModel->insert([
                'id' => uniqid('exp_'),
                'candidateId' => $candidateId,
                'title' => trim($exp['title']),
                'company' => trim($exp['company']),
                'location' => trim($exp['location'] ?? ''),
                'startDate' => date('Y-m-d', strtotime($exp['startDate'])),
                'endDate' => !empty($exp['endDate']) ? date('Y-m-d', strtotime($exp['endDate'])) : null,
                'isCurrent' => (int)($exp['isCurrent'] ?? 0),
                'description' => trim($exp['description'] ?? ''),
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Education
    private function processEducation(string $candidateId, array $educations): void
    {
        $eduModel = new Education();

        foreach ($educations as $edu) {
            if (empty($edu['degree']) || empty($edu['institution']) || empty($edu['fieldOfStudy']) || empty($edu['startDate'])) {
                continue;
            }

            $eduModel->insert([
                'id' => uniqid('edu_'),
                'candidateId' => $candidateId,
                'degree' => trim($edu['degree']),
                'institution' => trim($edu['institution']),
                'fieldOfStudy' => trim($edu['fieldOfStudy']),
                'startDate' => date('Y-m-d', strtotime($edu['startDate'])),
                'endDate' => !empty($edu['endDate']) ? date('Y-m-d', strtotime($edu['endDate'])) : null,
                'isCurrent' => (int)($edu['isCurrent'] ?? 0),
                'grade' => trim($edu['grade'] ?? ''),
                'description' => trim($edu['description'] ?? ''),
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Personal Info
    private function processPersonalInfo(string $candidateId, array $pinfo): void
    {
        $pinfoModel = new \App\Models\CandidatePersonalInfo();
        $existing = $pinfoModel->where('candidateId', $candidateId)->first();

        $payload = [
            'candidateId' => $candidateId,
            'fullName' => trim($pinfo['fullName'] ?? ''),
            'dob' => date('Y-m-d', strtotime($pinfo['dob'] ?? 'now')),
            'gender' => $pinfo['gender'] ?? 'M',
            'idNumber' => trim($pinfo['idNumber'] ?? ''),
            'nationality' => trim($pinfo['nationality'] ?? ''),
            'countyOfOrigin' => trim($pinfo['countyOfOrigin'] ?? ''),
            'plwd' => (int)($pinfo['plwd'] ?? 0),
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $pinfoModel->update($existing['id'], $payload);
        } else {
            $payload['id'] = uniqid('pinfo_');
            $pinfoModel->insert($payload);
        }
    }

    // Publications
    private function processPublications(string $candidateId, array $publications): void
    {
        $pubModel = new \App\Models\CandidatePublication();

        foreach ($publications as $pub) {
            if (empty($pub['title']) || empty($pub['year'])) continue;

            $pubModel->insert([
                'id' => uniqid('pub_'),
                'candidateId' => $candidateId,
                'title' => trim($pub['title']),
                'type' => $pub['type'] ?? 'Journal',
                'journalOrPublisher' => trim($pub['journalOrPublisher'] ?? ''),
                'year' => (int)$pub['year'],
                'link' => trim($pub['link'] ?? ''),
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Memberships
    private function processMemberships(string $candidateId, array $memberships): void
    {
        $memModel = new \App\Models\CandidateMembership();

        foreach ($memberships as $mem) {
            if (empty($mem['bodyName'])) continue;

            $memModel->insert([
                'id' => uniqid('mem_'),
                'candidateId' => $candidateId,
                'bodyName' => trim($mem['bodyName']),
                'membershipNumber' => trim($mem['membershipNumber'] ?? ''),
                'isActive' => (int)($mem['isActive'] ?? 1),
                'goodStanding' => (int)($mem['goodStanding'] ?? 1),
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Clearances
    private function processClearances(string $candidateId, array $clearances): void
    {
        $clearModel = new \App\Models\CandidateClearance();

        foreach ($clearances as $clear) {
            if (empty($clear['type']) || empty($clear['issueDate'])) continue;

            $clearModel->insert([
                'id' => uniqid('clear_'),
                'candidateId' => $candidateId,
                'type' => trim($clear['type']),
                'certificateNumber' => trim($clear['certificateNumber'] ?? ''),
                'issueDate' => date('Y-m-d', strtotime($clear['issueDate'])),
                'expiryDate' => !empty($clear['expiryDate']) ? date('Y-m-d', strtotime($clear['expiryDate'])) : null,
                'status' => $clear['status'] ?? 'VALID',
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Courses
    private function processCourses(string $candidateId, array $courses): void
    {
        $courseModel = new \App\Models\CandidateCourse();

        foreach ($courses as $course) {
            if (empty($course['name']) || empty($course['institution']) || empty($course['durationWeeks']) || empty($course['year'])) continue;

            $courseModel->insert([
                'id' => uniqid('crs_'),
                'candidateId' => $candidateId,
                'name' => trim($course['name']),
                'institution' => trim($course['institution']),
                'durationWeeks' => (int)$course['durationWeeks'],
                'year' => (int)$course['year'],
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Referees
    private function processReferees(string $candidateId, array $referees): void
    {
        $refModel = new \App\Models\CandidateReferee();

        foreach ($referees as $ref) {
            if (empty($ref['name'])) continue;

            $refModel->insert([
                'id' => uniqid('ref_'),
                'candidateId' => $candidateId,
                'name' => trim($ref['name']),
                'position' => trim($ref['position'] ?? ''),
                'organization' => trim($ref['organization'] ?? ''),
                'phone' => trim($ref['phone'] ?? ''),
                'email' => trim($ref['email'] ?? ''),
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function createApplication(string $jobId, object $user, array $profile, array $data): string
    {
        $applicationModel = new Application();

        $applicationId = uniqid('app_');
        $applicationModel->insert([
            'id' => $applicationId,
            'jobId' => $jobId,
            'candidateId' => $user->id,
            'coverLetter' => trim($data['coverLetter'] ?? ''),
            'resumeUrl' => $profile['resumeUrl'] ?? null,
            'portfolioUrl' => trim($data['portfolioUrl'] ?? ''),
            'expectedSalary' => trim($data['expectedSalary'] ?? ''),
            'availableStartDate' => date('Y-m-d H:i:s', strtotime($data['availableStartDate'] ?? 'now')),
            'privacyConsent' => (int)($data['privacyConsent'] ?? 0),
            'status' => 'PENDING',
            'isActive' => true,
            'appliedAt' => date('Y-m-d H:i:s'),
        ]);

        $statusHistoryModel = new \App\Models\ApplicationStatusHistory();
        $statusHistoryModel->insert([
            'id' => uniqid('status_'),
            'applicationId' => $applicationId,
            'toStatus' => 'PENDING',
            'changedAt' => date('Y-m-d H:i:s'),
        ]);

        return $applicationId;
    }

    /**
     * Create comprehensive snapshot including ALL compliance sections
     */
    private function createComprehensiveSnapshot(string $applicationId, array $profile, object $user, array $data): void
    {
        $db = \Config\Database::connect();

        $snapshot = [
            'applicationId' => $applicationId,
            'candidateId' => $profile['id'],
            'userId' => $user->id,
            'submittedAt' => date('Y-m-d H:i:s'),
            'basic' => [
                'firstName' => $user->firstName ?? '',
                'lastName' => $user->lastName ?? '',
                'email' => $user->email ?? '',
                'phone' => $data['basic']['phone'] ?? null,
                'title' => $profile['title'] ?? null,
                'location' => $profile['location'] ?? null,
                'bio' => $profile['bio'] ?? null,
            ],
            'skills' => $db->table('candidate_skills')
                ->select('candidate_skills.*, skills.name as skillName')
                ->join('skills', 'skills.id = candidate_skills.skillId', 'inner')
                ->where('candidate_skills.candidateId', $profile['id'])
                ->get()->getResultArray(),
            'experience' => $db->table('experiences')
                ->where('candidateId', $profile['id'])
                ->get()->getResultArray(),
            'education' => $db->table('educations')
                ->where('candidateId', $profile['id'])
                ->get()->getResultArray(),
            'personalInfo' => $db->table('candidate_personal_info')
                ->where('candidateId', $profile['id'])
                ->get()->getRowArray(),
            'publications' => $db->table('candidate_publications')
                ->where('candidateId', $profile['id'])
                ->get()->getResultArray(),
            'memberships' => $db->table('candidate_professional_memberships')
                ->where('candidateId', $profile['id'])
                ->get()->getResultArray(),
            'clearances' => $db->table('candidate_clearances')
                ->where('candidateId', $profile['id'])
                ->get()->getResultArray(),
            'courses' => $db->table('candidate_courses')
                ->where('candidateId', $profile['id'])
                ->get()->getResultArray(),
            'referees' => $db->table('candidate_referees')
                ->where('candidateId', $profile['id'])
                ->get()->getResultArray(),
            'files' => $db->table('candidate_files')
                ->where('candidateId', $profile['id'])
                ->get()->getResultArray(),
            'applicationDetails' => [
                'coverLetter' => $data['coverLetter'] ?? '',
                'portfolioUrl' => $data['portfolioUrl'] ?? '',
                'expectedSalary' => $data['expectedSalary'] ?? '',
                'availableStartDate' => $data['availableStartDate'] ?? '',
            ]
        ];

        $db->table('application_snapshots')->insert([
            'id' => uniqid('snapshot_'),
            'applicationId' => $applicationId,
            'snapshotData' => json_encode($snapshot),
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    private function sendApplicationEmails(string $applicationId, array $job, object $user, array $profile): void
    {
        try {
            $emailHelper = new \App\Libraries\EmailHelper();
            $companyModel = new \App\Models\Company();
            $company = $companyModel->find($job['companyId']);

            $emailHelper->sendApplicationReceivedEmail([
                'applicationId' => $applicationId,
                'candidate' => [
                    'firstName' => $user->firstName ?? '',
                    'lastName' => $user->lastName ?? '',
                    'email' => $user->email ?? ''
                ],
                'job' => [
                    'title' => $job['title'] ?? '',
                    'company' => [
                        'name' => $company['name'] ?? 'Company'
                    ]
                ],
            ]);

            $emailHelper->notifyAdminNewApplication([
                'applicationId' => $applicationId,
                'candidate' => [
                    'firstName' => $user->firstName ?? '',
                    'lastName' => $user->lastName ?? '',
                    'email' => $user->email ?? ''
                ],
                'job' => [
                    'title' => $job['title'] ?? '',
                    'company' => [
                        'name' => $company['name'] ?? 'Company'
                    ]
                ],
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Failed to send application emails: ' . $e->getMessage());
        }
    }
}
