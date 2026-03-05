<?php

namespace App\Models;

use CodeIgniter\Model;

class JobQuestionnaire extends Model
{
    protected $table            = 'job_questionnaires';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'jobId',
        'title',
        'description',
        'isActive',
        'createdAt',
        'updatedAt',
    ];

    protected $useTimestamps = false;
}
