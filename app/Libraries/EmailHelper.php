<?php

namespace App\Libraries;

use Config\Services;

class EmailHelper
{
    protected $email;
    protected $config;

    public function __construct()
    {
        $this->email = Services::email();
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
     * Recruitment sender (FOR CANDIDATES: apply, interview, rejected)
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
            $this->email->setTo($to);

            $fromEmail = $fromEmail ?? $this->config->fromEmail;
            $fromName  = $fromName ?? $this->config->fromName;

            $this->email->setFrom($fromEmail, $fromName);
            $this->email->setSubject($subject);

            if ($isHtml) {
                $this->email->setMailType('html');
            }

            $this->email->setMessage($message);

            foreach ($attachments as $attachment) {
                if (!isset($attachment['path'])) {
                    continue;
                }

                $path = $attachment['path'];
                if (!is_string($path) || trim($path) === '' || !file_exists($path)) {
                    log_message('error', 'Attachment file not found: ' . $path);
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

            log_message('error', 'Email send failed: ' . $this->email->printDebugger(['headers']));
            return [
                'success' => false,
                'error' => 'Failed to send email',
                'debug' => $this->email->printDebugger(['headers'])
            ];
        } catch (\Exception $e) {
            log_message('error', 'Email sending failed: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ============================================================
    // AUTHENTICATION & USER MANAGEMENT EMAILS
    // ============================================================

    /**
     * Send verification email
     */
    public function sendVerificationEmail($to, $name, $verificationToken)
    {
        $frontendUrl = $this->getFrontendUrl();
        $verificationUrl = $frontendUrl . '/verify-email?token=' . $verificationToken;
        $subject = 'Verify Your Email Address - Fortune Technologies';
        $html = $this->generateVerificationEmailHtml($name, $verificationUrl);
        
        // Use auth-specific from address
        $fromEmail = env('email_fromAuth', env('email_from', $this->config->fromEmail));
        $fromName = env('email_fromAuthName', env('email_fromName', $this->config->fromName));
        
        return $this->sendEmailWithFrom($to, $subject, $html, true, [], $fromEmail, $fromName);
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($to, $name, $resetToken)
    {
        $frontendUrl = $this->getFrontendUrl();
        $resetUrl = $frontendUrl . '/reset-password?token=' . $resetToken;
        $subject = 'Reset Your Password - Fortune Technologies';
        $html = $this->generatePasswordResetEmailHtml($name, $resetUrl);
        return $this->sendEmail($to, $subject, $html, true);
    }

    protected function generateVerificationEmailHtml($name, $verificationUrl)
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0066cc; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .button { display: inline-block; padding: 12px 24px; background: #0066cc; color: #ffffff !important; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Verify Your Email</h1>
                </div>
                <div class='content'>
                    <p>Dear {$name},</p>
                    <p>Thank you for registering with Fortune Technologies. Please verify your email address by clicking the button below:</p>
                    <p style='text-align: center;'><a href='{$verificationUrl}' class='button'>Verify Email</a></p>
                    <p>This link will expire in 24 hours.</p>
                    <p>Best regards,<br>Fortune Technologies Team</p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply directly to this message.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    protected function generatePasswordResetEmailHtml($name, $resetUrl)
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0066cc; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .button { display: inline-block; padding: 12px 24px; background: #0066cc; color: #ffffff !important; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Reset Your Password</h1>
                </div>
                <div class='content'>
                    <p>Dear {$name},</p>
                    <p>We received a request to reset your password. Click the button below to reset it:</p>
                    <p style='text-align: center;'><a href='{$resetUrl}' class='button'>Reset Password</a></p>
                    <p>This link will expire in 1 hour. If you didn't request this, please ignore this email.</p>
                    <p>Best regards,<br>Fortune Technologies Team</p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply directly to this message.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    // ============================================================
    // QUOTE & PRICING EMAILS
    // ============================================================

    /**
     * Send quote email
     */
    public function sendQuote($to, $subject, $message, $recipientName, $company = null, $quoteAmount = null, $currency = 'KES', $attachments = [])
    {
        $html = $this->generateQuoteEmailHtml($subject, $message, $recipientName, $company, $quoteAmount, $currency);
        return $this->sendEmail($to, $subject, $html, true, $attachments);
    }

    /**
     * Notify admin about new quote request
     */
    public function notifyAdminNewQuote($quoteRequest)
    {
        $adminEmail = env('email_adminEmail', 'support@fortunekenya.com');
        $subject = 'New Quote Request from ' . ($quoteRequest['company'] ?? $quoteRequest['name']);
        $html = $this->generateAdminQuoteNotificationHtml($quoteRequest);
        return $this->sendEmail($adminEmail, $subject, $html, true);
    }

    protected function generateQuoteEmailHtml($subject, $message, $recipientName, $company = null, $quoteAmount = null, $currency = 'KES')
    {
        $amountHtml = $quoteAmount ? "<p><strong>Quote Amount:</strong> {$currency} " . number_format($quoteAmount, 2) . "</p>" : '';
        $companyHtml = $company ? "<p><strong>Company:</strong> {$company}</p>" : '';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0066cc; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Fortune Technologies Limited</h1>
                </div>
                <div class='content'>
                    <p>Dear {$recipientName},</p>
                    {$companyHtml}
                    <p>{$message}</p>
                    {$amountHtml}
                    <p>Best regards,<br>Fortune Technologies Team</p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply directly to this message.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    protected function generateAdminQuoteNotificationHtml($quoteRequest)
    {
        $services = is_array($quoteRequest['services']) ? implode(', ', $quoteRequest['services']) : $quoteRequest['services'];
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0066cc; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .info { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #0066cc; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>New Quote Request</h1>
                </div>
                <div class='content'>
                    <div class='info'>
                        <p><strong>Name:</strong> {$quoteRequest['name']}</p>
                        <p><strong>Email:</strong> {$quoteRequest['email']}</p>
                        <p><strong>Phone:</strong> " . ($quoteRequest['phone'] ?? 'N/A') . "</p>
                        <p><strong>Company:</strong> " . ($quoteRequest['company'] ?? 'N/A') . "</p>
                        <p><strong>Country:</strong> " . ($quoteRequest['country'] ?? 'N/A') . "</p>
                        <p><strong>Industry:</strong> " . ($quoteRequest['industry'] ?? 'N/A') . "</p>
                        <p><strong>Team Size:</strong> " . ($quoteRequest['teamSize'] ?? 'N/A') . "</p>
                        <p><strong>Services:</strong> {$services}</p>
                        <p><strong>Message:</strong> " . ($quoteRequest['message'] ?? 'N/A') . "</p>
                    </div>
                    <p><a href='" . $this->getFrontendUrl() . "/admin/pricing-request'>View in Admin Panel</a></p>
                </div>
                <div class='footer'>
                    <p>This is an automated notification email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    // ============================================================
    // CONTACT FORM EMAILS
    // ============================================================

    /**
     * Notify admin about new contact submission
     */
    public function notifyAdminNewContact($contactData)
    {
        $adminEmail = env('email_adminEmail', 'support@fortunekenya.com');
        $subject = 'New Contact Form Submission from ' . ($contactData['name'] ?? 'Website Visitor');
        $html = $this->generateAdminContactNotificationHtml($contactData);
        return $this->sendEmail($adminEmail, $subject, $html, true);
    }

    protected function generateAdminContactNotificationHtml($contactData)
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0066cc; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .info { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #0066cc; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>New Contact Form Submission</h1>
                </div>
                <div class='content'>
                    <div class='info'>
                        <p><strong>Name:</strong> {$contactData['name']}</p>
                        <p><strong>Email:</strong> {$contactData['email']}</p>
                        <p><strong>Phone:</strong> " . ($contactData['phone'] ?? 'N/A') . "</p>
                        <p><strong>Company:</strong> " . ($contactData['company'] ?? 'N/A') . "</p>
                        <p><strong>Service:</strong> " . ($contactData['service'] ?? 'N/A') . "</p>
                        <p><strong>Message:</strong> " . ($contactData['message'] ?? 'N/A') . "</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated notification email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    // ============================================================
    // RECRUITMENT: ADMIN NOTIFICATIONS
    // ============================================================

    /**
     * ADMIN: NEW JOB APPLICATION NOTIFICATION
     * To: headhunting@fortunekenya.com
     * From: sales@fortunekenya.com
     */
    public function notifyAdminNewApplication($applicationData)
    {
        $adminEmail = env('email_adminRecruitmentInbox', 'headhunting@fortunekenya.com');

        $subject = 'New Job Application - ' . ($applicationData['job']['title'] ?? 'Unknown Position');

        $html = $this->generateAdminApplicationNotificationHtml($applicationData);

        $attachments = [];
        if (isset($applicationData['profile']['resumeUrl']) && !empty($applicationData['profile']['resumeUrl'])) {
            $resumePath = WRITEPATH . '../public' . $applicationData['profile']['resumeUrl'];
            if (file_exists($resumePath)) {
                $attachments[] = ['path' => $resumePath];
            }
        }

        // IMPORTANT: send from SALES to ADMIN
        return $this->sendSalesEmail($adminEmail, $subject, $html, true, $attachments);
    }

    protected function generateAdminApplicationNotificationHtml($applicationData)
    {
        $candidate = $applicationData['candidate'] ?? [];
        $profile = $applicationData['profile'] ?? [];
        $job = $applicationData['job'] ?? [];

        $expectedSalary = htmlspecialchars((string)($applicationData['expectedSalary'] ?? 'N/A'));

        $availableStart = 'N/A';
        if (!empty($applicationData['availableStartDate'])) {
            $availableStart = htmlspecialchars(date('F j, Y', strtotime($applicationData['availableStartDate'])));
        }

        $coverLetterHtml = '';
        if (isset($applicationData['coverLetter']) && trim((string)$applicationData['coverLetter']) !== '') {
            $coverLetterHtml = "<div class='info'><h3 style='margin-top:0;'>Cover Letter</h3><p>" .
                nl2br(htmlspecialchars((string)$applicationData['coverLetter'])) .
                "</p></div>";
        }

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 700px; margin: 0 auto; padding: 20px; }
                .header { background: #0b5ed7; color: white; padding: 18px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { padding: 20px; background: #f9f9f9; border-radius: 0 0 8px 8px; }
                .info { background: white; padding: 15px; margin: 12px 0; border-left: 4px solid #0b5ed7; border-radius: 6px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin:0;'>New Job Application Received</h2>
                </div>
                <div class='content'>
                    <div class='info'>
                        <h3 style='margin-top:0;'>Candidate</h3>
                        <p><strong>Name:</strong> " . ($candidate['firstName'] ?? '') . " " . ($candidate['lastName'] ?? '') . "</p>
                        <p><strong>Email:</strong> " . ($candidate['email'] ?? 'N/A') . "</p>
                        <p><strong>Phone:</strong> " . ($candidate['phone'] ?? 'N/A') . "</p>
                        <p><strong>Title:</strong> " . ($profile['title'] ?? 'N/A') . "</p>
                        <p><strong>Location:</strong> " . ($profile['location'] ?? 'N/A') . "</p>
                        <p><strong>Experience:</strong> " . ($profile['experienceYears'] ?? 'N/A') . " years</p>
                    </div>

                    <div class='info'>
                        <h3 style='margin-top:0;'>Application</h3>
                        <p><strong>Position:</strong> " . ($job['title'] ?? 'N/A') . "</p>
                        <p><strong>Company:</strong> " . (($job['company']['name'] ?? null) ?? 'N/A') . "</p>
                        <p><strong>Expected Salary:</strong> {$expectedSalary}</p>
                        <p><strong>Available Start Date:</strong> {$availableStart}</p>
                    </div>

                    {$coverLetterHtml}
                </div>
            </div>
        </body>
        </html>
        ";
    }

    // ============================================================
    // RECRUITMENT: CANDIDATE EMAILS
    // ============================================================

    /**
     * CANDIDATE: APPLICATION RECEIVED (From recruitment)
     */
    public function sendApplicationReceivedEmail(array $applicationData)
    {
        $candidate = $applicationData['candidate'] ?? [];
        $job = $applicationData['job'] ?? [];

        $to = $candidate['email'] ?? null;
        if (!$to) {
            return ['success' => false, 'error' => 'Candidate email missing'];
        }

        $candidateName = trim(($candidate['firstName'] ?? '') . ' ' . ($candidate['lastName'] ?? ''));
        if ($candidateName === '') {
            $candidateName = 'Candidate';
        }

        $jobTitle = $job['title'] ?? 'the advertised role';
        $companyName = $job['company']['name'] ?? 'Fortune Kenya';

        $subject = 'Application Submitted Successfully – ' . $jobTitle;

        $html = $this->generateApplicationReceivedEmailHtml([
            'candidateName' => $candidateName,
            'jobTitle' => $jobTitle,
            'companyName' => $companyName,
            'appliedAt' => $applicationData['appliedAt'] ?? null,
            'expectedSalary' => $applicationData['expectedSalary'] ?? null,
            'availableStartDate' => $applicationData['availableStartDate'] ?? null,
            'frontendUrl' => $this->getFrontendUrl(),
        ]);

        return $this->sendRecruitmentEmail($to, $subject, $html, true);
    }

    protected function generateApplicationReceivedEmailHtml(array $data)
    {
        $candidateName = htmlspecialchars($data['candidateName'] ?? 'Candidate');
        $jobTitle = htmlspecialchars($data['jobTitle'] ?? 'Role');
        $companyName = htmlspecialchars($data['companyName'] ?? 'Fortune Kenya');

        $appliedAt = $data['appliedAt'] ? date('F j, Y \a\t g:i A', strtotime($data['appliedAt'])) : null;

        $expectedSalary = htmlspecialchars((string)($data['expectedSalary'] ?? 'N/A'));
        $availableStartDate = $data['availableStartDate']
            ? htmlspecialchars(date('F j, Y', strtotime($data['availableStartDate'])))
            : 'N/A';

        $frontendUrl = $data['frontendUrl'] ?? $this->getFrontendUrl();

        $appliedAtHtml = $appliedAt ? "<p><strong>Submitted:</strong> {$appliedAt}</p>" : "";

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #222; }
                .container { max-width: 700px; margin: 0 auto; padding: 22px; }
                .header { background: #0b5ed7; color: #fff; padding: 18px 20px; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; }
                .card { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; margin: 14px 0; }
                ul li { margin: 8px 0; }
                .footer { margin-top: 18px; font-size: 12px; color: #6c757d; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin:0;'>Application Submitted Successfully</h2>
                    <p style='margin:6px 0 0 0;'>Fortune Kenya Recruitment</p>
                </div>

                <div class='content'>
                    <p>Dear {$candidateName},</p>

                    <p>Thank you for applying for <strong>{$jobTitle}</strong> at <strong>{$companyName}</strong>. Your application has been successfully sent.</p>

                    <div class='card'>
                        <h3 style='margin-top:0;'>Application Details</h3>
                        <p><strong>Position:</strong> {$jobTitle}</p>
                        <p><strong>Company:</strong> {$companyName}</p>
                        {$appliedAtHtml}
                        <p><strong>Expected Salary:</strong> {$expectedSalary}</p>
                        <p><strong>Available Start Date:</strong> {$availableStartDate}</p>
                    </div>

                    <div class='card'>
                        <h3 style='margin-top:0;'>Next Steps</h3>
                        <ul>
                            <li>Our recruitment team will review your application.</li>
                            <li>If shortlisted, you will receive an interview email from <strong>headhunting@fortunekenya.com</strong>.</li>
                            <li>Please keep your profile and CV updated for the best chance of shortlisting.</li>
                        </ul>
                    </div>

                    <p>We appreciate your interest and wish you the best.</p>

                    <p>Kind regards,<br>
                    <strong>Fortune Kenya Recruitment Team</strong><br>
                    headhunting@fortunekenya.com<br>
                    <a href='{$frontendUrl}'>{$frontendUrl}</a></p>

                    <div class='footer'>
                        <p>This is an automated email. Please do not reply directly to this message.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * CANDIDATE: REJECTION EMAIL (From recruitment)
     */
    public function sendApplicationRejectedEmail(array $applicationData)
    {
        $candidate = $applicationData['candidate'] ?? [];
        $job = $applicationData['job'] ?? [];

        $to = $candidate['email'] ?? null;
        if (!$to) {
            return ['success' => false, 'error' => 'Candidate email missing'];
        }

        $candidateName = trim(($candidate['firstName'] ?? '') . ' ' . ($candidate['lastName'] ?? ''));
        if ($candidateName === '') {
            $candidateName = 'Candidate';
        }

        $jobTitle = $job['title'] ?? 'the role';
        $companyName = $job['company']['name'] ?? 'Fortune Kenya';

        $subject = 'Update on Your Application – ' . $jobTitle;

        $html = $this->generateApplicationRejectedEmailHtml([
            'candidateName' => $candidateName,
            'jobTitle' => $jobTitle,
            'companyName' => $companyName,
            'frontendUrl' => $this->getFrontendUrl(),
        ]);

        return $this->sendRecruitmentEmail($to, $subject, $html, true);
    }

    protected function generateApplicationRejectedEmailHtml(array $data)
    {
        $candidateName = htmlspecialchars($data['candidateName'] ?? 'Candidate');
        $jobTitle = htmlspecialchars($data['jobTitle'] ?? 'Role');
        $companyName = htmlspecialchars($data['companyName'] ?? 'Fortune Kenya');
        $frontendUrl = $data['frontendUrl'] ?? $this->getFrontendUrl();

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #222; }
                .container { max-width: 700px; margin: 0 auto; padding: 22px; }
                .header { background: #212529; color: #fff; padding: 18px 20px; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; }
                .card { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; margin: 14px 0; }
                .footer { margin-top: 18px; font-size: 12px; color: #6c757d; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin:0;'>Update on Your Application</h2>
                    <p style='margin:6px 0 0 0;'>Fortune Kenya Recruitment</p>
                </div>

                <div class='content'>
                    <p>Dear {$candidateName},</p>

                    <p>Thank you for applying for <strong>{$jobTitle}</strong> at <strong>{$companyName}</strong>.</p>

                    <div class='card'>
                        <p>After careful review, we will not be progressing your application further at this time.</p>
                        <p>Please note this decision is based on the requirements for the role and the overall candidate pool, and does not diminish your skills or potential.</p>
                    </div>

                    <div class='card'>
                        <h3 style='margin-top:0;'>Next Steps</h3>
                        <ul>
                            <li>We encourage you to apply for future roles that match your experience.</li>
                            <li>Keep your CV/profile updated to increase your chances of being shortlisted.</li>
                        </ul>
                    </div>

                    <p>We appreciate your interest and wish you every success.</p>

                    <p>Kind regards,<br>
                    <strong>Fortune Kenya Recruitment Team</strong><br>
                    headhunting@fortunekenya.com<br>
                    <a href='{$frontendUrl}'>{$frontendUrl}</a></p>

                    <div class='footer'>
                        <p>This is an automated email. Please do not reply directly to this message.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * INTERVIEW INVITATION WITH ICS (From recruitment)
     */
    public function sendInterviewInvitation($to, $candidateName, $jobTitle, $scheduledAt, $details)
    {
        try {
            $subject = 'Interview Invitation – ' . $jobTitle;

            $timezone = $details['timezone'] ?? 'Africa/Nairobi';
            $durationMinutes = (int)($details['duration'] ?? 60);
            $type = $details['type'] ?? 'VIDEO';

            $humanDate = date('F j, Y \a\t g:i A', strtotime($scheduledAt));

            // Determine if online or physical
            $isOnline = in_array($type, ['VIDEO', 'PHONE']);
            $category = $isOnline ? 'Online Interview' : 'Physical Interview';

            $html = $this->generateInterviewInvitationEmailHtml([
                'candidateName' => $candidateName,
                'jobTitle' => $jobTitle,
                'scheduledHuman' => $humanDate,
                'type' => $type,
                'category' => $category,
                'isOnline' => $isOnline,
                'duration' => $durationMinutes,
                'meetingLink' => $details['meetingLink'] ?? null,
                'location' => $details['location'] ?? null,
                'notes' => $details['notes'] ?? null,
                'timezone' => $timezone,
            ]);

            $icsContent = $this->generateInterviewIcs([
                'uid' => uniqid('interview_', true) . '@fortunekenya.com',
                'summary' => 'Interview: ' . $jobTitle,
                'description' => $this->buildIcsDescription($jobTitle, $details),
                'location' => $details['location'] ?? ($details['meetingLink'] ?? ''),
                'start' => $scheduledAt,
                'durationMinutes' => $durationMinutes,
                'timezone' => $timezone,
                'organizerEmail' => env('email_fromRecruitment', 'headhunting@fortunekenya.com'),
                'organizerName' => env('email_fromRecruitmentName', 'Fortune Kenya Recruitment'),
                'attendeeEmail' => $to,
                'attendeeName' => $candidateName,
            ]);

            // Create temp directory
            $tmpDir = WRITEPATH . 'uploads/tmp/';
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0755, true);
            }

            $icsPath = $tmpDir . 'interview_' . time() . '_' . mt_rand(1000, 9999) . '.ics';

            // Write ICS file
            $bytesWritten = file_put_contents($icsPath, $icsContent);

            if ($bytesWritten === false) {
                log_message('error', 'Failed to write ICS file to: ' . $icsPath);
                // Send email WITHOUT attachment if ICS creation fails
                return $this->sendRecruitmentEmail($to, $subject, $html, true);
            }

            log_message('info', 'ICS file created at: ' . $icsPath . ' (' . $bytesWritten . ' bytes)');

            // Verify file exists and is readable
            if (!file_exists($icsPath) || !is_readable($icsPath)) {
                log_message('error', 'ICS file not readable: ' . $icsPath);
                // Send email WITHOUT attachment
                @unlink($icsPath);
                return $this->sendRecruitmentEmail($to, $subject, $html, true);
            }

            // Build attachments array
            $attachments = [
                [
                    'path' => $icsPath,
                    'name' => 'interview-invite.ics',
                    'mime' => 'text/calendar; charset=UTF-8; method=REQUEST'
                ]
            ];

            log_message('info', 'Sending interview email with ICS attachment');

            // Send with attachment
            $result = $this->sendRecruitmentEmail($to, $subject, $html, true, $attachments);

            // Clean up temp file
            if (file_exists($icsPath)) {
                $deleted = @unlink($icsPath);
                log_message('info', 'ICS file cleanup: ' . ($deleted ? 'SUCCESS' : 'FAILED'));
            }

            return $result;
        } catch (\Exception $e) {
            log_message('error', 'Interview invitation failed: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());

            // Fallback: send without attachment
            try {
                return $this->sendRecruitmentEmail($to, $subject, $html, true);
            } catch (\Exception $fallbackEx) {
                log_message('error', 'Fallback email also failed: ' . $fallbackEx->getMessage());
                return ['success' => false, 'error' => $fallbackEx->getMessage()];
            }
        }
    }

    protected function generateInterviewInvitationEmailHtml(array $data)
    {
        $candidateName = htmlspecialchars($data['candidateName'] ?? 'Candidate');
        $jobTitle = htmlspecialchars($data['jobTitle'] ?? 'Role');
        $scheduledHuman = htmlspecialchars($data['scheduledHuman'] ?? '');
        $type = htmlspecialchars(str_replace('_', ' ', (string)($data['type'] ?? 'INTERVIEW')));
        $category = htmlspecialchars($data['category'] ?? 'Interview');
        $isOnline = $data['isOnline'] ?? false;
        $duration = (int)($data['duration'] ?? 60);
        $meetingLink = $data['meetingLink'] ?? null;
        $location = $data['location'] ?? null;
        $notes = $data['notes'] ?? null;
        $timezone = htmlspecialchars($data['timezone'] ?? 'Africa/Nairobi');

        $linkHtml = $meetingLink ? "<p><strong>Meeting Link:</strong> <a href='{$meetingLink}' style='color: #0b5ed7;'>{$meetingLink}</a></p>" : "";
        $locationHtml = $location ? "<p><strong>Location:</strong> " . htmlspecialchars($location) . "</p>" : "";
        $notesHtml = $notes ? "<p><strong>Notes:</strong> " . nl2br(htmlspecialchars($notes)) . "</p>" : "";


        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #222; }
                .container { max-width: 700px; margin: 0 auto; padding: 22px; }
                .header { background: #198754; color: #fff; padding: 18px 20px; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; }
                .card { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; margin: 14px 0; }
                .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; margin-bottom: 10px; }
                .badge-online { background: #d1ecf1; color: #0c5460; }
                .badge-physical { background: #fff3cd; color: #856404; }
                .footer { margin-top: 18px; font-size: 12px; color: #6c757d; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin:0;'>Interview Invitation</h2>
                    <p style='margin:6px 0 0 0;'>Fortune Kenya Recruitment</p>
                </div>

                <div class='content'>

                    <p>Dear {$candidateName},</p>

                    <p>Congratulations🎉, we are pleased to invite you for an interview for the position of <strong>{$jobTitle}</strong>.</p>

                    <div class='card'>
                        <span class='badge " . ($isOnline ? "badge-online'> {$category}" : "badge-physical'>{$category}") . "</span>
                        <h3 style='margin-top:10px;'>Interview Details</h3>
                        <p><strong>Date & Time:</strong> {$scheduledHuman} ({$timezone})</p>
                        <p><strong>Type:</strong> {$type}</p>
                        <p><strong>Duration:</strong> {$duration} minutes</p>
                        {$linkHtml}
                        {$locationHtml}
                        {$notesHtml}
                        <p style='margin-bottom:0;'><strong>📅 Calendar Invite:</strong> Attached (.ics) — Click to add it to Google/Outlook/Apple Calendar.</p>
                    </div>

                    <div class='card'>
                        <h3 style='margin-top:0;'>Preparation Tips</h3>
                        <ul>
                            " . ($isOnline ? "<li>For online interviews, ensure <strong>stable internet</strong> and working <strong>microphone/camera</strong>.</li>" : "<li>For physical interviews, plan your route and arrive on time.</li>") . "
                            <li>Prepare in advance.</li>
                        </ul>
                    </div>

                    <p>If you have a question, please contact us at <strong>headhunting@fortunekenya.com</strong>.</p>

                    <p>We look forward to meeting you and learning more about your qualifications!</p>

                    <p>Kind regards,<br>
                    <strong>Fortune Kenya Recruitment Team</strong><br>
                    headhunting@fortunekenya.com</p>

                    <div class='footer'>
                        <p>This is an automated email. Please do not reply directly to this message.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    protected function buildIcsDescription(string $jobTitle, array $details): string
    {
        $parts = [];
        $parts[] = "Interview for: {$jobTitle}";
        if (!empty($details['type'])) {
            $type = str_replace('_', ' ', $details['type']);
            $parts[] = "Type: {$type}";
        }
        if (!empty($details['meetingLink'])) $parts[] = "Meeting Link: " . $details['meetingLink'];
        if (!empty($details['location'])) $parts[] = "Location: " . $details['location'];
        if (!empty($details['notes'])) $parts[] = "Notes: " . preg_replace("/\r\n|\r|\n/", " ", (string)$details['notes']);
        $parts[] = "Contact: headhunting@fortunekenya.com";
        return implode("\\n", $parts);
    }

    protected function generateInterviewIcs(array $data): string
    {
        $tz = $data['timezone'] ?? 'Africa/Nairobi';

        // Convert to UTC for maximum compatibility
        $start = new \DateTime($data['start'] ?? 'now', new \DateTimeZone($tz));
        $end = clone $start;
        $end->modify('+' . (int)($data['durationMinutes'] ?? 60) . ' minutes');

        // Convert to UTC
        $start->setTimezone(new \DateTimeZone('UTC'));
        $end->setTimezone(new \DateTimeZone('UTC'));

        $dtStart = $start->format('Ymd\THis\Z');  // Z suffix for UTC
        $dtEnd   = $end->format('Ymd\THis\Z');
        $dtStamp = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        $uid = $this->escapeIcsText($data['uid'] ?? uniqid('interview_', true) . '@fortunekenya.com');
        $summary = $this->escapeIcsText($data['summary'] ?? 'Interview');
        $description = $this->escapeIcsText($data['description'] ?? '');
        $location = $this->escapeIcsText($data['location'] ?? '');

        $orgEmail = $data['organizerEmail'] ?? 'headhunting@fortunekenya.com';
        $orgName  = $this->escapeIcsText($data['organizerName'] ?? 'Fortune Kenya Recruitment');
        $attEmail = $data['attendeeEmail'] ?? '';
        $attName  = $this->escapeIcsText($data['attendeeName'] ?? 'Candidate');

        $lines = [];
        $lines[] = "BEGIN:VCALENDAR";
        $lines[] = "VERSION:2.0";
        $lines[] = "PRODID:-//Fortune Kenya//Recruitment System//EN";
        $lines[] = "CALSCALE:GREGORIAN";
        $lines[] = "METHOD:REQUEST";
        $lines[] = "X-WR-TIMEZONE:{$tz}";

        // Add VTIMEZONE block for Africa/Nairobi
        $lines[] = "BEGIN:VTIMEZONE";
        $lines[] = "TZID:{$tz}";
        $lines[] = "BEGIN:STANDARD";
        $lines[] = "DTSTART:19700101T000000";
        $lines[] = "TZOFFSETFROM:+0300";
        $lines[] = "TZOFFSETTO:+0300";
        $lines[] = "TZNAME:EAT";
        $lines[] = "END:STANDARD";
        $lines[] = "END:VTIMEZONE";

        $lines[] = "BEGIN:VEVENT";
        $lines[] = "UID:{$uid}";
        $lines[] = "DTSTAMP:{$dtStamp}";
        $lines[] = "DTSTART:{$dtStart}";  // Clean UTC format
        $lines[] = "DTEND:{$dtEnd}";
        $lines[] = "SUMMARY:{$summary}";
        if ($description !== '') $lines[] = "DESCRIPTION:{$description}";
        if ($location !== '') $lines[] = "LOCATION:{$location}";
        $lines[] = "STATUS:CONFIRMED";
        $lines[] = "SEQUENCE:0";
        $lines[] = "PRIORITY:5";
        $lines[] = "CLASS:PUBLIC";
        $lines[] = "TRANSP:OPAQUE";
        $lines[] = "ORGANIZER;CN={$orgName}:mailto:{$orgEmail}";
        if ($attEmail !== '') {
            $lines[] = "ATTENDEE;CN={$attName};ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:{$attEmail}";
        }

        // Add reminders (24 hours and 1 hour before)
        $lines[] = "BEGIN:VALARM";
        $lines[] = "TRIGGER:-PT24H";
        $lines[] = "ACTION:DISPLAY";
        $lines[] = "DESCRIPTION:Interview Reminder - Tomorrow";
        $lines[] = "END:VALARM";

        $lines[] = "BEGIN:VALARM";
        $lines[] = "TRIGGER:-PT1H";
        $lines[] = "ACTION:DISPLAY";
        $lines[] = "DESCRIPTION:Interview Starting Soon - 1 Hour";
        $lines[] = "END:VALARM";

        $lines[] = "END:VEVENT";
        $lines[] = "END:VCALENDAR";

        return implode("\r\n", $lines) . "\r\n";
    }

    protected function escapeIcsText(string $text): string
    {
        $text = str_replace("\\", "\\\\", $text);
        $text = str_replace(";", "\;", $text);
        $text = str_replace(",", "\,", $text);
        $text = preg_replace("/\r\n|\r|\n/", "\\n", $text);
        return $text;
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
