<?php

namespace App\Models;

use CodeIgniter\Model;

class CandidateProfile extends Model
{
    protected $table            = 'candidate_profiles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'userId',
        'title',
        'bio',
        'location',
        'phone',
        'currentCompany',
        'experienceYears',
        'expectedSalary',
        'currency',
        'openToWork',
        'availableFrom',
        'websiteUrl',
        'linkedinUrl',
        'githubUrl',
        'portfolioUrl',
        'resumeUrl',
        'resumeUpdatedAt',
        'profileViews',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
