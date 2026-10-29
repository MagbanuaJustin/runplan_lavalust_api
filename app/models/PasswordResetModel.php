<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: PasswordResetModel
 *
 * Automatically generated via CLI.
 */
class PasswordResetModel extends Model
{
    protected $table       = 'password_reset_tokens';
    protected $primary_key = 'id';
    protected $timestamps  = false;

    const TTL_MINUTES = 30;

    /**
     * Compute a 64-character hex HMAC for a raw reset token.
     */
    public function hash($raw_token)
    {
        $key = (string) (
            config_item('refresh_token_key')
            ?: config_item('jwt_secret')
            ?: 'runplan-reset-fallback-key'
        );

        return hash_hmac('sha256', (string) $raw_token, $key);
    }

    /**
     * Issue a fresh single-use token for $user_id and invalidate prior unused ones.
     *
     * @param int $user_id
     * @return array{token:string,expires_at:string,ttl_minutes:int}
     */
    public function issue($user_id)
    {
        $uid        = (int) $user_id;
        $now_utc    = gmdate('Y-m-d H:i:s');
        $expires_at = gmdate('Y-m-d H:i:s', time() + (self::TTL_MINUTES * 60));

        // Invalidate any prior unused tokens for this user so only the latest works.
        $this->db->table($this->table)
            ->where('user_id', $uid)
            ->where_null('used_at')
            ->update([
                'used_at' => $now_utc,
            ]);

        $raw_token = bin2hex(random_bytes(32));

        $this->db->table($this->table)->insert([
            'user_id'    => $uid,
            'token_hash' => $this->hash($raw_token),
            'expires_at' => $expires_at,
            'used_at'    => null,
            'created_at' => $now_utc,
        ]);

        return [
            'token'       => $raw_token,
            'expires_at'  => $expires_at,
            'ttl_minutes' => self::TTL_MINUTES,
        ];
    }

    /**
     * Check whether $raw_token exists, has not been used, and has not expired.
     *
     * @param string $raw_token
     * @return array|null
     */
    public function find_valid($raw_token)
    {
        $raw_token = trim((string) $raw_token);

        if ($raw_token === '' || strlen($raw_token) > 256) {
            return null;
        }

        $now_utc = gmdate('Y-m-d H:i:s');

        $row = $this->db->table($this->table)
            ->select('id, user_id, expires_at, created_at')
            ->where('token_hash', $this->hash($raw_token))
            ->where_null('used_at')
            ->where('expires_at', '>', $now_utc)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Atomically validate and mark $raw_token as used so it cannot be replayed.
     *
     * @param string $raw_token
     * @return array|null The consumed token row, or null if invalid or expired.
     */
    public function consume($raw_token)
    {
        $row = $this->find_valid($raw_token);

        if (!$row) {
            return null;
        }

        $now_utc = gmdate('Y-m-d H:i:s');

        // Keep these conditions in the update so two simultaneous requests
        // cannot both successfully consume the same token.
        $updated = $this->db->table($this->table)
            ->where('id', (int) $row['id'])
            ->where_null('used_at')
            ->where('expires_at', '>', $now_utc)
            ->update([
                'used_at' => $now_utc,
            ]);

        if ((int) $updated !== 1) {
            return null;
        }

        return $row;
    }

    /**
     * Remove expired or already-used tokens older than 24 hours.
     */
    public function purge_stale()
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);

        return (int) $this->db->table($this->table)
            ->where('expires_at', '<', $cutoff)
            ->or_where('used_at', '<', $cutoff)
            ->delete();
    }
}