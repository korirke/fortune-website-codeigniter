<?php

namespace App\Models;

use CodeIgniter\Model;

class FaqAnalytics extends Model
{
    protected $table            = 'faq_analytics';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'faqId',
        'action',
        'userAgent',
        'ipAddress',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
