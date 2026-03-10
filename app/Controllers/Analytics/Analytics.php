<?php

namespace App\Controllers\Analytics;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @OA\Tag(
 *     name="Analytics",
 *     description="Analytics & Insights endpoints – all require HR_MANAGER / MODERATOR / SUPER_ADMIN role"
 * )
 */
class Analytics extends BaseController
{
    use ResponseTrait;

    // ---------------------------------------------------------------
    // Shared helper: resolve date range from ?period= query param
    // Supports: today | last_7_days | last_30_days | last_90_days | this_year | custom
    // Returns ['start' => DateTime, 'end' => DateTime, 'groupBy' => 'hour|day|week|month', 'intervalDays' => int]
    // ---------------------------------------------------------------
    private function resolveDateRange(): array
    {
        $period = $this->request->getGet('period') ?? 'last_30_days';
        $startDate = $this->request->getGet('startDate');
        $endDate = $this->request->getGet('endDate');

        $now = new \DateTime();
        $end = $endDate ? new \DateTime($endDate) : clone $now;

        switch ($period) {
            case 'today':
                $start = new \DateTime('today');
                $start->setTime(0, 0, 0);
                $end = clone $now;
                $groupBy = 'hour';
                $intervalDays = 1;
                break;

            case 'last_7_days':
                $start = clone $now;
                $start->modify('-7 days');
                $groupBy = 'day';
                $intervalDays = 7;
                break;

            case 'last_90_days':
                $start = clone $now;
                $start->modify('-90 days');
                $groupBy = 'week';
                $intervalDays = 90;
                break;

            case 'this_year':
                $start = new \DateTime($now->format('Y-01-01'));
                $groupBy = 'month';
                $intervalDays = (int) $now->format('z') + 1;
                break;

            case 'custom':
                $start = $startDate ? new \DateTime($startDate) : (clone $now)->modify('-30 days');
                $intervalDays = (int) $start->diff($end)->days;
                $groupBy = $intervalDays <= 1 ? 'hour' : ($intervalDays <= 31 ? 'day' : ($intervalDays <= 90 ? 'week' : 'month'));
                break;

            default: // last_30_days
                $start = clone $now;
                $start->modify('-30 days');
                $groupBy = 'day';
                $intervalDays = 30;
        }

        return [
            'start' => $start,
            'end' => $end,
            'groupBy' => $groupBy,
            'intervalDays' => $intervalDays,
        ];
    }

    // ---------------------------------------------------------------
    // Helper: build the SQL date-format expression for GROUP BY
    // ---------------------------------------------------------------
    private function dateFormatExpr(string $column, string $groupBy): string
    {
        return match ($groupBy) {
            'hour' => "DATE_FORMAT({$column}, '%Y-%m-%d %H:00')",
            'week' => "DATE_FORMAT({$column}, '%x-W%v')",
            'month' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
        };
    }

    // ---------------------------------------------------------------
    // Helper: calculate percentage change vs previous period
    // ---------------------------------------------------------------
    private function pctChange(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    // ---------------------------------------------------------------
    // Helper: fill in missing dates so the chart series is continuous
    // ---------------------------------------------------------------
    private function fillSeries(array $rows, string $start, string $end, string $groupBy): array
    {
        $filled = [];
        $map = [];
        foreach ($rows as $row) {
            $map[$row['label']] = (int) $row['count'];
        }

        $current = new \DateTime($start);
        $endDt = new \DateTime($end);

        while ($current <= $endDt) {
            $label = match ($groupBy) {
                'hour' => $current->format('Y-m-d H:00'),
                'week' => $current->format('o-\WW'),
                'month' => $current->format('Y-m'),
                default => $current->format('Y-m-d'),
            };

            $filled[$label] = $map[$label] ?? 0;

            match ($groupBy) {
                'hour' => $current->modify('+1 hour'),
                'week' => $current->modify('+1 week'),
                'month' => $current->modify('+1 month'),
                default => $current->modify('+1 day'),
            };
        }

        return $filled;
    }

    // ---------------------------------------------------------------
    // Private: normalise [{label,count}] → [{label, count(int)}]
    // ---------------------------------------------------------------
    private function formatLabelCount(array $rows): array
    {
        return array_map(fn($r) => [
            'label' => $r['label'],
            'count' => (int) $r['count'],
        ], $rows);
    }

    // ==============================================================
    //  NEW: GET /api/analytics/job-selector
    //  Small, fast list for dropdowns (no heavy joins except company)
    // ==============================================================
    public function getJobSelector()
    {
        try {
            $limit = (int) ($this->request->getGet('limit') ?? 200);
            $limit = max(1, min($limit, 1000));
            $status = $this->request->getGet('status'); // optional

            $db = \Config\Database::connect();

            $sql = "
                SELECT j.id, j.title, j.status, j.createdAt, j.applicationCount, j.views,
                       c.name AS companyName
                FROM jobs j
                LEFT JOIN companies c ON c.id = j.companyId
            ";

            $params = [];
            if ($status) {
                $sql .= " WHERE j.status = ? ";
                $params[] = $status;
            }

            $sql .= " ORDER BY j.createdAt DESC LIMIT {$limit}";

            $rows = $db->query($sql, $params)->getResultArray();

            return $this->respond([
                'success' => true,
                'message' => 'Job selector retrieved',
                'data' => [
                    'jobs' => array_map(fn($r) => [
                        'id' => $r['id'],
                        'title' => $r['title'],
                        'status' => $r['status'],
                        'createdAt' => $r['createdAt'],
                        'applicationCount' => (int) ($r['applicationCount'] ?? 0),
                        'views' => (int) ($r['views'] ?? 0),
                        'companyName' => $r['companyName'] ?? null,
                    ], $rows),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  NEW: GET /api/analytics/job/{jobId}
    //  Per-job analytics: applications over time + funnel + rates
    //
    //  Query params:
    //   - period/startDate/endDate (same as other analytics)
    //   - groupByOverride=hour|day (optional: force hour/day)
    // ==============================================================
    public function getJobAnalyticsByJob(string $jobId)
    {
        try {
            $range = $this->resolveDateRange();
            $start = $range['start'];
            $end = $range['end'];

            $groupByOverride = $this->request->getGet('groupByOverride');
            $groupBy = $range['groupBy'];
            if (in_array($groupByOverride, ['hour', 'day'], true)) {
                $groupBy = $groupByOverride;
            }

            $startStr = $start->format('Y-m-d H:i:s');
            $endStr = $end->format('Y-m-d H:i:s');
            $dateFmt = $this->dateFormatExpr('a.appliedAt', $groupBy);

            $db = \Config\Database::connect();

            // Job meta (validate existence)
            $jobRow = $db->query(
                "SELECT j.id, j.title, j.status, j.views, j.applicationCount, j.createdAt,
                        c.name AS companyName
                 FROM jobs j
                 LEFT JOIN companies c ON c.id = j.companyId
                 WHERE j.id = ?
                 LIMIT 1",
                [$jobId]
            )->getRowArray();

            if (!$jobRow) {
                return $this->respond(['success' => false, 'message' => 'Job not found', 'data' => []], 404);
            }

            // Funnel by application status (for this job, within period)
            $byStatus = $db->query(
                "SELECT a.status AS label, COUNT(*) AS count
                 FROM applications a
                 WHERE a.jobId = ?
                   AND a.appliedAt >= ? AND a.appliedAt <= ?
                 GROUP BY a.status
                 ORDER BY count DESC",
                [$jobId, $startStr, $endStr]
            )->getResultArray();

            $totalApps = array_sum(array_map(fn($r) => (int) $r['count'], $byStatus));
            $accepted = 0;
            $withdrawn = 0;
            foreach ($byStatus as $r) {
                if (($r['label'] ?? '') === 'ACCEPTED')
                    $accepted = (int) $r['count'];
                if (($r['label'] ?? '') === 'WITHDRAWN')
                    $withdrawn = (int) $r['count'];
            }

            $acceptanceRate = $totalApps > 0 ? round(($accepted / $totalApps) * 100, 1) : 0.0;
            $withdrawalRate = $totalApps > 0 ? round(($withdrawn / $totalApps) * 100, 1) : 0.0;

            // Applications over time (for this job)
            $overTimeRows = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM applications a
                 WHERE a.jobId = ?
                   AND a.appliedAt >= ? AND a.appliedAt <= ?
                 GROUP BY label
                 ORDER BY label ASC",
                [$jobId, $startStr, $endStr]
            )->getResultArray();

            $filled = $this->fillSeries($overTimeRows, $startStr, $endStr, $groupBy);

            // Reviewed count (simple operational metric)
            $reviewedCount = (int) $db->table('applications')
                ->where('jobId', $jobId)
                ->where('reviewedAt IS NOT NULL')
                ->where('appliedAt >=', $startStr)
                ->where('appliedAt <=', $endStr)
                ->countAllResults(false);

            return $this->respond([
                'success' => true,
                'message' => 'Job analytics retrieved',
                'data' => [
                    'job' => [
                        'id' => $jobRow['id'],
                        'title' => $jobRow['title'],
                        'status' => $jobRow['status'],
                        'companyName' => $jobRow['companyName'] ?? null,
                        'views' => (int) ($jobRow['views'] ?? 0),
                        'applicationCount' => (int) ($jobRow['applicationCount'] ?? 0),
                        'createdAt' => $jobRow['createdAt'] ?? null,
                    ],
                    'period' => [
                        'start' => $start->format('c'),
                        'end' => $end->format('c'),
                        'groupBy' => $groupBy,
                    ],
                    'byStatus' => $this->formatLabelCount($byStatus),
                    'overTime' => [
                        'groupBy' => $groupBy,
                        'labels' => array_keys($filled),
                        'values' => array_values($filled),
                    ],
                    'totals' => [
                        'applications' => (int) $totalApps,
                        'reviewed' => (int) $reviewedCount,
                        'accepted' => (int) $accepted,
                        'withdrawn' => (int) $withdrawn,
                    ],
                    'rates' => [
                        'acceptanceRate' => $acceptanceRate,
                        'withdrawalRate' => $withdrawalRate,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  1. GET /api/analytics/overview
    // ==============================================================
    public function getOverview()
    {
        try {
            $range = $this->resolveDateRange();
            $start = $range['start'];
            $end = $range['end'];
            $intervalDays = $range['intervalDays'];

            $startStr = $start->format('Y-m-d H:i:s');
            $endStr = $end->format('Y-m-d H:i:s');

            $prevEnd = clone $start;
            $prevStart = clone $start;
            $prevStart->modify("-{$intervalDays} days");
            $prevStartStr = $prevStart->format('Y-m-d H:i:s');
            $prevEndStr = $prevEnd->format('Y-m-d H:i:s');

            $db = \Config\Database::connect();

            $totalJobs = (int) $db->table('jobs')
                ->where('createdAt >=', $startStr)->where('createdAt <=', $endStr)
                ->countAllResults(false);

            $totalApplications = (int) $db->table('applications')
                ->where('appliedAt >=', $startStr)->where('appliedAt <=', $endStr)
                ->countAllResults(false);

            $totalCandidates = (int) $db->table('users')
                ->where('role', 'CANDIDATE')
                ->where('createdAt >=', $startStr)->where('createdAt <=', $endStr)
                ->countAllResults(false);

            $activeJobs = (int) $db->table('jobs')
                ->where('status', 'ACTIVE')->countAllResults(false);

            $activeEmployers = (int) $db->table('users')
                ->where('role', 'EMPLOYER')->where('status', 'ACTIVE')
                ->countAllResults(false);

            $pendingModeration = (int) $db->table('jobs')
                ->where('status', 'PENDING')->countAllResults(false);

            $totalViews = (int) ($db->table('jobs')
                ->selectSum('views')
                ->get()->getRow()->views ?? 0);

            $prevJobs = (int) $db->table('jobs')
                ->where('createdAt >=', $prevStartStr)->where('createdAt <=', $prevEndStr)
                ->countAllResults(false);

            $prevApplications = (int) $db->table('applications')
                ->where('appliedAt >=', $prevStartStr)->where('appliedAt <=', $prevEndStr)
                ->countAllResults(false);

            $prevCandidates = (int) $db->table('users')
                ->where('role', 'CANDIDATE')
                ->where('createdAt >=', $prevStartStr)->where('createdAt <=', $prevEndStr)
                ->countAllResults(false);

            $acceptedApps = $db->table('applications')
                ->select('appliedAt, reviewedAt')
                ->where('status', 'ACCEPTED')
                ->where('appliedAt >=', $startStr)->where('appliedAt <=', $endStr)
                ->where('reviewedAt IS NOT NULL')
                ->get()->getResultArray();

            $avgTimeToHire = 14;
            if (!empty($acceptedApps)) {
                $totalDays = array_reduce($acceptedApps, function ($carry, $app) {
                    return $carry + max(0, floor(
                        (strtotime($app['reviewedAt']) - strtotime($app['appliedAt'])) / 86400
                    ));
                }, 0);
                $avgTimeToHire = (int) round($totalDays / count($acceptedApps));
            }

            $acceptedCount = (int) $db->table('applications')
                ->where('status', 'ACCEPTED')
                ->where('appliedAt >=', $startStr)->where('appliedAt <=', $endStr)
                ->countAllResults(false);

            $successRate = $totalApplications > 0
                ? round(($acceptedCount / $totalApplications) * 100, 1)
                : 0.0;

            return $this->respond([
                'success' => true,
                'message' => 'Overview stats retrieved',
                'data' => [
                    'totalJobs' => $totalJobs,
                    'totalApplications' => $totalApplications,
                    'totalCandidates' => $totalCandidates,
                    'totalViews' => $totalViews,
                    'activeJobs' => $activeJobs,
                    'activeEmployers' => $activeEmployers,
                    'pendingModeration' => $pendingModeration,
                    'avgTimeToHire' => $avgTimeToHire,
                    'successRate' => $successRate,
                    'trends' => [
                        'jobs' => $this->pctChange($totalJobs, $prevJobs),
                        'applications' => $this->pctChange($totalApplications, $prevApplications),
                        'candidates' => $this->pctChange($totalCandidates, $prevCandidates),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  2. GET /api/analytics/growth-trends
    // ==============================================================
    public function getGrowthTrends()
    {
        try {
            $range = $this->resolveDateRange();
            $start = $range['start'];
            $end = $range['end'];
            $groupBy = $range['groupBy'];

            $startStr = $start->format('Y-m-d H:i:s');
            $endStr = $end->format('Y-m-d H:i:s');
            $dateFmt = $this->dateFormatExpr('createdAt', $groupBy);
            $appDateFmt = $this->dateFormatExpr('appliedAt', $groupBy);

            $db = \Config\Database::connect();

            $jobRows = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM jobs
                 WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            $appRows = $db->query(
                "SELECT {$appDateFmt} AS label, COUNT(*) AS count
                 FROM applications
                 WHERE appliedAt >= ? AND appliedAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            $candRows = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM users
                 WHERE role = 'CANDIDATE' AND createdAt >= ? AND createdAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            $jobsFilled = $this->fillSeries($jobRows, $startStr, $endStr, $groupBy);
            $appsFilled = $this->fillSeries($appRows, $startStr, $endStr, $groupBy);
            $candsFilled = $this->fillSeries($candRows, $startStr, $endStr, $groupBy);

            $labels = array_unique(array_merge(
                array_keys($jobsFilled),
                array_keys($appsFilled),
                array_keys($candsFilled)
            ));
            sort($labels);

            $series = ['jobs' => [], 'applications' => [], 'candidates' => []];
            foreach ($labels as $label) {
                $series['jobs'][] = $jobsFilled[$label] ?? 0;
                $series['applications'][] = $appsFilled[$label] ?? 0;
                $series['candidates'][] = $candsFilled[$label] ?? 0;
            }

            $perDayGroupBy = ($groupBy === 'hour') ? 'hour' : 'day';
            $dayWindowDays = min($range['intervalDays'], 30);

            if ($perDayGroupBy === 'hour') {
                $pdStart = clone $start;
                $pdEnd = clone $end;
            } else {
                $pdEnd = clone $end;
                $pdStart = clone $pdEnd;
                $pdStart->modify("-{$dayWindowDays} days");
                if ($pdStart < $start) {
                    $pdStart = clone $start;
                }
            }

            $pdStartStr = $pdStart->format('Y-m-d H:i:s');
            $pdEndStr = $pdEnd->format('Y-m-d H:i:s');
            $pdDateFmt = $this->dateFormatExpr('createdAt', $perDayGroupBy);
            $pdAppDateFmt = $this->dateFormatExpr('appliedAt', $perDayGroupBy);

            $pdJobRows = $db->query(
                "SELECT {$pdDateFmt} AS label, COUNT(*) AS count
                 FROM jobs
                 WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$pdStartStr, $pdEndStr]
            )->getResultArray();

            $pdAppRows = $db->query(
                "SELECT {$pdAppDateFmt} AS label, COUNT(*) AS count
                 FROM applications
                 WHERE appliedAt >= ? AND appliedAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$pdStartStr, $pdEndStr]
            )->getResultArray();

            $pdCandRows = $db->query(
                "SELECT {$pdDateFmt} AS label, COUNT(*) AS count
                 FROM users
                 WHERE role = 'CANDIDATE' AND createdAt >= ? AND createdAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$pdStartStr, $pdEndStr]
            )->getResultArray();

            $pdJobsFilled = $this->fillSeries($pdJobRows, $pdStartStr, $pdEndStr, $perDayGroupBy);
            $pdAppsFilled = $this->fillSeries($pdAppRows, $pdStartStr, $pdEndStr, $perDayGroupBy);
            $pdCandsFilled = $this->fillSeries($pdCandRows, $pdStartStr, $pdEndStr, $perDayGroupBy);

            $pdLabels = array_unique(array_merge(
                array_keys($pdJobsFilled),
                array_keys($pdAppsFilled),
                array_keys($pdCandsFilled)
            ));
            sort($pdLabels);

            $pdSeries = ['jobs' => [], 'applications' => [], 'candidates' => []];
            foreach ($pdLabels as $label) {
                $pdSeries['jobs'][] = $pdJobsFilled[$label] ?? 0;
                $pdSeries['applications'][] = $pdAppsFilled[$label] ?? 0;
                $pdSeries['candidates'][] = $pdCandsFilled[$label] ?? 0;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Growth trends retrieved',
                'data' => [
                    'groupBy' => $groupBy,
                    'labels' => array_values($labels),
                    'series' => $series,
                    'perDayBreakdown' => [
                        'groupBy' => $perDayGroupBy,
                        'labels' => array_values($pdLabels),
                        'series' => $pdSeries,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  3. GET /api/analytics/jobs
    // ==============================================================
    public function getJobAnalytics()
    {
        try {
            $range = $this->resolveDateRange();
            $startStr = $range['start']->format('Y-m-d H:i:s');
            $endStr = $range['end']->format('Y-m-d H:i:s');

            $db = \Config\Database::connect();

            $byStatus = $db->query(
                "SELECT status AS label, COUNT(*) AS count
                 FROM jobs WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY status ORDER BY count DESC",
                [$startStr, $endStr]
            )->getResultArray();

            $byType = $db->query(
                "SELECT type AS label, COUNT(*) AS count
                 FROM jobs WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY type ORDER BY count DESC",
                [$startStr, $endStr]
            )->getResultArray();

            $byExperienceLevel = $db->query(
                "SELECT experienceLevel AS label, COUNT(*) AS count
                 FROM jobs WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY experienceLevel ORDER BY count DESC",
                [$startStr, $endStr]
            )->getResultArray();

            $byCategory = $db->query(
                "SELECT jc.name AS label, COUNT(j.id) AS count
                 FROM jobs j
                 JOIN job_categories jc ON j.categoryId = jc.id
                 WHERE j.createdAt >= ? AND j.createdAt <= ?
                 GROUP BY jc.id, jc.name
                 ORDER BY count DESC
                 LIMIT 10",
                [$startStr, $endStr]
            )->getResultArray();

            $topByViews = $db->query(
                "SELECT j.id, j.title, j.views, j.applicationCount, c.name AS companyName
                 FROM jobs j
                 JOIN companies c ON j.companyId = c.id
                 WHERE j.status = 'ACTIVE'
                 ORDER BY j.views DESC
                 LIMIT 10"
            )->getResultArray();

            $remoteVsOnsite = $db->query(
                "SELECT IF(isRemote = 1, 'Remote', 'On-site') AS label, COUNT(*) AS count
                 FROM jobs WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY isRemote",
                [$startStr, $endStr]
            )->getResultArray();

            $featured = (int) $db->table('jobs')->where('featured', 1)->countAllResults(false);
            $sponsored = (int) $db->table('jobs')->where('sponsored', 1)->countAllResults(false);

            return $this->respond([
                'success' => true,
                'message' => 'Job analytics retrieved',
                'data' => [
                    'byStatus' => $this->formatLabelCount($byStatus),
                    'byType' => $this->formatLabelCount($byType),
                    'byExperienceLevel' => $this->formatLabelCount($byExperienceLevel),
                    'byCategory' => $this->formatLabelCount($byCategory),
                    'remoteVsOnsite' => $this->formatLabelCount($remoteVsOnsite),
                    'topByViews' => $topByViews,
                    'featured' => $featured,
                    'sponsored' => $sponsored,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  4. GET /api/analytics/applications
    // ==============================================================
    public function getApplicationAnalytics()
    {
        try {
            $range = $this->resolveDateRange();
            $start = $range['start'];
            $end = $range['end'];
            $groupBy = $range['groupBy'];
            $startStr = $start->format('Y-m-d H:i:s');
            $endStr = $end->format('Y-m-d H:i:s');
            $dateFmt = $this->dateFormatExpr('appliedAt', $groupBy);

            $db = \Config\Database::connect();

            $byStatus = $db->query(
                "SELECT status AS label, COUNT(*) AS count
                 FROM applications
                 WHERE appliedAt >= ? AND appliedAt <= ?
                 GROUP BY status ORDER BY count DESC",
                [$startStr, $endStr]
            )->getResultArray();

            $overTime = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM applications
                 WHERE appliedAt >= ? AND appliedAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            $filled = $this->fillSeries($overTime, $startStr, $endStr, $groupBy);

            $topHiringJobs = $db->query(
                "SELECT j.id, j.title, j.applicationCount, c.name AS companyName
                 FROM jobs j
                 JOIN companies c ON j.companyId = c.id
                 WHERE j.status = 'ACTIVE'
                 ORDER BY j.applicationCount DESC
                 LIMIT 10"
            )->getResultArray();

            $reviewedCount = (int) $db->table('applications')
                ->where('reviewedAt IS NOT NULL')
                ->where('appliedAt >=', $startStr)
                ->where('appliedAt <=', $endStr)
                ->countAllResults(false);

            $intervalDays = max(1, $range['intervalDays']);
            $avgDailyReviews = round($reviewedCount / $intervalDays, 1);

            $withdrawnCount = (int) $db->table('applications')
                ->where('status', 'WITHDRAWN')
                ->where('appliedAt >=', $startStr)
                ->where('appliedAt <=', $endStr)
                ->countAllResults(false);

            $totalCount = array_sum(array_column($byStatus, 'count'));
            $withdrawalRate = $totalCount > 0 ? round(($withdrawnCount / $totalCount) * 100, 1) : 0.0;

            return $this->respond([
                'success' => true,
                'message' => 'Application analytics retrieved',
                'data' => [
                    'byStatus' => $this->formatLabelCount($byStatus),
                    'overTime' => [
                        'groupBy' => $groupBy,
                        'labels' => array_keys($filled),
                        'values' => array_values($filled),
                    ],
                    'topHiringJobs' => $topHiringJobs,
                    'avgDailyReviews' => $avgDailyReviews,
                    'withdrawalRate' => $withdrawalRate,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  5. GET /api/analytics/candidates
    // ==============================================================
    /**
     * @OA\Get(
     *     path="/api/analytics/candidates",
     *     tags={"Analytics"},
     *     summary="Candidate analytics – registrations over time, top skills, domains",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false,
     *         @OA\Schema(type="string", enum={"last_7_days","last_30_days","last_90_days","this_year","custom"})),
     *     @OA\Response(response="200", description="Candidate analytics retrieved")
     * )
     */
    public function getCandidateAnalytics()
    {
        try {
            $range = $this->resolveDateRange();
            $start = $range['start'];
            $end = $range['end'];
            $groupBy = $range['groupBy'];
            $startStr = $start->format('Y-m-d H:i:s');
            $endStr = $end->format('Y-m-d H:i:s');
            $dateFmt = $this->dateFormatExpr('createdAt', $groupBy);

            $db = \Config\Database::connect();

            $regRows = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM users
                 WHERE role = 'CANDIDATE' AND createdAt >= ? AND createdAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            $filled = $this->fillSeries($regRows, $startStr, $endStr, $groupBy);

            $topSkills = $db->query(
                "SELECT s.name AS label, COUNT(cs.id) AS count
                 FROM candidate_skills cs
                 JOIN skills s ON cs.skillId = s.id
                 GROUP BY s.id, s.name
                 ORDER BY count DESC
                 LIMIT 15"
            )->getResultArray();

            // Top domains among candidates
            $topDomains = $db->query(
                "SELECT d.name AS label, COUNT(cd.id) AS count
                 FROM candidate_domains cd
                 JOIN domains d ON cd.domainId = d.id
                 GROUP BY d.id, d.name
                 ORDER BY count DESC
                 LIMIT 10"
            )->getResultArray();

            // Verified vs unverified email
            $emailVerified = $db->query(
                "SELECT IF(emailVerified = 1, 'Verified', 'Unverified') AS label, COUNT(*) AS count
                 FROM users WHERE role = 'CANDIDATE'
                 GROUP BY emailVerified"
            )->getResultArray();

            // Active vs inactive candidates
            $byStatus = $db->query(
                "SELECT status AS label, COUNT(*) AS count
                 FROM users WHERE role = 'CANDIDATE'
                 GROUP BY status ORDER BY count DESC"
            )->getResultArray();

            // Candidates with complete profiles (have at least 1 experience)
            $withExperience = (int) $db->query(
                "SELECT COUNT(DISTINCT candidateId) AS cnt FROM experiences"
            )->getRow()->cnt ?? 0;

            $totalCandidatesAllTime = (int) $db->table('users')
                ->where('role', 'CANDIDATE')->countAllResults(false);

            $profileCompletionRate = $totalCandidatesAllTime > 0
                ? round(($withExperience / $totalCandidatesAllTime) * 100, 1)
                : 0.0;

            return $this->respond([
                'success' => true,
                'message' => 'Candidate analytics retrieved',
                'data' => [
                    'registrationsOverTime' => [
                        'groupBy' => $groupBy,
                        'labels' => array_keys($filled),
                        'values' => array_values($filled),
                    ],
                    'topSkills' => $this->formatLabelCount($topSkills),
                    'topDomains' => $this->formatLabelCount($topDomains),
                    'emailVerification' => $this->formatLabelCount($emailVerified),
                    'byStatus' => $this->formatLabelCount($byStatus),
                    'profileCompletionRate' => $profileCompletionRate,
                    'totalAllTime' => $totalCandidatesAllTime,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  6. GET /api/analytics/top-performers
    // ==============================================================
    /**
     * @OA\Get(
     *     path="/api/analytics/top-performers",
     *     tags={"Analytics"},
     *     summary="Top performing jobs, employers, and job categories",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Top performers retrieved")
     * )
     */
    public function getTopPerformers()
    {
        try {
            $db = \Config\Database::connect();

            $topJobs = $db->query(
                "SELECT j.id, j.title, j.applicationCount, j.views, c.name AS companyName, c.logo AS companyLogo
                 FROM jobs j
                 JOIN companies c ON j.companyId = c.id
                 WHERE j.status = 'ACTIVE'
                 ORDER BY j.applicationCount DESC
                 LIMIT 10"
            )->getResultArray();

            $formattedJobs = array_map(fn($j) => [
                'id' => $j['id'],
                'title' => $j['title'],
                'applicationCount' => (int) $j['applicationCount'],
                'views' => (int) $j['views'],
                'company' => ['name' => $j['companyName'], 'logo' => $j['companyLogo']],
            ], $topJobs);

            $topEmployers = $db->query(
                "SELECT c.id, c.name, c.logo, COUNT(j.id) AS jobCount
                 FROM companies c
                 LEFT JOIN jobs j ON j.companyId = c.id AND j.status = 'ACTIVE'
                 WHERE c.status = 'VERIFIED'
                 GROUP BY c.id, c.name, c.logo
                 ORDER BY jobCount DESC
                 LIMIT 10"
            )->getResultArray();

            $formattedEmployers = array_map(fn($e) => [
                'id' => $e['id'],
                'name' => $e['name'],
                'logo' => $e['logo'],
                '_count' => ['jobs' => (int) $e['jobCount']],
            ], $topEmployers);

            $topCategories = $db->query(
                "SELECT jc.id, jc.name, COUNT(j.id) AS jobCount
                 FROM job_categories jc
                 LEFT JOIN jobs j ON j.categoryId = jc.id AND j.status = 'ACTIVE'
                 WHERE jc.isActive = 1
                 GROUP BY jc.id, jc.name
                 ORDER BY jobCount DESC
                 LIMIT 10"
            )->getResultArray();

            $formattedCategories = array_map(fn($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'jobCount' => (int) $c['jobCount'],
            ], $topCategories);

            return $this->respond([
                'success' => true,
                'message' => 'Top performers retrieved',
                'data' => [
                    'topJobs' => $formattedJobs,
                    'topEmployers' => $formattedEmployers,
                    'topCategories' => $formattedCategories,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  Export payload builder (shared by JSON + XLSX)
    // ==============================================================
    private function buildExportPayload(array $range): array
    {
        $start = $range['start'];
        $end = $range['end'];
        $groupBy = $range['groupBy'];
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $db = \Config\Database::connect();

        $totalJobs = (int) $db->table('jobs')
            ->where('createdAt >=', $startStr)->where('createdAt <=', $endStr)
            ->countAllResults(false);

        $totalApplications = (int) $db->table('applications')
            ->where('appliedAt >=', $startStr)->where('appliedAt <=', $endStr)
            ->countAllResults(false);

        $totalCandidates = (int) $db->table('users')
            ->where('role', 'CANDIDATE')
            ->where('createdAt >=', $startStr)->where('createdAt <=', $endStr)
            ->countAllResults(false);

        $totalEmployers = (int) $db->table('users')
            ->where('role', 'EMPLOYER')
            ->where('createdAt >=', $startStr)->where('createdAt <=', $endStr)
            ->countAllResults(false);

        $byJobStatus = $db->query(
            "SELECT status AS label, COUNT(*) AS count FROM jobs
             WHERE createdAt >= ? AND createdAt <= ? GROUP BY status ORDER BY count DESC",
            [$startStr, $endStr]
        )->getResultArray();

        $byJobType = $db->query(
            "SELECT type AS label, COUNT(*) AS count FROM jobs
             WHERE createdAt >= ? AND createdAt <= ? GROUP BY type ORDER BY count DESC",
            [$startStr, $endStr]
        )->getResultArray();

        $byJobCategory = $db->query(
            "SELECT jc.name AS label, COUNT(j.id) AS count
             FROM jobs j JOIN job_categories jc ON j.categoryId = jc.id
             WHERE j.createdAt >= ? AND j.createdAt <= ?
             GROUP BY jc.id, jc.name ORDER BY count DESC LIMIT 20",
            [$startStr, $endStr]
        )->getResultArray();

        $byAppStatus = $db->query(
            "SELECT status AS label, COUNT(*) AS count FROM applications
             WHERE appliedAt >= ? AND appliedAt <= ? GROUP BY status ORDER BY count DESC",
            [$startStr, $endStr]
        )->getResultArray();

        // Growth trend (per day, capped 30 days)
        $pdGroupBy = ($groupBy === 'hour') ? 'hour' : 'day';
        $pdDayLimit = min($range['intervalDays'], 30);
        $pdEnd = clone $end;
        $pdStart = clone $pdEnd;
        if ($pdGroupBy === 'day') {
            $pdStart->modify("-{$pdDayLimit} days");
            if ($pdStart < $start)
                $pdStart = clone $start;
        } else {
            $pdStart = clone $start;
        }

        $pdStartStr = $pdStart->format('Y-m-d H:i:s');
        $pdEndStr = $pdEnd->format('Y-m-d H:i:s');
        $pdJobFmt = $this->dateFormatExpr('createdAt', $pdGroupBy);
        $pdAppFmt = $this->dateFormatExpr('appliedAt', $pdGroupBy);

        $pdJobRows = $db->query(
            "SELECT {$pdJobFmt} AS label, COUNT(*) AS count FROM jobs
             WHERE createdAt >= ? AND createdAt <= ? GROUP BY label ORDER BY label ASC",
            [$pdStartStr, $pdEndStr]
        )->getResultArray();

        $pdAppRows = $db->query(
            "SELECT {$pdAppFmt} AS label, COUNT(*) AS count FROM applications
             WHERE appliedAt >= ? AND appliedAt <= ? GROUP BY label ORDER BY label ASC",
            [$pdStartStr, $pdEndStr]
        )->getResultArray();

        $pdCandRows = $db->query(
            "SELECT {$pdJobFmt} AS label, COUNT(*) AS count FROM users
             WHERE role = 'CANDIDATE' AND createdAt >= ? AND createdAt <= ?
             GROUP BY label ORDER BY label ASC",
            [$pdStartStr, $pdEndStr]
        )->getResultArray();

        $pdJobFilled = $this->fillSeries($pdJobRows, $pdStartStr, $pdEndStr, $pdGroupBy);
        $pdAppFilled = $this->fillSeries($pdAppRows, $pdStartStr, $pdEndStr, $pdGroupBy);
        $pdCandFilled = $this->fillSeries($pdCandRows, $pdStartStr, $pdEndStr, $pdGroupBy);

        $pdLabels = array_unique(array_merge(
            array_keys($pdJobFilled),
            array_keys($pdAppFilled),
            array_keys($pdCandFilled)
        ));
        sort($pdLabels);

        $dailyRows = [];
        foreach ($pdLabels as $lbl) {
            $dailyRows[] = [
                'date' => $lbl,
                'jobs' => $pdJobFilled[$lbl] ?? 0,
                'applications' => $pdAppFilled[$lbl] ?? 0,
                'registrations' => $pdCandFilled[$lbl] ?? 0,
            ];
        }

        $topJobs = $db->query(
            "SELECT j.title, c.name AS company, j.applicationCount, j.views
             FROM jobs j JOIN companies c ON j.companyId = c.id
             WHERE j.status = 'ACTIVE' ORDER BY j.applicationCount DESC LIMIT 10"
        )->getResultArray();

        $topCategories = $db->query(
            "SELECT jc.name AS category, COUNT(j.id) AS jobCount
             FROM job_categories jc
             LEFT JOIN jobs j ON j.categoryId = jc.id AND j.status = 'ACTIVE'
             WHERE jc.isActive = 1
             GROUP BY jc.id, jc.name ORDER BY jobCount DESC LIMIT 10"
        )->getResultArray();

        return [
            'generatedAt' => (new \DateTime())->format('c'),
            'period' => [
                'start' => $range['start']->format('c'),
                'end' => $range['end']->format('c'),
            ],
            'summary' => [
                'totalJobs' => $totalJobs,
                'totalApplications' => $totalApplications,
                'totalCandidates' => $totalCandidates,
                'totalEmployers' => $totalEmployers,
            ],
            'jobs' => [
                'byStatus' => $this->formatLabelCount($byJobStatus),
                'byType' => $this->formatLabelCount($byJobType),
                'byCategory' => $this->formatLabelCount($byJobCategory),
            ],
            'applications' => [
                'byStatus' => $this->formatLabelCount($byAppStatus),
            ],
            'dailyActivity' => $dailyRows,
            'topJobs' => $topJobs,
            'topCategories' => $topCategories,
        ];
    }

    // ==============================================================
    //  7. GET /api/analytics/export (JSON payload)
    // ==============================================================
    public function exportReport()
    {
        try {
            $range = $this->resolveDateRange();
            $payload = $this->buildExportPayload($range);

            return $this->respond([
                'success' => true,
                'message' => 'Report generated',
                'data' => $payload,
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  NEW: GET /api/analytics/export/xlsx
    //  Backend-generated XLSX
    // ==============================================================
    public function exportReportXlsx()
    {
        try {
            $range = $this->resolveDateRange();
            $period = $this->request->getGet('period') ?? 'last_30_days';
            $payload = $this->buildExportPayload($range);

            $ss = new Spreadsheet();

            // Sheet 1: Summary
            $sheet = $ss->getActiveSheet();
            $sheet->setTitle('Summary');

            $sheet->fromArray([
                ['Analytics Report'],
                ['Generated At', $payload['generatedAt']],
                ['Period Start', $payload['period']['start']],
                ['Period End', $payload['period']['end']],
                [],
                ['Metric', 'Value'],
                ['Total Jobs', $payload['summary']['totalJobs']],
                ['Total Applications', $payload['summary']['totalApplications']],
                ['Total Candidates', $payload['summary']['totalCandidates']],
                ['Total Employers', $payload['summary']['totalEmployers']],
            ], null, 'A1');

            $sheet->getColumnDimension('A')->setWidth(28);
            $sheet->getColumnDimension('B')->setWidth(34);

            // Helper to add a sheet from header + rows
            $addSheet = function (string $title, array $header, array $rows) use ($ss) {
                $ws = $ss->createSheet();
                $ws->setTitle(substr($title, 0, 31)); // Excel max 31 chars
                $ws->fromArray([$header], null, 'A1');
                if (!empty($rows)) {
                    $ws->fromArray($rows, null, 'A2');
                }
                // Basic autosize for first ~6 cols
                for ($col = 1; $col <= min(6, count($header)); $col++) {
                    $ws->getColumnDimensionByColumn($col)->setAutoSize(true);
                }
            };

            // Sheet 2: Daily Activity
            if (!empty($payload['dailyActivity'])) {
                $rows = array_map(fn($r) => [
                    $r['date'],
                    $r['jobs'],
                    $r['applications'],
                    $r['registrations']
                ], $payload['dailyActivity']);
                $addSheet('Daily Activity', ['Date / Hour', 'Jobs Posted', 'Applications', 'Registrations'], $rows);
            }

            // Sheet 3: Jobs by Status
            if (!empty($payload['jobs']['byStatus'])) {
                $rows = array_map(fn($r) => [$r['label'], $r['count']], $payload['jobs']['byStatus']);
                $addSheet('Jobs by Status', ['Status', 'Count'], $rows);
            }

            // Sheet 4: Jobs by Type
            if (!empty($payload['jobs']['byType'])) {
                $rows = array_map(fn($r) => [$r['label'], $r['count']], $payload['jobs']['byType']);
                $addSheet('Jobs by Type', ['Type', 'Count'], $rows);
            }

            // Sheet 5: Jobs by Category
            if (!empty($payload['jobs']['byCategory'])) {
                $rows = array_map(fn($r) => [$r['label'], $r['count']], $payload['jobs']['byCategory']);
                $addSheet('Jobs by Category', ['Category', 'Count'], $rows);
            }

            // Sheet 6: Applications by Status
            if (!empty($payload['applications']['byStatus'])) {
                $rows = array_map(fn($r) => [$r['label'], $r['count']], $payload['applications']['byStatus']);
                $addSheet('Apps by Status', ['Status', 'Count'], $rows);
            }

            // Sheet 7: Top Jobs
            if (!empty($payload['topJobs'])) {
                $rows = array_map(fn($r) => [
                    $r['title'],
                    $r['company'],
                    (int) $r['applicationCount'],
                    (int) $r['views']
                ], $payload['topJobs']);
                $addSheet('Top Jobs', ['Title', 'Company', 'Applications', 'Views'], $rows);
            }

            // Sheet 8: Top Categories
            if (!empty($payload['topCategories'])) {
                $rows = array_map(fn($r) => [$r['category'], (int) $r['jobCount']], $payload['topCategories']);
                $addSheet('Top Categories', ['Category', 'Job Count'], $rows);
            }

            // Output XLSX
            $writer = new Xlsx($ss);

            ob_start();
            $writer->save('php://output');
            $xlsxData = ob_get_clean();

            $fileName = 'analytics-' . $period . '-' . (new \DateTime())->format('Y-m-d') . '.xlsx';

            return $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
                ->setHeader('Cache-Control', 'max-age=0')
                ->setBody($xlsxData);

        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }
}