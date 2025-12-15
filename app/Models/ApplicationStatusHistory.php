<?php

namespace App\Models;

use CodeIgniter\Model;

class ApplicationStatusHistory extends Model
{
    protected $table            = 'application_status_history';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'applicationId',
        'fromStatus',
        'toStatus',
        'changedBy',
        'reason',
        'changedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'changedAt';
    protected $updatedField  = null;
}
