<?php

namespace App\Models;

use CodeIgniter\Model;

class SiteSettings extends Model
{
    protected $table            = 'site_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'siteName',
        'logoUrl',
        'faviconUrl',
        'description',
        'contactEmail',
        'contactPhone',
        'address',
        'socialLinks',
        'defaultCurrency',
        'timezone',
        'maintenanceMode',
        'allowRegistration',
        'requireEmailVerification',
        'jobApprovalRequired',
        'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = null;
    protected $updatedField  = 'updatedAt';
}
