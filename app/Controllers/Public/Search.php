<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\SearchAnalytics;
use App\Models\PopularSearch;
use App\Models\Service;
use App\Models\PageContent;
use App\Models\Testimonial;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Search",
 *     description="Search functionality endpoints"
 * )
 */
class Search extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/search",
     *     tags={"Search"},
     *     summary="Search across all content",
     *     @OA\Parameter(name="q", in="query", required=true, @OA\Schema(type="string"), description="Search query", example="payroll"),
     *     @OA\Parameter(name="type", in="query", required=false, @OA\Schema(type="string", enum={"all", "services", "pages", "testimonials"}), description="Content type to search", example="all"),
     *     @OA\Parameter(name="category", in="query", required=false, @OA\Schema(type="string"), description="Category filter", example="payroll"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Results per page", example=10),
     *     @OA\Response(
     *         response=200,
     *         description="Search results retrieved successfully"
     *     )
     * )
     */
    public function search()
    {
        try {
            $startTime = microtime(true);
            $query = $this->request->getGet('q');
            $type = $this->request->getGet('type') ?? 'all';
            $category = $this->request->getGet('category');
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 10);
            
            if (!$query) {
                return $this->fail('Search query required', 400);
            }
            
            $allResults = [];
            
            // Search services
            if ($type === 'all' || $type === 'services') {
                $serviceModel = new Service();
                $serviceModel->where('isActive', true);
                
                if ($category) {
                    $serviceModel->where('category', $category);
                }
                
                $services = $serviceModel->groupStart()
                    ->like('title', $query)
                    ->orLike('description', $query)
                    ->orLike('shortDesc', $query)
                    ->orLike('category', $query)
                    ->groupEnd()
                    ->orderBy('position', 'ASC')
                    ->limit(20)
                    ->findAll();
                
                foreach ($services as $service) {
                    $allResults[] = [
                        'id' => $service['id'],
                        'title' => $service['title'] ?? '',
                        'description' => $service['shortDesc'] ?? $service['description'] ?? '',
                        'url' => '/services/' . ($service['slug'] ?? $service['id']),
                        'type' => 'service',
                        'category' => !empty($service['category']) ? $service['category'] : null,
                        'score' => 0, // CodeIgniter doesn't calculate relevance score
                        'metadata' => [
                            'slug' => $service['slug'] ?? null,
                            'imageUrl' => $service['imageUrl'] ?? null,
                        ],
                    ];
                }
            }
            
            // Search pages
            if ($type === 'all' || $type === 'pages') {
                $pageModel = new PageContent();
                $pageModel->where('isActive', true);
                
                $pageResults = $pageModel->groupStart()
                    ->like('title', $query)
                    ->orLike('subtitle', $query)
                    ->orLike('description', $query)
                    ->orLike('heroTitle', $query)
                    ->orLike('heroSubtitle', $query)
                    ->orLike('heroDescription', $query)
                    ->orLike('keywords', $query)
                    ->groupEnd()
                    ->limit(10)
                    ->findAll();
                
                foreach ($pageResults as $pageItem) {
                    $allResults[] = [
                        'id' => $pageItem['id'],
                        'title' => $pageItem['title'] ?? $pageItem['heroTitle'] ?? '',
                        'description' => $pageItem['subtitle'] ?? $pageItem['description'] ?? '',
                        'url' => '/' . ($pageItem['pageKey'] ?? $pageItem['id']),
                        'type' => 'page',
                        'score' => 0,
                        'metadata' => [
                            'pageKey' => $pageItem['pageKey'] ?? null,
                        ],
                    ];
                }
            }
            
            // Search testimonials
            if ($type === 'all' || $type === 'testimonials') {
                $testimonialModel = new Testimonial();
                $testimonialModel->where('isActive', true);
                
                $testimonials = $testimonialModel->groupStart()
                    ->like('name', $query)
                    ->orLike('role', $query)
                    ->orLike('company', $query)
                    ->orLike('content', $query)
                    ->orLike('service', $query)
                    ->groupEnd()
                    ->orderBy('position', 'ASC')
                    ->limit(10)
                    ->findAll();
                
                foreach ($testimonials as $testimonial) {
                    $title = trim(($testimonial['name'] ?? '') . ' - ' . ($testimonial['role'] ?? ''));
                    $allResults[] = [
                        'id' => $testimonial['id'],
                        'title' => $title ?: '',
                        'description' => $testimonial['content'] ?? '',
                        'url' => '/testimonials#' . $testimonial['id'],
                        'type' => 'testimonial',
                        'category' => !empty($testimonial['service']) ? $testimonial['service'] : null,
                        'score' => 0,
                        'metadata' => [
                            'company' => $testimonial['company'] ?? null,
                            'avatar' => $testimonial['avatar'] ?? null,
                        ],
                    ];
                }
            }
            
            // Note: Node.js doesn't search jobs in the main search endpoint
            // Jobs are searched via /api/jobs/search endpoint
            // So we'll skip jobs here to match Node.js behavior
            
            $responseTime = (int) ((microtime(true) - $startTime) * 1000); // Convert to milliseconds
            $searchType = 'mysql'; // CodeIgniter uses MySQL search
            
            // Pagination
            $total = count($allResults);
            $startIndex = ($page - 1) * $limit;
            $endIndex = $startIndex + $limit;
            $paginatedResults = array_slice($allResults, $startIndex, $limit);
            
            // Log search analytics (non-blocking)
            try {
                $analyticsModel = new SearchAnalytics();
                $analyticsModel->insert([
                    'id' => uniqid('search_'),
                    'query' => $query,
                    'resultsCount' => $total,
                    'searchType' => $searchType,
                    'userAgent' => $this->request->getUserAgent()->getAgentString(),
                    'ipAddress' => $this->request->getIPAddress()
                ]);
            } catch (\Exception $e) {
                // Silently fail analytics logging
            }
            
            // Node.js returns: { success: true, query: "...", results: [...], pagination: {...}, searchType: "...", responseTime: ... }
            return $this->respond([
                'success' => true,
                'query' => $query,
                'results' => $paginatedResults,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'totalPages' => (int) ceil($total / $limit),
                    'hasNext' => $endIndex < $total,
                    'hasPrev' => $page > 1,
                ],
                'searchType' => $searchType,
                'responseTime' => $responseTime
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to perform search',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/search/suggestions",
     *     tags={"Search"},
     *     summary="Get search suggestions",
     *     @OA\Parameter(name="q", in="query", required=true, @OA\Schema(type="string"), description="Search query for suggestions", example="payroll"),
     *     @OA\Response(
     *         response=200,
     *         description="Search suggestions retrieved successfully"
     *     )
     * )
     */
    public function getSuggestions()
    {
        $query = $this->request->getGet('q');
        
        if (!$query) {
            return $this->respond(['success' => true, 'suggestions' => []]);
        }
        
        $popularModel = new PopularSearch();
        $suggestions = $popularModel->like('query', $query)
            ->where('isActive', true)
            ->orderBy('searchCount', 'DESC')
            ->limit(10)
            ->findAll();
        
        return $this->respond([
            'success' => true,
            'suggestions' => array_column($suggestions, 'query')
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/search/popular",
     *     tags={"Search"},
     *     summary="Get popular searches",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Popular searches retrieved successfully"
     *     )
     * )
     */
    public function getPopularSearches()
    {
        $limit = (int) ($this->request->getGet('limit') ?? 10);
        $popularModel = new PopularSearch();
        
        $searches = $popularModel->where('isActive', true)
            ->orderBy('searchCount', 'DESC')
            ->limit($limit)
            ->findAll();
        
        return $this->respond([
            'success' => true,
            'data' => $searches
        ]);
    }
}
