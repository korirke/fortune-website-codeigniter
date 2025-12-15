<?php

namespace App\Models;

use CodeIgniter\Model;

class Notification extends Model
{
    protected $table            = 'notifications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'userId',
        'type',
        'title',
        'message',
        'link',
        'isRead',
        'readAt',
        'metadata',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
