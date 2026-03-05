<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBackupTables extends Migration
{
    public function up()
    {
        // Backup Logs Table
        $this->forge->addField([
            'id' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'file_name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'file_size' => [
                'type' => 'BIGINT',
                'null' => true,
                'comment' => 'File size in bytes',
            ],
            'backup_type' => [
                'type' => 'ENUM',
                'constraint' => ['manual', 'scheduled', 'auto'],
                'default' => 'manual',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['pending', 'completed', 'failed'],
                'default' => 'pending',
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'database_name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
            'tables_count' => [
                'type' => 'INT',
                'null' => true,
            ],
            'created_by' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'last_downloaded_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'download_count' => [
                'type' => 'INT',
                'default' => 0,
            ],
            'last_restored_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'restore_count' => [
                'type' => 'INT',
                'default' => 0,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->addKey('backup_type');
        $this->forge->addKey('created_at');
        $this->forge->createTable('backup_logs');

        // Backup Settings Table
        $this->forge->addField([
            'id' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'auto_backup_enabled' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],
            'backup_frequency' => [
                'type' => 'ENUM',
                'constraint' => ['hourly', 'daily', 'weekly', 'monthly'],
                'default' => 'daily',
            ],
            'backup_time' => [
                'type' => 'TIME',
                'default' => '02:00:00',
                'comment' => 'Time to run scheduled backups',
            ],
            'max_backups' => [
                'type' => 'INT',
                'default' => 30,
                'comment' => 'Maximum number of backups to keep',
            ],
            'cloud_storage_enabled' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],
            'cloud_storage_type' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'comment' => 's3, google_drive, dropbox, etc.',
            ],
            'cloud_storage_config' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'JSON configuration for cloud storage',
            ],
            'retention_days' => [
                'type' => 'INT',
                'default' => 90,
                'comment' => 'Days to keep backups',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('backup_settings');
    }

    public function down()
    {
        $this->forge->dropTable('backup_logs', true);
        $this->forge->dropTable('backup_settings', true);
    }
}