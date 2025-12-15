<?php

namespace App\Models;

use CodeIgniter\Model;

class Faq extends Model
{
    protected $table            = 'faqs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'question',
        'answer',
        'categoryId',
        'isPopular',
        'tags',
        'views',
        'helpfulCount',
        'position',
        'isActive',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
