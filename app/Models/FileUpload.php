<?php

namespace App\Models;

use CodeIgniter\Model;

class FileUpload extends Model
{
    protected $table            = 'file_uploads';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'filename',
        'originalName',
        'mimetype',
        'size',
        'path',
        'url',
        'fileType',
        'uploadedBy',
        'tags',
        'description',
        'altText',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
