<?php

namespace App\Models;

use CodeIgniter\Model;

class Experience extends Model
{
    protected $table            = 'experiences';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'candidateId',
        'title',
        'company',
        'location',
        'employmentType',
        'startDate',
        'endDate',
        'isCurrent',
        'description',
        'achievements',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
