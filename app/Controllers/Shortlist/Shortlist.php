<?php

namespace App\Controllers\Shortlist;

use App\Controllers\BaseController;
use App\Services\ShortlistService;
use App\Models\Job;
use App\Models\Company;
use App\Traits\NormalizedResponseTrait;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class Shortlist extends BaseController
{
    use NormalizedResponseTrait;

    protected $shortlistService;
    protected $jobModel;
    protected $companyModel;

    public function __construct()
    {
        $this->shortlistService = new ShortlistService();
        $this->jobModel = new Job();
        $this->companyModel = new Company();
    }

    // ==================== AUTHORIZATION ====================

    protected function authorizeJobAccess(string $jobId, $user): array
    {
        if (in_array($user->role, ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR'], true)) {
            return [true, null];
        }

        $db = \Config\Database::connect();
        $employerProfile = $db->table('employer_profiles')
            ->where('userId', $user->id)
            ->get()->getRowArray();

        if (!$employerProfile) {
            return [false, $this->fail('Employer profile not found', 404)];
        }

        $job = $this->jobModel->find($jobId);
        if (!$job) {
            return [false, $this->failNotFound('Job not found')];
        }

        if ($job['companyId'] !== $employerProfile['companyId']) {
            return [false, $this->fail('Forbidden: Job does not belong to your company', 403)];
        }

        return [true, null];
    }

    protected function getEmployerCompanyId($user): ?string
    {
        if (in_array($user->role, ['SUPER_ADMIN', 'HR_MANAGER', 'MODERATOR'], true)) {
            return null;
        }

        $db = \Config\Database::connect();
        $employerProfile = $db->table('employer_profiles')
            ->where('userId', $user->id)
            ->get()->getRowArray();

        return $employerProfile['companyId'] ?? null;
    }

    // ==================== HELPER: BUILD FILTER ARRAY ====================

    protected function buildFilters(): array
    {
        return [
            'minScore' => $this->request->getGet('minScore'),
            'status' => $this->request->getGet('status'),
            'hasAllMandatory' => $this->request->getGet('hasAllMandatory') === 'true',
            'flaggedForReview' => $this->request->getGet('flaggedForReview') === 'true',
            'topN' => $this->request->getGet('topN'),

            // NEW: default true (show all)
            'includeDisqualified' => $this->request->getGet('includeDisqualified') !== 'false'
        ];
    }

    protected function getTopNValue(): ?int
    {
        $topN = $this->request->getGet('topN');
        if (empty($topN)) return null;
        $topNInt = intval($topN);
        return ($topNInt > 0) ? $topNInt : null;
    }

    // ==================== JOBS LIST ====================

    public function getJobsWithShortlists()
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            $db = \Config\Database::connect();
            $builder = $db->table('jobs');

            $builder->select('
                jobs.id, 
                jobs.title, 
                jobs.status, 
                jobs.createdAt,
                companies.name as companyName,
                companies.logo as companyLogo,
                COUNT(DISTINCT applications.id) as applicationCount,
                COUNT(DISTINCT shortlist_results.id) as shortlistCount
            ')
                ->join('companies', 'companies.id = jobs.companyId', 'left')
                ->join('applications', 'applications.jobId = jobs.id', 'left')
                ->join('shortlist_results', 'shortlist_results.jobId = jobs.id', 'left')
                ->groupBy('jobs.id');

            $companyId = $this->getEmployerCompanyId($user);
            if ($companyId) $builder->where('jobs.companyId', $companyId);

            $builder->having('applicationCount >', 0);

            $jobs = $builder->orderBy('jobs.createdAt', 'DESC')->get()->getResultArray();

            foreach ($jobs as &$job) {
                $criteria = $this->shortlistService->getJobCriteria($job['id']);
                $job['hasCriteria'] = !empty($criteria['id']);
                $job['criteriaConfigured'] = $this->isCriteriaConfigured($criteria);
            }

            return $this->respond(['success' => true, 'data' => $jobs]);
        } catch (\Exception $e) {
            log_message('error', 'Get jobs with shortlists error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to fetch jobs', 'error' => $e->getMessage()], 500);
        }
    }

    // FIXED: avoid undefined array keys
    protected function isCriteriaConfigured(array $criteria): bool
    {
        return
            !empty($criteria['minAge'] ?? null) ||
            !empty($criteria['maxAge'] ?? null) ||
            !empty($criteria['requiredGender'] ?? null) ||
            !empty($criteria['requireDoctorate'] ?? false) ||
            !empty($criteria['requireMasters'] ?? false) ||
            !empty($criteria['requireBachelors'] ?? false) ||
            !empty($criteria['minGeneralExperience'] ?? null) ||
            !empty($criteria['minSeniorExperience'] ?? null) ||
            !empty($criteria['requireTaxClearance'] ?? false) ||
            !empty($criteria['requireHELBClearance'] ?? false) ||
            !empty($criteria['requireDCICClearance'] ?? false) ||
            !empty($criteria['requireCRBClearance'] ?? false) ||
            !empty($criteria['requireEACCClearance'] ?? false) ||
            !empty($criteria['requiredSkills'] ?? []) ||
            !empty($criteria['minPublications'] ?? null) ||
            !empty($criteria['requireProfessionalMembership'] ?? false);
    }

    // ==================== CRITERIA MANAGEMENT ====================

    public function getCriteria(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            [$ok, $resp] = $this->authorizeJobAccess($jobId, $user);
            if (!$ok) return $resp;

            $criteria = $this->shortlistService->getJobCriteria($jobId);

            $job = $this->jobModel->find($jobId);
            $company = $this->companyModel->find($job['companyId']);

            return $this->respond([
                'success' => true,
                'data' => [
                    'criteria' => $criteria,
                    'job' => [
                        'id' => $job['id'],
                        'title' => $job['title'],
                        'status' => $job['status']
                    ],
                    'company' => [
                        'id' => $company['id'] ?? null,
                        'name' => $company['name'] ?? null
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get criteria error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to fetch criteria', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateCriteria(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            [$ok, $resp] = $this->authorizeJobAccess($jobId, $user);
            if (!$ok) return $resp;

            $data = $this->request->getJSON(true) ?? [];
            $data['createdBy'] = $user->id;

            $success = $this->shortlistService->updateJobCriteria($jobId, $data);

            if ($success) {
                $this->logAudit($user->id, 'SHORTLIST_CRITERIA_UPDATED', 'Job', $jobId, [
                    'criteria' => array_keys($data)
                ]);

                return $this->respond(['success' => true, 'message' => 'Shortlist criteria updated successfully']);
            }

            return $this->fail('Failed to update criteria', 500);
        } catch (\Exception $e) {
            log_message('error', 'Update criteria error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to update criteria', 'error' => $e->getMessage()], 500);
        }
    }

    // ==================== SHORTLIST GENERATION ====================

    public function generate(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            [$ok, $resp] = $this->authorizeJobAccess($jobId, $user);
            if (!$ok) return $resp;

            $result = $this->shortlistService->generateShortlist($jobId);

            if ($result['success']) {
                $this->logAudit($user->id, 'SHORTLIST_GENERATED', 'Job', $jobId, [
                    'count' => $result['count']
                ]);

                return $this->respond([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => ['count' => $result['count']]
                ]);
            }

            return $this->respond(['success' => false, 'message' => $result['message']], 400);
        } catch (\Exception $e) {
            log_message('error', 'Generate shortlist error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to generate shortlist', 'error' => $e->getMessage()], 500);
        }
    }

    public function rerank(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            [$ok, $resp] = $this->authorizeJobAccess($jobId, $user);
            if (!$ok) return $resp;

            $this->shortlistService->rerank($jobId);

            $this->logAudit($user->id, 'SHORTLIST_RERANKED', 'Job', $jobId, []);

            return $this->respond(['success' => true, 'message' => 'Shortlist reranked successfully']);
        } catch (\Exception $e) {
            log_message('error', 'Rerank shortlist error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to rerank shortlist', 'error' => $e->getMessage()], 500);
        }
    }

    // ==================== RESULTS VIEWING ====================

    public function getResults(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            [$ok, $resp] = $this->authorizeJobAccess($jobId, $user);
            if (!$ok) return $resp;

            $filters = $this->buildFilters();
            $topN = $this->getTopNValue();

            // IMPORTANT: topN slicing is already handled in service by filters['topN']
            if ($topN) $filters['topN'] = $topN;

            $results = $this->shortlistService->getShortlistResults($jobId, $filters);

            $job = $this->jobModel->find($jobId);
            $company = $this->companyModel->find($job['companyId']);
            $criteria = $this->shortlistService->getJobCriteria($jobId);

            $stats = $this->calculateStats($results);

            return $this->respond([
                'success' => true,
                'data' => [
                    'results' => $results,
                    'stats' => $stats,
                    'pagination' => [
                        'total' => $stats['total'],
                        'displayed' => count($results),
                        'topN' => $topN,
                        'filters' => $filters
                    ],
                    'job' => ['id' => $job['id'], 'title' => $job['title']],
                    'company' => ['id' => $company['id'] ?? null, 'name' => $company['name'] ?? null],
                    'criteriaConfigured' => $this->isCriteriaConfigured($criteria)
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Get shortlist results error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to fetch shortlist results', 'error' => $e->getMessage()], 500);
        }
    }

    protected function calculateStats(array $results): array
    {
        $stats = [
            'total' => count($results),
            'qualified' => 0,
            'hasAllMandatory' => 0,
            'disqualified' => 0,
            'flaggedForReview' => 0,
            'averageScore' => 0,
            'medianScore' => 0,
            'topScore' => 0,
            'bottomScore' => 100,
            'scoreDistribution' => [
                '80-100' => 0,
                '60-79' => 0,
                '40-59' => 0,
                '0-39' => 0
            ]
        ];

        if (count($results) === 0) return $stats;

        $totalScore = 0;
        $scores = [];

        foreach ($results as $result) {
            $score = (float) ($result['effectiveTotalScore'] ?? $result['totalScore'] ?? 0);

            $scores[] = $score;
            $totalScore += $score;

            $isDisq = !empty($result['isEffectivelyDisqualified']) || (!empty($result['hasDisqualifyingFactor']) && empty($result['overrideDisqualification']));

            if (!$isDisq) $stats['qualified']++;
            if (!empty($result['hasAllMandatory'])) $stats['hasAllMandatory']++;
            if ($isDisq) $stats['disqualified']++;
            if (!empty($result['flaggedForReview'])) $stats['flaggedForReview']++;

            if ($score > $stats['topScore']) $stats['topScore'] = $score;
            if ($score < $stats['bottomScore']) $stats['bottomScore'] = $score;

            if ($score >= 80) $stats['scoreDistribution']['80-100']++;
            elseif ($score >= 60) $stats['scoreDistribution']['60-79']++;
            elseif ($score >= 40) $stats['scoreDistribution']['40-59']++;
            else $stats['scoreDistribution']['0-39']++;
        }

        $stats['averageScore'] = round($totalScore / count($results), 2);

        sort($scores);
        $middleIndex = floor(count($scores) / 2);
        if (count($scores) % 2 === 0) {
            $stats['medianScore'] = ($scores[$middleIndex - 1] + $scores[$middleIndex]) / 2;
        } else {
            $stats['medianScore'] = $scores[$middleIndex];
        }
        $stats['medianScore'] = round($stats['medianScore'], 2);

        return $stats;
    }

    // ==================== ADMIN SCORING ====================

    public function setAdminScores(string $jobId, string $resultId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            [$ok, $resp] = $this->authorizeJobAccess($jobId, $user);
            if (!$ok) return $resp;

            $payload = $this->request->getJSON(true) ?? [];

            $allowed = [
                'manualTotalScore',
                'manualEducationScore',
                'manualExperienceScore',
                'manualSkillsScore',
                'manualClearanceScore',
                'manualProfessionalScore',
            ];

            $update = [];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $payload)) {
                    $val = $payload[$k];
                    $update[$k] = ($val === null || $val === '') ? null : (float) $val;
                }
            }

            $update['scoredBy'] = $user->id;
            $update['scoredAt'] = date('Y-m-d H:i:s');

            $resultModel = new \App\Models\ShortlistResult();
            $row = $resultModel->where('id', $resultId)->where('jobId', $jobId)->first();
            if (!$row) return $this->failNotFound('Shortlist result not found');

            $resultModel->update($resultId, $update);

            $this->shortlistService->rerank($jobId);

            $this->logAudit($user->id, 'SHORTLIST_ADMIN_SCORED', 'ShortlistResult', $resultId, [
                'jobId' => $jobId,
                'fields' => array_keys($update),
            ]);

            return $this->respond(['success' => true, 'message' => 'Admin scores saved and reranked']);
        } catch (\Exception $e) {
            log_message('error', 'Set admin scores error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to save admin scores', 'error' => $e->getMessage()], 500);
        }
    }

    public function setOverrideDisqualification(string $jobId, string $resultId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            [$ok, $resp] = $this->authorizeJobAccess($jobId, $user);
            if (!$ok) return $resp;

            $payload = $this->request->getJSON(true) ?? [];
            $override = !empty($payload['overrideDisqualification']);

            $resultModel = new \App\Models\ShortlistResult();
            $row = $resultModel->where('id', $resultId)->where('jobId', $jobId)->first();
            if (!$row) return $this->failNotFound('Shortlist result not found');

            $update = [
                'overrideDisqualification' => $override ? 1 : 0,
                'overrideDisqualificationBy' => $override ? $user->id : null,
                'overrideDisqualificationAt' => $override ? date('Y-m-d H:i:s') : null,
            ];

            $resultModel->update($resultId, $update);

            $this->shortlistService->rerank($jobId);

            $this->logAudit($user->id, 'SHORTLIST_OVERRIDE_DISQUALIFICATION', 'ShortlistResult', $resultId, [
                'jobId' => $jobId,
                'overrideDisqualification' => $override,
            ]);

            return $this->respond(['success' => true, 'message' => 'Override updated and reranked']);
        } catch (\Exception $e) {
            log_message('error', 'Override disqualification error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to update override', 'error' => $e->getMessage()], 500);
        }
    }


    // ==================== EXPORT ====================

    public function exportExcel(string $jobId)
    {
        try {
            $user = $this->request->user ?? null;
            if (!$user) return $this->fail('Unauthorized', 401);

            [$ok, $resp] = $this->authorizeJobAccess($jobId, $user);
            if (!$ok) return $resp;

            $job = $this->jobModel->find($jobId);
            if (!$job) return $this->failNotFound('Job not found');

            $company = $this->companyModel->find($job['companyId']);

            $topN = $this->getTopNValue();

            $exportMode = $this->request->getGet('exportMode') ?: 'all';
            // exportMode:
            // - all => include disqualified too
            // - shortlistedOnly => hasDisqualifyingFactor=false OR overridden
            $includeDisqualified = ($exportMode === 'all');

            $degreeLevel = $this->request->getGet('degreeLevel'); // optional

            $filters = [
                'minScore' => $this->request->getGet('minScore'),
                'status' => $this->request->getGet('status'),
                'hasAllMandatory' => $this->request->getGet('hasAllMandatory') === 'true',
                'flaggedForReview' => $this->request->getGet('flaggedForReview') === 'true',
                'includeDisqualified' => $includeDisqualified,
            ];

            if (!empty($degreeLevel)) {
                $filters['degreeLevel'] = $degreeLevel;
            }

            $bundle = $this->shortlistService->buildShortlistExportBundle($jobId, $filters, $topN);

            $allResults = $this->shortlistService->getShortlistResults($jobId, $filters);
            $totalAvailable = count($allResults);

            $resultsToExport = $bundle['resultsToExport'] ?? [];
            $profilesMap = $bundle['profilesMap'] ?? [];

            $exportedCount = count($resultsToExport);
            $isLimited = ($topN && $exportedCount < $totalAvailable);

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Shortlist Export');

            $titleSuffix = $isLimited ? " - TOP {$topN} OF {$totalAvailable}" : '';
            $titleText = strtoupper($company['name'] ?? 'COMPANY') . ' - ' . strtoupper($job['title']) . ' - SHORTLIST EXPORT' . $titleSuffix;

            $sheet->setCellValue('A1', $titleText);
            $sheet->mergeCells('A1:U1'); // expanded for AUDITED column
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2563EB');
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(1)->setRowHeight(30);

            $sheet->setCellValue('A2', 'Generated on: ' . date('F d, Y h:i A'));
            $sheet->setCellValue('A3', 'Generated by: ' . ($user->firstName ?? '') . ' ' . ($user->lastName ?? ''));

            $row = 4;
            if (!empty($filters['minScore'])) {
                $sheet->setCellValue('A' . $row, 'Minimum Score: ' . $filters['minScore']);
                $row++;
            }
            if (!empty($filters['status'])) {
                $sheet->setCellValue('A' . $row, 'Application Status: ' . $filters['status']);
                $row++;
            }
            if ($filters['hasAllMandatory']) {
                $sheet->setCellValue('A' . $row, 'Filter: Only candidates with all mandatory criteria');
                $row++;
            }
            if (!empty($degreeLevel)) {
                $sheet->setCellValue('A' . $row, 'Degree Level Filter: ' . strtoupper($degreeLevel));
                $row++;
            }

            $sheet->setCellValue('A' . $row, 'Export Mode: ' . $exportMode);
            $row++;

            if ($isLimited) {
                $sheet->setCellValue('A' . $row, '⚠️ EXPORT SCOPE: Showing TOP ' . $topN . ' candidates out of ' . $totalAvailable . ' total');
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setItalic(true)->setSize(11)->getColor()->setARGB('FFDC2626');
                $row++;
            } else {
                $sheet->setCellValue('A' . $row, 'Export Scope: All ' . $exportedCount . ' candidates');
                $sheet->getStyle('A' . $row)->getFont()->setItalic(true)->setSize(11);
                $row++;
            }

            $sheet->mergeCells('A2:U2');
            $sheet->mergeCells('A3:U3');
            $sheet->getStyle('A2:A3')->getFont()->setItalic(true)->setSize(10);

            // SECTION A
            $sectionAHeaderRow = $row + 1;
            $sheet->setCellValue('A' . $sectionAHeaderRow, 'RANKED CANDIDATES (DESCRIPTION)');
            $sheet->mergeCells("A{$sectionAHeaderRow}:U{$sectionAHeaderRow}");
            $sheet->getStyle("A{$sectionAHeaderRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$sectionAHeaderRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDBEAFE');
            $sheet->getRowDimension($sectionAHeaderRow)->setRowHeight(22);

            $descHeaderRow = $sectionAHeaderRow + 1;

            $descHeaders = [
                'Rank', 'AUDITED',
                'Name', 'Email', 'Phone', 'County', 'Nationality', 'DOB', 'Age', 'Gender', 'PLWD',
                'Education (Top)', 'Experience (Summary)', 'Current Employer',
                'Clearances (Valid)', 'Memberships', 'Courses', 'Publications', 'Certifications', 'Referees', 'Resume URL'
            ];

            $col = 'A';
            foreach ($descHeaders as $h) {
                $sheet->setCellValue($col . $descHeaderRow, $h);
                $sheet->getStyle($col . $descHeaderRow)->getFont()->setBold(true)->setSize(11);
                $sheet->getStyle($col . $descHeaderRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
                $sheet->getStyle($col . $descHeaderRow)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);
                $sheet->getStyle($col . $descHeaderRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $col++;
            }
            $sheet->getRowDimension($descHeaderRow)->setRowHeight(40);

            $descDataRow = $descHeaderRow + 1;

            foreach ($resultsToExport as $r) {
                $candidateId = $r['candidateId'] ?? '';
                $p = $profilesMap[$candidateId] ?? [];

                $rankDisplay = $r['candidateRank'] ?? '';
                if (!empty($r['isEffectivelyDisqualified']) || (!empty($r['hasDisqualifyingFactor']) && empty($r['overrideDisqualification']))) {
                    $rankDisplay = 'X';
                }

                $rowData = [
                    $rankDisplay,
                    !empty($r['auditFlag']) ? 'Y' : 'N',
                    trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? '')),
                    $r['email'] ?? '',
                    $r['phone'] ?? '',
                    $p['countyOfOrigin'] ?? '',
                    $p['nationality'] ?? '',
                    $p['dob'] ?? '',
                    $p['age'] ?? '',
                    $p['gender'] ?? '',
                    $p['plwd'] ?? '',
                    $p['educationTop'] ?? '',
                    $p['experienceSummary'] ?? '',
                    $p['currentEmployer'] ?? '',
                    $p['clearancesSummary'] ?? '',
                    $p['memberships'] ?? '',
                    $p['courses'] ?? '',
                    $p['publications'] ?? '',
                    $p['certifications'] ?? '',
                    $p['referees'] ?? '',
                    $p['resumeUrl'] ?? '',
                ];

                $col = 'A';
                foreach ($rowData as $value) {
                    $sheet->setCellValue($col . $descDataRow, $value);
                    $sheet->getStyle($col . $descDataRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($col . $descDataRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

                    if ($col === 'A') $sheet->getStyle($col . $descDataRow)->getFont()->setBold(true);
                    if ($col === 'A' && $value === 'X') {
                        $sheet->getStyle($col . $descDataRow)->getFont()->getColor()->setARGB('FFDC2626');
                    }

                    $col++;
                }

                $descDataRow++;
            }

            $afterDescRow = $descDataRow + 2;

            // SECTION B
            $sectionBHeaderRow = $afterDescRow;
            $sheet->setCellValue("A{$sectionBHeaderRow}", 'SHORTLIST SCORES BREAKDOWN');
            $sheet->mergeCells("A{$sectionBHeaderRow}:U{$sectionBHeaderRow}");
            $sheet->getStyle("A{$sectionBHeaderRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$sectionBHeaderRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFEE2E2');
            $sheet->getRowDimension($sectionBHeaderRow)->setRowHeight(22);

            $scoreHeaderRow = $sectionBHeaderRow + 1;

            $scoreHeaders = [
                'Rank', 'AUDITED',
                'Name', 'Email', 'Phone', 'Title', 'Experience',
                'Effective Total', 'System Total', 'Manual Total',
                'Effective Edu', 'System Edu', 'Manual Edu',
                'Effective Exp', 'System Exp', 'Manual Exp',
                'Effective Skills', 'System Skills', 'Manual Skills',
                'Effective Clearance', 'System Clearance', 'Manual Clearance',
                'Effective Professional', 'System Professional', 'Manual Professional',
                'Percentile',
                'All Mandatory Met', 'Disqualified', 'Disqualification Reasons',
                'Application Status', 'Expected Salary', 'Matched Criteria', 'Bonus Points'
            ];

            // Expand columns beyond U if needed: we’ll write starting A and allow autosize later.
            $col = 'A';
            foreach ($scoreHeaders as $h) {
                $sheet->setCellValue($col . $scoreHeaderRow, $h);
                $sheet->getStyle($col . $scoreHeaderRow)->getFont()->setBold(true)->setSize(11);
                $sheet->getStyle($col . $scoreHeaderRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
                $sheet->getStyle($col . $scoreHeaderRow)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);
                $sheet->getStyle($col . $scoreHeaderRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $col++;
            }
            $sheet->getRowDimension($scoreHeaderRow)->setRowHeight(40);

            $scoreDataRow = $scoreHeaderRow + 1;

            foreach ($resultsToExport as $result) {
                $isDisq = !empty($result['isEffectivelyDisqualified']) || (!empty($result['hasDisqualifyingFactor']) && empty($result['overrideDisqualification']));
                $rankDisplay = $result['candidateRank'] ?? '';
                if ($isDisq) $rankDisplay = 'X';

                $rowData = [
                    $rankDisplay,
                    !empty($result['auditFlag']) ? 'Y' : 'N',

                    trim(($result['firstName'] ?? '') . ' ' . ($result['lastName'] ?? '')),
                    $result['email'] ?? '',
                    $result['phone'] ?? '',
                    $result['title'] ?? '',
                    $result['experienceYears'] ?? '',

                    $result['effectiveTotalScore'] ?? ($result['totalScore'] ?? 0),
                    $result['totalScore'] ?? 0,
                    $result['manualTotalScore'] ?? '',

                    $result['effectiveEducationScore'] ?? ($result['educationScore'] ?? 0),
                    $result['educationScore'] ?? 0,
                    $result['manualEducationScore'] ?? '',

                    $result['effectiveExperienceScore'] ?? ($result['experienceScore'] ?? 0),
                    $result['experienceScore'] ?? 0,
                    $result['manualExperienceScore'] ?? '',

                    $result['effectiveSkillsScore'] ?? ($result['skillsScore'] ?? 0),
                    $result['skillsScore'] ?? 0,
                    $result['manualSkillsScore'] ?? '',

                    $result['effectiveClearanceScore'] ?? ($result['clearanceScore'] ?? 0),
                    $result['clearanceScore'] ?? 0,
                    $result['manualClearanceScore'] ?? '',

                    $result['effectiveProfessionalScore'] ?? ($result['professionalScore'] ?? 0),
                    $result['professionalScore'] ?? 0,
                    $result['manualProfessionalScore'] ?? '',

                    $result['percentile'] ?? 0,
                    ($result['hasAllMandatory'] ?? false) ? 'Yes' : 'No',
                    $isDisq ? 'Yes' : 'No',
                    is_array($result['disqualificationReasons']) ? implode('; ', $result['disqualificationReasons']) : '',
                    $result['applicationStatus'] ?? '',
                    $result['expectedSalary'] ?? '',
                    is_array($result['matchedCriteria']) ? implode('; ', $result['matchedCriteria']) : '',
                    is_array($result['bonusCriteria']) ? implode('; ', $result['bonusCriteria']) : ''
                ];

                $col = 'A';
                foreach ($rowData as $value) {
                    $sheet->setCellValue($col . $scoreDataRow, $value);

                    if ($col === 'A') {
                        $sheet->getStyle($col . $scoreDataRow)->getFont()->setBold(true);
                        if ($value === 'X') {
                            $sheet->getStyle($col . $scoreDataRow)->getFont()->getColor()->setARGB('FFDC2626');
                        }
                    }

                    $sheet->getStyle($col . $scoreDataRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($col . $scoreDataRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
                    $col++;
                }

                $scoreDataRow++;
            }

            // Autosize columns A..AZ (safe range for this export)
            foreach (range('A', 'Z') as $c) {
                $sheet->getColumnDimension($c)->setAutoSize(true);
            }

            $sheet->freezePane('A' . ($descHeaderRow + 1));

            $filename = 'shortlist_export_'
                . preg_replace('/[^a-z0-9]+/', '_', strtolower($job['title']))
                . ($isLimited ? '_top' . $topN . '_of' . $totalAvailable : '_full')
                . '_' . date('Y-m-d_His')
                . '.xlsx';

            $writer = new Xlsx($spreadsheet);
            $tmp = fopen('php://temp', 'w+b');
            $writer->save($tmp);
            rewind($tmp);
            $binary = stream_get_contents($tmp);
            fclose($tmp);

            $this->logAudit($user->id, 'SHORTLIST_EXPORTED', 'Job', $jobId, [
                'filename' => $filename,
                'totalAvailable' => $totalAvailable,
                'exported' => $exportedCount,
                'topN' => $topN,
                'isLimited' => $isLimited,
                'filters' => $filters,
                'exportMode' => $exportMode,
                'degreeLevel' => $degreeLevel,
            ]);

            return $this->response
                ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setHeader('Cache-Control', 'max-age=0')
                ->setBody($binary);

        } catch (\Exception $e) {
            log_message('error', 'Export shortlist error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Failed to export shortlist', 'error' => $e->getMessage()], 500);
        }
    }

    // ==================== AUDIT LOG ====================

    protected function logAudit(string $userId, string $action, string $resource, string $resourceId, array $details): void
    {
        try {
            $auditLogModel = new \App\Models\AuditLog();
            $auditLogModel->insert([
                'id' => uniqid('audit_'),
                'userId' => $userId,
                'action' => $action,
                'resource' => $resource,
                'resourceId' => $resourceId,
                'module' => 'SHORTLIST',
                'details' => json_encode($details),
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Audit log error: ' . $e->getMessage());
        }
    }
}