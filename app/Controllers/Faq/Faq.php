<?php

namespace App\Controllers\Faq;

use App\Controllers\BaseController;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="FAQ Admin",
 *     description="FAQ admin management endpoints"
 * )
 */
class FaqController extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/faq",
     *     tags={"FAQ Admin"},
     *     summary="Create FAQ",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"question", "answer", "category"},
     *             @OA\Property(property="question", type="string", description="FAQ question (min 5 characters)", example="How do I reset my password?"),
     *             @OA\Property(property="answer", type="string", description="FAQ answer (min 10 characters)", example="You can reset your password by clicking the 'Forgot Password' link on the login page."),
     *             @OA\Property(property="category", type="string", description="Category ID", example="category_123"),
     *             @OA\Property(property="isPopular", type="boolean", description="Mark as popular FAQ", example=false),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), description="FAQ tags", example={"password", "account"}),
     *             @OA\Property(property="isActive", type="boolean", description="Active status", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="FAQ created successfully"
     *     )
     * )
     */
    public function createFaq()
    {
        try {
            $data = $this->request->getJSON(true);
            $faqModel = new Faq();
            $categoryModel = new FaqCategory();
            
            // Validate category exists (matching Node.js)
            $category = $categoryModel->where('key', $data['category'])->first();
            if (!$category) {
                return $this->fail('Category not found', 400);
            }
            
            $faqData = [
                'id' => uniqid('faq_'),
                'question' => $data['question'],
                'answer' => $data['answer'],
                'categoryId' => $category['id'],
                'isPopular' => $data['isPopular'] ?? false,
                'tags' => isset($data['tags']) && is_array($data['tags']) ? json_encode($data['tags']) : json_encode([]),
                'isActive' => $data['isActive'] ?? true
            ];
            
            $faqModel->insert($faqData);
            
            // Get created FAQ with category (matching Node.js)
            $createdFaq = $faqModel->find($faqData['id']);
            $createdFaq['category'] = $category;

            return $this->respondCreated([
                'success' => true,
                'message' => 'FAQ created successfully',
                'data' => $createdFaq
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to create FAQ',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/faq/{id}",
     *     tags={"FAQ Admin"},
     *     summary="Update FAQ",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="question", type="string", description="FAQ question (min 5 characters)", example="How do I reset my password?"),
     *             @OA\Property(property="answer", type="string", description="FAQ answer (min 10 characters)", example="You can reset your password by clicking the 'Forgot Password' link."),
     *             @OA\Property(property="category", type="string", description="Category ID", example="category_123"),
     *             @OA\Property(property="isPopular", type="boolean", description="Mark as popular FAQ", example=false),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), description="FAQ tags", example={"password", "account"}),
     *             @OA\Property(property="isActive", type="boolean", description="Active status", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="FAQ updated successfully"
     *     )
     * )
     */
    public function updateFaq($id)
    {
        try {
            $data = $this->request->getJSON(true);
            $faqModel = new Faq();
            $categoryModel = new FaqCategory();
            
            $faq = $faqModel->find($id);
            if (!$faq) {
                return $this->failNotFound('FAQ not found');
            }
            
            $updateData = [];
            if (isset($data['question'])) $updateData['question'] = $data['question'];
            if (isset($data['answer'])) $updateData['answer'] = $data['answer'];
            if (isset($data['isPopular'])) $updateData['isPopular'] = $data['isPopular'];
            if (isset($data['isActive'])) $updateData['isActive'] = $data['isActive'];
            if (isset($data['tags'])) {
                $updateData['tags'] = is_array($data['tags']) ? json_encode($data['tags']) : json_encode([]);
            }
            
            // Handle category update (matching Node.js)
            if (isset($data['category'])) {
                $category = $categoryModel->where('key', $data['category'])->first();
                if (!$category) {
                    return $this->fail('Category not found', 400);
                }
                $updateData['categoryId'] = $category['id'];
            }
            
            $faqModel->update($id, $updateData);
            
            // Get updated FAQ with category (matching Node.js)
            $updatedFaq = $faqModel->find($id);
            $category = $categoryModel->find($updatedFaq['categoryId']);
            $updatedFaq['category'] = $category;

            return $this->respond([
                'success' => true,
                'message' => 'FAQ updated successfully',
                'data' => $updatedFaq
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update FAQ',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/faq/{id}",
     *     tags={"FAQ Admin"},
     *     summary="Delete FAQ",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="FAQ deleted successfully"
     *     )
     * )
     */
    public function deleteFaq($id)
    {
        $faqModel = new Faq();
        $faqModel->delete($id);

        return $this->respond([
            'success' => true,
            'message' => 'FAQ deleted successfully'
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/faq/reorder",
     *     tags={"FAQ Admin"},
     *     summary="Reorder FAQs",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="string", description="FAQ ID", example="faq_123"),
     *                 @OA\Property(property="position", type="integer", description="New position", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="FAQs reordered successfully"
     *     )
     * )
     */
    public function reorderFaqs()
    {
        $data = $this->request->getJSON(true);
        $faqModel = new Faq();
        
        foreach ($data as $item) {
            $faqModel->update($item['id'], ['position' => $item['position']]);
        }

        return $this->respond([
            'success' => true,
            'message' => 'FAQs reordered successfully'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/faq/categories",
     *     tags={"FAQ Admin"},
     *     summary="Create FAQ category",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=201,
     *         description="Category created successfully"
     *     )
     * )
     */
    public function createCategory()
    {
        try {
            $data = $this->request->getJSON(true);
            $categoryModel = new FaqCategory();
            
            $categoryData = [
                'id' => uniqid('category_'),
                'name' => $data['name'],
                'key' => $data['key'] ?? strtolower(preg_replace('/[^a-z0-9]+/', '-', $data['name'])),
                'icon' => $data['icon'] ?? null,
                'description' => $data['description'] ?? null,
                'isActive' => $data['isActive'] ?? true,
                'position' => $data['position'] ?? 0
            ];
            
            $categoryModel->insert($categoryData);
            $createdCategory = $categoryModel->find($categoryData['id']);
            
            // Add _count for faqs (matching Node.js)
            $faqModel = new \App\Models\Faq();
            $faqCount = $faqModel->where('categoryId', $createdCategory['id'])
                ->where('isActive', true)
                ->countAllResults(false);
            $createdCategory['faqCount'] = $faqCount;

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
     *     path="/api/faq/categories/{id}",
     *     tags={"FAQ Admin"},
     *     summary="Update FAQ category",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", description="Category name", example="Account Management"),
     *             @OA\Property(property="icon", type="string", description="Icon identifier", example="user-circle"),
     *             @OA\Property(property="description", type="string", description="Category description", example="Questions about account management"),
     *             @OA\Property(property="isActive", type="boolean", description="Active status", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Category updated successfully"
     *     )
     * )
     */
    public function updateCategory($id)
    {
        try {
            $data = $this->request->getJSON(true);
            $categoryModel = new FaqCategory();
            
            $category = $categoryModel->find($id);
            if (!$category) {
                return $this->failNotFound('Category not found');
            }
            
            $categoryModel->update($id, $data);
            $updatedCategory = $categoryModel->find($id);
            
            // Add _count for faqs (matching Node.js)
            $faqModel = new \App\Models\Faq();
            $faqCount = $faqModel->where('categoryId', $updatedCategory['id'])
                ->where('isActive', true)
                ->countAllResults(false);
            $updatedCategory['faqCount'] = $faqCount;

            return $this->respond([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $updatedCategory
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update category',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/faq/categories/{id}",
     *     tags={"FAQ Admin"},
     *     summary="Delete FAQ category",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Category deleted successfully"
     *     )
     * )
     */
    public function deleteCategory($id)
    {
        $categoryModel = new FaqCategory();
        $categoryModel->delete($id);

        return $this->respond([
            'success' => true,
            'message' => 'Category deleted successfully'
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/faq/categories/reorder",
     *     tags={"FAQ Admin"},
     *     summary="Reorder FAQ categories",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="string", description="Category ID", example="category_123"),
     *                 @OA\Property(property="position", type="integer", description="New position", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Categories reordered successfully"
     *     )
     * )
     */
    public function reorderCategories()
    {
        $data = $this->request->getJSON(true);
        $categoryModel = new FaqCategory();
        
        foreach ($data as $item) {
            $categoryModel->update($item['id'], ['position' => $item['position']]);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Categories reordered successfully'
        ]);
    }
}
