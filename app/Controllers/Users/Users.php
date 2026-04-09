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

            // Build filters 
            $role = $this->request->getGet('role');
            $status = $this->request->getGet('status');
            $search = $this->request->getGet('search');
            $scope = $this->request->getGet('scope');

            // Apply scope filter 
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

            // Format users with candidateProfile, employerProfile, _count 
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
                    'emailVerified' => (bool) ($user['emailVerified'] ?? false),
                    'lastLoginAt' => $user['lastLoginAt'] ?? null,
                    'createdAt' => $user['createdAt'],
                    'updatedAt' => $user['updatedAt']
                ];

                // Get candidate profile ( - included for all users, null if not exists)
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

                // Get employer profile ( - included for all users, null if not exists)
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
                    'applications' => (int) $appCount,
                    'postedJobs' => (int) $jobCount
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

            // Apply scope filter 
            $where = [];
            if ($scope === 'website') {
                $where['role'] = ['SUPER_ADMIN', 'WEBSITE_ADMIN'];
            } elseif ($scope === 'recruitment') {
                $where['role'] = ['CANDIDATE', 'EMPLOYER', 'MODERATOR', 'HR_MANAGER', 'SUPER_ADMIN'];
            }

            // Get stats by role and status 
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
                'total' => (int) $total,
                'active' => (int) $active,
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

            // Format user with candidateProfile, employerProfile, _count 
            $formattedUser = [
                'id' => $user['id'],
                'firstName' => $user['firstName'],
                'lastName' => $user['lastName'],
                'email' => $user['email'],
                'role' => $user['role'],
                'status' => $user['status'],
                'phone' => $user['phone'] ?? null,
                'avatar' => $user['avatar'] ?? null,
                'emailVerified' => (bool) ($user['emailVerified'] ?? false),
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
                        'openToWork' => (bool) ($candidateProfile['openToWork'] ?? false),
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
                        'canPostJobs' => (bool) ($employerProfile['canPostJobs'] ?? false),
                        'canViewCVs' => (bool) ($employerProfile['canViewCVs'] ?? false),
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
                'applications' => (int) $appCount,
                'postedJobs' => (int) $jobCount,
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
            if (
                empty($data['firstName']) || empty($data['lastName']) ||
                empty($data['email']) || empty($data['password']) || empty($data['role'])
            ) {
                return $this->fail('Missing required fields', 400);
            }

            $userModel = new User();

            // Check if email already exists 
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

            // Create candidate profile if role is CANDIDATE 
            if ($data['role'] === 'CANDIDATE') {
                $candidateProfileModel = new \App\Models\CandidateProfile();
                $candidateProfileModel->insert([
                    'id' => uniqid('candidate_'),
                    'userId' => $userData['id']
                ]);
            }

            // Log audit 
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

            // Get created user (without password, )
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
        $data = array_filter($data, function ($value) {
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

            // Check if email is being changed and already exists 
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
            if (isset($data['firstName']))
                $data['firstName'] = trim($data['firstName']);
            if (isset($data['lastName']))
                $data['lastName'] = trim($data['lastName']);
            if (isset($data['email']))
                $data['email'] = strtolower(trim($data['email']));

            $userModel->update($id, $data);

            // Get updated user (without password)
            $updatedUser = $userModel->select('id, firstName, lastName, email, role, status, phone, createdAt, updatedAt')
                ->find($id);

            // Log audit 
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

            // Cannot suspend own account 
            if ($id === $currentUser->id) {
                return $this->fail('Cannot suspend your own account', 400);
            }

            // Only SUPER_ADMIN can suspend SUPER_ADMIN 
            if ($user['role'] === 'SUPER_ADMIN') {
                $currentUserData = $userModel->select('role')->find($currentUser->id);
                if ($currentUserData['role'] !== 'SUPER_ADMIN') {
                    return $this->fail('Only SUPER_ADMIN can suspend SUPER_ADMIN accounts', 403);
                }
            }

            $data = $this->request->getJSON(true);
            $reason = $data['reason'] ?? null;

            $userModel->update($id, ['status' => 'SUSPENDED']);

            // Get updated user 
            $updatedUser = $userModel->select('id, firstName, lastName, email, role, status')
                ->find($id);

            // Log audit 
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

            // Get updated user 
            $updatedUser = $userModel->select('id, firstName, lastName, email, role, status')
                ->find($id);

            // Log audit 
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

            // Cannot change own role 
            if ($id === $currentUser->id) {
                return $this->fail('Cannot change your own role', 400);
            }

            // Only SUPER_ADMIN can change SUPER_ADMIN role 
            if ($user['role'] === 'SUPER_ADMIN' || $newRole === 'SUPER_ADMIN') {
                $currentUserData = $userModel->select('role')->find($currentUser->id);
                if ($currentUserData['role'] !== 'SUPER_ADMIN') {
                    return $this->fail('Only SUPER_ADMIN can change SUPER_ADMIN roles', 403);
                }
            }

            $oldRole = $user['role'];
            $userModel->update($id, ['role' => $newRole]);

            // Get updated user 
            $updatedUser = $userModel->select('id, firstName, lastName, email, role, status')
                ->find($id);

            // Log audit 
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

            if ($id === $currentUser->id) {
                return $this->fail('Cannot delete your own account', 400);
            }

            if ($user['role'] === 'SUPER_ADMIN') {
                $currentUserData = $userModel->select('role')->find($currentUser->id);
                if ($currentUserData['role'] !== 'SUPER_ADMIN') {
                    return $this->fail('Only SUPER_ADMIN can delete SUPER_ADMIN accounts', 403);
                }
            }

            $db = \Config\Database::connect();
            $db->transStart();

            try {
                // ── 1. Candidate Profile & all related child tables ───────────────
                $candidateProfileModel = new \App\Models\CandidateProfile();
                $candidateProfile = $candidateProfileModel->where('userId', $id)->first();

                if ($candidateProfile) {
                    $cpId = $candidateProfile['id'];

                    $candidateTables = [
                        'candidate_skills' => 'candidateId',
                        'candidate_domains' => 'candidateId',
                        'educations' => 'candidateId',
                        'experiences' => 'candidateId',
                        'certifications' => 'candidateId',
                        'candidate_languages' => 'candidateId',
                        'resume_versions' => 'candidateId',
                        'candidate_clearances' => 'candidateId',
                        'candidate_courses' => 'candidateId',
                        'candidate_files' => 'candidateId',
                        'candidate_personal_info' => 'candidateId',
                        'candidate_professional_memberships' => 'candidateId',
                        'candidate_publications' => 'candidateId',
                        'candidate_referees' => 'candidateId',
                    ];

                    foreach ($candidateTables as $table => $fkColumn) {
                        try {
                            $db->table($table)->where($fkColumn, $cpId)->delete();
                        } catch (\Exception $e) {
                            log_message('debug', "Delete from {$table} for user {$id}: " . $e->getMessage());
                        }
                    }

                    // Delete candidate profile itself
                    $candidateProfileModel->delete($cpId);
                }

                // ── 2. Applications where user is the candidate ───────────────────
                $applicationModel = new \App\Models\Application();
                $applications = $applicationModel->where('candidateId', $id)->findAll();

                if (!empty($applications)) {
                    $appIds = array_column($applications, 'id');
                    try {
                        $db->table('application_status_history')->whereIn('applicationId', $appIds)->delete();
                    } catch (\Exception $e) {
                        log_message('debug', 'Delete application_status_history: ' . $e->getMessage());
                    }
                    try {
                        $db->table('application_question_answers')->whereIn('applicationId', $appIds)->delete();
                    } catch (\Exception $e) {
                        log_message('debug', 'Delete application_question_answers: ' . $e->getMessage());
                    }
                    try {
                        $db->table('application_snapshots')->whereIn('applicationId', $appIds)->delete();
                    } catch (\Exception $e) {
                        log_message('debug', 'Delete application_snapshots: ' . $e->getMessage());
                    }
                    $applicationModel->whereIn('id', $appIds)->delete();
                }

                // ── 3. Jobs posted by this user & their children ──────────────────
                $jobModel = new \App\Models\Job();
                $jobs = $jobModel->where('postedById', $id)->findAll();

                if (!empty($jobs)) {
                    $jobIds = array_column($jobs, 'id');

                    $jobChildTables = [
                        'job_skills',
                        'job_domains',
                        'job_profile_requirements',
                        'job_questionnaires',
                        'job_questions',
                        'job_application_config',
                        'job_application_read_markers',
                        'job_shortlist_criteria',
                    ];
                    foreach ($jobChildTables as $table) {
                        try {
                            $db->table($table)->whereIn('jobId', $jobIds)->delete();
                        } catch (\Exception $e) {
                            log_message('debug', "Delete from {$table} for user jobs: " . $e->getMessage());
                        }
                    }

                    try {
                        $db->table('shortlist_results')->whereIn('jobId', $jobIds)->delete();
                    } catch (\Exception $e) {
                        log_message('debug', 'Delete shortlist_results: ' . $e->getMessage());
                    }

                    // Applications TO these jobs (by other candidates)
                    $jobApps = $applicationModel->whereIn('jobId', $jobIds)->findAll();
                    if (!empty($jobApps)) {
                        $jobAppIds = array_column($jobApps, 'id');
                        try {
                            $db->table('application_status_history')->whereIn('applicationId', $jobAppIds)->delete();
                        } catch (\Exception $e) {
                            log_message('debug', 'Delete app_status_history for job apps: ' . $e->getMessage());
                        }
                        try {
                            $db->table('application_question_answers')->whereIn('applicationId', $jobAppIds)->delete();
                        } catch (\Exception $e) {
                            log_message('debug', 'Delete app_question_answers for job apps: ' . $e->getMessage());
                        }
                        try {
                            $db->table('application_snapshots')->whereIn('applicationId', $jobAppIds)->delete();
                        } catch (\Exception $e) {
                            log_message('debug', 'Delete app_snapshots for job apps: ' . $e->getMessage());
                        }
                        $applicationModel->whereIn('id', $jobAppIds)->delete();
                    }

                    // Interviews for these jobs
                    try {
                        $interviews = $db->table('interviews')->whereIn('jobId', $jobIds)->get()->getResultArray();
                        if (!empty($interviews)) {
                            $intIds = array_column($interviews, 'id');
                            try {
                                $db->table('interview_history')->whereIn('interviewId', $intIds)->delete();
                            } catch (\Exception $e) {
                                log_message('debug', 'Delete interview_history for job interviews: ' . $e->getMessage());
                            }
                            $db->table('interviews')->whereIn('id', $intIds)->delete();
                        }
                    } catch (\Exception $e) {
                        log_message('debug', 'Delete interviews for jobs: ' . $e->getMessage());
                    }

                    $jobModel->whereIn('id', $jobIds)->delete();
                }

                // ── 4. Employer profile ───────────────────────────────────────────
                $employerProfileModel = new \App\Models\EmployerProfile();
                $employerProfile = $employerProfileModel->where('userId', $id)->first();
                if ($employerProfile) {
                    $employerProfileModel->delete($employerProfile['id']);
                }

                // ── 5. Interviews where this user is the candidate ────────────────
                try {
                    $userInterviews = $db->table('interviews')->where('candidateId', $id)->get()->getResultArray();
                    if (!empty($userInterviews)) {
                        $uIntIds = array_column($userInterviews, 'id');
                        try {
                            $db->table('interview_history')->whereIn('interviewId', $uIntIds)->delete();
                        } catch (\Exception $e) {
                            log_message('debug', 'Delete interview_history for candidate interviews: ' . $e->getMessage());
                        }
                        $db->table('interviews')->whereIn('id', $uIntIds)->delete();
                    }
                } catch (\Exception $e) {
                    log_message('debug', 'Delete candidate interviews: ' . $e->getMessage());
                }

                // ── 6. Job alerts  ────────────────────────────────
                try {
                    $db->table('job_alerts')->where('userId', $id)->delete();
                } catch (\Exception $e) {
                    log_message('debug', 'Delete job_alerts: ' . $e->getMessage());
                }

                // ── 7. Notification queue (skip if table/column doesn't exist) ────
                try {
                    $db->table('notification_queue')->where('userId', $id)->delete();
                } catch (\Exception $e) {
                    log_message('debug', 'Delete notification_queue: ' . $e->getMessage());
                }

                // ── 8. Other user-linked tables ───────────────────────────────────
                $userTables = [
                    'notifications' => 'userId',
                    'subscriptions' => 'userId',
                    'reports' => 'reportedById',
                    'activity_logs' => 'userId',
                ];

                foreach ($userTables as $table => $fkColumn) {
                    try {
                        $db->table($table)->where($fkColumn, $id)->delete();
                    } catch (\Exception $e) {
                        log_message('debug', "Delete from {$table}: " . $e->getMessage());
                    }
                }

                // ── 9. Delete the user record ─────────────────────────────────────
                $userModel->delete($id);

                $db->transComplete();

                if ($db->transStatus() === false) {
                    throw new \Exception('Transaction failed during cascade delete');
                }

                // ── 10. Audit log (after transaction commits — non-fatal) ─────────
                try {
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
                        ]),
                    ]);
                } catch (\Exception $e) {
                    // Non-fatal — deletion already succeeded
                    log_message('debug', 'Audit log after delete failed: ' . $e->getMessage());
                }

                return $this->respond([
                    'success' => true,
                    'message' => 'User and all associated data deleted successfully',
                ]);

            } catch (\Exception $e) {
                $db->transRollback();
                throw $e;
            }

        } catch (\Exception $e) {
            log_message('error', 'Delete user error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage(),
                'data' => [],
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

            // Check if trying to delete SUPER_ADMIN 
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
