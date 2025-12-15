<?php

namespace App\Models;

use CodeIgniter\Model;

class ResumeVersion extends Model
{
    protected $table            = 'resume_versions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'candidateId',
        'version',
        'fileName',
        'fileUrl',
        'fileSize',
        'uploadedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'uploadedAt';
    protected $updatedField  = null;
}
