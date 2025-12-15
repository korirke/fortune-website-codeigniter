<?php

namespace App\Models;

use CodeIgniter\Model;

class NavItem extends Model
{
    protected $table            = 'nav_items';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'key',
        'href',
        'position',
        'isActive',
        'hasDropdown',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
    
    // Allow model to work even if table doesn't exist
    protected bool $allowEmptyInserts = false;
}
