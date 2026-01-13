<?php

namespace App\Models;

use CodeIgniter\Model;

class InterviewHistory extends Model
{
    protected $table = 'interview_history';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $allowedFields = [
        'id',
        'interviewId',
        'fromStatus',
        'toStatus',
        'changedBy',
        'reason',
        'notes',
        'changedAt'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'changedAt';
    protected $updatedField = '';

    protected $beforeInsert = ['generateId'];

    protected function generateId(array $data)
    {
        if (!isset($data['data']['id'])) {
            $data['data']['id'] = uniqid('ih_');
        }
        return $data;
    }
}