<?php

namespace App\Controllers\Swagger;

use App\Controllers\BaseController;
use Psr\Log\LoggerInterface;

/**
 * @OA\Info(
 *     title="Fortune Technologies CMS API",
 *     version="1.0.0",
 *     description="Complete Content Management System API for Fortune Technologies website"
 * )
 * @OA\Server(
 *     url="http://localhost:8080",
 *     description="Development server"
 * )
 * @OA\Server(
 *     url="https://api.fortunekenya.com",
 *     description="Production server"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="JWT Authorization header using the Bearer scheme. Example: 'Authorization: Bearer {token}'"
 * )
 */
class Swagger extends BaseController
{
    public function index()
    {
        return view('swagger/index');
    }

    public function debug()
    {
        try {
            $vendorAutoload = ROOTPATH . 'vendor/autoload.php';
            if (file_exists($vendorAutoload) && !class_exists('OpenApi\Generator')) {
                require_once $vendorAutoload;
            }
            
            if (!class_exists('OpenApi\Generator')) {
                return $this->response->setJSON(['error' => 'OpenApi\Generator not found'], 500);
            }
            
            $swaggerControllerFile = __FILE__;
            $scanPaths = [
                APPPATH . 'Controllers',
                $swaggerControllerFile
            ];
            
            $openapi = \OpenApi\Generator::scan($scanPaths, ['validate' => false]);
            
            if (!$openapi) {
                return $this->response->setJSON(['error' => 'Generator returned null'], 500);
            }
            
            $json = json_decode($openapi->toJson(), true);
            
            return $this->response->setJSON([
                'openapi_version' => $json['openapi'] ?? 'NOT SET',
                'info' => $json['info'] ?? 'NOT SET',
                'paths_count' => isset($json['paths']) ? count($json['paths']) : 0,
                'paths' => isset($json['paths']) ? array_keys($json['paths']) : [],
                'scan_paths' => $scanPaths,
                'full_json_size' => strlen($openapi->toJson())
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function json()
    {
        try {
            // Ensure vendor autoloader is loaded
            $vendorAutoload = ROOTPATH . 'vendor/autoload.php';
            if (file_exists($vendorAutoload) && !class_exists('OpenApi\Generator')) {
                require_once $vendorAutoload;
            }
            
            // Check if class exists after loading
            if (!class_exists('OpenApi\Generator')) {
                throw new \Exception('OpenApi\Generator class not found. Please run: composer require zircote/swagger-php');
            }
            
            // Check if Doctrine Annotations is available (required for PHPDoc annotations)
            if (!class_exists('Doctrine\Common\Annotations\DocParser')) {
                throw new \Exception('Doctrine Annotations is required for PHPDoc annotations. Please run: composer require doctrine/annotations');
            }
            
            // Increase memory and execution time for large codebases
            ini_set('memory_limit', '256M');
            set_time_limit(60);
            
            // Define scan paths
            $controllersPath = APPPATH . 'Controllers';
            $configPath = APPPATH . 'Config';
            
            // Validate paths exist
            if (!is_dir($controllersPath)) {
                throw new \Exception("Controllers directory not found: {$controllersPath}");
            }
            
            if (!is_dir($configPath)) {
                throw new \Exception("Config directory not found: {$configPath}");
            }
            
            // Scan the app directory for OpenAPI annotations
            // Include Swagger controller file itself for @OA\Info annotation
            $swaggerControllerFile = __FILE__;
            
            $scanPaths = [
                $controllersPath,
                $swaggerControllerFile // Include this file for Info annotation
            ];
            
            // Create a custom logger that suppresses merge warnings
            $logger = new class implements LoggerInterface {
                public function emergency($message, array $context = []): void {}
                public function alert($message, array $context = []): void {}
                public function critical($message, array $context = []): void {}
                public function error($message, array $context = []): void {}
                public function warning($message, array $context = []): void
                {
                    // Suppress merge warnings - they're non-fatal
                    if (strpos($message, 'Unable to merge') === false) {
                        if (function_exists('log_message')) {
                            log_message('warning', "Swagger: $message");
                        }
                    }
                }
                public function notice($message, array $context = []): void {}
                public function info($message, array $context = []): void {}
                public function debug($message, array $context = []): void {}
                public function log($level, $message, array $context = []): void
                {
                    if ($level === 'warning' && strpos($message, 'Unable to merge') !== false) {
                        return; // Suppress merge warnings
                    }
                    if (function_exists('log_message')) {
                        log_message('debug', "Swagger [$level]: $message");
                    }
                }
            };
            
            // Use Generator to scan with custom logger
            // For version 5.7, we can use the static method or create an instance
            $openapi = \OpenApi\Generator::scan($scanPaths, [
                'validate' => false, // Disable validation to avoid errors during development
                'logger' => $logger, // Use custom logger to suppress merge warnings
            ]);
            
            if (!$openapi) {
                throw new \Exception('Generator::scan() returned null. Check if paths exist and contain valid PHP files with OpenAPI annotations.');
            }
            
            // Convert to JSON
            $jsonString = $openapi->toJson();
            $json = json_decode($jsonString, true);
            
            // Validate that the spec has required fields
            if (empty($json) || $json === null) {
                throw new \Exception('Generated OpenAPI JSON is empty. No annotations found in scanned paths. Make sure your controllers have @OA\ annotations and doctrine/annotations is installed.');
            }
            
            // Ensure openapi version is set
            if (!isset($json['openapi'])) {
                $json['openapi'] = '3.0.0';
            }
            
            // Ensure info is set
            if (!isset($json['info'])) {
                $json['info'] = [
                    'title' => 'Fortune Technologies CMS API',
                    'version' => '1.0.0',
                    'description' => 'Complete Content Management System API for Fortune Technologies website'
                ];
            }
            
            // Ensure paths exists (even if empty)
            if (!isset($json['paths'])) {
                $json['paths'] = [];
            }
            
            // Re-encode to JSON
            $jsonString = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            
            $this->response->setContentType('application/json');
            $this->response->setHeader('Access-Control-Allow-Origin', '*');
            return $this->response->setBody($jsonString);
        } catch (\Throwable $e) {
            // Log the error for debugging
            if (function_exists('log_message')) {
                log_message('error', 'Swagger JSON generation failed: ' . $e->getMessage());
                log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            }
            
            $errorResponse = [
                'error' => 'Failed to generate OpenAPI documentation',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
            
            if (ENVIRONMENT !== 'production') {
                $errorResponse['trace'] = $e->getTraceAsString();
                $errorResponse['scanPaths'] = $scanPaths ?? [];
                $errorResponse['appPath'] = APPPATH ?? 'not defined';
            }
            
            return $this->response->setJSON($errorResponse, 500);
        }
    }
}
