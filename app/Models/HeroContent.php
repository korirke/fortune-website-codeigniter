<?php

namespace App\Models;

use CodeIgniter\Model;

class HeroContent extends Model
{
    protected $table            = 'hero_content';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'trustBadge',
        'mainHeading',
        'subHeading',
        'tagline',
        'description',
        'trustPoints',
        'primaryCtaText',
        'secondaryCtaText',
        'primaryCtaLink',
        'secondaryCtaLink',
        'phoneNumber',
        'chatWidgetUrl',
        'isActive',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
