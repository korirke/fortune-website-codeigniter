<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
// Set default controller
$routes->setDefaultController('App');
$routes->setDefaultMethod('index');

// ============================================
// Routes WITHOUT /api/ prefix
// ============================================

$routes->get('api-docs', 'Swagger\Swagger::index');
$routes->get('api-docs.json', 'Swagger\Swagger::json');
$routes->get('api-docs/debug', 'Swagger\Swagger::debug');
$routes->get('swagger.json', 'Swagger\Swagger::json');

// Note: Static files at /uploads/* are served directly from public/uploads directory
// via .htaccess rules, no route definition needed

// ============================================
// Routes WITH /api/ prefix
// ============================================

// API Root and Health Routes
$routes->group('api', [], function ($routes) {
    $routes->get('/', 'App\Controllers\App::index');
    $routes->get('health', 'App\Controllers\App::health');
});

// ============================================
// JOBS ROUTES
// ============================================
$routes->group('api/jobs', ['namespace' => 'App\Controllers\Jobs'], function ($routes) {

    // ===== PUBLIC ROUTES (no auth) =====
    $routes->get('search', 'Jobs::searchJobs');
    $routes->get('categories', 'Jobs::getCategories');
    $routes->get('newest', 'Jobs::getNewestJobs');

    // ===== AUTH ROUTES (specific,be above generic) =====
    $routes->get('employer/my-jobs', 'Jobs::getMyJobs', ['filter' => 'auth']);

    //  Management fetch for edit screen (DRAFT/PENDING/REJECTED)
    $routes->get('manage/(:segment)', 'Jobs::getManageJobById/$1', ['filter' => 'auth']);

    // Admin routes
    $routes->get('admin/moderation-queue', 'Jobs::getModerationQueue', ['filter' => 'auth']);
    $routes->get('admin/all', 'Jobs::getAllJobsAdmin', ['filter' => 'auth']);
    $routes->patch('admin/bulk-status', 'Jobs::bulkUpdateStatus', ['filter' => 'auth']);

    // Create
    $routes->post('', 'Jobs::createJob', ['filter' => 'auth']);

    // Public - get by ID OR slug
    $routes->get('(:segment)', 'Jobs::getJobById/$1');

    // Authenticated update/delete
    $routes->put('(:segment)', 'Jobs::updateJob/$1', ['filter' => 'auth']);
    $routes->delete('(:segment)', 'Jobs::deleteJob/$1', ['filter' => 'auth']);

    // Nested
    $routes->patch('(:segment)/moderate', 'Jobs::moderateJob/$1', ['filter' => 'auth']);
    $routes->patch('(:segment)/status/(:segment)', 'Jobs::changeJobStatus/$1/$2', ['filter' => 'auth']);
});

// Public Routes 
$routes->group('api', ['namespace' => 'App\Controllers\Public'], function ($routes) {
    $routes->get('navigation', 'PublicController::getNavigation');
    $routes->get('hero', 'Hero::getHeroData');
    $routes->get('stats', 'PublicController::getStats');
    $routes->get('services', 'PublicController::getServices');
    // Specific routes must come before generic (:segment) route
    $routes->get('services/quote-options', 'PublicController::getQuoteServices', ['priority' => 1]);
    $routes->get('services/categories', 'PublicController::getServiceCategories', ['priority' => 1]);
    $routes->get('services/(:any)', 'PublicController::getServiceBySlug');
    $routes->get('testimonials', 'PublicController::getTestimonials');
    $routes->get('footer', 'PublicController::getFooter');
    $routes->get('contact-info', 'PublicController::getContactInfo');
    $routes->get('social-links', 'PublicController::getSocialLinks');
    $routes->get('page-content/(:segment)', 'PublicController::getPageContent');
    $routes->get('call-to-actions/(:segment)', 'PublicController::getCallToActions');
    $routes->get('section-content/(:any)', 'PublicController::getSectionContent');
    $routes->get('clients', 'PublicController::getClients');
    $routes->get('section-contents', 'PublicController::getSectionContents');
    $routes->post('contact', 'PublicController::submitContact');
    $routes->get('search', 'Search::search');
    $routes->get('search/suggestions', 'Search::getSuggestions');
    $routes->get('search/popular', 'Search::getPopularSearches');
    $routes->get('faq', 'PublicFaq::getAllFaqs');
    $routes->get('faq/categories', 'PublicFaq::getAllCategories');
    $routes->get('faq/categories/(:segment)', 'PublicFaq::getCategoryByKey');
    $routes->get('faq/stats', 'PublicFaq::getFaqStats');
    $routes->get('faq/(:segment)', 'PublicFaq::getFaqById');
    $routes->post('faq/(:segment)/helpful', 'PublicFaq::markAsHelpful');
    $routes->get('about', 'About::getAboutContent');
    $routes->get('about/sections', 'About::getAllSections');
    $routes->get('about/sections/(:segment)', 'About::getSection');
    $routes->get('companies', 'Companies::getPublicCompanies');
    $routes->get('companies/(:segment)', 'Companies::getCompanyBySlug');
});

// Authentication Routes 
$routes->group('api/auth', ['namespace' => 'App\Controllers\Auth'], function ($routes) {
    $routes->post('register', 'Auth::register');
    $routes->post('login', 'Auth::login');
    $routes->post('verify-email', 'Auth::verifyEmail');
    $routes->post('forgot-password', 'Auth::forgotPassword');
    $routes->post('reset-password', 'Auth::resetPassword');
    $routes->get('me', 'Auth::getMe', ['filter' => 'auth']);
});

// Candidate Routes 
$routes->group('api/candidate', ['namespace' => 'App\Controllers\Candidate', 'filter' => 'auth'], function ($routes) {
    $routes->get('profile', 'Candidate::getProfile');
    $routes->put('profile', 'Candidate::updateProfile');
    $routes->post('resume/upload', 'Candidate::uploadResume');
    $routes->post('skills', 'Candidate::addSkill');
    $routes->delete('skills/(:segment)', 'Candidate::removeSkill/$1');
    $routes->get('skills/available', 'Candidate::getAvailableSkills');
    $routes->post('domains', 'Candidate::addDomain');
    $routes->delete('domains/(:segment)', 'Candidate::removeDomain/$1');
    $routes->get('domains/available', 'Candidate::getAvailableDomains');
    $routes->post('education', 'Candidate::addEducation');
    $routes->put('education/(:segment)', 'Candidate::updateEducation/$1');
    $routes->delete('education/(:segment)', 'Candidate::deleteEducation/$1');
    $routes->post('experience', 'Candidate::addExperience');
    $routes->put('experience/(:segment)', 'Candidate::updateExperience/$1');
    $routes->delete('experience/(:segment)', 'Candidate::deleteExperience/$1');
    $routes->post('applications/apply', 'Candidate::applyToJob');
    $routes->get('applications', 'Candidate::getApplications');
    $routes->get('applications/(:segment)', 'Candidate::getApplication/$1');
    $routes->post('applications/(:segment)/withdraw', 'Candidate::withdrawApplication/$1');
});

// Applications Routes
$routes->group('api/applications', ['namespace' => 'App\Controllers\Applications', 'filter' => 'auth'], function ($routes) {
    $routes->get('job/(:segment)', 'Applications::getApplicationsForJob');
    $routes->get('filter', 'Applications::filterApplications');
    $routes->get('stats/dashboard', 'Applications::getDashboardStats');
    $routes->get('export/csv', 'Applications::exportApplications');
    $routes->get('my-applications', 'Applications::getMyApplications');
    $routes->get('candidate/(:segment)/profile', 'Applications::getCandidateProfile');
    $routes->get('(:segment)', 'Applications::getApplicationDetails');
    $routes->put('(:segment)/status', 'Applications::updateApplicationStatus');
    $routes->post('(:segment)/notes', 'Applications::addInternalNote');
    $routes->post('bulk-update', 'Applications::bulkUpdateStatus');
});

// Companies Routes 
$routes->group('api/companies', ['namespace' => 'App\Controllers\Companies'], function ($routes) {
    $routes->post('setup', 'Companies::setupCompany', ['filter' => 'auth']);
    $routes->get('me/profile', 'Companies::getMyCompany', ['filter' => 'auth']);
    $routes->put('me', 'Companies::updateMyCompany', ['filter' => 'auth']);
    $routes->get('me/stats', 'Companies::getMyCompanyStats', ['filter' => 'auth']);
    $routes->get('admin/all', 'Companies::getAllCompaniesAdmin', ['filter' => 'auth']);
    $routes->get('admin/pending', 'Companies::getPendingCompanies', ['filter' => 'auth']);
    $routes->get('admin/(:segment)', 'Companies::getCompanyById/$1', ['filter' => 'auth']);
    $routes->put('admin/(:segment)', 'Companies::forceUpdateCompany/$1', ['filter' => 'auth']);
    $routes->patch('admin/(:segment)', 'Companies::forceUpdateCompany/$1', ['filter' => 'auth']);
    $routes->patch('admin/(:segment)/verify', 'Companies::verifyCompany/$1', ['filter' => 'auth']);
    $routes->patch('admin/(:segment)/suspend', 'Companies::suspendCompany/$1', ['filter' => 'auth']);
});

// Contact Routes 
$routes->group('api/contact', ['namespace' => 'App\Controllers\Contact'], function ($routes) {
    $routes->post('submit', 'Contact::submitContact');
    $routes->get('', 'Contact::getAllInquiries', ['filter' => 'auth']);
    $routes->get('stats', 'Contact::getStats', ['filter' => 'auth']);
    $routes->get('(:segment)', 'Contact::getInquiryById', ['filter' => 'auth']);
    $routes->put('(:segment)', 'Contact::updateInquiry', ['filter' => 'auth']);
    $routes->delete('(:segment)', 'Contact::deleteInquiry', ['filter' => 'auth']);
});

// FAQ Admin Routes 
$routes->group('api/faq', ['namespace' => 'App\Controllers\Faq'], function ($routes) {
    $routes->post('', 'Faq::createFaq', ['filter' => 'auth']);
    $routes->put('(:segment)', 'Faq::updateFaq', ['filter' => 'auth']);
    $routes->delete('(:segment)', 'Faq::deleteFaq', ['filter' => 'auth']);
    $routes->put('reorder', 'Faq::reorderFaqs', ['filter' => 'auth']);
    $routes->post('categories', 'Faq::createCategory', ['filter' => 'auth']);
    $routes->put('categories/(:segment)', 'Faq::updateCategory', ['filter' => 'auth']);
    $routes->delete('categories/(:segment)', 'Faq::deleteCategory', ['filter' => 'auth']);
    $routes->put('categories/reorder', 'Faq::reorderCategories', ['filter' => 'auth']);
});

// Upload Routes 
$routes->group('api/admin/upload', ['namespace' => 'App\Controllers\Upload'], function ($routes) {
    $routes->post('', 'Upload::uploadFile');
    $routes->post('multiple', 'Upload::uploadFiles');
    $routes->get('', 'Upload::listFiles');
    $routes->get('stats', 'Upload::getStats');
    $routes->get('(:segment)', 'Upload::getFile/$1');
    $routes->delete('(:segment)', 'Upload::deleteFile/$1');
});

// Admin Routes 
$routes->group('api/admin', ['namespace' => 'App\Controllers\Admin', 'filter' => 'auth'], function ($routes) {
    $routes->post('upload', 'Admin::uploadFile');
    $routes->delete('uploads/(:segment)', 'Admin::deleteUpload');
    $routes->put('navigation', 'Admin::updateNavigation');
    $routes->delete('navigation/(:segment)', 'Admin::deleteNavItem');
    $routes->put('theme', 'Admin::updateTheme');
    $routes->put('hero-dashboards', 'Admin::updateHeroDashboards');
    $routes->put('hero-content', 'Admin::updateHeroContent');
    $routes->get('clients', 'Admin::getClients');
    $routes->put('clients', 'Admin::updateClients');
    $routes->delete('clients/(:segment)', 'Admin::deleteClient');
    $routes->get('section-contents', 'Admin::getSectionContents');
    $routes->put('section-content', 'Admin::updateSectionContent');
    $routes->put('services', 'Admin::updateServices');
    $routes->delete('services/(:segment)', 'Admin::deleteService');
    $routes->put('testimonials', 'Admin::updateTestimonials');
    $routes->delete('testimonials/(:segment)', 'Admin::deleteTestimonial/$1', ['filter' => 'auth']);
    // $routes->delete('testimonials/(:segment)', 'Admin::deleteTestimonial');
    $routes->put('stats', 'Admin::updateStats');
    $routes->delete('stats/(:segment)', 'Admin::deleteStat');
    $routes->put('footer', 'Admin::updateFooter');
    $routes->get('footer', 'Admin::getFooterContent');
    $routes->put('contact-info', 'Admin::updateContactInfo');
    $routes->get('contact-info', 'Admin::getContactInfo');
    $routes->put('social-links', 'Admin::updateSocialLinks');
    $routes->get('social-links', 'Admin::getSocialLinks');
    $routes->put('page-content', 'Admin::updatePageContent');
    $routes->get('page-content/(:segment)', 'Admin::getPageContent');
    $routes->put('call-to-actions', 'Admin::updateCallToActions');
    $routes->get('call-to-actions/(:segment)', 'Admin::getCallToActions');
    $routes->get('contact-submissions', 'Admin::getContactSubmissions');
    $routes->put('contact-submissions/(:segment)/status', 'Admin::updateContactSubmissionStatus');
    $routes->delete('contact-submissions/(:segment)', 'Admin::deleteContactSubmission');
});

// Admin Candidates Routes 
$routes->group('api/admin/candidates', ['namespace' => 'App\Controllers\AdminCandidate', 'filter' => 'auth'], function ($routes) {
    // IMPORTANT: Specific routes MUST come before parameterized routes
    $routes->get('stats', 'AdminCandidates::getCandidateStats');
    $routes->get('', 'AdminCandidates::getAllCandidates');
    // Use (:any) instead of (:segment) for longer IDs like cprofile_6937e1ef31b9a
    $routes->get('(:any)/applications', 'AdminCandidates::getCandidateApplications/$1');
    $routes->get('(:any)', 'AdminCandidates::getCandidateById/$1');
});

// Also support without /api/ prefix for backward compatibility (if frontend calls it)
$routes->group('admin/candidates', ['namespace' => 'App\Controllers\AdminCandidate', 'filter' => 'auth'], function ($routes) {
    $routes->get('stats', 'AdminCandidates::getCandidateStats');
    $routes->get('', 'AdminCandidates::getAllCandidates');
    $routes->get('(:any)/applications', 'AdminCandidates::getCandidateApplications/$1');
    $routes->get('(:any)', 'AdminCandidates::getCandidateById/$1');
});

// Users Routes 
$routes->group('api/users', ['namespace' => 'App\Controllers\Users', 'filter' => 'auth'], function ($routes) {
    $routes->get('', 'Users::getAllUsers');
    $routes->get('stats', 'Users::getUserStats');
    $routes->get('(:segment)', 'Users::getUserById');
    $routes->post('', 'Users::createUser');
    $routes->put('(:segment)', 'Users::updateUser');
    $routes->patch('(:segment)/suspend', 'Users::suspendUser');
    $routes->patch('(:segment)/activate', 'Users::activateUser');
    $routes->patch('(:segment)/role', 'Users::changeUserRole');
    $routes->delete('(:segment)', 'Users::deleteUser');
    $routes->post('bulk-delete', 'Users::bulkDelete');
});

// Audit Logs Routes 
$routes->group('api/audit-logs', ['namespace' => 'App\Controllers\AuditLog', 'filter' => 'auth'], function ($routes) {
    $routes->get('', 'AuditLog::getAll');
    $routes->get('stats', 'AuditLog::getStats');
    $routes->get('my-activity', 'AuditLog::getMyActivity');
});

// Recruitment Admin Routes 
$routes->group('api/recruitment-admin', ['namespace' => 'App\Controllers\RecruitmentAdmin', 'filter' => 'auth'], function ($routes) {
    $routes->get('dashboard/stats', 'RecruitmentAdmin::getDashboardStats');
    $routes->get('dashboard/top-performers', 'RecruitmentAdmin::getTopPerformers');
    $routes->get('dashboard/recent-activities', 'RecruitmentAdmin::getRecentActivities');
    $routes->get('reports/generate', 'RecruitmentAdmin::generateReport');
    $routes->get('candidates', 'RecruitmentAdmin::filterCandidates');
    $routes->post('categories', 'RecruitmentAdmin::createCategory');
    $routes->put('categories/(:segment)', 'RecruitmentAdmin::updateCategory');
    $routes->delete('categories/(:segment)', 'RecruitmentAdmin::deleteCategory');
    $routes->get('settings', 'RecruitmentAdmin::getSettings');
    $routes->put('settings', 'RecruitmentAdmin::updateSettings');
});

// Pricing Request Routes 
$routes->group('api/pricing-request', ['namespace' => 'App\Controllers\PricingRequest'], function ($routes) {
    $routes->post('', 'PricingRequest::create');
    $routes->get('', 'PricingRequest::findAll', ['filter' => 'auth']);
    $routes->get('(:segment)', 'PricingRequest::findOne', ['filter' => 'auth']);
    $routes->put('(:segment)', 'PricingRequest::update', ['filter' => 'auth']);
    $routes->put('(:segment)/status', 'PricingRequest::updateStatus', ['filter' => 'auth']);
    $routes->post('(:segment)/send-quote', 'PricingRequest::sendQuote', ['filter' => 'auth']);
    $routes->post('(:segment)/upload-attachment', 'PricingRequest::uploadAttachment', ['filter' => 'auth']);
    $routes->get('(:segment)/attachments', 'PricingRequest::getAttachments', ['filter' => 'auth']);
});

// About Admin Routes 
$routes->group('api/about', ['namespace' => 'App\Controllers\About'], function ($routes) {
    $routes->post('', 'About::createAboutContent', ['filter' => 'auth']);
    $routes->put('', 'About::updateAboutContent', ['filter' => 'auth']);
    $routes->post('sections', 'About::createSection', ['filter' => 'auth']);
    $routes->post('sections/(:segment)', 'About::updateSection', ['filter' => 'auth']);
    $routes->put('sections/reorder', 'About::reorderSections', ['filter' => 'auth']);
    $routes->put('sections/(:segment)/toggle', 'About::toggleSection', ['filter' => 'auth']);
    $routes->delete('sections/(:segment)', 'About::deleteSection', ['filter' => 'auth']);
    $routes->get('sections/(:segment)/versions', 'About::getSectionVersions', ['filter' => 'auth']);
    $routes->post('sections/(:segment)/restore/(:num)', 'About::restoreVersion', ['filter' => 'auth']);
});

// Interview Routes 
$routes->group('api/interviews', ['namespace' => 'App\Controllers\Interviews', 'filter' => 'auth'], function ($routes) {
    // Create interview
    $routes->post('', 'Interviews::createInterview');

    // Search interviews
    $routes->get('', 'Interviews::searchInterviews');

    // Upcoming interviews
    $routes->get('upcoming', 'Interviews::getUpcomingInterviews');

    // Statistics
    $routes->get('statistics', 'Interviews::getStatistics');

    // Get single interview (must come after specific routes)
    $routes->get('(:any)', 'Interviews::getInterviewById/$1');

    // Update interview
    $routes->put('(:any)', 'Interviews::updateInterview/$1');

    // Delete interview
    $routes->delete('(:any)', 'Interviews::deleteInterview/$1');

    // Bulk update
    $routes->post('bulk-update', 'Interviews::bulkUpdateStatus');

    // Send reminder
    $routes->post('(:any)/send-reminder', 'Interviews::sendReminder/$1');
});

// Serve uploaded files from writable/uploads/ directory
// Files are stored in writable/uploads/ but accessed via /uploads/* URLs
$routes->get('uploads/(:any)', function ($path) {
    // Security: Prevent directory traversal by using basename on each segment
    $pathSegments = explode('/', $path);
    $safePath = implode('/', array_map('basename', $pathSegments));

    // Build file path - files are in writable/uploads/
    $filePath = WRITEPATH . 'uploads/' . $safePath;

    // Check if file exists
    if (file_exists($filePath) && is_file($filePath)) {
        $mimeType = mime_content_type($filePath);
        $fileSize = filesize($filePath);
        $filename = basename($filePath);

        // Set headers
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=3600');

        // For CSV files, suggest inline viewing
        if ($mimeType === 'text/csv') {
            header('Content-Disposition: inline; filename="' . $filename . '"');
        }

        readfile($filePath);
        exit;
    }

    // File not found
    throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
});

// Handle /public/* URLs (if code generates URLs with /public/ prefix)
// This serves files from the public directory root
$routes->get('public/(:any)', function ($filename) {
    // Security: Prevent directory traversal
    $filename = basename($filename);
    $filePath = FCPATH . '../public/' . $filename;

    // Check if file exists
    if (file_exists($filePath) && is_file($filePath)) {
        $mimeType = mime_content_type($filePath);
        $fileSize = filesize($filePath);

        // Set headers
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=3600');

        // For CSV files, suggest inline viewing
        if ($mimeType === 'text/csv') {
            header('Content-Disposition: inline; filename="' . $filename . '"');
        }

        readfile($filePath);
        exit;
    }

    // File not found
    throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
});
