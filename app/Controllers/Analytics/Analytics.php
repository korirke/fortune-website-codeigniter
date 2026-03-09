<?php

namespace App\Controllers\Analytics;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

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
    // Returns ['start' => DateTime, 'end' => DateTime, 'groupBy' => 'day|week|month', 'intervalDays' => int]
    // ---------------------------------------------------------------
    private function resolveDateRange(): array
    {
        $period    = $this->request->getGet('period') ?? 'last_30_days';
        $startDate = $this->request->getGet('startDate');
        $endDate   = $this->request->getGet('endDate');

        $now = new \DateTime();
        $end = $endDate ? new \DateTime($endDate) : clone $now;

        switch ($period) {
            case 'last_7_days':
                $start        = clone $now;
                $start->modify('-7 days');
                $groupBy      = 'day';
                $intervalDays = 7;
                break;

            case 'last_90_days':
                $start        = clone $now;
                $start->modify('-90 days');
                $groupBy      = 'week';
                $intervalDays = 90;
                break;

            case 'this_year':
                $start        = new \DateTime($now->format('Y-01-01'));
                $groupBy      = 'month';
                $intervalDays = (int) $now->format('z') + 1; // days elapsed this year
                break;

            case 'custom':
                $start        = $startDate ? new \DateTime($startDate) : (clone $now)->modify('-30 days');
                $intervalDays = (int) $start->diff($end)->days;
                $groupBy      = $intervalDays <= 31 ? 'day' : ($intervalDays <= 90 ? 'week' : 'month');
                break;

            default: // last_30_days
                $start        = clone $now;
                $start->modify('-30 days');
                $groupBy      = 'day';
                $intervalDays = 30;
        }

        return [
            'start'        => $start,
            'end'          => $end,
            'groupBy'      => $groupBy,
            'intervalDays' => $intervalDays,
        ];
    }

    // ---------------------------------------------------------------
    // Helper: build the SQL date-format expression for GROUP BY
    // ---------------------------------------------------------------
    private function dateFormatExpr(string $column, string $groupBy): string
    {
        return match ($groupBy) {
            'week'  => "DATE_FORMAT({$column}, '%x-W%v')",
            'month' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
        };
    }

    // ---------------------------------------------------------------
    // Helper: calculate percentage change vs previous period
    // Returns float rounded to 1 decimal place
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
        $map    = [];
        foreach ($rows as $row) {
            $map[$row['label']] = (int) $row['count'];
        }

        $current = new \DateTime($start);
        $endDt   = new \DateTime($end);

        while ($current <= $endDt) {
            $label = match ($groupBy) {
                'week'  => $current->format('o-\WW'),
                'month' => $current->format('Y-m'),
                default => $current->format('Y-m-d'),
            };

            $filled[$label] = $map[$label] ?? 0;

            // advance
            match ($groupBy) {
                'week'  => $current->modify('+1 week'),
                'month' => $current->modify('+1 month'),
                default => $current->modify('+1 day'),
            };
        }

        return $filled;
    }

    // ==============================================================
    //  1. GET /api/analytics/overview
    // ==============================================================
    /**
     * @OA\Get(
     *     path="/api/analytics/overview",
     *     tags={"Analytics"},
     *     summary="Overview KPI stats with period-over-period trends",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false,
     *         @OA\Schema(type="string", enum={"last_7_days","last_30_days","last_90_days","this_year","custom"}),
     *         example="last_30_days"),
     *     @OA\Parameter(name="startDate", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="endDate",   in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(response="200", description="Overview stats retrieved")
     * )
     */
    public function getOverview()
    {
        try {
            $range        = $this->resolveDateRange();
            $start        = $range['start'];
            $end          = $range['end'];
            $intervalDays = $range['intervalDays'];

            $startStr = $start->format('Y-m-d H:i:s');
            $endStr   = $end->format('Y-m-d H:i:s');

            // Previous period window (same length)
            $prevEnd   = clone $start;
            $prevStart = clone $start;
            $prevStart->modify("-{$intervalDays} days");
            $prevStartStr = $prevStart->format('Y-m-d H:i:s');
            $prevEndStr   = $prevEnd->format('Y-m-d H:i:s');

            $db = \Config\Database::connect();

            // ---- Current period counts ----
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

            // All-time active jobs & employers (not period-scoped)
            $activeJobs = (int) $db->table('jobs')
                ->where('status', 'ACTIVE')->countAllResults(false);

            $activeEmployers = (int) $db->table('users')
                ->where('role', 'EMPLOYER')->where('status', 'ACTIVE')
                ->countAllResults(false);

            $pendingModeration = (int) $db->table('jobs')
                ->where('status', 'PENDING')->countAllResults(false);

            // Total job views (sum of views column across all active jobs)
            $totalViews = (int) ($db->table('jobs')
                ->selectSum('views')
                ->get()->getRow()->views ?? 0);

            // ---- Previous period counts (for trends) ----
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

            // ---- avgTimeToHire ----
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

            // ---- successRate ----
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
                'data'    => [
                    // Period KPIs
                    'totalJobs'         => $totalJobs,
                    'totalApplications' => $totalApplications,
                    'totalCandidates'   => $totalCandidates,
                    'totalViews'        => $totalViews,
                    // All-time
                    'activeJobs'         => $activeJobs,
                    'activeEmployers'    => $activeEmployers,
                    'pendingModeration'  => $pendingModeration,
                    // Derived
                    'avgTimeToHire' => $avgTimeToHire,
                    'successRate'   => $successRate,
                    // Period-over-period trends (%)
                    'trends' => [
                        'jobs'         => $this->pctChange($totalJobs, $prevJobs),
                        'applications' => $this->pctChange($totalApplications, $prevApplications),
                        'candidates'   => $this->pctChange($totalCandidates, $prevCandidates),
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
    /**
     * @OA\Get(
     *     path="/api/analytics/growth-trends",
     *     tags={"Analytics"},
     *     summary="Time-series growth data for jobs, applications and candidates",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false,
     *         @OA\Schema(type="string", enum={"last_7_days","last_30_days","last_90_days","this_year","custom"})),
     *     @OA\Response(response="200", description="Growth trends retrieved")
     * )
     */
    public function getGrowthTrends()
    {
        try {
            $range   = $this->resolveDateRange();
            $start   = $range['start'];
            $end     = $range['end'];
            $groupBy = $range['groupBy'];

            $startStr  = $start->format('Y-m-d H:i:s');
            $endStr    = $end->format('Y-m-d H:i:s');
            $dateFmt   = $this->dateFormatExpr('createdAt', $groupBy);
            $appDateFmt = $this->dateFormatExpr('appliedAt', $groupBy);

            $db = \Config\Database::connect();

            // Jobs trend
            $jobRows = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM jobs
                 WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            // Applications trend
            $appRows = $db->query(
                "SELECT {$appDateFmt} AS label, COUNT(*) AS count
                 FROM applications
                 WHERE appliedAt >= ? AND appliedAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            // Candidates trend
            $candRows = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM users
                 WHERE role = 'CANDIDATE' AND createdAt >= ? AND createdAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            $jobsFilled   = $this->fillSeries($jobRows,   $startStr, $endStr, $groupBy);
            $appsFilled   = $this->fillSeries($appRows,   $startStr, $endStr, $groupBy);
            $candsFilled  = $this->fillSeries($candRows,  $startStr, $endStr, $groupBy);

            // Align all series to a common label set
            $labels = array_unique(array_merge(
                array_keys($jobsFilled),
                array_keys($appsFilled),
                array_keys($candsFilled)
            ));
            sort($labels);

            $series = ['jobs' => [], 'applications' => [], 'candidates' => []];
            foreach ($labels as $label) {
                $series['jobs'][]         = $jobsFilled[$label]  ?? 0;
                $series['applications'][] = $appsFilled[$label]  ?? 0;
                $series['candidates'][]   = $candsFilled[$label] ?? 0;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Growth trends retrieved',
                'data'    => [
                    'groupBy' => $groupBy,
                    'labels'  => array_values($labels),
                    'series'  => $series,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  3. GET /api/analytics/jobs
    // ==============================================================
    /**
     * @OA\Get(
     *     path="/api/analytics/jobs",
     *     tags={"Analytics"},
     *     summary="Job analytics – by status, type, experience level, category, top by views",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false,
     *         @OA\Schema(type="string", enum={"last_7_days","last_30_days","last_90_days","this_year","custom"})),
     *     @OA\Response(response="200", description="Job analytics retrieved")
     * )
     */
    public function getJobAnalytics()
    {
        try {
            $range    = $this->resolveDateRange();
            $startStr = $range['start']->format('Y-m-d H:i:s');
            $endStr   = $range['end']->format('Y-m-d H:i:s');

            $db = \Config\Database::connect();

            // By status
            $byStatus = $db->query(
                "SELECT status AS label, COUNT(*) AS count
                 FROM jobs WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY status ORDER BY count DESC",
                [$startStr, $endStr]
            )->getResultArray();

            // By type
            $byType = $db->query(
                "SELECT type AS label, COUNT(*) AS count
                 FROM jobs WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY type ORDER BY count DESC",
                [$startStr, $endStr]
            )->getResultArray();

            // By experience level
            $byExperienceLevel = $db->query(
                "SELECT experienceLevel AS label, COUNT(*) AS count
                 FROM jobs WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY experienceLevel ORDER BY count DESC",
                [$startStr, $endStr]
            )->getResultArray();

            // By category (join job_categories)
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

            // Top jobs by views (all time)
            $topByViews = $db->query(
                "SELECT j.id, j.title, j.views, j.applicationCount, c.name AS companyName
                 FROM jobs j
                 JOIN companies c ON j.companyId = c.id
                 WHERE j.status = 'ACTIVE'
                 ORDER BY j.views DESC
                 LIMIT 10"
            )->getResultArray();

            // Remote vs on-site
            $remoteVsOnsite = $db->query(
                "SELECT IF(isRemote = 1, 'Remote', 'On-site') AS label, COUNT(*) AS count
                 FROM jobs WHERE createdAt >= ? AND createdAt <= ?
                 GROUP BY isRemote",
                [$startStr, $endStr]
            )->getResultArray();

            // Featured / sponsored breakdown
            $featured  = (int) $db->table('jobs')->where('featured', 1)->countAllResults(false);
            $sponsored = (int) $db->table('jobs')->where('sponsored', 1)->countAllResults(false);

            return $this->respond([
                'success' => true,
                'message' => 'Job analytics retrieved',
                'data'    => [
                    'byStatus'         => $this->formatLabelCount($byStatus),
                    'byType'           => $this->formatLabelCount($byType),
                    'byExperienceLevel'=> $this->formatLabelCount($byExperienceLevel),
                    'byCategory'       => $this->formatLabelCount($byCategory),
                    'remoteVsOnsite'   => $this->formatLabelCount($remoteVsOnsite),
                    'topByViews'       => $topByViews,
                    'featured'         => $featured,
                    'sponsored'        => $sponsored,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  4. GET /api/analytics/applications
    // ==============================================================
    /**
     * @OA\Get(
     *     path="/api/analytics/applications",
     *     tags={"Analytics"},
     *     summary="Application analytics – funnel, time series, top hiring jobs",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false,
     *         @OA\Schema(type="string", enum={"last_7_days","last_30_days","last_90_days","this_year","custom"})),
     *     @OA\Response(response="200", description="Application analytics retrieved")
     * )
     */
    public function getApplicationAnalytics()
    {
        try {
            $range    = $this->resolveDateRange();
            $start    = $range['start'];
            $end      = $range['end'];
            $groupBy  = $range['groupBy'];
            $startStr = $start->format('Y-m-d H:i:s');
            $endStr   = $end->format('Y-m-d H:i:s');
            $dateFmt  = $this->dateFormatExpr('appliedAt', $groupBy);

            $db = \Config\Database::connect();

            // Funnel by status
            $byStatus = $db->query(
                "SELECT status AS label, COUNT(*) AS count
                 FROM applications
                 WHERE appliedAt >= ? AND appliedAt <= ?
                 GROUP BY status ORDER BY count DESC",
                [$startStr, $endStr]
            )->getResultArray();

            // Applications over time
            $overTime = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM applications
                 WHERE appliedAt >= ? AND appliedAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            $filled = $this->fillSeries($overTime, $startStr, $endStr, $groupBy);

            // Top jobs attracting most applications
            $topHiringJobs = $db->query(
                "SELECT j.id, j.title, j.applicationCount, c.name AS companyName
                 FROM jobs j
                 JOIN companies c ON j.companyId = c.id
                 WHERE j.status = 'ACTIVE'
                 ORDER BY j.applicationCount DESC
                 LIMIT 10"
            )->getResultArray();

            // Average reviews per day
            $reviewedCount = (int) $db->table('applications')
                ->where('reviewedAt IS NOT NULL')
                ->where('appliedAt >=', $startStr)
                ->where('appliedAt <=', $endStr)
                ->countAllResults(false);

            $intervalDays    = max(1, $range['intervalDays']);
            $avgDailyReviews = round($reviewedCount / $intervalDays, 1);

            // Withdrawal rate
            $withdrawnCount = (int) $db->table('applications')
                ->where('status', 'WITHDRAWN')
                ->where('appliedAt >=', $startStr)
                ->where('appliedAt <=', $endStr)
                ->countAllResults(false);

            $totalCount      = array_sum(array_column($byStatus, 'count'));
            $withdrawalRate  = $totalCount > 0 ? round(($withdrawnCount / $totalCount) * 100, 1) : 0.0;

            return $this->respond([
                'success' => true,
                'message' => 'Application analytics retrieved',
                'data'    => [
                    'byStatus'        => $this->formatLabelCount($byStatus),
                    'overTime'        => [
                        'groupBy' => $groupBy,
                        'labels'  => array_keys($filled),
                        'values'  => array_values($filled),
                    ],
                    'topHiringJobs'   => $topHiringJobs,
                    'avgDailyReviews' => $avgDailyReviews,
                    'withdrawalRate'  => $withdrawalRate,
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
            $range    = $this->resolveDateRange();
            $start    = $range['start'];
            $end      = $range['end'];
            $groupBy  = $range['groupBy'];
            $startStr = $start->format('Y-m-d H:i:s');
            $endStr   = $end->format('Y-m-d H:i:s');
            $dateFmt  = $this->dateFormatExpr('createdAt', $groupBy);

            $db = \Config\Database::connect();

            // Registrations over time
            $regRows = $db->query(
                "SELECT {$dateFmt} AS label, COUNT(*) AS count
                 FROM users
                 WHERE role = 'CANDIDATE' AND createdAt >= ? AND createdAt <= ?
                 GROUP BY label ORDER BY label ASC",
                [$startStr, $endStr]
            )->getResultArray();

            $filled = $this->fillSeries($regRows, $startStr, $endStr, $groupBy);

            // Top skills among candidates
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
                'data'    => [
                    'registrationsOverTime' => [
                        'groupBy' => $groupBy,
                        'labels'  => array_keys($filled),
                        'values'  => array_values($filled),
                    ],
                    'topSkills'             => $this->formatLabelCount($topSkills),
                    'topDomains'            => $this->formatLabelCount($topDomains),
                    'emailVerification'     => $this->formatLabelCount($emailVerified),
                    'byStatus'              => $this->formatLabelCount($byStatus),
                    'profileCompletionRate' => $profileCompletionRate,
                    'totalAllTime'          => $totalCandidatesAllTime,
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

            // Top jobs by applicationCount
            $topJobs = $db->query(
                "SELECT j.id, j.title, j.applicationCount, j.views, c.name AS companyName, c.logo AS companyLogo
                 FROM jobs j
                 JOIN companies c ON j.companyId = c.id
                 WHERE j.status = 'ACTIVE'
                 ORDER BY j.applicationCount DESC
                 LIMIT 10"
            )->getResultArray();

            $formattedJobs = array_map(fn ($j) => [
                'id'               => $j['id'],
                'title'            => $j['title'],
                'applicationCount' => (int) $j['applicationCount'],
                'views'            => (int) $j['views'],
                'company'          => ['name' => $j['companyName'], 'logo' => $j['companyLogo']],
            ], $topJobs);

            // Top employers by active job count
            $topEmployers = $db->query(
                "SELECT c.id, c.name, c.logo, COUNT(j.id) AS jobCount
                 FROM companies c
                 LEFT JOIN jobs j ON j.companyId = c.id AND j.status = 'ACTIVE'
                 WHERE c.status = 'VERIFIED'
                 GROUP BY c.id, c.name, c.logo
                 ORDER BY jobCount DESC
                 LIMIT 10"
            )->getResultArray();

            $formattedEmployers = array_map(fn ($e) => [
                'id'     => $e['id'],
                'name'   => $e['name'],
                'logo'   => $e['logo'],
                '_count' => ['jobs' => (int) $e['jobCount']],
            ], $topEmployers);

            // Top categories by job count
            $topCategories = $db->query(
                "SELECT jc.id, jc.name, COUNT(j.id) AS jobCount
                 FROM job_categories jc
                 LEFT JOIN jobs j ON j.categoryId = jc.id AND j.status = 'ACTIVE'
                 WHERE jc.isActive = 1
                 GROUP BY jc.id, jc.name
                 ORDER BY jobCount DESC
                 LIMIT 10"
            )->getResultArray();

            $formattedCategories = array_map(fn ($c) => [
                'id'       => $c['id'],
                'name'     => $c['name'],
                'jobCount' => (int) $c['jobCount'],
            ], $topCategories);

            return $this->respond([
                'success' => true,
                'message' => 'Top performers retrieved',
                'data'    => [
                    'topJobs'        => $formattedJobs,
                    'topEmployers'   => $formattedEmployers,
                    'topCategories'  => $formattedCategories,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ==============================================================
    //  7. GET /api/analytics/export
    // ==============================================================
    /**
     * @OA\Get(
     *     path="/api/analytics/export",
     *     tags={"Analytics"},
     *     summary="Export full analytics report as JSON",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false,
     *         @OA\Schema(type="string", enum={"last_7_days","last_30_days","last_90_days","this_year","custom"})),
     *     @OA\Response(response="200", description="Report exported")
     * )
     */
    public function exportReport()
    {
        try {
            $range    = $this->resolveDateRange();
            $startStr = $range['start']->format('Y-m-d H:i:s');
            $endStr   = $range['end']->format('Y-m-d H:i:s');

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

            $byStatus = $db->query(
                "SELECT status AS label, COUNT(*) AS count FROM jobs
                 WHERE createdAt >= ? AND createdAt <= ? GROUP BY status",
                [$startStr, $endStr]
            )->getResultArray();

            $appByStatus = $db->query(
                "SELECT status AS label, COUNT(*) AS count FROM applications
                 WHERE appliedAt >= ? AND appliedAt <= ? GROUP BY status",
                [$startStr, $endStr]
            )->getResultArray();

            return $this->respond([
                'success' => true,
                'message' => 'Report generated',
                'data'    => [
                    'generatedAt' => (new \DateTime())->format('c'),
                    'period'      => [
                        'start' => $range['start']->format('c'),
                        'end'   => $range['end']->format('c'),
                    ],
                    'summary' => [
                        'totalJobs'         => $totalJobs,
                        'totalApplications' => $totalApplications,
                        'totalCandidates'   => $totalCandidates,
                        'totalEmployers'    => $totalEmployers,
                    ],
                    'jobs'         => ['byStatus' => $this->formatLabelCount($byStatus)],
                    'applications' => ['byStatus' => $this->formatLabelCount($appByStatus)],
                ],
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'data' => []], 500);
        }
    }

    // ---------------------------------------------------------------
    // Private: normalise [{label,count}] → [{label, count(int)}]
    // ---------------------------------------------------------------
    private function formatLabelCount(array $rows): array
    {
        return array_map(fn ($r) => [
            'label' => $r['label'],
            'count' => (int) $r['count'],
        ], $rows);
    }
}