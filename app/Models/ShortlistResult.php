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
        'id',
        'jobId',
        'applicationId',
        'candidateId',

        // System scores
        'totalScore',
        'educationScore',
        'experienceScore',
        'skillsScore',
        'clearanceScore',
        'professionalScore',

        // Manual scores (admin)
        'manualTotalScore',
        'manualEducationScore',
        'manualExperienceScore',
        'manualSkillsScore',
        'manualClearanceScore',
        'manualProfessionalScore',

        // Audit / scoring metadata
        'scoreSource',
        'scoredBy',
        'scoredAt',
        'auditFlag',

        // Disqualification override
        'overrideDisqualification',
        'overrideDisqualificationBy',
        'overrideDisqualificationAt',

        // Ranking
        'candidateRank',
        'percentile',

        // Analysis
        'matchedCriteria',
        'missedCriteria',
        'bonusCriteria',
        'hasAllMandatory',
        'hasDisqualifyingFactor',
        'disqualificationReasons',

        // HR annotations
        'hrNotes',
        'flaggedForReview',
        'reviewedBy',
        'reviewedAt',
        'internalRating',

        // Metadata
        'generatedAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'generatedAt';
    protected $updatedField = 'updatedAt';

    protected $beforeInsert = ['setId', 'setTimestamps', 'encodeJson', 'normalizeAuditFields'];
    protected $beforeUpdate = ['setTimestamps', 'encodeJson', 'normalizeAuditFields'];
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
            if (!isset($data['data'][$field])) {
                $data['data'][$field] = json_encode([]);
            }
        }

        return $data;
    }

    protected function normalizeAuditFields(array $data)
    {
        $manualFields = [
            'manualTotalScore',
            'manualEducationScore',
            'manualExperienceScore',
            'manualSkillsScore',
            'manualClearanceScore',
            'manualProfessionalScore',
        ];

        $hasManual = false;
        foreach ($manualFields as $f) {
            if (array_key_exists($f, $data['data']) && $data['data'][$f] !== null && $data['data'][$f] !== '') {
                $hasManual = true;
                break;
            }
        }

        $overrideDisq = !empty($data['data']['overrideDisqualification']);

        $data['data']['auditFlag'] = ($hasManual || $overrideDisq) ? 1 : 0;
        $data['data']['scoreSource'] = $hasManual ? 'MANUAL' : 'SYSTEM';

        return $data;
    }

    protected function decodeJson(array $data)
    {
        $jsonFields = ['matchedCriteria', 'missedCriteria', 'bonusCriteria', 'disqualificationReasons'];

        if (isset($data['data'])) {
            $record = &$data['data'];
            $this->decodeJsonRecord($record, $jsonFields);
            return $data;
        }

        if (isset($data[0])) {
            foreach ($data as &$record) {
                $this->decodeJsonRecord($record, $jsonFields);
            }
            return $data;
        }

        return $data;
    }

    private function decodeJsonRecord(&$record, $jsonFields)
    {
        foreach ($jsonFields as $field) {
            if (isset($record[$field]) && is_string($record[$field])) {
                $decoded = json_decode($record[$field], true);
                $record[$field] = $decoded ?? [];
            }
            if (!isset($record[$field])) {
                $record[$field] = [];
            }
        }
    }
}