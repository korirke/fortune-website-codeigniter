<?php

namespace App\Traits;

use App\Helpers\DataTypeHelper;
use CodeIgniter\API\ResponseTrait;

/**
 * NormalizedResponseTrait
 * 
 * Provides normalized response methods that convert MySQL data types
 * (0/1 booleans) to JavaScript data types (true/false)
 * 
 * Use this trait in controllers instead of or alongside ResponseTrait
 */
trait NormalizedResponseTrait
{
    use ResponseTrait {
        ResponseTrait::respond as protected respondOriginal;
    }

    /**
     * Normalized respond method that converts data types to match Node.js format
     * 
     * @param array|object|null $data Response data
     * @param int|null $statusCode HTTP status code
     * @param string|null $message Optional message
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    protected function respond($data = null, ?int $statusCode = null, ?string $message = null)
    {
        // Normalize the data before responding
        if (is_array($data)) {
            $data = DataTypeHelper::normalizeResponse($data);
        } elseif (is_object($data)) {
            // Convert object to array, normalize
            $normalized = DataTypeHelper::normalizeForApi((array) $data);
            $data = $normalized;
        }
        
        // ResponseTrait expects string for message, not null - use empty string if null
        $message = $message ?? '';
        
        // Call the original respond method from ResponseTrait
        return $this->respondOriginal($data, $statusCode, $message);
    }
}
