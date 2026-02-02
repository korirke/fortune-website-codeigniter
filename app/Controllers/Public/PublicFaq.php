<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Traits\NormalizedResponseTrait;

/**
 * Public FAQ endpoints (no auth required)
 */
class PublicFaq extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * Get all FAQs with categories
     * When authenticated (admin): returns all FAQs including inactive
     * When not authenticated: returns only active FAQs
     */
    public function getAllFaqs()
    {
        try {
            $faqModel = new Faq();
            $categoryModel = new FaqCategory();

            $builder = $faqModel
                ->select('faqs.*')
                ->join('faq_categories', 'faq_categories.id = faqs.categoryId')
                ->orderBy('faq_categories.position', 'ASC')
                ->orderBy('faqs.position', 'ASC');

            // Public: only active. Admin (authenticated): all
            if (!$this->request->header('Authorization')) {
                $builder->where('faqs.isActive', true)->where('faq_categories.isActive', true);
            }

            $faqs = $builder->findAll();

            $result = [];
            foreach ($faqs as $faq) {
                $category = $categoryModel->find($faq['categoryId']);
                $faq['category'] = $category ?: [
                    'id' => '',
                    'name' => '',
                    'key' => '',
                    'icon' => ''
                ];
                $faq['tags'] = isset($faq['tags']) && is_string($faq['tags'])
                    ? (json_decode($faq['tags'], true) ?: [])
                    : ($faq['tags'] ?? []);
                $result[] = $faq;
            }

            return $this->respond([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            log_message('error', 'PublicFaq getAllFaqs: ' . $e->getMessage());
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * Get all FAQ categories with faqCount
     * When authenticated (admin): returns all categories including inactive
     */
    public function getAllCategories()
    {
        try {
            $categoryModel = new FaqCategory();
            $faqModel = new Faq();

            $builder = $categoryModel->orderBy('position', 'ASC');
            if (!$this->request->header('Authorization')) {
                $builder->where('isActive', true);
            }
            $categories = $builder->findAll();

            foreach ($categories as &$cat) {
                $countBuilder = $faqModel->where('categoryId', $cat['id']);
                if (!$this->request->header('Authorization')) {
                    $countBuilder->where('isActive', true);
                }
                $cat['faqCount'] = $countBuilder->countAllResults(false);
            }

            return $this->respond([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            log_message('error', 'PublicFaq getAllCategories: ' . $e->getMessage());
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * Get category by key
     */
    public function getCategoryByKey($key)
    {
        try {
            $categoryModel = new FaqCategory();
            $category = $categoryModel->where('key', $key)->where('isActive', true)->first();
            if (!$category) {
                return $this->failNotFound('Category not found');
            }

            $faqModel = new Faq();
            $category['faqCount'] = $faqModel
                ->where('categoryId', $category['id'])
                ->where('isActive', true)
                ->countAllResults(false);

            return $this->respond([
                'success' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to fetch category', 500);
        }
    }

    /**
     * Get FAQ stats
     */
    public function getFaqStats()
    {
        try {
            $faqModel = new Faq();
            $categoryModel = new FaqCategory();

            $faqs = $faqModel->where('isActive', true)->findAll();
            $categories = $categoryModel->where('isActive', true)->findAll();

            $totalViews = array_sum(array_column($faqs, 'views'));
            $popularFaqs = array_filter($faqs, fn($f) => !empty($f['isPopular']));

            usort($faqs, fn($a, $b) => ($b['views'] ?? 0) - ($a['views'] ?? 0));
            $topFaqs = array_slice(array_map(function ($f) {
                return [
                    'id' => $f['id'],
                    'question' => $f['question'],
                    'views' => $f['views'] ?? 0,
                    'helpfulCount' => $f['helpfulCount'] ?? 0
                ];
            }, $faqs), 0, 10);

            return $this->respond([
                'success' => true,
                'data' => [
                    'totalFaqs' => count($faqs),
                    'popularFaqs' => count($popularFaqs),
                    'totalViews' => $totalViews,
                    'categories' => count($categories),
                    'topFaqs' => $topFaqs
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'PublicFaq getFaqStats: ' . $e->getMessage());
            return $this->respond([
                'success' => true,
                'data' => [
                    'totalFaqs' => 0,
                    'popularFaqs' => 0,
                    'totalViews' => 0,
                    'categories' => 0,
                    'topFaqs' => []
                ]
            ]);
        }
    }

    /**
     * Get single FAQ by ID
     */
    public function getFaqById($id)
    {
        try {
            $faqModel = new Faq();
            $categoryModel = new FaqCategory();

            $faq = $faqModel->find($id);
            if (!$faq) {
                return $this->failNotFound('FAQ not found');
            }

            $faq['category'] = $categoryModel->find($faq['categoryId']) ?: [];
            $faq['tags'] = isset($faq['tags']) && is_string($faq['tags'])
                ? (json_decode($faq['tags'], true) ?: [])
                : ($faq['tags'] ?? []);

            return $this->respond([
                'success' => true,
                'data' => $faq
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to fetch FAQ', 500);
        }
    }

    /**
     * Mark FAQ as helpful (or not)
     */
    public function markAsHelpful($id)
    {
        try {
            $data = $this->request->getJSON(true);
            $helpful = $data['helpful'] ?? true;

            $faqModel = new Faq();
            $faq = $faqModel->find($id);
            if (!$faq) {
                return $this->failNotFound('FAQ not found');
            }

            $faqModel->update($id, [
                'helpfulCount' => ($faq['helpfulCount'] ?? 0) + ($helpful ? 1 : 0)
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Thank you for your feedback'
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to submit feedback', 500);
        }
    }
}
