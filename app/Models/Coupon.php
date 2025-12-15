<?php

namespace App\Models;

use CodeIgniter\Model;

class Coupon extends Model
{
    protected $table            = 'coupons';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'code',
        'type',
        'value',
        'usageLimit',
        'usageCount',
        'minPurchase',
        'maxDiscount',
        'applicablePlans',
        'expiresAt',
        'isActive',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
