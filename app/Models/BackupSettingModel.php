<?php

namespace App\Models;

use CodeIgniter\Model;

class BackupSettingModel extends Model
{
    protected $table = 'backup_settings';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'id',
        'auto_backup_enabled',
        'backup_frequency',
        'backup_time',
        'max_backups',
        'cloud_storage_enabled',
        'cloud_storage_type',
        'cloud_storage_config',
        'retention_days',
        'created_at',
        'updated_at',
    ];

    // Dates
    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = '';

    // Validation
    protected $validationRules = [
        'backup_frequency' => 'permit_empty|in_list[hourly,daily,weekly,monthly]',
        'max_backups' => 'permit_empty|integer|greater_than[0]',
        'retention_days' => 'permit_empty|integer|greater_than[0]',
    ];

    protected $validationMessages = [];
    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert = [];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];
}