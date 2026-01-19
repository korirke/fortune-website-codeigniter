<?php

namespace App\Models;

use CodeIgniter\Model;

class Testimonial extends Model
{
    protected $table = 'testimonials';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;

    protected $allowedFields = [
        'id',
        'name',
        'role',
        'company',
        'content',
        'rating',
        'avatar',
        'results',
        'service',
        'category',
        'isActive',
        'isFeatured',
        'position',
        'createdAt',
        'updatedAt',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'createdAt';
    protected $updatedField = 'updatedAt';
}
