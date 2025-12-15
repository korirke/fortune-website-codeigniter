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
            
            // Get related data matching Node.js structure
            $userModel = new User();
            $userData = $userModel->select('id, email, firstName, lastName, phone, avatar, emailVerified, createdAt')
                ->find($user->id);
            
            if (!$userData) {
                return $this->failNotFound('User not found');
            }
            
            // Get skills with skill details (matching Node.js - wrapped in { skill: ... })
            $candidateSkillModel = new CandidateSkill();
            $candidateSkills = $candidateSkillModel->where('candidateId', $profile['id'])
                ->orderBy('createdAt', 'DESC')
                ->findAll();
            
            $skillModel = new Skill();
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
            
            // Get domains with domain details (matching Node.js - wrapped in { domain: ... })
            $candidateDomainModel = new CandidateDomain();
            $candidateDomains = $candidateDomainModel->where('candidateId', $profile['id'])
                ->orderBy('isPrimary', 'DESC')
                ->findAll();
            
            $domainModel = new Domain();
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
            
            // Get educations (matching Node.js)
            $educationModel = new Education();
            $educations = $educationModel->where('candidateId', $profile['id'])
                ->orderBy('startDate', 'DESC')
                ->findAll();
            
            // Get experiences (matching Node.js)
            $experienceModel = new Experience();
            $experiences = $experienceModel->where('candidateId', $profile['id'])
                ->orderBy('startDate', 'DESC')
                ->findAll();
            
            // Get certifications (matching Node.js)
            $certificationModel = new \App\Models\Certification();
            $certifications = $certificationModel->where('candidateId', $profile['id'])
                ->orderBy('issueDate', 'DESC')
                ->findAll();
            
            // Get languages (matching Node.js - wrapped in { language: ... })
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
                // If languages table doesn't exist or query fails, just set empty array
                $languages = [];
            }
            
            // Get resumes (latest 5) (matching Node.js)
            $resumeModel = new ResumeVersion();
            $resumes = $resumeModel->where('candidateId', $profile['id'])
                ->orderBy('version', 'DESC')
                ->limit(5)
                ->findAll();
            
            // Build response matching Node.js structure exactly
            $profileData = $profile;
            $profileData['user'] = $userData;
            $profileData['skills'] = $skills;
            $profileData['domains'] = $domains;
            $profileData['educations'] = $educations;
            $profileData['experiences'] = $experiences;
            $profileData['certifications'] = $certifications;
            $profileData['languages'] = $languages;
            $profileData['resumes'] = $resumes;
            
            return $this->respond([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => $profileData
            ]);
        } catch (\Exception $e) {
            // Log error for debugging
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
            
            $profile = $profileModel->where('userId', $user->id)->first();
            if (!$profile) {
                return $this->failNotFound('Candidate profile not found');
            }
            
            // Validate required fields (matching Node.js)
            $requiredFields = ['title', 'bio', 'location', 'phone', 'experienceYears'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || $data[$field] === null || (is_string($data[$field]) && trim($data[$field]) === '')) {
                    $missingFields[] = $field;
                }
            }
            
            if (!empty($missingFields)) {
                return $this->fail('Missing required fields: ' . implode(', ', $missingFields), 400);
            }
            
            // Validate phone format (matching Node.js)
            if (isset($data['phone']) && !preg_match('/^\+?[1-9]\d{1,14}$/', $data['phone'])) {
                return $this->fail('Please provide a valid phone number with country code (e.g., +254712345678)', 400);
            }
            
            // Handle date conversion
            if (isset($data['availableFrom'])) {
                $data['availableFrom'] = date('Y-m-d', strtotime($data['availableFrom']));
            }
            
            // Update profile
            $profileModel->update($profile['id'], $data);
            
            // Get updated profile with user data (matching Node.js)
            $updatedProfile = $profileModel->where('userId', $user->id)->first();
            $userModel = new User();
            $userData = $userModel->select('firstName, lastName, email, phone')
                ->find($user->id);
            
            $updatedProfile['user'] = $userData;
            
            return $this->respond([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $updatedProfile
            ]);
        } catch (\Exception $e) {
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

        $newName = $file->getRandomName();
        $file->move($uploadPath, $newName);

        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();
        
        if (!$profile) {
            return $this->failNotFound('Candidate profile not found');
        }
        
        // Calculate next version number
        $resumeModel = new ResumeVersion();
        $latestResume = $resumeModel->where('candidateId', $profile['id'])
            ->orderBy('version', 'DESC')
            ->first();
        
        $nextVersion = $latestResume ? ((int)$latestResume['version'] + 1) : 1;

        // Use transaction to ensure both operations succeed or fail together (matching Node.js)
        $db = \Config\Database::connect();
        $db->transStart();
        
        try {
            $resumeData = [
                'id' => uniqid('resume_'),
                'candidateId' => $profile['id'],
                'version' => $nextVersion,
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
            
            // Get created resume (matching Node.js response)
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
        
        // Validate skillName (matching Node.js)
        if (empty($data['skillName']) || !isset($data['skillName'])) {
            return $this->fail('Skill name is required', 400);
        }
        
        $profileModel = new CandidateProfile();
        $profile = $profileModel->where('userId', $user->id)->first();
        
        if (!$profile) {
            return $this->failNotFound('Candidate profile not found');
        }

        try {
            // Find or create skill (matching Node.js)
            $skillName = trim($data['skillName']);
            // Generate slug exactly like Node.js: lowercase -> replace spaces with hyphen -> remove non-alphanumeric (except hyphen)
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

            // Check if skill already exists for this candidate (matching Node.js)
            $candidateSkillModel = new CandidateSkill();
            $existing = $candidateSkillModel->where('candidateId', $profile['id'])
                ->where('skillId', $skill['id'])
                ->first();
            
            if ($existing) {
                return $this->fail('Skill already added to your profile', 409);
            }

            // Add skill to candidate profile (matching Node.js - uses candidateId)
            $candidateSkillData = [
                'id' => uniqid('cskill_'),
                'candidateId' => $profile['id'],
                'skillId' => $skill['id'],
                'level' => $data['level'] ?? null,
                'yearsOfExp' => $data['yearsOfExp'] ?? null
            ];
            $candidateSkillModel->insert($candidateSkillData);
            
            // Get created candidate skill with skill details (matching Node.js structure)
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
            // Check if domain exists (matching Node.js)
            $domainModel = new Domain();
            $domain = $domainModel->find($data['domainId']);
            
            if (!$domain) {
                return $this->failNotFound('Domain not found');
            }

            // Check if domain already exists for this candidate (matching Node.js)
            $candidateDomainModel = new CandidateDomain();
            $existing = $candidateDomainModel->where('candidateId', $profile['id'])
                ->where('domainId', $data['domainId'])
                ->first();
            
            if ($existing) {
                return $this->fail('Domain already added to your profile', 409);
            }

            // Use transaction if setting as primary (matching Node.js)
            $isPrimary = $data['isPrimary'] ?? false;
            $db = \Config\Database::connect();
            $db->transStart();
            
            try {
                // If setting as primary, unset other primary domains (matching Node.js)
                if ($isPrimary) {
                    $candidateDomainModel->where('candidateId', $profile['id'])
                        ->where('isPrimary', true)
                        ->set(['isPrimary' => false])
                        ->update();
                }

                // Add domain (matching Node.js)
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
                
                // Get created domain with domain details (matching Node.js structure)
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
            // Validate dates (matching Node.js)
            $startDate = isset($data['startDate']) ? date('Y-m-d', strtotime($data['startDate'])) : null;
            $endDate = isset($data['endDate']) ? date('Y-m-d', strtotime($data['endDate'])) : null;
            
            if ($endDate && $startDate && strtotime($endDate) < strtotime($startDate)) {
                return $this->fail('End date cannot be before start date', 400);
            }
            
            if (isset($data['isCurrent']) && $data['isCurrent'] && $endDate) {
                return $this->fail('Cannot have end date for current education', 400);
            }

            $educationModel = new Education();
            $educationData = [
                'id' => uniqid('edu_'),
                'candidateId' => $profile['id'],
                'degree' => $data['degree'],
                'fieldOfStudy' => $data['fieldOfStudy'],
                'institution' => $data['institution'],
                'location' => $data['location'] ?? null,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'isCurrent' => $data['isCurrent'] ?? false,
                'grade' => $data['grade'] ?? null,
                'description' => $data['description'] ?? null
            ];
            $educationModel->insert($educationData);
            
            // Get created education (matching Node.js)
            $createdEducation = $educationModel->find($educationData['id']);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Education added successfully',
                'data' => $createdEducation
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
        
        if (!$educationId) {
            return $this->fail('Education ID is required', 400);
        }
        
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $data = $this->request->getJSON(true);
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
            
            // Validate dates (matching Node.js)
            $startDate = isset($data['startDate']) ? date('Y-m-d', strtotime($data['startDate'])) : $existingEducation['startDate'];
            $endDate = isset($data['endDate']) ? date('Y-m-d', strtotime($data['endDate'])) : $existingEducation['endDate'];
            
            if ($endDate && $startDate && strtotime($endDate) < strtotime($startDate)) {
                return $this->fail('End date cannot be before start date', 400);
            }
            
            if (isset($data['isCurrent']) && $data['isCurrent'] && $endDate) {
                return $this->fail('Cannot have end date for current education', 400);
            }
            
            $updateData = [
                'degree' => $data['degree'] ?? $existingEducation['degree'],
                'fieldOfStudy' => $data['fieldOfStudy'] ?? $existingEducation['fieldOfStudy'],
                'institution' => $data['institution'] ?? $existingEducation['institution'],
                'location' => $data['location'] ?? $existingEducation['location'],
                'startDate' => $startDate,
                'endDate' => $endDate,
                'isCurrent' => $data['isCurrent'] ?? $existingEducation['isCurrent'],
                'grade' => $data['grade'] ?? $existingEducation['grade'],
                'description' => $data['description'] ?? $existingEducation['description']
            ];
            
            $educationModel->update($educationId, $updateData);
            
            // Get updated education (matching Node.js)
            $updatedEducation = $educationModel->find($educationId);

            return $this->respond([
                'success' => true,
                'message' => 'Education updated successfully',
                'data' => $updatedEducation
            ]);
        } catch (\Exception $e) {
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
            // Validate dates (matching Node.js)
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
            
            // Get created experience (matching Node.js)
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
            
            // Verify experience belongs to this candidate (matching Node.js)
            $experienceModel = new Experience();
            $existingExperience = $experienceModel->where('id', $experienceId)
                ->where('candidateId', $profile['id'])
                ->first();
            
            if (!$existingExperience) {
                return $this->failNotFound('Experience not found');
            }
            
            // Validate dates (matching Node.js)
            $startDate = isset($data['startDate']) ? date('Y-m-d', strtotime($data['startDate'])) : $existingExperience['startDate'];
            $endDate = isset($data['endDate']) ? date('Y-m-d', strtotime($data['endDate'])) : ($existingExperience['endDate'] ?? null);
            
            if ($endDate && $startDate && strtotime($endDate) < strtotime($startDate)) {
                return $this->fail('End date cannot be before start date', 400);
            }
            
            if (isset($data['isCurrent']) && $data['isCurrent'] && $endDate) {
                return $this->fail('Cannot have end date for current position', 400);
            }
            
            // Update experience (matching Node.js)
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
            
            // Get updated experience (matching Node.js)
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
     *     summary="Apply to a job",
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
            // Validate privacy consent (matching Node.js)
            if (!isset($data['privacyConsent']) || !$data['privacyConsent']) {
                return $this->fail('You must accept the privacy policy to submit your application', 400);
            }
            
            // Get candidate profile
            $profileModel = new CandidateProfile();
            $profile = $profileModel->where('userId', $user->id)->first();
            
            if (!$profile) {
                return $this->failNotFound('Candidate profile not found. Please complete your profile first.');
            }
            
            // Check if job exists and is active
            $jobModel = new Job();
            $job = $jobModel->find($data['jobId']);
            
            if (!$job) {
                return $this->failNotFound('Job not found');
            }
            
            if ($job['status'] !== 'ACTIVE') {
                return $this->fail('This job is not currently accepting applications', 400);
            }
            
            // Check if already applied
            $existing = $applicationModel->where('jobId', $data['jobId'])
                ->where('candidateId', $user->id)
                ->first();
            
            if ($existing) {
                return $this->fail('You have already applied to this job', 409);
            }
            
            // Use resume URL from DTO, fallback to profile, validate exists
            $finalResumeUrl = $data['resumeUrl'] ?? $profile['resumeUrl'] ?? null;
            if (!$finalResumeUrl) {
                return $this->fail('Please upload your resume before applying to jobs', 400);
            }
            
            // Use portfolio from DTO if provided, otherwise from profile
            $finalPortfolioUrl = $data['portfolioUrl'] ?? $profile['portfolioUrl'] ?? null;
            
            // Parse and validate available start date
            $parsedStartDate = isset($data['availableStartDate']) ? date('Y-m-d H:i:s', strtotime($data['availableStartDate'])) : date('Y-m-d H:i:s');
            $today = date('Y-m-d');
            if (date('Y-m-d', strtotime($parsedStartDate)) < $today) {
                return $this->fail('Available start date cannot be in the past', 400);
            }
            
            // Use transaction to ensure both operations succeed or fail together (matching Node.js)
            $db = \Config\Database::connect();
            $db->transStart();
            
            try {
                // Create application
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
                    'appliedAt' => date('Y-m-d H:i:s')
                ];
                $applicationModel->insert($applicationData);
                
                // Update job application count
                $currentCount = $job['applicationCount'] ?? 0;
                $jobModel->update($data['jobId'], [
                    'applicationCount' => $currentCount + 1
                ]);
                
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
                
                // Get created application with job and company details (matching Node.js)
                $createdApplication = $applicationModel->select('applications.*, jobs.title as jobTitle, jobs.slug as jobSlug, companies.id as companyId, companies.name as companyName, companies.logo as companyLogo, companies.location as companyLocation, job_categories.name as categoryName')
                    ->join('jobs', 'jobs.id = applications.jobId', 'left')
                    ->join('companies', 'companies.id = jobs.companyId', 'left')
                    ->join('job_categories', 'job_categories.id = jobs.categoryId', 'left')
                    ->find($applicationData['id']);
                
                // Format response matching Node.js structure
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
                    'message' => 'Application submitted successfully',
                    'data' => $formattedApplication
                ]);
            } catch (\Exception $e) {
                $db->transRollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to submit application. Please try again.',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/candidate/applications",
     *     tags={"Candidate"},
     *     summary="Get all my applications",
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
            $applicationModel->where('candidateId', $user->id);
            
            // Get filters
            $status = $this->request->getGet('status');
            $jobId = $this->request->getGet('jobId');
            
            if ($status) {
                $applicationModel->where('status', $status);
            }
            if ($jobId) {
                $applicationModel->where('jobId', $jobId);
            }
            
            $applications = $applicationModel->orderBy('appliedAt', 'DESC')->findAll();
            
            // Format applications with job details (matching Node.js)
            $jobModel = new Job();
            $companyModel = new Company();
            $categoryModel = new \App\Models\JobCategory();
            
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
                
                // Get status history (last 5)
                $statusHistoryModel = new \App\Models\ApplicationStatusHistory();
                $statusHistory = $statusHistoryModel->where('applicationId', $app['id'])
                    ->orderBy('changedAt', 'DESC')
                    ->limit(5)
                    ->findAll();
                $formattedApp['statusHistory'] = $statusHistory;
                
                $formattedApplications[] = $formattedApp;
            }
            
            // Calculate stats (matching Node.js)
            $stats = [
                'total' => count($applications),
                'pending' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'PENDING')),
                'reviewed' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'REVIEWED')),
                'shortlisted' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'SHORTLISTED')),
                'interview' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'INTERVIEW')),
                'offered' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'OFFERED')),
                'accepted' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'ACCEPTED')),
                'rejected' => count(array_filter($applications, fn($a) => ($a['status'] ?? '') === 'REJECTED'))
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

            // Verify application belongs to this candidate (matching Node.js)
            if ($application['candidateId'] !== $user->id) {
                return $this->fail('You do not have access to this application', 400);
            }
            
            // Format application with job details (matching Node.js)
            $jobModel = new Job();
            $companyModel = new Company();
            $categoryModel = new \App\Models\JobCategory();
            $statusHistoryModel = new \App\Models\ApplicationStatusHistory();
            
            $formattedApp = $application;
            
            // Get job details with company, category, and skills (matching Node.js)
            $job = $jobModel->find($application['jobId']);
            if ($job) {
                $company = $companyModel->find($job['companyId']);
                $category = $categoryModel->find($job['categoryId']);
                
                // Get job skills (matching Node.js)
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
            
            // Get status history (matching Node.js)
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

            // Verify application belongs to this candidate (matching Node.js)
            if ($application['candidateId'] !== $user->id) {
                return $this->fail('You do not have access to this application', 400);
            }

            // Check if already withdrawn (matching Node.js)
            if ($application['status'] === 'WITHDRAWN') {
                return $this->fail('Application already withdrawn', 400);
            }

            // Check if accepted (cannot withdraw) (matching Node.js)
            if ($application['status'] === 'ACCEPTED') {
                return $this->fail('Cannot withdraw an accepted application', 400);
            }

            // Update application with transaction (matching Node.js)
            $db = \Config\Database::connect();
            $db->transStart();
            
            try {
                // Update application status
                $applicationModel->update($applicationId, ['status' => 'WITHDRAWN']);

                // Create status history (matching Node.js)
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
}