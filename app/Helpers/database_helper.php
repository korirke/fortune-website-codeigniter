<?php

if (!function_exists('isDatabaseConfigured')) {
    /**
     * Check if database is configured
     */
    function isDatabaseConfigured(): bool
    {
        $dbName = getenv('database.default.database');
        return !empty($dbName);
    }
}

if (!function_exists('safeModelQuery')) {
    /**
     * Safely execute model query with error handling
     */
    function safeModelQuery(callable $callback, $default = [])
    {
        try {
            if (!isDatabaseConfigured()) {
                return $default;
            }
            return $callback();
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            return $default;
        } catch (\Exception $e) {
            return $default;
        }
    }
}
