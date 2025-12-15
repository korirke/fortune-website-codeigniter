<?php

namespace App\Models;

use CodeIgniter\Model;

class PageContent extends Model
{
    protected $table            = 'page_contents';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'pageKey',
        'title',
        'subtitle',
        'description',
        'heroTitle',
        'heroSubtitle',
        'heroDescription',
        'heroImageUrl',
        'processImageUrl',
        'complianceImageUrl',
        'ctaText',
        'ctaLink',
        'ctaSecondaryText',
        'ctaSecondaryLink',
        'metadata',
        'isActive',
        'keywords',
        'metaDescription',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
