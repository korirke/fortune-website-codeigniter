<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return service('response')->setJSON([
                'success' => false,
                'message' => 'Authorization token required'
            ])->setStatusCode(401);
        }

        $token = $matches[1];
        $secretKey = getenv('jwt.secret') ?: 'your-secret-key-change-this-in-production';

        try {
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            $request->user = $decoded;
        } catch (\Exception $e) {
            return service('response')->setJSON([
                'success' => false,
                'message' => 'Invalid or expired token'
            ])->setStatusCode(401);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
}
