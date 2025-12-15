<?php

namespace App\Models;

use CodeIgniter\Model;

class Service extends Model
{
    protected $table            = 'services';
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
        'shortDesc',
        'icon',
        'color',
        'category',
        'features',
        'benefits',
        'processSteps',
        'complianceItems',
        'imageUrl',
        'heroImageUrl',
        'processImageUrl',
        'complianceImageUrl',
        'onQuote',
        'hasProcess',
        'hasCompliance',
        'isActive',
        'isFeatured',
        'isPopular',
        'position',
        'price',
        'buttonText',
        'buttonLink',
        'metadata',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
