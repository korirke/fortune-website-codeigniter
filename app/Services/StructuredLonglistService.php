<?php

/**
 * Description: Questionnaire-based Structured Longlist builder.
 * columns are drawn from the job's screening questionnaire, and each
 * candidate's answers (Yes/No or open-ended text) are auto-populated from
 * the `application_question_answers` table.
 *
 */

namespace App\Services;

use Config\Database;

class StructuredLonglistService
{
    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build the structured-longlist data rows.
     *
     * @param string      $jobId       Required – job to export
     * @param string|null $companyId   Optional – scope to company
     * @param array       $questionIds Optional – specific question IDs to include.
     *                                 Empty = include ALL questions for this job.
     *
     * @return array{headers: string[], rows: array[], title: string, jobTitle: string}
     */
    public function build(
        string $jobId,
        ?string $companyId = null,
        array $questionIds = []
    ): array {
        $db = Database::connect();

        // ── 1. Fetch applications ────────────────────────────────────────
        $builder = $db->table('applications');
        $builder->select("
            applications.id              AS applicationId,
            applications.expectedSalary,
            applications.currentSalary,

            users.firstName,
            users.lastName,
            users.email,
            users.phone,

            candidate_profiles.id        AS candidateProfileId,

            jobs.title                   AS jobTitle,
            companies.name               AS companyName
        ");
        $builder->join('users', 'users.id = applications.candidateId', 'left');
        $builder->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left');
        $builder->join('jobs', 'jobs.id = applications.jobId', 'left');
        $builder->join('companies', 'companies.id = jobs.companyId', 'left');

        $builder->where('applications.jobId', $jobId);

        if ($companyId) {
            $builder->where('jobs.companyId', $companyId);
        }

        $apps = $builder->orderBy('applications.appliedAt', 'DESC')
            ->get()
            ->getResultArray();

        $jobTitle = $this->resolveJobTitle($db, $jobId);

        if (empty($apps)) {
            return [
                'headers' => [],
                'rows' => [],
                'title' => strtoupper($jobTitle),
                'jobTitle' => $jobTitle,
            ];
        }

        // ── 2. Fetch questionnaire questions for this job ────────────────
        $questions = $this->getQuestions($db, $jobId, $questionIds);

        // ── 3. Batch-fetch answers keyed by applicationId→questionId ─────
        $applicationIds = array_column($apps, 'applicationId');
        $answersMap = $this->getAnswersMap($db, $applicationIds);

        // ── 4. Batch-fetch education data for qualification + grad year ──
        $profileIds = array_values(
            array_unique(array_filter(array_column($apps, 'candidateProfileId')))
        );
        $educationMap = $this->getEducationMap($db, $profileIds);

        // ── 5. Build header row ──────────────────────────────────────────
        $headers = ['#', 'Candidate', 'Qualification'];

        foreach ($questions as $q) {
            $headers[] = $q['questionText'];
        }

        $headers = array_merge($headers, [
            'Email',
            'Graduation Year',
            'Contacts',
            'Expected Salary',
            'Notes',
        ]);

        // ── 6. Build data rows ───────────────────────────────────────────
        $rows = [];
        $rowNum = 1;

        foreach ($apps as $a) {
            $cid = $a['candidateProfileId'] ?? null;
            $hasProfile = !empty($cid);
            $appId = $a['applicationId'];

            $fullName = trim(($a['firstName'] ?? '') . ' ' . ($a['lastName'] ?? ''));
            $qualification = $hasProfile ? ($educationMap[$cid]['highest'] ?? '-') : '-';
            $gradYear = $hasProfile ? ($educationMap[$cid]['gradYear'] ?? '') : '';
            $email = $a['email'] ?? '';
            $phone = $a['phone'] ?? '';
            $expectedSalary = $a['expectedSalary'] ?? '';

            $row = [
                $rowNum,       // #
                $fullName,     // Candidate
                $qualification // Qualification
            ];

            // ── Question answer columns ──────────────────────────────────
            foreach ($questions as $q) {
                $qid = $q['id'];
                $type = $q['type'] ?? 'OPEN_ENDED';

                $answer = $answersMap[$appId][$qid] ?? null;

                if (!$answer) {
                    $row[] = '-';
                } elseif ($type === 'YES_NO') {
                    $bool = $answer['answerBool'];
                    if ($bool === null || $bool === '') {
                        $row[] = '-';
                    } elseif ((int) $bool === 1) {
                        $row[] = 'Yes';
                    } else {
                        $row[] = 'No';
                    }
                } else {
                    // OPEN_ENDED
                    $text = trim((string) ($answer['answerText'] ?? ''));
                    $row[] = $text !== '' ? $text : '-';
                }
            }

            $row[] = $email;
            $row[] = $gradYear;
            $row[] = $phone;
            $row[] = $expectedSalary;
            $row[] = ''; // Notes (blank)

            $rows[] = $row;
            $rowNum++;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'title' => strtoupper($jobTitle),
            'jobTitle' => $jobTitle,
            'questions' => $questions, 
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fetch questions for the job, optionally filtered to specific IDs.
     */
    private function getQuestions($db, string $jobId, array $questionIds): array
    {
        $builder = $db->table('job_questions')
            ->select('id, questionText, type, sortOrder')
            ->where('jobId', $jobId)
            ->orderBy('sortOrder', 'ASC');

        if (!empty($questionIds)) {
            $builder->whereIn('id', $questionIds);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Build a nested map:  answersMap[applicationId][questionId] = row
     */
    private function getAnswersMap($db, array $applicationIds): array
    {
        if (empty($applicationIds)) {
            return [];
        }

        $rows = $db->table('application_question_answers')
            ->select('applicationId, questionId, answerText, answerBool')
            ->whereIn('applicationId', $applicationIds)
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['applicationId']][$r['questionId']] = $r;
        }

        return $map;
    }

    /**
     * Resolve the human-readable job title.
     */
    private function resolveJobTitle($db, string $jobId): string
    {
        $job = $db->table('jobs')
            ->select('jobs.title, companies.name')
            ->join('companies', 'companies.id = jobs.companyId', 'left')
            ->where('jobs.id', $jobId)
            ->get()
            ->getRowArray();

        return $job ? trim($job['title'] ?? 'POSITION') : 'STRUCTURED LONGLIST';
    }

    /**
     * Returns per-profile:
     *   - 'highest'  => "BSc Actuarial Science"
     *   - 'gradYear' => "2023" | "ongoing"
     */
    private function getEducationMap($db, array $profileIds): array
    {
        if (empty($profileIds)) {
            return [];
        }

        $rows = $db->table('educations')
            ->select('candidateId, degree, fieldOfStudy, institution, endDate, isCurrent')
            ->whereIn('candidateId', $profileIds)
            ->orderBy('endDate', 'DESC')
            ->get()
            ->getResultArray();

        $priority = [
            'phd' => 6,
            'doctor' => 6,
            'master' => 5,
            'msc' => 5,
            'mba' => 5,
            'ma' => 5,
            'bachelor' => 4,
            'bsc' => 4,
            'bcom' => 4,
            'ba' => 4,
            'bba' => 4,
            'diploma' => 3,
            'higher diploma' => 3,
            'certificate' => 2,
        ];

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $degree = $r['degree'] ?? '';
            $field = $r['fieldOfStudy'] ?? '';
            $endDate = $r['endDate'] ?? '';
            $isCurrent = !empty($r['isCurrent']);

            if (!isset($map[$cid])) {
                $map[$cid] = ['highest' => '-', 'gradYear' => '', '_bestPri' => -1];
            }

            $degreeLower = strtolower($degree);
            $pri = 0;
            foreach ($priority as $keyword => $p) {
                if (strpos($degreeLower, $keyword) !== false) {
                    $pri = max($pri, $p);
                }
            }

            if ($pri > ($map[$cid]['_bestPri'] ?? -1)) {
                $map[$cid]['_bestPri'] = $pri;
                $map[$cid]['highest'] = trim($degree . ($field ? " $field" : ''));

                if ($isCurrent) {
                    $map[$cid]['gradYear'] = 'ongoing';
                } elseif ($endDate) {
                    try {
                        $map[$cid]['gradYear'] = date('Y', strtotime($endDate));
                    } catch (\Exception $e) {
                        $map[$cid]['gradYear'] = '';
                    }
                }
            }
        }

        foreach ($map as &$entry) {
            unset($entry['_bestPri']);
        }

        return $map;
    }
}