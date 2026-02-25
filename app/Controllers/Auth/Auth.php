<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Models\User;
use App\Libraries\EmailHelper;
use App\Models\CandidateProfile;
use App\Traits\NormalizedResponseTrait;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * @OA\Tag(
 *     name="Authentication",
 *     description="User authentication endpoints"
 * )
 */
class Auth extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/auth/register",
     *     tags={"Authentication"},
     *     summary="User registration (Candidate/Employer only)",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "password", "firstName", "lastName"},
     *             @OA\Property(property="email", type="string", format="email", description="User email address", example="user@example.com"),
     *             @OA\Property(property="password", type="string", description="User password (min 6 characters)", example="securepass123", minLength=6),
     *             @OA\Property(property="firstName", type="string", description="User first name", example="John", minLength=2),
     *             @OA\Property(property="lastName", type="string", description="User last name", example="Doe", minLength=2),
     *             @OA\Property(property="role", type="string", enum={"CANDIDATE", "EMPLOYER"}, description="User role (optional, defaults to CANDIDATE)", example="CANDIDATE")
     *         )
     *     ),
     *     @OA\Response(response="201", description="Registration successful"),
     *     @OA\Response(response="409", description="Email already exists"),
     *     @OA\Response(response="403", description="Cannot register with admin role")
     * )
     */
    public function register()
    {
        $userModel = new User();
        $data = $this->request->getJSON(true);

        // Validation
        if (!isset($data['email']) || !isset($data['password']) || !isset($data['firstName']) || !isset($data['lastName'])) {
            return $this->fail('Missing required fields', 400);
        }

        // Check if email exists
        $existingUser = $userModel->where('email', $data['email'])->first();
        if ($existingUser) {
            return $this->respond([
                'success' => false,
                'message' => 'Email already exists'
            ], 409);
        }

        // Prevent admin role registration
        if (isset($data['role']) && in_array($data['role'], ['SUPER_ADMIN', 'MODERATOR', 'HR_MANAGER', 'WEBSITE_ADMIN'])) {
            return $this->respond([
                'success' => false,
                'message' => 'Cannot register with admin role'
            ], 403);
        }

        // Hash password
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        $data['id'] = uniqid('user_');
        $data['role'] = $data['role'] ?? 'CANDIDATE';
        $data['status'] = $data['status'] ?? 'PENDING_VERIFICATION';
        $data['emailVerified'] = false;

        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));
        $data['resetPasswordToken'] = $verificationToken;
        $data['resetPasswordExpires'] = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $userModel->insert($data);

        if ($data['role'] === 'CANDIDATE') {
            $candidateProfileModel = new CandidateProfile();
            $candidateProfileModel->insert([
                'id' => uniqid('cprofile_'),
                'userId' => $data['id'],
                'openToWork' => true
            ]);
        }

        // Send verification email (non-blocking)
        try {
            $emailHelper = new EmailHelper();
            $emailHelper->sendVerificationEmail(
                $data['email'],
                $data['firstName'] . ' ' . $data['lastName'],
                $verificationToken
            );
        } catch (\Exception $e) {
            log_message('error', 'Failed to send verification email: ' . $e->getMessage());
        }

        // Get created user (without password)
        $createdUser = $userModel->select('id, email, firstName, lastName, role, status, createdAt')
            ->find($data['id']);

        return $this->respondCreated([
            'success' => true,
            'message' => 'Registration successful! Please check your email to verify your account.',
            'data' => [
                'user' => $createdUser,
                'verificationRequired' => true
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/login",
     *     tags={"Authentication"},
     *     summary="User login (all roles)",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", description="User email address", example="user@example.com"),
     *             @OA\Property(property="password", type="string", description="User password", example="password123", minLength=6)
     *         )
     *     ),
     *     @OA\Response(response="200", description="Login successful"),
     *     @OA\Response(response="401", description="Invalid credentials")
     * )
     */
    public function login()
    {
        $userModel = new User();
        $data = $this->request->getJSON(true);

        if (!isset($data['email']) || !isset($data['password'])) {
            return $this->fail('Email and password required', 400);
        }

        $user = $userModel->where('email', $data['email'])->first();
        if (!$user || !password_verify($data['password'], $user['password'])) {
            return $this->fail('Invalid credentials', 401);
        }

        // Generate JWT
        $secretKey = getenv('jwt.secret') ?: 'your-secret-key-change-this-in-production';
        $payload = [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['firstName'] . ' ' . $user['lastName'],
            'role' => $user['role'],
            'iat' => time(),
            'exp' => time() + (getenv('jwt.expiration') ?: 86400)
        ];

        $token = JWT::encode($payload, $secretKey, 'HS256');

        // Update last login
        $userModel->update($user['id'], [
            'lastLoginAt' => date('Y-m-d H:i:s'),
            'lastLoginIp' => $this->request->getIPAddress()
        ]);

        // Check if email verification is required
        if (in_array($user['role'], ['CANDIDATE', 'EMPLOYER']) && !$user['emailVerified']) {
            return $this->respond([
                'success' => false,
                'message' => 'Please verify your email before logging in. Check your inbox for the verification link.',
                'data' => [
                    'emailVerificationRequired' => true,
                    'email' => $user['email']
                ]
            ], 400);
        }

        // Determine redirect URL based on role
        $redirectUrl = '/careers-portal';
        if ($user['role'] === 'EMPLOYER') {
            $employerProfileModel = new \App\Models\EmployerProfile();
            $employerProfile = $employerProfileModel->where('userId', $user['id'])->first();
            if (!$employerProfile) {
                $redirectUrl = '/recruitment-portal/setup/company';
            } else {
                $redirectUrl = '/recruitment-portal/dashboard';
            }
        } elseif ($user['role'] === 'CANDIDATE') {
            $redirectUrl = '/careers-portal/jobs';
        } elseif (in_array($user['role'], ['HR_MANAGER', 'MODERATOR'])) {
            $redirectUrl = '/recruitment-portal/dashboard';
        } elseif ($user['role'] === 'SUPER_ADMIN') {
            $redirectUrl = '/select-portal';
        } elseif ($user['role'] === 'WEBSITE_ADMIN') {
            $redirectUrl = '/dashboard';
        }

        // Check if company setup is required
        $requiresCompanySetup = false;
        if ($user['role'] === 'EMPLOYER') {
            $employerProfileModel = new \App\Models\EmployerProfile();
            $employerProfile = $employerProfileModel->where('userId', $user['id'])->first();
            $requiresCompanySetup = !$employerProfile;
        }

        return $this->respond([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'firstName' => $user['firstName'],
                    'lastName' => $user['lastName'],
                    'name' => $user['firstName'] . ' ' . $user['lastName'],
                    'role' => $user['role'],
                    'status' => $user['status'],
                    'emailVerified' => $user['emailVerified'] ?? false
                ],
                'redirectUrl' => $redirectUrl,
                'requiresCompanySetup' => $requiresCompanySetup
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/verify-email",
     *     tags={"Authentication"},
     *     summary="Verify email address",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token"},
     *             @OA\Property(property="token", type="string", description="Email verification token", example="verification_token_123")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Email verified"),
     *     @OA\Response(response="400", description="Invalid token")
     * )
     */
    public function verifyEmail()
    {
        $data = $this->request->getJSON(true);
        $token = $data['token'] ?? $this->request->getGet('token');

        if (!$token) {
            return $this->fail('Verification token is required', 400);
        }

        $userModel = new User();
        $user = $userModel->where('resetPasswordToken', $token)
            ->where('resetPasswordExpires >', date('Y-m-d H:i:s'))
            ->first();

        if (!$user) {
            return $this->fail('Invalid or expired verification token', 400);
        }

        // Verify email
        $userModel->update($user['id'], [
            'emailVerified' => true,
            'status' => 'ACTIVE',
            'resetPasswordToken' => null,
            'resetPasswordExpires' => null
        ]);

        return $this->respond([
            'success' => true,
            'message' => 'Email verified successfully! You can now log in to your account.'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/forgot-password",
     *     tags={"Authentication"},
     *     summary="Request password reset",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", description="User email address", example="user@example.com")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Reset email sent")
     * )
     */
    public function forgotPassword()
    {
        $data = $this->request->getJSON(true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->fail('Email is required', 400);
        }

        $userModel = new User();
        $user = $userModel->where('email', $email)->first();

        $message = 'If an account exists with that email, password reset instructions have been sent.';

        if ($user) {
            // Generate reset token
            $resetToken = bin2hex(random_bytes(32));
            $resetExpires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $userModel->update($user['id'], [
                'resetPasswordToken' => $resetToken,
                'resetPasswordExpires' => $resetExpires
            ]);

            // Send password reset email (non-blocking)
            try {
                $emailHelper = new EmailHelper();
                $emailHelper->sendPasswordResetEmail(
                    $user['email'],
                    $user['firstName'] . ' ' . $user['lastName'],
                    $resetToken
                );
            } catch (\Exception $e) {
                log_message('error', 'Failed to send password reset email: ' . $e->getMessage());
            }
        }

        // Always return same message for security (don't reveal if email exists)
        return $this->respond([
            'success' => true,
            'message' => $message
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/reset-password",
     *     tags={"Authentication"},
     *     summary="Reset password with token",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token", "newPassword"},
     *             @OA\Property(property="token", type="string", description="Password reset token", example="reset_token_123"),
     *             @OA\Property(property="newPassword", type="string", description="New password (min 6 characters)", example="newpassword123", minLength=6)
     *         )
     *     ),
     *     @OA\Response(response="200", description="Password reset successfully"),
     *     @OA\Response(response="400", description="Invalid or expired token")
     * )
     */
    public function resetPassword()
    {
        $data = $this->request->getJSON(true);
        $token = $data['token'] ?? null;
        $newPassword = $data['newPassword'] ?? $data['password'] ?? null;

        if (!$token || !$newPassword) {
            return $this->fail('Token and new password are required', 400);
        }

        $userModel = new User();
        $user = $userModel->where('resetPasswordToken', $token)
            ->where('resetPasswordExpires >', date('Y-m-d H:i:s'))
            ->first();

        if (!$user) {
            return $this->fail('Invalid or expired reset token', 400);
        }

        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update password and clear reset token
        $userModel->update($user['id'], [
            'password' => $hashedPassword,
            'resetPasswordToken' => null,
            'resetPasswordExpires' => null
        ]);

        return $this->respond([
            'success' => true,
            'message' => 'Password reset successfully! You can now log in with your new password.'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/auth/me",
     *     tags={"Authentication"},
     *     summary="Get logged-in user info",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Authenticated user data"),
     *     @OA\Response(response="401", description="Unauthorized")
     * )
     */
    public function getMe()
    {
        $user = $this->request->user ?? null;
        if (!$user) {
            return $this->fail('Unauthorized', 401);
        }

        $userModel = new User();
        $userData = $userModel->select('id, email, firstName, lastName, role, status, phone, avatar, emailVerified, createdAt, updatedAt')
            ->find($user->id);

        if (!$userData) {
            return $this->fail('User not found', 404);
        }

        return $this->respond([
            'success' => true,
            'data' => $userData
        ]);
    }
}
