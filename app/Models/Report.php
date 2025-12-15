<?php

namespace App\Models;

use CodeIgniter\Model;

class Report extends Model
{
    protected $table            = 'reports';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'type',
        'entityId',
        'entityType',
        'reason',
        'description',
        'status',
        'resolvedBy',
        'resolvedAt',
        'resolution',
        'reportedById',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
