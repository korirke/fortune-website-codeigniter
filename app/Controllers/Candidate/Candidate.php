<?php

namespace App\Controllers\Candidate;

use App\Controllers\BaseController;
use App\Models\CandidateProfile;
use App\Models\CandidateSkill;
use App\Models\CandidateDomain;
use App\Models\Education;
use App\Models\Experience;
use App\Models\Skill;
use App\Models\Domain;
use App\Models\Application;
use App\Models\ResumeVersion;
use App\Models\Job;
use App\Models\Company;
use App\Models\User;
use App\Libraries\EmailHelper;
use App\Libraries\ProfileRequirementKeys;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Candidate",
 *     description="Candidate profile management endpoints"
 * )
 */
class Candidate extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/candidate/profile",
     *     tags={"Candidate"},
     *     summary="Get candidate profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Profile retrieved")
     * )
     */
    private function getCandidateProfileOrFail($user)
    {
        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();
        if (!$profile) {
            return [null, $this->failNotFound('Candidate profile not found')];
        }
        return [$profile, null];
    }

    private function normalizeDegreeLevel(?string $level): ?string
    {
        if ($level === null)
            return null;

        $cleaned = strtoupper(trim($level));

        // Accept any non-empty level — validation is handled by the
        // frontend which loads levels directly from education_qualification_levels.
        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Get Profile 
     */
    public function getProfile()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();

            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }

            // =========================================================
            // ✅ INCLUDE FILTERING (BACKWARD COMPATIBLE)
            // =========================================================
            $includeParam = $this->request->getGet('include');
            $includeAll = ($includeParam === null || trim((string) $includeParam) === '');
            $include = [];

            if (!$includeAll) {
                $parts = explode(',', $includeParam);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '')
                        $include[$p] = true;
                }
            }

            // helper
            $wants = function (string $key) use ($includeAll, $include) {
                if ($includeAll)
                    return true;
                return isset($include[$key]);
            };
            // =========================================================

            // Get user data INCLUDING PHONE
            $userModel = new User();
            $userData = $userModel->select('id, email, firstName, lastName, phone, avatar, emailVerified, createdAt')
                ->find($user->id);

            if (!$userData) {
                return $this->failNotFound('User not found');
            }

            // Calculate experience years
            $profile['experienceYears'] = $profile['experienceYears'] ?? '0 years';
            $profile['totalExperienceMonths'] = (int) ($profile['totalExperienceMonths'] ?? 0);

            // =========================================================
            // Skills 
            // =========================================================
            $skills = [];
            if ($wants('skills')) {
                $candidateSkillModel = new CandidateSkill();
                $candidateSkills = $candidateSkillModel->where('candidateId', $profile['id'])
                    ->orderBy('createdAt', 'DESC')
                    ->findAll();

                $skillModel = new Skill();
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
            }

            // =========================================================
            // Domains
            // =========================================================
            $domains = [];
            if ($wants('domains')) {
                $candidateDomainModel = new CandidateDomain();
                $candidateDomains = $candidateDomainModel->where('candidateId', $profile['id'])
                    ->orderBy('isPrimary', 'DESC')
                    ->findAll();

                $domainModel = new Domain();
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
            }

            // =========================================================
            // Educations
            // =========================================================
            $educations = [];
            if ($wants('educations')) {
                $educationModel = new Education();
                $educations = $educationModel->where('candidateId', $profile['id'])
                    ->orderBy('startDate', 'DESC')
                    ->findAll();
            }

            // =========================================================
            // Experiences
            // =========================================================
            $experiences = [];
            if ($wants('experiences')) {
                $experienceModel = new Experience();
                $experiences = $experienceModel->where('candidateId', $profile['id'])
                    ->orderBy('startDate', 'DESC')
                    ->findAll();
            }

            // =========================================================
            // Certifications
            // =========================================================
            $certificationModel = new \App\Models\Certification();
            $certifications = $certificationModel->where('candidateId', $profile['id'])
                ->orderBy('issueDate', 'DESC')
                ->findAll();

            // =========================================================
            // Languages
            // =========================================================
            $languages = [];
            try {
                $candidateLanguageModel = new \App\Models\CandidateLanguage();
                $candidateLanguages = $candidateLanguageModel->where('candidateId', $profile['id'])->findAll();

                $languageModel = new \App\Models\Language();
                foreach ($candidateLanguages as $cl) {
                    $language = $languageModel->find($cl['languageId'] ?? null);
                    if ($language) {
                        $languages[] = [
                            'id' => $cl['id'] ?? null,
                            'candidateId' => $cl['candidateId'] ?? null,
                            'languageId' => $cl['languageId'] ?? null,
                            'proficiency' => $cl['proficiency'] ?? null,
                            'createdAt' => $cl['createdAt'] ?? null,
                            'language' => $language
                        ];
                    }
                }
            } catch (\Exception $e) {
                $languages = [];
            }

            // =========================================================
            // Resumes
            // =========================================================
            $resumes = [];
            if ($wants('resumes')) {
                $resumeModel = new ResumeVersion();
                $resumes = $resumeModel->where('candidateId', $profile['id'])
                    ->orderBy('version', 'DESC')
                    ->limit(5)
                    ->findAll();
            }

            // =========================================================
            // Publications
            // =========================================================
            $publications = [];
            if ($wants('publications')) {
                $pubModel = new \App\Models\CandidatePublication();
                $publications = $pubModel->where('candidateId', $profile['id'])
                    ->orderBy('year', 'DESC')
                    ->findAll();
            }

            // =========================================================
            // Memberships
            // =========================================================
            $memberships = [];
            if ($wants('memberships')) {
                $memModel = new \App\Models\CandidateMembership();
                $memberships = $memModel->where('candidateId', $profile['id'])
                    ->orderBy('createdAt', 'DESC')
                    ->findAll();
            }

            // =========================================================
            // Clearances
            // =========================================================
            $clearances = [];
            if ($wants('clearances')) {
                $clearModel = new \App\Models\CandidateClearance();
                $clearances = $clearModel->where('candidateId', $profile['id'])
                    ->orderBy('issueDate', 'DESC')
                    ->findAll();
            }

            // =========================================================
            // Courses
            // =========================================================
            $courses = [];
            if ($wants('courses')) {
                $courseModel = new \App\Models\CandidateCourse();
                $courses = $courseModel->where('candidateId', $profile['id'])
                    ->orderBy('year', 'DESC')
                    ->findAll();
            }

            // =========================================================
            // Referees
            // =========================================================
            $referees = [];
            if ($wants('referees')) {
                $refModel = new \App\Models\CandidateReferee();
                $referees = $refModel->where('candidateId', $profile['id'])
                    ->orderBy('createdAt', 'DESC')
                    ->findAll();
            }

            // =========================================================
            // Personal info
            // =========================================================
            $personalInfo = null;
            if ($wants('personalInfo')) {
                $pinfoModel = new \App\Models\CandidatePersonalInfo();
                $personalInfo = $pinfoModel->where('candidateId', $profile['id'])->first();
            }

            // =========================================================
            // Candidate files
            // =========================================================
            $files = [];
            if ($wants('files')) {
                $fileModel = new \App\Models\CandidateFile();
                $files = $fileModel->where('candidateId', $profile['id'])
                    ->orderBy('createdAt', 'DESC')
                    ->findAll();
            }

            // Build response
            $profileData = $profile;
            $profileData['user'] = $userData;

            // Always assign (even if empty) => frontend stable
            $profileData['skills'] = $skills;
            $profileData['domains'] = $domains;
            $profileData['educations'] = $educations;
            $profileData['experiences'] = $experiences;
            $profileData['certifications'] = $certifications;
            $profileData['languages'] = $languages;
            $profileData['resumes'] = $resumes;
            $profileData['publications'] = $publications;
            $profileData['memberships'] = $memberships;
            $profileData['clearances'] = $clearances;
            $profileData['courses'] = $courses;
            $profileData['referees'] = $referees;
            $profileData['personalInfo'] = $personalInfo;
            $profileData['files'] = $files;

            return $this->respond([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => $profileData
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to retrieve candidate profile: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());

            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve profile: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }


    /**
     * @OA\Put(
     *     path="/api/candidate/profile",
     *     tags={"Candidate"},
     *     summary="Update candidate profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Profile updated")
     * )
     */
    public function updateProfile()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $data = $this->request->getJSON(true);
            $profileModel = new CandidateProfile();
            $userModel = new User();

            $profile = $profileModel->where('userId', $user->id)->first();
            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }

            $requiredFields = ['title', 'bio', 'location'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || trim($data[$field]) === '') {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                return $this->fail('Missing required fields: ' . implode(', ', $missingFields), 400);
            }

            // Validate and update PHONE in USERS table
            if (isset($data['phone'])) {
                if (!preg_match('/^\+?[1-9]\d{1,14}$/', $data['phone'])) {
                    return $this->fail('Invalid phone number format (e.g., +254712345678)', 400);
                }

                $userModel->update($user->id, ['phone' => $data['phone']]);
                unset($data['phone']); // Remove from profile data
            }

            // Remove experienceYears if provided we do calculation
            unset($data['experienceYears']);

            // Handle date conversion
            if (isset($data['availableFrom'])) {
                $data['availableFrom'] = date('Y-m-d', strtotime($data['availableFrom']));
            }

            // Update profile
            $profileModel->update($profile['id'], $data);

            // Get updated profile with calculated experience
            $updatedProfile = $profileModel->where('userId', $user->id)->first();
            $updatedProfile['experienceYears'] = $updatedProfile['experienceYears'] ?? '0 years';
            $updatedProfile['totalExperienceMonths'] = (int) ($updatedProfile['totalExperienceMonths'] ?? 0);

            $userData = $userModel->select('firstName, lastName, email, phone')->find($user->id);
            $updatedProfile['user'] = $userData;

            return $this->respond([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $updatedProfile
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Update profile error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update profile',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/candidate/resume/upload",
     *     tags={"Candidate"},
     *     summary="Upload resume",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Resume uploaded")
     * )
     */
    public function uploadResume()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->fail('No file uploaded', 400);
        }

        $uploadPath = WRITEPATH . 'uploads/resumes/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();

        if (!$profile) {
            return $this->failNotFound('Candidate profile not found');
        }

        $resumeModel = new ResumeVersion();

        // transaction to ensure all operations succeed or fail together
        $db = \Config\Database::connect();
        $db->transStart();

        try {
            // Delete ALL existing resumes for this candidate (DB records + physical files)
            $existingResumes = $resumeModel->where('candidateId', $profile['id'])->findAll();
            foreach ($existingResumes as $existing) {
                $physicalPath = WRITEPATH . ltrim(str_replace('/uploads/', 'uploads/', $existing['fileUrl']), '/');
                if (is_file($physicalPath)) {
                    @unlink($physicalPath);
                }
                $resumeModel->delete($existing['id']);
            }

            // Sanitize original filename and add timestamp
            $originalName = $file->getClientName();
            $pathInfo = pathinfo($originalName);
            $sanitizedName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $pathInfo['filename']);
            $extension = $pathInfo['extension'] ?? '';
            $timestamp = time();
            $newName = "{$sanitizedName}_{$timestamp}.{$extension}";

            $file->move($uploadPath, $newName);

            $resumeData = [
                'id' => uniqid('resume_'),
                'candidateId' => $profile['id'],
                'version' => 1,
                'fileName' => $file->getClientName(),
                'fileUrl' => '/uploads/resumes/' . $newName,
                'fileSize' => $file->getSize()
            ];
            $resumeModel->insert($resumeData);

            // Update profile with latest resume URL
            $profileModel->update($profile['id'], [
                'resumeUrl' => '/uploads/resumes/' . $newName,
                'resumeUpdatedAt' => date('Y-m-d H:i:s')
            ]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \Exception('Transaction failed');
            }

            // Get created resume
            $createdResume = $resumeModel->find($resumeData['id']);

            return $this->respond([
                'success' => true,
                'message' => 'Resume uploaded successfully',
                'data' => $createdResume
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            return $this->respond([
                'success' => false,
                'message' => 'Failed to upload resume',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/candidate/skills",
     *     tags={"Candidate"},
     *     summary="Add skill to profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="201", description="Skill added")
     * )
     */
    public function addSkill()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $data = $this->request->getJSON(true);

        // Validate skillName
        if (empty($data['skillName']) || !isset($data['skillName'])) {
            return $this->fail('Skill name is required', 400);
        }

        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();

        if (!$profile) {
            return $this->failNotFound('Candidate profile not found');
        }

        try {
            // Find or create skill
            $skillName = trim($data['skillName']);
            $slug = strtolower($skillName);
            $slug = preg_replace('/\s+/', '-', $slug);
            $slug = preg_replace('/[^a-z0-9-]/', '', $slug);

            $skillModel = new Skill();
            $skill = $skillModel->where('name', $skillName)
                ->orWhere('slug', $slug)
                ->first();

            if (!$skill) {
                // Create new skill
                $skillId = uniqid('skill_');
                $skillModel->insert([
                    'id' => $skillId,
                    'name' => $skillName,
                    'slug' => $slug,
                    'isActive' => true
                ]);
                $skill = $skillModel->find($skillId);
            }

            if (!$skill) {
                return $this->fail('Failed to create or find skill', 500);
            }

            // Check if skill already exists for this candidate
            $candidateSkillModel = new CandidateSkill();
            $existing = $candidateSkillModel->where('candidateId', $profile['id'])
                ->where('skillId', $skill['id'])
                ->first();

            if ($existing) {
                return $this->fail('Skill already added to your profile', 409);
            }

            // Add skill to candidate profile
            $candidateSkillData = [
                'id' => uniqid('cskill_'),
                'candidateId' => $profile['id'],
                'skillId' => $skill['id'],
                'level' => $data['level'] ?? null,
                'yearsOfExp' => $data['yearsOfExp'] ?? null
            ];
            $candidateSkillModel->insert($candidateSkillData);

            // Get created candidate skill with skill details
            $createdCandidateSkill = [
                'id' => $candidateSkillData['id'],
                'candidateId' => $candidateSkillData['candidateId'],
                'skillId' => $candidateSkillData['skillId'],
                'level' => $candidateSkillData['level'],
                'yearsOfExp' => $candidateSkillData['yearsOfExp'],
                'createdAt' => date('Y-m-d H:i:s'),
                'skill' => $skill
            ];

            return $this->respondCreated([
                'success' => true,
                'message' => 'Skill added successfully',
                'data' => $createdCandidateSkill
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Add skill error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to add skill: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/candidate/skills/{skillId}",
     *     tags={"Candidate"},
     *     summary="Remove skill from profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="skillId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Skill removed")
     * )
     */
    public function removeSkill($skillId = null)
    {
        if ($skillId === null) {
            $skillId = $this->request->getUri()->getSegment(4);
        }

        if (!$skillId) {
            return $this->fail('Skill ID is required', 400);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();

        if (!$profile) {
            return $this->fail('Profile not found', 404);
        }

        try {
            $candidateSkillModel = new CandidateSkill();
            $candidateSkill = $candidateSkillModel->where('candidateId', $profile['id'])
                ->where('skillId', $skillId)
                ->first();

            if (!$candidateSkill) {
                return $this->failNotFound('Skill not found in your profile');
            }

            $candidateSkillModel->delete($candidateSkill['id']);

            return $this->respond([
                'success' => true,
                'message' => 'Skill removed successfully'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Remove skill error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to remove skill',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/candidate/skills/available",
     *     tags={"Candidate"},
     *     summary="Get all available skills",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Skills retrieved")
     * )
     */
    public function getAvailableSkills()
    {
        $skillModel = new Skill();
        $skills = $skillModel->where('isActive', true)->orderBy('name', 'ASC')->findAll();

        return $this->respond([
            'success' => true,
            'message' => 'Skills retrieved successfully',
            'data' => $skills
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/candidate/domains",
     *     tags={"Candidate"},
     *     summary="Add domain to profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="201", description="Domain added")
     * )
     */
    public function addDomain()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $data = $this->request->getJSON(true);
        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();

        if (!$profile) {
            return $this->failNotFound('Candidate profile not found');
        }

        try {
            // Check if domain exists
            $domainModel = new Domain();
            $domain = $domainModel->find($data['domainId']);

            if (!$domain) {
                return $this->failNotFound('Domain not found');
            }

            // Check if domain already exists for this candidate
            $candidateDomainModel = new CandidateDomain();
            $existing = $candidateDomainModel->where('candidateId', $profile['id'])
                ->where('domainId', $data['domainId'])
                ->first();

            if ($existing) {
                return $this->fail('Domain already added to your profile', 409);
            }

            // Use transaction if setting as primary
            $isPrimary = $data['isPrimary'] ?? false;
            $db = \Config\Database::connect();
            $db->transStart();

            try {
                // If setting as primary, unset other primary domains
                if ($isPrimary) {
                    $candidateDomainModel->where('candidateId', $profile['id'])
                        ->where('isPrimary', true)
                        ->set(['isPrimary' => false])
                        ->update();
                }

                // Add domain
                $domainData = [
                    'id' => uniqid('cdomain_'),
                    'candidateId' => $profile['id'],
                    'domainId' => $data['domainId'],
                    'isPrimary' => $isPrimary
                ];
                $candidateDomainModel->insert($domainData);

                $db->transComplete();

                if ($db->transStatus() === false) {
                    throw new \Exception('Transaction failed');
                }

                // Get created domain with domain details
                $createdDomain = [
                    'id' => $domainData['id'],
                    'candidateId' => $domainData['candidateId'],
                    'domainId' => $domainData['domainId'],
                    'isPrimary' => $domainData['isPrimary'],
                    'createdAt' => date('Y-m-d H:i:s'),
                    'domain' => $domain
                ];

                return $this->respondCreated([
                    'success' => true,
                    'message' => 'Domain added successfully',
                    'data' => $createdDomain
                ]);
            } catch (\Exception $e) {
                $db->transRollback();
                throw $e;
            }
        } catch (\Exception $e) {
            log_message('error', 'Add domain error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to add domain',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/candidate/domains/{domainId}",
     *     tags={"Candidate"},
     *     summary="Remove domain from profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Domain removed")
     * )
     */
    public function removeDomain($domainId = null)
    {
        if ($domainId === null) {
            $domainId = $this->request->getUri()->getSegment(4);
        }

        if (!$domainId) {
            return $this->fail('Domain ID is required', 400);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();

        if (!$profile) {
            return $this->fail('Profile not found', 404);
        }

        try {
            $domainModel = new CandidateDomain();
            $domain = $domainModel->where('candidateId', $profile['id'])
                ->where('domainId', $domainId)
                ->first();

            if (!$domain) {
                return $this->failNotFound('Domain not found in your profile');
            }

            $domainModel->delete($domain['id']);

            return $this->respond([
                'success' => true,
                'message' => 'Domain removed successfully'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to remove domain',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/candidate/domains/available",
     *     tags={"Candidate"},
     *     summary="Get all available domains",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Domains retrieved")
     * )
     */
    public function getAvailableDomains()
    {
        $domainModel = new Domain();
        $domains = $domainModel->where('isActive', true)
            ->where('parentId', null)
            ->orderBy('sortOrder', 'ASC')
            ->findAll();

        // Get children for each domain
        foreach ($domains as &$domain) {
            $domain['children'] = $domainModel->where('isActive', true)
                ->where('parentId', $domain['id'])
                ->orderBy('sortOrder', 'ASC')
                ->findAll();
        }

        return $this->respond([
            'success' => true,
            'message' => 'Domains retrieved successfully',
            'data' => $domains
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/candidate/education",
     *     tags={"Candidate"},
     *     summary="Add education to profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="201", description="Education added")
     * )
     */
    public function addEducation()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        $data = $this->request->getJSON(true);

        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();
        if (!$profile)
            return $this->fail('Profile not found', 404);

        try {
            // Basic validation
            if (empty(trim($data['degree'] ?? ''))) {
                return $this->fail('Degree is required', 400);
            }
            if (empty(trim($data['fieldOfStudy'] ?? ''))) {
                return $this->fail('Field of Study is required', 400);
            }
            if (empty(trim($data['institution'] ?? ''))) {
                return $this->fail('Institution is required', 400);
            }
            if (empty($data['startDate'])) {
                return $this->fail('Start date is required', 400);
            }

            // Validate degreeLevel
            $degreeLevel = $this->normalizeDegreeLevel($data['degreeLevel'] ?? null);


            // Dates
            $startDate = date('Y-m-d', strtotime($data['startDate']));
            $endDate = !empty($data['endDate']) ? date('Y-m-d', strtotime($data['endDate'])) : null;

            $isCurrent = !empty($data['isCurrent']);

            if ($endDate && strtotime($endDate) < strtotime($startDate)) {
                return $this->fail('End date cannot be before start date', 400);
            }
            if ($isCurrent && $endDate) {
                return $this->fail('Cannot have end date for current education', 400);
            }

            $educationModel = new Education();
            $educationData = [
                'id' => uniqid('edu_'),
                'candidateId' => $profile['id'],
                'degree' => trim($data['degree']),
                'degreeLevel' => $degreeLevel,
                'fieldOfStudy' => trim($data['fieldOfStudy']),
                'institution' => trim($data['institution']),
                'location' => isset($data['location']) ? trim((string) $data['location']) : null,
                'startDate' => $startDate,
                'endDate' => $isCurrent ? null : $endDate,
                'isCurrent' => $isCurrent ? 1 : 0,
                'grade' => isset($data['grade']) ? trim((string) $data['grade']) : null,
                'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            ];

            $educationModel->insert($educationData);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Education added successfully',
                'data' => $educationModel->find($educationData['id'])
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Add education error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to add education',
                'data' => []
            ], 500);
        }
    }


    /**
     * @OA\Put(
     *     path="/api/candidate/education/{educationId}",
     *     tags={"Candidate"},
     *     summary="Update education",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="educationId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Education updated")
     * )
     */
    public function updateEducation($educationId = null)
    {
        if ($educationId === null) {
            $educationId = $this->request->getUri()->getSegment(4);
        }
        if (!$educationId)
            return $this->fail('Education ID is required', 400);

        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        try {
            $data = $this->request->getJSON(true);

            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();
            if (!$profile)
                return $this->failNotFound('Candidate profile not found');

            $educationModel = new Education();
            $existingEducation = $educationModel->where('id', $educationId)
                ->where('candidateId', $profile['id'])
                ->first();

            if (!$existingEducation)
                return $this->failNotFound('Education not found');

            // degreeLevel validation (optional)
            if (array_key_exists('degreeLevel', $data)) {
                $degreeLevel = $this->normalizeDegreeLevel($data['degreeLevel']);
                // null just means blank — don't block; updateData below only writes if key was present
            }

            // Dates
            $startDate = array_key_exists('startDate', $data)
                ? date('Y-m-d', strtotime($data['startDate']))
                : $existingEducation['startDate'];

            $endDate = array_key_exists('endDate', $data)
                ? (!empty($data['endDate']) ? date('Y-m-d', strtotime($data['endDate'])) : null)
                : ($existingEducation['endDate'] ?? null);

            $isCurrent = array_key_exists('isCurrent', $data)
                ? (int) !!$data['isCurrent']
                : (int) ($existingEducation['isCurrent'] ?? 0);

            if ($endDate && strtotime($endDate) < strtotime($startDate)) {
                return $this->fail('End date cannot be before start date', 400);
            }
            if ($isCurrent && $endDate) {
                return $this->fail('Cannot have end date for current education', 400);
            }

            $updateData = [
                'degree' => array_key_exists('degree', $data) ? trim((string) $data['degree']) : $existingEducation['degree'],
                'fieldOfStudy' => array_key_exists('fieldOfStudy', $data) ? trim((string) $data['fieldOfStudy']) : $existingEducation['fieldOfStudy'],
                'institution' => array_key_exists('institution', $data) ? trim((string) $data['institution']) : $existingEducation['institution'],
                'location' => array_key_exists('location', $data) ? trim((string) $data['location']) : ($existingEducation['location'] ?? null),
                'startDate' => $startDate,
                'endDate' => $isCurrent ? null : $endDate,
                'isCurrent' => $isCurrent,
                'grade' => array_key_exists('grade', $data) ? trim((string) $data['grade']) : ($existingEducation['grade'] ?? null),
                'description' => array_key_exists('description', $data) ? trim((string) $data['description']) : ($existingEducation['description'] ?? null),
            ];

            // Only set if sent
            if (array_key_exists('degreeLevel', $data)) {
                $updateData['degreeLevel'] = $degreeLevel;
            }

            $educationModel->update($educationId, $updateData);

            return $this->respond([
                'success' => true,
                'message' => 'Education updated successfully',
                'data' => $educationModel->find($educationId)
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Update education error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update education',
                'data' => []
            ], 500);
        }
    }


    /**
     * @OA\Delete(
     *     path="/api/candidate/education/{educationId}",
     *     tags={"Candidate"},
     *     summary="Delete education",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="educationId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Education deleted")
     * )
     */
    public function deleteEducation($educationId = null)
    {
        if ($educationId === null) {
            $educationId = $this->request->getUri()->getSegment(4);
        }

        if (!$educationId) {
            return $this->fail('Education ID is required', 400);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();

            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }

            // Verify education belongs to this candidate
            $educationModel = new Education();
            $existingEducation = $educationModel->where('id', $educationId)
                ->where('candidateId', $profile['id'])
                ->first();

            if (!$existingEducation) {
                return $this->failNotFound('Education not found');
            }

            $educationModel->delete($educationId);

            return $this->respond([
                'success' => true,
                'message' => 'Education deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete education',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/candidate/experience",
     *     tags={"Candidate"},
     *     summary="Add work experience to profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="201", description="Experience added")
     * )
     */
    public function addExperience()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $data = $this->request->getJSON(true);
        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();

        if (!$profile) {
            return $this->fail('Profile not found', 404);
        }

        try {
            // Validate dates
            $startDate = isset($data['startDate']) ? date('Y-m-d', strtotime($data['startDate'])) : null;
            $endDate = isset($data['endDate']) ? date('Y-m-d', strtotime($data['endDate'])) : null;

            if ($endDate && $startDate && strtotime($endDate) < strtotime($startDate)) {
                return $this->fail('End date cannot be before start date', 400);
            }

            if (isset($data['isCurrent']) && $data['isCurrent'] && $endDate) {
                return $this->fail('Cannot have end date for current position', 400);
            }

            $experienceModel = new Experience();
            $experienceData = [
                'id' => uniqid('exp_'),
                'candidateId' => $profile['id'],
                'title' => $data['title'],
                'company' => $data['company'],
                'location' => $data['location'] ?? null,
                'employmentType' => $data['employmentType'] ?? null,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'isCurrent' => $data['isCurrent'] ?? false,
                'description' => $data['description'] ?? null
            ];
            $experienceModel->insert($experienceData);

            // Get created experience
            $createdExperience = $experienceModel->find($experienceData['id']);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Experience added successfully',
                'data' => $createdExperience
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Add experience error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to add experience: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/candidate/experience/{experienceId}",
     *     tags={"Candidate"},
     *     summary="Update work experience",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="experienceId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Experience updated")
     * )
     */
    public function updateExperience($experienceId = null)
    {
        if ($experienceId === null) {
            $experienceId = $this->request->getUri()->getSegment(4);
        }

        if (!$experienceId) {
            return $this->fail('Experience ID is required', 400);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $data = $this->request->getJSON(true);

        try {
            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();

            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }

            // Verify experience belongs to this candidate
            $experienceModel = new Experience();
            $existingExperience = $experienceModel->where('id', $experienceId)
                ->where('candidateId', $profile['id'])
                ->first();

            if (!$existingExperience) {
                return $this->failNotFound('Experience not found');
            }

            // Validate dates
            $startDate = isset($data['startDate']) ? date('Y-m-d', strtotime($data['startDate'])) : $existingExperience['startDate'];
            $endDate = isset($data['endDate']) ? date('Y-m-d', strtotime($data['endDate'])) : ($existingExperience['endDate'] ?? null);

            if ($endDate && $startDate && strtotime($endDate) < strtotime($startDate)) {
                return $this->fail('End date cannot be before start date', 400);
            }

            if (isset($data['isCurrent']) && $data['isCurrent'] && $endDate) {
                return $this->fail('Cannot have end date for current position', 400);
            }

            // Update experience
            $updateData = [
                'title' => $data['title'] ?? $existingExperience['title'],
                'company' => $data['company'] ?? $existingExperience['company'],
                'location' => $data['location'] ?? ($existingExperience['location'] ?? null),
                'employmentType' => $data['employmentType'] ?? ($existingExperience['employmentType'] ?? null),
                'startDate' => $startDate,
                'endDate' => $endDate,
                'isCurrent' => $data['isCurrent'] ?? ($existingExperience['isCurrent'] ?? false),
                'description' => $data['description'] ?? ($existingExperience['description'] ?? null)
            ];

            $experienceModel->update($experienceId, $updateData);

            // Get updated experience
            $updatedExperience = $experienceModel->find($experienceId);

            return $this->respond([
                'success' => true,
                'message' => 'Experience updated successfully',
                'data' => $updatedExperience
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Update experience error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update experience',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/candidate/experience/{experienceId}",
     *     tags={"Candidate"},
     *     summary="Delete work experience",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="experienceId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Experience deleted")
     * )
     */
    public function deleteExperience($experienceId = null)
    {
        if ($experienceId === null) {
            $experienceId = $this->request->getUri()->getSegment(4);
        }

        if (!$experienceId) {
            return $this->fail('Experience ID is required', 400);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();

            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }

            // Verify experience belongs to this candidate
            $experienceModel = new Experience();
            $existingExperience = $experienceModel->where('id', $experienceId)
                ->where('candidateId', $profile['id'])
                ->first();

            if (!$existingExperience) {
                return $this->failNotFound('Experience not found');
            }

            $experienceModel->delete($experienceId);

            return $this->respond([
                'success' => true,
                'message' => 'Experience deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete experience',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/candidate/applications/apply",
     *     tags={"Candidate"},
     *     summary="Apply to a job - SENDS EMAIL TO BOTH CANDIDATE AND ADMIN",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="201", description="Application submitted")
     * )
     */
    public function applyToJob()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $data = $this->request->getJSON(true);
        $applicationModel = new Application();

        try {
            // Validate privacy consent
            if (!isset($data['privacyConsent']) || !$data['privacyConsent']) {
                return $this->fail('You must accept the privacy policy', 400);
            }

            // Get candidate profile
            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();
            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }

            // Check if job exists and is active
            $jobModel = new Job();
            $job = $jobModel->find($data['jobId']);
            if (!$job) {
                return $this->failNotFound('Job not found');
            }
            if ($job['status'] !== 'ACTIVE') {
                return $this->fail('Job not accepting applications', 400);
            }

            // Get company details
            $companyModel = new Company();
            $company = $companyModel->find($job['companyId']);

            // Check for ACTIVE applications only
            $existingActiveApp = $applicationModel
                ->where('jobId', $data['jobId'])
                ->where('candidateId', $user->id)
                ->where('isActive', true)
                ->first();

            // Block if active non-withdrawn application exists
            if ($existingActiveApp && $existingActiveApp['status'] !== 'WITHDRAWN') {
                return $this->fail('You have already applied to this job', 409);
            }

            // Validate resume
            $finalResumeUrl = $data['resumeUrl'] ?? $profile['resumeUrl'] ?? null;
            if (!$finalResumeUrl) {
                return $this->fail('Please upload resume before applying', 400);
            }

            $finalPortfolioUrl = $data['portfolioUrl'] ?? $profile['portfolioUrl'] ?? null;

            // Validate start date
            $parsedStartDate = isset($data['availableStartDate'])
                ? date('Y-m-d H:i:s', strtotime($data['availableStartDate']))
                : date('Y-m-d H:i:s');

            if (date('Y-m-d', strtotime($parsedStartDate)) < date('Y-m-d')) {
                return $this->fail('Start date cannot be in the past', 400);
            }

            // Use transaction
            $db = \Config\Database::connect();
            $db->transStart();

            try {
                // Mark withdrawn app as inactive
                if ($existingActiveApp && $existingActiveApp['status'] === 'WITHDRAWN') {
                    $db->table('applications')
                        ->where('id', $existingActiveApp['id'])
                        ->set(['isActive' => false, 'updatedAt' => date('Y-m-d H:i:s')])
                        ->update();

                    log_message('info', "Marked withdrawn application {$existingActiveApp['id']} as inactive");
                }

                // Create NEW application
                $applicationData = [
                    'id' => uniqid('app_'),
                    'jobId' => $data['jobId'],
                    'candidateId' => $user->id,
                    'coverLetter' => trim($data['coverLetter']),
                    'resumeUrl' => $finalResumeUrl,
                    'portfolioUrl' => $finalPortfolioUrl,
                    'expectedSalary' => trim($data['expectedSalary']),
                    'privacyConsent' => $data['privacyConsent'],
                    'availableStartDate' => $parsedStartDate,
                    'status' => 'PENDING',
                    'isActive' => true,
                    'appliedAt' => date('Y-m-d H:i:s')
                ];
                $applicationModel->insert($applicationData);

                // Update job count only if not replacing withdrawn
                if (!$existingActiveApp || $existingActiveApp['status'] !== 'WITHDRAWN') {
                    $jobModel->update($data['jobId'], [
                        'applicationCount' => ($job['applicationCount'] ?? 0) + 1
                    ]);
                }

                // Create status history
                $statusHistoryModel = new \App\Models\ApplicationStatusHistory();
                $statusHistoryModel->insert([
                    'id' => uniqid('status_'),
                    'applicationId' => $applicationData['id'],
                    'toStatus' => 'PENDING'
                ]);

                $db->transComplete();
                if ($db->transStatus() === false) {
                    throw new \Exception('Transaction failed');
                }

                // ✅ SEND EMAILS

                // 1. Email to CANDIDATE (from headhunting@fortunekenya.com)
                $emailHelper = new EmailHelper();
                $candidateEmailData = [
                    'applicationId' => $applicationData['id'],
                    'candidate' => [
                        'firstName' => $user->firstName ?? '',
                        'lastName' => $user->lastName ?? '',
                        'email' => $user->email ?? ''
                    ],
                    'job' => [
                        'title' => $job['title'] ?? 'Position',
                        'company' => [
                            'name' => $company['name'] ?? 'Company'
                        ]
                    ],
                    'expectedSalary' => $applicationData['expectedSalary'],
                    'availableStartDate' => $applicationData['availableStartDate'],
                    'appliedAt' => $applicationData['appliedAt']
                ];

                $candidateEmailResult = $emailHelper->sendApplicationReceivedEmail($candidateEmailData);
                log_message('info', 'Candidate email result: ' . json_encode($candidateEmailResult));

                // 2. Email to ADMIN (from sales@fortunekenya.com to headhunting@fortunekenya.com)
                $adminEmailData = [
                    'applicationId' => $applicationData['id'],
                    'candidate' => [
                        'firstName' => $user->firstName ?? '',
                        'lastName' => $user->lastName ?? '',
                        'email' => $user->email ?? '',
                        'phone' => $user->phone ?? 'N/A'
                    ],
                    'profile' => [
                        'title' => $profile['title'] ?? 'N/A',
                        'location' => $profile['location'] ?? 'N/A',
                        'experienceYears' => $profile['experienceYears'] ?? '0 years',
                        'resumeUrl' => $finalResumeUrl
                    ],
                    'job' => [
                        'title' => $job['title'] ?? 'Position',
                        'company' => [
                            'name' => $company['name'] ?? 'Company'
                        ]
                    ],
                    'expectedSalary' => $applicationData['expectedSalary'],
                    'availableStartDate' => $applicationData['availableStartDate'],
                    'coverLetter' => $applicationData['coverLetter']
                ];

                $adminEmailResult = $emailHelper->notifyAdminNewApplication($adminEmailData);
                log_message('info', 'Admin email result: ' . json_encode($adminEmailResult));

                // Fetch created application with details
                $createdApplication = $applicationModel
                    ->select('applications.*, jobs.title as jobTitle, jobs.slug as jobSlug, companies.id as companyId, companies.name as companyName, companies.logo as companyLogo, companies.location as companyLocation, job_categories.name as categoryName')
                    ->join('jobs', 'jobs.id = applications.jobId', 'left')
                    ->join('companies', 'companies.id = jobs.companyId', 'left')
                    ->join('job_categories', 'job_categories.id = jobs.categoryId', 'left')
                    ->find($applicationData['id']);

                // Format response
                $formattedApplication = [
                    'id' => $createdApplication['id'],
                    'jobId' => $createdApplication['jobId'],
                    'candidateId' => $createdApplication['candidateId'],
                    'coverLetter' => $createdApplication['coverLetter'],
                    'resumeUrl' => $createdApplication['resumeUrl'],
                    'portfolioUrl' => $createdApplication['portfolioUrl'],
                    'expectedSalary' => $createdApplication['expectedSalary'],
                    'privacyConsent' => $createdApplication['privacyConsent'],
                    'availableStartDate' => $createdApplication['availableStartDate'],
                    'status' => $createdApplication['status'],
                    'appliedAt' => $createdApplication['appliedAt'],
                    'job' => [
                        'id' => $data['jobId'],
                        'title' => $createdApplication['jobTitle'] ?? null,
                        'slug' => $createdApplication['jobSlug'] ?? null,
                        'company' => [
                            'id' => $createdApplication['companyId'] ?? null,
                            'name' => $createdApplication['companyName'] ?? null,
                            'logo' => $createdApplication['companyLogo'] ?? null,
                            'location' => $createdApplication['companyLocation'] ?? null
                        ],
                        'category' => $createdApplication['categoryName'] ?? null
                    ]
                ];

                return $this->respondCreated([
                    'success' => true,
                    'message' => 'Application submitted successfully. Confirmation emails sent.',
                    'data' => $formattedApplication
                ]);
            } catch (\Exception $e) {
                $db->transRollback();
                log_message('error', 'Apply transaction error: ' . $e->getMessage());
                throw $e;
            }
        } catch (\Exception $e) {
            log_message('error', 'Apply error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to submit application',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/candidate/applications",
     *     tags={"Candidate"},
     *     summary="Get all candidate applications",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Applications retrieved")
     * )
     */
    public function getApplications()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            $applicationModel = new Application();

            // Only fetch ACTIVE applications for candidates
            $applicationModel->where('candidateId', $user->id)
                ->where('isActive', true);

            // Apply filters
            $status = $this->request->getGet('status');
            $jobId = $this->request->getGet('jobId');

            if ($status) {
                $applicationModel->where('status', $status);
            }
            if ($jobId) {
                $applicationModel->where('jobId', $jobId);
            }

            $applications = $applicationModel->orderBy('appliedAt', 'DESC')->findAll();

            // Enrich with job details
            $jobModel = new Job();
            $companyModel = new Company();
            $categoryModel = new \App\Models\JobCategory();
            $statusHistoryModel = new \App\Models\ApplicationStatusHistory();

            $formattedApplications = [];
            foreach ($applications as $app) {
                $job = $jobModel->find($app['jobId']);
                $formattedApp = $app;

                if ($job) {
                    $company = $companyModel->find($job['companyId']);
                    $category = $categoryModel->find($job['categoryId']);

                    $formattedApp['job'] = [
                        'id' => $job['id'],
                        'title' => $job['title'],
                        'slug' => $job['slug'] ?? null,
                        'company' => $company ? [
                            'id' => $company['id'],
                            'name' => $company['name'],
                            'logo' => $company['logo'] ?? null,
                            'location' => $company['location'] ?? null
                        ] : null,
                        'category' => $category ? [
                            'id' => $category['id'],
                            'name' => $category['name']
                        ] : null
                    ];
                }

                // Status history
                $statusHistory = $statusHistoryModel->where('applicationId', $app['id'])
                    ->orderBy('changedAt', 'DESC')
                    ->limit(5)
                    ->findAll();
                $formattedApp['statusHistory'] = $statusHistory;

                $formattedApplications[] = $formattedApp;
            }

            // Calculate stats from ACTIVE applications only
            $activeStatuses = ['PENDING', 'REVIEWED', 'SHORTLISTED', 'INTERVIEW', 'INTERVIEWED', 'OFFERED'];

            $stats = [
                'total' => count(array_filter($applications, fn($a) => in_array($a['status'] ?? '', $activeStatuses))),
                'pending' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'PENDING')),
                'reviewed' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'REVIEWED')),
                'shortlisted' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'SHORTLISTED')),
                'interview' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'INTERVIEW')),
                'interviewed' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'INTERVIEWED')),
                'offered' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'OFFERED')),
                'accepted' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'ACCEPTED')),
                'rejected' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'REJECTED')),
                'withdrawn' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'WITHDRAWN'))
            ];

            return $this->respond([
                'success' => true,
                'message' => 'Applications retrieved successfully',
                'data' => [
                    'applications' => $formattedApplications,
                    'stats' => $stats
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get applications error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve applications',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/candidate/applications/{applicationId}",
     *     tags={"Candidate"},
     *     summary="Get single application details",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="applicationId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Application retrieved")
     * )
     */
    public function getApplication($applicationId = null)
    {
        if ($applicationId === null) {
            $applicationId = $this->request->getUri()->getSegment(4);
        }

        if (!$applicationId) {
            return $this->fail('Application ID is required', 400);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $applicationModel = new Application();
            $application = $applicationModel->find($applicationId);

            if (!$application) {
                return $this->failNotFound('Application not found');
            }

            // Verify application belongs to this candidate
            if ($application['candidateId'] !== $user->id) {
                return $this->fail('You do not have access to this application', 400);
            }

            // Format application with job details
            $jobModel = new Job();
            $companyModel = new Company();
            $categoryModel = new \App\Models\JobCategory();
            $statusHistoryModel = new \App\Models\ApplicationStatusHistory();

            $formattedApp = $application;

            // Get job details with company, category, and skills
            $job = $jobModel->find($application['jobId']);
            if ($job) {
                $company = $companyModel->find($job['companyId']);
                $category = $categoryModel->find($job['categoryId']);

                // Get job skills
                $jobSkillModel = new \App\Models\JobSkill();
                $jobSkills = $jobSkillModel->where('jobId', $job['id'])->findAll();
                $skills = [];
                foreach ($jobSkills as $js) {
                    $skillModel = new Skill();
                    $skill = $skillModel->find($js['skillId']);
                    if ($skill) {
                        $skills[] = [
                            'id' => $js['id'],
                            'jobId' => $js['jobId'],
                            'skillId' => $js['skillId'],
                            'required' => $js['required'] ?? false,
                            'createdAt' => $js['createdAt'] ?? null,
                            'skill' => $skill
                        ];
                    }
                }

                $formattedApp['job'] = [
                    'id' => $job['id'],
                    'title' => $job['title'],
                    'slug' => $job['slug'] ?? null,
                    'company' => $company ? [
                        'id' => $company['id'],
                        'name' => $company['name'],
                        'logo' => $company['logo'] ?? null,
                        'location' => $company['location'] ?? null
                    ] : null,
                    'category' => $category ? [
                        'id' => $category['id'],
                        'name' => $category['name']
                    ] : null,
                    'skills' => $skills
                ];
            }

            // Get status history
            $statusHistory = $statusHistoryModel->where('applicationId', $applicationId)
                ->orderBy('changedAt', 'DESC')
                ->findAll();
            $formattedApp['statusHistory'] = $statusHistory;

            return $this->respond([
                'success' => true,
                'message' => 'Application retrieved successfully',
                'data' => $formattedApp
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get application error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve application',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/candidate/applications/{applicationId}/withdraw",
     *     tags={"Candidate"},
     *     summary="Withdraw application",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="applicationId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Application withdrawn")
     * )
     */
    public function withdrawApplication($applicationId = null)
    {
        if ($applicationId === null) {
            $applicationId = $this->request->getUri()->getSegment(4);
        }

        if (!$applicationId) {
            return $this->fail('Application ID is required', 400);
        }

        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $applicationModel = new Application();
            $application = $applicationModel->find($applicationId);

            if (!$application) {
                return $this->failNotFound('Application not found');
            }

            // Verify application belongs to this candidate
            if ($application['candidateId'] !== $user->id) {
                return $this->fail('You do not have access to this application', 400);
            }

            // Check if already withdrawn
            if ($application['status'] === 'WITHDRAWN') {
                return $this->fail('Application already withdrawn', 400);
            }

            // Check if accepted (cannot withdraw)
            if ($application['status'] === 'ACCEPTED') {
                return $this->fail('Cannot withdraw an accepted application', 400);
            }

            // Update application with transaction
            $db = \Config\Database::connect();
            $db->transStart();

            try {
                // Update application status
                $applicationModel->update($applicationId, ['status' => 'WITHDRAWN']);

                // Create status history
                $statusHistoryModel = new \App\Models\ApplicationStatusHistory();
                $statusHistoryModel->insert([
                    'id' => uniqid('status_'),
                    'applicationId' => $applicationId,
                    'fromStatus' => $application['status'],
                    'toStatus' => 'WITHDRAWN'
                ]);

                $db->transComplete();

                if ($db->transStatus() === false) {
                    throw new \Exception('Transaction failed');
                }

                return $this->respond([
                    'success' => true,
                    'message' => 'Application withdrawn successfully'
                ]);
            } catch (\Exception $e) {
                $db->transRollback();
                throw $e;
            }
        } catch (\Exception $e) {
            log_message('error', 'Withdraw application error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to withdraw application',
                'data' => []
            ], 500);
        }
    }

    public function addPublication()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $data = $this->request->getJSON(true);

        if (empty(trim($data['title'] ?? '')))
            return $this->fail('Title is required', 400);
        if (empty($data['year']))
            return $this->fail('Year is required', 400);

        $model = new \App\Models\CandidatePublication();
        $id = uniqid('pub_');

        $model->insert([
            'id' => $id,
            'candidateId' => $profile['id'],
            'title' => trim($data['title']),
            'type' => $data['type'] ?? 'Journal',
            'journalOrPublisher' => $data['journalOrPublisher'] ?? null,
            'year' => $data['year'],
            'link' => $data['link'] ?? null,
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respondCreated(['success' => true, 'message' => 'Publication added', 'data' => $model->find($id)]);
    }

    public function updatePublication($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        if (!$id)
            return $this->fail('Publication ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidatePublication();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Publication not found');

        $data = $this->request->getJSON(true);

        $model->update($id, [
            'title' => isset($data['title']) ? trim($data['title']) : $existing['title'],
            'type' => $data['type'] ?? $existing['type'],
            'journalOrPublisher' => array_key_exists('journalOrPublisher', $data) ? $data['journalOrPublisher'] : $existing['journalOrPublisher'],
            'year' => $data['year'] ?? $existing['year'],
            'link' => array_key_exists('link', $data) ? $data['link'] : $existing['link'],
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond(['success' => true, 'message' => 'Publication updated', 'data' => $model->find($id)]);
    }

    public function deletePublication($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Publication ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidatePublication();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Publication not found');

        $model->delete($id);
        return $this->respond(['success' => true, 'message' => 'Publication deleted']);
    }

    public function addMembership()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $data = $this->request->getJSON(true);
        if (empty(trim($data['bodyName'] ?? '')))
            return $this->fail('Body name is required', 400);

        $model = new \App\Models\CandidateMembership();
        $id = uniqid('mem_');

        $model->insert([
            'id' => $id,
            'candidateId' => $profile['id'],
            'bodyName' => trim($data['bodyName']),
            'membershipNumber' => $data['membershipNumber'] ?? null,
            'isActive' => isset($data['isActive']) ? (int) !!$data['isActive'] : 1,
            'goodStanding' => isset($data['goodStanding']) ? (int) !!$data['goodStanding'] : 1,
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respondCreated(['success' => true, 'message' => 'Membership added', 'data' => $model->find($id)]);
    }

    public function updateMembership($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Membership ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateMembership();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Membership not found');

        $data = $this->request->getJSON(true);

        $model->update($id, [
            'bodyName' => isset($data['bodyName']) ? trim($data['bodyName']) : $existing['bodyName'],
            'membershipNumber' => array_key_exists('membershipNumber', $data) ? $data['membershipNumber'] : $existing['membershipNumber'],
            'isActive' => array_key_exists('isActive', $data) ? (int) !!$data['isActive'] : $existing['isActive'],
            'goodStanding' => array_key_exists('goodStanding', $data) ? (int) !!$data['goodStanding'] : $existing['goodStanding'],
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond(['success' => true, 'message' => 'Membership updated', 'data' => $model->find($id)]);
    }

    public function deleteMembership($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Membership ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateMembership();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Membership not found');

        $model->delete($id);
        return $this->respond(['success' => true, 'message' => 'Membership deleted']);
    }

    public function addClearance()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $data = $this->request->getJSON(true);

        if (empty($data['type']))
            return $this->fail('Type is required', 400);
        if (empty($data['issueDate']))
            return $this->fail('Issue date is required', 400);

        $model = new \App\Models\CandidateClearance();
        $id = uniqid('clr_');

        $model->insert([
            'id' => $id,
            'candidateId' => $profile['id'],
            'type' => $data['type'],
            'certificateNumber' => $data['certificateNumber'] ?? null,
            'issueDate' => date('Y-m-d', strtotime($data['issueDate'])),
            'expiryDate' => !empty($data['expiryDate']) ? date('Y-m-d', strtotime($data['expiryDate'])) : null,
            'status' => $data['status'] ?? 'PENDING',
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respondCreated(['success' => true, 'message' => 'Clearance added', 'data' => $model->find($id)]);
    }

    public function updateClearance($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Clearance ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateClearance();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Clearance not found');

        $data = $this->request->getJSON(true);

        $model->update($id, [
            'type' => $data['type'] ?? $existing['type'],
            'certificateNumber' => array_key_exists('certificateNumber', $data) ? $data['certificateNumber'] : $existing['certificateNumber'],
            'issueDate' => array_key_exists('issueDate', $data) ? date('Y-m-d', strtotime($data['issueDate'])) : $existing['issueDate'],
            'expiryDate' => array_key_exists('expiryDate', $data)
                ? (!empty($data['expiryDate']) ? date('Y-m-d', strtotime($data['expiryDate'])) : null)
                : $existing['expiryDate'],
            'status' => $data['status'] ?? $existing['status'],
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond(['success' => true, 'message' => 'Clearance updated', 'data' => $model->find($id)]);
    }

    public function deleteClearance($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Clearance ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateClearance();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Clearance not found');

        $model->delete($id);
        return $this->respond(['success' => true, 'message' => 'Clearance deleted']);
    }

    public function addCourse()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $data = $this->request->getJSON(true);
        if (empty(trim($data['name'] ?? '')))
            return $this->fail('Course name is required', 400);
        if (empty(trim($data['institution'] ?? '')))
            return $this->fail('Institution is required', 400);
        if (!isset($data['durationWeeks']))
            return $this->fail('durationWeeks is required', 400);
        if (empty($data['year']))
            return $this->fail('year is required', 400);

        $model = new \App\Models\CandidateCourse();
        $id = uniqid('crs_');

        $model->insert([
            'id' => $id,
            'candidateId' => $profile['id'],
            'name' => trim($data['name']),
            'institution' => trim($data['institution']),
            'durationWeeks' => (int) $data['durationWeeks'],
            'year' => $data['year'],
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respondCreated(['success' => true, 'message' => 'Course added', 'data' => $model->find($id)]);
    }

    public function updateCourse($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Course ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateCourse();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Course not found');

        $data = $this->request->getJSON(true);
        $model->update($id, [
            'name' => isset($data['name']) ? trim($data['name']) : $existing['name'],
            'institution' => isset($data['institution']) ? trim($data['institution']) : $existing['institution'],
            'durationWeeks' => array_key_exists('durationWeeks', $data) ? (int) $data['durationWeeks'] : $existing['durationWeeks'],
            'year' => $data['year'] ?? $existing['year'],
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond(['success' => true, 'message' => 'Course updated', 'data' => $model->find($id)]);
    }

    public function deleteCourse($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Course ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateCourse();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Course not found');

        $model->delete($id);
        return $this->respond(['success' => true, 'message' => 'Course deleted']);
    }

    public function addReferee()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $data = $this->request->getJSON(true);

        // ── ALL FIELDS NOW REQUIRED ────────────────────────────────────────────
        if (empty(trim($data['name'] ?? '')))
            return $this->fail('Name is required', 400);
        if (empty(trim($data['position'] ?? '')))
            return $this->fail('Position is required', 400);
        if (empty(trim($data['organization'] ?? '')))
            return $this->fail('Organization is required', 400);
        if (empty(trim($data['phone'] ?? '')))
            return $this->fail('Phone number is required', 400);
        if (empty(trim($data['email'] ?? '')))
            return $this->fail('Email is required', 400);
        if (empty(trim($data['relationship'] ?? '')))
            return $this->fail('Relationship is required', 400);
        // ── MEMBERSHIP NUMBER validation moved here from Memberships (already required in addMembership) ──
        // ─────────────────────────────────────────────────────────────────────

        $model = new \App\Models\CandidateReferee();
        $id = uniqid('ref_');

        $model->insert([
            'id' => $id,
            'candidateId' => $profile['id'],
            'name' => trim($data['name']),
            'position' => trim($data['position']),
            'organization' => trim($data['organization']),
            'phone' => trim($data['phone']),
            'email' => trim($data['email']),
            // ── NEW: relationship ─────────────────────────────────────────────
            'relationship' => trim($data['relationship']),
            // ─────────────────────────────────────────────────────────────────
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respondCreated(['success' => true, 'message' => 'Referee added', 'data' => $model->find($id)]);
    }

    public function updateReferee($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Referee ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateReferee();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Referee not found');

        $data = $this->request->getJSON(true);

        $model->update($id, [
            'name' => isset($data['name']) ? trim($data['name']) : $existing['name'],
            'position' => array_key_exists('position', $data) ? trim((string) $data['position']) : $existing['position'],
            'organization' => array_key_exists('organization', $data) ? trim((string) $data['organization']) : $existing['organization'],
            'phone' => array_key_exists('phone', $data) ? trim((string) $data['phone']) : $existing['phone'],
            'email' => array_key_exists('email', $data) ? trim((string) $data['email']) : $existing['email'],
            // ── NEW: relationship ─────────────────────────────────────────────
            'relationship' => array_key_exists('relationship', $data) ? trim((string) $data['relationship']) : ($existing['relationship'] ?? null),
            // ─────────────────────────────────────────────────────────────────
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond(['success' => true, 'message' => 'Referee updated', 'data' => $model->find($id)]);
    }

    public function deleteReferee($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('Referee ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateReferee();
        $existing = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$existing)
            return $this->failNotFound('Referee not found');

        $model->delete($id);
        return $this->respond(['success' => true, 'message' => 'Referee deleted']);
    }
    public function getPersonalInfo()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidatePersonalInfo();
        $row = $model->where('candidateId', $profile['id'])->first();

        return $this->respond(['success' => true, 'message' => 'Personal info', 'data' => $row ?? []]);
    }
    public function upsertPersonalInfo()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $data = $this->request->getJSON(true);

        $required = ['fullName', 'dob', 'gender', 'idNumber', 'nationality', 'countyOfOrigin'];
        foreach ($required as $f) {
            if (!isset($data[$f]) || trim((string) $data[$f]) === '') {
                return $this->fail("$f is required", 400);
            }
        }

        // basic gender guard
        if (!in_array($data['gender'], ['M', 'F', 'Other'], true)) {
            return $this->fail("gender must be M, F or Other", 400);
        }

        $model = new \App\Models\CandidatePersonalInfo();
        $existing = $model->where('candidateId', $profile['id'])->first();

        $payload = [
            'candidateId' => $profile['id'],
            'fullName' => trim($data['fullName']),
            'dob' => date('Y-m-d', strtotime($data['dob'])),
            'gender' => $data['gender'],
            'idNumber' => trim($data['idNumber']),
            'nationality' => trim($data['nationality']),
            'countyOfOrigin' => trim($data['countyOfOrigin']),
            'plwd' => isset($data['plwd']) ? (int) !!$data['plwd'] : 0,
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $model->update($existing['id'], $payload);
            $row = $model->find($existing['id']);
        } else {
            $id = uniqid('pinfo_');
            $payload['id'] = $id;
            $model->insert($payload);
            $row = $model->find($id);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Personal info saved',
            'data' => $row
        ]);
    }

    public function uploadCandidateFile()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid())
            return $this->fail('No file uploaded', 400);

        $category = $this->request->getPost('category');
        $title = $this->request->getPost('title');

        $allowedCategories = [
            'NATIONAL_ID',
            'CV',
            'ACADEMIC_CERT',
            'PROFESSIONAL_CERT',
            'TESTIMONIAL',
            'CLEARANCE_CERT',
            'DRIVING_LICENSE',
            'PUBLICATION_EVIDENCE',
            'COVER_LETTER',
            'OTHER'
        ];

        if (!$category || !in_array($category, $allowedCategories, true))
            return $this->fail('Invalid category', 400);

        // Limit size
        $maxBytes = 10 * 1024 * 1024; // 10MB
        if ($file->getSize() > $maxBytes)
            return $this->fail('File exceeds 10MB limit', 400);

        $uploadPath = WRITEPATH . 'uploads/candidate-files/';
        if (!is_dir($uploadPath))
            mkdir($uploadPath, 0755, true);

        // Sanitize filename
        $originalName = $file->getClientName();
        $pathInfo = pathinfo($originalName);
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $pathInfo['filename'] ?? 'file');
        $ext = $pathInfo['extension'] ?? '';
        $newName = $base . '_' . time() . ($ext ? '.' . $ext : '');

        $model = new \App\Models\CandidateFile();

        // ─────────────────────────────────────────────────────────────────────────
        // REPLACEMENT LOGIC
        //
        // For every category (including OTHER), if the candidate already has a file
        // with the SAME original filename + category, replace it (delete old physical
        // file + DB record) so you never accumulate duplicates of the same upload.
        //
        // For non-OTHER categories we ALSO replace ALL files in that category
        // (one-file-per-category rule), matching the original behaviour.
        // ─────────────────────────────────────────────────────────────────────────

        $deletePhysical = function (array $existing) use ($model): void {
            try {
                $physicalPath = WRITEPATH . ltrim(
                    str_replace('/uploads/', 'uploads/', $existing['fileUrl']),
                    '/'
                );
                if (is_file($physicalPath)) {
                    @unlink($physicalPath);
                }
            } catch (\Exception $e) {
                // ignore — DB record will still be removed
            }
            $model->delete($existing['id']);
        };

        if ($category === 'OTHER') {
            // For OTHER: only replace if the same filename already exists for this candidate
            $sameNameFile = $model
                ->where('candidateId', $profile['id'])
                ->where('category', $category)
                ->where('fileName', $originalName)
                ->first();

            if ($sameNameFile) {
                $deletePhysical($sameNameFile);
            }
        } else {
            // For all other categories: replace every existing file in that slot
            $existingFiles = $model
                ->where('candidateId', $profile['id'])
                ->where('category', $category)
                ->findAll();

            foreach ($existingFiles as $existing) {
                $deletePhysical($existing);
            }
        }

        // Move the new file only after old ones are cleaned up
        $file->move($uploadPath, $newName);

        $id = uniqid('cfile_');
        $row = [
            'id' => $id,
            'candidateId' => $profile['id'],
            'category' => $category,
            'title' => $title ? trim($title) : null,
            'fileName' => $originalName,
            'fileUrl' => '/uploads/candidate-files/' . $newName,
            'mimeType' => $file->getClientMimeType(),
            'fileSize' => $file->getSize(),
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        $model->insert($row);

        return $this->respondCreated([
            'success' => true,
            'message' => 'File uploaded successfully',
            'data' => $model->find($id),
        ]);
    }

    public function listCandidateFiles()
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $category = $this->request->getGet('category');

        $model = new \App\Models\CandidateFile();
        $model->where('candidateId', $profile['id']);

        if ($category) {
            $model->where('category', $category);
        }

        $rows = $model->orderBy('createdAt', 'DESC')->findAll();

        return $this->respond([
            'success' => true,
            'message' => 'Files retrieved',
            'data' => $rows
        ]);
    }

    public function deleteCandidateFile($id = null)
    {
        $user = $this->request->user ?? null;
        if (!$user)
            return $this->fail('Unauthorized', 401);
        if (!$id)
            return $this->fail('File ID is required', 400);

        [$profile, $resp] = $this->getCandidateProfileOrFail($user);
        if (!$profile)
            return $resp;

        $model = new \App\Models\CandidateFile();
        $row = $model->where('id', $id)->where('candidateId', $profile['id'])->first();
        if (!$row)
            return $this->failNotFound('File not found');

        // Optionally delete physical file
        try {
            $filePath = WRITEPATH . ltrim(str_replace('/uploads/', 'uploads/', $row['fileUrl']), '/');
            if (is_file($filePath))
                @unlink($filePath);
        } catch (\Exception $e) {
            // ignore file delete errors; DB delete still OK
        }

        $model->delete($id);

        return $this->respond([
            'success' => true,
            'message' => 'File deleted'
        ]);
    }

}