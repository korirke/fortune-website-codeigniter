<?php

namespace App\Models;

use CodeIgniter\Model;

class ProfileFieldSetting extends Model
{
    protected $table = 'profile_field_settings';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id',
        'fieldName',
        'label',
        'description',
        'category',
        'isVisible',
        'isRequired',
        'displayOrder',
        'createdAt',
        'updatedAt',
    ];

    protected $useTimestamps = false;
}
