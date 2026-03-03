<?php

namespace App\Models;

use CodeIgniter\Model;

class BackupModel extends Model
{
    protected $table = 'backup_logs';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'id',
        'file_name',
        'file_size',
        'backup_type',
        'description',
        'status',
        'error_message',
        'database_name',
        'tables_count',
        'created_by',
        'created_at',
        'last_downloaded_at',
        'download_count',
        'last_restored_at',
        'restore_count',
    ];

    // Dates
    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = '';
    protected $deletedField = '';

    // Validation
    protected $validationRules = [
        'backup_type' => 'required|in_list[manual,scheduled,auto]',
        'status' => 'required|in_list[pending,completed,failed]',
    ];

    protected $validationMessages = [
        'backup_type' => [
            'required' => 'Backup type is required',
            'in_list' => 'Invalid backup type',
        ],
        'status' => [
            'required' => 'Status is required',
            'in_list' => 'Invalid status',
        ],
    ];

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