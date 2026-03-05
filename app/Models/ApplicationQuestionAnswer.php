<?php

namespace App\Models;

use CodeIgniter\Model;

class ApplicationQuestionAnswer extends Model
{
    protected $table            = 'application_question_answers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'applicationId',
        'jobId',
        'candidateId',
        'questionId',
        'answerText',
        'answerBool',
        'createdAt',
    ];

    protected $useTimestamps = false;
}
