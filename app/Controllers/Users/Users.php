<?php

namespace App\Controllers\Users;

use App\Controllers\BaseController;
use App\Models\User;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Users Management",
 *     description="User management endpoints"
 * )
 */
class Users extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/users",
     *     tags={"Users Management"},
     *     summary="Get all users with pagination",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Parameter(name="role", in="query", required=false, @OA\Schema(type="string"), description="Filter by role"),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string"), description="Filter by status"),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="Search by name or email"),
     *     @OA\Parameter(name="scope", in="query", required=false, @OA\Schema(type="string", enum={"website", "recruitment"}), description="Filter by scope"),
     *     @OA\Response(response="200", description="Users retrieved successfully")
     * )
     */
    public function getAllUsers()
    {
        try {
            $userModel = new User();
            
            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 10);
            $skip = ($page - 1) * $limit;
            
            // Build filters (matching Node.js)
            $role = $this->request->getGet('role');
            $status = $this->request->getGet('status');
            $search = $this->request->getGet('search');
            $scope = $this->request->getGet('scope');
            
            // Apply scope filter (matching Node.js)
            if ($scope === 'website') {
                $userModel->whereIn('role', ['SUPER_ADMIN', 'WEBSITE_ADMIN']);
            } elseif ($scope === 'recruitment') {
                $userModel->whereIn('role', ['CANDIDATE', 'EMPLOYER', 'MODERATOR', 'HR_MANAGER', 'SUPER_ADMIN']);
            }
            
            if ($role) {
                $userModel->where('role', $role);
            }
            if ($status) {
                $userModel->where('status', $status);
            }
            if ($search) {
                $userModel->groupStart()
                    ->like('firstName', $search)
                    ->orLike('lastName', $search)
                    ->orLike('email', $search)
                    ->groupEnd();
            }
            
            // Get total count
            $total = $userModel->countAllResults(false);
            
            // Get paginated results
            $users = $userModel
                ->orderBy('createdAt', 'DESC')
                ->findAll($limit, $skip);
            
            // Format users with candidateProfile, employerProfile, _count (matching Node.js)
            $candidateProfileModel = new \App\Models\CandidateProfile();
            $employerProfileModel = new \App\Models\EmployerProfile();
            $applicationModel = new \App\Models\Application();
            $jobModel = new \App\Models\Job();
            
            $formattedUsers = [];
            foreach ($users as $user) {
                $formattedUser = [
                    'id' => $user['id'],
                    'firstName' => $user['firstName'],
                    'lastName' => $user['lastName'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'status' => $user['status'],
                    'phone' => $user['phone'] ?? null,
                    'avatar' => $user['avatar'] ?? null,
                    'emailVerified' => (bool)($user['emailVerified'] ?? false),
                    'lastLoginAt' => $user['lastLoginAt'] ?? null,
                    'createdAt' => $user['createdAt'],
                    'updatedAt' => $user['updatedAt']
                ];
                
                // Get candidate profile (matching Node.js - included for all users, null if not exists)
                $candidateProfile = $candidateProfileModel->where('userId', $user['id'])->first();
                if ($candidateProfile) {
                    $formattedUser['candidateProfile'] = [
                        'id' => $candidateProfile['id'],
                        'title' => $candidateProfile['title'] ?? null,
                        'location' => $candidateProfile['location'] ?? null,
                        'openToWork' => (bool) ($candidateProfile['openToWork'] ?? false)
                    ];
                } else {
                    $formattedUser['candidateProfile'] = null;
                }
                
                // Get employer profile (matching Node.js - included for all users, null if not exists)
                $employerProfile = $employerProfileModel->where('userId', $user['id'])->first();
                if ($employerProfile) {
                    $companyModel = new \App\Models\Company();
                    $company = $companyModel->select('id, name, logo')->find($employerProfile['companyId']);
                    $formattedUser['employerProfile'] = [
                        'id' => $employerProfile['id'],
                        'company' => $company ? [
                            'id' => $company['id'],
                            'name' => $company['name'],
                            'logo' => $company['logo'] ?? null
                        ] : null
                    ];
                } else {
                    $formattedUser['employerProfile'] = null;
                }
                
                // Get counts
                $appCount = $applicationModel->where('candidateId', $user['id'])->countAllResults(false);
                $jobCount = $jobModel->where('postedById', $user['id'])->countAllResults(false);
                $formattedUser['_count'] = [
                    'applications' => (int)$appCount,
                    'postedJobs' => (int)$jobCount
                ];
                
                $formattedUsers[] = $formattedUser;
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Users retrieved successfully',
                'data' => $formattedUsers,
                'pagination' => [
                    'total' => (int) $total,
                    'page' => (int) $page,
                    'limit' => (int) $limit,
                    'totalPages' => (int) ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get all users error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve users',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/users/stats",
     *     tags={"Users Management"},
     *     summary="Get user statistics",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="scope", in="query", required=false, @OA\Schema(type="string", enum={"website", "recruitment"})),
     *     @OA\Response(response="200", description="Statistics retrieved successfully")
     * )
     */
    public function getUserStats()
    {
        try {
            $userModel = new User();
            $scope = $this->request->getGet('scope');
            
            // Apply scope filter (matching Node.js)
            $where = [];
            if ($scope === 'website') {
                $where['role'] = ['SUPER_ADMIN', 'WEBSITE_ADMIN'];
            } elseif ($scope === 'recruitment') {
                $where['role'] = ['CANDIDATE', 'EMPLOYER', 'MODERATOR', 'HR_MANAGER', 'SUPER_ADMIN'];
            }
            
            // Get stats by role and status (matching Node.js)
            $db = \Config\Database::connect();
            
            // By role
            $byRoleQuery = $db->table('users')
                ->select('role, COUNT(*) as count')
                ->groupBy('role');
            if (!empty($where['role'])) {
                $byRoleQuery->whereIn('role', $where['role']);
            }
            $byRoleRaw = $byRoleQuery->get()->getResultArray();
            $byRole = [];
            foreach ($byRoleRaw as $item) {
                $byRole[$item['role']] = (int) $item['count'];
            }
            
            // By status
            $byStatusQuery = $db->table('users')
                ->select('status, COUNT(*) as count')
                ->groupBy('status');
            if (!empty($where['role'])) {
                $byStatusQuery->whereIn('role', $where['role']);
            }
            $byStatusRaw = $byStatusQuery->get()->getResultArray();
            $byStatus = [];
            foreach ($byStatusRaw as $item) {
                $byStatus[$item['status']] = (int) $item['count'];
            }
            
            // Total counts
            $totalQuery = $db->table('users');
            if (!empty($where['role'])) {
                $totalQuery->whereIn('role', $where['role']);
            }
            $total = $totalQuery->countAllResults(false);
            
            $activeQuery = $db->table('users')->where('status', 'ACTIVE');
            if (!empty($where['role'])) {
                $activeQuery->whereIn('role', $where['role']);
            }
            $active = $activeQuery->countAllResults(false);
            
            $stats = [
                'total' => (int)$total,
                'active' => (int)$active,
                'byRole' => $byRole,
                'byStatus' => $byStatus
            ];

            return $this->respond([
                'success' => true,
                'message' => 'User stats retrieved successfully',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get user stats error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve user stats',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/users/{id}",
     *     tags={"Users Management"},
     *     summary="Get user by ID",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="User retrieved successfully"),
     *     @OA\Response(response="404", description="User not found")
     * )
     */
    public function getUserById($id = null)
    {
        if ($id === null) {
            $segments = $this->request->getUri()->getSegments();
            $usersIndex = array_search('users', $segments);
            if ($usersIndex !== false && isset($segments[$usersIndex + 1])) {
                $id = $segments[$usersIndex + 1];
            }
        }
        
        if (!$id || in_array($id, ['stats'])) {
            return $this->fail('User ID is required', 400);
        }
        
        try {
            $userModel = new User();
            $user = $userModel->find($id);

            if (!$user) {
                return $this->failNotFound('User not found');
            }
            
            // Format user with candidateProfile, employerProfile, _count (matching Node.js)
            $formattedUser = [
                'id' => $user['id'],
                'firstName' => $user['firstName'],
                'lastName' => $user['lastName'],
                'email' => $user['email'],
                'role' => $user['role'],
                'status' => $user['status'],
                'phone' => $user['phone'] ?? null,
                'avatar' => $user['avatar'] ?? null,
                'emailVerified' => (bool)($user['emailVerified'] ?? false),
                'lastLoginAt' => $user['lastLoginAt'] ?? null,
                'lastLoginIp' => $user['lastLoginIp'] ?? null,
                'createdAt' => $user['createdAt'],
                'updatedAt' => $user['updatedAt']
            ];
            
            // Get candidate profile if exists
            if ($user['role'] === 'CANDIDATE') {
                $candidateProfileModel = new \App\Models\CandidateProfile();
                $candidateProfile = $candidateProfileModel->where('userId', $user['id'])->first();
                if ($candidateProfile) {
                    $formattedUser['candidateProfile'] = [
                        'id' => $candidateProfile['id'],
                        'title' => $candidateProfile['title'] ?? null,
                        'bio' => $candidateProfile['bio'] ?? null,
                        'location' => $candidateProfile['location'] ?? null,
                        'openToWork' => (bool)($candidateProfile['openToWork'] ?? false),
                        'resumeUrl' => $candidateProfile['resumeUrl'] ?? null,
                        'experienceYears' => $candidateProfile['experienceYears'] ?? null,
                        'expectedSalary' => $candidateProfile['expectedSalary'] ?? null,
                        'currency' => $candidateProfile['currency'] ?? null
                    ];
                } else {
                    $formattedUser['candidateProfile'] = null;
                }
            }
            
            // Get employer profile if exists
            if ($user['role'] === 'EMPLOYER') {
                $employerProfileModel = new \App\Models\EmployerProfile();
                $employerProfile = $employerProfileModel->where('userId', $user['id'])->first();
                if ($employerProfile) {
                    $companyModel = new \App\Models\Company();
                    $company = $companyModel->select('id, name, logo, website, industry, companySize')
                        ->find($employerProfile['companyId']);
                    $formattedUser['employerProfile'] = [
                        'id' => $employerProfile['id'],
                        'title' => $employerProfile['title'] ?? null,
                        'department' => $employerProfile['department'] ?? null,
                        'canPostJobs' => (bool)($employerProfile['canPostJobs'] ?? false),
                        'canViewCVs' => (bool)($employerProfile['canViewCVs'] ?? false),
                        'company' => $company
                    ];
                } else {
                    $formattedUser['employerProfile'] = null;
                }
            }
            
            // Get counts
            $applicationModel = new \App\Models\Application();
            $jobModel = new \App\Models\Job();
            $appCount = $applicationModel->where('candidateId', $user['id'])->countAllResults(false);
            $jobCount = $jobModel->where('postedById', $user['id'])->countAllResults(false);
            $formattedUser['_count'] = [
                'applications' => (int)$appCount,
                'postedJobs' => (int)$jobCount,
                'subscriptions' => 0,
                'notifications' => 0
            ];

            return $this->respond([
                'success' => true,
                'data' => $formattedUser
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get user by ID error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve user',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/users",
     *     tags={"Users Management"},
     *     summary="Create new user",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"firstName", "lastName", "email", "password", "role"},
     *             @OA\Property(property="firstName", type="string", example="John"),
     *             @OA\Property(property="lastName", type="string", example="Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john.doe@example.com"),
     *             @OA\Property(property="password", type="string", example="SecurePass123"),
     *             @OA\Property(property="role", type="string", enum={"CANDIDATE", "EMPLOYER", "SUPER_ADMIN", "MODERATOR", "HR_MANAGER", "WEBSITE_ADMIN"}, example="CANDIDATE"),
     *             @OA\Property(property="phone", type="string", example="+1234567890")
     *         )
     *     ),
     *     @OA\Response(response="201", description="User created successfully"),
     *     @OA\Response(response="409", description="Email already in use")
     * )
     */
    public function createUser()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }
            
            $data = $this->request->getJSON(true);
            
            // Validate required fields
            if (empty($data['firstName']) || empty($data['lastName']) || 
                empty($data['email']) || empty($data['password']) || empty($data['role'])) {
                return $this->fail('Missing required fields', 400);
            }
            
            $userModel = new User();
            
            // Check if email already exists (matching Node.js)
            $existing = $userModel->where('email', $data['email'])->first();
            if ($existing) {
                return $this->fail('Email already in use', 409);
            }
            
            $userData = [
                'id' => uniqid('user_'),
                'firstName' => trim($data['firstName']),
                'lastName' => trim($data['lastName']),
                'email' => strtolower(trim($data['email'])),
                'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'role' => $data['role'],
                'phone' => $data['phone'] ?? null,
                'status' => 'ACTIVE'
            ];
            $userModel->insert($userData);
            
            // Create candidate profile if role is CANDIDATE (matching Node.js)
            if ($data['role'] === 'CANDIDATE') {
                $candidateProfileModel = new \App\Models\CandidateProfile();
                $candidateProfileModel->insert([
                    'id' => uniqid('candidate_'),
                    'userId' => $userData['id']
                ]);
            }
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $user->id,
                'action' => 'CREATE_USER',
                'resource' => 'USER',
                'resourceId' => $userData['id'],
                'module' => 'SYSTEM',
                'details' => json_encode(['email' => $userData['email'], 'role' => $userData['role']])
            ]);
            
            // Get created user (without password, matching Node.js)
            $createdUser = $userModel->select('id, firstName, lastName, email, role, status, phone, createdAt, updatedAt')
                ->find($userData['id']);

            return $this->respondCreated([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $createdUser
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Create user error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to create user',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/users/{id}",
     *     tags={"Users Management"},
     *     summary="Update user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="firstName", type="string"),
     *             @OA\Property(property="lastName", type="string"),
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="password", type="string"),
     *             @OA\Property(property="phone", type="string")
     *         )
     *     ),
     *     @OA\Response(response="200", description="User updated successfully")
     * )
     */
    public function updateUser($id = null)
    {
        if ($id === null) {
            $segments = $this->request->getUri()->getSegments();
            $usersIndex = array_search('users', $segments);
            if ($usersIndex !== false && isset($segments[$usersIndex + 1])) {
                $id = $segments[$usersIndex + 1];
            }
        }
        
        if (!$id) {
            return $this->fail('User ID is required', 400);
        }
        
        $data = $this->request->getJSON(true);
        
        if (empty($data) || !is_array($data)) {
            return $this->fail('No data provided for update', 400);
        }
        
        // Filter out null and empty string values
        $data = array_filter($data, function($value) {
            return $value !== null && $value !== '';
        });
        
        if (empty($data)) {
            return $this->fail('No valid data provided for update', 400);
        }
        
        try {
            $currentUser = $this->request->user ?? null;
            if (!$currentUser) {
                return $this->fail('Unauthorized', 401);
            }
            
            $userModel = new User();
            $user = $userModel->find($id);
            
            if (!$user) {
                return $this->failNotFound('User not found');
            }
            
            // Check if email is being changed and already exists (matching Node.js)
            if (isset($data['email']) && $data['email'] !== $user['email']) {
                $existing = $userModel->where('email', $data['email'])->first();
                if ($existing) {
                    return $this->fail('Email already in use', 409);
                }
            }
            
            // Hash password if provided
            if (isset($data['password'])) {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            // Trim string fields
            if (isset($data['firstName'])) $data['firstName'] = trim($data['firstName']);
            if (isset($data['lastName'])) $data['lastName'] = trim($data['lastName']);
            if (isset($data['email'])) $data['email'] = strtolower(trim($data['email']));
            
            $userModel->update($id, $data);
            
            // Get updated user (without password, matching Node.js)
            $updatedUser = $userModel->select('id, firstName, lastName, email, role, status, phone, createdAt, updatedAt')
                ->find($id);
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $currentUser->id,
                'action' => 'UPDATE_USER',
                'resource' => 'USER',
                'resourceId' => $id,
                'module' => 'SYSTEM',
                'details' => json_encode(['changes' => array_keys($data)])
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $updatedUser
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Update user error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update user',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/users/{id}/suspend",
     *     tags={"Users Management"},
     *     summary="Suspend user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="reason", type="string", example="Violation of terms")
     *         )
     *     ),
     *     @OA\Response(response="200", description="User suspended successfully")
     * )
     */
    public function suspendUser($id = null)
    {
        try {
            if ($id === null) {
                $segments = $this->request->getUri()->getSegments();
                $usersIndex = array_search('users', $segments);
                if ($usersIndex !== false && isset($segments[$usersIndex + 1])) {
                    $id = $segments[$usersIndex + 1];
                }
            }
            
            if (!$id) {
                return $this->fail('User ID is required', 400);
            }
            
            $currentUser = $this->request->user ?? null;
            if (!$currentUser) {
                return $this->fail('Unauthorized', 401);
            }
            
            $userModel = new User();
            $user = $userModel->find($id);
            
            if (!$user) {
                return $this->failNotFound('User not found');
            }
            
            // Cannot suspend own account (matching Node.js)
            if ($id === $currentUser->id) {
                return $this->fail('Cannot suspend your own account', 400);
            }
            
            // Only SUPER_ADMIN can suspend SUPER_ADMIN (matching Node.js)
            if ($user['role'] === 'SUPER_ADMIN') {
                $currentUserData = $userModel->select('role')->find($currentUser->id);
                if ($currentUserData['role'] !== 'SUPER_ADMIN') {
                    return $this->fail('Only SUPER_ADMIN can suspend SUPER_ADMIN accounts', 403);
                }
            }
            
            $data = $this->request->getJSON(true);
            $reason = $data['reason'] ?? null;
            
            $userModel->update($id, ['status' => 'SUSPENDED']);
            
            // Get updated user (matching Node.js)
            $updatedUser = $userModel->select('id, firstName, lastName, email, role, status')
                ->find($id);
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $currentUser->id,
                'action' => 'SUSPEND_USER',
                'resource' => 'USER',
                'resourceId' => $id,
                'module' => 'SYSTEM',
                'details' => json_encode([
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'reason' => $reason ?? 'No reason provided'
                ])
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'User suspended successfully',
                'data' => $updatedUser
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Suspend user error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to suspend user',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/users/{id}/activate",
     *     tags={"Users Management"},
     *     summary="Activate user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="User activated successfully")
     * )
     */
    public function activateUser($id = null)
    {
        try {
            if ($id === null) {
                $segments = $this->request->getUri()->getSegments();
                $usersIndex = array_search('users', $segments);
                if ($usersIndex !== false && isset($segments[$usersIndex + 1])) {
                    $id = $segments[$usersIndex + 1];
                }
            }
            
            if (!$id) {
                return $this->fail('User ID is required', 400);
            }
            
            $currentUser = $this->request->user ?? null;
            if (!$currentUser) {
                return $this->fail('Unauthorized', 401);
            }
            
            $userModel = new User();
            $user = $userModel->find($id);
            
            if (!$user) {
                return $this->failNotFound('User not found');
            }
            
            $userModel->update($id, ['status' => 'ACTIVE']);
            
            // Get updated user (matching Node.js)
            $updatedUser = $userModel->select('id, firstName, lastName, email, role, status')
                ->find($id);
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $currentUser->id,
                'action' => 'ACTIVATE_USER',
                'resource' => 'USER',
                'resourceId' => $id,
                'module' => 'SYSTEM',
                'details' => json_encode([
                    'email' => $user['email'],
                    'role' => $user['role']
                ])
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'User activated successfully',
                'data' => $updatedUser
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Activate user error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to activate user',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/users/{id}/role",
     *     tags={"Users Management"},
     *     summary="Change user role",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"newRole"},
     *             @OA\Property(property="newRole", type="string", enum={"CANDIDATE", "EMPLOYER", "SUPER_ADMIN", "MODERATOR", "HR_MANAGER", "WEBSITE_ADMIN"}),
     *             @OA\Property(property="reason", type="string")
     *         )
     *     ),
     *     @OA\Response(response="200", description="User role changed successfully")
     * )
     */
    public function changeUserRole($id = null)
    {
        try {
            if ($id === null) {
                $segments = $this->request->getUri()->getSegments();
                $usersIndex = array_search('users', $segments);
                if ($usersIndex !== false && isset($segments[$usersIndex + 1])) {
                    $id = $segments[$usersIndex + 1];
                }
            }
            
            if (!$id) {
                return $this->fail('User ID is required', 400);
            }
            
            $currentUser = $this->request->user ?? null;
            if (!$currentUser) {
                return $this->fail('Unauthorized', 401);
            }
            
            $data = $this->request->getJSON(true);
            $newRole = $data['newRole'] ?? null;
            $reason = $data['reason'] ?? null;
            
            if (!$newRole) {
                return $this->fail('New role is required', 400);
            }
            
            $userModel = new User();
            $user = $userModel->find($id);
            
            if (!$user) {
                return $this->failNotFound('User not found');
            }
            
            // Cannot change own role (matching Node.js)
            if ($id === $currentUser->id) {
                return $this->fail('Cannot change your own role', 400);
            }
            
            // Only SUPER_ADMIN can change SUPER_ADMIN role (matching Node.js)
            if ($user['role'] === 'SUPER_ADMIN' || $newRole === 'SUPER_ADMIN') {
                $currentUserData = $userModel->select('role')->find($currentUser->id);
                if ($currentUserData['role'] !== 'SUPER_ADMIN') {
                    return $this->fail('Only SUPER_ADMIN can change SUPER_ADMIN roles', 403);
                }
            }
            
            $oldRole = $user['role'];
            $userModel->update($id, ['role' => $newRole]);
            
            // Get updated user (matching Node.js)
            $updatedUser = $userModel->select('id, firstName, lastName, email, role, status')
                ->find($id);
            
            // Log audit (matching Node.js)
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $currentUser->id,
                'action' => 'CHANGE_USER_ROLE',
                'resource' => 'USER',
                'resourceId' => $id,
                'module' => 'SYSTEM',
                'details' => json_encode([
                    'oldRole' => $oldRole,
                    'newRole' => $newRole,
                    'reason' => $reason ?? 'No reason provided'
                ])
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'User role changed successfully',
                'data' => $updatedUser
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Change user role error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to change user role',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/users/{id}",
     *     tags={"Users Management"},
     *     summary="Delete user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="User deleted successfully")
     * )
     */
    public function deleteUser($id = null)
    {
        try {
            if ($id === null) {
                $segments = $this->request->getUri()->getSegments();
                $usersIndex = array_search('users', $segments);
                if ($usersIndex !== false && isset($segments[$usersIndex + 1])) {
                    $id = $segments[$usersIndex + 1];
                }
            }
            
            if (!$id) {
                return $this->fail('User ID is required', 400);
            }
            
            $currentUser = $this->request->user ?? null;
            if (!$currentUser) {
                return $this->fail('Unauthorized', 401);
            }
            
            $userModel = new User();
            $user = $userModel->find($id);
            
            if (!$user) {
                return $this->failNotFound('User not found');
            }
            
            // Cannot delete own account (matching Node.js)
            if ($id === $currentUser->id) {
                return $this->fail('Cannot delete your own account', 400);
            }
            
            // Only SUPER_ADMIN can delete SUPER_ADMIN (matching Node.js)
            if ($user['role'] === 'SUPER_ADMIN') {
                $currentUserData = $userModel->select('role')->find($currentUser->id);
                if ($currentUserData['role'] !== 'SUPER_ADMIN') {
                    return $this->fail('Only SUPER_ADMIN can delete SUPER_ADMIN accounts', 403);
                }
            }
            
            // Cascade delete (matching Node.js) - delete related records first
            $db = \Config\Database::connect();
            $db->transStart();
            
            try {
                // Delete application status history
                $applicationModel = new \App\Models\Application();
                $applications = $applicationModel->where('candidateId', $id)->findAll();
                if (!empty($applications)) {
                    $appIds = array_column($applications, 'id');
                    $db->table('application_status_history')->whereIn('applicationId', $appIds)->delete();
                }
                
                // Delete applications
                $applicationModel->where('candidateId', $id)->delete();
                
                // Delete jobs posted by user
                $jobModel = new \App\Models\Job();
                $jobs = $jobModel->where('postedById', $id)->findAll();
                if (!empty($jobs)) {
                    $jobIds = array_column($jobs, 'id');
                    
                    // Delete job skills and domains
                    $db->table('job_skills')->whereIn('jobId', $jobIds)->delete();
                    $db->table('job_domains')->whereIn('jobId', $jobIds)->delete();
                    
                    // Delete applications for these jobs
                    $jobApplications = $applicationModel->whereIn('jobId', $jobIds)->findAll();
                    if (!empty($jobApplications)) {
                        $jobAppIds = array_column($jobApplications, 'id');
                        $db->table('application_status_history')->whereIn('applicationId', $jobAppIds)->delete();
                        $applicationModel->whereIn('jobId', $jobIds)->delete();
                    }
                    
                    $jobModel->whereIn('id', $jobIds)->delete();
                }
                
                // Delete candidate profile
                $candidateProfileModel = new \App\Models\CandidateProfile();
                $candidateProfile = $candidateProfileModel->where('userId', $id)->first();
                if ($candidateProfile) {
                    // Delete candidate skills, domains, educations, experiences, certifications, languages
                    $db->table('candidate_skills')->where('candidateId', $candidateProfile['id'])->delete();
                    $db->table('candidate_domains')->where('candidateId', $candidateProfile['id'])->delete();
                    $db->table('educations')->where('candidateId', $candidateProfile['id'])->delete();
                    $db->table('experiences')->where('candidateId', $candidateProfile['id'])->delete();
                    $db->table('certifications')->where('candidateId', $candidateProfile['id'])->delete();
                    $db->table('candidate_languages')->where('candidateId', $candidateProfile['id'])->delete();
                    $db->table('resume_versions')->where('candidateId', $candidateProfile['id'])->delete();
                    
                    $candidateProfileModel->delete($candidateProfile['id']);
                }
                
                // Delete employer profile
                $employerProfileModel = new \App\Models\EmployerProfile();
                $employerProfile = $employerProfileModel->where('userId', $id)->first();
                if ($employerProfile) {
                    $employerProfileModel->delete($employerProfile['id']);
                }
                
                // Delete other user-related records
                $db->table('notifications')->where('userId', $id)->delete();
                $db->table('subscriptions')->where('userId', $id)->delete();
                $db->table('reports')->where('reportedById', $id)->delete();
                $db->table('activity_logs')->where('userId', $id)->delete();
                
                // Delete user
                $userModel->delete($id);
                
                $db->transComplete();
                
                if ($db->transStatus() === false) {
                    throw new \Exception('Transaction failed');
                }
                
                // Log audit (matching Node.js)
                $auditLogModel = new \App\Models\AuditLog();
                $auditLogModel->insert([
                    'id' => uniqid('audit_'),
                    'userId' => $currentUser->id,
                    'action' => 'DELETE_USER',
                    'resource' => 'USER',
                    'resourceId' => $id,
                    'module' => 'SYSTEM',
                    'details' => json_encode([
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'deletedProfiles' => [
                            'candidateProfile' => isset($candidateProfile),
                            'employerProfile' => isset($employerProfile)
                        ]
                    ])
                ]);

                return $this->respond([
                    'success' => true,
                    'message' => 'User deleted successfully'
                ]);
            } catch (\Exception $e) {
                $db->transRollback();
                throw $e;
            }
        } catch (\Exception $e) {
            log_message('error', 'Delete user error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete user',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/users/bulk-delete",
     *     tags={"Users Management"},
     *     summary="Bulk delete users",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"ids"},
     *             @OA\Property(property="ids", type="array", @OA\Items(type="string"), example={"user_123", "user_456"})
     *         )
     *     ),
     *     @OA\Response(response="200", description="Users deleted successfully")
     * )
     */
    public function bulkDelete()
    {
        try {
            $currentUser = $this->request->user ?? null;
            if (!$currentUser) {
                return $this->fail('Unauthorized', 401);
            }
            
            $data = $this->request->getJSON(true);
            $ids = $data['ids'] ?? [];
            
            if (empty($ids) || !is_array($ids)) {
                return $this->fail('User IDs are required', 400);
            }
            
            // Prevent deleting yourself
            if (in_array($currentUser->id, $ids)) {
                return $this->fail('Cannot delete your own account', 400);
            }
            
            $userModel = new User();
            $users = $userModel->whereIn('id', $ids)->findAll();
            
            if (empty($users)) {
                return $this->failNotFound('No users found to delete');
            }
            
            $currentUserData = $userModel->select('role')->find($currentUser->id);
            
            // Check if trying to delete SUPER_ADMIN (matching Node.js)
            $hasSuperAdmin = false;
            foreach ($users as $user) {
                if ($user['role'] === 'SUPER_ADMIN') {
                    $hasSuperAdmin = true;
                    break;
                }
            }
            
            if ($hasSuperAdmin && $currentUserData['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only SUPER_ADMIN can delete SUPER_ADMIN accounts', 403);
            }
            
            // Delete each user
            $deletedCount = 0;
            foreach ($users as $user) {
                // Use the deleteUser logic for each user
                $response = $this->deleteUser($user['id']);
                if ($response->getStatusCode() === 200) {
                    $deletedCount++;
                }
            }

            return $this->respond([
                'success' => true,
                'message' => "{$deletedCount} user(s) deleted successfully",
                'data' => ['deletedCount' => $deletedCount]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Bulk delete error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete users',
                'data' => []
            ], 500);
        }
    }
}
