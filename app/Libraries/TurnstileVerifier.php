<?php

/**
 * app/Libraries/TurnstileVerifier.php
 *
 * Cloudflare Turnstile server-side token verification library.
 * Sends the frontend token to Cloudflare's siteverify endpoint
 * and returns whether the challenge was passed.
 *
 * Usage:
 *   $verifier = new TurnstileVerifier();
 *   $result = $verifier->verify($token, $remoteIp);
 *   if (!$result['success']) { return error; }
 *
 * Configuration:
 *   Reads turnstile_secret_key and turnstile_enabled from the
 *   `settings` database table. Falls back to .env if DB unavailable.
 * Path: app/Libraries/TurnstileVerifier.php
 */

namespace App\Libraries;

use App\Models\Setting;

class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var string Cloudflare Turnstile secret key */
    private string $secretKey;

    /** @var bool Whether Turnstile verification is enabled */
    private bool $enabled;

    public function __construct()
    {
        $this->loadSettings();
    }

    /**
     * Load Turnstile settings from database, fallback to .env
     */
    private function loadSettings(): void
    {
        try {
            $settingModel = new Setting();

            // Get enabled flag
            $enabledRow = $settingModel
                ->where('settingKey', 'turnstile_enabled')
                ->first();
            $this->enabled = $enabledRow
                ? ($enabledRow['settingValue'] === '1' || $enabledRow['settingValue'] === 'true')
                : (bool)(getenv('TURNSTILE_ENABLED') ?: false);

            // Get secret key
            $secretRow = $settingModel
                ->where('settingKey', 'turnstile_secret_key')
                ->first();
            $this->secretKey = $secretRow
                ? ($secretRow['settingValue'] ?? '')
                : (getenv('TURNSTILE_SECRET_KEY') ?: '');

        } catch (\Exception $e) {
            // If DB is unavailable, fallback to .env
            log_message('warning', '[TurnstileVerifier] DB read failed, using .env: ' . $e->getMessage());
            $this->enabled = (bool)(getenv('TURNSTILE_ENABLED') ?: false);
            $this->secretKey = getenv('TURNSTILE_SECRET_KEY') ?: '';
        }
    }

    /**
     * Check if Turnstile verification is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->secretKey);
    }

    /**
     * Verify a Turnstile token with Cloudflare
     *
     * @param string|null $token  The token from the frontend widget
     * @param string|null $remoteIp  The client's IP address (optional but recommended)
     * @return array{success: bool, message: string, errorCodes?: array}
     */
    public function verify(?string $token, ?string $remoteIp = null): array
    {
        // If Turnstile is disabled, always pass
        if (!$this->isEnabled()) {
            return [
                'success' => true,
                'message' => 'Turnstile verification skipped (disabled)',
            ];
        }

        // If no token provided, fail
        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'Security verification is required. Please complete the challenge.',
            ];
        }

        // Build request payload
        $postData = [
            'secret'   => $this->secretKey,
            'response' => $token,
        ];

        if ($remoteIp) {
            $postData['remoteip'] = $remoteIp;
        }

        // Call Cloudflare siteverify
        try {
            $ch = curl_init(self::VERIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($postData),
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // cURL error
            if ($response === false || !empty($curlError)) {
                log_message('error', '[TurnstileVerifier] cURL error: ' . $curlError);
                // Fail open or fail closed? For security, we fail closed.
                return [
                    'success' => false,
                    'message' => 'Security verification service unavailable. Please try again.',
                ];
            }

            // Parse response
            $result = json_decode($response, true);

            if (!is_array($result)) {
                log_message('error', '[TurnstileVerifier] Invalid JSON response: ' . $response);
                return [
                    'success' => false,
                    'message' => 'Security verification failed. Please try again.',
                ];
            }

            // Log for debugging
            log_message('debug', '[TurnstileVerifier] Response: ' . json_encode($result));

            if (!empty($result['success'])) {
                return [
                    'success' => true,
                    'message' => 'Turnstile verification passed',
                ];
            }

            // Verification failed
            $errorCodes = $result['error-codes'] ?? [];
            log_message('warning', '[TurnstileVerifier] Verification failed. Errors: ' . implode(', ', $errorCodes));

            return [
                'success'    => false,
                'message'    => 'Security verification failed. Please try again.',
                'errorCodes' => $errorCodes,
            ];

        } catch (\Exception $e) {
            log_message('error', '[TurnstileVerifier] Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Security verification error. Please try again.',
            ];
        }
    }
}
