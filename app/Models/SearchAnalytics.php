<?php

namespace App\Models;

use CodeIgniter\Model;

class SearchAnalytics extends Model
{
    protected $table            = 'search_analytics';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'query',
        'resultsCount',
        'searchType',
        'userAgent',
        'ipAddress',
        'responseTime',
        'createdAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = null;
}
