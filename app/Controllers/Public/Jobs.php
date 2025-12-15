<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\Job;
use App\Models\JobCategory;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Jobs",
 *     description="Public job endpoints"
 * )
 */
class Jobs extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/jobs/search",
     *     tags={"Jobs"},
     *     summary="Search jobs (public)",
     *     @OA\Parameter(name="q", in="query", required=false, @OA\Schema(type="string"), description="Search query"),
     *     @OA\Parameter(name="location", in="query", required=false, @OA\Schema(type="string"), description="Filter by location"),
     *     @OA\Parameter(name="type", in="query", required=false, @OA\Schema(type="string", enum={"FULL_TIME", "PART_TIME", "CONTRACT", "INTERNSHIP", "FREELANCE"}), description="Filter by job type"),
     *     @OA\Parameter(name="category", in="query", required=false, @OA\Schema(type="string"), description="Filter by category ID"),
     *     @OA\Response(
     *         response=200,
     *         description="Jobs retrieved"
     *     )
     * )
     */
    public function searchJobs()
    {
        try {
            $jobModel = new Job();
            
            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;
            
            // Get filters
            $q = $this->request->getGet('q') ?? $this->request->getGet('query');
            $location = $this->request->getGet('location');
            $type = $this->request->getGet('type');
            $categoryId = $this->request->getGet('categoryId') ?? $this->request->getGet('category');
            $experienceLevel = $this->request->getGet('experienceLevel');
            $status = $this->request->getGet('status') ?? 'ACTIVE';
            
            $jobModel->where('status', $status);
            
            if ($q) {
                $jobModel->groupStart()
                    ->like('title', $q)
                    ->orLike('description', $q)
                    ->groupEnd();
            }
            if ($location) {
                $jobModel->like('location', $location);
            }
            if ($type) {
                $jobModel->where('type', $type);
            }
            if ($categoryId) {
                $jobModel->where('categoryId', $categoryId);
            }
            if ($experienceLevel) {
                $jobModel->where('experienceLevel', $experienceLevel);
            }
            
            // Get total count
            $total = $jobModel->countAllResults(false);
            
            // Get paginated results
            $jobs = $jobModel
                ->orderBy('publishedAt', 'DESC')
                ->findAll($limit, $skip);
            
            // Node.js returns pagination without hasNext/hasPrev for searchJobs
            return $this->respond([
                'success' => true,
                'message' => 'Jobs retrieved successfully',
                'data' => [
                    'jobs' => $jobs ?: [],
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'totalPages' => (int) ceil($total / $limit),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to search jobs',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/categories",
     *     tags={"Jobs"},
     *     summary="Get job categories",
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved"
     *     )
     * )
     */
    public function getCategories()
    {
        try {
            $categoryModel = new JobCategory();
            $jobModel = new Job();
            
            $categories = $categoryModel->where('isActive', true)
                ->orderBy('sortOrder', 'ASC')
                ->findAll();
            
            // Format categories to include jobCount (matching Node.js)
            $formattedCategories = [];
            foreach ($categories as $cat) {
                // Count active jobs for this category
                $jobCount = $jobModel->where('categoryId', $cat['id'])
                    ->where('status', 'ACTIVE')
                    ->countAllResults(false);
                
                $formattedCategories[] = [
                    'id' => $cat['id'],
                    'name' => $cat['name'],
                    'slug' => $cat['slug'] ?? null,
                    'description' => $cat['description'] ?? null,
                    'icon' => $cat['icon'] ?? null,
                    'isActive' => $cat['isActive'],
                    'jobCount' => $jobCount,
                ];
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $formattedCategories
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/newest",
     *     tags={"Jobs"},
     *     summary="Get newest jobs (public)",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Number of jobs to return", example=8),
     *     @OA\Response(
     *         response=200,
     *         description="Newest jobs retrieved"
     *     )
     * )
     */
    public function getNewestJobs()
    {
        try {
            $limit = (int) ($this->request->getGet('limit') ?? 8);
            $jobModel = new Job();
            
            $jobs = $jobModel->where('status', 'ACTIVE')
                ->where('publishedAt IS NOT NULL')
                ->orderBy('publishedAt', 'DESC')
                ->limit($limit)
                ->findAll();
            
            return $this->respond([
                'success' => true,
                'data' => $jobs ?: []
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve newest jobs',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/jobs/{id}",
     *     tags={"Jobs"},
     *     summary="Get job details",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Job retrieved"
     *     )
     * )
     */
    public function getJobById($id = null)
    {
        try {
            // Get ID from route parameter or URI segment
            if ($id === null) {
                $id = $this->request->getUri()->getSegment(2);
            }
            
            if (!$id) {
                return $this->fail('Job ID is required', 400);
            }
            
            $jobModel = new Job();
            $job = $jobModel->find($id);
            
            if (!$job) {
                return $this->failNotFound('Job not found');
            }
            
            // Only show non-active jobs to admins and job owners (matching Node.js)
            $user = $this->request->user ?? null;
            $userRole = null;
            if ($user) {
                $userModel = new \App\Models\User();
                $userData = $userModel->select('role')->find($user->id);
                $userRole = $userData['role'] ?? null;
            }
            
            if ($job['status'] !== 'ACTIVE') {
                $isAdmin = $userRole && in_array($userRole, ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR']);
                if (!$isAdmin) {
                    return $this->failNotFound('Job not found');
                }
            }
            
            // Increment views (matching Node.js)
            $currentViews = $job['views'] ?? 0;
            $jobModel->update($id, ['views' => $currentViews + 1]);
            
            // Get job with all relations (matching Node.js)
            $companyModel = new \App\Models\Company();
            $company = $companyModel->select('id, name, slug, logo, description, website, location, industry, companySize')
                ->find($job['companyId']);
            $job['company'] = $company;
            
            $categoryModel = new \App\Models\JobCategory();
            $category = $categoryModel->find($job['categoryId']);
            $job['category'] = $category;
            
            $userModel = new \App\Models\User();
            $postedBy = $userModel->select('firstName, lastName')
                ->find($job['postedById']);
            $job['postedBy'] = $postedBy;
            
            // Get skills
            $jobSkillModel = new \App\Models\JobSkill();
            $jobSkills = $jobSkillModel->where('jobId', $id)->findAll();
            $skills = [];
            foreach ($jobSkills as $js) {
                $skillModel = new \App\Models\Skill();
                $skill = $skillModel->find($js['skillId']);
                if ($skill) {
                    $skills[] = ['skill' => $skill];
                }
            }
            $job['skills'] = $skills;
            
            // Get domains
            $jobDomainModel = new \App\Models\JobDomain();
            $jobDomains = $jobDomainModel->where('jobId', $id)->findAll();
            $domains = [];
            foreach ($jobDomains as $jd) {
                $domainModel = new \App\Models\Domain();
                $domain = $domainModel->find($jd['domainId']);
                if ($domain) {
                    $domains[] = ['domain' => $domain];
                }
            }
            $job['domains'] = $domains;
            
            return $this->respond([
                'success' => true,
                'message' => 'Job retrieved successfully',
                'data' => $job
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve job',
                'data' => []
            ], 500);
        }
    }
}
