<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/HttpClient.php';

/**
 * GoogleCalendarService - the three OAuth calls Google Calendar needs.
 *
 * A runner's calendar is reached with an OAuth 2.0 authorisation-code flow:
 *
 *   1. authorize_url()    - send the browser to Google's consent screen.
 *   2. exchange_code()    - turn the one-time ?code= into access + refresh tokens.
 *   3. refresh()          - swap the refresh token for a new access token later.
 *                           (access tokens last an hour; refresh tokens do not
 *                           expire while the grant stands)
 *   4. revoke()           - give the grant back when the runner disconnects.
 *
 * Three rules this class follows, because integrations are optional:
 *
 *   - is_ready() is false when the feature is off or a key is missing. Callers
 *     then show a sentence; nothing throws (NFR-04).
 *   - It never returns Google's raw error text to a client. `error` is our own
 *     wording and `error_code` is a small enum, so a provider message cannot
 *     echo a fragment of a credential into an API response.
 *   - All network traffic goes through HttpClient, which means tests can fake
 *     it and the privacy test can assert exactly what was sent.
 *
 * `access_type=offline` plus `prompt=consent` are deliberate: Google only issues
 * a refresh token on the first consent unless consent is forced, and a reconnect
 * that silently loses the refresh token would leave a runner with a connection
 * that dies an hour later.
 */
class GoogleCalendarService {

    const AUTH_URI   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URI  = 'https://oauth2.googleapis.com/token';
    const REVOKE_URI = 'https://oauth2.googleapis.com/revoke';

    /** @var array provider config block */
    private $config;

    /** @var HttpClient */
    private $http;

    /**
     * @param array|null      $config overrides the integrations.php block (used by tests)
     * @param HttpClient|null $http
     */
    public function __construct(?array $config = null, ?HttpClient $http = null)
    {
        $this->config = $config === null ? integration_config('google_calendar') : $config;

        $timeout    = (int) ($this->config['timeout'] ?? 15);
        $this->http = $http ?: new HttpClient($timeout > 0 ? $timeout : 15);
    }

    /**
     * Enabled AND configured: a consent screen is only offered when we can
     * actually finish the exchange.
     *
     * @return bool
     */
    public function is_ready()
    {
        $enabled = filter_var($this->config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled !== true) {
            return false;
        }

        foreach (array('client_id', 'client_secret', 'redirect_uri') as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array narrowest scopes that can create and update the runner's events
     */
    public function scopes()
    {
        $scopes = $this->config['scopes'] ?? array();
        if (!is_array($scopes) || !$scopes) {
            $scopes = array('https://www.googleapis.com/auth/calendar.events');
        }

        return array_values(array_filter(array_map('strval', $scopes)));
    }

    /**
     * @return int default event length in minutes
     */
    public function event_minutes()
    {
        $minutes = (int) ($this->config['event_length_minutes'] ?? 60);
        return $minutes > 0 ? $minutes : 60;
    }

    /**
     * The consent-screen URL the browser is sent to.
     *
     * @param string $state value from OAuthState::issue()
     * @return string
     */
    public function authorize_url($state)
    {
        $params = array(
            'client_id'             => (string) $this->config['client_id'],
            'redirect_uri'          => (string) $this->config['redirect_uri'],
            'response_type'         => 'code',
            'scope'                 => implode(' ', $this->scopes()),
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'include_granted_scopes' => 'true',
            'state'                 => (string) $state,
        );

        return self::AUTH_URI . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Turn the callback's one-time code into tokens.
     *
     * @param string $code
     * @return array {ok, tokens?, error?, error_code?}
     */
    public function exchange_code($code)
    {
        $code = trim((string) $code);
        if ($code === '') {
            return $this->failure('denied', 'The Google consent screen was closed before access was granted.');
        }

        return $this->token_request(array(
            'code'          => $code,
            'client_id'     => (string) $this->config['client_id'],
            'client_secret' => (string) $this->config['client_secret'],
            'redirect_uri'  => (string) $this->config['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ));
    }

    /**
     * Get a fresh access token from a stored refresh token.
     *
     * @param string $refresh_token
     * @param array  $previous existing token set, so a reply without a new
     *                         refresh_token keeps the old one
     * @return array {ok, tokens?, error?, error_code?}
     */
    public function refresh($refresh_token, array $previous = array())
    {
        $token = trim((string) $refresh_token);
        if ($token === '') {
            return $this->failure('revoked', 'This Google connection no longer has a refresh token.');
        }

        return $this->token_request(array(
            'refresh_token' => $token,
            'client_id'     => (string) $this->config['client_id'],
            'client_secret' => (string) $this->config['client_secret'],
            'grant_type'    => 'refresh_token',
        ), $previous);
    }

    /**
     * Give the grant back. Best effort: a disconnect must succeed locally even
     * when Google is unreachable.
     *
     * @param string $token refresh token ideally, access token also accepted
     * @return bool
     */
    public function revoke($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return true;
        }

        $res = $this->http->request(
            self::REVOKE_URI . '?token=' . rawurlencode($token),
            array(
                'method'  => 'POST',
                'headers' => array('Content-Type: application/x-www-form-urlencoded'),
                'body'    => '',
                'timeout' => (int) ($this->config['timeout'] ?? 15),
            )
        );

        return (bool) $res['ok'];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param array $form
     * @param array $previous
     * @return array
     */
    private function token_request(array $form, array $previous = array())
    {
        $res = $this->http->request(self::TOKEN_URI, array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ),
            'body'    => http_build_query($form, '', '&'),
            'timeout' => (int) ($this->config['timeout'] ?? 15),
        ));

        $data = is_array($res['data'] ?? null) ? $res['data'] : array();

        if (!$res['ok']) {
            return $this->failure(
                $this->map_error($res, $data),
                $this->message_for($this->map_error($res, $data))
            );
        }

        if (empty($data['access_token'])) {
            return $this->failure('unavailable', 'Google did not return an access token.');
        }

        return array('ok' => true, 'tokens' => $this->normalise($data, $previous));
    }

    /**
     * @param array $res   HttpClient result
     * @param array $data  decoded body
     * @return string revoked|unauthorized|rate_limited|unavailable
     */
    private function map_error(array $res, array $data)
    {
        $google_error = strtolower((string) ($data['error'] ?? ''));

        if ($google_error === 'invalid_grant' || $google_error === 'invalid_request') {
            return 'revoked';
        }
        if ($google_error === 'invalid_client' || $google_error === 'unauthorized_client') {
            return 'unauthorized';
        }
        if ((int) $res['status'] === 401 || (int) $res['status'] === 403) {
            return 'unauthorized';
        }
        if ((int) $res['status'] === 429) {
            return 'rate_limited';
        }

        return 'unavailable';
    }

    /**
     * @param string $code
     * @return string safe, human wording - never Google's raw text
     */
    private function message_for($code)
    {
        switch ($code) {
            case 'revoked':
                return 'Google says this authorisation is no longer valid. Reconnect Google Calendar.';
            case 'unauthorized':
                return 'Google rejected the OAuth client credentials. Check GCAL_CLIENT_ID and GCAL_CLIENT_SECRET.';
            case 'rate_limited':
                return 'Google is rate limiting us. Try again in a minute.';
            default:
                return 'Google Calendar could not be reached. Try again shortly.';
        }
    }

    /**
     * @param string $code
     * @param string $message
     * @return array
     */
    private function failure($code, $message)
    {
        return array('ok' => false, 'error_code' => $code, 'error' => $message);
    }

    /**
     * Flatten Google's token reply into the small set of fields we store.
     *
     * @param array $data
     * @param array $previous
     * @return array
     */
    private function normalise(array $data, array $previous = array())
    {
        $expires_in = (int) ($data['expires_in'] ?? 0);

        $tokens = array(
            'access_token' => (string) $data['access_token'],
            'expires_at'   => time() + max(0, $expires_in),
            'token_type'   => (string) ($data['token_type'] ?? 'Bearer'),
        );

        // A refresh reply usually omits refresh_token: keep the stored one.
        $refresh = (string) ($data['refresh_token'] ?? ($previous['refresh_token'] ?? ''));
        if ($refresh !== '') {
            $tokens['refresh_token'] = $refresh;
        }

        $scope = (string) ($data['scope'] ?? ($previous['scope'] ?? ''));
        if ($scope !== '') {
            $tokens['scope'] = $scope;
        }

        return $tokens;
    }
}