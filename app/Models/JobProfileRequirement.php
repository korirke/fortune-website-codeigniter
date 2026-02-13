<?php

namespace App\Models;

use CodeIgniter\Model;

class JobProfileRequirement extends Model
{
    protected $table = 'job_profile_requirements';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'id',
        'jobId',
        'requirementKey',
        'isRequired',
        'createdAt',
    ];
    protected $useTimestamps = false;
}
