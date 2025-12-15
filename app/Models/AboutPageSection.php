<?php

namespace App\Models;

use CodeIgniter\Model;

class AboutPageSection extends Model
{
    protected $table            = 'about_page_sections';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'sectionKey',
        'sectionName',
        'content',
        'isActive',
        'sortOrder',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
