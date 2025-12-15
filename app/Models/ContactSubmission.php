<?php

namespace App\Models;

use CodeIgniter\Model;

class ContactSubmission extends Model
{
    protected $table            = 'contact_submissions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'email',
        'phone',
        'company',
        'service',
        'message',
        'status',
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
