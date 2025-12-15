<?php

namespace App\Models;

use CodeIgniter\Model;

class AboutPageVersion extends Model
{
    protected $table            = 'about_page_versions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'sectionKey',
        'version',
        'content',
        'changeNotes',
        'createdBy',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
