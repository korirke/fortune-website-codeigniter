<?php

namespace App\Models;

use CodeIgniter\Model;

class QuoteRequestAttachment extends Model
{
    protected $table            = 'quote_request_attachments';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'quoteRequestId',
        'fileName',
        'originalName',
        'fileUrl',
        'fileSize',
        'mimeType',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
