<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\NavItem;
use App\Models\Stat;
use App\Models\Service;
use App\Models\Testimonial;
use App\Models\FooterSection;
use App\Models\FooterLink;
use App\Models\ContactInfo;
use App\Models\SocialLink;
use App\Models\PageContent;
use App\Models\CallToAction;
use App\Models\SectionContent;
use App\Models\Client;
use App\Models\ContactSubmission;
use App\Models\DropdownData;
use App\Models\DropdownItem;
use App\Models\ThemeConfig;
use App\Libraries\EmailHelper;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Public",
 *     description="Public website content endpoints"
 * )
 */
class PublicController extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/navigation",
     *     tags={"Public"},
     *     summary="Get website navigation",
     *     @OA\Response(
     *         response=200,
     *         description="Navigation retrieved successfully"
     *     )
     * )
     */
    public function getNavigation()
    {
        try {
            $navModel = new NavItem();
            $dropdownDataModel = new DropdownData();
            $dropdownItemModel = new DropdownItem();
            $themeConfigModel = new ThemeConfig();
            
            // Get active navigation items
            $navItems = $navModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
            
            // Get theme config
            $themeConfig = $themeConfigModel->where('isActive', true)->first();
            
            // Build dropdown data structure matching Node.js
            $dropdownData = [];
            foreach ($navItems as $item) {
                if (!empty($item['hasDropdown']) && $item['hasDropdown']) {
                    // Find dropdown data for this nav item
                    $dropdown = $dropdownDataModel->where('navItemId', $item['id'])->first();
                    if ($dropdown) {
                        // Get dropdown items
                        $items = $dropdownItemModel
                            ->where('dropdownDataId', $dropdown['id'])
                            ->where('isActive', true)
                            ->orderBy('position', 'ASC')
                            ->findAll();
                        
                        $dropdownData[$item['key']] = [
                            'title' => $dropdown['title'] ?? '',
                            'items' => $items ?: []
                        ];
                    }
                }
            }
            
            // Remove dropdowns from navItems (matching Node.js structure)
            $navItemsClean = array_map(function($item) {
                unset($item['dropdowns']);
                return $item;
            }, $navItems);
            
            return $this->respond([
                'success' => true,
                'data' => [
                    'navItems' => $navItemsClean ?: [],
                    'dropdownData' => $dropdownData,
                    'themeConfig' => $themeConfig ?: null
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch navigation data',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/stats",
     *     tags={"Public"},
     *     summary="Get all active stats",
     *     @OA\Response(
     *         response=200,
     *         description="Stats retrieved successfully"
     *     )
     * )
     */
    public function getStats()
    {
        $statModel = new Stat();
        $stats = $statModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
        
        return $this->respond([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/services",
     *     tags={"Public"},
     *     summary="Get services with filtering",
     *     @OA\Parameter(name="category", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="featured", in="query", required=false, @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="onQuote", in="query", required=false, @OA\Schema(type="boolean")),
     *     @OA\Response(
     *         response=200,
     *         description="Services retrieved successfully"
     *     )
     * )
     */
    public function getServices()
    {
        try {
            $serviceModel = new Service();
            $category = $this->request->getGet('category');
            $featured = $this->request->getGet('featured');
            $onQuote = $this->request->getGet('onQuote');

            $serviceModel->where('isActive', true);
            if ($category) $serviceModel->where('category', $category);
            if ($featured === 'true') $serviceModel->where('isFeatured', true);
            if ($onQuote === 'true') $serviceModel->where('onQuote', true);

            $services = $serviceModel->orderBy('position', 'ASC')->findAll();
            
            return $this->respond([
                'success' => true,
                'data' => $services ?: []
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/services/{slug}",
     *     tags={"Public"},
     *     summary="Get service by slug",
     *     @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Service retrieved successfully"
     *     )
     * )
     */
    /**
     * @OA\Get(
     *     path="/api/services/quote-options",
     *     tags={"Public"},
     *     summary="Get services available for quotes",
     *     @OA\Response(
     *         response=200,
     *         description="Quote services retrieved successfully"
     *     )
     * )
     */
    public function getQuoteServices()
    {
        try {
            $serviceModel = new Service();
            $services = $serviceModel
                ->where('isActive', true)
                ->where('onQuote', true)
                ->orderBy('position', 'ASC')
                ->findAll();
            
            // Return only specific fields matching Node.js implementation
            $quoteServices = array_map(function($service) {
                return [
                    'id' => $service->id ?? $service['id'],
                    'title' => $service->title ?? $service['title'],
                    'slug' => $service->slug ?? $service['slug'],
                    'category' => $service->category ?? $service['category'],
                    'position' => $service->position ?? $service['position'],
                ];
            }, $services);
            
            return $this->respond([
                'success' => true,
                'data' => $quoteServices ?: []
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/services/categories",
     *     tags={"Public"},
     *     summary="Get all service categories",
     *     @OA\Response(
     *         response=200,
     *         description="Service categories retrieved successfully"
     *     )
     * )
     */
    public function getServiceCategories()
    {
        try {
            $serviceModel = new Service();
            $db = \Config\Database::connect();
            
            // Get distinct categories from active services
            $categories = $db->table('services')
                ->select('category')
                ->distinct()
                ->where('isActive', true)
                ->where('category IS NOT NULL')
                ->where('category !=', '')
                ->orderBy('category', 'ASC')
                ->get()
                ->getResultArray();
            
            // Extract unique category values and sort
            $uniqueCategories = array_unique(array_column($categories, 'category'));
            sort($uniqueCategories);
            
            return $this->respond([
                'success' => true,
                'data' => $uniqueCategories ?: []
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    public function getServiceBySlug($slug = null)
    {
        // Get slug from route parameter or URI segment
        // Route: /api/services/:slug
        // Full URI segments: [0]=api, [1]=services, [2]=slug
        // Route group 'api' means segments inside group: [0]=services, [1]=slug
        if ($slug === null) {
            // Try to get from route parameter first (CodeIgniter 4 should pass it automatically)
            // If not, extract from URI segments
            $segments = $this->request->getUri()->getSegments();
            // Find 'services' in segments and get next one
            $servicesIndex = array_search('services', $segments);
            if ($servicesIndex !== false && isset($segments[$servicesIndex + 1])) {
                $slug = $segments[$servicesIndex + 1];
            } else {
                // Fallback: try segment 2 (api/services/slug) or segment 1 (if api is stripped)
                $slug = $this->request->getUri()->getSegment(2) ?? $this->request->getUri()->getSegment(1);
            }
        }
        
        if (!$slug || $slug === 'quote-options' || $slug === 'categories') {
            // These are handled by specific routes, should not reach here
            return $this->failNotFound('Service not found');
        }
        
        if (!$slug) {
            return $this->fail('Service slug is required', 400);
        }
        
        try {
            $serviceModel = new Service();
            $service = $serviceModel->where('slug', $slug)->where('isActive', true)->first();
            
            if (!$service) {
                // Matching Node.js: NotFoundException with message "Service not found: {slug}"
                return $this->failNotFound("Service not found: {$slug}");
            }
            
            // Node.js returns: { success: true, data: service }
            return $this->respond([
                'success' => true,
                'data' => $service
            ]);
        } catch (\Exception $e) {
            // Matching Node.js: BadRequestException on error
            return $this->fail('Failed to fetch service', 400);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/testimonials",
     *     tags={"Public"},
     *     summary="Get testimonials with filtering",
     *     @OA\Response(
     *         response=200,
     *         description="Testimonials retrieved successfully"
     *     )
     * )
     */
    public function getTestimonials()
    {
        $testimonialModel = new Testimonial();
        $testimonials = $testimonialModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
        
        return $this->respond([
            'success' => true,
            'data' => $testimonials
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/footer",
     *     tags={"Public"},
     *     summary="Get complete footer content",
     *     @OA\Response(
     *         response=200,
     *         description="Footer content retrieved successfully"
     *     )
     * )
     */
    public function getFooter()
    {
        try {
            $footerSectionModel = new FooterSection();
            $contactInfoModel = new ContactInfo();
            $socialLinkModel = new SocialLink();
            
            // Get footer sections with links
            $sections = $footerSectionModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
            
            // Get contact info
            $contactInfo = $contactInfoModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
            
            // Get social links
            $socialLinks = $socialLinkModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
            
            // For each section, get its links
            $footerLinkModel = new FooterLink();
            foreach ($sections as &$section) {
                $section['links'] = $footerLinkModel
                    ->where('footerSectionId', $section['id'])
                    ->where('isActive', true)
                    ->orderBy('position', 'ASC')
                    ->findAll();
            }
            
            return $this->respond([
                'success' => true,
                'data' => [
                    'sections' => $sections ?: [],
                    'contactInfo' => $contactInfo ?: [],
                    'socialLinks' => $socialLinks ?: []
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => [
                    'sections' => [],
                    'contactInfo' => [],
                    'socialLinks' => []
                ]
            ]);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/contact-info",
     *     tags={"Public"},
     *     summary="Get contact information",
     *     @OA\Response(
     *         response=200,
     *         description="Contact info retrieved successfully"
     *     )
     * )
     */
    public function getContactInfo()
    {
        try {
            $contactModel = new ContactInfo();
            $contacts = $contactModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
            
            return $this->respond([
                'success' => true,
                'data' => $contacts ?: []
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/social-links",
     *     tags={"Public"},
     *     summary="Get social media links",
     *     @OA\Response(
     *         response=200,
     *         description="Social links retrieved successfully"
     *     )
     * )
     */
    public function getSocialLinks()
    {
        try {
            $socialModel = new SocialLink();
            $links = $socialModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
            
            return $this->respond([
                'success' => true,
                'data' => $links ?: []
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/page-content/{pageKey}",
     *     tags={"Public"},
     *     summary="Get page content",
     *     @OA\Parameter(name="pageKey", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Page content retrieved successfully"
     *     )
     * )
     */
    public function getPageContent($pageKey = null)
    {
        // Get pageKey from route parameter or URI segment
        // Route: /api/page-content/{pageKey}
        // Segments: [0]=api, [1]=page-content, [2]=pageKey
        if ($pageKey === null) {
            $pageKey = $this->request->getUri()->getSegment(3);
        }
        
        if (!$pageKey) {
            return $this->fail('Page key is required', 400);
        }
        
        try {
            // Database column is pageKey (camelCase) - use Model class which handles it correctly
            $pageModel = new PageContent();
            $page = $pageModel->where('pageKey', $pageKey)->where('isActive', true)->first();
            
            if (!$page) {
                return $this->failNotFound('Page not found');
            }
            
            return $this->respond([
                'success' => true,
                'data' => $page
            ]);
        } catch (\Exception $e) {
            return $this->failNotFound('Page not found');
        }
    }

    /**
     * @OA\Get(
     *     path="/api/call-to-actions/{pageKey}",
     *     tags={"Public"},
     *     summary="Get call-to-actions for specific page",
     *     @OA\Parameter(name="pageKey", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="CTAs retrieved successfully"
     *     )
     * )
     */
    public function getCallToActions($pageKey = null)
    {
        // Get pageKey from route parameter or URI segment
        if ($pageKey === null) {
            $pageKey = $this->request->getUri()->getSegment(2);
        }
        
        if (!$pageKey) {
            return $this->fail('Page key is required', 400);
        }
        
        try {
            // Match Node.js implementation: findMany with where { pageKey, isActive: true }, orderBy { position: 'asc' }
            // Use DB builder directly to preserve exact camelCase column names as in database
            $db = \Config\Database::connect();
            $ctas = $db->table('call_to_actions')
                ->where('pageKey', $pageKey)
                ->where('isActive', true)
                ->orderBy('position', 'ASC')
                ->get()
                ->getResultArray();
            
            return $this->respond([
                'success' => true,
                'data' => $ctas ?: []
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/section-content/{sectionKey}",
     *     tags={"Public"},
     *     summary="Get section content by key",
     *     @OA\Parameter(name="sectionKey", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Section content retrieved successfully"
     *     )
     * )
     */
    public function getSectionContent($sectionKey = null)
    {
        // Get sectionKey from route parameter or URI segment
        // Route: /api/section-content/:sectionKey
        // Full URI segments: [0]=api, [1]=section-content, [2]=sectionKey
        // Route group 'api' means segments inside group: [0]=section-content, [1]=sectionKey
        if ($sectionKey === null) {
            // Try to get from route parameter first (CodeIgniter 4 should pass it automatically)
            // If not, extract from URI segments
            $segments = $this->request->getUri()->getSegments();
            // Find 'section-content' in segments and get next one
            $sectionIndex = array_search('section-content', $segments);
            if ($sectionIndex !== false && isset($segments[$sectionIndex + 1])) {
                $sectionKey = $segments[$sectionIndex + 1];
            } else {
                // Fallback: try segment 2 (api/section-content/key) or segment 1 (if api is stripped)
                $sectionKey = $this->request->getUri()->getSegment(2) ?? $this->request->getUri()->getSegment(1);
            }
        }
        
        if (!$sectionKey) {
            return $this->fail('Section key is required', 400);
        }
        
        try {
            $sectionModel = new SectionContent();
            $section = $sectionModel->where('sectionKey', $sectionKey)->where('isActive', true)->first();
            
            if (!$section) {
                // Matching Node.js: NotFoundException with message "Section content not found for key: {sectionKey}"
                return $this->failNotFound("Section content not found for key: {$sectionKey}");
            }
            
            // Node.js returns: { success: true, data: sectionContent }
            return $this->respond([
                'success' => true,
                'data' => $section
            ]);
        } catch (\Exception $e) {
            // Matching Node.js: BadRequestException on error
            return $this->fail('Failed to fetch section content', 400);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/clients",
     *     tags={"Public"},
     *     summary="Get all active clients",
     *     @OA\Response(
     *         response=200,
     *         description="Clients retrieved successfully"
     *     )
     * )
     */
    public function getClients()
    {
        try {
            $clientModel = new Client();
            $clients = $clientModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();
            
            // Node.js returns { success: true, data: { clients } } (matching Node.js)
            return $this->respond([
                'success' => true,
                'data' => ['clients' => $clients ?: []]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch clients',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/section-contents",
     *     tags={"Public"},
     *     summary="Get all section contents",
     *     @OA\Response(
     *         response=200,
     *         description="Section contents retrieved successfully"
     *     )
     * )
     */
    public function getSectionContents()
    {
        try {
            $sectionModel = new SectionContent();
            $sections = $sectionModel->where('isActive', true)->findAll();
            
            // Build section data object keyed by sectionKey (matching Node.js)
            $sectionData = [];
            foreach ($sections as $section) {
                $sectionData[$section['sectionKey']] = $section;
            }
            
            return $this->respond([
                'success' => true,
                'data' => $sectionData
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch section contents',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/contact",
     *     tags={"Public"},
     *     summary="Submit contact form",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "phone", "service", "message"},
     *             @OA\Property(property="name", type="string", description="Contact name", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", description="Contact email", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", description="Contact phone number", example="+1234567890"),
     *             @OA\Property(property="company", type="string", description="Company name (optional)", example="Acme Corp"),
     *             @OA\Property(property="service", type="string", description="Service interested in", example="Payroll Management"),
     *             @OA\Property(property="message", type="string", description="Message", example="I would like to know more about your services..."),
     *             @OA\Property(property="source", type="string", description="Source of contact (optional)", example="website")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Contact submitted successfully"
     *     )
     * )
     */
    public function submitContact()
    {
        try {
            $data = $this->request->getJSON(true);
            $submissionModel = new ContactSubmission();
            
            $data['id'] = uniqid('contact_');
            $data['status'] = 'pending';
            $data['source'] = $data['source'] ?? 'website';
            
            // Convert metadata to JSON if it's an array/object
            if (isset($data['metadata']) && (is_array($data['metadata']) || is_object($data['metadata']))) {
                $data['metadata'] = json_encode($data['metadata']);
            }
            
            $submissionModel->insert($data);
            
            // Notify admin about new contact submission (non-blocking)
            try {
                $emailHelper = new EmailHelper();
                $emailHelper->notifyAdminNewContact($data);
            } catch (\Exception $e) {
                log_message('error', 'Failed to send admin notification: ' . $e->getMessage());
            }
            
            return $this->respondCreated([
                'success' => true,
                'message' => "Thank you! We'll contact you within 24 hours.",
                'data' => ['id' => $data['id']]
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to submit contact: ' . $e->getMessage(), 500);
        }
    }

}
