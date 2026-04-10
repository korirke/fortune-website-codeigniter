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

    /** @var string Current environment (development/production) */
    private string $environment;

    private const ERROR_MESSAGES = [
        'missing-input-secret' => 'The secret key was not provided.',
        'invalid-input-secret' => 'The secret key is invalid or malformed.',
        'missing-input-response' => 'The token was not provided.',
        'invalid-input-response' => 'The token is invalid or has already been used.',
        'bad-request' => 'The request was rejected as malformed.',
        'timeout-or-duplicate' => 'The token has expired or was already verified. Please try again.',
        'internal-error' => 'Cloudflare internal error. Please try again later.',
    ];

    public function __construct()
    {
        $this->environment = getenv('CI_ENVIRONMENT') ?: (getenv('APP_ENV') ?: 'production');
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
                : (bool) (getenv('TURNSTILE_ENABLED') ?: false);

            // Get secret key
            $secretRow = $settingModel
                ->where('settingKey', 'turnstile_secret_key')
                ->first();
            $this->secretKey = $secretRow
                ? ($secretRow['settingValue'] ?? '')
                : (getenv('TURNSTILE_SECRET_KEY') ?: '');

            // Validate secret key format (start with 0x for real keys)
            if ($this->enabled && !empty($this->secretKey)) {
                if (strlen($this->secretKey) < 10) {
                    log_message('warning', '[TurnstileVerifier] Secret key looks too short. Check your settings.');
                }
            }

        } catch (\Exception $e) {
            // If DB is unavailable, fallback to .env
            log_message('warning', '[TurnstileVerifier] DB read failed, using .env: ' . $e->getMessage());
            $this->enabled = (bool) (getenv('TURNSTILE_ENABLED') ?: false);
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
     * Find the CA certificate bundle path for cURL SSL verification
     */
    private function findCaBundlePath(): ?string
    {
        // Common CA bundle locations
        $possiblePaths = [
            // Linux
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/ca-bundle.pem',
            '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
            // macOS
            '/usr/local/etc/openssl/cert.pem',
            '/usr/local/etc/openssl@1.1/cert.pem',
            '/usr/local/etc/openssl@3/cert.pem',
            // Windows (XAMPP, WAMP, Laragon)
            'C:/xampp/apache/bin/curl-ca-bundle.crt',
            'C:/xampp/php/extras/ssl/cacert.pem',
            'C:/laragon/etc/ssl/cacert.pem',
            'C:/wamp64/bin/php/php8.2.0/extras/ssl/cacert.pem',
            // PHP ini configured path
            ini_get('curl.cainfo') ?: '',
            ini_get('openssl.cafile') ?: '',
        ];

        foreach ($possiblePaths as $path) {
            if (!empty($path) && file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Verify a Turnstile token with Cloudflare
     *
     * @param string|null $token    The token from the frontend widget
     * @param string|null $remoteIp The client's IP address (optional but recommended)
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
            'secret' => $this->secretKey,
            'response' => $token,
        ];

        if ($remoteIp) {
            $postData['remoteip'] = $remoteIp;
        }

        log_message('debug', '[TurnstileVerifier] Verifying token (length: ' . strlen($token) . ') for IP: ' . ($remoteIp ?? 'unknown'));

        // Call Cloudflare siteverify
        try {
            $ch = curl_init(self::VERIFY_URL);

            // ── SSL Configuration ──────────────────────────────────────
            $curlOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ];

            // Attempt to find CA bundle for proper SSL verification
            $caBundlePath = $this->findCaBundlePath();

            if ($caBundlePath) {
                $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
                $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
                log_message('debug', '[TurnstileVerifier] Using CA bundle: ' . $caBundlePath);
            } elseif ($this->environment === 'development' || $this->environment === 'testing') {
                // In development ONLY: disable SSL verification if no CA bundle found
                $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
                $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
                log_message('warning', '[TurnstileVerifier] ⚠️  SSL verification DISABLED (dev mode, no CA bundle found). '
                    . 'Download cacert.pem from https://curl.se/docs/caextract.html and configure curl.cainfo in php.ini');
            } else {
                // Production: keep SSL verification enabled (use system defaults)
                $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
            }

            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            // ── cURL error handling ────────────────────────────────────
            if ($response === false || $curlErrno !== 0) {
                log_message('error', sprintf(
                    '[TurnstileVerifier] cURL FAILED — errno: %d, error: "%s", url: %s, httpCode: %d',
                    $curlErrno,
                    $curlError,
                    $effectiveUrl,
                    $httpCode
                ));

                // Provide specific guidance for common cURL errors
                $guidance = match ($curlErrno) {
                    60, 77 => ' (SSL certificate problem — download cacert.pem from https://curl.se/docs/caextract.html)',
                    6 => ' (DNS resolution failed — check server DNS config)',
                    7 => ' (Connection refused — check firewall/proxy)',
                    28 => ' (Connection timed out — check network)',
                    default => '',
                };

                log_message('error', '[TurnstileVerifier] Guidance: ' . ($guidance ?: 'Check server network configuration'));

                return [
                    'success' => false,
                    'message' => 'Security verification service unavailable. Please try again.' .
                        ($this->environment === 'development' ? ' [cURL error ' . $curlErrno . ': ' . $curlError . ']' : ''),
                ];
            }

            // ── Parse response ─────────────────────────────────────────
            $result = json_decode($response, true);

            if (!is_array($result)) {
                log_message('error', sprintf(
                    '[TurnstileVerifier] Invalid JSON from Cloudflare — httpCode: %d, body: %s',
                    $httpCode,
                    substr($response, 0, 500)
                ));
                return [
                    'success' => false,
                    'message' => 'Security verification failed. Please try again.',
                ];
            }

            // Log full response for debugging
            log_message('debug', '[TurnstileVerifier] Cloudflare response: ' . json_encode($result));

            // ── Success ────────────────────────────────────────────────
            if (!empty($result['success'])) {
                log_message('info', '[TurnstileVerifier] ✅ Verification passed for IP: ' . ($remoteIp ?? 'unknown'));
                return [
                    'success' => true,
                    'message' => 'Turnstile verification passed',
                ];
            }

            // ── Verification failed ────────────────────────────────────
            $errorCodes = $result['error-codes'] ?? [];

            // Build human-readable error message
            $readableErrors = array_map(function ($code) {
                return self::ERROR_MESSAGES[$code] ?? $code;
            }, $errorCodes);

            log_message('warning', sprintf(
                '[TurnstileVerifier] ❌ Verification FAILED — errors: [%s], readable: [%s]',
                implode(', ', $errorCodes),
                implode('; ', $readableErrors)
            ));

            // Check for token-already-used error (common when widget re-renders)
            $isTokenExpiredOrDuplicate = in_array('timeout-or-duplicate', $errorCodes);
            $isInvalidToken = in_array('invalid-input-response', $errorCodes);

            $userMessage = 'Security verification failed. Please try again.';
            if ($isTokenExpiredOrDuplicate) {
                $userMessage = 'Security token expired. Please complete the verification again.';
            } elseif ($isInvalidToken) {
                $userMessage = 'Security token is invalid. Please refresh and try again.';
            }

            return [
                'success' => false,
                'message' => $userMessage,
                'errorCodes' => $errorCodes,
            ];

        } catch (\Exception $e) {
            log_message('error', '[TurnstileVerifier] Exception: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return [
                'success' => false,
                'message' => 'Security verification error. Please try again.',
            ];
        }
    }
}