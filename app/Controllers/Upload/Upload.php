<?php

namespace App\Controllers\Upload;

use App\Controllers\BaseController;
use App\Models\FileUpload;
use App\Traits\NormalizedResponseTrait;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

class Upload extends BaseController
{
    use NormalizedResponseTrait;

    private int $maxFileSize = 10 * 1024 * 1024; // 10MB

    private array $allowedMimes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/svg+xml',
        'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'video/mp4',
        'video/webm',
    ];

    private function ensureUploadDir(string $uploadPath): void
    {
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }
    }

    private function getFileType(string $mimetype): string
    {
        if (str_starts_with($mimetype, 'image/'))
            return 'image';
        if (str_starts_with($mimetype, 'video/'))
            return 'video';
        if ($mimetype === 'application/pdf')
            return 'document';
        // ← ADDED: Word document types
        if (in_array($mimetype, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], true))
            return 'document';
        return 'other';
    }

    private function flattenFilesArray(array $files): array
    {
        $flat = [];
        $walk = function ($node) use (&$flat, &$walk) {
            if ($node instanceof UploadedFile) {
                $flat[] = $node;
                return;
            }
            if (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $walk($files);
        return $flat;
    }

    public function uploadFile(): ResponseInterface
    {
        $file = $this->request->getFile('file');
        $uploadedBy = $this->request->getGet('uploadedBy');

        if (!$file || !$file->isValid()) {
            return $this->fail('No file provided', 400);
        }

        if ($file->getSize() > $this->maxFileSize) {
            return $this->fail('File size exceeds limit (10MB)', 400);
        }

        $mime = (string) $file->getClientMimeType();
        if (!in_array($mime, $this->allowedMimes, true)) {
            return $this->fail("Invalid file type: {$mime}", 400);
        }

        $uploadPath = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR;
        $this->ensureUploadDir($uploadPath);

        $ext = $file->getClientExtension();
        $newName = $ext ? (uniqid('', true) . '.' . $ext) : $file->getRandomName();

        if (!$file->move($uploadPath, $newName)) {
            return $this->fail('Failed to save uploaded file', 500);
        }

        $fileType = $this->getFileType($mime);

        $fileData = [
            'id' => uniqid('file_', true),
            'filename' => $newName,
            'originalName' => $file->getClientName(),
            'mimetype' => $mime,
            'size' => $file->getSize(),
            'path' => $uploadPath . $newName,
            'url' => base_url('uploads/' . $newName),
            'fileType' => $fileType,
            'uploadedBy' => $uploadedBy,
        ];

        $fileModel = new FileUpload();
        $fileModel->insert($fileData);

        return $this->respond(['success' => true, 'data' => $fileData]);
    }

    public function uploadFiles(): ResponseInterface
    {
        $uploadedBy = $this->request->getGet('uploadedBy');

        $all = $this->request->getFiles();
        $flatFiles = $this->flattenFilesArray($all);

        if (!$flatFiles || count($flatFiles) === 0) {
            return $this->fail('No files provided', 400);
        }

        $uploadPath = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR;
        $this->ensureUploadDir($uploadPath);

        $fileModel = new FileUpload();
        $results = [];

        foreach ($flatFiles as $file) {
            if (!$file->isValid())
                continue;
            if ($file->getSize() > $this->maxFileSize)
                continue;

            $mime = (string) $file->getClientMimeType();
            if (!in_array($mime, $this->allowedMimes, true))
                continue;

            $ext = $file->getClientExtension();
            $newName = $ext ? (uniqid('', true) . '.' . $ext) : $file->getRandomName();

            if (!$file->move($uploadPath, $newName))
                continue;

            $fileType = $this->getFileType($mime);

            $row = [
                'id' => uniqid('file_', true),
                'filename' => $newName,
                'originalName' => $file->getClientName(),
                'mimetype' => $mime,
                'size' => $file->getSize(),
                'path' => $uploadPath . $newName,
                'url' => base_url('uploads/' . $newName),
                'fileType' => $fileType,
                'uploadedBy' => $uploadedBy,
            ];

            $fileModel->insert($row);
            $results[] = $row;
        }

        if (count($results) === 0) {
            return $this->fail('No valid files provided', 400);
        }

        return $this->respond(['success' => true, 'data' => $results]);
    }

    public function listFiles(): ResponseInterface
    {
        $fileModel = new FileUpload();
        $uploadedBy = $this->request->getGet('uploadedBy');
        $fileType = $this->request->getGet('fileType');
        $search = $this->request->getGet('search');

        if ($uploadedBy)
            $fileModel->where('uploadedBy', $uploadedBy);
        if ($fileType && $fileType !== 'all')
            $fileModel->where('fileType', $fileType);

        if ($search) {
            $fileModel->groupStart()
                ->like('originalName', $search)
                ->orLike('filename', $search)
                ->groupEnd();
        }

        $files = $fileModel->orderBy('createdAt', 'DESC')->findAll();

        return $this->respond(['success' => true, 'data' => $files ?: []]);
    }

    public function getStats(): ResponseInterface
    {
        $db = \Config\Database::connect();

        $total = (int) $db->table('file_uploads')->countAllResults();
        $images = (int) $db->table('file_uploads')->where('fileType', 'image')->countAllResults();
        $documents = (int) $db->table('file_uploads')->where('fileType', 'document')->countAllResults();
        $videos = (int) $db->table('file_uploads')->where('fileType', 'video')->countAllResults();

        $sumRow = $db->table('file_uploads')->selectSum('size')->get()->getRowArray();
        $totalSize = (int) ($sumRow['size'] ?? 0);

        return $this->respond([
            'success' => true,
            'data' => [
                'total' => $total,
                'images' => $images,
                'documents' => $documents,
                'videos' => $videos,
                'totalSize' => $totalSize,
            ]
        ]);
    }

    public function getFile(string $id = null): ResponseInterface
    {
        if (!$id)
            return $this->fail('File ID is required', 400);

        $fileModel = new FileUpload();
        $file = $fileModel->find($id);

        if (!$file)
            return $this->failNotFound('File not found');

        return $this->respond(['success' => true, 'data' => $file]);
    }

    public function deleteFile(string $id = null): ResponseInterface
    {
        if (!$id)
            return $this->fail('File ID is required', 400);

        $fileModel = new FileUpload();
        $file = $fileModel->find($id);

        if (!$file)
            return $this->failNotFound('File not found');

        if (!empty($file['path']) && is_file($file['path'])) {
            @unlink($file['path']);
        }

        $fileModel->delete($id);

        return $this->respond(['success' => true, 'message' => 'File deleted successfully']);
    }
}