<?php

namespace App\Helpers;

/**
 * DataTypeHelper
 * 
 * Converts MySQL data types to match Node.js/JavaScript data types
 * Specifically converts 0/1 (tinyint) booleans to true/false
 */
class DataTypeHelper
{
    /**
     * List of common boolean field names across all models
     * These fields will be converted from 0/1 to true/false
     */
    protected static array $booleanFields = [
        'isActive',
        'isFeatured',
        'isPopular',
        'onQuote',
        'hasProcess',
        'hasCompliance',
        'emailVerified',
        'hasDropdown',
        'isPublished',
        'isArchived',
        'isDeleted',
        'isDefault',
        'isPublic',
        'isVerified',
        'isSuspended',
        'isBlocked',
        'requiresAuth',
        'allowComments',
        'isRecommended',
        'hasAttachment',
        'isPrimary',
        'isSelected',
        'isComplete',
        'isPending',
        'isApproved',
        'isRejected',
        'openToWork',
        'maintenanceMode',
        'allowRegistration',
        'requireEmailVerification',
        'jobApprovalRequired',
        'autoApproveJobs',
        'requireCompanyVerification',
        'enableEmailNotifications',
        // Job-related boolean fields
        'isRemote',
        'featured',
        'sponsored',
        'verified',
        'required',
        'canPostJobs',
        'canViewCVs',
    ];

    /**
     * List of common integer field names across all models
     * These fields will be cast to integers
     */
    protected static array $integerFields = [
        'position',
        'sortOrder',
        'version',
        'views',
        'applicationCount',
        'helpfulCount',
        'faqCount',
        'jobCount',
        'page',
        'limit',
        'total',
        'totalPages',
        'totalCandidates',
        'activeCandidates',
        'candidatesWithResume',
        'candidatesOpenToWork',
        'recentCandidates',
        'totalJobs',
        'activeJobs',
        'totalApplications',
        'registeredCandidates',
        'activeEmployers',
        'pendingModeration',
        'avgTimeToHire',
        'count',
        'skip',
        'take',
        'maxJobPostingsPerCompany',
        'jobExpirationDays',
    ];

    /**
     * List of common float/number field names across all models
     * These fields will be cast to floats
     */
    protected static array $floatFields = [
        'salaryMin',
        'salaryMax',
        'specificSalary',
        'quoteAmount',
        'fileSize',
        'rating',
        'price',
        'amount',
        'cost',
        'fee',
        'discount',
        'tax',
        'totalAmount',
        'subtotal',
    ];

    /**
     * List of JSON field names that should be parsed from strings to arrays/objects
     * These fields are stored as JSON strings in MySQL but should be returned as arrays/objects
     */
    protected static array $jsonFields = [
        'features',
        'benefits',
        'processSteps',
        'complianceItems',
        'tags',
        'results',
        'services',
        'stats',
        'metadata',
        // 'content',
        'socialLinks',
        'trustPoints',
    ];

    /**
     * Convert database result to match Node.js data types
     * 
     * @param mixed $data Data from database (array, object, or nested structure)
     * @return mixed Converted data with proper types
     */
    public static function normalizeForApi($data)
    {
        if (is_array($data)) {
            // Handle array of items
            if (isset($data[0]) && is_array($data[0])) {
                // Array of arrays/objects
                return array_map([self::class, 'normalizeForApi'], $data);
            } else {
                // Single array/object
                return self::normalizeItem($data);
            }
        } elseif (is_object($data)) {
            // Convert object to array, normalize, then return as array
            return self::normalizeItem((array) $data);
        } else {
            // Primitive value, return as is
            return $data;
        }
    }

    /**
     * Normalize a single item (array)
     * 
     * @param array $item Single database record
     * @return array Normalized record
     */
    protected static function normalizeItem(array $item): array
    {
        $normalized = [];
        
        foreach ($item as $key => $value) {
            if (is_array($value)) {
                // Recursively normalize nested arrays
                $normalized[$key] = self::normalizeForApi($value);
            } elseif (is_object($value)) {
                // Recursively normalize nested objects
                $normalized[$key] = self::normalizeForApi((array) $value);
            } elseif (self::isJsonField($key) && is_string($value) && !empty($value)) {
                // Parse JSON string fields to arrays/objects
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $normalized[$key] = $decoded;
                } else {
                    // If JSON decode fails, set to empty array for array fields, null for object fields
                    $normalized[$key] = in_array($key, ['features', 'benefits', 'processSteps', 'complianceItems', 'tags', 'services', 'results', 'stats', 'trustPoints']) ? [] : null;
                }
            } elseif (self::isBooleanField($key)) {
                // Convert boolean field: 0/1 -> false/true, '0'/'1' -> false/true, null -> false
                $normalized[$key] = self::toBoolean($value);
            } elseif (self::isIntegerField($key)) {
                // Convert to integer (null stays null)
                // Handle numeric strings and ensure proper integer casting
                if ($value === null) {
                    $normalized[$key] = null;
                } elseif (is_numeric($value)) {
                    $normalized[$key] = (int) $value;
                } else {
                    $normalized[$key] = $value;
                }
            } elseif (self::isFloatField($key)) {
                // Convert to float (null stays null)
                // Handle numeric strings and ensure proper float casting
                if ($value === null) {
                    $normalized[$key] = null;
                } elseif (is_numeric($value)) {
                    $normalized[$key] = (float) $value;
                } else {
                    $normalized[$key] = $value;
                }
            } elseif ($value === null) {
                // Keep null as null
                $normalized[$key] = null;
            } else {
                // Keep other values as is
                $normalized[$key] = $value;
            }
        }
        
        return $normalized;
    }

    /**
     * Check if a field name is a boolean field
     * 
     * @param string $fieldName Field name to check
     * @return bool
     */
    protected static function isBooleanField(string $fieldName): bool
    {
        // Check exact match
        if (in_array($fieldName, self::$booleanFields, true)) {
            return true;
        }
        
        // Check if field name starts with common boolean prefixes
        $booleanPrefixes = ['is', 'has', 'on', 'allow', 'requires', 'can'];
        foreach ($booleanPrefixes as $prefix) {
            if (str_starts_with($fieldName, $prefix) && ctype_upper(substr($fieldName, strlen($prefix), 1))) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if a field name is an integer field
     * 
     * @param string $fieldName Field name to check
     * @return bool
     */
    protected static function isIntegerField(string $fieldName): bool
    {
        // Check exact match
        if (in_array($fieldName, self::$integerFields, true)) {
            return true;
        }
        
        // Check if field name ends with common integer suffixes
        $integerSuffixes = ['Count', 'Id', 'Version', 'Views', 'Page', 'Limit', 'Total', 'Pages'];
        foreach ($integerSuffixes as $suffix) {
            if (str_ends_with($fieldName, $suffix)) {
                return true;
            }
        }
        
        // Check _count nested fields (but not the _count key itself - that's an object)
        // Fields inside _count objects should be integers
        if (str_contains($fieldName, '_count') && $fieldName !== '_count') {
            return true;
        }
        if (str_contains($fieldName, 'Count') && $fieldName !== 'Count') {
            return true;
        }
        
        // Common integer field patterns (these are count fields inside _count objects)
        // Note: These are only integers when they're inside _count objects, not when they're arrays
        // The recursive normalization will handle _count objects properly
        
        return false;
    }

    /**
     * Check if a field name is a float/number field
     * 
     * @param string $fieldName Field name to check
     * @return bool
     */
    protected static function isFloatField(string $fieldName): bool
    {
        // Check exact match
        if (in_array($fieldName, self::$floatFields, true)) {
            return true;
        }
        
        // Check if field name contains common float indicators
        $floatIndicators = ['Amount', 'Price', 'Cost', 'Fee', 'Salary', 'Rate', 'Size'];
        foreach ($floatIndicators as $indicator) {
            if (str_contains($fieldName, $indicator)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if a field name is a JSON field that should be parsed
     * 
     * @param string $fieldName Field name to check
     * @return bool
     */
    protected static function isJsonField(string $fieldName): bool
    {
        // Check exact match
        if (in_array($fieldName, self::$jsonFields, true)) {
            return true;
        }
        
        return false;
    }

    /**
     * Convert value to proper boolean
     * 
     * @param mixed $value Value to convert (0, 1, '0', '1', true, false, null)
     * @return bool
     */
    protected static function toBoolean($value): bool
    {
        if ($value === null) {
            return false;
        }
        
        // Handle string representations
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }
        
        // Handle numeric (0, 1)
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        
        // Handle actual boolean
        if (is_bool($value)) {
            return $value;
        }
        
        // Default to false for unexpected types
        return false;
    }

    /**
     * Normalize response data structure
     * This is the main method to use in controllers
     * 
     * @param array $response Response array with 'data' key
     * @return array Normalized response
     */
    public static function normalizeResponse(array $response): array
    {
        if (isset($response['data'])) {
            $response['data'] = self::normalizeForApi($response['data']);
        }
        
        // Also normalize pagination data if present
        if (isset($response['data']['pagination'])) {
            // Ensure pagination metadata has proper integer types
            $pagination = &$response['data']['pagination'];
            if (isset($pagination['page'])) $pagination['page'] = (int) $pagination['page'];
            if (isset($pagination['limit'])) $pagination['limit'] = (int) $pagination['limit'];
            if (isset($pagination['total'])) $pagination['total'] = (int) $pagination['total'];
            if (isset($pagination['totalPages'])) $pagination['totalPages'] = (int) $pagination['totalPages'];
            if (isset($pagination['hasNext'])) $pagination['hasNext'] = self::toBoolean($pagination['hasNext']);
            if (isset($pagination['hasPrev'])) $pagination['hasPrev'] = self::toBoolean($pagination['hasPrev']);
            
            // Nested items need normalization
            if (isset($response['data']['items'])) {
                $response['data']['items'] = self::normalizeForApi($response['data']['items']);
            }
            if (isset($response['data']['candidates'])) {
                $response['data']['candidates'] = self::normalizeForApi($response['data']['candidates']);
            }
            if (isset($response['data']['users'])) {
                $response['data']['users'] = self::normalizeForApi($response['data']['users']);
            }
            if (isset($response['data']['jobs'])) {
                $response['data']['jobs'] = self::normalizeForApi($response['data']['jobs']);
            }
            if (isset($response['data']['applications'])) {
                $response['data']['applications'] = self::normalizeForApi($response['data']['applications']);
            }
        }
        
        // Normalize pagination at root level if present
        if (isset($response['pagination'])) {
            $pagination = &$response['pagination'];
            if (isset($pagination['page'])) $pagination['page'] = (int) $pagination['page'];
            if (isset($pagination['limit'])) $pagination['limit'] = (int) $pagination['limit'];
            if (isset($pagination['total'])) $pagination['total'] = (int) $pagination['total'];
            if (isset($pagination['totalPages'])) $pagination['totalPages'] = (int) $pagination['totalPages'];
            if (isset($pagination['hasNext'])) $pagination['hasNext'] = self::toBoolean($pagination['hasNext']);
            if (isset($pagination['hasPrev'])) $pagination['hasPrev'] = self::toBoolean($pagination['hasPrev']);
        }
        
        return $response;
    }
}
