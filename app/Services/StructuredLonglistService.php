<?php

/**
 * Description: New standalone service that builds "structured longlist" rows
 * using the template format (Candidate, Qualification, dynamic
 * experience columns, Email, Graduation Year, Contacts, Expected Salary, Notes).
 *
 * The admin supplies experienceColumns (e.g. "Diploma in Insurance") and the
 * service auto-searches each candidate's education, certifications, courses,
 * and work-experience records for keyword matches, producing Yes / No values.
 *
 * extraColumns allows appending arbitrary blank columns for manual annotation.
 * A "Notes" column is always appended as the final column.
 *
 */

namespace App\Services;

use Config\Database;

class StructuredLonglistService
{
    // ─────────────────────────────────────────────────────────────────────
    // Words ignored when tokenising an experience-column label for search.
    // ─────────────────────────────────────────────────────────────────────
    private const STOP_WORDS = [
        'in', 'of', 'the', 'a', 'an', 'and', 'or', 'for', 'with', 'from',
        'to', 'at', 'by', 'on', 'is', 'are', 'was', 'were', 'not', 'no',
        'yes', 'yrs', 'yr', 'years', 'year', 'months', 'month',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build the structured-longlist data rows.
     *
     * @param string      $jobId             Required – job to export
     * @param string|null $companyId          Optional – scope to company
     * @param array       $experienceColumns  Dynamic column labels (searched)
     * @param array       $extraColumns       Extra blank columns appended
     *
     * @return array{headers: string[], rows: array[]}
     */
    public function build(
        string $jobId,
        ?string $companyId = null,
        array $experienceColumns = [],
        array $extraColumns = []
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

        if (empty($apps)) {
            return ['headers' => [], 'rows' => [], 'title' => $this->resolveTitle($db, $jobId)];
        }

        // ── 2. Collect profile IDs and batch-fetch helper data ───────────
        $profileIds = array_values(
            array_unique(array_filter(array_column($apps, 'candidateProfileId')))
        );

        $educationMap     = $this->getEducationMap($db, $profileIds);
        $certificationMap = $this->getCertificationMap($db, $profileIds);
        $courseMap         = $this->getCourseMap($db, $profileIds);
        $experienceMap    = $this->getExperienceTextMap($db, $profileIds);

        // ── 3. Tokenise experience-column labels once ────────────────────
        $tokenSets = [];
        foreach ($experienceColumns as $label) {
            $tokenSets[] = $this->tokenise($label);
        }

        // ── 4. Build header row ──────────────────────────────────────────
        $headers = ['Candidate', 'Qualification'];
        foreach ($experienceColumns as $label) {
            $headers[] = $label;
        }
        $headers = array_merge($headers, ['Email', 'Graduation year', 'Contacts', 'Expected salary']);
        foreach ($extraColumns as $extra) {
            $headers[] = $extra;
        }
        $headers[] = 'Notes';

        // ── 5. Build data rows ───────────────────────────────────────────
        $rows = [];
        foreach ($apps as $a) {
            $cid        = $a['candidateProfileId'] ?? null;
            $hasProfile = !empty($cid);

            $fullName       = trim(($a['firstName'] ?? '') . ' ' . ($a['lastName'] ?? ''));
            $qualification  = $hasProfile ? ($educationMap[$cid]['highest'] ?? '-') : '-';
            $gradYear       = $hasProfile ? ($educationMap[$cid]['gradYear'] ?? '') : '';
            $email          = $a['email'] ?? '';
            $phone          = $a['phone'] ?? '';
            $expectedSalary = $a['expectedSalary'] ?? '';

            // Build the searchable corpus for this candidate (once per candidate)
            $corpus = $hasProfile
                ? $this->buildCorpus($cid, $educationMap, $certificationMap, $courseMap, $experienceMap)
                : '';

            $row = [$fullName, $qualification];

            // Dynamic experience columns
            foreach ($tokenSets as $tokens) {
                $row[] = $this->matchTokens($tokens, $corpus);
            }

            $row[] = $email;
            $row[] = $gradYear;
            $row[] = $phone;
            $row[] = $expectedSalary;

            // Extra blank columns
            foreach ($extraColumns as $_) {
                $row[] = '';
            }

            // Notes (always blank)
            $row[] = '';

            $rows[] = $row;
        }

        return [
            'headers' => $headers,
            'rows'    => $rows,
            'title'   => $this->resolveTitle($db, $jobId),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // KEYWORD MATCHING
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tokenise a label string into meaningful lowercase keywords.
     */
    private function tokenise(string $label): array
    {
        // Remove numbers followed by dash/range indicators (e.g. "2-3")
        $clean = preg_replace('/\d+\s*-\s*\d+/', '', $label);

        // Split on non-alpha chars
        $words = preg_split('/[^a-zA-Z]+/', strtolower($clean));
        $words = array_filter($words, function ($w) {
            return strlen($w) >= 2 && !in_array($w, self::STOP_WORDS, true);
        });

        return array_values($words);
    }

    /**
     * Check whether ALL tokens appear somewhere in the corpus.
     * Returns "Yes" or "No".
     */
    private function matchTokens(array $tokens, string $corpus): string
    {
        if (empty($tokens) || $corpus === '') {
            return 'No';
        }

        $corpusLower = strtolower($corpus);

        foreach ($tokens as $token) {
            if (strpos($corpusLower, $token) === false) {
                return 'No';
            }
        }

        return 'Yes';
    }

    /**
     * Build a single searchable text blob for a candidate.
     */
    private function buildCorpus(
        string $cid,
        array $educationMap,
        array $certificationMap,
        array $courseMap,
        array $experienceMap
    ): string {
        $parts = [];

        if (isset($educationMap[$cid]['raw'])) {
            $parts[] = $educationMap[$cid]['raw'];
        }
        if (isset($certificationMap[$cid])) {
            $parts[] = $certificationMap[$cid];
        }
        if (isset($courseMap[$cid])) {
            $parts[] = $courseMap[$cid];
        }
        if (isset($experienceMap[$cid])) {
            $parts[] = $experienceMap[$cid];
        }

        return implode(' ', $parts);
    }

    // ─────────────────────────────────────────────────────────────────────
    // TITLE RESOLVER
    // ─────────────────────────────────────────────────────────────────────

    private function resolveTitle($db, string $jobId): string
    {
        $job = $db->table('jobs')
            ->select('jobs.title, companies.name')
            ->join('companies', 'companies.id = jobs.companyId', 'left')
            ->where('jobs.id', $jobId)
            ->get()
            ->getRowArray();

        if ($job) {
            return strtoupper(trim(($job['title'] ?? 'POSITION')));
        }

        return 'STRUCTURED LONGLIST';
    }

    // ─────────────────────────────────────────────────────────────────────
    // BATCH-FETCH MAPS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Returns per-profile:
     *   - 'highest' => "BSc Actuarial Science" (highest degree text)
     *   - 'gradYear' => "2023" | "ongoing"
     *   - 'raw' => concatenated searchable text of ALL education records
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

        // Degree-level priority (higher = better)
        $priority = [
            'phd' => 6, 'doctor' => 6,
            'master' => 5, 'msc' => 5, 'mba' => 5, 'ma' => 5,
            'bachelor' => 4, 'bsc' => 4, 'bcom' => 4, 'ba' => 4, 'bba' => 4,
            'diploma' => 3, 'higher diploma' => 3,
            'certificate' => 2,
        ];

        $map = [];
        foreach ($rows as $r) {
            $cid         = $r['candidateId'];
            $degree      = $r['degree'] ?? '';
            $field       = $r['fieldOfStudy'] ?? '';
            $institution = $r['institution'] ?? '';
            $endDate     = $r['endDate'] ?? '';
            $isCurrent   = !empty($r['isCurrent']);

            $text = trim("$degree $field $institution");

            // Accumulate raw text
            if (!isset($map[$cid])) {
                $map[$cid] = ['highest' => '-', 'gradYear' => '', 'raw' => '', '_bestPri' => -1];
            }
            $map[$cid]['raw'] .= ' ' . $text;

            // Determine priority
            $degreeLower = strtolower($degree);
            $pri = 0;
            foreach ($priority as $keyword => $p) {
                if (strpos($degreeLower, $keyword) !== false) {
                    $pri = max($pri, $p);
                }
            }

            if ($pri > ($map[$cid]['_bestPri'] ?? -1)) {
                $map[$cid]['_bestPri'] = $pri;
                $map[$cid]['highest']  = trim("$degree" . ($field ? " $field" : ''));

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

        // Clean up internal key
        foreach ($map as &$entry) {
            unset($entry['_bestPri']);
            $entry['raw'] = trim($entry['raw']);
        }

        return $map;
    }

    /**
     * Returns per-profile: concatenated certification text.
     */
    private function getCertificationMap($db, array $profileIds): array
    {
        if (empty($profileIds)) {
            return [];
        }

        $rows = $db->table('certifications')
            ->select('candidateId, name, issuingOrg')
            ->whereIn('candidateId', $profileIds)
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid  = $r['candidateId'];
            $text = trim(($r['name'] ?? '') . ' ' . ($r['issuingOrg'] ?? ''));

            if (!isset($map[$cid])) {
                $map[$cid] = '';
            }
            $map[$cid] .= ' ' . $text;
        }

        return array_map('trim', $map);
    }

    /**
     * Returns per-profile: concatenated course text.
     */
    private function getCourseMap($db, array $profileIds): array
    {
        if (empty($profileIds)) {
            return [];
        }

        $rows = $db->table('candidate_courses')
            ->select('candidateId, name, institution')
            ->whereIn('candidateId', $profileIds)
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid  = $r['candidateId'];
            $text = trim(($r['name'] ?? '') . ' ' . ($r['institution'] ?? ''));

            if (!isset($map[$cid])) {
                $map[$cid] = '';
            }
            $map[$cid] .= ' ' . $text;
        }

        return array_map('trim', $map);
    }

    /**
     * Returns per-profile: concatenated experience text (title + company + description).
     */
    private function getExperienceTextMap($db, array $profileIds): array
    {
        if (empty($profileIds)) {
            return [];
        }

        $rows = $db->table('experiences')
            ->select('candidateId, title, company, description')
            ->whereIn('candidateId', $profileIds)
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid  = $r['candidateId'];
            $text = trim(
                ($r['title'] ?? '') . ' ' .
                ($r['company'] ?? '') . ' ' .
                ($r['description'] ?? '')
            );

            if (!isset($map[$cid])) {
                $map[$cid] = '';
            }
            $map[$cid] .= ' ' . $text;
        }

        return array_map('trim', $map);
    }
}
