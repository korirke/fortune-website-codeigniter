<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailLog extends Model
{
    protected $table            = 'email_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'to',
        'cc',
        'bcc',
        'subject',
        'templateKey',
        'status',
        'openCount',
        'clickCount',
        'errorMessage',
        'metadata',
        'sentAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'sentAt';
    protected $updatedField  = null;
}
