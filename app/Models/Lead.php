<?php

namespace App\Models;

use CodeIgniter\Model;

class Lead extends Model
{
    protected $table            = 'leads';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'leadType',
        'fullName',
        'email',
        'phone',
        'company',
        'serviceInterest',
        'projectDetails',
        'message',
        'source',
        'status',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
