<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * JobApplicationConfig Model
 *
 * Stores per-job configuration for the candidate application form:
 * - How many referees are required
 * - Which education levels are required
 * - General / specific experience text descriptors
 * - Section ordering and description visibility
 */
class JobApplicationConfig extends Model
{
    protected $table            = 'job_application_config';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'jobId',
        'refereesRequired',
        'requiredEducationLevels',
        'generalExperienceText',
        'specificExperienceText',
        'showGeneralExperience',
        'showSpecificExperience',
        'sectionOrder',
        'showDescription',
        'createdAt',
        'updatedAt',
    ];

    protected $useTimestamps = false;
}