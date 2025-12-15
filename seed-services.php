<?php

/**
 * Simple script to seed services data
 * Run with: php seed-services.php
 */

// Define path constants
define('ROOTPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('FCPATH', ROOTPATH . 'public' . DIRECTORY_SEPARATOR);
define('SYSTEMPATH', ROOTPATH . 'vendor' . DIRECTORY_SEPARATOR . 'codeigniter4' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
define('APPPATH', ROOTPATH . 'app' . DIRECTORY_SEPARATOR);
define('WRITEPATH', ROOTPATH . 'writable' . DIRECTORY_SEPARATOR);

// Load CodeIgniter
require SYSTEMPATH . 'bootstrap.php';

// Get the CodeIgniter instance
$app = Config\Services::codeigniter();
$app->initialize();

try {
    echo "🌱 Starting services seeder...\n\n";
    
    // Load the seeder
    $seeder = new \App\Database\Seeds\ServiceSeeder();
    $seeder->run();
    
    echo "\n✅ Services seeded successfully!\n";
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
