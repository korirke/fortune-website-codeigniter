<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\Company;
use App\Models\Job;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Companies",
 *     description="Public company endpoints"
 * )
 */
class Companies extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/companies",
     *     tags={"Companies"},
     *     summary="Get public companies list (verified only)",
     *     @OA\Response(
     *         response=200,
     *         description="Public companies retrieved"
     *     )
     * )
     */
    public function getPublicCompanies()
    {
        try {
            $companyModel = new Company();
            $jobModel = new \App\Models\Job();
            
            // Get filters
            $industry = $this->request->getGet('industry');
            $search = $this->request->getGet('search');
            
            $companyModel->where('status', 'VERIFIED')
                ->where('verified', true);
            
            if ($industry) {
                $companyModel->where('industry', $industry);
            }
            if ($search) {
                $companyModel->groupStart()
                    ->like('name', $search)
                    ->orLike('description', $search)
                    ->groupEnd();
            }
            
            $companies = $companyModel->orderBy('createdAt', 'DESC')->findAll();
            
            // Format companies to match Node.js (include job count)
            $formattedCompanies = [];
            foreach ($companies as $company) {
                // Count active jobs for this company
                $jobCount = $jobModel->where('companyId', $company['id'])
                    ->where('status', 'ACTIVE')
                    ->countAllResults(false);
                
                $formattedCompanies[] = [
                    'id' => $company['id'],
                    'name' => $company['name'],
                    'slug' => $company['slug'] ?? null,
                    'logo' => $company['logo'] ?? null,
                    'description' => $company['description'] ?? null,
                    'industry' => $company['industry'] ?? null,
                    'location' => $company['location'] ?? null,
                    'companySize' => $company['companySize'] ?? null,
                    'website' => $company['website'] ?? null,
                    '_count' => [
                        'jobs' => $jobCount
                    ]
                ];
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Companies retrieved successfully',
                'data' => $formattedCompanies
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
     *     path="/api/companies/{slug}",
     *     tags={"Companies"},
     *     summary="Get company by slug (public profile)",
     *     @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Company retrieved"
     *     )
     * )
     */
    public function getCompanyBySlug($slug = null)
    {
        try {
            // Get slug from route parameter or URI segment
            if ($slug === null) {
                $slug = $this->request->getUri()->getSegment(2);
            }
            
            if (!$slug) {
                return $this->fail('Company slug is required', 400);
            }
            
            $companyModel = new Company();
            $company = $companyModel->where('slug', $slug)
                ->where('status', 'VERIFIED')
                ->where('verified', true)
                ->first();
            
            if (!$company) {
                return $this->failNotFound('Company not found');
            }
            
            // Get active jobs for this company (matching Node.js)
            $jobModel = new Job();
            $jobs = $jobModel->where('companyId', $company['id'])
                ->where('status', 'ACTIVE')
                ->orderBy('publishedAt', 'DESC')
                ->limit(10)
                ->findAll();
            
            // Format jobs to match Node.js structure
            $formattedJobs = array_map(function($job) {
                return [
                    'id' => $job['id'],
                    'title' => $job['title'],
                    'slug' => $job['slug'] ?? null,
                    'type' => $job['type'] ?? null,
                    'location' => $job['location'] ?? null,
                    'salaryMin' => $job['salaryMin'] ?? null,
                    'salaryMax' => $job['salaryMax'] ?? null,
                    'publishedAt' => $job['publishedAt'] ?? null,
                ];
            }, $jobs);
            
            // Count total active jobs
            $jobCount = $jobModel->where('companyId', $company['id'])
                ->where('status', 'ACTIVE')
                ->countAllResults(false);
            
            // Format company response matching Node.js
            $companyData = $company;
            $companyData['jobs'] = $formattedJobs;
            $companyData['_count'] = [
                'jobs' => $jobCount
            ];
            
            return $this->respond([
                'success' => true,
                'message' => 'Company retrieved successfully',
                'data' => $companyData
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve company',
                'data' => []
            ], 500);
        }
    }
}
