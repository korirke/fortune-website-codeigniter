<?php

namespace App\Models;

use CodeIgniter\Model;

class ShortlistResult extends Model
{
    protected $table = 'shortlist_results';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id', 'jobId', 'applicationId', 'candidateId',
        'totalScore', 'educationScore', 'experienceScore', 'skillsScore',
        'clearanceScore', 'professionalScore',
        'candidateRank', 'percentile',
        'matchedCriteria', 'missedCriteria', 'bonusCriteria',
        'hasAllMandatory', 'hasDisqualifyingFactor', 'disqualificationReasons',
        'hrNotes', 'flaggedForReview', 'reviewedBy', 'reviewedAt', 'internalRating',
        'generatedAt', 'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'generatedAt';
    protected $updatedField = 'updatedAt';

    protected $beforeInsert = ['setId', 'setTimestamps', 'encodeJson'];
    protected $beforeUpdate = ['setTimestamps', 'encodeJson'];
    protected $afterFind = ['decodeJson'];

    protected function setId(array $data)
    {
        if (!isset($data['data']['id'])) {
            $data['data']['id'] = uniqid('shortlist_');
        }
        return $data;
    }

    protected function setTimestamps(array $data)
    {
        $now = date('Y-m-d H:i:s');
        if (!isset($data['data']['generatedAt'])) {
            $data['data']['generatedAt'] = $now;
        }
        $data['data']['updatedAt'] = $now;
        return $data;
    }

    protected function encodeJson(array $data)
    {
        $jsonFields = ['matchedCriteria', 'missedCriteria', 'bonusCriteria', 'disqualificationReasons'];

        foreach ($jsonFields as $field) {
            if (isset($data['data'][$field]) && is_array($data['data'][$field])) {
                $data['data'][$field] = json_encode($data['data'][$field]);
            }
        }

        return $data;
    }

    protected function decodeJson(array $data)
    {
        $jsonFields = ['matchedCriteria', 'missedCriteria', 'bonusCriteria', 'disqualificationReasons'];

        if (isset($data['data'])) {
            $record = &$data['data'];
        } elseif (isset($data[0])) {
            foreach ($data as &$record) {
                $this->decodeJsonRecord($record, $jsonFields);
            }
            return $data;
        } else {
            $record = &$data;
        }

        $this->decodeJsonRecord($record, $jsonFields);
        return $data;
    }

    private function decodeJsonRecord(&$record, $jsonFields)
    {
        foreach ($jsonFields as $field) {
            if (isset($record[$field]) && is_string($record[$field])) {
                $decoded = json_decode($record[$field], true);
                $record[$field] = $decoded ?? [];
            }
        }
    }
}