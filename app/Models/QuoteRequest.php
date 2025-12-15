<?php

namespace App\Models;

use CodeIgniter\Model;

class QuoteRequest extends Model
{
    protected $table            = 'quote_requests';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'formType',
        'name',
        'email',
        'phone',
        'company',
        'country',
        'industry',
        'teamSize',
        'services',
        'message',
        'status',
        'priority',
        'assignedTo',
        'notes',
        'estimatedValue',
        'quoteAmount',
        'quoteSentAt',
        'source',
        'metadata',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
