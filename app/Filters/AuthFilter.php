<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Authentication Filter
 * Validates JWT tokens and attaches decoded user data to the request
 * 
 * Note: #[AllowDynamicProperties] is required for PHP 8.2+ to suppress
 * deprecation warning when assigning $request->user dynamically.
 */
#[\AllowDynamicProperties]
class AuthFilter implements FilterInterface
{
    /**
     * Validate JWT token before request processing
     * 
     * @param RequestInterface $request
     * @param array|null $arguments
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Extract Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        
        // Check if Authorization header exists and has Bearer token
        if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return service('response')->setJSON([
                'success' => false,
                'message' => 'Authorization token required'
            ])->setStatusCode(401);
        }

        // Extract token from Bearer scheme
        $token = $matches[1];
        
        // Get secret key from environment or use default (change in production!)
        $secretKey = getenv('jwt.secret') ?: 'your-secret-key-change-this-in-production';

        try {
            // Decode and verify JWT token
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            
            // Attach decoded user to request
            // This works because of #[AllowDynamicProperties] attribute above
            $request->user = $decoded;
            
        } catch (\Exception $e) {
            // Token is invalid or expired
            return service('response')->setJSON([
                'success' => false,
                'message' => 'Invalid or expired token: ' . $e->getMessage()
            ])->setStatusCode(401);
        }
    }

    /**
     * Process after request (no action needed)
     * 
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param array|null $arguments
     * @return void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-processing needed
    }
}
