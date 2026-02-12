<?php

namespace App\Models;

use CodeIgniter\Model;

class Education extends Model
{
    protected $table            = 'educations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields    = [
        'id',
        'candidateId',
        'degree',
        'degreeLevel',
        'fieldOfStudy',
        'institution',
        'location',
        'startDate',
        'endDate',
        'isCurrent',
        'grade',
        'description',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
