<?php

namespace App\Services;

use App\Models\BackupModel;
use App\Models\BackupSettingModel;
use ZipArchive;

class BackupService
{
    private BackupModel $backupModel;
    private BackupSettingModel $settingModel;

    public function __construct()
    {
        $this->backupModel = new BackupModel();
        $this->settingModel = new BackupSettingModel();
    }

    /**
     * Create a database backup with uploaded files
     */
    public function createBackup(array $data): array
    {
        try {
            $db = \Config\Database::connect();
            $dbConfig = [
                'hostname' => $db->hostname,
                'username' => $db->username,
                'password' => $db->password,
                'database' => $db->database,
                'port' => $db->port ?? 3306,
            ];

            $backupDir = WRITEPATH . 'backups/';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $timestamp = date('Y-m-d_H-i-s');
            $fileName = "backup_{$dbConfig['database']}_{$timestamp}.sql";
            $filePath = $backupDir . $fileName;

            // Get mysqldump path
            $mysqldumpPath = $this->getMysqldumpPath();

            // Create mysqldump command
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $command = sprintf(
                    '"%s" --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers %s > "%s" 2>&1',
                    $mysqldumpPath,
                    $dbConfig['hostname'],
                    $dbConfig['port'],
                    $dbConfig['username'],
                    $dbConfig['password'],
                    $dbConfig['database'],
                    $filePath
                );
            } else {
                $command = sprintf(
                    '%s --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers %s > %s 2>&1',
                    escapeshellcmd($mysqldumpPath),
                    escapeshellarg($dbConfig['hostname']),
                    $dbConfig['port'],
                    escapeshellarg($dbConfig['username']),
                    escapeshellarg($dbConfig['password']),
                    escapeshellarg($dbConfig['database']),
                    escapeshellarg($filePath)
                );
            }

            // Execute backup
            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($filePath)) {
                $errorMsg = 'Backup failed: ' . implode("\n", $output);
                log_message('error', $errorMsg);
                throw new \Exception($errorMsg);
            }

            if (filesize($filePath) == 0) {
                throw new \Exception('Backup file is empty');
            }

            // Create ZIP archive with SQL dump and uploaded files
            $zipFileName = "backup_{$dbConfig['database']}_{$timestamp}.zip";
            $zipFilePath = $backupDir . $zipFileName;

            $zip = new ZipArchive();
            if ($zip->open($zipFilePath, ZipArchive::CREATE) !== TRUE) {
                throw new \Exception('Failed to create backup archive');
            }

            // Add SQL dump
            $zip->addFile($filePath, $fileName);

            // Add uploaded files (resumes, candidate files, etc.)
            $uploadsDir = WRITEPATH . 'uploads/';
            if (is_dir($uploadsDir)) {
                $this->addDirectoryToZip($zip, $uploadsDir, 'uploads/');
            }

            $zip->close();

            // Remove uncompressed SQL file
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $fileSize = filesize($zipFilePath);

            // Save backup record
            $backupId = 'backup_' . uniqid();
            $backupData = [
                'id' => $backupId,
                'file_name' => $zipFileName,
                'file_size' => $fileSize,
                'backup_type' => $data['backup_type'] ?? 'manual',
                'description' => $data['description'] ?? '',
                'status' => 'completed',
                'created_by' => $data['created_by'] ?? null,
                'database_name' => $dbConfig['database'],
                'tables_count' => $this->getTablesCount($db),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            $this->backupModel->insert($backupData);

            return [
                'success' => true,
                'message' => 'Backup created successfully',
                'data' => $backupData,
            ];
        } catch (\Exception $e) {
            log_message('error', 'Backup creation failed: ' . $e->getMessage());

            $backupId = 'backup_' . uniqid();
            $this->backupModel->insert([
                'id' => $backupId,
                'backup_type' => $data['backup_type'] ?? 'manual',
                'description' => $data['description'] ?? '',
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'created_by' => $data['created_by'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Add directory to ZIP recursively
     */
    private function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipPath): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = $zipPath . substr($filePath, strlen($dir));
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    /**
     * Restore a backup
     */
    public function restoreBackup(array $backup, string $userId): array
    {
        try {
            $filePath = WRITEPATH . 'backups/' . $backup['file_name'];

            if (!file_exists($filePath)) {
                throw new \Exception('Backup file not found');
            }

            $db = \Config\Database::connect();
            $dbConfig = [
                'hostname' => $db->hostname,
                'username' => $db->username,
                'password' => $db->password,
                'database' => $db->database,
                'port' => $db->port ?? 3306,
            ];

            // Extract ZIP archive
            $extractDir = WRITEPATH . 'backups/temp_restore_' . uniqid() . '/';
            if (!is_dir($extractDir)) {
                mkdir($extractDir, 0755, true);
            }

            $zip = new ZipArchive();
            if ($zip->open($filePath) !== TRUE) {
                throw new \Exception('Failed to open backup archive');
            }
            $zip->extractTo($extractDir);
            $zip->close();

            // Find SQL file
            $sqlFiles = glob($extractDir . '*.sql');
            if (empty($sqlFiles)) {
                throw new \Exception('No SQL dump found in backup');
            }
            $sqlFilePath = $sqlFiles[0];

            // Restore database
            $mysqlPath = $this->getMysqlPath();

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $command = sprintf(
                    '"%s" --host=%s --port=%d --user=%s --password=%s %s < "%s" 2>&1',
                    $mysqlPath,
                    $dbConfig['hostname'],
                    $dbConfig['port'],
                    $dbConfig['username'],
                    $dbConfig['password'],
                    $dbConfig['database'],
                    $sqlFilePath
                );
            } else {
                $command = sprintf(
                    '%s --host=%s --port=%d --user=%s --password=%s %s < %s 2>&1',
                    escapeshellcmd($mysqlPath),
                    escapeshellarg($dbConfig['hostname']),
                    $dbConfig['port'],
                    escapeshellarg($dbConfig['username']),
                    escapeshellarg($dbConfig['password']),
                    escapeshellarg($dbConfig['database']),
                    escapeshellarg($sqlFilePath)
                );
            }

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception('Restore failed: ' . implode("\n", $output));
            }

            // Restore uploaded files if exists
            $uploadsRestoreDir = $extractDir . 'uploads/';
            if (is_dir($uploadsRestoreDir)) {
                $uploadsDir = WRITEPATH . 'uploads/';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }

                // Copy files recursively
                $this->copyDirectory($uploadsRestoreDir, $uploadsDir);
            }

            // Clean up temp directory
            $this->deleteDirectory($extractDir);

            // Update backup record
            $this->backupModel->update($backup['id'], [
                'last_restored_at' => date('Y-m-d H:i:s'),
                'restore_count' => ($backup['restore_count'] ?? 0) + 1,
            ]);

            log_message('info', "Database restored from backup {$backup['id']} by user {$userId}");

            return [
                'success' => true,
                'message' => 'Database and files restored successfully',
                'data' => [
                    'backup_id' => $backup['id'],
                    'restored_at' => date('Y-m-d H:i:s'),
                    'restored_by' => $userId,
                ],
            ];
        } catch (\Exception $e) {
            log_message('error', 'Restore failed: ' . $e->getMessage());

            // Clean up on error
            if (isset($extractDir) && is_dir($extractDir)) {
                $this->deleteDirectory($extractDir);
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectory(string $src, string $dst): void
    {
        $dir = opendir($src);
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->copyDirectory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * Delete directory recursively
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Get backup settings
     */
    public function getSettings(): array
    {
        $settings = $this->settingModel->first();

        if (!$settings) {
            return [
                'auto_backup_enabled' => false,
                'backup_frequency' => 'daily',
                'backup_time' => '02:00',
                'max_backups' => 30,
                'cloud_storage_enabled' => false,
                'cloud_storage_type' => null,
                'retention_days' => 90,
            ];
        }

        return $settings;
    }

    /**
     * Update backup settings
     */
    public function updateSettings(array $settings): array
    {
        try {
            $existing = $this->settingModel->first();

            $data = [
                'auto_backup_enabled' => $settings['auto_backup_enabled'] ?? false,
                'backup_frequency' => $settings['backup_frequency'] ?? 'daily',
                'backup_time' => $settings['backup_time'] ?? '02:00',
                'max_backups' => $settings['max_backups'] ?? 30,
                'cloud_storage_enabled' => $settings['cloud_storage_enabled'] ?? false,
                'cloud_storage_type' => $settings['cloud_storage_type'] ?? null,
                'cloud_storage_config' => isset($settings['cloud_storage_config'])
                    ? json_encode($settings['cloud_storage_config'])
                    : null,
                'retention_days' => $settings['retention_days'] ?? 90,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($existing) {
                $this->settingModel->update($existing['id'], $data);
            } else {
                $data['id'] = 'setting_' . uniqid();
                $data['created_at'] = date('Y-m-d H:i:s');
                $this->settingModel->insert($data);
            }

            if ($data['auto_backup_enabled']) {
                $this->updateCronJob($data['backup_frequency'], $data['backup_time']);
            }

            return [
                'success' => true,
                'message' => 'Settings updated successfully',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get backup statistics
     */
    public function getStatistics(): array
    {
        $db = \Config\Database::connect();

        $total = $this->backupModel->countAll();
        $completed = $this->backupModel->where('status', 'completed')->countAllResults();
        $failed = $this->backupModel->where('status', 'failed')->countAllResults();

        $result = $db->query("SELECT SUM(file_size) as total_size FROM backup_logs WHERE status = 'completed'");
        $totalSize = $result->getRow()->total_size ?? 0;

        $lastBackup = $this->backupModel
            ->where('status', 'completed')
            ->orderBy('created_at', 'DESC')
            ->first();

        $backupDir = WRITEPATH . 'backups/';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $diskSpace = 0;
        $diskTotal = 0;
        $diskUsagePercent = 0;

        try {
            if (is_dir($backupDir) && is_readable($backupDir)) {
                $diskSpace = @disk_free_space($backupDir);
                $diskTotal = @disk_total_space($backupDir);

                if ($diskTotal > 0 && $diskSpace !== false) {
                    $diskUsagePercent = round((($diskTotal - $diskSpace) / $diskTotal) * 100, 2);
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Disk space calculation error: ' . $e->getMessage());
        }

        return [
            'total_backups' => $total,
            'completed_backups' => $completed,
            'failed_backups' => $failed,
            'total_size' => (int) $totalSize,
            'total_size_formatted' => $this->formatBytes((int) $totalSize),
            'last_backup' => $lastBackup,
            'disk_space_free' => (int) $diskSpace,
            'disk_space_free_formatted' => $this->formatBytes((int) $diskSpace),
            'disk_space_total' => (int) $diskTotal,
            'disk_space_total_formatted' => $this->formatBytes((int) $diskTotal),
            'disk_usage_percent' => $diskUsagePercent,
        ];
    }

    /**
     * Test backup configuration
     */
    public function testConfiguration(): array
    {
        try {
            $tests = [];

            $mysqldumpPath = $this->getMysqldumpPath();

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                exec("\"{$mysqldumpPath}\" --version 2>&1", $output, $returnCode);
            } else {
                exec(escapeshellcmd($mysqldumpPath) . " --version 2>&1", $output, $returnCode);
            }

            $tests['mysqldump'] = [
                'success' => $returnCode === 0,
                'path' => $mysqldumpPath,
                'version' => $returnCode === 0 ? $output[0] : 'Not available',
            ];

            $mysqlPath = $this->getMysqlPath();

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                exec("\"{$mysqlPath}\" --version 2>&1", $output, $returnCode);
            } else {
                exec(escapeshellcmd($mysqlPath) . " --version 2>&1", $output, $returnCode);
            }

            $tests['mysql'] = [
                'success' => $returnCode === 0,
                'path' => $mysqlPath,
                'version' => $returnCode === 0 ? $output[0] : 'Not available',
            ];

            try {
                $db = \Config\Database::connect();
                $tests['database'] = [
                    'success' => $db->connID !== false,
                    'database' => $db->database,
                    'hostname' => $db->hostname,
                ];
            } catch (\Exception $e) {
                $tests['database'] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }

            $backupDir = WRITEPATH . 'backups/';
            $tests['backup_directory'] = [
                'success' => is_writable($backupDir),
                'path' => $backupDir,
                'writable' => is_writable($backupDir),
                'exists' => is_dir($backupDir),
            ];

            $allSuccess = $tests['mysqldump']['success'] &&
                $tests['mysql']['success'] &&
                $tests['database']['success'] &&
                $tests['backup_directory']['success'];

            return [
                'success' => $allSuccess,
                'message' => $allSuccess ? 'All tests passed' : 'Some tests failed',
                'data' => $tests,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function getMysqldumpPath(): string
    {
        $customPath = getenv('MYSQLDUMP_PATH');
        if ($customPath && file_exists($customPath)) {
            return $customPath;
        }

        $paths = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump.exe',
            'C:/Program Files/MySQL/MySQL Server 5.7/bin/mysqldump.exe',
            'C:/xampp/mysql/bin/mysqldump.exe',
            'C:/wamp64/bin/mysql/mysql8.0.27/bin/mysqldump.exe',
            'C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('where mysqldump 2>nul', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                return $output[0];
            }
        } else {
            exec('which mysqldump 2>/dev/null', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                return $output[0];
            }
        }

        return 'mysqldump';
    }

    private function getMysqlPath(): string
    {
        $customPath = getenv('MYSQL_PATH');
        if ($customPath && file_exists($customPath)) {
            return $customPath;
        }

        $paths = [
            'mysql',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            '/usr/local/mysql/bin/mysql',
            'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe',
            'C:/Program Files/MySQL/MySQL Server 5.7/bin/mysql.exe',
            'C:/xampp/mysql/bin/mysql.exe',
            'C:/wamp64/bin/mysql/mysql8.0.27/bin/mysql.exe',
            'C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('where mysql 2>nul', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                return $output[0];
            }
        } else {
            exec('which mysql 2>/dev/null', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                return $output[0];
            }
        }

        return 'mysql';
    }

    private function getTablesCount($db): int
    {
        $tables = $db->listTables();
        return count($tables);
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function updateCronJob(string $frequency, string $time): void
    {
        log_message('info', "Cron job should be updated: frequency=$frequency, time=$time");
    }
}
