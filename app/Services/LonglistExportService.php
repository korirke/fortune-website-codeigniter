<?php

namespace App\Services;

use Config\Database;

class LonglistExportService
{
    /**
     * Build longlist data rows with querying
     */
    public function buildLonglistRows(?string $jobId = null, ?string $companyId = null): array
    {
        $db = Database::connect();

        // Get applications with basic info
        $builder = $db->table('applications');
        $builder->select("
            applications.id as applicationId,
            applications.status,
            applications.appliedAt,
            applications.expectedSalary,
            applications.availableStartDate,
            applications.rating,
            applications.internalNotes,

            users.firstName,
            users.lastName,
            users.email,
            users.phone,

            candidate_profiles.id as candidateProfileId,
            candidate_profiles.experienceYears,
            candidate_profiles.totalExperienceMonths,
            candidate_profiles.resumeUrl,

            jobs.title as jobTitle,
            companies.name as companyName
        ");
        $builder->join('users', 'users.id = applications.candidateId', 'left');
        $builder->join('candidate_profiles', 'candidate_profiles.userId = users.id', 'left');
        $builder->join('jobs', 'jobs.id = applications.jobId', 'left');
        $builder->join('companies', 'companies.id = jobs.companyId', 'left');

        if ($jobId) {
            $builder->where('applications.jobId', $jobId);
        }
        if ($companyId) {
            $builder->where('jobs.companyId', $companyId);
        }

        $apps = $builder->orderBy('applications.appliedAt', 'DESC')->get()->getResultArray();
        if (empty($apps))
            return [];

        // Collect valid profile IDs
        $profileIds = array_values(array_unique(array_filter(array_column($apps, 'candidateProfileId'))));

        // Batch fetch all data
        $personalInfoMap = $this->getPersonalInfoMap($db, $profileIds);
        $clearanceMap = $this->getClearanceMap($db, $profileIds);
        $educationMap = $this->getEducationMap($db, $profileIds);
        $experienceMap = $this->getExperienceMap($db, $profileIds);
        $membershipMap = $this->getMembershipMap($db, $profileIds);
        $courseMap = $this->getCourseMap($db, $profileIds);
        $publicationMap = $this->getPublicationMap($db, $profileIds);
        $certificationMap = $this->getCertificationMap($db, $profileIds);
        $refereeMap = $this->getRefereeMap($db, $profileIds);

        // Build rows
        $rows = [];
        $serial = 1;

        foreach ($apps as $a) {
            $cid = $a['candidateProfileId'] ?? null;
            $hasProfile = !empty($cid);

            $pInfo = $hasProfile ? ($personalInfoMap[$cid] ?? null) : null;
            $fullName = trim(($a['firstName'] ?? '') . ' ' . ($a['lastName'] ?? ''));

            // Calculate age
            $age = 'N/A';
            if (!empty($pInfo['dob'])) {
                try {
                    $dob = new \DateTime($pInfo['dob']);
                    $age = (new \DateTime())->diff($dob)->y;
                } catch (\Exception $e) {
                }
            }

            // Education
            $doctorate = $hasProfile ? ($educationMap[$cid]['Doctorate'] ?? '') : '';
            $masters = $hasProfile ? ($educationMap[$cid]['Masters'] ?? '') : '';
            $bachelors = $hasProfile ? ($educationMap[$cid]['Bachelors'] ?? '') : '';

            // Experience (existing strings)
            $allExp = $hasProfile ? ($experienceMap[$cid]['all'] ?? '') : '';
            $seniorExp = $hasProfile ? ($experienceMap[$cid]['senior'] ?? '') : '';
            $currentEmployer = $hasProfile ? ($experienceMap[$cid]['current'] ?? '') : '';

            /**
             * General experience years must come from cached totalExperienceMonths
             * - Column X expects "Specific Number of years of General Experience" (numeric years)
             * - Column Y expects "15 years relevant work experience" (Y/N)
             */
            $months = (int) ($a['totalExperienceMonths'] ?? 0);
            $generalYears = (int) floor($months / 12);
            $generalExp = (string) $generalYears;

            // Col Y (Y/N)
            $has15Years = ($months >= (15 * 12)) ? 'Y' : 'N';

            // Clearances
            $taxCert = $hasProfile ? ($clearanceMap[$cid]['TAX_COMPLIANCE']['status'] ?? ($clearanceMap[$cid]['Tax']['status'] ?? 'N')) : 'N';
            $taxValidity = $hasProfile ? ($clearanceMap[$cid]['TAX_COMPLIANCE']['validity'] ?? ($clearanceMap[$cid]['Tax']['validity'] ?? '')) : '';
            $helbCert = $hasProfile ? ($clearanceMap[$cid]['HELB']['status'] ?? 'N') : 'N';
            $helbValidity = $hasProfile ? ($clearanceMap[$cid]['HELB']['validity'] ?? '') : '';
            $dciCert = $hasProfile ? ($clearanceMap[$cid]['DCI']['status'] ?? 'N') : 'N';
            $dciValidity = $hasProfile ? ($clearanceMap[$cid]['DCI']['validity'] ?? '') : '';
            $crbCert = $hasProfile ? ($clearanceMap[$cid]['CRB']['status'] ?? 'N') : 'N';
            $crbValidity = $hasProfile ? ($clearanceMap[$cid]['CRB']['validity'] ?? '') : '';
            $eaccCert = $hasProfile ? ($clearanceMap[$cid]['EACC']['status'] ?? 'N') : 'N';
            $eaccValidity = $hasProfile ? ($clearanceMap[$cid]['EACC']['validity'] ?? '') : '';

            // Professional
            $memberships = $hasProfile ? ($membershipMap[$cid]['names'] ?? '') : '';
            $goodStanding = $hasProfile ? ($membershipMap[$cid]['standing'] ?? '') : '';
            $courses = $hasProfile ? ($courseMap[$cid] ?? '') : '';
            $publications = $hasProfile ? ($publicationMap[$cid] ?? '') : '';
            $certifications = $hasProfile ? ($certificationMap[$cid] ?? '') : '';

            // Referees
            $referees = $hasProfile ? ($refereeMap[$cid] ?? '') : '';

            $rows[] = [
                // Col 1
                $serial++,

                // Col 2-12: GENERAL INFORMATION
                $fullName ?: 'N/A',
                $pInfo['countyOfOrigin'] ?? 'Not Stated',
                $a['phone'] ?? '',
                $a['email'] ?? '',
                $pInfo['nationality'] ?? 'Kenyan',
                $pInfo['countyOfOrigin'] ?? 'N/A',
                $this->formatDate($pInfo['dob'] ?? ''),
                $age,
                $pInfo['idNumber'] ?? 'N/A',
                $pInfo['gender'] ?? '',
                (!empty($pInfo['plwd']) ? 'Y' : 'N'),

                // Col 13-14: Doctorate
                $doctorate,
                ($doctorate ? 'Y' : 'N'),

                // Col 15-16: Masters
                $masters,
                ($masters ? 'Y' : 'N'),

                // Col 17-18: Bachelors
                $bachelors,
                ($bachelors ? 'Y' : 'N'),

                // Col 19-23: Professional memberships and certificates
                $memberships,
                $goodStanding,
                $courses,
                $publications,
                $certifications,

                // Col 24-27: Experience
                $generalExp,                 // Col X (numeric years)
                $has15Years,                 // Col Y (Y/N)
                $this->countSeniorYears($seniorExp), // Col Z
                $seniorExp,                  // Col AA

                // Col 28-32: Current employment and referees
                $currentEmployer,
                $a['expectedSalary'] ?? 'Not stated',
                'Not stated',
                'Not stated',
                $referees,

                // Col 33-42: Clearance certificates
                $taxCert,
                $taxValidity,
                $helbCert,
                $helbValidity,
                $dciCert,
                $dciValidity,
                $crbCert,
                $crbValidity,
                $eaccCert,
                $eaccValidity,
            ];
        }

        return $rows;
    }

    /**
     * Get company/job title for Row 1
     */
    public function getExportTitle(?string $jobId = null, ?string $companyId = null): string
    {
        $db = Database::connect();

        if ($jobId) {
            $job = $db->table('jobs')
                ->select('jobs.title, companies.name')
                ->join('companies', 'companies.id = jobs.companyId', 'left')
                ->where('jobs.id', $jobId)
                ->get()->getRowArray();

            if ($job) {
                return strtoupper($job['name'] ?? 'COMPANY') . ' - ' . strtoupper($job['title'] ?? 'POSITION') . ' RECRUITMENT LONGLIST';
            }
        }

        if ($companyId) {
            $company = $db->table('companies')
                ->select('name')
                ->where('id', $companyId)
                ->get()->getRowArray();

            if ($company) {
                return strtoupper($company['name']) . ' - RECRUITMENT LONGLIST';
            }
        }

        return 'RECRUITMENT LONGLIST';
    }

    // ==================== BATCH FETCH METHODS ====================

    private function getPersonalInfoMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('candidate_personal_info')
            ->select('candidateId, dob, gender, idNumber, nationality, countyOfOrigin, plwd')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['candidateId']] = [
                'dob' => $r['dob'] ?? '',
                'gender' => $r['gender'] ?? '',
                'idNumber' => $r['idNumber'] ?? '',
                'nationality' => $r['nationality'] ?? 'Kenyan',
                'countyOfOrigin' => $r['countyOfOrigin'] ?? '',
                'plwd' => (bool) ($r['plwd'] ?? false),
            ];
        }
        return $map;
    }

    private function getClearanceMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('candidate_clearances')
            ->select('candidateId, type, status, issueDate, expiryDate')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $type = strtoupper((string) ($r['type'] ?? ''));

            if (!isset($map[$cid]))
                $map[$cid] = [];
            if (!isset($map[$cid][$type])) {
                $map[$cid][$type] = ['status' => 'N', 'validity' => ''];
            }

            // Only set to Y if VALID
            if (strtoupper($r['status'] ?? '') === 'VALID') {
                $map[$cid][$type]['status'] = 'Y';

                $validity = '';
                if (!empty($r['expiryDate'])) {
                    $validity = $this->formatDate($r['expiryDate']);
                } elseif (!empty($r['issueDate'])) {
                    $validity = $this->formatDate($r['issueDate']);
                }
                $map[$cid][$type]['validity'] = $validity;
            }
        }
        return $map;
    }

    private function getEducationMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('educations')
            ->select('candidateId, degree, fieldOfStudy, institution, endDate, isCurrent')
            ->whereIn('candidateId', $profileIds)
            ->orderBy('endDate', 'DESC')
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $degree = $r['degree'] ?? '';
            $field = $r['fieldOfStudy'] ?? '';
            $institution = $r['institution'] ?? '';
            $year = $this->extractYear($r['endDate'] ?? '', $r['isCurrent'] ?? false);

            $text = trim("$degree $field $institution $year");
            if (!$text)
                continue;

            if (!isset($map[$cid]))
                $map[$cid] = [];

            $degreeUpper = strtoupper($degree);
            if (strpos($degreeUpper, 'PHD') !== false || strpos($degreeUpper, 'DOCTOR') !== false) {
                if (empty($map[$cid]['Doctorate']))
                    $map[$cid]['Doctorate'] = $text;
            } elseif (strpos($degreeUpper, 'MASTER') !== false || strpos($degreeUpper, 'MSC') !== false) {
                if (empty($map[$cid]['Masters']))
                    $map[$cid]['Masters'] = $text;
            } elseif (
                strpos($degreeUpper, 'BACHELOR') !== false ||
                strpos($degreeUpper, 'BSC') !== false ||
                strpos($degreeUpper, 'BA') !== false
            ) {
                if (empty($map[$cid]['Bachelors']))
                    $map[$cid]['Bachelors'] = $text;
            }
        }
        return $map;
    }

    private function getExperienceMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('experiences')
            ->select('candidateId, title, company, startDate, endDate, isCurrent')
            ->whereIn('candidateId', $profileIds)
            ->orderBy('isCurrent', 'DESC')
            ->orderBy('startDate', 'DESC')
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $title = $r['title'] ?? '';
            $company = $r['company'] ?? '';
            $duration = $this->formatDuration($r['startDate'] ?? '', $r['endDate'] ?? '', $r['isCurrent'] ?? false);

            $text = trim("$title - $company ($duration)");

            if (!isset($map[$cid])) {
                $map[$cid] = ['all' => '', 'senior' => '', 'current' => ''];
            }

            // All experience
            if ($map[$cid]['all'])
                $map[$cid]['all'] .= '; ';
            $map[$cid]['all'] .= $text;

            // Senior management check
            $titleLower = strtolower($title);
            $seniorKeywords = ['director', 'manager', 'head', 'chief', 'executive', 'vp', 'president', 'ceo', 'cfo', 'cto', 'coo'];
            foreach ($seniorKeywords as $keyword) {
                if (strpos($titleLower, $keyword) !== false) {
                    if ($map[$cid]['senior'])
                        $map[$cid]['senior'] .= '; ';
                    $map[$cid]['senior'] .= $text;
                    break;
                }
            }

            // Current employer
            if (!empty($r['isCurrent'])) {
                if ($map[$cid]['current'])
                    $map[$cid]['current'] .= '; ';
                $map[$cid]['current'] .= $text;
            }
        }
        return $map;
    }

    private function getMembershipMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('candidate_professional_memberships')
            ->select('candidateId, bodyName, isActive, goodStanding')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $bodyName = $r['bodyName'] ?? '';
            $isActive = !empty($r['isActive']);
            $goodStanding = !empty($r['goodStanding']);

            if (!isset($map[$cid])) {
                $map[$cid] = ['names' => '', 'standing' => ''];
            }

            if ($bodyName) {
                if ($map[$cid]['names'])
                    $map[$cid]['names'] .= ', ';
                $map[$cid]['names'] .= $bodyName;

                if ($isActive && $goodStanding) {
                    if ($map[$cid]['standing'])
                        $map[$cid]['standing'] .= ', ';
                    $map[$cid]['standing'] .= $bodyName . ' (Good Standing)';
                }
            }
        }
        return $map;
    }

    private function getCourseMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('candidate_courses')
            ->select('candidateId, name, institution, durationWeeks')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $name = $r['name'] ?? '';
            $institution = $r['institution'] ?? '';
            $duration = $r['durationWeeks'] ?? '';

            $text = trim("$name - $institution" . ($duration ? " ($duration weeks)" : ''));

            if (!isset($map[$cid]))
                $map[$cid] = '';
            if ($map[$cid])
                $map[$cid] .= ', ';
            $map[$cid] .= $text;
        }
        return $map;
    }

    private function getPublicationMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('candidate_publications')
            ->select('candidateId, title, year')
            ->whereIn('candidateId', $profileIds)
            ->orderBy('year', 'DESC')
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $title = $r['title'] ?? '';
            $year = $r['year'] ?? '';

            $text = trim("$title ($year)");

            if (!isset($map[$cid]))
                $map[$cid] = '';
            if ($map[$cid])
                $map[$cid] .= '; ';
            $map[$cid] .= $text;
        }
        return $map;
    }

    private function getCertificationMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('certifications')
            ->select('candidateId, name, issuingOrg')
            ->whereIn('candidateId', $profileIds)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $cid = $r['candidateId'];
            $name = $r['name'] ?? '';
            $issuingOrg = $r['issuingOrg'] ?? '';

            $text = trim("$name - $issuingOrg");

            if (!isset($map[$cid]))
                $map[$cid] = '';
            if ($map[$cid])
                $map[$cid] .= ', ';
            $map[$cid] .= $text;
        }
        return $map;
    }

    private function getRefereeMap($db, array $profileIds): array
    {
        if (empty($profileIds))
            return [];

        $rows = $db->table('candidate_referees')
            ->select('candidateId, name, position, organization, phone, email')
            ->whereIn('candidateId', $profileIds)
            ->orderBy('createdAt', 'DESC')
            ->get()->getResultArray();

        $map = [];
        $counts = [];

        foreach ($rows as $r) {
            $cid = $r['candidateId'];

            if (!isset($counts[$cid]))
                $counts[$cid] = 0;
            if ($counts[$cid] >= 3) {
                continue;
            }

            $name = $r['name'] ?? '';
            $position = $r['position'] ?? '';
            $org = $r['organization'] ?? '';
            $phone = $r['phone'] ?? '';
            $email = $r['email'] ?? '';

            $text = trim("$name, $position, $org, Tel: $phone, Email: $email");

            if (!isset($map[$cid]))
                $map[$cid] = '';
            if ($map[$cid])
                $map[$cid] .= '; ';
            $map[$cid] .= $text;

            $counts[$cid]++;
        }

        return $map;
    }

    // ==================== HELPER METHODS ====================

    private function formatDate($dateString): string
    {
        if (empty($dateString) || $dateString === '0000-00-00 00:00:00.000' || $dateString === '0000-00-00') {
            return '';
        }
        try {
            return date('d/m/Y', strtotime($dateString));
        } catch (\Exception $e) {
            return '';
        }
    }

    private function extractYear($dateString, $isCurrent): string
    {
        if ($isCurrent)
            return date('Y');
        if (empty($dateString))
            return '';
        try {
            return date('Y', strtotime($dateString));
        } catch (\Exception $e) {
            return '';
        }
    }

    private function formatDuration($startDate, $endDate, $isCurrent): string
    {
        $start = $this->extractYear($startDate, false);
        $end = $isCurrent ? 'Present' : $this->extractYear($endDate, false);

        if (!$start)
            return $end;
        if (!$end)
            return $start;
        return "$start - $end";
    }

    private function countSeniorYears($seniorExpText): string
    {
        // Simple heuristic: count positions and estimate years
        if (empty($seniorExpText))
            return '0';

        $positions = explode(';', $seniorExpText);
        $totalYears = 0;

        foreach ($positions as $pos) {
            // Try to extract duration like "2020 - 2023"
            if (preg_match('/(\d{4})\s*-\s*(\d{4}|Present)/', $pos, $matches)) {
                $startYear = (int) $matches[1];
                $endYear = ($matches[2] === 'Present') ? (int) date('Y') : (int) $matches[2];
                $totalYears += ($endYear - $startYear);
            }
        }

        return $totalYears > 0 ? (string) $totalYears : '';
    }
}