<?php

namespace App\Libraries;

use CodeIgniter\Email\Email;
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

    /**
     * Send a basic email
     */
    public function sendEmail($to, $subject, $message, $isHtml = true, $attachments = [])
    {
        return $this->sendEmailWithFrom($to, $subject, $message, $isHtml, $attachments, $this->config->fromEmail, $this->config->fromName);
    }

    /**
     * Send email with custom from address
     */
    protected function sendEmailWithFrom($to, $subject, $message, $isHtml = true, $attachments = [], $fromEmail = null, $fromName = null)
    {
        try {
            $this->email->clear();
            $this->email->setTo($to);
            
            $fromEmail = $fromEmail ?? $this->config->fromEmail;
            $fromName = $fromName ?? $this->config->fromName;
            $this->email->setFrom($fromEmail, $fromName);
            
            $this->email->setSubject($subject);
            
            if ($isHtml) {
                $this->email->setMailType('html');
            }
            
            $this->email->setMessage($message);
            
            // Add attachments if provided
            foreach ($attachments as $attachment) {
                if (isset($attachment['path']) && file_exists($attachment['path'])) {
                    $this->email->attach($attachment['path']);
                }
            }
            
            if ($this->email->send()) {
                return ['success' => true, 'message' => 'Email sent successfully'];
            } else {
                return ['success' => false, 'error' => 'Failed to send email'];
            }
        } catch (\Exception $e) {
            log_message('error', 'Email sending failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send quote email
     */
    public function sendQuote($to, $subject, $message, $recipientName, $company = null, $quoteAmount = null, $currency = 'KES', $attachments = [])
    {
        $html = $this->generateQuoteEmailHtml($subject, $message, $recipientName, $company, $quoteAmount, $currency);
        return $this->sendEmail($to, $subject, $html, true, $attachments);
    }

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

    /**
     * Notify admin about new job application
     */
    public function notifyAdminNewApplication($applicationData)
    {
        $adminEmail = 'headhunting@fortunekenya.com';
        $subject = 'New Job Application - ' . ($applicationData['job']['title'] ?? 'Unknown Position');
        $html = $this->generateAdminApplicationNotificationHtml($applicationData);
        $attachments = [];
        
        // Add resume attachment if available
        if (isset($applicationData['profile']['resumeUrl']) && !empty($applicationData['profile']['resumeUrl'])) {
            $resumePath = WRITEPATH . '../public' . $applicationData['profile']['resumeUrl'];
            if (file_exists($resumePath)) {
                $attachments[] = ['path' => $resumePath];
            }
        }
        
        return $this->sendEmail($adminEmail, $subject, $html, true, $attachments);
    }

    /**
     * Generate quote email HTML
     */
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

    /**
     * Generate verification email HTML
     */
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

    /**
     * Generate password reset email HTML
     */
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

    /**
     * Generate admin quote notification HTML
     */
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

    /**
     * Generate admin contact notification HTML
     */
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

    /**
     * Generate admin application notification HTML
     */
    protected function generateAdminApplicationNotificationHtml($applicationData)
    {
        $candidate = $applicationData['candidate'] ?? [];
        $profile = $applicationData['profile'] ?? [];
        $job = $applicationData['job'] ?? [];
        
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
                    <h1>New Job Application</h1>
                </div>
                <div class='content'>
                    <div class='info'>
                        <h3>Candidate Information</h3>
                        <p><strong>Name:</strong> " . ($candidate['firstName'] ?? '') . " " . ($candidate['lastName'] ?? '') . "</p>
                        <p><strong>Email:</strong> " . ($candidate['email'] ?? 'N/A') . "</p>
                        <p><strong>Phone:</strong> " . ($candidate['phone'] ?? 'N/A') . "</p>
                        <p><strong>Title:</strong> " . ($profile['title'] ?? 'N/A') . "</p>
                        <p><strong>Location:</strong> " . ($profile['location'] ?? 'N/A') . "</p>
                        <p><strong>Experience:</strong> " . ($profile['experienceYears'] ?? 'N/A') . " years</p>
                    </div>
                    <div class='info'>
                        <h3>Job Information</h3>
                        <p><strong>Position:</strong> " . ($job['title'] ?? 'N/A') . "</p>
                        <p><strong>Company:</strong> " . ($job['company']['name'] ?? 'N/A') . "</p>
                    </div>
                    " . (isset($applicationData['coverLetter']) ? "<div class='info'><h3>Cover Letter</h3><p>" . nl2br(htmlspecialchars($applicationData['coverLetter'])) . "</p></div>" : "") . "
                </div>
                <div class='footer'>
                    <p>This is an automated notification email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Get frontend URL from environment variable
     * Ensures proper protocol (https://) is included
     */
    protected function getFrontendUrl()
    {
        $frontendUrl = env('FRONTEND_URL', '');
        
        if (empty($frontendUrl)) {
            // Fallback to base_url if FRONTEND_URL is not set
            return rtrim(base_url(), '/');
        }
        
        // Ensure protocol is included
        if (!preg_match('/^https?:\/\//', $frontendUrl)) {
            $frontendUrl = 'https://' . $frontendUrl;
        }
        
        // Remove trailing slash
        return rtrim($frontendUrl, '/');
    }
}
