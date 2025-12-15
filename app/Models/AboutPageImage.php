<?php

namespace App\Models;

use CodeIgniter\Model;

class AboutPageImage extends Model
{
    protected $table            = 'about_page_images';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'sectionKey',
        'fieldKey',
        'imageUrl',
        'altText',
        'caption',
        'width',
        'height',
        'fileSize',
        'mimeType',
        'uploadedBy',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
