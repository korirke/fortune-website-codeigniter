<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\FaqAnalytics;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="FAQ",
 *     description="FAQ endpoints"
 * )
 */
class PublicFaq extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/faq",
     *     tags={"FAQ"},
     *     summary="Get all FAQs",
     *     @OA\Parameter(name="category", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="popular", in="query", required=false, @OA\Schema(type="boolean")),
     *     @OA\Response(
     *         response=200,
     *         description="FAQs retrieved successfully"
     *     )
     * )
     */
    public function getAllFaqs()
    {
        $faqModel = new Faq();
        $category = $this->request->getGet('category');
        $search = $this->request->getGet('search');
        $popular = $this->request->getGet('popular');
        
        $faqModel->where('isActive', true);
        if ($category) $faqModel->where('categoryId', $category);
        if ($search) {
            $faqModel->groupStart()
                ->like('question', $search)
                ->orLike('answer', $search)
                ->groupEnd();
        }
        if ($popular === 'true') $faqModel->where('isPopular', true);
        
        $faqs = $faqModel->orderBy('position', 'ASC')->findAll();
        
        return $this->respond([
            'success' => true,
            'message' => 'FAQs retrieved successfully',
            'data' => $faqs
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/faq/categories",
     *     tags={"FAQ"},
     *     summary="Get all FAQ categories",
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved successfully"
     *     )
     * )
     */
    public function getAllCategories()
    {
        $categoryModel = new FaqCategory();
        $categories = $categoryModel->where('isActive', true)
            ->orderBy('position', 'ASC')
            ->findAll();
        
        return $this->respond([
            'success' => true,
            'message' => 'Categories retrieved successfully',
            'data' => $categories
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/faq/categories/{key}",
     *     tags={"FAQ"},
     *     summary="Get category by key",
     *     @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Category retrieved successfully"
     *     )
     * )
     */
    public function getCategoryByKey($key = null)
    {
        // Get key from route parameter or URI segment
        if ($key === null) {
            $key = $this->request->getUri()->getSegment(3);
        }
        
        if (!$key) {
            return $this->fail('Category key is required', 400);
        }
        
        $categoryModel = new FaqCategory();
        $category = $categoryModel->where('key', $key)->where('isActive', true)->first();
        
        if (!$category) {
            return $this->failNotFound('Category not found');
        }
        
        return $this->respond([
            'success' => true,
            'message' => 'Category retrieved successfully',
            'data' => $category
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/faq/stats",
     *     tags={"FAQ"},
     *     summary="Get FAQ statistics",
     *     @OA\Response(
     *         response=200,
     *         description="FAQ stats retrieved successfully"
     *     )
     * )
     */
    public function getFaqStats()
    {
        $faqModel = new Faq();
        $categoryModel = new FaqCategory();
        
        $stats = [
            'totalFaqs' => $faqModel->where('isActive', true)->countAllResults(false),
            'totalCategories' => $categoryModel->where('isActive', true)->countAllResults(false),
            'popularFaqs' => $faqModel->where('isPopular', true)->where('isActive', true)->countAllResults(false)
        ];
        
        return $this->respond([
            'success' => true,
            'message' => 'FAQ stats retrieved successfully',
            'data' => $stats
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/faq/{id}",
     *     tags={"FAQ"},
     *     summary="Get FAQ by ID",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="FAQ retrieved successfully"
     *     )
     * )
     */
    public function getFaqById($id = null)
    {
        // Get ID from route parameter or URI segment
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(2);
        }
        
        if (!$id) {
            return $this->fail('FAQ ID is required', 400);
        }
        
        $faqModel = new Faq();
        $faq = $faqModel->find($id);
        
        if (!$faq) {
            return $this->failNotFound('FAQ not found');
        }
        
        // Increment views
        $faqModel->update($id, ['views' => ($faq['views'] ?? 0) + 1]);
        
        return $this->respond([
            'success' => true,
            'message' => 'FAQ retrieved successfully',
            'data' => $faq
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/faq/{id}/helpful",
     *     tags={"FAQ"},
     *     summary="Mark FAQ as helpful",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"helpful"},
     *             @OA\Property(property="helpful", type="boolean")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Feedback recorded successfully"
     *     )
     * )
     */
    public function markAsHelpful($id = null)
    {
        // Get ID from route parameter or URI segment
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(2);
        }
        
        if (!$id) {
            return $this->fail('FAQ ID is required', 400);
        }
        
        $data = $this->request->getJSON(true);
        $helpful = $data['helpful'] ?? true;
        
        $faqModel = new Faq();
        $faq = $faqModel->find($id);
        
        if (!$faq) {
            return $this->failNotFound('FAQ not found');
        }
        
        if ($helpful) {
            $faqModel->update($id, [
                'helpfulCount' => ($faq['helpfulCount'] ?? 0) + 1
            ]);
        }
        
        // Log analytics
        $analyticsModel = new FaqAnalytics();
        $analyticsModel->insert([
            'id' => uniqid('faq_analytics_'),
            'faqId' => $id,
            'action' => $helpful ? 'helpful' : 'not_helpful',
            'userAgent' => $this->request->getUserAgent()->getAgentString(),
            'ipAddress' => $this->request->getIPAddress()
        ]);
        
        return $this->respond([
            'success' => true,
            'message' => 'Feedback recorded successfully'
        ]);
    }
}
