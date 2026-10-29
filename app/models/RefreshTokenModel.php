<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: RefreshTokenModel
 *
 * Read/invalidation side of the refresh-token table that the framework's Api
 * library writes to in issue_tokens().
 *
 * Both Api.php and this model read `refresh_token_table`,
 * `refresh_token_column`, and `refresh_token_key` from app/config/api.php so
 * schema configuration lives in one place.
 */
class RefreshTokenModel extends Model
{
    protected $table        = 'refresh_tokens';
    protected $primary_key  = 'id';
    protected $timestamps   = false;
    protected $token_column = 'token_hash';

    public function __construct()
    {
        parent::__construct();

        $table = config_item('refresh_token_table') ?: $this->table;

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $table) === 1) {
            $this->table = (string) $table;
        }

        $column = config_item('refresh_token_column') ?: $this->token_column;

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $column) === 1) {
            $this->token_column = (string) $column;
        }
    }

    /**
     * HMAC a raw token exactly the way Api.php does, so a token issued by the
     * framework can be matched here. The raw token is never stored.
     *
     * @param string $token
     * @return string 64-char hex
     */
    public function hash($token)
    {
        return hash_hmac(
            'sha256',
            (string) $token,
            (string) config_item('refresh_token_key')
        );
    }

    /**
     * Is this raw refresh token present, unexpired and live?
     *
     * @param string $token
     * @return array|null
     */
    public function find_valid($token)
    {
        if ((string) $token === '') {
            return null;
        }

        $now_utc = gmdate('Y-m-d H:i:s');

        $row = $this->db->table($this->table)
            ->select('id, user_id, expires_at, jti, revoked_at')
            ->where($this->token_column, $this->hash($token))
            ->where_null('revoked_at')
            ->where('expires_at', '>', $now_utc)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Rotate-on-use: the presented token dies whether or not the caller then
     * succeeds in getting a new pair.
     *
     * @param string $token
     * @return void
     */
    public function revoke($token)
    {
        if ((string) $token === '') {
            return;
        }

        $this->db->table($this->table)
            ->where($this->token_column, $this->hash($token))
            ->delete();
    }

    /**
     * Drop every stored token for a user - used by password reset and by
     * account deletion so no prior session survives.
     *
     * @param int $user_id
     * @return int rows removed
     */
    public function revoke_all_for_user($user_id)
    {
        return (int) $this->db->table($this->table)
            ->where('user_id', (int) $user_id)
            ->delete();
    }

    /**
     * Housekeeping for expired tokens.
     *
     * @return int rows removed
     */
    public function purge_expired()
    {
        $now_utc = gmdate('Y-m-d H:i:s');

        return (int) $this->db->table($this->table)
            ->where('expires_at', '<', $now_utc)
            ->delete();
    }
}