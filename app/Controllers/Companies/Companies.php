<?php

namespace App\Controllers\Companies;

use App\Controllers\BaseController;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Companies",
 *     description="Company management endpoints"
 * )
 */
class Companies extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/companies/setup",
     *     tags={"Companies"},
     *     summary="First-time company setup for employers",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "industry", "companySize"},
     *             @OA\Property(property="name", type="string", description="Company name", example="Acme Corporation"),
     *             @OA\Property(property="description", type="string", description="Company description"),
     *             @OA\Property(property="website", type="string", format="uri", description="Company website URL"),
     *             @OA\Property(property="email", type="string", format="email", description="Company email", example="contact@acme.com"),
     *             @OA\Property(property="phone", type="string", description="Company phone number"),
     *             @OA\Property(property="logo", type="string", description="Logo URL"),
     *             @OA\Property(property="coverImage", type="string", description="Cover image URL"),
     *             @OA\Property(property="location", type="string", description="Company location"),
     *             @OA\Property(property="industry", type="string", description="Industry", example="Finance"),
     *             @OA\Property(property="companySize", type="string", description="Company size", example="51-200 employees"),
     *             @OA\Property(property="socialLinks", type="object", description="Social media links", @OA\Property(property="linkedin", type="string"), @OA\Property(property="twitter", type="string"), @OA\Property(property="facebook", type="string"), @OA\Property(property="instagram", type="string")),
     *             @OA\Property(property="title", type="string", description="Contact person title", example="HR Manager"),
     *             @OA\Property(property="department", type="string", description="Department")
     *         )
     *     ),
     *     @OA\Response(response="201", description="Company created")
     * )
     */
    public function setupCompany()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $data = $this->request->getJSON(true);
        $companyModel = new Company();

        try {
            // Check if employer already has a company
            $employerModel = new EmployerProfile();
            $existingProfile = $employerModel->where('userId', $user->id)->first();

            if ($existingProfile) {
                return $this->fail('You already have a company profile', 400);
            }

            // Get user email
            $userModel = new \App\Models\User();
            $userData = $userModel->select('email')->find($user->id);

            if (!$userData) {
                return $this->failNotFound('User not found');
            }

            // Generate unique slug 
            $baseSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $data['name']));
            $baseSlug = preg_replace('/^-|-$/', '', $baseSlug);
            $slug = $baseSlug . '-' . time();

            // Convert socialLinks to JSON if it's an array/object
            if (isset($data['socialLinks']) && (is_array($data['socialLinks']) || is_object($data['socialLinks']))) {
                $data['socialLinks'] = json_encode($data['socialLinks']);
            }

            // Use transaction to ensure both operations succeed or fail together 
            $db = \Config\Database::connect();
            $db->transStart();

            try {
                // Create company
                $companyData = [
                    'id' => uniqid('company_'),
                    'name' => $data['name'],
                    'slug' => $slug,
                    'description' => $data['description'] ?? null,
                    'website' => $data['website'] ?? null,
                    'email' => $data['email'] ?? $userData['email'],
                    'phone' => $data['phone'] ?? null,
                    'logo' => $data['logo'] ?? null,
                    'coverImage' => $data['coverImage'] ?? null,
                    'location' => $data['location'],
                    'industry' => $data['industry'],
                    'companySize' => $data['companySize'],
                    'socialLinks' => $data['socialLinks'] ?? null,
                    'status' => 'VERIFIED',
                    'verified' => true,
                    'verifiedAt' => date('Y-m-d H:i:s')
                ];
                $companyModel->insert($companyData);

                // Create employer profile
                $employerData = [
                    'id' => uniqid('employer_'),
                    'userId' => $user->id,
                    'companyId' => $companyData['id'],
                    'title' => $data['title'] ?? 'Company Administrator',
                    'department' => $data['department'] ?? null,
                    'canPostJobs' => true,
                    'canViewCVs' => true
                ];
                $employerModel->insert($employerData);

                $db->transComplete();

                if ($db->transStatus() === false) {
                    throw new \Exception('Transaction failed');
                }

                // Get created company and employer profile 
                $createdCompany = $companyModel->find($companyData['id']);
                $createdEmployer = $employerModel->find($employerData['id']);

                return $this->respondCreated([
                    'success' => true,
                    'message' => 'Company setup completed successfully',
                    'data' => [
                        'company' => $createdCompany,
                        'employerProfile' => $createdEmployer
                    ]
                ]);
            } catch (\Exception $e) {
                $db->transRollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to setup company',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/companies/me/profile",
     *     tags={"Companies"},
     *     summary="Get my company details",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Company retrieved")
     * )
     */
    public function getMyCompany()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $employerModel = new EmployerProfile();
            $employer = $employerModel->where('userId', $user->id)->first();

            if (!$employer) {
                // Node.js returns success: true with data: null if no profile found
                return $this->respond([
                    'success' => true,
                    'message' => 'No company profile found',
                    'data' => null
                ]);
            }

            $companyModel = new Company();
            $company = $companyModel->find($employer['companyId']);

            if (!$company) {
                return $this->failNotFound('Company not found');
            }

            // Get job counts and employer profile counts 
            $jobModel = new \App\Models\Job();
            $jobCount = $jobModel->where('companyId', $company['id'])->countAllResults(false);

            $employerCount = $employerModel->where('companyId', $company['id'])->countAllResults(false);

            $company['_count'] = [
                'jobs' => $jobCount,
                'employerProfiles' => $employerCount
            ];

            return $this->respond([
                'success' => true,
                'message' => 'Company retrieved successfully',
                'data' => $company
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve company',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/companies/me",
     *     tags={"Companies"},
     *     summary="Update my company",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", description="Company name"),
     *             @OA\Property(property="description", type="string", description="Company description"),
     *             @OA\Property(property="website", type="string", format="uri", description="Company website URL"),
     *             @OA\Property(property="email", type="string", format="email", description="Company email"),
     *             @OA\Property(property="phone", type="string", description="Company phone number"),
     *             @OA\Property(property="logo", type="string", description="Logo URL"),
     *             @OA\Property(property="coverImage", type="string", description="Cover image URL"),
     *             @OA\Property(property="location", type="string", description="Company location"),
     *             @OA\Property(property="industry", type="string", description="Industry"),
     *             @OA\Property(property="companySize", type="string", description="Company size"),
     *             @OA\Property(property="socialLinks", type="object", description="Social media links")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Company updated")
     * )
     */
    public function updateMyCompany()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        try {
            $data = $this->request->getJSON(true);

            $employerModel = new EmployerProfile();
            $employer = $employerModel->where('userId', $user->id)->first();

            if (!$employer) {
                return $this->failNotFound('Employer profile not found');
            }

            // Convert socialLinks to JSON if it's an array/object
            $updateData = [];
            foreach ($data as $key => $value) {
                if ($key === 'socialLinks') {
                    if ($value === null) {
                        $updateData[$key] = null;
                    } elseif (is_array($value) || is_object($value)) {
                        $updateData[$key] = json_encode($value);
                    } else {
                        $updateData[$key] = $value;
                    }
                } elseif ($value !== null) {  // Changed from 'undefined' to 'null'
                    $updateData[$key] = $value;
                }
            }

            $companyModel = new Company();
            $companyModel->update($employer['companyId'], $updateData);

            // Get updated company 
            $updatedCompany = $companyModel->find($employer['companyId']);

            return $this->respond([
                'success' => true,
                'message' => 'Company updated successfully',
                'data' => $updatedCompany
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update company',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/companies/me/stats",
     *     tags={"Companies"},
     *     summary="Get my company statistics",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Stats retrieved")
     * )
     */
    public function getMyCompanyStats()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            $employerModel = new EmployerProfile();
            $employer = $employerModel->where('userId', $user->id)->first();

            if (!$employer) {
                return $this->failNotFound('Employer profile not found');
            }

            $jobModel = new \App\Models\Job();
            $applicationModel = new \App\Models\Application();

            // Get stats matching Node.js structure
            $activeJobs = $jobModel->where('companyId', $employer['companyId'])
                ->where('status', 'ACTIVE')
                ->countAllResults(false);

            $totalJobs = $jobModel->where('companyId', $employer['companyId'])
                ->countAllResults(false);

            // Get total applications (matching Node.js - using subquery to avoid join issues)
            $db = \Config\Database::connect();
            $totalApplications = $db->table('applications')
                ->join('jobs', 'jobs.id = applications.jobId', 'inner')
                ->where('jobs.companyId', $employer['companyId'])
                ->countAllResults(false);

            // Get recent applications (last 10) - matching Node.js structure
            $recentApplicationsRaw = $db->table('applications')
                ->select('applications.id, applications.status, applications.appliedAt, 
                         users.firstName, users.lastName, users.email, users.avatar,
                         jobs.title, jobs.slug')
                ->join('jobs', 'jobs.id = applications.jobId', 'inner')
                ->join('users', 'users.id = applications.candidateId', 'left')
                ->where('jobs.companyId', $employer['companyId'])
                ->orderBy('applications.appliedAt', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();

            // Format recent applications to match Node.js structure
            $recentFormatted = [];
            foreach ($recentApplicationsRaw as $app) {
                $recentFormatted[] = [
                    'id' => $app['id'],
                    'status' => $app['status'],
                    'appliedAt' => $app['appliedAt'],
                    'candidate' => [
                        'firstName' => $app['firstName'] ?? null,
                        'lastName' => $app['lastName'] ?? null,
                        'email' => $app['email'] ?? null,
                        'avatar' => $app['avatar'] ?? null,
                    ],
                    'job' => [
                        'title' => $app['title'] ?? null,
                        'slug' => $app['slug'] ?? null,
                    ]
                ];
            }

            return $this->respond([
                'success' => true,
                'message' => 'Company stats retrieved successfully',
                'data' => [
                    'activeJobs' => $activeJobs,
                    'totalJobs' => $totalJobs,
                    'totalApplications' => $totalApplications,
                    'recentApplications' => $recentFormatted
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve company stats',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/companies/admin/all",
     *     tags={"Companies"},
     *     summary="Get all companies (admin only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"PENDING", "VERIFIED", "SUSPENDED", "REJECTED"}), description="Filter by status"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(response="200", description="Companies retrieved")
     * )
     */
    public function getAllCompaniesAdmin()
    {
        try {
            $companyModel = new Company();

            // Get filters
            $status = $this->request->getGet('status');
            $industry = $this->request->getGet('industry');
            $search = $this->request->getGet('search');

            if ($status) {
                $companyModel->where('status', $status);
            }
            if ($industry) {
                $companyModel->where('industry', $industry);
            }
            if ($search) {
                $companyModel->groupStart()
                    ->like('name', $search)
                    ->orLike('email', $search)
                    ->groupEnd();
            }

            $companies = $companyModel->orderBy('createdAt', 'DESC')->findAll();

            // Format companies to include employer profiles and counts 
            $employerModel = new EmployerProfile();
            $jobModel = new \App\Models\Job();
            $userModel = new \App\Models\User();

            foreach ($companies as &$company) {
                // Get employer profiles with user data
                $employers = $employerModel->where('companyId', $company['id'])->findAll();
                $employersWithUsers = [];
                foreach ($employers as $employer) {
                    $user = $userModel->select('id, email, firstName, lastName')->find($employer['userId']);
                    $employer['user'] = $user;
                    $employersWithUsers[] = $employer;
                }
                $company['employerProfiles'] = $employersWithUsers;

                // Get counts
                $jobCount = $jobModel->where('companyId', $company['id'])->countAllResults(false);
                $employerCount = count($employers);
                $company['_count'] = [
                    'jobs' => $jobCount,
                    'employerProfiles' => $employerCount
                ];
            }

            return $this->respond([
                'success' => true,
                'message' => 'Companies retrieved successfully',
                'data' => $companies
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve companies',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/companies/admin/pending",
     *     tags={"Companies"},
     *     summary="Get pending companies (admin only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Pending companies retrieved")
     * )
     */
    public function getPendingCompanies()
    {
        $companyModel = new Company();
        $companies = $companyModel->where('status', 'PENDING')->findAll();

        return $this->respond([
            'success' => true,
            'data' => $companies
        ]);
    }

    /**
     * @OA\Patch(
     *     path="/api/companies/admin/{id}/verify",
     *     tags={"Companies"},
     *     summary="Verify/Reject company (admin only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"VERIFIED", "REJECTED", "PENDING"}, description="Verification status", example="VERIFIED"),
     *             @OA\Property(property="rejectionReason", type="string", description="Rejection reason (if status is REJECTED)", example="Incomplete documentation")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Company verification updated")
     * )
     */
    public function verifyCompany($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(3);
        }

        if (!$id) {
            return $this->fail('Company ID is required', 400);
        }

        $data = $this->request->getJSON(true);
        $companyModel = new Company();

        $updateData = [
            'status' => $data['status'],
            'verified' => $data['status'] === 'VERIFIED',
            'verifiedAt' => $data['status'] === 'VERIFIED' ? date('Y-m-d H:i:s') : null
        ];

        $companyModel->update($id, $updateData);

        return $this->respond([
            'success' => true,
            'message' => 'Company verification updated'
        ]);
    }

    /**
     * @OA\Patch(
     *     path="/api/companies/admin/{id}/suspend",
     *     tags={"Companies"},
     *     summary="Suspend company (admin only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="reason", type="string", description="Suspension reason (optional)", example="Violation of terms")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Company suspended")
     * )
     */
    public function suspendCompany($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(3);
        }

        if (!$id) {
            return $this->fail('Company ID is required', 400);
        }

        $data = $this->request->getJSON(true);
        $companyModel = new Company();

        $companyModel->update($id, [
            'status' => 'SUSPENDED'
        ]);

        return $this->respond([
            'success' => true,
            'message' => 'Company suspended'
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/companies/admin/{id}",
     *     tags={"Companies"},
     *     summary="Force update company (admin override)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", description="Company name"),
     *             @OA\Property(property="description", type="string", description="Company description"),
     *             @OA\Property(property="website", type="string", format="uri", description="Company website URL"),
     *             @OA\Property(property="email", type="string", format="email", description="Company email"),
     *             @OA\Property(property="phone", type="string", description="Company phone number"),
     *             @OA\Property(property="logo", type="string", description="Logo URL"),
     *             @OA\Property(property="coverImage", type="string", description="Cover image URL"),
     *             @OA\Property(property="location", type="string", description="Company location"),
     *             @OA\Property(property="industry", type="string", description="Industry"),
     *             @OA\Property(property="companySize", type="string", description="Company size"),
     *             @OA\Property(property="status", type="string", enum={"PENDING", "VERIFIED", "SUSPENDED", "REJECTED"}),
     *             @OA\Property(property="verified", type="boolean", description="Verification status"),
     *             @OA\Property(property="socialLinks", type="object", description="Social media links")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Company updated")
     * )
     */
    public function forceUpdateCompany($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(3);
        }

        if (!$id) {
            return $this->fail('Company ID is required', 400);
        }

        $data = $this->request->getJSON(true);

        // Convert socialLinks to JSON if it's an array/object
        if (isset($data['socialLinks']) && (is_array($data['socialLinks']) || is_object($data['socialLinks']))) {
            $data['socialLinks'] = json_encode($data['socialLinks']);
        }

        $companyModel = new Company();
        $companyModel->update($id, $data);

        return $this->respond([
            'success' => true,
            'message' => 'Company updated'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/companies/admin/{id}",
     *     tags={"Companies"},
     *     summary="Get company by ID (Admin)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Company retrieved")
     * )
     */
    public function getCompanyById($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(3);
        }

        if (!$id) {
            return $this->fail('Company ID is required', 400);
        }

        $companyModel = new Company();
        $company = $companyModel->find($id);

        if (!$company) {
            return $this->failNotFound('Company not found');
        }

        return $this->respond([
            'success' => true,
            'data' => $company
        ]);
    }
}
