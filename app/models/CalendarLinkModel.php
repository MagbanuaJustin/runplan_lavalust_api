<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CalendarLinkModel extends Model
{
    protected $table       = 'integration_accounts';
    protected $primary_key = 'id';

    const PROVIDER = 'google_calendar';

    const STATE_NOT_CONFIGURED  = 'not_configured';
    const STATE_UNAVAILABLE     = 'unavailable';
    const STATE_DISCONNECTED    = 'disconnected';
    const STATE_CONNECTED       = 'connected';
    const STATE_NEEDS_RECONNECT = 'needs_reconnect';

    /** @var bool|null cached answer to "did migration 015 run here?" */
    private static $columns_ready = null;

    /**
     * True when migration 015's columns exist. An older database can still
     * connect, but it cannot record or control calendar syncing.
     */
    private function columns_ready()
    {
        if (self::$columns_ready !== null) {
            return self::$columns_ready;
        }

        try {
            $this->call->dbforge();
            $forge = lava_instance()->properties['dbforge'];

            self::$columns_ready = $forge->column_exists(
                'integration_accounts',
                'sync_enabled'
            );
        } catch (Throwable $e) {
            self::$columns_ready = false;
        }

        return self::$columns_ready;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * Full, browser-safe connection state for one runner.
     *
     * @param int             $user_id
     * @param bool|null       $provider_ready null = ask the integration config
     * @param TokenVault|null $vault
     * @return array
     */
    public function status_for_user($user_id, $provider_ready = null, ?TokenVault $vault = null)
    {
        $uid   = (int) $user_id;
        $ready = $provider_ready === null ? integration_ready(self::PROVIDER) : (bool) $provider_ready;
        $vault = $vault ?: new TokenVault();

        $blank = [
            'provider'         => self::PROVIDER,
            'status'           => self::STATE_NOT_CONFIGURED,
            'ok'               => false,
            'ready'            => $ready,
            'connected'        => false,
            'sync_enabled'     => false,
            'can_sync'         => false,
            'calendar_id'      => null,
            'scopes'           => array(),
            'connected_at'     => null,
            'last_synced_at'   => null,
            'last_error'       => null,
            'token_expires_at' => null,
            'message'          => '',
        ];

        if (!$ready) {
            $blank['message'] = 'Google Calendar sync is off. Set GCAL_ENABLED=true and the GCAL_* keys in .env to turn it on.';
            return $blank;
        }

        if (!$vault->is_ready()) {
            return array_merge($blank, [
                'status'  => self::STATE_UNAVAILABLE,
                'message' => 'Calendar sync cannot store tokens securely: GCAL_TOKEN_AAD must be 64 hex characters (32 random bytes).',
            ]);
        }

        try {
            $row = $this->row_for_user($uid);
        } catch (Throwable $e) {
            return array_merge($blank, [
                'status'  => self::STATE_UNAVAILABLE,
                'message' => 'Calendar sync is temporarily unavailable. Your training plan is unaffected.',
            ]);
        }

        if (!$row) {
            return array_merge($blank, [
                'status'  => self::STATE_DISCONNECTED,
                'ok'      => true,
                'message' => 'Google Calendar is not connected yet.',
            ]);
        }

        $tokens = $vault->open($row['encrypted_tokens'] ?? null);

        if (!is_array($tokens)) {
            return array_merge($blank, [
                'status'       => self::STATE_NEEDS_RECONNECT,
                'calendar_id'  => $row['calendar_id'] ?? null,
                'connected_at' => $this->iso($row['connected_at'] ?? null),
                'message'      => 'The saved Google authorisation can no longer be read (the encryption key changed). Reconnect to resume syncing.',
            ]);
        }

        $sync_enabled = array_key_exists('sync_enabled', $row)
            ? ((int) $row['sync_enabled'] === 1)
            : true;

        return [
            'provider'         => self::PROVIDER,
            'status'           => self::STATE_CONNECTED,
            'ok'               => true,
            'ready'            => true,
            'connected'        => true,
            'sync_enabled'     => $sync_enabled,
            'can_sync'         => $sync_enabled,
            'calendar_id'      => ($row['calendar_id'] ?? null) ?: 'primary',
            'scopes'           => $this->scope_list($row['scopes'] ?? null),
            'connected_at'     => $this->iso($row['connected_at'] ?? null),
            'last_synced_at'   => $this->iso($row['last_synced_at'] ?? null),
            'last_error'       => ($row['last_error'] ?? null) ?: null,
            'token_expires_at' => $this->iso($this->unix_to_datetime($tokens['expires_at'] ?? null)),
            'message'          => $sync_enabled
                ? 'Google Calendar is connected.'
                : 'Google Calendar is connected but event syncing is paused.',
        ];
    }

    /**
     * Decrypted tokens for one runner.
     *
     * @param int             $user_id
     * @param TokenVault|null $vault
     * @return array|null null when not connected, unreadable, or vault not ready
     */
    public function tokens_for_user($user_id, ?TokenVault $vault = null)
    {
        $vault = $vault ?: new TokenVault();

        if (!$vault->is_ready()) {
            return null;
        }

        try {
            $row = $this->row_for_user((int) $user_id);
        } catch (Throwable $e) {
            return null;
        }

        return $row ? $vault->open($row['encrypted_tokens'] ?? null) : null;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Insert or replace the sealed token set for a runner.
     *
     * @param int             $user_id
     * @param array           $tokens refresh_token, access_token, expires_at, token_type
     * @param string|null     $scopes space-separated scope string from Google
     * @param TokenVault|null $vault
     * @return bool
     */
    public function save_tokens($user_id, array $tokens, $scopes = null, ?TokenVault $vault = null)
    {
        $uid   = (int) $user_id;
        $vault = $vault ?: new TokenVault();

        if ($uid <= 0) {
            return false;
        }

        $blob = $vault->seal($tokens);

        if ($blob === null) {
            return false;
        }

        $now  = gmdate('Y-m-d H:i:s');
        $data = [
            'encrypted_tokens' => $blob,
            'scopes'           => $scopes === null ? null : substr((string) $scopes, 0, 500),
            'connected_at'     => $now,
            'last_error'       => null,
        ];

        if ($this->columns_ready()) {
            $data['updated_at']   = $now;
            $data['sync_enabled'] = 1;
        }

        try {
            $existing = $this->row_for_user($uid);

            if ($existing) {
                $this->db->table($this->table)
                    ->where('id', (int) $existing['id'])
                    ->update($data);

                return true;
            }

            $this->db->table($this->table)->insert(array_merge($data, [
                'user_id'  => $uid,
                'provider' => self::PROVIDER,
            ]));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Remove the grant and the tokens. Used by "Disconnect".
     *
     * @param int $user_id
     * @return bool
     */
    public function forget_user($user_id)
    {
        try {
            $this->db->table($this->table)
                ->where('user_id', (int) $user_id)
                ->where('provider', self::PROVIDER)
                ->delete();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Record a successful sync.
     *
     * @param int         $user_id
     * @param string|null $calendar_id
     * @return bool
     */
    public function mark_synced($user_id, $calendar_id = null)
    {
        if (!$this->columns_ready()) {
            return false;
        }

        $data = [
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
            'last_error'     => null,
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ];

        if (is_string($calendar_id) && $calendar_id !== '') {
            $data['calendar_id'] = substr($calendar_id, 0, 191);
        }

        try {
            $this->db->table($this->table)
                ->where('user_id', (int) $user_id)
                ->where('provider', self::PROVIDER)
                ->update($data);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Record a failed sync. The message is truncated and shown as-is, so
     * callers must pass safe text that never contains a token.
     *
     * @param int    $user_id
     * @param string $message
     * @return bool
     */
    public function mark_error($user_id, $message)
    {
        if (!$this->columns_ready()) {
            return false;
        }

        try {
            $this->db->table($this->table)
                ->where('user_id', (int) $user_id)
                ->where('provider', self::PROVIDER)
                ->update([
                    'last_error' => substr(trim((string) $message), 0, 255),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Pause or resume syncing without revoking access.
     *
     * @param int  $user_id
     * @param bool $enabled
     * @return bool
     */
    public function set_sync_enabled($user_id, $enabled)
    {
        if (!$this->columns_ready()) {
            return false;
        }

        try {
            $this->db->table($this->table)
                ->where('user_id', (int) $user_id)
                ->where('provider', self::PROVIDER)
                ->update([
                    'sync_enabled' => $enabled ? 1 : 0,
                    'updated_at'   => gmdate('Y-m-d H:i:s'),
                ]);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // OAuth flow (spec 5.3)
    // ------------------------------------------------------------------

    /**
     * The consent URL for one runner.
     *
     * Returned rather than redirected so the browser can open it as a normal
     * navigation while the SPA keeps its JWT in memory. When the provider is
     * not configured `auth_url` is null and the UI shows `message` - a missing
     * key is never an error page (NFR-04).
     *
     * @param int                        $user_id
     * @param GoogleCalendarService|null $service
     * @param OAuthState|null            $state_guard
     * @param TokenVault|null            $vault
     * @return array {ready, status, auth_url, expires_in, message}
     */
    public function connect_url($user_id, ?GoogleCalendarService $service = null, ?OAuthState $state_guard = null, ?TokenVault $vault = null)
    {
        $uid     = (int) $user_id;
        $service = $service ?: new GoogleCalendarService();
        $guard   = $state_guard ?: new OAuthState();

        $status = $this->status_for_user($uid, $service->is_ready(), $vault);

        if ($uid <= 0) {
            return array(
                'ready'      => false,
                'status'     => self::STATE_UNAVAILABLE,
                'auth_url'   => null,
                'expires_in' => 0,
                'message'    => 'Sign in again to connect a calendar.',
            );
        }

        if (!$service->is_ready() || !$guard->is_ready()) {
            return array(
                'ready'      => false,
                'status'     => self::STATE_NOT_CONFIGURED,
                'auth_url'   => null,
                'expires_in' => 0,
                'message'    => $status['message'],
            );
        }

        $state = $guard->issue($uid);

        if ($state === null) {
            return array(
                'ready'      => false,
                'status'     => self::STATE_NOT_CONFIGURED,
                'auth_url'   => null,
                'expires_in' => 0,
                'message'    => 'Google Calendar sync is not configured on this server.',
            );
        }

        return array(
            'ready'      => true,
            'status'     => $status['status'],
            'auth_url'   => $service->authorize_url($state),
            'expires_in' => OAuthState::TTL,
            'message'    => 'Open the Google consent screen to finish connecting.',
        );
    }

    /**
     * Finish the flow from the callback query string.
     *
     * The `state` value is the only identity this request has: the callback is a
     * browser navigation from Google and carries no bearer token.
     *
     * @param mixed                      $state_value
     * @param mixed                      $code
     * @param GoogleCalendarService|null $service
     * @param OAuthState|null            $state_guard
     * @param TokenVault|null            $vault
     * @return array {ok, user_id, reason, message}
     */
    public function finish_connection($state_value, $code, ?GoogleCalendarService $service = null, ?OAuthState $state_guard = null, ?TokenVault $vault = null)
    {
        $service = $service ?: new GoogleCalendarService();
        $guard   = $state_guard ?: new OAuthState();

        if (!$service->is_ready()) {
            return $this->outcome(
                false,
                null,
                'not_configured',
                'Google Calendar sync is turned off on this server.'
            );
        }

        $uid = $guard->verify($state_value);

        if ($uid === null) {
            return $this->outcome(
                false,
                null,
                'invalid_state',
                'That connect link has expired or was altered. Start again from RunPlan.'
            );
        }

        $res = $service->exchange_code($code);

        if (!$res['ok']) {
            return $this->outcome(false, $uid, (string) $res['error_code'], (string) $res['error']);
        }

        $saved = $this->save_tokens(
            $uid,
            $res['tokens'],
            $res['tokens']['scope'] ?? null,
            $vault
        );

        if (!$saved) {
            return $this->outcome(
                false,
                $uid,
                'storage_failed',
                'Google approved the connection, but RunPlan could not save it. Check the server logs.'
            );
        }

        return $this->outcome(true, $uid, 'ok', 'Google Calendar is connected.');
    }

    /**
     * Revoke at Google, then forget the grant locally.
     *
     * Local removal happens even when Google cannot be reached: a runner who
     * pressed Disconnect must not stay connected because a provider was down.
     *
     * @param int                        $user_id
     * @param GoogleCalendarService|null $service
     * @param TokenVault|null            $vault
     * @return array {ok, revoked, message}
     */
    public function disconnect($user_id, ?GoogleCalendarService $service = null, ?TokenVault $vault = null)
    {
        $uid     = (int) $user_id;
        $service = $service ?: new GoogleCalendarService();
        $tokens  = $this->tokens_for_user($uid, $vault);
        $revoked = true;

        if ($service->is_ready() && is_array($tokens)) {
            $token = (string) ($tokens['refresh_token'] ?? $tokens['access_token'] ?? '');
            $revoked = $token === '' ? true : $service->revoke($token);
        }

        $forgotten = $this->forget_user($uid);

        return array(
            'ok'      => $forgotten,
            'revoked' => $revoked,
            'message' => $forgotten
                ? ($revoked
                    ? 'Google Calendar is disconnected.'
                    : 'Disconnected here. Google could not be reached to revoke the grant; remove RunPlan at myaccount.google.com if you want it gone there too.')
                : 'Could not disconnect. Try again.',
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param bool     $ok
     * @param int|null $user_id
     * @param string   $reason
     * @param string   $message
     * @return array
     */
    private function outcome($ok, $user_id, $reason, $message)
    {
        return array(
            'ok'      => (bool) $ok,
            'user_id' => $user_id === null ? null : (int) $user_id,
            'reason'  => (string) $reason,
            'message' => (string) $message,
        );
    }

    /**
     * @param int $user_id
     * @return array|null
     */
    private function row_for_user($user_id)
    {
        $row = $this->db->table($this->table)
            ->where('user_id', (int) $user_id)
            ->where('provider', self::PROVIDER)
            ->get();

        return $row ?: null;
    }

    /**
     * @param string|null $scopes space-separated string as Google returns it
     * @return array
     */
    private function scope_list($scopes)
    {
        $parts = preg_split('/\s+/', trim((string) $scopes), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : array();
    }

    /**
     * @param int|string|null $value unix timestamp
     * @return string|null
     */
    private function unix_to_datetime($value)
    {
        $unix = (int) $value;

        return $unix > 0 ? gmdate('Y-m-d H:i:s', $unix) : null;
    }

    /**
     * @param string|null $value MySQL DATETIME in UTC
     * @return string|null ISO 8601, or null
     */
    private function iso($value)
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $unix = strtotime($text . ' UTC');

        return $unix === false ? null : gmdate('c', $unix);
    }
}