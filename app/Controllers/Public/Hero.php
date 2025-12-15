<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\HeroDashboard;
use App\Models\HeroContent;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Public - Hero",
 *     description="Hero section endpoints"
 * )
 */
class Hero extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/hero",
     *     tags={"Public - Hero"},
     *     summary="Get hero section data",
     *     @OA\Response(
     *         response=200,
     *         description="Hero data retrieved successfully"
     *     )
     * )
     */
    public function getHeroData()
    {
        try {
            $dashboardModel = new HeroDashboard();
            $contentModel = new HeroContent();
            
            $dashboards = $dashboardModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
            $content = $contentModel->where('isActive', true)->first();
            
            // Process dashboards (matching Node.js)
            // JSON parsing for stats and features is handled automatically by DataTypeHelper
            // But we need type-specific logic for content vs image dashboards
            $processedDashboards = [];
            foreach ($dashboards as $dashboard) {
                $processedDashboard = $dashboard;
                
                // Type-specific field handling (matching Node.js)
                if ($dashboard['type'] === 'content') {
                    // Ensure stats and features are arrays (default to empty if not set)
                    if (!isset($processedDashboard['stats'])) {
                        $processedDashboard['stats'] = [];
                    }
                    if (!isset($processedDashboard['features'])) {
                        $processedDashboard['features'] = [];
                    }
                    $processedDashboard['imageUrl'] = null;
                } elseif ($dashboard['type'] === 'image') {
                    $processedDashboard['stats'] = null;
                    $processedDashboard['features'] = null;
                    $processedDashboard['imageUrl'] = $dashboard['imageUrl'] ?? null;
                }
                
                $processedDashboards[] = $processedDashboard;
            }
            
            return $this->respond([
                'success' => true,
                'data' => [
                    'heroDashboards' => $processedDashboards,
                    'heroContent' => $content ?: null
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => [
                    'dashboards' => [],
                    'content' => null
                ]
            ]);
        }
    }
}
