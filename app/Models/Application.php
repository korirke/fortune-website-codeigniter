<?php

namespace App\Models;

use CodeIgniter\Model;

class Application extends Model
{
    protected $table            = 'applications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'jobId',
        'candidateId',
        'coverLetter',
        'resumeUrl',
        'portfolioUrl',
        'expectedSalary',
        'privacyConsent',
        'availableStartDate',
        'status',
        'rating',
        'internalNotes',
        'appliedAt',
        'reviewedAt',
        'reviewedBy',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'appliedAt';
    protected $updatedField  = 'updatedAt';
}
