<?php

namespace App\Models;

use CodeIgniter\Model;

class Job extends Model
{
    protected $table            = 'jobs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'title',
        'slug',
        'description',
        'responsibilities',
        'requirements',
        'benefits',
        'niceToHave',
        'type',
        'experienceLevel',
        'location',
        'isRemote',
        'salaryType',
        'salaryMin',
        'salaryMax',
        'specificSalary',
        'currency',
        'status',
        'featured',
        'sponsored',
        'views',
        'applicationCount',
        'publishedAt',
        'expiresAt',
        'closedAt',
        'rejectedAt',
        'rejectionReason',
        'companyId',
        'postedById',
        'categoryId',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
