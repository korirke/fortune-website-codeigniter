<?php

/**
 * NotificationService::createApplicationNotification() so that in-app
 * notification records are written for the job poster + HR/admin users.
 */

namespace App\Controllers\Jobs;

use App\Controllers\BaseController;
use App\Models\Job;
use App\Models\CandidateProfile;
use App\Models\Application;
use App\Models\Company;
use App\Services\ProfileRequirementService;
use App\Services\NotificationService;
use App\Traits\NormalizedResponseTrait;

class JobApplicationPipeline extends BaseController
{
    use NormalizedResponseTrait;

    private ProfileRequirementService $requirementService;

    public function __construct()
    {
        $this->requirementService = new ProfileRequirementService();
    }

    // =========================================================
    // PUBLIC: GET /jobs/{jobId}/application-pipeline
    // =========================================================
    public function getApplicationFormData(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user || ($user->role ?? null) !== 'CANDIDATE') {
                return $this->fail('Unauthorized', 401);
            }

            $db = \Config\Database::connect();

            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job || ($job['status'] ?? null) !== 'ACTIVE') {
                return $this->failNotFound('Job not found or inactive');
            }

            // Prevent duplicate application (non-withdrawn)
            $existing = $db->table('applications')
                ->where('jobId', $jobId)
                ->where('candidateId', $user->id)
                ->where('status !=', 'WITHDRAWN')
                ->get()
                ->getRowArray();

            if ($existing) {
                return $this->respond([
                    'success' => false,
                    'message' => 'You have already applied for this job',
                    'data' => []
                ], 409);
            }

            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();

            $eligibility = $this->requirementService->evaluateCandidateForJob($user->id, $jobId);
            $profileData = $this->buildComprehensiveProfileSnapshot($profile, $user);
            $jobConfig = $this->requirementService->getJobApplicationConfig($jobId);

            return $this->respond([
                'success' => true,
                'data' => [
                    'job' => [
                        'id' => $job['id'],
                        'title' => $job['title'],
                        'companyId' => $job['companyId'],
                        'description' => $job['description'] ?? null,
                    ],
                    'eligibility' => $eligibility,
                    'profileSnapshot' => $profileData,
                    'requirements' => $eligibility['missingKeys'] ?? [],
                    'applicationConfig' => $jobConfig,
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

    // =========================================================
    // PUBLIC: POST /jobs/{jobId}/application-pipeline/submit
    // =========================================================
    public function submitWithInlineUpdates(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user || ($user->role ?? null) !== 'CANDIDATE') {
                return $this->fail('Unauthorized', 401);
            }

            $data = $this->request->getJSON(true);
            if (!is_array($data)) {
                return $this->fail('Invalid request body', 400);
            }

            $db = \Config\Database::connect();

            // ---- Validate job ----
            $jobModel = new Job();
            $job = $jobModel->find($jobId);
            if (!$job || ($job['status'] ?? null) !== 'ACTIVE') {
                return $this->failNotFound('Job not found or inactive');
            }

            // ---- Prevent duplicate application ----
            $existing = $db->table('applications')
                ->where('jobId', $jobId)
                ->where('candidateId', $user->id)
                ->where('status !=', 'WITHDRAWN')
                ->get()
                ->getRowArray();

            if ($existing) {
                return $this->respond([
                    'success' => false,
                    'message' => 'You have already applied for this job',
                    'data' => []
                ], 409);
            }

            // ---- Load candidate profile ----
            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();
            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }

            // ---- Required field validation (align with frontend) ----
            $coverLetter = trim((string) ($data['coverLetter'] ?? ''));
            if ($coverLetter === '') {
                return $this->fail('Cover letter is required', 400);
            }
            if (mb_strlen($coverLetter) < 150) {
                return $this->fail('Cover letter must be at least 150 characters', 400);
            }

            $expectedSalary = trim((string) ($data['expectedSalary'] ?? ''));
            if ($expectedSalary === '') {
                return $this->fail('Expected salary is required', 400);
            }

            $availableStartDate = (string) ($data['availableStartDate'] ?? '');
            if (trim($availableStartDate) === '') {
                return $this->fail('Available start date is required', 400);
            }
            $startTs = strtotime($availableStartDate);
            if ($startTs === false) {
                return $this->fail('Invalid available start date', 400);
            }
            $today = date('Y-m-d');
            if (date('Y-m-d', $startTs) < $today) {
                return $this->fail('Available start date cannot be in the past', 400);
            }

            if (empty($data['privacyConsent'])) {
                return $this->fail('Privacy consent is required', 400);
            }

            // Resume required (must exist on profile, since resume upload saves immediately)
            $profileResumeUrl = $profile['resumeUrl'] ?? null;
            if (!$profileResumeUrl) {
                return $this->fail('Please upload your resume before submitting the application', 400);
            }

            $jobConfig = $this->requirementService->getJobApplicationConfig($jobId);
            $minReferees = (int) ($jobConfig['refereesRequired'] ?? 0);
            if ($minReferees > 0) {
                $refereesProvided = count($data['referees'] ?? []);
                $existingRefereeCount = $db->table('candidate_referees')
                    ->where('candidateId', $profile['id'])
                    ->countAllResults();
                $totalReferees = $existingRefereeCount + $refereesProvided;
                if ($totalReferees < $minReferees) {
                    return $this->fail("At least {$minReferees} referee(s) are required for this job", 400);
                }
            }
            // ─────────────────────────────────────────────────────────────────────

            // ---- Transaction: DB writes only ----
            $db->transStart();

            try {
                // 1) Apply idempotent inline updates (if user did not Save per section)
                $this->processAllProfileUpdates($db, $profile, $user, $data);

                // 2) Reload profile after updates
                $profile = $profileModel->where('userId', $user->id)->first() ?? $profile;

                // 3) Create application + atomic job increment + status history
                $applicationId = $this->createApplication($db, $jobId, $user, $profile, $data);

                // 4) Questionnaire answers
                $this->saveJobQuestionnaireAnswers($db, $applicationId, $jobId, $user->id, $data);

                // 5) Snapshot
                $this->createComprehensiveSnapshot($db, $applicationId, $profile, $user, $data);

                $db->transComplete();

                if ($db->transStatus() === false) {
                    throw new \Exception('Transaction failed');
                }

            } catch (\Exception $e) {
                $db->transRollback();
                throw $e;
            }

            // 6) Emails AFTER commit (must never rollback persisted application)
            $this->sendApplicationEmails($applicationId, $job, $user->id, $profile, $data);
            $this->triggerApplicationNotification($applicationId, $job, $user);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Application submitted successfully',
                'data' => ['applicationId' => $applicationId]
            ]);

        } catch (\Exception $e) {
            log_message('error', 'submitWithInlineUpdates error: ' . $e->getMessage());
            log_message('error', 'Stack: ' . $e->getTraceAsString());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to submit application: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    // =========================================================
    // PRIVATE: Trigger in-app notification 
    // =========================================================
    private function triggerApplicationNotification(string $applicationId, array $job, object $user): void
    {
        try {
            $db = \Config\Database::connect();
            $userRow = $db->table('users')
                ->select('firstName, lastName')
                ->where('id', $user->id)
                ->get()
                ->getRowArray();

            $firstName = $userRow['firstName'] ?? '';
            $lastName = $userRow['lastName'] ?? '';

            $notifService = new NotificationService();
            $notifService->createApplicationNotification(
                $job['id'],
                $applicationId,
                $firstName,
                $lastName,
                $job['title'],
                $job['companyId'],
                $job['postedById']
            );
        } catch (\Throwable $e) {
            log_message('error', '[JobApplicationPipeline] triggerApplicationNotification error: ' . $e->getMessage());
        }
    }

    // =========================================================
    // PRIVATE: Create application record + atomic increment job count
    // =========================================================
    private function createApplication(
        $db,
        string $jobId,
        object $user,
        array $profile,
        array $data
    ): string {
        $applicationId = uniqid('app_');
        $now = date('Y-m-d H:i:s');

        $availableStart = date('Y-m-d H:i:s', strtotime((string) ($data['availableStartDate'] ?? 'now')));

        $currentSalary = trim((string) ($data['currentSalary'] ?? ''));

        $db->table('applications')->insert([
            'id' => $applicationId,
            'jobId' => $jobId,
            'candidateId' => $user->id,
            'coverLetter' => trim((string) ($data['coverLetter'] ?? '')),
            'resumeUrl' => $profile['resumeUrl'] ?? null,
            'portfolioUrl' => trim((string) ($data['portfolioUrl'] ?? '')),
            'expectedSalary' => trim((string) ($data['expectedSalary'] ?? '')),
            'currentSalary' => $currentSalary !== '' ? $currentSalary : null,
            'availableStartDate' => $availableStart,
            'privacyConsent' => (int) !!($data['privacyConsent'] ?? false),
            'status' => 'PENDING',
            'isActive' => 1,
            'appliedAt' => $now,
            'updatedAt' => $now,
        ]);

        // Initial status history entry
        $db->table('application_status_history')->insert([
            'id' => uniqid('status_'),
            'applicationId' => $applicationId,
            'fromStatus' => null,
            'toStatus' => 'PENDING',
            'changedBy' => $user->id,
            'changedAt' => $now,
        ]);

        // Atomic increment (safe under concurrency)
        $db->table('jobs')
            ->set('applicationCount', 'applicationCount + 1', false)
            ->where('id', $jobId)
            ->update();

        return $applicationId;
    }

    // =========================================================
    // PRIVATE: Save questionnaire answers
    // =========================================================
    private function saveJobQuestionnaireAnswers(
        $db,
        string $applicationId,
        string $jobId,
        string $candidateUserId,
        array $data
    ): void {
        $questionnaire = $db->table('job_questionnaires')
            ->where('jobId', $jobId)
            ->where('isActive', 1)
            ->get()
            ->getRowArray();

        if (!$questionnaire) {
            return;
        }

        $questions = $db->table('job_questions')
            ->where('jobId', $jobId)
            ->orderBy('sortOrder', 'ASC')
            ->get()
            ->getResultArray();

        if (empty($questions)) {
            return;
        }

        $submitted = $data['questionnaireAnswers'] ?? [];
        if (!is_array($submitted))
            $submitted = [];

        $submittedByQid = [];
        foreach ($submitted as $a) {
            $qid = $a['questionId'] ?? null;
            if ($qid)
                $submittedByQid[$qid] = $a;
        }

        // Validate required
        $errors = [];
        foreach ($questions as $q) {
            $qid = $q['id'];
            $required = (int) ($q['isRequired'] ?? 0) === 1;
            if (!$required)
                continue;

            $a = $submittedByQid[$qid] ?? null;
            $type = $q['type'] ?? 'OPEN_ENDED';

            if (!$a) {
                $errors[$qid] = 'Answer is required';
                continue;
            }

            if ($type === 'YES_NO') {
                $raw = $a['answerBool'] ?? null;
                if ($raw === null || $raw === '')
                    $errors[$qid] = 'Yes/No answer is required';
            } else {
                $txt = trim((string) ($a['answerText'] ?? ''));
                if ($txt === '')
                    $errors[$qid] = 'Text answer is required';
            }
        }

        if (!empty($errors)) {
            throw new \Exception('Questionnaire validation failed: ' . json_encode($errors));
        }

        // Clear then insert
        $db->table('application_question_answers')
            ->where('applicationId', $applicationId)
            ->delete();

        $now = date('Y-m-d H:i:s');
        $mirrorRows = [];

        foreach ($questions as $q) {
            $qid = $q['id'];
            $type = $q['type'] ?? 'OPEN_ENDED';
            $a = $submittedByQid[$qid] ?? null;

            if (!$a)
                continue;

            $answerText = null;
            $answerBool = null;

            if ($type === 'YES_NO') {
                $raw = $a['answerBool'] ?? null;
                if ($raw === true || $raw === 1 || $raw === '1')
                    $answerBool = 1;
                if ($raw === false || $raw === 0 || $raw === '0')
                    $answerBool = 0;
            } else {
                $answerText = trim((string) ($a['answerText'] ?? ''));
            }

            $db->table('application_question_answers')->insert([
                'id' => uniqid('aqa_'),
                'applicationId' => $applicationId,
                'jobId' => $jobId,
                'candidateId' => $candidateUserId,
                'questionId' => $qid,
                'answerText' => $answerText,
                'answerBool' => $answerBool,
                'createdAt' => $now,
            ]);

            $mirrorRows[] = [
                'questionId' => $qid,
                'questionText' => $q['questionText'] ?? '',
                'type' => $type,
                'answerText' => $answerText,
                'answerBool' => $answerBool === null ? null : (bool) $answerBool,
                'isRequired' => (int) ($q['isRequired'] ?? 0) === 1,
                'sortOrder' => (int) ($q['sortOrder'] ?? 0),
            ];
        }

        $mirror = [
            'questionnaire' => [
                'id' => $questionnaire['id'],
                'jobId' => $questionnaire['jobId'],
                'title' => $questionnaire['title'] ?? 'Screening Questions',
                'description' => $questionnaire['description'] ?? null,
            ],
            'questionnaireAnswers' => $mirrorRows,
            'savedAt' => $now,
        ];

        $json = json_encode($mirror, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \Exception('Failed to JSON-encode questionnaire answers');
        }

        $db->table('applications')
            ->where('id', $applicationId)
            ->update([
                'answers' => $json,
                'updatedAt' => $now,
            ]);
    }

    // =========================================================
    // PRIVATE: Build snapshot for GET form endpoint
    // =========================================================
    private function buildComprehensiveProfileSnapshot(?array $profile, object $user): array
    {
        $db = \Config\Database::connect();

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
                'resumeUrl' => null,
            ];
        }

        $userRow = $db->table('users')
            ->select('phone, firstName, lastName')
            ->where('id', $user->id)
            ->get()
            ->getRowArray();

        $pid = $profile['id'];

        $skills = $db->table('candidate_skills')
            ->select('candidate_skills.id, candidate_skills.level, candidate_skills.yearsOfExp, skills.name, skills.id as skillId')
            ->join('skills', 'skills.id = candidate_skills.skillId', 'inner')
            ->where('candidate_skills.candidateId', $pid)
            ->get()->getResultArray();

        $experiences = $db->table('experiences')
            ->where('candidateId', $pid)
            ->orderBy('startDate', 'DESC')
            ->get()->getResultArray();

        $educations = $db->table('educations')
            ->where('candidateId', $pid)
            ->orderBy('startDate', 'DESC')
            ->get()->getResultArray();

        $personalInfo = $db->table('candidate_personal_info')
            ->where('candidateId', $pid)
            ->get()->getRowArray() ?: null;

        $publications = $db->table('candidate_publications')
            ->where('candidateId', $pid)
            ->orderBy('year', 'DESC')
            ->get()->getResultArray();

        $memberships = $db->table('candidate_professional_memberships')
            ->where('candidateId', $pid)
            ->orderBy('createdAt', 'DESC')
            ->get()->getResultArray();

        $clearances = $db->table('candidate_clearances')
            ->where('candidateId', $pid)
            ->orderBy('issueDate', 'DESC')
            ->get()->getResultArray();

        $courses = $db->table('candidate_courses')
            ->where('candidateId', $pid)
            ->orderBy('year', 'DESC')
            ->get()->getResultArray();

        $referees = $db->table('candidate_referees')
            ->select('id, candidateId, name, position, organization, phone, email, relationship, createdAt, updatedAt')
            ->where('candidateId', $pid)
            ->orderBy('createdAt', 'DESC')
            ->get()->getResultArray();

        $files = $db->table('candidate_files')
            ->where('candidateId', $pid)
            ->orderBy('createdAt', 'DESC')
            ->get()->getResultArray();

        return [
            'user' => [
                'firstName' => $userRow['firstName'] ?? $user->firstName ?? '',
                'lastName' => $userRow['lastName'] ?? $user->lastName ?? '',
                'email' => $user->email ?? '',
                'phone' => $userRow['phone'] ?? null,
            ],

            'basic' => [
                'title' => $profile['title'] ?? null,
                'location' => $profile['location'] ?? null,
                'bio' => $profile['bio'] ?? null,
                'portfolioUrl' => $profile['portfolioUrl'] ?? null,
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

    // =========================================================
    // PRIVATE: Idempotent inline updates (dedupe + batch insert)
    // =========================================================
    private function processAllProfileUpdates($db, array $profile, object $user, array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $candidateProfileId = $profile['id'];

        // ---- Basic updates -> candidate_profiles
        $basicUpdates = [];
        $basic = $data['basic'] ?? null;
        if (is_array($basic)) {
            if (array_key_exists('title', $basic))
                $basicUpdates['title'] = trim((string) $basic['title']);
            if (array_key_exists('location', $basic))
                $basicUpdates['location'] = trim((string) $basic['location']);
            if (array_key_exists('bio', $basic))
                $basicUpdates['bio'] = trim((string) $basic['bio']);
            if (array_key_exists('portfolioUrl', $basic))
                $basicUpdates['portfolioUrl'] = trim((string) $basic['portfolioUrl']);
        }
        if (!empty($basicUpdates)) {
            $basicUpdates['updatedAt'] = $now;
            $db->table('candidate_profiles')->where('id', $candidateProfileId)->update($basicUpdates);
        }

        // ---- Phone stored in users table
        if (isset($basic['phone']) && trim((string) $basic['phone']) !== '') {
            $db->table('users')->where('id', $user->id)->update([
                'phone' => trim((string) $basic['phone'])
            ]);
        }

        // ---- Skills (dedupe on skillId in candidate_skills)
        if (!empty($data['skills']) && is_array($data['skills'])) {
            $this->processSkillsIdempotent($db, $candidateProfileId, $data['skills']);
        }

        // ---- Experience
        if (!empty($data['experience']) && is_array($data['experience'])) {
            $this->processExperiencesIdempotent($db, $candidateProfileId, $data['experience']);
        }

        // ---- Education
        if (!empty($data['education']) && is_array($data['education'])) {
            $this->processEducationsIdempotent($db, $candidateProfileId, $data['education']);
        }

        // ---- Personal info (upsert)
        if (!empty($data['personalInfo']) && is_array($data['personalInfo'])) {
            $this->processPersonalInfoUpsert($db, $candidateProfileId, $data['personalInfo']);
        }

        // ---- Publications
        if (!empty($data['publications']) && is_array($data['publications'])) {
            $this->processPublicationsIdempotent($db, $candidateProfileId, $data['publications']);
        }

        // ---- Memberships
        if (!empty($data['memberships']) && is_array($data['memberships'])) {
            $this->processMembershipsIdempotent($db, $candidateProfileId, $data['memberships']);
        }

        // ---- Clearances
        if (!empty($data['clearances']) && is_array($data['clearances'])) {
            $this->processClearancesIdempotent($db, $candidateProfileId, $data['clearances']);
        }

        // ---- Courses
        if (!empty($data['courses']) && is_array($data['courses'])) {
            $this->processCoursesIdempotent($db, $candidateProfileId, $data['courses']);
        }

        // ---- Referees
        if (!empty($data['referees']) && is_array($data['referees'])) {
            $this->processRefereesIdempotent($db, $candidateProfileId, $data['referees']);
        }
    }

    private function normStr($v): string
    {
        return mb_strtolower(trim((string) $v));
    }

    private function normDateOnly($v): string
    {
        $ts = strtotime((string) $v);
        if ($ts === false)
            return '';
        return date('Y-m-d', $ts);
    }

    private function fp(array $parts): string
    {
        return hash('sha256', implode('|', $parts));
    }

    // ---------- Skills ----------
    private function processSkillsIdempotent($db, string $candidateProfileId, array $skills): void
    {
        $now = date('Y-m-d H:i:s');

        // Fetch existing skillIds for candidate
        $existingRows = $db->table('candidate_skills')
            ->select('skillId')
            ->where('candidateId', $candidateProfileId)
            ->get()->getResultArray();

        $existingSkillIds = [];
        foreach ($existingRows as $r) {
            $existingSkillIds[$r['skillId']] = true;
        }

        foreach ($skills as $skillData) {
            $skillName = trim((string) ($skillData['skillName'] ?? ''));
            if ($skillName === '')
                continue;

            // find/create skill
            $slug = strtolower(
                preg_replace(
                    '/\s+/',
                    '-',
                    preg_replace('/[^a-z0-9\s-]/', '', strtolower($skillName))
                )
            );

            $skill = $db->table('skills')->where('slug', $slug)->get()->getRowArray();
            if (!$skill) {
                $skill = $db->table('skills')->where('name', $skillName)->get()->getRowArray();
            }
            if (!$skill) {
                $skillId = uniqid('skill_');
                $db->table('skills')->insert([
                    'id' => $skillId,
                    'name' => $skillName,
                    'slug' => $slug,
                    'isActive' => 1,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]);
                $skill = $db->table('skills')->where('id', $skillId)->get()->getRowArray();
            }
            if (!$skill)
                continue;

            if (isset($existingSkillIds[$skill['id']])) {
                continue;
            }

            $db->table('candidate_skills')->insert([
                'id' => uniqid('cskill_'),
                'candidateId' => $candidateProfileId,
                'skillId' => $skill['id'],
                'level' => $skillData['level'] ?? null,
                'yearsOfExp' => $skillData['yearsOfExp'] ?? null,
                'createdAt' => $now,
            ]);

            $existingSkillIds[$skill['id']] = true;
        }
    }

    // ---------- Experiences ----------
    private function processExperiencesIdempotent($db, string $candidateProfileId, array $items): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('experiences')
            ->select('title, company, location, startDate, endDate, isCurrent')
            ->where('candidateId', $candidateProfileId)
            ->get()->getResultArray();

        $seen = [];
        foreach ($existing as $r) {
            $seen[$this->fp([
                $this->normStr($r['title'] ?? ''),
                $this->normStr($r['company'] ?? ''),
                $this->normStr($r['location'] ?? ''),
                $this->normDateOnly($r['startDate'] ?? ''),
                $this->normDateOnly($r['endDate'] ?? ''),
                (string) ((int) ($r['isCurrent'] ?? 0)),
            ])] = true;
        }

        $batch = [];
        foreach ($items as $exp) {
            $title = trim((string) ($exp['title'] ?? ''));
            $company = trim((string) ($exp['company'] ?? ''));
            $startDate = (string) ($exp['startDate'] ?? '');
            if ($title === '' || $company === '' || trim($startDate) === '')
                continue;

            $fp = $this->fp([
                $this->normStr($title),
                $this->normStr($company),
                $this->normStr($exp['location'] ?? ''),
                $this->normDateOnly($startDate),
                $this->normDateOnly($exp['endDate'] ?? ''),
                (string) ((int) !!($exp['isCurrent'] ?? false)),
            ]);

            if (isset($seen[$fp]))
                continue;

            $batch[] = [
                'id' => uniqid('exp_'),
                'candidateId' => $candidateProfileId,
                'title' => $title,
                'company' => $company,
                'location' => isset($exp['location']) ? trim((string) $exp['location']) : null,
                'employmentType' => isset($exp['employmentType']) ? trim((string) $exp['employmentType']) : null,
                'startDate' => date('Y-m-d H:i:s', strtotime($startDate)),
                'endDate' => !empty($exp['endDate']) ? date('Y-m-d H:i:s', strtotime((string) $exp['endDate'])) : null,
                'isCurrent' => (int) !!($exp['isCurrent'] ?? false),
                'description' => isset($exp['description']) ? trim((string) $exp['description']) : null,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $seen[$fp] = true;
        }

        if (!empty($batch)) {
            $db->table('experiences')->insertBatch($batch);
        }
    }

    // ---------- Educations ----------
    private function processEducationsIdempotent($db, string $candidateProfileId, array $items): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('educations')
            ->select('degree, institution, fieldOfStudy, startDate, endDate, isCurrent')
            ->where('candidateId', $candidateProfileId)
            ->get()->getResultArray();

        $seen = [];
        foreach ($existing as $r) {
            $seen[$this->fp([
                $this->normStr($r['degree'] ?? ''),
                $this->normStr($r['institution'] ?? ''),
                $this->normStr($r['fieldOfStudy'] ?? ''),
                $this->normDateOnly($r['startDate'] ?? ''),
                $this->normDateOnly($r['endDate'] ?? ''),
                (string) ((int) ($r['isCurrent'] ?? 0)),
            ])] = true;
        }

        $batch = [];
        foreach ($items as $edu) {
            $degree = trim((string) ($edu['degree'] ?? ''));
            $institution = trim((string) ($edu['institution'] ?? ''));
            $field = trim((string) ($edu['fieldOfStudy'] ?? ''));
            $startDate = (string) ($edu['startDate'] ?? '');

            if ($degree === '' || $institution === '' || $field === '' || trim($startDate) === '')
                continue;

            $fp = $this->fp([
                $this->normStr($degree),
                $this->normStr($institution),
                $this->normStr($field),
                $this->normDateOnly($startDate),
                $this->normDateOnly($edu['endDate'] ?? ''),
                (string) ((int) !!($edu['isCurrent'] ?? false)),
            ]);

            if (isset($seen[$fp]))
                continue;

            $batch[] = [
                'id' => uniqid('edu_'),
                'candidateId' => $candidateProfileId,
                'degree' => $degree,
                'degreeLevel' => $edu['degreeLevel'] ?? null,
                'institution' => $institution,
                'fieldOfStudy' => $field,
                'location' => isset($edu['location']) ? trim((string) $edu['location']) : null,
                'startDate' => date('Y-m-d H:i:s', strtotime($startDate)),
                'endDate' => !empty($edu['endDate']) ? date('Y-m-d H:i:s', strtotime((string) $edu['endDate'])) : null,
                'isCurrent' => (int) !!($edu['isCurrent'] ?? false),
                'grade' => isset($edu['grade']) ? trim((string) $edu['grade']) : null,
                'description' => isset($edu['description']) ? trim((string) $edu['description']) : null,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $seen[$fp] = true;
        }

        if (!empty($batch)) {
            $db->table('educations')->insertBatch($batch);
        }
    }

    // ---------- Personal info upsert ----------
    private function processPersonalInfoUpsert($db, string $candidateProfileId, array $pinfo): void
    {
        $now = date('Y-m-d H:i:s');

        $payload = [
            'candidateId' => $candidateProfileId,
            'fullName' => trim((string) ($pinfo['fullName'] ?? '')),
            'dob' => date('Y-m-d', strtotime((string) ($pinfo['dob'] ?? 'now'))),
            'gender' => $pinfo['gender'] ?? 'M',
            'idNumber' => trim((string) ($pinfo['idNumber'] ?? '')),
            'nationality' => trim((string) ($pinfo['nationality'] ?? '')),
            'countyOfOrigin' => trim((string) ($pinfo['countyOfOrigin'] ?? '')),
            'plwd' => (int) !!($pinfo['plwd'] ?? false),
            'updatedAt' => $now,
        ];

        $existing = $db->table('candidate_personal_info')
            ->where('candidateId', $candidateProfileId)
            ->get()->getRowArray();

        if ($existing) {
            $db->table('candidate_personal_info')
                ->where('id', $existing['id'])
                ->update($payload);
        } else {
            $payload['id'] = uniqid('pinfo_');
            $payload['createdAt'] = $now;
            $db->table('candidate_personal_info')->insert($payload);
        }
    }

    // ---------- Publications ----------
    private function processPublicationsIdempotent($db, string $candidateProfileId, array $items): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('candidate_publications')
            ->select('title, type, journalOrPublisher, year')
            ->where('candidateId', $candidateProfileId)
            ->get()->getResultArray();

        $seen = [];
        foreach ($existing as $r) {
            $seen[$this->fp([
                $this->normStr($r['title'] ?? ''),
                $this->normStr($r['type'] ?? ''),
                $this->normStr($r['journalOrPublisher'] ?? ''),
                (string) ($r['year'] ?? ''),
            ])] = true;
        }

        $batch = [];
        foreach ($items as $pub) {
            $title = trim((string) ($pub['title'] ?? ''));
            $year = (string) ($pub['year'] ?? '');
            if ($title === '' || trim($year) === '')
                continue;

            $type = trim((string) ($pub['type'] ?? 'Journal'));
            $j = trim((string) ($pub['journalOrPublisher'] ?? ''));

            $fp = $this->fp([$this->normStr($title), $this->normStr($type), $this->normStr($j), (string) $year]);
            if (isset($seen[$fp]))
                continue;

            $batch[] = [
                'id' => uniqid('pub_'),
                'candidateId' => $candidateProfileId,
                'title' => $title,
                'type' => $type,
                'journalOrPublisher' => $j !== '' ? $j : null,
                'year' => (int) $year,
                'link' => isset($pub['link']) ? trim((string) $pub['link']) : null,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];
            $seen[$fp] = true;
        }

        if (!empty($batch)) {
            $db->table('candidate_publications')->insertBatch($batch);
        }
    }

    // ---------- Memberships ----------
    private function processMembershipsIdempotent($db, string $candidateProfileId, array $items): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('candidate_professional_memberships')
            ->select('bodyName, membershipNumber')
            ->where('candidateId', $candidateProfileId)
            ->get()->getResultArray();

        $seen = [];
        foreach ($existing as $r) {
            $seen[$this->fp([$this->normStr($r['bodyName'] ?? ''), $this->normStr($r['membershipNumber'] ?? '')])] = true;
        }

        $batch = [];
        foreach ($items as $mem) {
            $body = trim((string) ($mem['bodyName'] ?? ''));
            if ($body === '')
                continue;

            $num = trim((string) ($mem['membershipNumber'] ?? ''));

            $fp = $this->fp([$this->normStr($body), $this->normStr($num)]);
            if (isset($seen[$fp]))
                continue;

            $batch[] = [
                'id' => uniqid('mem_'),
                'candidateId' => $candidateProfileId,
                'bodyName' => $body,
                'membershipNumber' => $num !== '' ? $num : null,
                'isActive' => (int) !!($mem['isActive'] ?? true),
                'goodStanding' => (int) !!($mem['goodStanding'] ?? true),
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $seen[$fp] = true;
        }

        if (!empty($batch)) {
            $db->table('candidate_professional_memberships')->insertBatch($batch);
        }
    }

    // ---------- Clearances ----------
    private function processClearancesIdempotent($db, string $candidateProfileId, array $items): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('candidate_clearances')
            ->select('type, certificateNumber, issueDate, expiryDate, status')
            ->where('candidateId', $candidateProfileId)
            ->get()->getResultArray();

        $seen = [];
        foreach ($existing as $r) {
            $seen[$this->fp([
                $this->normStr($r['type'] ?? ''),
                $this->normStr($r['certificateNumber'] ?? ''),
                $this->normDateOnly($r['issueDate'] ?? ''),
                $this->normDateOnly($r['expiryDate'] ?? ''),
                $this->normStr($r['status'] ?? ''),
            ])] = true;
        }

        $batch = [];
        foreach ($items as $clr) {
            $type = trim((string) ($clr['type'] ?? ''));
            $issue = (string) ($clr['issueDate'] ?? '');
            if ($type === '' || trim($issue) === '')
                continue;

            $cert = trim((string) ($clr['certificateNumber'] ?? ''));
            $exp = (string) ($clr['expiryDate'] ?? '');
            $status = trim((string) ($clr['status'] ?? 'PENDING'));

            $fp = $this->fp([
                $this->normStr($type),
                $this->normStr($cert),
                $this->normDateOnly($issue),
                $this->normDateOnly($exp),
                $this->normStr($status),
            ]);
            if (isset($seen[$fp]))
                continue;

            $batch[] = [
                'id' => uniqid('clr_'),
                'candidateId' => $candidateProfileId,
                'type' => $type,
                'certificateNumber' => $cert !== '' ? $cert : null,
                'issueDate' => date('Y-m-d', strtotime($issue)),
                'expiryDate' => $exp !== '' ? date('Y-m-d', strtotime($exp)) : null,
                'status' => $status,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $seen[$fp] = true;
        }

        if (!empty($batch)) {
            $db->table('candidate_clearances')->insertBatch($batch);
        }
    }

    // ---------- Courses ----------
    private function processCoursesIdempotent($db, string $candidateProfileId, array $items): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('candidate_courses')
            ->select('name, institution, durationWeeks, year')
            ->where('candidateId', $candidateProfileId)
            ->get()->getResultArray();

        $seen = [];
        foreach ($existing as $r) {
            $seen[$this->fp([
                $this->normStr($r['name'] ?? ''),
                $this->normStr($r['institution'] ?? ''),
                (string) ($r['durationWeeks'] ?? ''),
                (string) ($r['year'] ?? ''),
            ])] = true;
        }

        $batch = [];
        foreach ($items as $crs) {
            $name = trim((string) ($crs['name'] ?? ''));
            $inst = trim((string) ($crs['institution'] ?? ''));
            $dur = $crs['durationWeeks'] ?? null;
            $year = $crs['year'] ?? null;

            if ($name === '' || $inst === '' || !$dur || !$year)
                continue;

            $fp = $this->fp([
                $this->normStr($name),
                $this->normStr($inst),
                (string) (int) $dur,
                (string) (int) $year,
            ]);
            if (isset($seen[$fp]))
                continue;

            $batch[] = [
                'id' => uniqid('crs_'),
                'candidateId' => $candidateProfileId,
                'name' => $name,
                'institution' => $inst,
                'durationWeeks' => (int) $dur,
                'year' => (int) $year,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $seen[$fp] = true;
        }

        if (!empty($batch)) {
            $db->table('candidate_courses')->insertBatch($batch);
        }
    }

    // ---------- Referees ----------
    private function processRefereesIdempotent($db, string $candidateProfileId, array $items): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('candidate_referees')
            ->select('name, email, phone, organization')
            ->where('candidateId', $candidateProfileId)
            ->get()->getResultArray();

        $seen = [];
        foreach ($existing as $r) {
            $seen[$this->fp([
                $this->normStr($r['name'] ?? ''),
                $this->normStr($r['email'] ?? ''),
                $this->normStr($r['phone'] ?? ''),
                $this->normStr($r['organization'] ?? ''),
            ])] = true;
        }

        $batch = [];
        foreach ($items as $ref) {
            $name = trim((string) ($ref['name'] ?? ''));
            if ($name === '')
                continue;

            $email = trim((string) ($ref['email'] ?? ''));
            $phone = trim((string) ($ref['phone'] ?? ''));
            $org = trim((string) ($ref['organization'] ?? ''));

            $fp = $this->fp([
                $this->normStr($name),
                $this->normStr($email),
                $this->normStr($phone),
                $this->normStr($org),
            ]);
            if (isset($seen[$fp]))
                continue;

            $batch[] = [
                'id' => uniqid('ref_'),
                'candidateId' => $candidateProfileId,
                'name' => $name,
                'position' => isset($ref['position']) ? trim((string) $ref['position']) : null,
                'organization' => $org !== '' ? $org : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'relationship' => isset($ref['relationship']) ? trim((string) $ref['relationship']) : null,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $seen[$fp] = true;
        }

        if (!empty($batch)) {
            $db->table('candidate_referees')->insertBatch($batch);
        }
    }

    // =========================================================
    // PRIVATE: Immutable snapshot
    // =========================================================
    private function createComprehensiveSnapshot(
        $db,
        string $applicationId,
        array $profile,
        object $user,
        array $data
    ): void {
        $pid = $profile['id'];

        $snapshot = [
            'applicationId' => $applicationId,
            'candidateId' => $pid,
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
                'portfolioUrl' => $profile['portfolioUrl'] ?? null,
            ],

            'skills' => $db->table('candidate_skills')
                ->select('candidate_skills.*, skills.name as skillName')
                ->join('skills', 'skills.id = candidate_skills.skillId', 'inner')
                ->where('candidate_skills.candidateId', $pid)
                ->get()->getResultArray(),

            'experience' => $db->table('experiences')
                ->where('candidateId', $pid)
                ->orderBy('startDate', 'DESC')
                ->get()->getResultArray(),

            'education' => $db->table('educations')
                ->where('candidateId', $pid)
                ->orderBy('startDate', 'DESC')
                ->get()->getResultArray(),

            'personalInfo' => $db->table('candidate_personal_info')
                ->where('candidateId', $pid)
                ->get()->getRowArray() ?: null,

            'publications' => $db->table('candidate_publications')
                ->where('candidateId', $pid)->get()->getResultArray(),

            'memberships' => $db->table('candidate_professional_memberships')
                ->where('candidateId', $pid)->get()->getResultArray(),

            'clearances' => $db->table('candidate_clearances')
                ->where('candidateId', $pid)->get()->getResultArray(),

            'courses' => $db->table('candidate_courses')
                ->where('candidateId', $pid)->get()->getResultArray(),

            'referees' => $db->table('candidate_referees')
                ->select('id, candidateId, name, position, organization, phone, email, relationship, createdAt')
                ->where('candidateId', $pid)->get()->getResultArray(),

            'files' => $db->table('candidate_files')
                ->where('candidateId', $pid)->get()->getResultArray(),

            'applicationDetails' => [
                'coverLetter' => $data['coverLetter'] ?? '',
                'portfolioUrl' => $data['portfolioUrl'] ?? '',
                'expectedSalary' => $data['expectedSalary'] ?? '',
                'currentSalary' => $data['currentSalary'] ?? null,
                'availableStartDate' => $data['availableStartDate'] ?? '',
            ],

            'questionnaireAnswers' => $data['questionnaireAnswers'] ?? [],
        ];

        $db->table('application_snapshots')->insert([
            'id' => uniqid('snapshot_'),
            'applicationId' => $applicationId,
            'snapshotData' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================
    // PRIVATE: Email notifications (no EmailHelper edits)
    // =========================================================
    private function sendApplicationEmails(
        string $applicationId,
        array $job,
        string $userId,
        array $profile,
        array $data
    ): void {
        try {
            $db = \Config\Database::connect();
            $emailHelper = new \App\Libraries\EmailHelper();
            $companyModel = new Company();

            // Explicit user fetch (fix empty names)
            $userRow = $db->table('users')
                ->select('firstName, lastName, email, phone')
                ->where('id', $userId)
                ->get()
                ->getRowArray();

            $firstName = $userRow['firstName'] ?? '';
            $lastName = $userRow['lastName'] ?? '';
            $email = $userRow['email'] ?? '';
            $phone = $userRow['phone'] ?? 'N/A';

            $company = !empty($job['companyId']) ? $companyModel->find($job['companyId']) : null;
            $companyName = $company['name'] ?? 'Fortune Kenya';

            $now = date('Y-m-d H:i:s');

            $jobPayload = [
                'id' => $job['id'] ?? '',
                'title' => $job['title'] ?? 'Position',
                'company' => [
                    'name' => $companyName,
                    'logo' => $company['logo'] ?? null,
                ],
            ];

            $candidatePayload = [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
            ];

            // Candidate email
            $emailHelper->sendApplicationReceivedEmail([
                'applicationId' => $applicationId,
                'candidate' => $candidatePayload,
                'job' => $jobPayload,
                'expectedSalary' => $data['expectedSalary'] ?? null,
                'availableStartDate' => $data['availableStartDate'] ?? null,
                'appliedAt' => $now,
                'portfolioUrl' => $data['portfolioUrl'] ?? null,
                'coverLetterPreview' => mb_substr(trim((string) ($data['coverLetter'] ?? '')), 0, 200),
            ]);

            // Admin email
            $emailHelper->notifyAdminNewApplication([
                'applicationId' => $applicationId,
                'candidate' => array_merge($candidatePayload, ['phone' => $phone]),
                'profile' => [
                    'title' => $profile['title'] ?? 'N/A',
                    'location' => $profile['location'] ?? 'N/A',
                    'experienceYears' => $profile['experienceYears'] ?? '0 years',
                    'resumeUrl' => $profile['resumeUrl'] ?? null,
                ],
                'job' => $jobPayload,
                'expectedSalary' => $data['expectedSalary'] ?? null,
                'availableStartDate' => $data['availableStartDate'] ?? null,
                'coverLetter' => $data['coverLetter'] ?? '',
                'reviewUrl' => rtrim((string) env('app.baseURL', base_url()), '/')
                    . '/recruitment-portal/applications/detail?id=' . $applicationId,
            ]);

        } catch (\Exception $e) {
            log_message('error', 'sendApplicationEmails error [' . $applicationId . ']: ' . $e->getMessage());
        }
    }
}