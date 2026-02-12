<?php

namespace App\Models;

use CodeIgniter\Model;

class Experience extends Model
{
    protected $table = 'experiences';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;

    protected $allowedFields = [
        'id',
        'candidateId',
        'title',
        'company',
        'location',
        'employmentType',
        'startDate',
        'endDate',
        'isCurrent',
        'description',
        'achievements',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'createdAt';
    protected $updatedField = 'updatedAt';

    // register callbacks
    protected $afterInsert = ['afterInsert'];
    protected $afterUpdate = ['afterUpdate'];
    protected $beforeDelete = ['beforeDeleteCaptureCandidate'];
    protected $afterDelete = ['afterDelete'];

    /**
     * Store candidateIds before delete so afterDelete can recalc.
     * (Because afterDelete usually doesn't have row data.)
     */
    protected array $deletedCandidateIds = [];

    /**
     * BEFORE DELETE: capture candidateId(s)
     */
    protected function beforeDeleteCaptureCandidate(array $data)
    {
        $this->deletedCandidateIds = [];

        $ids = $data['id'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        if (!empty($ids)) {
            $rows = $this->select('id, candidateId')
                ->whereIn('id', $ids)
                ->findAll();

            foreach ($rows as $r) {
                if (!empty($r['candidateId'])) {
                    $this->deletedCandidateIds[] = $r['candidateId'];
                }
            }

            $this->deletedCandidateIds = array_values(array_unique($this->deletedCandidateIds));
        }

        return $data;
    }

    /**
     * After INSERT: Recalculate experience
     */
    protected function afterInsert(array $data)
    {
        $candidateId = $data['data']['candidateId'] ?? null;
        $this->triggerRecalculation($candidateId);
        return $data;
    }

    /**
     * After UPDATE: Recalculate experience
     */
    protected function afterUpdate(array $data)
    {
        $ids = $data['id'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        // Recalc for each updated row (safe)
        foreach ($ids as $id) {
            $exp = $this->select('candidateId')->find($id);
            if ($exp && !empty($exp['candidateId'])) {
                $this->triggerRecalculation($exp['candidateId']);
            }
        }

        return $data;
    }

    /**
     * After DELETE: Recalculate experience
     */
    protected function afterDelete(array $data)
    {
        foreach ($this->deletedCandidateIds as $candidateId) {
            $this->triggerRecalculation($candidateId);
        }

        // reset
        $this->deletedCandidateIds = [];

        return $data;
    }

    /**
     * Trigger experience recalculation
     */
    private function triggerRecalculation($candidateId): void
    {
        if (!$candidateId) {
            return;
        }

        try {
            $profileModel = new \App\Models\CandidateProfile();
            $profileModel->recalculateExperience($candidateId);
        } catch (\Exception $e) {
            log_message('error', 'Failed to recalculate experience: ' . $e->getMessage());
        }
    }
}