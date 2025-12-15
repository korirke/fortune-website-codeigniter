<?php

namespace App\Models;

use CodeIgniter\Model;

class EmployerProfile extends Model
{
    protected $table            = 'employer_profiles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'userId',
        'companyId',
        'title',
        'department',
        'canPostJobs',
        'canViewCVs',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
