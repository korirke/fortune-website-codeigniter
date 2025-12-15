<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations and Seeds directories.
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to use if no other is specified.
     */
    public string $defaultGroup = 'default';

    /**
     * The default database connection.
     *
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'          => '',
        'hostname'     => 'localhost',
        'username'     => 'root',
        'password'     => '',
        'database'     => '',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_unicode_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * Constructor to load values from .env file
     */
    public function __construct()
    {
        parent::__construct();
        
        // Override with .env values if they exist
        // CodeIgniter 4 uses env() helper function, not getenv()
        if (env('database.default.hostname')) {
            $this->default['hostname'] = env('database.default.hostname');
        }
        if (env('database.default.username')) {
            $this->default['username'] = env('database.default.username');
        }
        if (env('database.default.password') !== null) {
            $this->default['password'] = env('database.default.password');
        }
        if (env('database.default.database')) {
            $this->default['database'] = env('database.default.database');
        }
        if (env('database.default.DBDriver')) {
            $this->default['DBDriver'] = env('database.default.DBDriver');
        }
        if (env('database.default.port')) {
            $this->default['port'] = (int)env('database.default.port');
        }
        
        // Set DBDebug based on environment
        $this->default['DBDebug'] = (ENVIRONMENT !== 'production');
    }
}
