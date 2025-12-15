<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Health",
 *     description="Health check and system information endpoints"
 * )
 */
class App extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/",
     *     tags={"Health"},
     *     summary="API root information",
     *     @OA\Response(
     *         response=200,
     *         description="API information"
     *     )
     * )
     */
    public function index()
    {
        return $this->respond([
            'success' => true,
            'message' => 'Fortune Technologies API',
            'version' => '1.0.0'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/health",
     *     tags={"Health"},
     *     summary="Health check endpoint",
     *     @OA\Response(
     *         response=200,
     *         description="Service is healthy"
     *     )
     * )
     */
    public function health()
    {
        return $this->respond([
            'success' => true,
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/db-test",
     *     tags={"Health"},
     *     summary="Database configuration and connection test",
     *     @OA\Response(response="200", description="Database test results")
     * )
     */
    public function dbTest()
    {
        $db = \Config\Database::connect();
        $config = config('Database');
        
        // Get configuration values (mask password for security)
        $dbConfig = [
            'hostname' => $config->default['hostname'],
            'username' => $config->default['username'],
            'password' => $config->default['password'] ? '***SET***' : '***NOT SET***',
            'database' => $config->default['database'] ?: '***NOT SET***',
            'DBDriver' => $config->default['DBDriver'],
            'port' => $config->default['port'],
        ];
        
        // Get .env values using CodeIgniter's env() helper
        $envConfig = [
            'hostname' => env('database.default.hostname') ?: '***NOT SET IN .ENV***',
            'username' => env('database.default.username') ?: '***NOT SET IN .ENV***',
            'password' => env('database.default.password') !== null ? '***SET***' : '***NOT SET IN .ENV***',
            'database' => env('database.default.database') ?: '***NOT SET IN .ENV***',
            'DBDriver' => env('database.default.DBDriver') ?: '***NOT SET IN .ENV***',
            'port' => env('database.default.port') ?: '***NOT SET IN .ENV***',
        ];
        
        // Test connection
        $connectionStatus = 'unknown';
        $connectionError = null;
        $tables = [];
        
        try {
            if (empty($config->default['database'])) {
                $connectionStatus = 'not_configured';
                $connectionError = 'Database name is not set in configuration';
            } else {
                // Try to connect
                $db->initialize();
                $connectionStatus = 'connected';
                
                // Try to get tables
                try {
                    $tables = $db->listTables();
                } catch (\Exception $e) {
                    $tables = ['error' => 'Could not list tables: ' . $e->getMessage()];
                }
            }
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            $connectionStatus = 'failed';
            $connectionError = $e->getMessage();
        } catch (\Exception $e) {
            $connectionStatus = 'error';
            $connectionError = $e->getMessage();
        }
        
        return $this->respond([
            'success' => true,
            'connection_status' => $connectionStatus,
            'connection_error' => $connectionError,
            'active_config' => $dbConfig,
            'env_config' => $envConfig,
            'tables_count' => is_array($tables) && !isset($tables['error']) ? count($tables) : 0,
            'tables' => is_array($tables) && count($tables) <= 20 ? $tables : (isset($tables['error']) ? $tables : ['too_many_to_display']),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/test-nav",
     *     tags={"Health"},
     *     summary="Test navigation table query",
     *     @OA\Response(response="200", description="Navigation test results")
     * )
     */
    public function testNav()
    {
        try {
            $db = \Config\Database::connect();
            
            // Test 1: Check if table exists
            $tableExists = $db->tableExists('nav_items');
            
            // Test 2: Count all rows
            $totalCount = 0;
            if ($tableExists) {
                $totalCount = $db->table('nav_items')->countAllResults();
            }
            
            // Test 3: Get all rows
            $allRows = [];
            if ($tableExists) {
                $allRows = $db->table('nav_items')->get()->getResultArray();
            }
            
            // Test 4: Get active rows with isActive = 1
            $activeRows1 = [];
            if ($tableExists) {
                $activeRows1 = $db->table('nav_items')->where('isActive', 1)->get()->getResultArray();
            }
            
            // Test 5: Get active rows with isActive = true
            $activeRowsTrue = [];
            if ($tableExists) {
                $activeRowsTrue = $db->table('nav_items')->where('isActive', true)->get()->getResultArray();
            }
            
            // Test 6: Get table structure
            $fields = [];
            if ($tableExists) {
                $fields = $db->getFieldData('nav_items');
            }
            
            return $this->respond([
                'success' => true,
                'table_exists' => $tableExists,
                'total_count' => $totalCount,
                'all_rows_count' => count($allRows),
                'active_rows_count_1' => count($activeRows1),
                'active_rows_count_true' => count($activeRowsTrue),
                'sample_row' => !empty($allRows) ? $allRows[0] : null,
                'fields' => array_map(function($field) {
                    return [
                        'name' => $field->name,
                        'type' => $field->type,
                        'max_length' => $field->max_length,
                        'default' => $field->default
                    ];
                }, $fields),
                'all_rows' => $allRows
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
