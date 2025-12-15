<?php

namespace App\Controllers\PricingRequest;

use App\Controllers\BaseController;
use App\Models\QuoteRequest;
use App\Libraries\EmailHelper;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Pricing Requests",
 *     description="Pricing request endpoints"
 * )
 */
class PricingRequest extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/pricing-request",
     *     tags={"Pricing Requests"},
     *     summary="Submit quote request with optional file attachments (public)",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "phone", "country", "industry", "teamSize", "services"},
     *             @OA\Property(property="name", type="string", description="Contact name", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", description="Contact email", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", description="Contact phone", example="+254722123456"),
     *             @OA\Property(property="company", type="string", description="Company name (optional)", example="Acme Corp"),
     *             @OA\Property(property="country", type="string", description="Country", example="Kenya"),
     *             @OA\Property(property="industry", type="string", description="Industry", example="Technology"),
     *             @OA\Property(property="teamSize", type="string", description="Team size", example="50-100"),
     *             @OA\Property(property="services", type="array", @OA\Items(type="string"), description="Services interested in", example={"Payroll Management", "HR Consulting"}),
     *             @OA\Property(property="message", type="string", description="Additional message (optional)", example="I need help with payroll management")
     *         )
     *     ),
     *     @OA\Response(response="201", description="Quote request created")
     * )
     */
    public function create()
    {
        $data = $this->request->getJSON(true);
        $quoteModel = new QuoteRequest();
        
        $data['id'] = uniqid('quote_');
        $data['status'] = 'new';
        
        // Convert services array to JSON if it's an array
        if (isset($data['services']) && is_array($data['services'])) {
            $data['services'] = json_encode($data['services']);
        }
        
        // Convert metadata to JSON if it's an array/object
        if (isset($data['metadata']) && (is_array($data['metadata']) || is_object($data['metadata']))) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        
        $quoteModel->insert($data);

        // Notify admin about new quote request (non-blocking)
        try {
            $emailHelper = new EmailHelper();
            $emailHelper->notifyAdminNewQuote($data);
        } catch (\Exception $e) {
            log_message('error', 'Failed to send admin notification: ' . $e->getMessage());
        }

        return $this->respondCreated([
            'success' => true,
            'message' => 'Your quote request has been received. Our sales team will reach out shortly.',
            'data' => ['id' => $data['id']]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/pricing-request",
     *     tags={"Pricing Requests"},
     *     summary="Get all quote requests (admin)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"new", "contacted", "quoted", "accepted", "rejected", "archived"}), description="Filter by status"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer"), description="Page number", example=1),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer"), description="Items per page", example=10),
     *     @OA\Response(response="200", description="Quote requests retrieved")
     * )
     */
    public function findAll()
    {
        try {
            $quoteModel = new QuoteRequest();
            
            // Get pagination parameters
            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 20);
            $skip = ($page - 1) * $limit;
            
            // Get filters
            $status = $this->request->getGet('status');
            
            if ($status) {
                $quoteModel->where('status', $status);
            }
            
            // Get total count
            $total = $quoteModel->countAllResults(false);
            
            // Get paginated results
            $quotes = $quoteModel
                ->orderBy('createdAt', 'DESC')
                ->findAll($limit, $skip);
            
            // Format quotes with _count (matching Node.js)
            $attachmentModel = new \App\Models\QuoteRequestAttachment();
            $quoteEmailModel = new \App\Models\QuoteEmail();
            
            $formattedQuotes = [];
            foreach ($quotes as $quote) {
                $formattedQuote = $quote;
                
                // Get counts (matching Node.js)
                $clientAttachmentsCount = $attachmentModel->where('quoteRequestId', $quote['id'])->countAllResults(false);
                $adminAttachmentsCount = $attachmentModel->where('quoteRequestId', $quote['id'])->countAllResults(false);
                $emailsCount = $quoteEmailModel->where('quoteRequestId', $quote['id'])->countAllResults(false);
                
                $formattedQuote['_count'] = [
                    'clientAttachments' => $clientAttachmentsCount,
                    'adminAttachments' => $adminAttachmentsCount,
                    'emails' => $emailsCount
                ];
                
                $formattedQuotes[] = $formattedQuote;
            }

            // Node.js returns { success: true, data: requests, pagination: {...} }
            return $this->respond([
                'success' => true,
                'data' => $formattedQuotes,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'totalPages' => (int) ceil($total / $limit),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve quote requests',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/pricing-request/{id}",
     *     tags={"Pricing Requests"},
     *     summary="Get quote request by ID with attachments",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Quote request retrieved")
     * )
     */
    public function findOne($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(2);
        }
        
        if (!$id) {
            return $this->fail('Quote request ID is required', 400);
        }
        
        $quoteModel = new QuoteRequest();
        $quote = $quoteModel->find($id);

        if (!$quote) {
            return $this->failNotFound('Quote request not found');
        }
        
        // Get attachments (matching Node.js)
        $attachmentModel = new \App\Models\QuoteRequestAttachment();
        $attachments = $attachmentModel->where('quoteRequestId', $id)
            ->orderBy('createdAt', 'DESC')
            ->findAll();
        $quote['attachments'] = $attachments;
        $quote['clientAttachments'] = $attachments; // For backward compatibility
        $quote['adminAttachments'] = $attachments;

        return $this->respond([
            'success' => true,
            'data' => $quote
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/pricing-request/{id}",
     *     tags={"Pricing Requests"},
     *     summary="Update quote request",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", description="Contact name"),
     *             @OA\Property(property="email", type="string", format="email", description="Contact email"),
     *             @OA\Property(property="phone", type="string", description="Contact phone"),
     *             @OA\Property(property="company", type="string", description="Company name"),
     *             @OA\Property(property="country", type="string", description="Country"),
     *             @OA\Property(property="industry", type="string", description="Industry"),
     *             @OA\Property(property="teamSize", type="string", description="Team size"),
     *             @OA\Property(property="services", type="array", @OA\Items(type="string"), description="Services"),
     *             @OA\Property(property="message", type="string", description="Additional message"),
     *             @OA\Property(property="status", type="string", enum={"new", "contacted", "quoted", "accepted", "rejected", "archived"})
     *         )
     *     ),
     *     @OA\Response(response="200", description="Quote request updated")
     * )
     */
    public function update($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(2);
        }
        
        if (!$id) {
            return $this->fail('Quote request ID is required', 400);
        }
        
        $data = $this->request->getJSON(true);
        
        // Convert services array to JSON if it's an array
        if (isset($data['services']) && is_array($data['services'])) {
            $data['services'] = json_encode($data['services']);
        }
        
        // Convert metadata to JSON if it's an array/object
        if (isset($data['metadata']) && (is_array($data['metadata']) || is_object($data['metadata']))) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        
        $quoteModel = new QuoteRequest();
        $quote = $quoteModel->find($id);
        if (!$quote) {
            return $this->failNotFound('Quote request not found');
        }
        
        $quoteModel->update($id, $data);
        $updatedQuote = $quoteModel->find($id);
        
        // JSON parsing is handled automatically by DataTypeHelper

        return $this->respond([
            'success' => true,
            'message' => 'Quote request updated',
            'data' => $updatedQuote
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/pricing-request/{id}/status",
     *     tags={"Pricing Requests"},
     *     summary="Update quote request status",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"new", "contacted", "quoted", "accepted", "rejected", "archived"}, description="New status", example="contacted")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Status updated")
     * )
     */
    public function updateStatus($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(2);
        }
        
        if (!$id) {
            return $this->fail('Quote request ID is required', 400);
        }
        
        $data = $this->request->getJSON(true);
        $quoteModel = new QuoteRequest();
        $quoteModel->update($id, ['status' => $data['status']]);

        return $this->respond([
            'success' => true,
            'message' => 'Status updated'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/pricing-request/{id}/send-quote",
     *     tags={"Pricing Requests"},
     *     summary="Send quote email with attachments",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="subject", type="string", description="Email subject", example="Quote for Your Services"),
     *             @OA\Property(property="message", type="string", description="Email message body"),
     *             @OA\Property(property="quoteAmount", type="number", description="Quote amount", example=50000),
     *             @OA\Property(property="currency", type="string", description="Currency", example="KES")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Quote sent")
     * )
     */
    public function sendQuote($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(2);
        }
        
        if (!$id) {
            return $this->fail('Quote request ID is required', 400);
        }
        
        $data = $this->request->getJSON(true);
        $quoteModel = new QuoteRequest();
        $quote = $quoteModel->find($id);
        
        if (!$quote) {
            return $this->failNotFound('Quote request not found');
        }
        
        // Send quote email
        try {
            $emailHelper = new EmailHelper();
            $recipientName = $quote['name'];
            $company = $quote['company'] ?? null;
            $quoteAmount = $data['quoteAmount'] ?? null;
            $currency = $data['currency'] ?? 'KES';
            $subject = $data['subject'] ?? 'Quote for Your Services';
            $message = $data['message'] ?? 'Please find attached our quote for your requested services.';
            
            $result = $emailHelper->sendQuote(
                $quote['email'],
                $subject,
                $message,
                $recipientName,
                $company,
                $quoteAmount,
                $currency
            );
            
            if ($result['success']) {
                // Update quote status (matching Node.js)
                $quoteModel->update($id, [
                    'status' => 'quoted',
                    'quoteSentAt' => date('Y-m-d H:i:s'),
                    'quoteAmount' => $quoteAmount
                ]);
                
                // Create quote email record (matching Node.js)
                $quoteEmailModel = new \App\Models\QuoteEmail();
                $emailId = uniqid('email_');
                $quoteEmailModel->insert([
                    'id' => $emailId,
                    'quoteRequestId' => $id,
                    'recipient' => $quote['email'],
                    'subject' => $subject,
                    'body' => $message,
                    'status' => 'sent',
                    'sentAt' => date('Y-m-d H:i:s'),
                    'metadata' => json_encode(['quoteAmount' => $quoteAmount, 'currency' => $currency])
                ]);
                
                return $this->respond([
                    'success' => true,
                    'message' => 'Quote sent successfully',
                    'data' => ['emailId' => $emailId]
                ]);
            } else {
                return $this->fail('Failed to send quote email: ' . ($result['error'] ?? 'Unknown error'), 500);
            }
        } catch (\Exception $e) {
            log_message('error', 'Failed to send quote email: ' . $e->getMessage());
            return $this->fail('Failed to send quote email', 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/pricing-request/{id}/upload-attachment",
     *     tags={"Pricing Requests"},
     *     summary="Upload attachment for existing quote (admin)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Attachment uploaded")
     * )
     */
    public function uploadAttachment($id = null)
    {
        try {
            if ($id === null) {
                $id = $this->request->getUri()->getSegment(2);
            }
            
            if (!$id) {
                return $this->fail('Quote request ID is required', 400);
            }
            
            $file = $this->request->getFile('file');
            if (!$file || !$file->isValid()) {
                return $this->fail('No file provided', 400);
            }
            
            $quoteModel = new QuoteRequest();
            $quote = $quoteModel->find($id);
            if (!$quote) {
                return $this->failNotFound('Quote request not found');
            }
            
            $uploadPath = WRITEPATH . 'uploads/quotes/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }
            
            $newName = $file->getRandomName();
            $file->move($uploadPath, $newName);
            
            // Create attachment record (matching Node.js)
            $attachmentModel = new \App\Models\QuoteRequestAttachment();
            $attachmentData = [
                'id' => uniqid('attachment_'),
                'quoteRequestId' => $id,
                'fileName' => $newName,
                'originalName' => $file->getClientName(),
                'fileUrl' => '/uploads/quotes/' . $newName,
                'fileSize' => $file->getSize(),
                'mimeType' => $file->getClientMimeType()
            ];
            $attachmentModel->insert($attachmentData);
            
            return $this->respond([
                'success' => true,
                'message' => 'Attachment uploaded successfully',
                'data' => $attachmentData
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to upload attachment',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/pricing-request/{id}/attachments",
     *     tags={"Pricing Requests"},
     *     summary="Get all attachments for a quote",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response="200", description="Attachments retrieved")
     * )
     */
    public function getAttachments($id = null)
    {
        try {
            if ($id === null) {
                $id = $this->request->getUri()->getSegment(2);
            }
            
            if (!$id) {
                return $this->fail('Quote request ID is required', 400);
            }
            
            $attachmentModel = new \App\Models\QuoteRequestAttachment();
            $attachments = $attachmentModel->where('quoteRequestId', $id)
                ->orderBy('createdAt', 'DESC')
                ->findAll();
            
            return $this->respond([
                'success' => true,
                'data' => $attachments
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch attachments',
                'data' => []
            ], 500);
        }
    }
}
