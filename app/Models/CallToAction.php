<?php

namespace App\Models;

use CodeIgniter\Model;

class CallToAction extends Model
{
    protected $table            = 'call_to_actions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'pageKey',
        'title',
        'description',
        'primaryText',
        'primaryLink',
        'secondaryText',
        'secondaryLink',
        'bgColor',
        'textColor',
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
