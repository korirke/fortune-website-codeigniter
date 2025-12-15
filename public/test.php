<?php
// Simple test endpoint
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Server is running!',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION
]);
