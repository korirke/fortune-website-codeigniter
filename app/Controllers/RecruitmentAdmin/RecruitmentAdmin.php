<?php

namespace App\Controllers\RecruitmentAdmin;

use App\Controllers\BaseController;
use App\Models\JobCategory;
use App\Models\Job;
use App\Models\Application;
use App\Models\User;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Recruitment Admin",
 *     description="Recruitment admin endpoints"
 * )
 */
class RecruitmentAdmin extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/recruitment-admin/dashboard/stats",
     *     tags={"Recruitment Admin"},
     *     summary="Get dashboard statistics",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"last_7_days", "last_30_days", "last_90_days", "this_year", "custom"}), description="Time period", example="last_30_days"),
     *     @OA\Parameter(name="startDate", in="query", required=false, @OA\Schema(type="string", format="date"), description="Start date (for custom period)", example="2024-01-01"),
     *     @OA\Parameter(name="endDate", in="query", required=false, @OA\Schema(type="string", format="date"), description="End date (for custom period)", example="2024-12-31"),
     *     @OA\Response(response="200", description="Stats retrieved")
     * )
     */
    public function getDashboardStats()
    {
        try {
            $period = $this->request->getGet('period') ?? 'last_30_days';
            $startDate = $this->request->getGet('startDate');
            $endDate = $this->request->getGet('endDate');
            
            // Calculate date range
            $now = new \DateTime();
            $end = $endDate ? new \DateTime($endDate) : $now;
            
            switch ($period) {
                case 'last_7_days':
                    $start = clone $now;
                    $start->modify('-7 days');
                    break;
                case 'last_90_days':
                    $start = clone $now;
                    $start->modify('-90 days');
                    break;
                case 'this_year':
                    $start = new \DateTime($now->format('Y-01-01'));
                    break;
                case 'custom':
                    $start = $startDate ? new \DateTime($startDate) : clone $now;
                    $start->modify('-30 days');
                    break;
                default: // last_30_days
                    $start = clone $now;
                    $start->modify('-30 days');
            }
            
            $jobModel = new \App\Models\Job();
            $applicationModel = new \App\Models\Application();
            $userModel = new \App\Models\User();
            
            // Get stats matching Node.js structure
            $totalJobs = $jobModel->where('createdAt >=', $start->format('Y-m-d H:i:s'))
                ->where('createdAt <=', $end->format('Y-m-d H:i:s'))
                ->countAllResults(false);
            
            $activeJobs = $jobModel->where('status', 'ACTIVE')->countAllResults(false);
            
            $totalApplications = $applicationModel->where('appliedAt >=', $start->format('Y-m-d H:i:s'))
                ->where('appliedAt <=', $end->format('Y-m-d H:i:s'))
                ->countAllResults(false);
            
            $registeredCandidates = $userModel->where('role', 'CANDIDATE')
                ->where('createdAt >=', $start->format('Y-m-d H:i:s'))
                ->where('createdAt <=', $end->format('Y-m-d H:i:s'))
                ->countAllResults(false);
            
            $activeEmployers = $userModel->where('role', 'EMPLOYER')
                ->where('status', 'ACTIVE')
                ->countAllResults(false);
            
            $pendingModeration = $jobModel->where('status', 'PENDING')->countAllResults(false);
            
            // Calculate avgTimeToHire (matching Node.js)
            $acceptedApplications = $applicationModel
                ->where('status', 'ACCEPTED')
                ->where('appliedAt >=', $start->format('Y-m-d H:i:s'))
                ->where('appliedAt <=', $end->format('Y-m-d H:i:s'))
                ->where('reviewedAt IS NOT NULL')
                ->findAll();
            
            $avgTimeToHire = 14; // Default 14 days
            if (!empty($acceptedApplications)) {
                $totalDays = 0;
                foreach ($acceptedApplications as $app) {
                    $appliedAt = strtotime($app['appliedAt']);
                    $reviewedAt = strtotime($app['reviewedAt']);
                    $days = floor(($reviewedAt - $appliedAt) / (60 * 60 * 24));
                    $totalDays += $days;
                }
                $avgTimeToHire = (int) round($totalDays / count($acceptedApplications));
            }
            
            // Calculate successRate (matching Node.js)
            $acceptedCount = $applicationModel
                ->where('status', 'ACCEPTED')
                ->where('appliedAt >=', $start->format('Y-m-d H:i:s'))
                ->where('appliedAt <=', $end->format('Y-m-d H:i:s'))
                ->countAllResults(false);
            
            $successRate = 0.0;
            if ($totalApplications > 0) {
                $successRate = round(($acceptedCount / $totalApplications) * 100 * 10) / 10;
            }
            
            // Calculate trends (matching Node.js)
            $db = \Config\Database::connect();
            $jobsTrend = $db->table('jobs')
                ->select('createdAt, COUNT(*) as count')
                ->where('createdAt >=', $start->format('Y-m-d H:i:s'))
                ->where('createdAt <=', $end->format('Y-m-d H:i:s'))
                ->groupBy('createdAt')
                ->get()
                ->getResultArray();
            
            $applicationsTrend = $db->table('applications')
                ->select('appliedAt, COUNT(*) as count')
                ->where('appliedAt >=', $start->format('Y-m-d H:i:s'))
                ->where('appliedAt <=', $end->format('Y-m-d H:i:s'))
                ->groupBy('appliedAt')
                ->get()
                ->getResultArray();
            
            $trends = [
                'jobs' => count($jobsTrend),
                'applications' => count($applicationsTrend)
            ];
            
            return $this->respond([
                'success' => true,
                'message' => 'Dashboard stats retrieved',
                'data' => [
                    'totalJobs' => $totalJobs,
                    'activeJobs' => $activeJobs,
                    'totalApplications' => $totalApplications,
                    'registeredCandidates' => $registeredCandidates,
                    'activeEmployers' => $activeEmployers,
                    'pendingModeration' => $pendingModeration,
                    'avgTimeToHire' => $avgTimeToHire,
                    'successRate' => $successRate,
                    'trends' => $trends
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve dashboard stats',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/recruitment-admin/dashboard/top-performers",
     *     tags={"Recruitment Admin"},
     *     summary="Get top performers",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Top performers retrieved")
     * )
     */
    public function getTopPerformers()
    {
        try {
            // Get top jobs by applications (matching Node.js)
            $jobModel = new \App\Models\Job();
            $topJobs = $jobModel->where('status', 'ACTIVE')
                ->orderBy('applicationCount', 'DESC')
                ->limit(5)
                ->findAll();
            
            // Format jobs with company (matching Node.js)
            $companyModel = new \App\Models\Company();
            foreach ($topJobs as &$job) {
                $company = $companyModel->select('name, logo')->find($job['companyId']);
                $job['company'] = $company;
            }
            
            // Get top employers (matching Node.js)
            $companyModel = new \App\Models\Company();
            $topEmployers = $companyModel->where('status', 'VERIFIED')
                ->orderBy('createdAt', 'DESC')
                ->limit(5)
                ->findAll();
            
            // Add _count for jobs (matching Node.js)
            $jobModel = new \App\Models\Job();
            foreach ($topEmployers as &$employer) {
                $jobCount = $jobModel->where('companyId', $employer['id'])
                    ->where('status', 'ACTIVE')
                    ->countAllResults(false);
                $employer['_count'] = ['jobs' => $jobCount];
            }
            
            // Get top categories (matching Node.js)
            $categoryModel = new JobCategory();
            $topCategories = $categoryModel->where('isActive', true)
                ->orderBy('createdAt', 'DESC')
                ->limit(5)
                ->findAll();
            
            return $this->respond([
                'success' => true,
                'message' => 'Top performers retrieved',
                'data' => [
                    'topJobs' => $topJobs,
                    'topEmployers' => $topEmployers,
                    'topCategories' => $topCategories
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve top performers',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/recruitment-admin/dashboard/recent-activities",
     *     tags={"Recruitment Admin"},
     *     summary="Get recent activities",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Activities retrieved")
     * )
     */
    public function getRecentActivities()
    {
        try {
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            
            // Get recent activities with user data (matching Node.js)
            $activityLogModel = new \App\Models\ActivityLog();
            $activities = $activityLogModel->orderBy('createdAt', 'DESC')
                ->limit($limit)
                ->findAll();
            
            // Format activities with user data (matching Node.js)
            $userModel = new \App\Models\User();
            $formattedActivities = [];
            foreach ($activities as $activity) {
                $formattedActivity = $activity;
                
                // Get user data
                $user = $userModel->select('firstName, lastName, email, role')
                    ->find($activity['userId']);
                $formattedActivity['user'] = $user;
                
                $formattedActivities[] = $formattedActivity;
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Activities retrieved successfully',
                'data' => $formattedActivities
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve activities',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/recruitment-admin/reports/generate",
     *     tags={"Recruitment Admin"},
     *     summary="Generate analytics report",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"last_7_days", "last_30_days", "last_90_days", "this_year", "custom"}), description="Time period", example="last_30_days"),
     *     @OA\Parameter(name="startDate", in="query", required=false, @OA\Schema(type="string", format="date"), description="Start date (for custom period)"),
     *     @OA\Parameter(name="endDate", in="query", required=false, @OA\Schema(type="string", format="date"), description="End date (for custom period)"),
     *     @OA\Parameter(name="format", in="query", required=false, @OA\Schema(type="string", enum={"json", "csv", "pdf"}), description="Report format", example="json"),
     *     @OA\Response(response="200", description="Report generated")
     * )
     */
    public function generateReport()
    {
        try {
            $period = $this->request->getGet('period') ?? 'last_30_days';
            $startDate = $this->request->getGet('startDate');
            $endDate = $this->request->getGet('endDate');
            $format = $this->request->getGet('format') ?? 'json';
            
            // Calculate date range (matching Node.js)
            $now = new \DateTime();
            $end = $endDate ? new \DateTime($endDate) : $now;
            
            switch ($period) {
                case 'last_7_days':
                    $start = clone $now;
                    $start->modify('-7 days');
                    break;
                case 'last_90_days':
                    $start = clone $now;
                    $start->modify('-90 days');
                    break;
                case 'this_year':
                    $start = new \DateTime($now->format('Y-01-01'));
                    break;
                case 'custom':
                    $start = $startDate ? new \DateTime($startDate) : clone $now;
                    $start->modify('-30 days');
                    break;
                case 'last_30_days':
                default:
                    $start = clone $now;
                    $start->modify('-30 days');
                    break;
            }
            
            // Generate report data (matching Node.js)
            $jobModel = new \App\Models\Job();
            $applicationModel = new \App\Models\Application();
            $userModel = new \App\Models\User();
            $companyModel = new \App\Models\Company();
            
            $startStr = $start->format('Y-m-d H:i:s');
            $endStr = $end->format('Y-m-d H:i:s');
            
            // Jobs report
            $totalJobs = $jobModel->where('createdAt >=', $startStr)
                ->where('createdAt <=', $endStr)
                ->countAllResults(false);
            
            // Applications report
            $totalApplications = $applicationModel->where('appliedAt >=', $startStr)
                ->where('appliedAt <=', $endStr)
                ->countAllResults(false);
            
            // Candidates report
            $totalCandidates = $userModel->where('role', 'CANDIDATE')
                ->where('createdAt >=', $startStr)
                ->where('createdAt <=', $endStr)
                ->countAllResults(false);
            
            // Employers report
            $totalEmployers = $userModel->where('role', 'EMPLOYER')
                ->where('createdAt >=', $startStr)
                ->where('createdAt <=', $endStr)
                ->countAllResults(false);
            
            $reportData = [
                'period' => [
                    'start' => $start->format('c'),
                    'end' => $end->format('c')
                ],
                'jobs' => ['total' => $totalJobs, 'byStatus' => [], 'byCategory' => []],
                'applications' => ['total' => $totalApplications, 'byStatus' => []],
                'candidates' => ['total' => $totalCandidates],
                'employers' => ['total' => $totalEmployers]
            ];
            
            return $this->respond([
                'success' => true,
                'message' => 'Report generated successfully',
                'data' => $reportData
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to generate report',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/recruitment-admin/candidates",
     *     tags={"Recruitment Admin"},
     *     summary="Filter candidates by domain",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="domainId", in="query", required=false, @OA\Schema(type="string"), description="Filter by domain ID"),
     *     @OA\Parameter(name="skillId", in="query", required=false, @OA\Schema(type="string"), description="Filter by skill ID"),
     *     @OA\Parameter(name="openToWork", in="query", required=false, @OA\Schema(type="boolean"), description="Filter by open to work status"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(response="200", description="Candidates retrieved")
     * )
     */
    public function filterCandidates()
    {
        return $this->respond([
            'success' => true,
            'data' => []
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/recruitment-admin/categories",
     *     tags={"Recruitment Admin"},
     *     summary="Create job category",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", description="Category name", example="Software Development"),
     *             @OA\Property(property="description", type="string", description="Category description", example="Software development jobs"),
     *             @OA\Property(property="icon", type="string", description="Category icon", example="code"),
     *             @OA\Property(property="parentId", type="string", description="Parent category ID (for subcategories)", example="category_123"),
     *             @OA\Property(property="sortOrder", type="integer", description="Display order", example=1)
     *         )
     *     ),
     *     @OA\Response(response="201", description="Category created")
     * )
     */
    public function createCategory()
    {
        try {
            $data = $this->request->getJSON(true);
            $categoryModel = new JobCategory();
            
            // Generate slug from name (matching Node.js)
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $data['name']));
            $slug = preg_replace('/^-|-$/', '', $slug);
            
            $categoryData = [
                'id' => uniqid('category_'),
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? null,
                'parentId' => $data['parentId'] ?? null,
                'sortOrder' => $data['sortOrder'] ?? 0,
                'isActive' => $data['isActive'] ?? true
            ];
            
            $categoryModel->insert($categoryData);
            $createdCategory = $categoryModel->find($categoryData['id']);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $createdCategory
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to create category',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/recruitment-admin/categories/{categoryId}",
     *     tags={"Recruitment Admin"},
     *     summary="Update job category",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="categoryId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", description="Category name", example="Software Development"),
     *             @OA\Property(property="description", type="string", description="Category description"),
     *             @OA\Property(property="icon", type="string", description="Category icon"),
     *             @OA\Property(property="sortOrder", type="integer", description="Display order"),
     *             @OA\Property(property="isActive", type="boolean", description="Active status", example=true)
     *         )
     *     ),
     *     @OA\Response(response="200", description="Category updated")
     * )
     */
    public function updateCategory($categoryId = null)
    {
        if ($categoryId === null) {
            $categoryId = $this->request->getUri()->getSegment(3);
        }
        
        if (!$categoryId) {
            return $this->fail('Category ID is required', 400);
        }
        
        $data = $this->request->getJSON(true);
        $categoryModel = new JobCategory();
        $categoryModel->update($categoryId, $data);

        return $this->respond([
            'success' => true,
            'message' => 'Category updated'
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/recruitment-admin/categories/{categoryId}",
     *     tags={"Recruitment Admin"},
     *     summary="Delete job category",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="categoryId", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Category deleted")
     * )
     */
    public function deleteCategory($categoryId = null)
    {
        if ($categoryId === null) {
            $categoryId = $this->request->getUri()->getSegment(3);
        }
        
        if (!$categoryId) {
            return $this->fail('Category ID is required', 400);
        }
        
        try {
            $categoryModel = new JobCategory();
            $category = $categoryModel->find($categoryId);
            
            if (!$category) {
                return $this->failNotFound('Category not found');
            }
            
            // Check if category has jobs (matching Node.js)
            $jobModel = new \App\Models\Job();
            $jobCount = $jobModel->where('categoryId', $categoryId)->countAllResults(false);
            
            if ($jobCount > 0) {
                return $this->fail('Cannot delete category with existing jobs. Please reassign jobs first.', 403);
            }
            
            $categoryModel->delete($categoryId);

            return $this->respond([
                'success' => true,
                'message' => 'Category deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete category',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/recruitment-admin/settings",
     *     tags={"Recruitment Admin"},
     *     summary="Get site settings",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Settings retrieved")
     * )
     */
    public function getSettings()
    {
        return $this->respond([
            'success' => true,
            'data' => []
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/recruitment-admin/settings",
     *     tags={"Recruitment Admin"},
     *     summary="Update site settings",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="autoApproveJobs", type="boolean", description="Auto-approve job postings", example=false),
     *             @OA\Property(property="requireCompanyVerification", type="boolean", description="Require company verification", example=true),
     *             @OA\Property(property="maxJobPostingsPerCompany", type="integer", description="Maximum job postings per company", example=10),
     *             @OA\Property(property="jobExpirationDays", type="integer", description="Job expiration in days", example=30),
     *             @OA\Property(property="enableEmailNotifications", type="boolean", description="Enable email notifications", example=true)
     *         )
     *     ),
     *     @OA\Response(response="200", description="Settings updated")
     * )
     */
    public function updateSettings()
    {
        try {
            $data = $this->request->getJSON(true);
            $settingsModel = new \App\Models\SiteSettings();
            
            $settings = $settingsModel->first();
            if (!$settings) {
                return $this->failNotFound('Settings not found');
            }
            
            $settingsModel->update($settings['id'], $data);
            $updatedSettings = $settingsModel->find($settings['id']);

            return $this->respond([
                'success' => true,
                'message' => 'Settings updated successfully',
                'data' => $updatedSettings
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update settings',
                'data' => []
            ], 500);
        }
    }
}
