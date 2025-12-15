<?php

namespace App\Models;

use CodeIgniter\Model;

class Certification extends Model
{
    protected $table            = 'certifications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'candidateId',
        'name',
        'issuingOrg',
        'issueDate',
        'expiryDate',
        'credentialId',
        'credentialUrl',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
