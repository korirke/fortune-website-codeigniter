<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Setting Model
 * All runtime configuration lives here – replaces hardcoded env() calls.
 */
class Setting extends Model
{
    protected $table            = 'settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id', 'settingKey', 'settingValue', 'type',
        'groupName', 'label', 'description', 'isPublic', 'isEncrypted',
        'createdAt', 'updatedAt',
    ];

    protected $useTimestamps = false;

    // ─── Simple in-process cache ──────────────────────────────────────────────
    private static array $cache = [];

    /**
     * Get a setting value by key.
     * Returns $default if not found.
     */
    public function get(string $key, $default = null)
    {
        $key = strtoupper($key);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $row = $this->where('settingKey', $key)->first();

        if (!$row) {
            return $default;
        }

        $value = $this->castValue($row['settingValue'], $row['type'] ?? 'string');

        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Set (upsert) a setting value.
     */
    public function set($key, $value = '', bool|null $escape = null)
    {
        $key = strtoupper($key);

        // Invalidate cache
        unset(self::$cache[$key]);

        $existing = $this->where('settingKey', $key)->first();

        $strValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        if ($existing) {
            return $this->update($existing['id'], [
                'settingValue' => $strValue,
                'updatedAt'    => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->insert([
            'id'           => uniqid('set_'),
            'settingKey'   => $key,
            'settingValue' => $strValue,
            'type'         => 'string',
            'groupName'    => 'general',
            'createdAt'    => date('Y-m-d H:i:s'),
            'updatedAt'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get all settings, optionally filtered by group.
     */
    public function getByGroup(?string $group = null): array
    {
        $q = $this->orderBy('groupName', 'ASC')->orderBy('label', 'ASC');
        if ($group) {
            $q->where('groupName', $group);
        }
        return $q->findAll();
    }

    /**
     * Bulk upsert – expects [key => value] array.
     */
    public function bulkSet(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            $this->set($k, $v);
        }
    }

    /**
     * Clear the in-process cache (useful after bulk updates).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function castValue($value, string $type)
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'boolean' => in_array(strtolower((string) $value), ['true', '1', 'yes'], true),
            'number'  => is_numeric($value) ? (float) $value : 0,
            'json'    => json_decode($value, true) ?? [],
            default   => (string) $value,
        };
    }
}
