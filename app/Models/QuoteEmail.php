<?php

namespace App\Models;

use CodeIgniter\Model;

class QuoteEmail extends Model
{
    protected $table            = 'quote_emails';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'quoteRequestId',
        'recipient',
        'subject',
        'body',
        'status',
        'sentAt',
        'sentBy',
        'error',
        'metadata',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
