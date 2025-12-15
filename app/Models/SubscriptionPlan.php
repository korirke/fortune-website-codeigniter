<?php

namespace App\Models;

use CodeIgniter\Model;

class SubscriptionPlan extends Model
{
    protected $table            = 'subscription_plans';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billingPeriod',
        'features',
        'jobPostingLimit',
        'featuredListings',
        'cvDatabaseAccess',
        'analyticsAccess',
        'supportLevel',
        'isActive',
        'sortOrder',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'createdAt';
    protected $updatedField  = 'updatedAt';
}
