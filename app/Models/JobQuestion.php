<?php

namespace App\Models;

use CodeIgniter\Model;

class JobQuestion extends Model
{
    protected $table            = 'job_questions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'jobId',
        'questionnaireId',
        'questionText',
        'type',
        'isRequired',
        'placeholder',
        'sortOrder',
        'createdAt',
        'updatedAt',
    ];

    protected $useTimestamps = false;
}
