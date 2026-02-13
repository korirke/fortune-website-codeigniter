<?php

namespace App\Controllers\Jobs;

use App\Controllers\BaseController;
use App\Services\ProfileRequirementService;
use App\Traits\NormalizedResponseTrait;

class JobProfileEligibility extends BaseController
{
    use NormalizedResponseTrait;

    public function get($jobId = null)
    {
        $user = $this->request->user ?? null;
        if (!$user) return $this->fail('Unauthorized', 401);

        if (($user->role ?? null) !== 'CANDIDATE') {
            return $this->fail('Forbidden', 403);
        }

        if (!$jobId) return $this->fail('Job ID is required', 400);

        $svc = new ProfileRequirementService();
        $result = $svc->evaluateCandidateForJob($user->id, $jobId);

        return $this->respond([
            'success' => true,
            'message' => 'Eligibility evaluated',
            'data' => $result,
        ]);
    }
}
