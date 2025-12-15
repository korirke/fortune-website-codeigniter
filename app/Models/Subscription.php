<?php

namespace App\Models;

use CodeIgniter\Model;

class Subscription extends Model
{
    protected $table            = 'subscriptions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'status',
        'startDate',
        'endDate',
        'cancelledAt',
        'autoRenew',
        'jobPostingsUsed',
        'userId',
        'planId',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
