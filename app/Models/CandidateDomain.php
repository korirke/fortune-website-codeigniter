<?php

namespace App\Models;

use CodeIgniter\Model;

class CandidateDomain extends Model
{
    protected $table            = 'candidate_domains';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'candidateId',
        'domainId',
        'isPrimary',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
