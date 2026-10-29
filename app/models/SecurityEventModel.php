<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: SecurityEventModel
 * 
 * Automatically generated via CLI.
 */
class SecurityEventModel extends Model {

    protected $table       = 'auth_security_events';
    protected $primary_key = 'id';
    protected $timestamps  = false;

    private static $redacted_keys = [
        'password',
        'current_password',
        'new_password',
        'password_hash',
        'token',
        'raw_token',
        'refresh_token',
        'access_token',
        'email',
        'secret',
    ];

    /**
     * Record a security event with sanitized metadata.
     *
     * @param string   $event_type Short snake_case identifier (max 40 chars)
     * @param int|null $user_id    Nullable user ID (`ON DELETE SET NULL`)
     * @param array    $metadata   Safe non-PII diagnostic fields
     */
    public function record($event_type, $user_id = null, array $metadata = [])
    {
        $type = substr(preg_replace('/[^a-z0-9_]/i', '_', (string) $event_type), 0, 40);
        if ($type === '') {
            return;
        }

        $safe = [];
        foreach ($metadata as $k => $v) {
            $key_lower = strtolower((string) $k);
            if (in_array($key_lower, self::$redacted_keys, true)) {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $safe[(string) $k] = $v;
            }
        }

        $this->db->table($this->table)->insert([
            'user_id'       => $user_id !== null ? (int) $user_id : null,
            'event_type'    => $type,
            'safe_metadata' => !empty($safe) ? json_encode($safe) : null,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Return recent security events for an authenticated runner's Account view.
     *
     * @param int $user_id
     * @param int $limit
     * @return array
     */
    public function recent_for_user($user_id, $limit = 8)
    {
        $max  = max(1, min(25, (int) $limit));
        $rows = $this->db->table($this->table)
            ->select('id, event_type, safe_metadata, created_at')
            ->where('user_id', (int) $user_id)
            ->order_by('id', 'DESC')
            ->limit($max)
            ->get_all() ?: [];

        return array_map(function ($row) {
            $meta = null;
            if (!empty($row['safe_metadata'])) {
                $decoded = json_decode((string) $row['safe_metadata'], true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            return [
                'id'         => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'metadata'   => $meta,
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }
}