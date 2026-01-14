<?php

namespace App\Libraries;

use Config\Services;

class EmailHelper
{
    protected $email;
    protected $config;

    public function __construct()
    {
        $this->email  = Services::email();
        $this->config = config('Email');
    }

    // ============================================================
    // CORE SENDERS
    // ============================================================

    /**
     * Default email sender (uses configured from)
     */
    public function sendEmail($to, $subject, $message, $isHtml = true, $attachments = [])
    {
        return $this->sendEmailWithFrom(
            $to,
            $subject,
            $message,
            $isHtml,
            $attachments,
            $this->config->fromEmail,
            $this->config->fromName
        );
    }

    /**
     * Sales sender (FOR ADMIN NOTIFICATIONS)
     * Always from sales@fortunekenya.com
     */
    public function sendSalesEmail($to, $subject, $message, $isHtml = true, $attachments = [])
    {
        $fromEmail = env('email_from', 'sales@fortunekenya.com');
        $fromName  = env('email_fromName', 'Fortune Technologies Limited');

        return $this->sendEmailWithFrom($to, $subject, $message, $isHtml, $attachments, $fromEmail, $fromName);
    }

    /**
     * Recruitment sender (FOR CANDIDATES + RECRUITMENT OPS)
     * Always from headhunting@fortunekenya.com
     */
    public function sendRecruitmentEmail($to, $subject, $message, $isHtml = true, $attachments = [])
    {
        $fromEmail = env('email_fromRecruitment', 'headhunting@fortunekenya.com');
        $fromName  = env('email_fromRecruitmentName', 'Fortune Kenya Recruitment');

        return $this->sendEmailWithFrom($to, $subject, $message, $isHtml, $attachments, $fromEmail, $fromName);
    }

    /**
     * Low-level send
     */
    protected function sendEmailWithFrom($to, $subject, $message, $isHtml = true, $attachments = [], $fromEmail = null, $fromName = null)
    {
        try {
            $this->email->clear(true);

            $fromEmail = $fromEmail ?? $this->config->fromEmail;
            $fromName  = $fromName ?? $this->config->fromName;

            $this->email->setFrom($fromEmail, $fromName);
            $this->email->setTo($to);
            $this->email->setSubject($subject);

            $this->email->setMailType($isHtml ? 'html' : 'text');
            $this->email->setMessage($message);

            foreach ($attachments as $attachment) {
                if (empty($attachment['path'])) {
                    continue;
                }

                $path = $attachment['path'];
                if (!is_string($path) || trim($path) === '' || !file_exists($path)) {
                    log_message('error', 'Attachment file not found: ' . (string)$path);
                    continue;
                }

                $name = $attachment['name'] ?? null;
                $mime = $attachment['mime'] ?? null;

                if ($name && $mime) {
                    $this->email->attach($path, 'attachment', $name, $mime);
                } elseif ($name) {
                    $this->email->attach($path, 'attachment', $name);
                } else {
                    $this->email->attach($path);
                }
            }

            if ($this->email->send()) {
                log_message('info', 'Email sent successfully to: ' . $to);
                return ['success' => true, 'message' => 'Email sent successfully'];
            }

            $debug = $this->email->printDebugger(['headers', 'subject']);
            log_message('error', 'Email send failed: ' . $debug);

            return [
                'success' => false,
                'error'   => 'Failed to send email',
                'debug'   => $debug,
            ];
        } catch (\Throwable $e) {
            log_message('error', 'Email sending failed: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ============================================================
    // PROFESSIONAL WRAPPER + CTA COMPONENTS
    // ============================================================

    public function wrapEmailHtml(string $title, string $innerHtml): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return "<!DOCTYPE html>
<html>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1'>
  <title>{$safeTitle}</title>
</head>
<body style='margin:0; padding:0; background:#ffffff;'>
  <div style='width:100%; background:#ffffff; margin:0; padding:0;'>
    <div style='max-width:640px; margin:0 auto; padding:24px 20px; font-family: Arial, Helvetica, sans-serif; color:#202124; line-height:1.6; font-size:14px;'>
      <div style='margin:0 0 14px 0; font-size:18px; font-weight:700; color:#202124;'>{$safeTitle}</div>

      <div style='font-size:14px; color:#202124;'>
        {$innerHtml}
      </div>

      <div style='margin-top:18px; font-size:12px; color:#5f6368;'>
        This is an automated email. Please do not reply directly to this message.
      </div>
    </div>
  </div>

  <style>
    a { color:#1a73e8 !important; text-decoration:underline !important; }
  </style>
</body>
</html>";
    }

    /**
     * Email-safe blue CTA button (table-based)
     */
    protected function renderCtaButton(string $label, string $url): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeUrl   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return "
<table role='presentation' cellpadding='0' cellspacing='0' border='0' style='margin:14px 0 10px 0;'>
  <tr>
    <td bgcolor='#1a73e8' style='border-radius:6px;'>
      <a href='{$safeUrl}'
         style='display:inline-block; padding:12px 18px; font-size:14px; color:#ffffff !important; text-decoration:none; font-weight:700; border-radius:6px;'>
         {$safeLabel}
      </a>
    </td>
  </tr>
</table>";
    }

    /**
     * Fallback link that does NOT display the token in visible text.
     */
    protected function renderHiddenTokenLink(string $url, string $visibleText = 'Open link'): string
    {
        $safeUrl  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeText = htmlspecialchars($visibleText, ENT_QUOTES, 'UTF-8');

        return "<p style='margin-top:8px; font-size:12px; color:#5f6368;'>
If the button doesn't work, click: <a href='{$safeUrl}'>{$safeText}</a>
</p>";
    }

    // ============================================================
    // AUTHENTICATION & USER MANAGEMENT EMAILS
    // ============================================================

    public function sendVerificationEmail($to, $name, $verificationToken)
    {
        $frontendUrl     = $this->getFrontendUrl();
        $verificationUrl = $frontendUrl . '/verify-email?token=' . rawurlencode((string)$verificationToken);

        $subject = 'Verify Your Email Address - Fortune Technologies';

        $inner = "
<p>Dear " . htmlspecialchars((string)$name) . ",</p>
<p>Thank you for registering with Fortune Technologies. Please verify your email address by clicking the button below:</p>
" . $this->renderCtaButton('Verify Email', $verificationUrl) . "
" . $this->renderHiddenTokenLink($verificationUrl, 'Verify your email address') . "
<p style='margin-top:10px;'>This link will expire in 24 hours.</p>
<p>Best regards,<br>Fortune Technologies Team</p>";

        $html = $this->wrapEmailHtml('Verify Your Email', $inner);

        $fromEmail = env('email_fromAuth', env('email_from', $this->config->fromEmail));
        $fromName  = env('email_fromAuthName', env('email_fromName', $this->config->fromName));

        return $this->sendEmailWithFrom($to, $subject, $html, true, [], $fromEmail, $fromName);
    }

    public function sendPasswordResetEmail($to, $name, $resetToken)
    {
        $frontendUrl = $this->getFrontendUrl();
        $resetUrl    = $frontendUrl . '/reset-password?token=' . rawurlencode((string)$resetToken);

        $subject = 'Reset Your Password - Fortune Technologies';

        $inner = "
<p>Dear " . htmlspecialchars((string)$name) . ",</p>
<p>We received a request to reset your password. Click the button below to continue:</p>
" . $this->renderCtaButton('Reset Password', $resetUrl) . "
" . $this->renderHiddenTokenLink($resetUrl, 'Reset your password') . "
<p style='margin-top:10px;'>This link will expire in 1 hour. If you didn't request this, please ignore this email.</p>
<p>Best regards,<br>Fortune Technologies Team</p>";

        $html = $this->wrapEmailHtml('Reset Your Password', $inner);

        $fromEmail = env('email_fromAuth', env('email_from', $this->config->fromEmail));
        $fromName  = env('email_fromAuthName', env('email_fromName', $this->config->fromName));

        return $this->sendEmailWithFrom($to, $subject, $html, true, [], $fromEmail, $fromName);
    }

    // ============================================================
    // QUOTE & PRICING EMAILS
    // ============================================================

    public function sendQuote($to, $subject, $message, $recipientName, $company = null, $quoteAmount = null, $currency = 'KES', $attachments = [])
    {
        $amountHtml  = $quoteAmount ? "<p><strong>Quote Amount:</strong> " . htmlspecialchars($currency) . " " . number_format((float)$quoteAmount, 2) . "</p>" : '';
        $companyHtml = $company ? "<p><strong>Company:</strong> " . htmlspecialchars($company) . "</p>" : '';

        $inner = "
<p>Dear " . htmlspecialchars((string)$recipientName) . ",</p>
{$companyHtml}
<p>" . nl2br(htmlspecialchars((string)$message)) . "</p>
{$amountHtml}
<p>Best regards,<br>Fortune Technologies Team</p>";

        $html = $this->wrapEmailHtml('Fortune Technologies Limited', $inner);

        return $this->sendEmail($to, $subject, $html, true, $attachments);
    }

    public function notifyAdminNewQuote($quoteRequest)
    {
        $adminEmail = env('email_adminEmail', 'support@fortunekenya.com');
        $subject    = 'New Quote Request from ' . ($quoteRequest['company'] ?? $quoteRequest['name'] ?? 'Unknown');

        $services = is_array($quoteRequest['services'] ?? null)
            ? implode(', ', $quoteRequest['services'])
            : (string)($quoteRequest['services'] ?? 'N/A');

        $inner = "
<p><strong>Name:</strong> " . htmlspecialchars((string)($quoteRequest['name'] ?? '')) . "</p>
<p><strong>Email:</strong> " . htmlspecialchars((string)($quoteRequest['email'] ?? '')) . "</p>
<p><strong>Phone:</strong> " . htmlspecialchars((string)($quoteRequest['phone'] ?? 'N/A')) . "</p>
<p><strong>Company:</strong> " . htmlspecialchars((string)($quoteRequest['company'] ?? 'N/A')) . "</p>
<p><strong>Country:</strong> " . htmlspecialchars((string)($quoteRequest['country'] ?? 'N/A')) . "</p>
<p><strong>Industry:</strong> " . htmlspecialchars((string)($quoteRequest['industry'] ?? 'N/A')) . "</p>
<p><strong>Team Size:</strong> " . htmlspecialchars((string)($quoteRequest['teamSize'] ?? 'N/A')) . "</p>
<p><strong>Services:</strong> " . htmlspecialchars($services) . "</p>
<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars((string)($quoteRequest['message'] ?? 'N/A'))) . "</p>
<p><a href='" . htmlspecialchars($this->getFrontendUrl() . "/admin/pricing-request") . "'>View in Admin Panel</a></p>";

        $html = $this->wrapEmailHtml('New Quote Request', $inner);

        return $this->sendSalesEmail($adminEmail, $subject, $html, true);
    }

    // ============================================================
    // CONTACT FORM EMAILS
    // ============================================================

    public function notifyAdminNewContact($contactData)
    {
        $adminEmail = env('email_adminEmail', 'support@fortunekenya.com');
        $subject    = 'New Contact Form Submission from ' . ($contactData['name'] ?? 'Website Visitor');

        $inner = "
<p><strong>Name:</strong> " . htmlspecialchars((string)($contactData['name'] ?? '')) . "</p>
<p><strong>Email:</strong> " . htmlspecialchars((string)($contactData['email'] ?? '')) . "</p>
<p><strong>Phone:</strong> " . htmlspecialchars((string)($contactData['phone'] ?? 'N/A')) . "</p>
<p><strong>Company:</strong> " . htmlspecialchars((string)($contactData['company'] ?? 'N/A')) . "</p>
<p><strong>Service:</strong> " . htmlspecialchars((string)($contactData['service'] ?? 'N/A')) . "</p>
<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars((string)($contactData['message'] ?? 'N/A'))) . "</p>";

        $html = $this->wrapEmailHtml('New Contact Form Submission', $inner);

        return $this->sendSalesEmail($adminEmail, $subject, $html, true);
    }

    // ============================================================
    // RECRUITMENT: ADMIN NOTIFICATIONS
    // ============================================================

    public function notifyAdminNewApplication($applicationData)
    {
        $adminEmail = env('email_adminRecruitmentInbox', 'headhunting@fortunekenya.com');
        $subject = 'New Job Application - ' . ($applicationData['job']['title'] ?? 'Unknown Position');

        $html = $this->generateAdminApplicationNotificationHtml($applicationData);

        $attachments = [];
        if (!empty($applicationData['profile']['resumeUrl'])) {
            $resumeUrl  = (string)$applicationData['profile']['resumeUrl'];

            // Try public path first
            $resumePath = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($resumeUrl, '/\\');

            // fallback to previous behavior
            if (!file_exists($resumePath)) {
                $resumePath = WRITEPATH . '../public' . $resumeUrl;
            }

            if (file_exists($resumePath)) {
                $attachments[] = ['path' => $resumePath];
            }
        }

        // Recruitment operations should come from recruitment mailbox
        return $this->sendRecruitmentEmail($adminEmail, $subject, $html, true, $attachments);
    }

    protected function generateAdminApplicationNotificationHtml($applicationData)
    {
        $candidate = $applicationData['candidate'] ?? [];
        $profile   = $applicationData['profile'] ?? [];
        $job       = $applicationData['job'] ?? [];

        $expectedSalary = htmlspecialchars((string)($applicationData['expectedSalary'] ?? 'N/A'));

        $availableStart = 'N/A';
        if (!empty($applicationData['availableStartDate'])) {
            $availableStart = htmlspecialchars(date('F j, Y', strtotime($applicationData['availableStartDate'])));
        }

        $coverLetterHtml = '';
        if (isset($applicationData['coverLetter']) && trim((string)$applicationData['coverLetter']) !== '') {
            $coverLetterHtml = "<p><strong>Cover Letter:</strong><br>" .
                nl2br(htmlspecialchars((string)$applicationData['coverLetter'])) . "</p>";
        }

        $inner = "
<p><strong>Candidate</strong></p>
<p><strong>Name:</strong> " . htmlspecialchars(($candidate['firstName'] ?? '') . ' ' . ($candidate['lastName'] ?? '')) . "</p>
<p><strong>Email:</strong> " . htmlspecialchars((string)($candidate['email'] ?? 'N/A')) . "</p>
<p><strong>Phone:</strong> " . htmlspecialchars((string)($candidate['phone'] ?? 'N/A')) . "</p>
<p><strong>Title:</strong> " . htmlspecialchars((string)($profile['title'] ?? 'N/A')) . "</p>
<p><strong>Location:</strong> " . htmlspecialchars((string)($profile['location'] ?? 'N/A')) . "</p>
<p><strong>Experience:</strong> " . htmlspecialchars((string)($profile['experienceYears'] ?? 'N/A')) . " years</p>

<p style='margin-top:16px;'><strong>Application</strong></p>
<p><strong>Position:</strong> " . htmlspecialchars((string)($job['title'] ?? 'N/A')) . "</p>
<p><strong>Company:</strong> " . htmlspecialchars((string)(($job['company']['name'] ?? null) ?? 'N/A')) . "</p>
<p><strong>Expected Salary:</strong> {$expectedSalary}</p>
<p><strong>Available Start Date:</strong> {$availableStart}</p>

{$coverLetterHtml}
";

        return $this->wrapEmailHtml('New Job Application Received', $inner);
    }

    // ============================================================
    // RECRUITMENT: CANDIDATE EMAILS
    // ============================================================

    public function sendApplicationReceivedEmail(array $applicationData)
    {
        $candidate = $applicationData['candidate'] ?? [];
        $job       = $applicationData['job'] ?? [];

        $to = $candidate['email'] ?? null;
        if (!$to) {
            return ['success' => false, 'error' => 'Candidate email missing'];
        }

        $candidateName = trim(($candidate['firstName'] ?? '') . ' ' . ($candidate['lastName'] ?? ''));
        if ($candidateName === '') $candidateName = 'Candidate';

        $jobTitle     = $job['title'] ?? 'the advertised role';
        $companyName  = $job['company']['name'] ?? 'Fortune Kenya';
        $subject      = 'Application Submitted Successfully – ' . $jobTitle;

        $scheduledAt = $applicationData['appliedAt'] ?? null;
        $appliedAt   = $scheduledAt ? date('F j, Y \a\t g:i A', strtotime($this->normalizeDateTimeString((string)$scheduledAt))) : null;
        $appliedAtHtml = $appliedAt ? "<p><strong>Submitted:</strong> " . htmlspecialchars($appliedAt) . "</p>" : "";

        $expectedSalary     = htmlspecialchars((string)($applicationData['expectedSalary'] ?? 'N/A'));
        $availableStartDate = !empty($applicationData['availableStartDate'])
            ? htmlspecialchars(date('F j, Y', strtotime($applicationData['availableStartDate'])))
            : 'N/A';

        $frontendUrl = $this->getFrontendUrl();

        $inner = "
<p>Dear " . htmlspecialchars($candidateName) . ",</p>

<p>Thank you for applying for <strong>" . htmlspecialchars($jobTitle) . "</strong> at <strong>" . htmlspecialchars($companyName) . "</strong>. Your application has been successfully sent.</p>

<p><strong>Application Details</strong></p>
<p><strong>Position:</strong> " . htmlspecialchars($jobTitle) . "</p>
<p><strong>Company:</strong> " . htmlspecialchars($companyName) . "</p>
{$appliedAtHtml}
<p><strong>Expected Salary:</strong> {$expectedSalary}</p>
<p><strong>Available Start Date:</strong> {$availableStartDate}</p>

<p style='margin-top:16px;'><strong>Next Steps</strong></p>
<ul>
  <li>Our recruitment team will review your application.</li>
  <li>If shortlisted, you will receive an interview email from <strong>headhunting@fortunekenya.com</strong>.</li>
  <li>Please keep your profile and CV updated for the best chance of shortlisting.</li>
</ul>

<p>We appreciate your interest and wish you the best.</p>

<p>Kind regards,<br>
<strong>Fortune Kenya Recruitment Team</strong><br>
headhunting@fortunekenya.com<br>
<a href='" . htmlspecialchars($frontendUrl) . "'>" . htmlspecialchars($frontendUrl) . "</a></p>
";

        $html = $this->wrapEmailHtml('Application Submitted Successfully', $inner);

        return $this->sendRecruitmentEmail($to, $subject, $html, true);
    }

    public function sendApplicationRejectedEmail(array $applicationData)
    {
        $candidate = $applicationData['candidate'] ?? [];
        $job       = $applicationData['job'] ?? [];

        $to = $candidate['email'] ?? null;
        if (!$to) {
            return ['success' => false, 'error' => 'Candidate email missing'];
        }

        $candidateName = trim(($candidate['firstName'] ?? '') . ' ' . ($candidate['lastName'] ?? ''));
        if ($candidateName === '') $candidateName = 'Candidate';

        $jobTitle    = $job['title'] ?? 'the role';
        $companyName = $job['company']['name'] ?? 'Fortune Kenya';
        $subject     = 'Update on Your Application – ' . $jobTitle;

        $frontendUrl = $this->getFrontendUrl();

        $inner = "
<p>Dear " . htmlspecialchars($candidateName) . ",</p>

<p>Thank you for applying for <strong>" . htmlspecialchars($jobTitle) . "</strong> at <strong>" . htmlspecialchars($companyName) . "</strong>.</p>

<p>After careful review, we will not be progressing your application further at this time.</p>
<p>Please note this decision is based on the requirements for the role and the overall candidate pool, and does not diminish your skills or potential.</p>

<p style='margin-top:16px;'><strong>Next Steps</strong></p>
<ul>
  <li>We encourage you to apply for future roles that match your experience.</li>
  <li>Keep your CV/profile updated to increase your chances of being shortlisted.</li>
</ul>

<p>We appreciate your interest and wish you every success.</p>

<p>Kind regards,<br>
<strong>Fortune Kenya Recruitment Team</strong><br>
headhunting@fortunekenya.com<br>
<a href='" . htmlspecialchars($frontendUrl) . "'>" . htmlspecialchars($frontendUrl) . "</a></p>
";

        $html = $this->wrapEmailHtml('Update on Your Application', $inner);

        return $this->sendRecruitmentEmail($to, $subject, $html, true);
    }

    // ============================================================
    // INTERVIEW INVITATION WITH ICS (GMAIL SAFE)
    // ============================================================

    public function sendInterviewInvitation($to, $candidateName, $jobTitle, $scheduledAt, $details)
    {
        $subject = 'Interview Invitation – ' . $jobTitle;

        $html = null;

        try {
            $timezone        = $details['timezone'] ?? 'Africa/Nairobi';
            $durationMinutes = (int)($details['duration'] ?? 60);
            $type            = $details['type'] ?? 'VIDEO';

            $scheduledAtClean = $this->normalizeDateTimeString((string)$scheduledAt);
            $humanDate        = $this->formatHumanDateTime($scheduledAtClean, $timezone);

            $isOnline = in_array($type, ['VIDEO', 'PHONE'], true);
            $category = $isOnline ? 'Online Interview' : 'Physical Interview';

            $meetingLink = $details['meetingLink'] ?? null;
            $location    = $details['location'] ?? null;
            $notes       = $details['notes'] ?? null;

            $linkHtml = $meetingLink
                ? "<p><strong>Meeting Link:</strong> <a href='" . htmlspecialchars($meetingLink) . "'>" . htmlspecialchars($meetingLink) . "</a></p>"
                : "";

            $locationHtml = $location
                ? "<p><strong>Location:</strong> " . htmlspecialchars($location) . "</p>"
                : "";

            $notesHtml = $notes
                ? "<p><strong>Notes:</strong> " . nl2br(htmlspecialchars((string)$notes)) . "</p>"
                : "";

            $inner = "
<p>Dear " . htmlspecialchars((string)$candidateName) . ",</p>

<p>We are pleased to invite you for an interview for the position of <strong>" . htmlspecialchars((string)$jobTitle) . "</strong>.</p>

<p><strong>Interview Details</strong></p>
<p><strong>Category:</strong> " . htmlspecialchars((string)$category) . "</p>
<p><strong>Date &amp; Time:</strong> " . htmlspecialchars((string)$humanDate) . " (" . htmlspecialchars((string)$timezone) . ")</p>
<p><strong>Type:</strong> " . htmlspecialchars(str_replace('_', ' ', (string)$type)) . "</p>
<p><strong>Duration:</strong> " . (int)$durationMinutes . " minutes</p>
{$linkHtml}
{$locationHtml}
{$notesHtml}

<p style='margin-bottom:0;'><strong>Calendar Invite:</strong> Attached (.ics) — click to add it to Google/Outlook/Apple Calendar.</p>

<p>If you have a question, please contact us at <strong>headhunting@fortunekenya.com</strong>.</p>

<p>Kind regards,<br>
<strong>Fortune Kenya Recruitment Team</strong><br>
headhunting@fortunekenya.com</p>
";

            $html = $this->wrapEmailHtml('Interview Invitation', $inner);

            $icsContent = $this->generateInterviewIcsGmailSafe([
                'uid'             => uniqid('interview_', true) . '@fortunekenya.com',
                'summary'         => 'Interview: ' . (string)$jobTitle,
                'description'     => $this->buildIcsDescription((string)$jobTitle, (array)$details),
                'location'        => (string)($location ?: ($meetingLink ?: '')),
                'url'             => (string)($meetingLink ?: ''),
                'startLocal'      => $scheduledAtClean,
                'timezone'        => (string)$timezone,
                'durationMinutes' => (int)$durationMinutes,
                'organizerEmail'  => env('email_fromRecruitment', 'headhunting@fortunekenya.com'),
                'organizerName'   => env('email_fromRecruitmentName', 'Fortune Kenya Recruitment'),
                'attendeeEmail'   => (string)$to,
                'attendeeName'    => (string)$candidateName,
                'calName'         => 'Fortune Kenya Interviews',
            ]);

            $tmpDir = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0755, true);
            }

            $icsPath = $tmpDir . 'interview_' . time() . '_' . mt_rand(1000, 9999) . '.ics';
            $bytesWritten = file_put_contents($icsPath, $icsContent);

            if ($bytesWritten === false || !file_exists($icsPath) || !is_readable($icsPath)) {
                log_message('error', 'ICS file creation failed, sending without attachment');
                return $this->sendRecruitmentEmail($to, $subject, $html, true);
            }

            $attachments = [[
                'path' => $icsPath,
                'name' => 'interview-invite.ics',
                'mime' => 'text/calendar; charset=UTF-8; method=REQUEST',
            ]];

            $result = $this->sendRecruitmentEmail($to, $subject, $html, true, $attachments);

            @unlink($icsPath);

            return $result;
        } catch (\Throwable $e) {
            log_message('error', 'Interview invitation failed: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return $this->sendRecruitmentEmail($to, $subject, $html ?? 'Interview invitation', true);
        }
    }

    // ============================================================
    // ICS (GMAIL)
    // ============================================================

    protected function generateInterviewIcsGmailSafe(array $data): string
    {
        $tz = $data['timezone'] ?? 'Africa/Nairobi';

        $start = new \DateTime($data['startLocal'] ?? 'now', new \DateTimeZone($tz));
        $end   = clone $start;
        $end->modify('+' . (int)($data['durationMinutes'] ?? 60) . ' minutes');

        $startUtc = (clone $start)->setTimezone(new \DateTimeZone('UTC'));
        $endUtc   = (clone $end)->setTimezone(new \DateTimeZone('UTC'));

        $dtStart = $startUtc->format('Ymd\THis\Z');
        $dtEnd   = $endUtc->format('Ymd\THis\Z');
        $dtStamp = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        $uid     = $this->escapeIcsText((string)($data['uid'] ?? uniqid('interview_', true) . '@fortunekenya.com'));
        $summary = $this->escapeIcsText((string)($data['summary'] ?? 'Interview'));

        $descRaw = (string)($data['description'] ?? '');
        $descRaw = preg_replace("/\r\n|\r|\n/", "\n", $descRaw);
        if (strlen($descRaw) > 800) {
            $descRaw = substr($descRaw, 0, 780) . '...';
        }
        $description = $this->escapeIcsText($descRaw);

        $location = $this->escapeIcsText((string)($data['location'] ?? ''));
        $calName  = $this->escapeIcsText((string)($data['calName'] ?? 'Interviews'));

        $orgEmail = (string)($data['organizerEmail'] ?? 'headhunting@fortunekenya.com');
        $orgName  = $this->escapeIcsText((string)($data['organizerName'] ?? 'Fortune Kenya Recruitment'));

        $attEmail = (string)($data['attendeeEmail'] ?? '');
        $attName  = $this->escapeIcsText((string)($data['attendeeName'] ?? 'Candidate'));

        $url = trim((string)($data['url'] ?? ''));
        $url = $url !== '' ? $this->escapeIcsText($url) : '';

        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'PRODID:-//Fortune Kenya//Recruitment//EN';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:REQUEST';
        $lines[] = "X-WR-CALNAME:{$calName}";
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = "UID:{$uid}";
        $lines[] = "DTSTAMP:{$dtStamp}";
        $lines[] = "DTSTART:{$dtStart}";
        $lines[] = "DTEND:{$dtEnd}";
        $lines[] = "SUMMARY:{$summary}";

        if ($description !== '') $lines[] = "DESCRIPTION:{$description}";
        if ($location !== '')    $lines[] = "LOCATION:{$location}";
        if ($url !== '')         $lines[] = "URL:{$url}";

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'SEQUENCE:0';
        $lines[] = 'TRANSP:OPAQUE';

        $lines[] = "ORGANIZER;CN={$orgName}:mailto:{$orgEmail}";
        if ($attEmail !== '') {
            $lines[] = "ATTENDEE;CN={$attName};ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:{$attEmail}";
        }

        // Alarms
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-PT24H';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:Interview Reminder';
        $lines[] = 'END:VALARM';

        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-PT1H';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:Interview Starting Soon';
        $lines[] = 'END:VALARM';

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // CRITICAL: Use CRLF exactly
        return implode("\r\n", $lines) . "\r\n";
    }

    protected function buildIcsDescription(string $jobTitle, array $details): string
    {
        $parts = [];
        $parts[] = "Interview for: {$jobTitle}";
        if (!empty($details['type'])) {
            $parts[] = "Type: " . str_replace('_', ' ', (string)$details['type']);
        }
        if (!empty($details['meetingLink'])) $parts[] = "Meeting Link: " . (string)$details['meetingLink'];
        if (!empty($details['location']))    $parts[] = "Location: " . (string)$details['location'];
        if (!empty($details['notes']))       $parts[] = "Notes: " . preg_replace("/\r\n|\r|\n/", " ", (string)$details['notes']);
        $parts[] = "Contact: headhunting@fortunekenya.com";

        return implode("\n", $parts);
    }

    /**
     * RFC5545 TEXT escaping: \ ; , newline
     */
    protected function escapeIcsText(string $text): string
    {
        $text = preg_replace("/\r\n|\r|\n/", "\n", (string)$text);
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(';', '\;', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace("\n", '\n', $text);
        return $text;
    }

    // ============================================================
    // DATETIME HELPERS
    // ============================================================

    public function normalizeDateTimeString(string $input): string
    {
        $s = trim($input);
        $s = preg_replace('/\.\d+$/', '', $s);
        $s = str_replace('T', ' ', $s);
        $s = str_replace('Z', '', $s);

        $ts = strtotime($s);
        if ($ts === false) {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    public function formatHumanDateTime(string $dateTime, string $tz = 'Africa/Nairobi'): string
    {
        $dt = new \DateTime($dateTime, new \DateTimeZone($tz));
        return $dt->format('F j, Y \a\t g:i A');
    }

    // ============================================================
    // FRONTEND URL
    // ============================================================

    protected function getFrontendUrl()
    {
        $frontendUrl = env('FRONTEND_URL', '');

        if (empty($frontendUrl)) {
            return rtrim(base_url(), '/');
        }

        if (!preg_match('/^https?:\/\//', $frontendUrl)) {
            $frontendUrl = 'https://' . $frontendUrl;
        }

        return rtrim($frontendUrl, '/');
    }
}
