<?php

namespace App\Controllers\AuditLog;

use App\Controllers\BaseController;
use App\Models\AuditLog as AuditLogModel;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Audit Logs",
 *     description="Audit log endpoints"
 * )
 */
class AuditLog extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/audit-logs",
     *     tags={"Audit Logs"},
     *     summary="Get all audit logs with filtering",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="userId", in="query", required=false, @OA\Schema(type="string"), description="Filter by user ID"),
     *     @OA\Parameter(name="action", in="query", required=false, @OA\Schema(type="string"), description="Filter by action type"),
     *     @OA\Parameter(name="resource", in="query", required=false, @OA\Schema(type="string"), description="Filter by resource type"),
     *     @OA\Parameter(name="module", in="query", required=false, @OA\Schema(type="string"), description="Filter by module"),
     *     @OA\Parameter(name="startDate", in="query", required=false, @OA\Schema(type="string", format="date"), description="Filter from date", example="2024-01-01"),
     *     @OA\Parameter(name="endDate", in="query", required=false, @OA\Schema(type="string", format="date"), description="Filter to date", example="2024-12-31"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(response="200", description="Logs retrieved successfully")
     * )
     */
    public function getAll()
    {
        try {
            $logModel = new AuditLogModel();
            
            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;
            
            // Get filters
            $userId = $this->request->getGet('userId');
            $action = $this->request->getGet('action');
            $resource = $this->request->getGet('resource');
            $module = $this->request->getGet('module');
            $startDate = $this->request->getGet('startDate');
            $endDate = $this->request->getGet('endDate');
            
            // Apply filters
            if ($userId) {
                $logModel->where('userId', $userId);
            }
            if ($action) {
                $logModel->like('action', $action);
            }
            if ($resource) {
                $logModel->like('resource', $resource);
            }
            if ($module) {
                $logModel->where('module', $module);
            }
            if ($startDate) {
                $logModel->where('createdAt >=', $startDate);
            }
            if ($endDate) {
                $logModel->where('createdAt <=', $endDate);
            }
            
            // Get total count
            $total = $logModel->countAllResults(false);
            
            // Get paginated results
            $logs = $logModel
                ->orderBy('createdAt', 'DESC')
                ->findAll($limit, $skip);
            
            // Format logs with user data and parse JSON details
            $userModel = new \App\Models\User();
            $formattedLogs = [];
            
            foreach ($logs as $log) {
                // Parse JSON details field
                $details = null;
                if (!empty($log['details'])) {
                    $decoded = json_decode($log['details'], true);
                    $details = json_last_error() === JSON_ERROR_NONE ? $decoded : $log['details'];
                }
                
                // Get user data
                $user = $userModel->select('id, email, firstName, lastName, role')
                    ->find($log['userId']);
                
                // Format timestamps to ISO 8601 (matching Node.js)
                $createdAt = $log['createdAt'];
                if (!str_contains($createdAt, 'T')) {
                    // Convert "2025-12-03 06:03:24.592" to "2025-12-03T06:03:24.592Z"
                    $createdAt = str_replace(' ', 'T', $createdAt) . 'Z';
                }
                
                $formattedLogs[] = [
                    'id' => $log['id'],
                    'userId' => $log['userId'],
                    'action' => $log['action'],
                    'resource' => $log['resource'],
                    'resourceId' => $log['resourceId'],
                    'module' => $log['module'],
                    'details' => $details,
                    'ipAddress' => $log['ipAddress'],
                    'userAgent' => $log['userAgent'],
                    'createdAt' => $createdAt,
                    'user' => $user
                ];
            }
            
            // Match Node.js response structure
            return $this->respond([
                'success' => true,
                'message' => 'Audit logs retrieved successfully',
                'data' => $formattedLogs,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => (int) ceil($total / $limit),
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AuditLog::getAll - ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve audit logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/audit-logs/stats",
     *     tags={"Audit Logs"},
     *     summary="Get audit log statistics",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="module", in="query", required=false, @OA\Schema(type="string"), description="Filter by module"),
     *     @OA\Response(response="200", description="Stats retrieved successfully")
     * )
     */
    public function getStats()
    {
        try {
            $logModel = new AuditLogModel();
            $module = $this->request->getGet('module');
            
            $now = new \DateTime();
            $yesterday = clone $now;
            $yesterday->modify('-1 day');
            $lastWeek = clone $now;
            $lastWeek->modify('-7 days');
            $lastMonth = clone $now;
            $lastMonth->modify('-30 days');
            
            $where = $module ? ['module' => $module] : [];
            
            // Get counts
            $total = $logModel->where($where)->countAllResults(false);
            $recent24h = $logModel->where($where)->where('createdAt >=', $yesterday->format('Y-m-d H:i:s'))->countAllResults(false);
            $recentWeek = $logModel->where($where)->where('createdAt >=', $lastWeek->format('Y-m-d H:i:s'))->countAllResults(false);
            $recentMonth = $logModel->where($where)->where('createdAt >=', $lastMonth->format('Y-m-d H:i:s'))->countAllResults(false);
            
            $db = \Config\Database::connect();
            
            // By action
            $byActionQuery = $db->table('audit_logs')
                ->select('action, COUNT(*) as count')
                ->groupBy('action')
                ->orderBy('count', 'DESC')
                ->limit(10);
            if ($module) {
                $byActionQuery->where('module', $module);
            }
            $byActionRaw = $byActionQuery->get()->getResultArray();
            $byAction = array_map(function($item) {
                return ['action' => $item['action'], 'count' => (int) $item['count']];
            }, $byActionRaw);
            
            // By resource
            $byResourceQuery = $db->table('audit_logs')
                ->select('resource, COUNT(*) as count')
                ->groupBy('resource')
                ->orderBy('count', 'DESC')
                ->limit(10);
            if ($module) {
                $byResourceQuery->where('module', $module);
            }
            $byResourceRaw = $byResourceQuery->get()->getResultArray();
            $byResource = array_map(function($item) {
                return ['resource' => $item['resource'], 'count' => (int) $item['count']];
            }, $byResourceRaw);
            
            // By module
            $byModuleRaw = $db->table('audit_logs')
                ->select('module, COUNT(*) as count')
                ->groupBy('module')
                ->orderBy('count', 'DESC')
                ->get()
                ->getResultArray();
            $byModule = array_map(function($item) {
                return ['module' => $item['module'], 'count' => (int) $item['count']];
            }, $byModuleRaw);
            
            // By user
            $byUserQuery = $db->table('audit_logs')
                ->select('userId, COUNT(*) as count')
                ->groupBy('userId')
                ->orderBy('count', 'DESC')
                ->limit(10);
            if ($module) {
                $byUserQuery->where('module', $module);
            }
            $byUserRaw = $byUserQuery->get()->getResultArray();
            $byUser = array_map(function($item) {
                return ['userId' => $item['userId'], 'count' => (int) $item['count']];
            }, $byUserRaw);
            
            return $this->respond([
                'success' => true,
                'message' => 'Audit log stats retrieved successfully',
                'data' => [
                    'total' => (int) $total,
                    'recent24h' => (int) $recent24h,
                    'recentWeek' => (int) $recentWeek,
                    'recentMonth' => (int) $recentMonth,
                    'byAction' => $byAction,
                    'byResource' => $byResource,
                    'byModule' => $byModule,
                    'byUser' => $byUser,
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AuditLog::getStats - ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/audit-logs/my-activity",
     *     tags={"Audit Logs"},
     *     summary="Get current user activity logs",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Number of logs to retrieve", example=20),
     *     @OA\Response(response="200", description="Activity retrieved successfully")
     * )
     */
    public function getMyActivity()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $logModel = new AuditLogModel();
            $logs = $logModel->where('userId', $user->id)
                ->orderBy('createdAt', 'DESC')
                ->limit($limit)
                ->findAll();
            
            // Format logs with user data and parse JSON details
            $userModel = new \App\Models\User();
            $formattedLogs = [];
            
            foreach ($logs as $log) {
                // Parse JSON details field
                $details = null;
                if (!empty($log['details'])) {
                    $decoded = json_decode($log['details'], true);
                    $details = json_last_error() === JSON_ERROR_NONE ? $decoded : $log['details'];
                }
                
                // Get user data
                $userData = $userModel->select('firstName, lastName, email')
                    ->find($log['userId']);
                
                // Format timestamps to ISO 8601
                $createdAt = $log['createdAt'];
                if (!str_contains($createdAt, 'T')) {
                    $createdAt = str_replace(' ', 'T', $createdAt) . 'Z';
                }
                
                $formattedLogs[] = [
                    'id' => $log['id'],
                    'userId' => $log['userId'],
                    'action' => $log['action'],
                    'resource' => $log['resource'],
                    'resourceId' => $log['resourceId'],
                    'module' => $log['module'],
                    'details' => $details,
                    'ipAddress' => $log['ipAddress'],
                    'userAgent' => $log['userAgent'],
                    'createdAt' => $createdAt,
                    'user' => $userData
                ];
            }

            return $this->respond([
                'success' => true,
                'message' => 'Activity logs retrieved successfully',
                'data' => $formattedLogs
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AuditLog::getMyActivity - ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve activity',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
