<?php

namespace App\Controllers\PricingRequest;

use App\Controllers\BaseController;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestAttachment;
use App\Models\QuoteEmail;
use App\Libraries\EmailHelper;
use App\Traits\NormalizedResponseTrait;

class PricingRequest extends BaseController
{
    use NormalizedResponseTrait;

    private const DEFAULT_PRIORITY = 'normal';

    private function getIncomingData(): array
    {
        $contentType = strtolower($this->request->getHeaderLine('Content-Type') ?? '');

        // JSON requests
        if (str_contains($contentType, 'application/json')) {
            $json = $this->request->getJSON(true);
            return is_array($json) ? $json : [];
        }

        // multipart/form-data or x-www-form-urlencoded
        $post = $this->request->getPost();
        return is_array($post) ? $post : [];
    }

    private function safeJsonDecode($value)
    {
        if (!is_string($value))
            return $value;

        $trim = trim($value);
        if ($trim === '')
            return $value;

        $looksJson =
            (str_starts_with($trim, '{') && str_ends_with($trim, '}')) ||
            (str_starts_with($trim, '[') && str_ends_with($trim, ']'));

        if (!$looksJson)
            return $value;

        $decoded = json_decode($trim, true);
        if (json_last_error() === JSON_ERROR_NONE)
            return $decoded;

        log_message('error', 'safeJsonDecode failed: ' . json_last_error_msg() . ' | value=' . substr($trim, 0, 300));
        return $value;
    }

    private function normalizeServicesForStorage($services): string
    {
        if (is_array($services)) {
            return json_encode(array_values($services));
        }

        if (is_string($services)) {
            $decoded = $this->safeJsonDecode($services);
            if (is_array($decoded)) {
                return json_encode(array_values($decoded));
            }

            if (str_contains($services, ',')) {
                $parts = array_values(array_filter(array_map('trim', explode(',', $services))));
                return json_encode($parts);
            }

            return json_encode([$services]);
        }

        return json_encode([]);
    }

    private function normalizeMetadataForStorage($metadata): ?string
    {
        if ($metadata === null)
            return null;

        if (is_array($metadata) || is_object($metadata)) {
            return json_encode($metadata);
        }

        if (is_string($metadata)) {
            $decoded = $this->safeJsonDecode($metadata);
            if (is_array($decoded))
                return json_encode($decoded);
            return json_encode(['value' => $metadata]);
        }

        return json_encode(['value' => $metadata]);
    }

    private function hydrateQuoteRow(array $quote): array
    {
        // services
        if (array_key_exists('services', $quote)) {
            $decoded = $this->safeJsonDecode($quote['services']);
            if (is_array($decoded))
                $quote['services'] = $decoded;
            else
                $quote['services'] = $quote['services'] !== null ? [$quote['services']] : [];
        } else {
            $quote['services'] = [];
        }

        // metadata
        if (array_key_exists('metadata', $quote)) {
            $decoded = $this->safeJsonDecode($quote['metadata']);
            $quote['metadata'] = is_array($decoded) ? $decoded : null;
        }

        return $quote;
    }

    /**
     * POST /api/pricing-request (public)
     * Supports multipart/form-data (with optional files[]) or JSON.
     */
    public function create()
    {
        try {
            $contentType = $this->request->getHeaderLine('Content-Type') ?? '';
            log_message('info', 'PricingRequest.create called. Content-Type=' . $contentType);

            $data = $this->getIncomingData();

            $required = ['name', 'email', 'phone', 'country', 'industry', 'teamSize', 'services'];
            foreach ($required as $key) {
                if (!isset($data[$key]) || $data[$key] === '') {
                    log_message('error', 'PricingRequest.create missing field: ' . $key);
                    return $this->fail("Missing required field: {$key}", 400);
                }
            }

            $quoteModel = new QuoteRequest();
            $id = uniqid('quote_');

            $priority = $data['priority'] ?? self::DEFAULT_PRIORITY;
            if ($priority === null || $priority === '')
                $priority = self::DEFAULT_PRIORITY;

            $now = date('Y-m-d H:i:s');

            $insert = [
                'id' => $id,
                'formType' => 'quote',
                'name' => (string) $data['name'],
                'email' => strtolower(trim((string) $data['email'])),
                'phone' => (string) $data['phone'],
                'company' => $data['company'] ?? null,
                'country' => (string) $data['country'],
                'industry' => (string) $data['industry'],
                'teamSize' => (string) $data['teamSize'],
                'services' => $this->normalizeServicesForStorage($data['services']),
                'message' => $data['message'] ?? null,
                'status' => 'new',
                'priority' => $priority,
                'assignedTo' => $data['assignedTo'] ?? null,
                'notes' => $data['notes'] ?? null,
                'estimatedValue' => $data['estimatedValue'] ?? null,
                'source' => 'website',
                'metadata' => $this->normalizeMetadataForStorage($data['metadata'] ?? null),
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            log_message('info', 'PricingRequest.create inserting quote id=' . $id . ' priority=' . $priority);

            // Insert
            $quoteModel->insert($insert);

            // Multiple files support: key "files" (up to 5)
            $files = $this->request->getFiles();
            if (isset($files['files'])) {
                $uploaded = $files['files'];
                if (!is_array($uploaded))
                    $uploaded = [$uploaded];
                $uploaded = array_slice($uploaded, 0, 5);

                $attachmentModel = new QuoteRequestAttachment();
                $uploadPath = WRITEPATH . 'uploads/quotes/';
                if (!is_dir($uploadPath))
                    mkdir($uploadPath, 0755, true);

                foreach ($uploaded as $file) {
                    if (!$file || !$file->isValid())
                        continue;

                    $newName = $file->getRandomName();
                    $file->move($uploadPath, $newName);

                    $attachmentModel->insert([
                        'id' => uniqid('attachment_'),
                        'quoteRequestId' => $id,
                        'fileName' => $newName,
                        'originalName' => $file->getClientName(),
                        'fileUrl' => '/uploads/quotes/' . $newName,
                        'fileSize' => $file->getSize(),
                        'mimeType' => $file->getClientMimeType(),
                        'createdAt' => $now,
                    ]);
                }
            }

            // Non-blocking admin email notify
            try {
                $emailHelper = new EmailHelper();
                $emailHelper->notifyAdminNewQuote($insert);
            } catch (\Throwable $e) {
                log_message('error', 'notifyAdminNewQuote failed: ' . $e->getMessage());
            }

            return $this->respondCreated([
                'success' => true,
                'message' => 'Your quote request has been received. Our sales team will reach out shortly.',
                'data' => ['id' => $id],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'PricingRequest.create fatal: ' . $e->getMessage());
            return $this->fail('Failed to submit quote request', 400);
        }
    }

    /**
     * GET /api/pricing-request (admin)
     */
    public function findAll()
    {
        try {
            $quoteModel = new QuoteRequest();
            $attachmentModel = new QuoteRequestAttachment();
            $quoteEmailModel = new QuoteEmail();

            $page = (int) ($this->request->getGet('page') ?? 1);
            $limit = (int) ($this->request->getGet('limit') ?? 50);
            $page = max(1, $page);
            $limit = min(max(1, $limit), 200);
            $skip = ($page - 1) * $limit;

            $status = $this->request->getGet('status');
            $priority = $this->request->getGet('priority');

            if ($status && $status !== 'all')
                $quoteModel->where('status', $status);
            if ($priority && $priority !== 'all')
                $quoteModel->where('priority', $priority);

            $total = $quoteModel->countAllResults(false);

            $quotes = $quoteModel
                ->orderBy('createdAt', 'DESC')
                ->findAll($limit, $skip);

            $out = [];
            foreach ($quotes as $q) {
                $q = $this->hydrateQuoteRow($q);

                $clientAttachmentsCount = $attachmentModel->where('quoteRequestId', $q['id'])->countAllResults();
                $emailsCount = $quoteEmailModel->where('quoteRequestId', $q['id'])->countAllResults();

                // If you have a separate admin attachments table (QuoteAttachment), wire it here.
                $adminAttachmentsCount = 0;

                $q['_count'] = [
                    'clientAttachments' => $clientAttachmentsCount,
                    'adminAttachments' => $adminAttachmentsCount,
                    'emails' => $emailsCount,
                ];

                $out[] = $q;
            }

            return $this->respond([
                'success' => true,
                'data' => $out,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'totalPages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'PricingRequest.findAll error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch quote requests',
                'data' => [],
            ], 500);
        }
    }

    /**
     * GET /api/pricing-request/{id}
     */
    public function findOne($id = null)
    {
        $id = $id ?: $this->request->getUri()->getSegment(3);
        if (!$id)
            return $this->fail('Quote request ID is required', 400);

        try {
            $quoteModel = new QuoteRequest();
            $attachmentModel = new QuoteRequestAttachment();
            $quoteEmailModel = new QuoteEmail();

            $quote = $quoteModel->find($id);
            if (!$quote)
                return $this->failNotFound('Quote request not found');

            $quote = $this->hydrateQuoteRow($quote);

            $clientAttachments = $attachmentModel->where('quoteRequestId', $id)
                ->orderBy('createdAt', 'DESC')
                ->findAll();

            $emails = $quoteEmailModel->where('quoteRequestId', $id)
                ->orderBy('createdAt', 'DESC')
                ->findAll();

            $quote['clientAttachments'] = $clientAttachments;
            $quote['adminAttachments'] = [];
            $quote['emails'] = $emails;
            $quote['attachments'] = $quote['adminAttachments'];

            return $this->respond(['success' => true, 'data' => $quote]);
        } catch (\Throwable $e) {
            log_message('error', 'PricingRequest.findOne error: ' . $e->getMessage());
            return $this->fail('Failed to fetch quote request', 400);
        }
    }

    /**
     * PUT /api/pricing-request/{id}
     */
    public function update($id = null)
    {
        $id = $id ?: $this->request->getUri()->getSegment(3);
        if (!$id)
            return $this->fail('Quote request ID is required', 400);

        try {
            $quoteModel = new QuoteRequest();
            $existing = $quoteModel->find($id);
            if (!$existing)
                return $this->failNotFound('Quote request not found');

            $data = $this->getIncomingData();

            if (isset($data['email']))
                $data['email'] = strtolower(trim((string) $data['email']));
            if (isset($data['services']))
                $data['services'] = $this->normalizeServicesForStorage($data['services']);
            if (array_key_exists('metadata', $data))
                $data['metadata'] = $this->normalizeMetadataForStorage($data['metadata']);

            // Prevent priority null update if DB disallows null
            if (array_key_exists('priority', $data) && ($data['priority'] === null || $data['priority'] === '')) {
                $data['priority'] = self::DEFAULT_PRIORITY;
            }

            $data['updatedAt'] = date('Y-m-d H:i:s');

            $quoteModel->update($id, $data);

            $updated = $quoteModel->find($id);
            $updated = $this->hydrateQuoteRow($updated);

            return $this->respond([
                'success' => true,
                'message' => 'Quote request updated',
                'data' => $updated,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'PricingRequest.update error: ' . $e->getMessage());
            return $this->fail('Failed to update quote request', 400);
        }
    }

    /**
     * PUT /api/pricing-request/{id}/status
     */
    public function updateStatus($id = null)
    {
        $id = $id ?: $this->request->getUri()->getSegment(3);
        if (!$id)
            return $this->fail('Quote request ID is required', 400);

        try {
            $data = $this->getIncomingData();
            if (!isset($data['status']) || $data['status'] === '') {
                return $this->fail('status is required', 400);
            }

            $quoteModel = new QuoteRequest();
            $existing = $quoteModel->find($id);
            if (!$existing)
                return $this->failNotFound('Quote request not found');

            $update = [
                'status' => $data['status'],
                'updatedAt' => date('Y-m-d H:i:s'),
            ];
            if (isset($data['notes']))
                $update['notes'] = $data['notes'];

            $quoteModel->update($id, $update);

            return $this->respond([
                'success' => true,
                'message' => 'Status updated',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'PricingRequest.updateStatus error: ' . $e->getMessage());
            return $this->fail('Failed to update status', 400);
        }
    }

    /**
     * POST /api/pricing-request/{id}/send-quote
     */
    public function sendQuote($id = null)
    {
        $id = $id ?: $this->request->getUri()->getSegment(3);
        if (!$id)
            return $this->fail('Quote request ID is required', 400);

        try {
            $quoteModel = new QuoteRequest();
            $quote = $quoteModel->find($id);
            if (!$quote)
                return $this->failNotFound('Quote request not found');

            $data = $this->getIncomingData();

            $subject = $data['subject'] ?? 'Quote for Your Services';
            $body = $data['body'] ?? ($data['message'] ?? 'Please find our quote for your requested services.');
            $quoteAmount = $data['quoteAmount'] ?? null;

            $emailHelper = new EmailHelper();
            $send = $emailHelper->sendQuote(
                $quote['email'],
                $subject,
                $body,
                $quote['name'],
                $quote['company'] ?? null,
                $quoteAmount,
                $data['currency'] ?? 'KES'
            );

            if (!($send['success'] ?? false)) {
                return $this->fail('Failed to send quote email: ' . ($send['error'] ?? 'Unknown error'), 400);
            }

            $now = date('Y-m-d H:i:s');

            $quoteModel->update($id, [
                'status' => 'quoted',
                'quoteSentAt' => $now,
                'quoteAmount' => $quoteAmount,
                'updatedAt' => $now,
            ]);

            $quoteEmailModel = new QuoteEmail();
            $emailId = uniqid('email_');
            $quoteEmailModel->insert([
                'id' => $emailId,
                'quoteRequestId' => $id,
                'recipient' => $quote['email'],
                'subject' => $subject,
                'body' => $body,
                'status' => 'sent',
                'sentAt' => $now,
                'metadata' => json_encode([
                    'quoteAmount' => $quoteAmount,
                    'currency' => $data['currency'] ?? 'KES',
                ]),
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Quote sent successfully',
                'data' => ['emailId' => $emailId],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'PricingRequest.sendQuote error: ' . $e->getMessage());
            return $this->fail('Failed to process quote email', 400);
        }
    }

    /**
     * POST /api/pricing-request/{id}/upload-attachment (admin)
     * Single file key: "file"
     */
    public function uploadAttachment($id = null)
    {
        $id = $id ?: $this->request->getUri()->getSegment(3);
        if (!$id)
            return $this->fail('Quote request ID is required', 400);

        try {
            $file = $this->request->getFile('file');
            if (!$file || !$file->isValid())
                return $this->fail('No file provided', 400);

            $quoteModel = new QuoteRequest();
            $quote = $quoteModel->find($id);
            if (!$quote)
                return $this->failNotFound('Quote request not found');

            $uploadPath = WRITEPATH . 'uploads/quotes/';
            if (!is_dir($uploadPath))
                mkdir($uploadPath, 0755, true);

            $newName = $file->getRandomName();
            $file->move($uploadPath, $newName);

            $now = date('Y-m-d H:i:s');

            $attachmentModel = new QuoteRequestAttachment();
            $attachmentData = [
                'id' => uniqid('attachment_'),
                'quoteRequestId' => $id,
                'fileName' => $newName,
                'originalName' => $file->getClientName(),
                'fileUrl' => '/uploads/quotes/' . $newName,
                'fileSize' => $file->getSize(),
                'mimeType' => $file->getClientMimeType(),
                'createdAt' => $now,
            ];
            $attachmentModel->insert($attachmentData);

            return $this->respond([
                'success' => true,
                'message' => 'Attachment uploaded successfully',
                'data' => $attachmentData,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'PricingRequest.uploadAttachment error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to upload attachment',
                'data' => [],
            ], 500);
        }
    }

    /**
     * GET /api/pricing-request/{id}/attachments
     */
    public function getAttachments($id = null)
    {
        $id = $id ?: $this->request->getUri()->getSegment(3);
        if (!$id)
            return $this->fail('Quote request ID is required', 400);

        try {
            $attachmentModel = new QuoteRequestAttachment();
            $attachments = $attachmentModel
                ->where('quoteRequestId', $id)
                ->orderBy('createdAt', 'DESC')
                ->findAll();

            return $this->respond(['success' => true, 'data' => $attachments]);
        } catch (\Throwable $e) {
            log_message('error', 'PricingRequest.getAttachments error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch attachments',
                'data' => [],
            ], 500);
        }
    }
}
