<?php

namespace App\Models;

use CodeIgniter\Model;

class EducationQualificationLevel extends Model
{
    protected $table            = 'education_qualification_levels';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'id',
        'key',
        'label',
        'sortOrder',
        'isActive',
        'isSystem',
        'createdAt',
        'updatedAt',
    ];

    protected $validationRules    = [];
    protected $validationMessages = [];

    // ── Scopes

    public function active(): array
    {
        return $this
            ->where('isActive', 1)
            ->orderBy('sortOrder', 'ASC')
            ->orderBy('label', 'ASC')
            ->findAll();
    }

    public function allOrdered(): array
    {
        return $this
            ->orderBy('sortOrder', 'ASC')
            ->orderBy('label', 'ASC')
            ->findAll();
    }

    public function keyLabelMap(): array
    {
        $rows = $this->active();
        $map  = [];
        foreach ($rows as $row) {
            $map[$row['key']] = $row['label'];
        }
        return $map;
    }

    public function activeKeys(): array
    {
        return array_column($this->active(), 'key');
    }
}