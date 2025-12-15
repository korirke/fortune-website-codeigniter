<?php

namespace App\Models;

use CodeIgniter\Model;

class QuoteAttachment extends Model
{
    protected $table            = 'quote_attachments';
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
        'uploadedBy',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
