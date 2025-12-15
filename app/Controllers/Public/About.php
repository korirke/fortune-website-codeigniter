<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\AboutPage;
use App\Models\AboutPageSection;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="About",
 *     description="About page public endpoints"
 * )
 */
class About extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/about",
     *     tags={"About"},
     *     summary="Get about content",
     *     @OA\Response(
     *         response=200,
     *         description="About content retrieved successfully"
     *     )
     * )
     */
    public function getAboutContent()
    {
        try {
            $aboutModel = new AboutPage();
            $about = $aboutModel->orderBy('updatedAt', 'DESC')->first();
            
            if (!$about) {
                // Return null if no about page exists (matching Node.js)
                return $this->respond([
                    'success' => true,
                    'message' => 'About content retrieved successfully',
                    'data' => null
                ]);
            }
            
            // JSON parsing for content is handled automatically by DataTypeHelper
            // If content is a JSON string, it will be parsed to array/object
            // If it's already parsed or a plain string, it will be returned as-is
            return $this->respond([
                'success' => true,
                'message' => 'About content retrieved successfully',
                'data' => $about['content'] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve about content',
                'data' => null
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/about/sections",
     *     tags={"About"},
     *     summary="Get all about sections",
     *     @OA\Response(
     *         response=200,
     *         description="About sections retrieved successfully"
     *     )
     * )
     */
    public function getAllSections()
    {
        $sectionModel = new AboutPageSection();
        $sections = $sectionModel->where('isActive', true)
            ->orderBy('sortOrder', 'ASC')
            ->findAll();
        
        return $this->respond([
            'success' => true,
            'message' => 'About sections retrieved successfully',
            'data' => $sections
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/about/sections/{key}",
     *     tags={"About"},
     *     summary="Get about section by key",
     *     @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="About section retrieved successfully"
     *     )
     * )
     */
    public function getSection($key = null)
    {
        // Get key from route parameter or URI segment
        if ($key === null) {
            $key = $this->request->getUri()->getSegment(3);
        }
        
        if (!$key) {
            return $this->fail('Section key is required', 400);
        }
        
        $sectionModel = new AboutPageSection();
        $section = $sectionModel->where('sectionKey', $key)->where('isActive', true)->first();
        
        if (!$section) {
            return $this->failNotFound('Section not found');
        }
        
        return $this->respond([
            'success' => true,
            'message' => 'About section retrieved successfully',
            'data' => $section
        ]);
    }
}
