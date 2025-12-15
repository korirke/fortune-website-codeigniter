<?php

namespace App\Models;

use CodeIgniter\Model;

class ActivityLog extends Model
{
    protected $table            = 'activity_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'userId',
        'action',
        'entity',
        'entityId',
        'details',
        'ipAddress',
        'userAgent',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
