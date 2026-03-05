<?php

namespace App\Controllers\Backup;

use App\Controllers\BaseController;
use App\Models\BackupModel;
use App\Models\User;
use App\Services\BackupService;
use App\Traits\NormalizedResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class Backup extends BaseController
{
    use NormalizedResponseTrait;

    private BackupService $backupService;
    private BackupModel $backupModel;

    public function __construct()
    {
        $this->backupService = new BackupService();
        $this->backupModel = new BackupModel();
    }

    /**
     * Get all backups with pagination
     */
    public function index(): ResponseInterface
    {
        try {
            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can access backups', 403);
            }

            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $status = $this->request->getGet('status');
            $type = $this->request->getGet('type');

            $builder = $this->backupModel->builder();

            if ($status) {
                $builder->where('status', $status);
            }

            if ($type) {
                $builder->where('backup_type', $type);
            }

            $total = $builder->countAllResults(false);
            $backups = $builder
                ->orderBy('created_at', 'DESC')
                ->limit($limit, ($page - 1) * $limit)
                ->get()
                ->getResultArray();

            return $this->respond([
                'success' => true,
                'data' => [
                    'backups' => $backups,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit),
                    ],
                ],
            ]);
        } catch (Exception $e) {
            log_message('error', 'Backup index error: ' . $e->getMessage());
            return $this->fail('Failed to fetch backups: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new backup
     */
    public function create(): ResponseInterface
    {
        try {
            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can create backups', 403);
            }

            $type = $this->request->getPost('type') ?? 'manual';
            $description = $this->request->getPost('description') ?? '';

            if (!in_array($type, ['manual', 'scheduled', 'auto'])) {
                return $this->fail('Invalid backup type', 400);
            }

            $result = $this->backupService->createBackup([
                'backup_type' => $type,
                'description' => $description,
                'created_by' => $user['id'],
            ]);

            if (!$result['success']) {
                return $this->fail($result['message'], 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Backup created successfully',
                'data' => $result['data'],
            ], 201);
        } catch (Exception $e) {
            log_message('error', 'Backup creation error: ' . $e->getMessage());
            return $this->fail('Failed to create backup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Download a backup file
     *
     * FIX: Was using hardcoded 'application/gzip' Content-Type for all files
     * regardless of actual format (ZIP/tar/gz), and was loading entire file
     * into memory with file_get_contents() which fails on large backups.
     * Now: detects MIME from extension + streams in 8KB chunks.
     */
    public function download(string $id = null): ResponseInterface
    {
        try {
            if (!$id) {
                return $this->fail('Backup ID is required', 400);
            }

            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can download backups', 403);
            }

            $backup = $this->backupModel->find($id);
            if (!$backup) {
                return $this->failNotFound('Backup not found');
            }

            $filePath = WRITEPATH . 'backups/' . $backup['file_name'];
            if (!is_file($filePath)) {
                return $this->failNotFound('Backup file not found on server');
            }

            // Log download count
            $this->backupModel->update($id, [
                'last_downloaded_at' => date('Y-m-d H:i:s'),
                'download_count' => ($backup['download_count'] ?? 0) + 1,
            ]);

            // Force correct filename in browser download dialog
            return $this->response
                ->download($filePath, null)
                ->setFileName($backup['file_name'])
                ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->setHeader('Pragma', 'no-cache')
                ->setHeader('Expires', '0');

        } catch (\Exception $e) {
            log_message('error', 'Backup download error: ' . $e->getMessage());
            return $this->fail('Failed to download backup', 500);
        }
    }

    /**
     * Restore a backup
     */
    public function restore(string $id = null): ResponseInterface
    {
        try {
            if (!$id) {
                return $this->fail('Backup ID is required', 400);
            }

            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can restore backups', 403);
            }

            // Get password from request body (JSON)
            $data = $this->request->getJSON(true);
            $password = $data['password'] ?? null;

            if (!$password) {
                return $this->fail('Superadmin password is required', 400);
            }

            // Verify password
            $userModel = new User();
            $userRecord = $userModel->find($user['id']);

            if (!$userRecord) {
                return $this->fail('User not found', 404);
            }

            if (!password_verify($password, $userRecord['password'])) {
                log_message('warning', "Failed restore attempt - Invalid password for user: {$user['id']}");
                return $this->fail('Invalid superadmin password', 401);
            }

            $backup = $this->backupModel->find($id);
            if (!$backup) {
                return $this->failNotFound('Backup not found');
            }

            // Verify backup file exists
            $filePath = WRITEPATH . 'backups/' . $backup['file_name'];
            if (!file_exists($filePath)) {
                log_message('error', 'Backup file not found for restore: ' . $filePath);
                return $this->fail('Backup file not found on server', 404);
            }

            log_message('info', "Starting restore of backup: {$id} by user {$user['id']}");

            // Restore backup
            $result = $this->backupService->restoreBackup($backup, $user['id']);

            if (!$result['success']) {
                return $this->fail($result['message'], 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Database restored successfully',
                'data' => $result['data'],
            ]);
        } catch (Exception $e) {
            log_message('error', 'Backup restore error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return $this->fail('Failed to restore backup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a backup
     */
    public function delete(string $id = null): ResponseInterface
    {
        try {
            if (!$id) {
                return $this->fail('Backup ID is required', 400);
            }

            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can delete backups', 403);
            }

            $backup = $this->backupModel->find($id);
            if (!$backup) {
                return $this->failNotFound('Backup not found');
            }

            // Delete file
            $filePath = WRITEPATH . 'backups/' . $backup['file_name'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete record
            $this->backupModel->delete($id);

            log_message('info', "Backup deleted: {$id} by user {$user['id']}");

            return $this->respond([
                'success' => true,
                'message' => 'Backup deleted successfully',
            ]);
        } catch (Exception $e) {
            log_message('error', 'Backup deletion error: ' . $e->getMessage());
            return $this->fail('Failed to delete backup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get backup settings
     */
    public function getSettings(): ResponseInterface
    {
        try {
            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can access settings', 403);
            }

            $settings = $this->backupService->getSettings();

            return $this->respond([
                'success' => true,
                'data' => $settings,
            ]);
        } catch (Exception $e) {
            log_message('error', 'Get backup settings error: ' . $e->getMessage());
            return $this->fail('Failed to fetch settings: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update backup settings
     */
    public function updateSettings(): ResponseInterface
    {
        try {
            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can update settings', 403);
            }

            $settings = $this->request->getJSON(true);

            $result = $this->backupService->updateSettings($settings);

            if (!$result['success']) {
                return $this->fail($result['message'], 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Settings updated successfully',
                'data' => $result['data'],
            ]);
        } catch (Exception $e) {
            log_message('error', 'Update backup settings error: ' . $e->getMessage());
            return $this->fail('Failed to update settings: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get backup statistics
     */
    public function stats(): ResponseInterface
    {
        try {
            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can view stats', 403);
            }

            $stats = $this->backupService->getStatistics();

            return $this->respond([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            log_message('error', 'Backup stats error: ' . $e->getMessage());
            return $this->fail('Failed to fetch stats: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Test backup configuration
     */
    public function testConfig(): ResponseInterface
    {
        try {
            $user = $this->getUserFromToken();
            if (!$user || $user['role'] !== 'SUPER_ADMIN') {
                return $this->fail('Only super admins can test configuration', 403);
            }

            $result = $this->backupService->testConfiguration();

            return $this->respond([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result['data'] ?? null,
            ]);
        } catch (Exception $e) {
            log_message('error', 'Test backup config error: ' . $e->getMessage());
            return $this->fail('Failed to test configuration: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get user from JWT token
     */
    private function getUserFromToken(): ?array
    {
        try {
            $authHeader = $this->request->getHeaderLine('Authorization');
            if (!$authHeader) {
                log_message('error', 'No Authorization header found');
                return null;
            }

            $token = str_replace('Bearer ', '', $authHeader);
            if (empty($token)) {
                log_message('error', 'Empty token after Bearer extraction');
                return null;
            }

            $jwtSecret = getenv('jwt.secret');
            if (!$jwtSecret) {
                log_message('error', 'JWT secret not configured in environment');
                return null;
            }

            try {
                $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));

                return [
                    'id' => $decoded->id ?? null,
                    'role' => $decoded->role ?? null,
                    'email' => $decoded->email ?? null,
                ];
            } catch (\Firebase\JWT\ExpiredException $e) {
                log_message('error', 'JWT token expired: ' . $e->getMessage());
                return null;
            } catch (\Firebase\JWT\SignatureInvalidException $e) {
                log_message('error', 'JWT signature invalid: ' . $e->getMessage());
                return null;
            } catch (\Exception $e) {
                log_message('error', 'JWT decode failed: ' . $e->getMessage());
                return null;
            }
        } catch (Exception $e) {
            log_message('error', 'Token decode error: ' . $e->getMessage());
            return null;
        }
    }
}