<?php

namespace App\Models;

use CodeIgniter\Model;

class ThemeConfig extends Model
{
    protected $table            = 'theme_config';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'primaryColor',
        'accentColor',
        'logoUrl',
        'companyName',
        'isActive',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
