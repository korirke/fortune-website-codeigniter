<?php

/**
 * Cron Job Entry Point for Checking Expired Jobs
 * 
 * This script is called by cPanel cron to automatically close expired jobs.
 * Runs every hour to check for expired job postings.
 * 
 * @author Fortune Kenya Recruitment System
 * @version 1.0.0
 */

// Prevent web access
if (php_sapi_name() !== 'cli' && !defined('STDIN')) {
    // Allow both CLI and web-based cron
    // die('This script can only be run from command line or cron.');
}

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set the correct timezone for Kenya
date_default_timezone_set('Africa/Nairobi');

echo "=================================================\n";
echo "Fortune Kenya - Job Expiration Checker\n";
echo "=================================================\n";
echo "Started at: " . date('Y-m-d H:i:s T') . "\n";
echo "Timezone: Africa/Nairobi (EAT)\n";
echo "=================================================\n\n";

/*
 *---------------------------------------------------------------
 * SET THE CURRENT DIRECTORY
 *---------------------------------------------------------------
 */
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Change to the application directory
chdir(__DIR__);

/*
 *---------------------------------------------------------------
 * LOAD ENVIRONMENT FILE
 *---------------------------------------------------------------
 */
// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    require_once __DIR__ . '/vendor/autoload.php';
    
    // Use Dotenv if available
    if (class_exists('\Dotenv\Dotenv')) {
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }
}

/*
 *---------------------------------------------------------------
 * BOOTSTRAP CODEIGNITER
 *---------------------------------------------------------------
 */
require_once __DIR__ . '/app/Config/Paths.php';

$paths = new Config\Paths();

// Verify paths exist
if (!is_dir($paths->systemDirectory)) {
    die("ERROR: System directory not found: {$paths->systemDirectory}\n");
}

if (!is_dir($paths->appDirectory)) {
    die("ERROR: App directory not found: {$paths->appDirectory}\n");
}

/*
 *---------------------------------------------------------------
 * LOAD CODEIGNITER BOOTSTRAP
 *---------------------------------------------------------------
 */
require $paths->systemDirectory . '/Boot.php';

// Set environment to production for cron
putenv('CI_ENVIRONMENT=production');
$_SERVER['CI_ENVIRONMENT'] = 'production';

// Simulate CLI arguments for Spark
$_SERVER['argv'] = ['spark', 'jobs:check-expired'];
$_SERVER['argc'] = 2;

// Define SPARKED constant if not defined
if (!defined('SPARKED')) {
    define('SPARKED', true);
}

/*
 *---------------------------------------------------------------
 * RUN THE COMMAND
 *---------------------------------------------------------------
 */
try {
    echo "Initializing CodeIgniter...\n";
    
    // Boot the application
    $app = \CodeIgniter\Boot::bootCommand($paths);
    
    echo "Running jobs:check-expired command...\n\n";
    
    // Run the command
    $exitCode = $app->run();
    
    echo "\n=================================================\n";
    echo "Completed at: " . date('Y-m-d H:i:s T') . "\n";
    echo "Exit Code: " . $exitCode . "\n";
    echo "=================================================\n";
    
    exit($exitCode);
    
} catch (\Throwable $e) {
    echo "\n=================================================\n";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "=================================================\n";
    
    // Log the error
    if (function_exists('log_message')) {
        log_message('error', 'Cron job error: ' . $e->getMessage());
    }
    
    exit(1);
}