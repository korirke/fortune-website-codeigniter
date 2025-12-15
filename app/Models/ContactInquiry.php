<?php

namespace App\Models;

use CodeIgniter\Model;

class ContactInquiry extends Model
{
    protected $table            = 'contact_inquiries';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'inquiry',
        'firstName',
        'lastName',
        'email',
        'message',
        'status',
        'notes',
        'metadata',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
