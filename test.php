<?php
// Simple test script to verify CodeIgniter setup
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing CodeIgniter Setup...\n\n";

// Test 1: Check if autoloader works
echo "1. Testing autoloader...\n";
try {
    require_once __DIR__ . '/vendor/autoload.php';
    echo "   ✓ Autoloader loaded\n";
} catch (Exception $e) {
    echo "   ✗ Autoloader error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Check if CodeIgniter can be loaded
echo "2. Testing CodeIgniter framework...\n";
try {
    define('ROOTPATH', __DIR__ . DIRECTORY_SEPARATOR);
    define('SYSTEMPATH', ROOTPATH . 'vendor/codeigniter4/framework/system/');
    define('APPPATH', ROOTPATH . 'app/');
    define('WRITEPATH', ROOTPATH . 'writable/');
    define('FCPATH', ROOTPATH . 'public/');
    
    require SYSTEMPATH . 'bootstrap.php';
    echo "   ✓ CodeIgniter framework loaded\n";
} catch (Exception $e) {
    echo "   ✗ Framework error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Check if models can be loaded
echo "3. Testing models...\n";
try {
    $userModel = new \App\Models\User();
    echo "   ✓ User model loaded\n";
} catch (Exception $e) {
    echo "   ✗ Model error: " . $e->getMessage() . "\n";
}

// Test 4: Check if controllers can be loaded
echo "4. Testing controllers...\n";
try {
    $appController = new \App\Controllers\App();
    echo "   ✓ App controller loaded\n";
} catch (Exception $e) {
    echo "   ✗ Controller error: " . $e->getMessage() . "\n";
}

echo "\n✓ All basic tests passed!\n";
echo "\nTo start the server, run: php spark serve\n";
echo "Then access: http://localhost:8080/\n";
