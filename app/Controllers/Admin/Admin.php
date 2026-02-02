<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\NavItem;
use App\Models\ThemeConfig;
use App\Models\HeroDashboard;
use App\Models\HeroContent;
use App\Models\Client;
use App\Models\SectionContent;
use App\Models\Service;
use App\Models\Testimonial;
use App\Models\Stat;
use App\Models\FooterSection;
use App\Models\FooterLink;
use App\Models\ContactInfo;
use App\Models\SocialLink;
use App\Models\PageContent;
use App\Models\CallToAction;
use App\Models\ContactSubmission;
use App\Models\FileUpload;
use App\Models\DropdownData;
use App\Models\DropdownItem;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Admin",
 *     description="Admin CMS endpoints"
 * )
 */
class Admin extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/admin/upload",
     *     tags={"Admin"},
     *     summary="Upload file for admin use",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="File uploaded successfully")
     * )
     */
    public function uploadFile()
    {
        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->fail('No file provided', 400);
        }

        $uploadPath = WRITEPATH . 'uploads/admin/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $newName = $file->getRandomName();
        $file->move($uploadPath, $newName);

        $fileModel = new FileUpload();
        $fileData = [
            'id' => uniqid('file_'),
            'filename' => $newName,
            'originalName' => $file->getClientName(),
            'mimetype' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'path' => $uploadPath . $newName,
            'url' => '/uploads/admin/' . $newName,
            'fileType' => 'admin'
        ];
        $fileModel->insert($fileData);

        return $this->respond([
            'success' => true,
            'data' => $fileData
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/uploads/{id}",
     *     tags={"Admin"},
     *     summary="Delete uploaded file",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="File deleted successfully")
     * )
     */
    public function deleteUpload($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(4);
        }

        if (!$id) {
            return $this->fail('File ID is required', 400);
        }

        $fileModel = new FileUpload();
        $file = $fileModel->find($id);

        if (!$file) {
            return $this->failNotFound('File not found');
        }

        if (file_exists($file['path'])) {
            unlink($file['path']);
        }

        $fileModel->delete($id);

        return $this->respond([
            'success' => true,
            'message' => 'File deleted successfully'
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/admin/navigation",
     *     tags={"Admin"},
     *     summary="Update website navigation structure",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Navigation updated successfully")
     * )
     */
    public function updateNavigation()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);

            $db->transStart();

            $navModel = new NavItem();
            $dropdownDataModel = new DropdownData();
            $dropdownItemModel = new DropdownItem();

            // Update nav items
            if (isset($data['navItems']) && is_array($data['navItems'])) {
                foreach ($data['navItems'] as $item) {
                    $navItemData = [
                        'name' => $item['name'],
                        'key' => $item['key'],
                        'href' => $item['href'] ?? null,
                        'position' => $item['position'],
                        'hasDropdown' => $item['hasDropdown'] ?? false,
                        'isActive' => isset($item['isActive']) ? $item['isActive'] : true
                    ];

                    $existing = $navModel->where('key', $item['key'])->first();
                    if ($existing) {
                        $navModel->update($existing['id'], $navItemData);
                    } else {
                        // Generate ID for new nav item
                        $navItemData['id'] = uniqid('nav_');
                        $navModel->insert($navItemData);
                    }
                }
            }

            // Update dropdown data
            if (isset($data['dropdownData']) && is_array($data['dropdownData'])) {
                foreach ($data['dropdownData'] as $key => $dropdownInfo) {
                    $navItem = $navModel->where('key', $key)->first();

                    if ($navItem && $dropdownInfo) {
                        $existingDropdown = $dropdownDataModel->where('navItemId', $navItem['id'])->first();

                        if ($existingDropdown) {
                            // Update existing dropdown
                            $dropdownDataModel->update($existingDropdown['id'], [
                                'title' => $dropdownInfo['title'] ?? 'Dropdown Title'
                            ]);
                            $dropdownId = $existingDropdown['id'];

                            // Delete old dropdown items
                            $dropdownItemModel->where('dropdownDataId', $dropdownId)->delete();
                        } else {
                            // Create new dropdown with generated ID
                            $newDropdownId = uniqid('dropdown_');
                            $dropdownDataModel->insert([
                                'id' => $newDropdownId,
                                'navItemId' => $navItem['id'],
                                'title' => $dropdownInfo['title'] ?? 'Dropdown Title'
                            ]);
                            $dropdownId = $newDropdownId;
                        }

                        // Create dropdown items
                        if (isset($dropdownInfo['items']) && is_array($dropdownInfo['items'])) {
                            foreach ($dropdownInfo['items'] as $index => $item) {
                                $dropdownItemModel->insert([
                                    'id' => uniqid('dditem_'),
                                    'name' => $item['name'],
                                    'href' => $item['href'],
                                    'description' => $item['description'] ?? '',
                                    'features' => isset($item['features']) && is_array($item['features'])
                                        ? json_encode($item['features'])
                                        : json_encode([]),
                                    'position' => $index + 1,
                                    'isActive' => isset($item['isActive']) ? $item['isActive'] : true,
                                    'dropdownDataId' => $dropdownId
                                ]);
                            }
                        }
                    }
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update navigation', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Navigation updated successfully'
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Navigation update failed: ' . $e->getMessage());
            return $this->fail('Failed to update navigation', 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/navigation/{id}",
     *     tags={"Admin"},
     *     summary="Delete a navigation item",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Navigation item deleted successfully")
     * )
     */
    public function deleteNavItem($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(4);
        }

        if (!$id) {
            return $this->fail('Navigation item ID is required', 400);
        }

        try {
            $navModel = new NavItem();
            $navItem = $navModel->find($id);

            if (!$navItem) {
                return $this->failNotFound('Navigation item not found');
            }

            $navModel->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Navigation item deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to delete navigation item', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/theme",
     *     tags={"Admin"},
     *     summary="Update website theme configuration",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Theme updated successfully")
     * )
     */
    public function updateTheme()
    {
        try {
            $data = $this->request->getJSON(true);
            $themeModel = new ThemeConfig();

            $theme = $themeModel->where('isActive', true)->first();
            if ($theme) {
                $themeModel->update($theme['id'], $data);
            } else {
                $data['id'] = uniqid('theme_');
                $data['isActive'] = true;
                $themeModel->insert($data);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Theme updated successfully'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Theme update failed: ' . $e->getMessage());
            return $this->fail('Failed to update theme', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/hero-dashboards",
     *     tags={"Admin"},
     *     summary="Update hero dashboard slides",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Hero dashboards updated successfully")
     * )
     */
    public function updateHeroDashboards()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);

            $db->transStart();

            $dashboardModel = new HeroDashboard();

            // Delete all existing dashboards
            $dashboardModel->where('1=1')->delete();

            // Insert new dashboards
            if (isset($data['dashboards']) && is_array($data['dashboards'])) {
                foreach ($data['dashboards'] as $index => $dashboard) {
                    $dashboardData = [
                        'id' => uniqid('herodash_'),
                        'title' => $dashboard['title'],
                        'description' => $dashboard['description'] ?? null,
                        'type' => $dashboard['type'],
                        'position' => $index + 1,
                        'isActive' => true
                    ];

                    if ($dashboard['type'] === 'content') {
                        $dashboardData['stats'] = isset($dashboard['stats']) && is_array($dashboard['stats'])
                            ? json_encode($dashboard['stats'])
                            : json_encode([]);
                        $dashboardData['features'] = isset($dashboard['features']) && is_array($dashboard['features'])
                            ? json_encode($dashboard['features'])
                            : json_encode([]);
                        $dashboardData['imageUrl'] = null;
                    } elseif ($dashboard['type'] === 'image') {
                        $dashboardData['stats'] = null;
                        $dashboardData['features'] = null;
                        $dashboardData['imageUrl'] = $dashboard['imageUrl'] ?? null;
                    }

                    $dashboardModel->insert($dashboardData);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update hero dashboards', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Hero dashboards updated successfully'
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Hero dashboards update failed: ' . $e->getMessage());
            return $this->fail('Failed to update hero dashboards', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/hero-content",
     *     tags={"Admin"},
     *     summary="Update hero section text content",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Hero content updated successfully")
     * )
     */
    public function updateHeroContent()
    {
        try {
            $data = $this->request->getJSON(true);
            $contentModel = new HeroContent();

            $updateData = [
                'trustBadge' => $data['trustBadge'] ?? 'Trusted by 5,000+ Companies',
                'mainHeading' => $data['mainHeading'] ?? 'Transform Your',
                'subHeading' => $data['subHeading'] ?? 'HR Operations',
                'tagline' => $data['tagline'] ?? 'with AI-Powered Solutions',
                'description' => $data['description'] ?? 'Streamline payroll, optimize talent management.',
                'trustPoints' => isset($data['trustPoints']) && is_array($data['trustPoints'])
                    ? json_encode($data['trustPoints'])
                    : json_encode(['No Setup Fees', '24/7 Support', 'GDPR Compliant']),
                'primaryCtaText' => $data['primaryCtaText'] ?? 'Start Free Trial',
                'secondaryCtaText' => $data['secondaryCtaText'] ?? 'Schedule Demo',
                'primaryCtaLink' => $data['primaryCtaLink'] ?? null,
                'secondaryCtaLink' => $data['secondaryCtaLink'] ?? null,
                'phoneNumber' => $data['phoneNumber'] ?? '0733769149',
                'chatWidgetUrl' => $data['chatWidgetUrl'] ?? 'https://rag-chat-widget.vercel.app/'
            ];

            $content = $contentModel->where('isActive', true)->first();
            if ($content) {
                $contentModel->update($content['id'], $updateData);
            } else {
                $updateData['id'] = uniqid('hero_content_');
                $updateData['isActive'] = true;
                $contentModel->insert($updateData);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Hero content updated successfully'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Hero content update failed: ' . $e->getMessage());
            return $this->fail('Failed to update hero content', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/clients",
     *     tags={"Admin"},
     *     summary="Get all clients for admin",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Clients retrieved successfully")
     * )
     */
    public function getClients()
    {
        try {
            $clientModel = new Client();
            $clients = $clientModel->orderBy('position', 'ASC')->findAll();

            return $this->respond([
                'success' => true,
                'data' => $clients ?: []
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to fetch clients', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/clients",
     *     tags={"Admin"},
     *     summary="Update all clients",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Clients updated successfully")
     * )
     */
    public function updateClients()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);

            $db->transStart();

            $clientModel = new Client();

            // Get existing client IDs
            $existingClients = $clientModel->select('id')->findAll();
            $existingIds = array_column($existingClients, 'id');

            // Get incoming IDs (exclude temp IDs)
            $incomingIds = [];
            if (isset($data['clients']) && is_array($data['clients'])) {
                foreach ($data['clients'] as $client) {
                    if (isset($client['id']) && !str_starts_with($client['id'], 'temp-')) {
                        $incomingIds[] = $client['id'];
                    }
                }
            }

            // Delete clients not in incoming data
            $idsToDelete = array_diff($existingIds, $incomingIds);
            if (!empty($idsToDelete)) {
                $clientModel->whereIn('id', $idsToDelete)->delete();
            }

            $createdCount = 0;
            $updatedCount = 0;

            // Upsert clients
            if (isset($data['clients']) && is_array($data['clients'])) {
                foreach ($data['clients'] as $client) {
                    $clientData = [
                        'name' => trim($client['name']),
                        'logo' => trim($client['logo']),
                        'industry' => isset($client['industry']) ? trim($client['industry']) : null,
                        'website' => isset($client['website']) ? trim($client['website']) : null,
                        'position' => $client['position'] ?? 0,
                        'isActive' => isset($client['isActive']) ? $client['isActive'] : true
                    ];

                    $isUpdate = isset($client['id']) && !str_starts_with($client['id'], 'temp-');

                    if ($isUpdate) {
                        $existing = $clientModel->find($client['id']);
                        if ($existing) {
                            $clientModel->update($client['id'], $clientData);
                            $updatedCount++;
                        } else {
                            // ID doesn't exist in DB, create new with generated ID
                            $clientData['id'] = uniqid('client_');
                            $clientModel->insert($clientData);
                            $createdCount++;
                        }
                    } else {
                        // New client (temp ID or no ID), generate new ID
                        $clientData['id'] = uniqid('client_');
                        $clientModel->insert($clientData);
                        $createdCount++;
                    }
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update clients', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Clients updated successfully',
                'data' => [
                    'createdCount' => $createdCount,
                    'updatedCount' => $updatedCount,
                    'deletedCount' => count($idsToDelete)
                ]
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Clients update failed: ' . $e->getMessage());
            return $this->fail('Failed to update clients: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/clients/{id}",
     *     tags={"Admin"},
     *     summary="Delete a client",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Client deleted successfully")
     * )
     */
    public function deleteClient($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(4);
        }

        if (!$id) {
            return $this->fail('Client ID is required', 400);
        }

        try {
            $clientModel = new Client();
            $client = $clientModel->find($id);

            if (!$client) {
                return $this->failNotFound('Client not found');
            }

            $clientModel->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Client deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to delete client', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/section-contents",
     *     tags={"Admin"},
     *     summary="Get all section contents for admin",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Section contents retrieved successfully")
     * )
     */
    public function getSectionContents()
    {
        try {
            $sectionModel = new SectionContent();
            $sections = $sectionModel->where('isActive', true)->findAll();

            $sectionData = [];
            foreach ($sections as $section) {
                $sectionData[$section['sectionKey']] = $section;
            }

            return $this->respond([
                'success' => true,
                'data' => $sectionData
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to fetch section contents', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/section-content",
     *     tags={"Admin"},
     *     summary="Update section content",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Section content updated successfully")
     * )
     */
    public function updateSectionContent()
    {
        try {
            $data = $this->request->getJSON(true);
            $sectionModel = new SectionContent();

            $updateData = [
                'title' => $data['title'] ?? null,
                'subtitle' => $data['subtitle'] ?? null,
                'description' => $data['description'] ?? null,
                'isActive' => isset($data['isActive']) ? $data['isActive'] : true
            ];

            $existing = $sectionModel->where('sectionKey', $data['sectionKey'])->first();
            if ($existing) {
                $sectionModel->update($existing['id'], $updateData);
            } else {
                $updateData['sectionKey'] = $data['sectionKey'];
                $sectionModel->insert($updateData);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Section content updated successfully'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Section content update failed: ' . $e->getMessage());
            return $this->fail('Failed to update section content', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/services",
     *     tags={"Admin"},
     *     summary="Update all services",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Services updated successfully")
     * )
     */
    public function updateServices()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);
            if (!is_array($data)) {
                return $this->fail('Invalid request body', 400);
            }

            $db->transStart();

            $serviceModel = new Service();

            // Delete all existing services
            $serviceModel->where('1=1')->delete();

            // Insert new services
            if (isset($data['services']) && is_array($data['services'])) {
                foreach ($data['services'] as $service) {
                    $id = isset($service['id']) && $service['id'] && !str_starts_with((string) $service['id'], 'temp-')
                        ? (string) $service['id']
                        : 'service_' . uniqid();

                    $serviceData = [
                        'id' => $id,
                        'title' => $service['title'],
                        'slug' => $service['slug'],
                        'description' => $service['description'],
                        'shortDesc' => $service['shortDesc'] ?? null,
                        'icon' => $service['icon'],
                        'color' => $service['color'],
                        'category' => $service['category'] ?? null,
                        'features' => isset($service['features']) && is_array($service['features'])
                            ? json_encode($service['features'])
                            : json_encode([]),
                        'benefits' => isset($service['benefits']) && is_array($service['benefits'])
                            ? json_encode($service['benefits'])
                            : json_encode([]),
                        'processSteps' => isset($service['processSteps']) && is_array($service['processSteps'])
                            ? json_encode($service['processSteps'])
                            : json_encode([]),
                        'complianceItems' => isset($service['complianceItems']) && is_array($service['complianceItems'])
                            ? json_encode($service['complianceItems'])
                            : json_encode([]),
                        'imageUrl' => $service['imageUrl'] ?? null,
                        'heroImageUrl' => $service['heroImageUrl'] ?? null,
                        'processImageUrl' => $service['processImageUrl'] ?? null,
                        'complianceImageUrl' => $service['complianceImageUrl'] ?? null,
                        'onQuote' => isset($service['onQuote']) ? $service['onQuote'] : true,
                        'hasProcess' => $service['hasProcess'] ?? false,
                        'hasCompliance' => $service['hasCompliance'] ?? false,
                        'isActive' => isset($service['isActive']) ? $service['isActive'] : true,
                        'isFeatured' => $service['isFeatured'] ?? false,
                        'isPopular' => $service['isPopular'] ?? false,
                        'position' => $service['position'],
                        'price' => $service['price'] ?? null,
                        'buttonText' => $service['buttonText'] ?? 'Learn More',
                        'buttonLink' => $service['buttonLink'] ?? null,
                        'metadata' => isset($service['metadata']) && (is_array($service['metadata']) || is_object($service['metadata']))
                            ? json_encode($service['metadata'])
                            : $service['metadata'] ?? null
                    ];

                    $serviceModel->insert($serviceData);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update services', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Services updated successfully'
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Services update failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->fail('Failed to update services', 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/services/{id}",
     *     tags={"Admin"},
     *     summary="Delete a service",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Service deleted successfully")
     * )
     */
    public function deleteService($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(4);
        }

        if (!$id) {
            return $this->fail('Service ID is required', 400);
        }

        try {
            $serviceModel = new Service();
            $service = $serviceModel->find($id);

            if (!$service) {
                return $this->failNotFound('Service not found');
            }

            $serviceModel->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Service deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to delete service', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/testimonials",
     *     tags={"Admin"},
     *     summary="Update testimonials (upsert only, no implicit deletes)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Testimonials updated successfully")
     * )
     */
    public function updateTestimonials()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);

            if (!isset($data['testimonials']) || !is_array($data['testimonials'])) {
                return $this->fail('testimonials array is required', 400);
            }

            $testimonialModel = new \App\Models\Testimonial();

            $db->transStart();

            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;

            foreach ($data['testimonials'] as $testimonial) {
                $name = trim((string) ($testimonial['name'] ?? ''));
                $role = trim((string) ($testimonial['role'] ?? ''));
                $company = trim((string) ($testimonial['company'] ?? ''));
                $content = trim((string) ($testimonial['content'] ?? ''));

                if ($name === '' || $role === '' || $company === '' || $content === '') {
                    $skippedCount++;
                    continue;
                }

                $isUpdate = isset($testimonial['id']) && $testimonial['id'] && !str_starts_with((string) $testimonial['id'], 'temp-');
                $id = $isUpdate ? (string) $testimonial['id'] : uniqid('test_', true);

                // Normalize results
                $resultsArray = [];
                if (isset($testimonial['results']) && is_array($testimonial['results'])) {
                    $resultsArray = array_values(array_filter(array_map(
                        fn($r) => trim((string) $r),
                        $testimonial['results']
                    ), fn($r) => $r !== ''));
                }

                $testimonialData = [
                    'id' => $id,
                    'name' => $name,
                    'role' => $role,      // NOT NULL in DB
                    'company' => $company,   // NOT NULL in DB
                    'content' => $content,
                    'rating' => isset($testimonial['rating']) ? (int) $testimonial['rating'] : 5,
                    'avatar' => trim((string) ($testimonial['avatar'] ?? '')),
                    'results' => json_encode($resultsArray),
                    'service' => isset($testimonial['service']) && trim((string) $testimonial['service']) !== '' ? trim((string) $testimonial['service']) : null,
                    'category' => isset($testimonial['category']) && trim((string) $testimonial['category']) !== '' ? trim((string) $testimonial['category']) : null,
                    'isActive' => isset($testimonial['isActive']) ? (bool) $testimonial['isActive'] : true,
                    'isFeatured' => isset($testimonial['isFeatured']) ? (bool) $testimonial['isFeatured'] : false,
                    'position' => isset($testimonial['position']) ? (int) $testimonial['position'] : 0,
                ];

                if ($isUpdate) {
                    $existing = $testimonialModel->find($id);
                    if ($existing) {
                        $testimonialModel->update($id, $testimonialData);
                        $updatedCount++;
                    } else {
                        // If client sent id but it doesn't exist, create it
                        $testimonialModel->insert($testimonialData);
                        $createdCount++;
                    }
                } else {
                    $testimonialModel->insert($testimonialData);
                    $createdCount++;
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update testimonials', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Testimonials updated successfully',
                'data' => [
                    'createdCount' => $createdCount,
                    'updatedCount' => $updatedCount,
                    'skippedCount' => $skippedCount,
                ],
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Testimonials update failed: ' . $e->getMessage());
            return $this->fail('Failed to update testimonials: ' . $e->getMessage(), 500);
        }
    }
    /**
     * @OA\Delete(
     *     path="/api/admin/testimonials/{id}",
     *     tags={"Admin"},
     *     summary="Delete a testimonial",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Testimonial deleted successfully")
     * )
     */
    public function deleteTestimonial($id = null)
    {
        if (!$id) {
            return $this->fail('Testimonial ID is required', 400);
        }

        try {
            $testimonialModel = new \App\Models\Testimonial();
            $testimonial = $testimonialModel->find($id);

            if (!$testimonial) {
                return $this->failNotFound('Testimonial not found');
            }

            $testimonialModel->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Testimonial deleted successfully',
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to delete testimonial: ' . $e->getMessage(), 500);
        }
    }
    /**
     * @OA\Put(
     *     path="/api/admin/stats",
     *     tags={"Admin"},
     *     summary="Update all stats",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Stats updated successfully")
     * )
     */
    public function updateStats()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);
            if (!is_array($data)) {
                return $this->fail('Invalid request body', 400);
            }

            $db->transStart();

            $statModel = new Stat();

            // Delete all existing stats
            $statModel->where('1=1')->delete();

            // Insert new stats
            if (isset($data['stats']) && is_array($data['stats'])) {
                foreach ($data['stats'] as $stat) {
                    $id = isset($stat['id']) && $stat['id'] && !str_starts_with((string) $stat['id'], 'temp-')
                        ? (string) $stat['id']
                        : 'stat_' . uniqid();

                    $statData = [
                        'id' => $id,
                        'number' => $stat['number'],
                        'label' => $stat['label'],
                        'icon' => $stat['icon'],
                        'color' => $stat['color'] ?? 'text-primary-600',
                        'isActive' => isset($stat['isActive']) ? $stat['isActive'] : true,
                        'position' => $stat['position']
                    ];

                    $statModel->insert($statData);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update stats', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Stats updated successfully'
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Stats update failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->fail('Failed to update stats', 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/stats/{id}",
     *     tags={"Admin"},
     *     summary="Delete a stat",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Stat deleted successfully")
     * )
     */
    public function deleteStat($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(4);
        }

        if (!$id) {
            return $this->fail('Stat ID is required', 400);
        }

        try {
            $statModel = new Stat();
            $stat = $statModel->find($id);

            if (!$stat) {
                return $this->failNotFound('Stat not found');
            }

            $statModel->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Stat deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to delete stat', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/footer",
     *     tags={"Admin"},
     *     summary="Update footer sections and links",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Footer updated successfully")
     * )
     */
    public function updateFooter()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);
            if (!is_array($data)) {
                return $this->fail('Invalid request body', 400);
            }

            $db->transStart();

            $footerSectionModel = new FooterSection();
            $footerLinkModel = new FooterLink();

            // Delete existing footer data (links first due to FK)
            $footerLinkModel->where('1=1')->delete();
            $footerSectionModel->where('1=1')->delete();

            // Create new footer sections and links
            if (isset($data['sections']) && is_array($data['sections'])) {
                foreach ($data['sections'] as $section) {
                    $sectionId = isset($section['id']) && $section['id'] && !str_starts_with((string) $section['id'], 'temp-')
                        ? (string) $section['id']
                        : 'footer_section_' . uniqid();

                    $sectionData = [
                        'id' => $sectionId,
                        'title' => $section['title'],
                        'position' => $section['position'],
                        'isActive' => isset($section['isActive']) ? $section['isActive'] : true
                    ];

                    $footerSectionModel->insert($sectionData);

                    // Create links for this section
                    if (isset($section['links']) && is_array($section['links'])) {
                        foreach ($section['links'] as $link) {
                            $linkId = isset($link['id']) && $link['id'] && !str_starts_with((string) $link['id'], 'temp-')
                                ? (string) $link['id']
                                : 'footer_link_' . uniqid();

                            $footerLinkModel->insert([
                                'id' => $linkId,
                                'footerSectionId' => $sectionId,
                                'name' => $link['name'],
                                'href' => $link['href'],
                                'position' => $link['position'],
                                'isActive' => isset($link['isActive']) ? $link['isActive'] : true
                            ]);
                        }
                    }
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update footer', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Footer updated successfully'
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Footer update failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->fail('Failed to update footer', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/footer",
     *     tags={"Admin"},
     *     summary="Get footer content for admin",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Footer content retrieved successfully")
     * )
     */
    public function getFooterContent()
    {
        try {
            $footerSectionModel = new FooterSection();
            $footerLinkModel = new FooterLink();

            $sections = $footerSectionModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();

            foreach ($sections as &$section) {
                $section['links'] = $footerLinkModel
                    ->where('footerSectionId', $section['id'])
                    ->where('isActive', true)
                    ->orderBy('position', 'ASC')
                    ->findAll();
            }

            return $this->respond([
                'success' => true,
                'data' => ['sections' => $sections]
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to fetch footer content', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/contact-info",
     *     tags={"Admin"},
     *     summary="Update contact information",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Contact info updated successfully")
     * )
     */
    public function updateContactInfo()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);
            if (!is_array($data)) {
                return $this->fail('Invalid request body', 400);
            }

            $db->transStart();

            $contactModel = new ContactInfo();

            // Delete all existing contact info
            $contactModel->where('1=1')->delete();

            // Insert new contact info
            if (isset($data['contactInfo']) && is_array($data['contactInfo'])) {
                foreach ($data['contactInfo'] as $info) {
                    $id = isset($info['id']) && $info['id'] && !str_starts_with((string) $info['id'], 'temp-')
                        ? (string) $info['id']
                        : 'contact_' . uniqid();

                    $contactModel->insert([
                        'id' => $id,
                        'type' => $info['type'],
                        'label' => $info['label'],
                        'value' => $info['value'],
                        'icon' => $info['icon'],
                        'position' => $info['position'],
                        'isActive' => isset($info['isActive']) ? $info['isActive'] : true
                    ]);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update contact info', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Contact info updated successfully'
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Contact info update failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->fail('Failed to update contact info', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/contact-info",
     *     tags={"Admin"},
     *     summary="Get contact info for admin",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Contact info retrieved successfully")
     * )
     */
    public function getContactInfo()
    {
        try {
            $contactModel = new ContactInfo();
            $contactInfo = $contactModel->where('isActive', true)->orderBy('position', 'ASC')->findAll();

            return $this->respond([
                'success' => true,
                'data' => $contactInfo ?: []
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to fetch contact info', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/social-links",
     *     tags={"Admin"},
     *     summary="Update social media links",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Social links updated successfully")
     * )
     */
    public function updateSocialLinks()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);
            if (!is_array($data)) {
                return $this->fail('Invalid request body', 400);
            }

            $db->transStart();

            $socialModel = new SocialLink();

            // Delete all existing social links
            $socialModel->where('1=1')->delete();

            // Insert new social links
            if (isset($data['socialLinks']) && is_array($data['socialLinks'])) {
                foreach ($data['socialLinks'] as $link) {
                    $id = isset($link['id']) && $link['id'] && !str_starts_with((string) $link['id'], 'temp-')
                        ? (string) $link['id']
                        : 'social_' . uniqid();

                    $socialModel->insert([
                        'id' => $id,
                        'name' => $link['name'],
                        'icon' => $link['icon'],
                        'href' => $link['href'],
                        'position' => $link['position'],
                        'isActive' => isset($link['isActive']) ? $link['isActive'] : true
                    ]);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update social links', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Social links updated successfully'
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Social links update failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->fail('Failed to update social links', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/social-links",
     *     tags={"Admin"},
     *     summary="Get social links for admin",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Social links retrieved successfully")
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
            return $this->fail('Failed to fetch social links', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/page-content",
     *     tags={"Admin"},
     *     summary="Update page-specific content",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Page content updated successfully")
     * )
     */
    public function updatePageContent()
    {
        try {
            $data = $this->request->getJSON(true);
            $pageModel = new PageContent();

            $updateData = [
                'title' => $data['title'] ?? null,
                'subtitle' => $data['subtitle'] ?? null,
                'description' => $data['description'] ?? null,
                'heroTitle' => $data['heroTitle'] ?? null,
                'heroSubtitle' => $data['heroSubtitle'] ?? null,
                'heroDescription' => $data['heroDescription'] ?? null,
                'heroImageUrl' => $data['heroImageUrl'] ?? null,
                'processImageUrl' => $data['processImageUrl'] ?? null,
                'complianceImageUrl' => $data['complianceImageUrl'] ?? null,
                'ctaText' => $data['ctaText'] ?? null,
                'ctaLink' => $data['ctaLink'] ?? null,
                'ctaSecondaryText' => $data['ctaSecondaryText'] ?? null,
                'ctaSecondaryLink' => $data['ctaSecondaryLink'] ?? null,
                'metadata' => isset($data['metadata']) && (is_array($data['metadata']) || is_object($data['metadata']))
                    ? json_encode($data['metadata'])
                    : $data['metadata'] ?? null,
                'isActive' => isset($data['isActive']) ? $data['isActive'] : true
            ];

            $existing = $pageModel->where('pageKey', $data['pageKey'])->first();
            if ($existing) {
                $result = $pageModel->update($existing['id'], $updateData);
                $pageContent = $pageModel->find($existing['id']);
            } else {
                $updateData['pageKey'] = $data['pageKey'];
                $pageModel->insert($updateData);
                $pageContent = $pageModel->where('pageKey', $data['pageKey'])->first();
            }

            return $this->respond([
                'success' => true,
                'message' => 'Page content updated successfully',
                'data' => $pageContent
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Page content update failed: ' . $e->getMessage());
            return $this->fail('Failed to update page content', 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/admin/page-content/batch",
     *     tags={"Admin"},
     *     summary="Get multiple page contents in a single request",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Page contents retrieved successfully")
     * )
     */
    public function getPageContentsBatch()
    {
        try {
            $data = $this->request->getJSON(true);
            $pageKeys = $data['pageKeys'] ?? [];

            if (empty($pageKeys) || !is_array($pageKeys)) {
                return $this->fail('pageKeys array is required', 400);
            }

            $pageModel = new PageContent();
            $pageContents = $pageModel->whereIn('pageKey', $pageKeys)->findAll();

            // Organize results by pageKey
            $result = [];
            foreach ($pageContents as $content) {
                $result[$content['pageKey']] = $content;
            }

            return $this->respond([
                'success' => true,
                'data' => $result,
                'message' => 'Fetched ' . count($pageContents) . ' page contents'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Batch page contents fetch failed: ' . $e->getMessage());
            return $this->fail('Failed to fetch page contents batch', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/page-content/{pageKey}",
     *     tags={"Admin"},
     *     summary="Get page content for admin",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Page content retrieved successfully")
     * )
     */
    public function getPageContent($pageKey = null)
    {
        if ($pageKey === null) {
            $pageKey = $this->request->getUri()->getSegment(4);
        }

        if (!$pageKey) {
            return $this->fail('Page key is required', 400);
        }

        try {
            $pageModel = new PageContent();
            $page = $pageModel->where('pageKey', $pageKey)->first();

            if (!$page) {
                return $this->failNotFound("Page content not found for key: {$pageKey}. Please ensure the database is properly seeded.");
            }

            return $this->respond([
                'success' => true,
                'data' => $page
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to fetch page content', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/call-to-actions",
     *     tags={"Admin"},
     *     summary="Update call-to-action sections",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="CTAs updated successfully")
     * )
     */
    public function updateCallToActions()
    {
        $db = \Config\Database::connect();

        try {
            $data = $this->request->getJSON(true);

            $db->transStart();

            $ctaModel = new CallToAction();

            // Get all unique page keys from the request
            $pageKeys = [];
            if (isset($data['ctas']) && is_array($data['ctas'])) {
                foreach ($data['ctas'] as $cta) {
                    if (isset($cta['pageKey'])) {
                        $pageKeys[] = $cta['pageKey'];
                    }
                }
            }
            $pageKeys = array_unique($pageKeys);

            // Delete existing CTAs for these pages
            foreach ($pageKeys as $pageKey) {
                $ctaModel->where('pageKey', $pageKey)->delete();
            }

            // Create new CTAs
            if (isset($data['ctas']) && is_array($data['ctas'])) {
                foreach ($data['ctas'] as $cta) {
                    $ctaModel->insert([
                        'id' => uniqid('cta_'),
                        'pageKey' => $cta['pageKey'],
                        'title' => $cta['title'],
                        'description' => $cta['description'] ?? null,
                        'primaryText' => $cta['primaryText'] ?? null,
                        'primaryLink' => $cta['primaryLink'] ?? null,
                        'secondaryText' => $cta['secondaryText'] ?? null,
                        'secondaryLink' => $cta['secondaryLink'] ?? null,
                        'bgColor' => $cta['bgColor'] ?? null,
                        'textColor' => $cta['textColor'] ?? null,
                        'position' => $cta['position'],
                        'isActive' => isset($cta['isActive']) ? $cta['isActive'] : true
                    ]);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Failed to update call-to-actions', 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Call-to-actions updated successfully'
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'CTAs update failed: ' . $e->getMessage());
            return $this->fail('Failed to update call-to-actions', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/call-to-actions/{pageKey}",
     *     tags={"Admin"},
     *     summary="Get CTAs for specific page",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="CTAs retrieved successfully")
     * )
     */
    public function getCallToActions($pageKey = null)
    {
        if ($pageKey === null) {
            $pageKey = $this->request->getUri()->getSegment(4);
        }

        if (!$pageKey) {
            return $this->fail('Page key is required', 400);
        }

        try {
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
            return $this->fail('Failed to fetch call-to-actions', 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/contact-submissions",
     *     tags={"Admin"},
     *     summary="Get all contact submissions",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Contact submissions retrieved successfully")
     * )
     */
    public function getContactSubmissions()
    {
        try {
            $submissionModel = new ContactSubmission();
            $submissions = $submissionModel->orderBy('createdAt', 'DESC')->findAll();

            return $this->respond([
                'success' => true,
                'data' => $submissions ?: []
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to fetch contact submissions', 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/contact-submissions/{id}/status",
     *     tags={"Admin"},
     *     summary="Update contact submission status",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Status updated successfully")
     * )
     */
    public function updateContactSubmissionStatus($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(4);
        }

        if (!$id) {
            return $this->fail('Contact submission ID is required', 400);
        }

        try {
            $data = $this->request->getJSON(true);
            $submissionModel = new ContactSubmission();

            $submission = $submissionModel->find($id);
            if (!$submission) {
                return $this->failNotFound('Contact submission not found');
            }

            $submissionModel->update($id, ['status' => $data['status']]);

            return $this->respond([
                'success' => true,
                'message' => 'Status updated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to update status', 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/contact-submissions/{id}",
     *     tags={"Admin"},
     *     summary="Delete contact submission",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Submission deleted successfully")
     * )
     */
    public function deleteContactSubmission($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(4);
        }

        if (!$id) {
            return $this->fail('Contact submission ID is required', 400);
        }

        try {
            $submissionModel = new ContactSubmission();
            $submission = $submissionModel->find($id);

            if (!$submission) {
                return $this->failNotFound('Contact submission not found');
            }

            $submissionModel->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Submission deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to delete submission', 500);
        }
    }
}
