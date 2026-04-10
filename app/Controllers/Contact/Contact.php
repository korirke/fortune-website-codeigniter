<?php

namespace App\Controllers\Contact;

use App\Controllers\BaseController;
use App\Models\ContactInquiry;
use App\Libraries\EmailHelper;
use App\Libraries\TurnstileVerifier;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Contact",
 *     description="Contact form endpoints"
 * )
 */
class Contact extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/contact/submit",
     *     tags={"Contact"},
     *     summary="Submit contact form (with Turnstile verification)",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="inquiry", type="string"),
     *             @OA\Property(property="firstName", type="string"),
     *             @OA\Property(property="lastName", type="string"),
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="source", type="string", enum={"website","careers_portal","recruitment_portal"}),
     *             @OA\Property(property="turnstileToken", type="string", description="Cloudflare Turnstile token")
     *         )
     *     ),
     *     @OA\Response(response="201", description="Contact submitted successfully"),
     *     @OA\Response(response="400", description="Turnstile verification failed")
     * )
     */
    public function submitContact()
    {
        $data = $this->request->getJSON(true);

        // ── Turnstile Verification
        $turnstile = new TurnstileVerifier();
        if ($turnstile->isEnabled()) {
            $turnstileToken = $data['turnstileToken'] ?? null;
            $clientIp = $this->request->getIPAddress();

            $verification = $turnstile->verify($turnstileToken, $clientIp);

            if (!$verification['success']) {
                log_message('warning', '[ContactSubmit] Turnstile failed for ' . ($data['email'] ?? 'unknown') . ': ' . $verification['message']);
                return $this->respond([
                    'success' => false,
                    'message' => $verification['message'],
                ], 400);
            }
        }

        $inquiryModel = new ContactInquiry();

        $data['id'] = uniqid('inquiry_');
        $data['status'] = 'pending';
        $data['source'] = $data['source'] ?? 'website';

        // Convert metadata to JSON if it's an array/object
        if (isset($data['metadata']) && (is_array($data['metadata']) || is_object($data['metadata']))) {
            $data['metadata'] = json_encode($data['metadata']);
        }

        // Remove turnstileToken before insert (not a DB column)
        unset($data['turnstileToken']);

        $inquiryModel->insert($data);

        // Notify admin about new contact submission (non-blocking)
        try {
            $emailHelper = new EmailHelper();
            $emailHelper->notifyAdminNewContact($data);
        } catch (\Exception $e) {
            log_message('error', 'Failed to send admin notification: ' . $e->getMessage());
        }

        return $this->respondCreated([
            'success' => true,
            'message' => 'Your message has been sent successfully',
            'data' => [
                'id' => $data['id'],
                'submittedAt' => date('Y-m-d\TH:i:s.000\Z'),
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/contact",
     *     tags={"Contact"},
     *     summary="Get all contact inquiries (supports source filtering)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="source", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(response="200", description="Inquiries retrieved successfully")
     * )
     */
    public function getAllInquiries()
    {
        try {
            $inquiryModel = new ContactInquiry();

            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;

            // Get filters
            $status = $this->request->getGet('status');
            $source = $this->request->getGet('source');
            $search = $this->request->getGet('search');

            if ($status) {
                $inquiryModel->where('status', $status);
            }

            // Source filter for distinguishing website vs careers portal inquiries
            if ($source) {
                $inquiryModel->where('source', $source);
            }

            if ($search) {
                $inquiryModel->groupStart()
                    ->like('firstName', $search)
                    ->orLike('lastName', $search)
                    ->orLike('email', $search)
                    ->orLike('inquiry', $search)
                    ->orLike('message', $search)
                    ->groupEnd();
            }

            // Get total count
            $total = $inquiryModel->countAllResults(false);

            // Get paginated results
            $inquiries = $inquiryModel
                ->orderBy('createdAt', 'DESC')
                ->findAll($limit, $skip);

            return $this->respond([
                'success' => true,
                'message' => 'Contact inquiries retrieved successfully',
                'data' => [
                    'items' => $inquiries ?: [],
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'totalPages' => (int) ceil($total / $limit),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve inquiries',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/contact/stats",
     *     tags={"Contact"},
     *     summary="Get contact statistics",
     *     security={{"bearerAuth":{}}}
     * )
     */
    public function getStats()
    {
        try {
            $inquiryModel = new ContactInquiry();

            $total = $inquiryModel->countAllResults(false);
            $pending = $inquiryModel->where('status', 'pending')->countAllResults(false);
            $inProgress = $inquiryModel->where('status', 'in_progress')->countAllResults(false);
            $resolved = $inquiryModel->where('status', 'resolved')->countAllResults(false);
            $closed = $inquiryModel->where('status', 'closed')->countAllResults(false);

            $recent = $inquiryModel
                ->orderBy('createdAt', 'DESC')
                ->limit(5)
                ->findAll();

            $recentFormatted = array_map(function ($inq) {
                return [
                    'id' => $inq['id'],
                    'firstName' => $inq['firstName'] ?? null,
                    'lastName' => $inq['lastName'] ?? null,
                    'email' => $inq['email'],
                    'inquiry' => $inq['inquiry'] ?? null,
                    'status' => $inq['status'],
                    'source' => $inq['source'] ?? 'website',
                    'createdAt' => $inq['createdAt'],
                ];
            }, $recent);

            return $this->respond([
                'success' => true,
                'message' => 'Contact stats retrieved successfully',
                'data' => [
                    'total' => $total,
                    'pending' => $pending,
                    'inProgress' => $inProgress,
                    'resolved' => $resolved,
                    'closed' => $closed,
                    'recent' => $recentFormatted
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve stats',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/contact/{id}",
     *     tags={"Contact"},
     *     summary="Get contact inquiry by ID",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Inquiry retrieved successfully")
     * )
     */
    public function getInquiryById($id)
    {
        $inquiryModel = new ContactInquiry();
        $inquiry = $inquiryModel->find($id);

        if (!$inquiry) {
            return $this->failNotFound('Inquiry not found');
        }

        return $this->respond([
            'success' => true,
            'data' => $inquiry
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/contact/{id}",
     *     tags={"Contact"},
     *     summary="Update contact inquiry",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", enum={"pending", "resolved", "archived"}, description="Inquiry status", example="resolved"),
     *             @OA\Property(property="notes", type="string", description="Admin notes"),
     *             @OA\Property(property="assignedTo", type="string", description="Assigned user ID")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Inquiry updated successfully")
     * )
     */
    public function updateInquiry($id)
    {
        try {
            $data = $this->request->getJSON(true);
            $inquiryModel = new ContactInquiry();

            $inquiry = $inquiryModel->find($id);
            if (!$inquiry) {
                return $this->failNotFound('Inquiry not found');
            }

            $updateData = [];
            if (isset($data['status'])) {
                $updateData['status'] = $data['status'];
            }
            if (isset($data['notes'])) {
                $updateData['notes'] = $data['notes'];
            }

            $inquiryModel->update($id, $updateData);

            $updatedInquiry = $inquiryModel->find($id);

            return $this->respond([
                'success' => true,
                'message' => 'Contact inquiry updated successfully',
                'data' => $updatedInquiry
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update contact inquiry',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/contact/{id}",
     *     tags={"Contact"},
     *     summary="Delete contact inquiry",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Inquiry deleted successfully")
     * )
     */
    public function deleteInquiry($id)
    {
        $inquiryModel = new ContactInquiry();
        $inquiryModel->delete($id);

        return $this->respond([
            'success' => true,
            'message' => 'Inquiry deleted successfully'
        ]);
    }
}
