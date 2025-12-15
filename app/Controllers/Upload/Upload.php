<?php

namespace App\Controllers\Upload;

use App\Controllers\BaseController;
use App\Models\FileUpload;
use App\Traits\NormalizedResponseTrait;

/**
 * @OA\Tag(
 *     name="Upload",
 *     description="File upload endpoints"
 * )
 */
class Upload extends BaseController
{
    use NormalizedResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/admin/upload",
     *     tags={"Upload"},
     *     summary="Upload a single file",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"file"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="File to upload"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="File uploaded successfully"
     *     )
     * )
     */
    public function uploadFile()
    {
        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->fail('No file provided', 400);
        }

        $uploadPath = WRITEPATH . 'uploads/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $newName = $file->getRandomName();
        $file->move($uploadPath, $newName);

        $fileModel = new FileUpload();
        $fileData = [
            'id' => uniqid('file_'),
            'filename' => $newName,
            'originalName' => $file->getClientName(),
            'mimetype' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'path' => $uploadPath . $newName,
            'url' => '/uploads/' . $newName
        ];
        $fileModel->insert($fileData);

        return $this->respond([
            'success' => true,
            'data' => $fileData
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/upload/multiple",
     *     tags={"Upload"},
     *     summary="Upload multiple files",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"files"},
     *                 @OA\Property(
     *                     property="files",
     *                     type="array",
     *                     @OA\Items(type="string", format="binary"),
     *                     description="Files to upload"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Files uploaded successfully"
     *     )
     * )
     */
    public function uploadFiles()
    {
        $files = $this->request->getFiles();
        $results = [];
        
        foreach ($files['files'] as $file) {
            if ($file->isValid()) {
                $uploadPath = WRITEPATH . 'uploads/';
                $newName = $file->getRandomName();
                $file->move($uploadPath, $newName);
                
                $results[] = [
                    'filename' => $newName,
                    'originalName' => $file->getClientName()
                ];
            }
        }

        return $this->respond([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/upload",
     *     tags={"Upload"},
     *     summary="List uploaded files",
     *     @OA\Parameter(name="uploadedBy", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="fileType", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Files retrieved successfully"
     *     )
     * )
     */
    public function listFiles()
    {
        try {
            $fileModel = new FileUpload();
            
            // Get query parameters
            $uploadedBy = $this->request->getGet('uploadedBy');
            $fileType = $this->request->getGet('fileType');
            $search = $this->request->getGet('search');
            
            // Apply filters
            if ($uploadedBy) {
                $fileModel->where('uploadedBy', $uploadedBy);
            }
            
            if ($fileType) {
                $fileModel->where('fileType', $fileType);
            }
            
            if ($search) {
                $fileModel->groupStart();
                $fileModel->like('originalName', $search);
                $fileModel->orLike('filename', $search);
                $fileModel->groupEnd();
            }
            
            $files = $fileModel->orderBy('createdAt', 'DESC')->findAll();

            return $this->respond([
                'success' => true,
                'data' => $files ?: []
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/upload/stats",
     *     tags={"Upload"},
     *     summary="Get file statistics",
     *     @OA\Response(
     *         response=200,
     *         description="Stats retrieved successfully"
     *     )
     * )
     */
    public function getStats()
    {
        try {
            $fileModel = new FileUpload();
            
            // Get counts by file type
            $total = $fileModel->countAllResults(false);
            $images = $fileModel->where('fileType', 'image')->countAllResults(false);
            $documents = $fileModel->where('fileType', 'document')->countAllResults(false);
            $videos = $fileModel->where('fileType', 'video')->countAllResults(false);
            
            // Get total size
            $db = \Config\Database::connect();
            $totalSizeResult = $db->table('file_uploads')
                ->selectSum('size')
                ->get()
                ->getRowArray();
            $totalSize = (int) ($totalSizeResult['size'] ?? 0);
            
            return $this->respond([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'images' => $images,
                    'documents' => $documents,
                    'videos' => $videos,
                    'totalSize' => $totalSize
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to retrieve file stats',
                'data' => []
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/upload/{id}",
     *     tags={"Upload"},
     *     summary="Get file info",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="File info retrieved successfully"
     *     )
     * )
     */
    public function getFile($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(3);
        }
        
        if (!$id) {
            return $this->fail('File ID is required', 400);
        }
        
        $fileModel = new FileUpload();
        $file = $fileModel->find($id);

        if (!$file) {
            return $this->failNotFound('File not found');
        }

        return $this->respond([
            'success' => true,
            'data' => $file
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/upload/{id}",
     *     tags={"Upload"},
     *     summary="Delete a file",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="File deleted successfully"
     *     )
     * )
     */
    public function deleteFile($id = null)
    {
        if ($id === null) {
            $id = $this->request->getUri()->getSegment(3);
        }
        
        if (!$id) {
            return $this->fail('File ID is required', 400);
        }
        
        $fileModel = new FileUpload();
        $file = $fileModel->find($id);
        
        if (!$file) {
            return $this->failNotFound('File not found');
        }

        if (file_exists($file['path'])) {
            unlink($file['path']);
        }

        $fileModel->delete($id);

        return $this->respond([
            'success' => true,
            'message' => 'File deleted successfully'
        ]);
    }
}
