<?php

namespace App\Models;

use CodeIgniter\Model;

class Company extends Model
{
    protected $table            = 'companies';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'slug',
        'description',
        'website',
        'email',
        'phone',
        'logo',
        'coverImage',
        'location',
        'industry',
        'companySize',
        'foundedYear',
        'status',
        'verified',
        'verifiedAt',
        'socialLinks',
        'benefits',
        'culture',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
