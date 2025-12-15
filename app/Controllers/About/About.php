<?php

namespace App\Controllers\About;

use App\Controllers\BaseController;
use App\Models\AboutPage;
use App\Models\AboutPageSection;
use App\Models\AboutPageVersion;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="About Admin",
 *     description="About page admin endpoints"
 * )
 */
class About extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/about",
     *     tags={"About Admin"},
     *     summary="Create about content",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"content"},
     *             @OA\Property(property="content", type="string", description="About page content (min 10 characters)", example="We are a leading HR solutions provider...", minLength=10),
     *             @OA\Property(property="title", type="string", description="About page title", example="About Us"),
     *             @OA\Property(property="subtitle", type="string", description="About page subtitle"),
     *             @OA\Property(property="metaTitle", type="string", description="SEO meta title"),
     *             @OA\Property(property="metaDescription", type="string", description="SEO meta description")
     *         )
     *     ),
     *     @OA\Response(response="201", description="About content created successfully")
     * )
     */
    public function createAboutContent()
    {
        try {
            $data = $this->request->getJSON(true);
            $aboutModel = new AboutPage();
            
            // Node.js returns { content, updatedAt } (matching Node.js)
            $content = $data['content'] ?? null;
            if (!$content || (is_string($content) && trim($content) === '')) {
                return $this->fail('Content cannot be empty', 400);
            }
            
            // Check if there's an existing about page
            $existingAboutPage = $aboutModel->orderBy('createdAt', 'ASC')->first();
            
            if ($existingAboutPage) {
                // Update existing record
                $aboutModel->update($existingAboutPage['id'], [
                    'content' => is_string($content) ? trim($content) : json_encode($content)
                ]);
                $aboutPage = $aboutModel->find($existingAboutPage['id']);
            } else {
                // Create new record
                $aboutData = [
                    'id' => uniqid('about_'),
                    'content' => is_string($content) ? trim($content) : json_encode($content)
                ];
                $aboutModel->insert($aboutData);
                $aboutPage = $aboutModel->find($aboutData['id']);
            }
            
            // Return { content, updatedAt } (matching Node.js)
            // JSON parsing for content is handled automatically by DataTypeHelper
            $returnContent = $aboutPage['content'];

            return $this->respondCreated([
                'success' => true,
                'message' => 'About content created successfully',
                'data' => [
                    'content' => $returnContent,
                    'updatedAt' => $aboutPage['updatedAt']
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to create about content',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/about",
     *     tags={"About Admin"},
     *     summary="Update about content",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="content", type="string", description="About page content (min 10 characters)", minLength=10),
     *             @OA\Property(property="title", type="string", description="About page title"),
     *             @OA\Property(property="subtitle", type="string", description="About page subtitle"),
     *             @OA\Property(property="metaTitle", type="string", description="SEO meta title"),
     *             @OA\Property(property="metaDescription", type="string", description="SEO meta description")
     *         )
     *     ),
     *     @OA\Response(response="200", description="About content updated successfully")
     * )
     */
    public function updateAboutContent()
    {
        try {
            $data = $this->request->getJSON(true);
            $aboutModel = new AboutPage();
            
            // Node.js returns { content, updatedAt } (matching Node.js)
            $content = $data['content'] ?? null;
            if (!$content || (is_string($content) && trim($content) === '')) {
                return $this->fail('Content cannot be empty', 400);
            }
            
            $about = $aboutModel->orderBy('createdAt', 'ASC')->first();
            if ($about) {
                $aboutModel->update($about['id'], [
                    'content' => is_string($content) ? trim($content) : json_encode($content)
                ]);
                $aboutPage = $aboutModel->find($about['id']);
            } else {
                $aboutData = [
                    'id' => uniqid('about_'),
                    'content' => is_string($content) ? trim($content) : json_encode($content)
                ];
                $aboutModel->insert($aboutData);
                $aboutPage = $aboutModel->find($aboutData['id']);
            }
            
            // Return { content, updatedAt } (matching Node.js)
            // JSON parsing for content is handled automatically by DataTypeHelper
            $returnContent = $aboutPage['content'];

            return $this->respond([
                'success' => true,
                'message' => 'About content updated successfully',
                'data' => [
                    'content' => $returnContent,
                    'updatedAt' => $aboutPage['updatedAt']
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update about content',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/about/sections",
     *     tags={"About Admin"},
     *     summary="Create about section",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"sectionKey", "title", "content"},
     *             @OA\Property(property="sectionKey", type="string", description="Unique section key", example="mission"),
     *             @OA\Property(property="title", type="string", description="Section title", example="Our Mission"),
     *             @OA\Property(property="content", type="string", description="Section content", example="Our mission is to..."),
     *             @OA\Property(property="subtitle", type="string", description="Section subtitle"),
     *             @OA\Property(property="imageUrl", type="string", description="Section image URL"),
     *             @OA\Property(property="sortOrder", type="integer", description="Display order", example=1),
     *             @OA\Property(property="isActive", type="boolean", description="Active status", example=true)
     *         )
     *     ),
     *     @OA\Response(response="201", description="Section created successfully")
     * )
     */
    public function createSection()
    {
        try {
            $data = $this->request->getJSON(true);
            $sectionModel = new AboutPageSection();
            
            // Node.js returns section object (matching Node.js)
            $sectionData = [
                'id' => uniqid('section_'),
                'sectionKey' => $data['sectionKey'],
                'sectionName' => $data['sectionName'] ?? $data['title'] ?? null,
                'content' => $data['content'] ?? null,
                'sortOrder' => $data['sortOrder'] ?? 0,
                'isActive' => $data['isActive'] ?? true
            ];
            
            // Content column is JSON type - always encode to JSON
            if (isset($sectionData['content'])) {
                if (is_string($sectionData['content']) && (substr(trim($sectionData['content']), 0, 1) === '{' || substr(trim($sectionData['content']), 0, 1) === '[')) {
                    json_decode($sectionData['content']);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $sectionData['content'] = json_encode($sectionData['content']);
                    }
                } else {
                    $sectionData['content'] = json_encode($sectionData['content']);
                }
            }
            
            $sectionModel->insert($sectionData);
            $createdSection = $sectionModel->find($sectionData['id']);
            
            // JSON parsing for content is handled automatically by DataTypeHelper

            return $this->respondCreated([
                'success' => true,
                'message' => 'About section created successfully',
                'data' => [
                    'id' => $createdSection['id'],
                    'sectionKey' => $createdSection['sectionKey'],
                    'sectionName' => $createdSection['sectionName'],
                    'content' => $createdSection['content'],
                    'sortOrder' => $createdSection['sortOrder'],
                    'updatedAt' => $createdSection['updatedAt']
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to create section',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/about/sections/{key}",
     *     tags={"About Admin"},
     *     summary="Update about section",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", description="Section title", example="Our Mission"),
     *             @OA\Property(property="content", type="string", description="Section content", example="Our mission is to..."),
     *             @OA\Property(property="subtitle", type="string", description="Section subtitle"),
     *             @OA\Property(property="imageUrl", type="string", description="Section image URL"),
     *             @OA\Property(property="sortOrder", type="integer", description="Display order"),
     *             @OA\Property(property="isActive", type="boolean", description="Active status")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Section updated successfully")
     * )
     */
    public function updateSection($key = null)
    {
        // Get key from route parameter or URI segment
        if ($key === null) {
            $key = $this->request->getUri()->getSegment(3);
        }
        
        if (!$key) {
            return $this->fail('Section key is required', 400);
        }
        
        $data = $this->request->getJSON(true);
        $sectionModel = new AboutPageSection();
        
        // Content column is JSON type - always encode to JSON
        // MySQL JSON columns require valid JSON, so even plain strings must be JSON-encoded
        if (isset($data['content'])) {
            // Check if it's already a valid JSON string (starts with { or [)
            if (is_string($data['content']) && (substr(trim($data['content']), 0, 1) === '{' || substr(trim($data['content']), 0, 1) === '[')) {
                // Validate it's actually valid JSON
                json_decode($data['content']);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Already valid JSON, use as is
                    // No change needed
                } else {
                    // Invalid JSON, encode it
                    $data['content'] = json_encode($data['content']);
                }
            } else {
                // Plain string, array, or object - always encode it
                $data['content'] = json_encode($data['content']);
            }
        }
        
        $section = $sectionModel->where('sectionKey', $key)->first();
        if (!$section) {
            return $this->failNotFound('Section not found');
        }
        
        // Content column is JSON type - always encode to JSON
        if (isset($data['content'])) {
            if (is_string($data['content']) && (substr(trim($data['content']), 0, 1) === '{' || substr(trim($data['content']), 0, 1) === '[')) {
                json_decode($data['content']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $data['content'] = json_encode($data['content']);
                }
            } else {
                $data['content'] = json_encode($data['content']);
            }
        }
        
        // Create version backup (matching Node.js)
        $versionModel = new AboutPageVersion();
        $latestVersion = $versionModel->where('sectionKey', $key)
            ->orderBy('version', 'DESC')
            ->first();
        $nextVersion = ($latestVersion ? $latestVersion['version'] : 0) + 1;
        
        $versionModel->insert([
            'id' => uniqid('version_'),
            'sectionKey' => $key,
            'version' => $nextVersion,
            'content' => $section['content'],
            'changeDescription' => 'Content updated'
        ]);
        
        $sectionModel->update($section['id'], $data);
        $updatedSection = $sectionModel->find($section['id']);
        
        // JSON parsing for content is handled automatically by DataTypeHelper

        return $this->respond([
            'success' => true,
            'message' => 'About section updated successfully',
            'data' => [
                'id' => $updatedSection['id'],
                'sectionKey' => $updatedSection['sectionKey'],
                'sectionName' => $updatedSection['sectionName'],
                'content' => $updatedSection['content'],
                'sortOrder' => $updatedSection['sortOrder'],
                'updatedAt' => $updatedSection['updatedAt']
            ]
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/about/sections/reorder",
     *     tags={"About Admin"},
     *     summary="Reorder about sections",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="string", description="Section ID", example="section_123"),
     *                 @OA\Property(property="sortOrder", type="integer", description="New sort order", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(response="200", description="Sections reordered successfully")
     * )
     */
    public function reorderSections()
    {
        $data = $this->request->getJSON(true);
        $sectionModel = new AboutPageSection();
        
        foreach ($data as $item) {
            $sectionModel->update($item['id'], ['sortOrder' => $item['sortOrder']]);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Sections reordered successfully'
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/about/sections/{key}/toggle",
     *     tags={"About Admin"},
     *     summary="Toggle section visibility",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"isActive"},
     *             @OA\Property(property="isActive", type="boolean", description="Active status", example=true)
     *         )
     *     ),
     *     @OA\Response(response="200", description="Section visibility updated successfully")
     * )
     */
    public function toggleSection($key = null)
    {
        // Get key from route parameter or URI segment
        if ($key === null) {
            $key = $this->request->getUri()->getSegment(3);
        }
        
        if (!$key) {
            return $this->fail('Section key is required', 400);
        }
        
        $data = $this->request->getJSON(true);
        $sectionModel = new AboutPageSection();
        
        $section = $sectionModel->where('sectionKey', $key)->first();
        if ($section) {
            $sectionModel->update($section['id'], ['isActive' => $data['isActive']]);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Section visibility updated successfully'
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/about/sections/{key}",
     *     tags={"About Admin"},
     *     summary="Delete about section",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Section deleted successfully")
     * )
     */
    public function deleteSection($key = null)
    {
        // Get key from route parameter or URI segment
        if ($key === null) {
            $key = $this->request->getUri()->getSegment(3);
        }
        
        if (!$key) {
            return $this->fail('Section key is required', 400);
        }
        
        $sectionModel = new AboutPageSection();
        $section = $sectionModel->where('sectionKey', $key)->first();
        
        if ($section) {
            $sectionModel->delete($section['id']);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Section deleted successfully'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/about/sections/{key}/versions",
     *     tags={"About Admin"},
     *     summary="Get section versions",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Versions retrieved successfully")
     * )
     */
    public function getSectionVersions($key = null)
    {
        // Get key from route parameter or URI segment
        if ($key === null) {
            $key = $this->request->getUri()->getSegment(3);
        }
        
        if (!$key) {
            return $this->fail('Section key is required', 400);
        }
        
        $versionModel = new AboutPageVersion();
        $versions = $versionModel->where('sectionKey', $key)
            ->orderBy('version', 'DESC')
            ->findAll();

        return $this->respond([
            'success' => true,
            'data' => $versions
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/about/sections/{key}/restore/{version}",
     *     tags={"About Admin"},
     *     summary="Restore section version",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="version", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response="200", description="Version restored successfully")
     * )
     */
    public function restoreVersion($key = null, $version = null)
    {
        // Get key and version from route parameters or URI segments
        if ($key === null) {
            $key = $this->request->getUri()->getSegment(3);
        }
        if ($version === null) {
            $version = $this->request->getUri()->getSegment(4);
        }
        
        if (!$key) {
            return $this->fail('Section key is required', 400);
        }
        if (!$version) {
            return $this->fail('Version number is required', 400);
        }
        
        $versionModel = new AboutPageVersion();
        $versionData = $versionModel->where('sectionKey', $key)
            ->where('version', $version)
            ->first();

        if (!$versionData) {
            return $this->failNotFound('Version not found');
        }

        $sectionModel = new AboutPageSection();
        $section = $sectionModel->where('sectionKey', $key)->first();
        if (!$section) {
            return $this->failNotFound('Section not found');
        }
        
        $sectionModel->update($section['id'], ['content' => $versionData['content']]);
        $restoredSection = $sectionModel->find($section['id']);
        
        // JSON parsing for content is handled automatically by DataTypeHelper

        return $this->respond([
            'success' => true,
            'message' => 'Version restored successfully',
            'data' => [
                'id' => $restoredSection['id'],
                'sectionKey' => $restoredSection['sectionKey'],
                'sectionName' => $restoredSection['sectionName'],
                'content' => $restoredSection['content'],
                'sortOrder' => $restoredSection['sortOrder'],
                'updatedAt' => $restoredSection['updatedAt']
            ]
        ]);
    }
}
