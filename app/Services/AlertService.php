<?php

namespace App\Services;

use App\Models\JobAlert;
use App\Models\NotificationQueue;
use App\Models\User;

/**
 * AlertService
 * Core logic for notifying users when jobs are published.
 *
 * CURRENT MODE: Broadcast – ALL users with emailNotifications=1 receive alerts
 * for EVERY new job. No keyword/location/type filtering is applied.
 *
 * FUTURE: Will implement the matching logic to enable per-user alert filtering.
    * This will allow users to create specific alerts (e.g. "Software Engineer in NY")
 */
class AlertService
{
    private JobAlert          $alertModel;
    private NotificationQueue $queueModel;
    private TemplateService   $templateService;

    public function __construct()
    {
        $this->alertModel      = new JobAlert();
        $this->queueModel      = new NotificationQueue();
        $this->templateService = new TemplateService();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CALLED WHEN A JOB IS PUBLISHED (ACTIVE)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run when a job is published.
     *
     * CURRENT: Sends to ALL users who have emailNotifications=1.
     *          No matching criteria applied.
     *
     * FUTURE:  Re-enable per-alert matching by uncommenting the filter block.
     */
    public function onJobPublished(array $job): int
    {
        if (!SettingService::bool('EMAIL_NOTIFICATIONS_ENABLED')) {
            log_message('info', 'AlertService: email notifications disabled globally, skipping.');
            return 0;
        }

        if (!SettingService::bool('INSTANT_ALERTS_ENABLED')) {
            log_message('info', 'AlertService: instant alerts disabled, skipping.');
            return 0;
        }

        $userModel = new User();

        // ── BROADCAST MODE: notify every opted-in user ───────────────────────
        $users = $userModel
            ->where('emailNotifications', 1)
            ->where('status', 'ACTIVE')
            ->findAll();

        $queued = 0;

        foreach ($users as $user) {
            $this->queueJobAlertEmail($user, $job);
            $queued++;
        }

        // ── FUTURE: Per-alert matching (uncomment to enable) ─────────────────
        // $matches = $this->alertModel->getInstantMatchesForJob($job);
        // foreach ($matches as $alert) {
        //     $user = $userModel->find($alert['userId']);
        //     if (!$user || empty($user['emailNotifications'])) continue;
        //     $this->queueJobAlertEmail($user, $job, $alert);
        //     $queued++;
        // }
        // ─────────────────────────────────────────────────────────────────────

        log_message('info', "AlertService: queued {$queued} instant alerts for job {$job['id']}");
        return $queued;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DAILY DIGEST
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called by CRON: send a digest of ALL jobs from the last 24h
     * to ALL users with emailNotifications=1.
     */
    public function sendDailyDigest(): void
    {
        if (!SettingService::bool('EMAIL_NOTIFICATIONS_ENABLED')) return;
        if (!SettingService::bool('DAILY_DIGEST_ENABLED')) return;

        $jobModel  = new \App\Models\Job();
        $userModel = new User();

        // Get all jobs published in last 24 hours
        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $jobs  = $this->getNewJobsSince($jobModel, $since);

        if (empty($jobs)) {
            log_message('info', 'AlertService: no new jobs in last 24h, skipping daily digest.');
            return;
        }

        // Get all opted-in users
        $users = $userModel
            ->where('emailNotifications', 1)
            ->where('status', 'ACTIVE')
            ->findAll();

        $frontendUrl = SettingService::get('FRONTEND_URL', base_url());

        foreach ($users as $user) {
            $unsubscribeUrl = $frontendUrl . '/careers-portal/unsubscribe?token=' . ($user['unsubscribeToken'] ?? '');

            $rendered = $this->templateService->render('daily_digest', [
                'name'             => trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? '')),
                'date'             => date('F j, Y'),
                'alerts'           => [
                    [
                        'alert_name' => 'New Opportunities',
                        'jobs'       => $jobs,
                    ],
                ],
                'portal_link'      => $frontendUrl . '/careers-portal/jobs',
                'unsubscribe_link' => $unsubscribeUrl,
            ]);

            if ($rendered) {
                $this->queueModel->enqueue([
                    'userId'   => $user['id'],
                    'type'     => 'DIGEST',
                    'subject'  => $rendered['subject'],
                    'bodyHtml' => $rendered['body'],
                    'toEmail'  => $user['email'],
                ]);
            }
        }

        log_message('info', 'AlertService: daily digest queued for ' . count($users) . ' users with ' . count($jobs) . ' jobs.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WEEKLY DIGEST
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called by CRON: send a digest of ALL jobs from the last 7 days
     * to ALL users with emailNotifications=1.
     */
    public function sendWeeklyDigest(): void
    {
        if (!SettingService::bool('EMAIL_NOTIFICATIONS_ENABLED')) return;
        if (!SettingService::bool('WEEKLY_DIGEST_ENABLED')) return;

        $jobModel  = new \App\Models\Job();
        $userModel = new User();

        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        $jobs  = $this->getNewJobsSince($jobModel, $since);

        if (empty($jobs)) {
            log_message('info', 'AlertService: no new jobs in last 7 days, skipping weekly digest.');
            return;
        }

        $users = $userModel
            ->where('emailNotifications', 1)
            ->where('status', 'ACTIVE')
            ->findAll();

        $frontendUrl = SettingService::get('FRONTEND_URL', base_url());

        foreach ($users as $user) {
            $unsubscribeUrl = $frontendUrl . '/careers-portal/unsubscribe?token=' . ($user['unsubscribeToken'] ?? '');

            $rendered = $this->templateService->render('weekly_digest', [
                'name'             => trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? '')),
                'week_start'       => date('F j, Y', strtotime('last Monday')),
                'alerts'           => [
                    [
                        'alert_name' => 'This Week\'s Opportunities',
                        'jobs'       => $jobs,
                    ],
                ],
                'portal_link'      => $frontendUrl . '/careers-portal/jobs',
                'unsubscribe_link' => $unsubscribeUrl,
            ]);

            if ($rendered) {
                $this->queueModel->enqueue([
                    'userId'   => $user['id'],
                    'type'     => 'DIGEST',
                    'subject'  => $rendered['subject'],
                    'bodyHtml' => $rendered['body'],
                    'toEmail'  => $user['email'],
                ]);
            }
        }

        log_message('info', 'AlertService: weekly digest queued for ' . count($users) . ' users with ' . count($jobs) . ' jobs.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Queue a single job alert email for one user.
     * The optional $alert param is for FUTURE per-alert matching.
     */
    private function queueJobAlertEmail(array $user, array $job, ?array $alert = null): void
    {
        $frontendUrl    = SettingService::get('FRONTEND_URL', base_url());
        $jobSlug        = $job['slug'] ?? $job['id'];
        $jobUrl         = $frontendUrl . '/careers-portal/jobs/job-detail?id=' . ($job['slug'] ?? $job['id']);
        $unsubscribeUrl = $frontendUrl . '/careers-portal/unsubscribe?token=' . ($user['unsubscribeToken'] ?? '');

        // Get company name if available
        $companyName = '';
        if (!empty($job['companyId'])) {
            $companyModel = new \App\Models\Company();
            $company      = $companyModel->select('name')->find($job['companyId']);
            $companyName  = $company['name'] ?? '';
        }

        $rendered = $this->templateService->render('job_alert', [
            'name'             => trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? '')),
            'keyword'          => $job['title'] ?? 'Job',
            'jobs'             => [[
                'title'    => $job['title'] ?? '',
                'location' => $job['location'] ?? '',
                'type'     => $job['type'] ?? '',
                'company'  => $companyName,
                'job_link' => $jobUrl,
            ]],
            'jobs_link'        => $jobUrl,
            'unsubscribe_link' => $unsubscribeUrl,
        ]);

        if (!$rendered) return;

        $this->queueModel->enqueue([
            'userId'   => $user['id'],
            'jobId'    => $job['id'],
            'alertId'  => $alert['id'] ?? null,
            'type'     => 'JOB_ALERT',
            'subject'  => $rendered['subject'],
            'bodyHtml' => $rendered['body'],
            'toEmail'  => $user['email'],
        ]);
    }

    /**
     * Get all ACTIVE jobs published since a given datetime.
     * Returns them formatted for template rendering.
     */
    private function getNewJobsSince(\App\Models\Job $jobModel, string $since): array
    {
        $jobs = $jobModel
            ->where('status', 'ACTIVE')
            ->where('publishedAt >=', $since)
            ->orderBy('publishedAt', 'DESC')
            ->findAll();

        $frontendUrl  = SettingService::get('FRONTEND_URL', base_url());
        $companyModel = new \App\Models\Company();
        $formatted    = [];

        foreach ($jobs as $job) {
            $company     = !empty($job['companyId']) ? $companyModel->select('name')->find($job['companyId']) : null;
            $formatted[] = [
                'title'    => $job['title'] ?? '',
                'company'  => $company['name'] ?? '',
                'location' => $job['location'] ?? '',
                'type'     => $job['type'] ?? '',
                'job_link' => $frontendUrl . '/careers-portal/jobs/job-detail?id=' . ($job['slug'] ?? $job['id']),
            ];
        }

        return $formatted;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FUTURE: Per-alert matching helpers (kept for when matching is re-enabled)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Group an array of alerts by userId.
     */
    private function groupAlertsByUser(array $alerts): array
    {
        $byUser = [];
        foreach ($alerts as $a) {
            $byUser[$a['userId']][] = $a;
        }
        return $byUser;
    }

    /**
     * Get new jobs since $since that match a specific alert's criteria.
     */
    private function getMatchingJobsSince(\App\Models\Job $jobModel, array $alert, string $since): array
    {
        $jobs = $jobModel->where('status', 'ACTIVE')
                         ->where('publishedAt >=', $since)
                         ->findAll();

        $frontendUrl = SettingService::get('FRONTEND_URL', base_url());
        $matched     = [];

        foreach ($jobs as $job) {
            if ($this->alertModel->matches($job, $alert)) {
                $matched[] = [
                    'title'    => $job['title'],
                    'company'  => '',
                    'location' => $job['location'] ?? '',
                    'type'     => $job['type'] ?? '',
                    'job_link' => $frontendUrl . '/careers-portal/jobs/job-detail?id=' . ($job['slug'] ?? $job['id']),
                ];
            }
        }

        return $matched;
    }
}
