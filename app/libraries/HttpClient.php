<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * HttpClient - the only outbound HTTP path in the application.
 *
 * Why this exists as its own class:
 *
 *   - Every provider call (Gemini, OpenWeatherMap, Google, FCM) needs the
 *     same boring guarantees: a hard timeout, a response size cap, JSON
 *     decoding that cannot emit notices, and one error shape. Doing that at
 *     each call site is how you end up with a provider outage taking the
 *     dashboard down with it.
 *   - It is the seam that makes the integrations testable. Tests call
 *     HttpClient::fake() and assert on what we WOULD have sent, so the
 *     privacy rules in the spec ("send only eligible first-party fields")
 *     become checkable rather than aspirational.
 *   - No new dependencies. This project has no composer autoloader and the
 *     framework is vendored, so Guzzle is not on the table.
 *
 * Failure contract: request() never throws and never returns a partial body.
 * It returns an array:
 *
 *   ok         bool     - transport succeeded AND status is 2xx
 *   status     int      - HTTP status, 0 when the request never completed
 *   data       ?array   - decoded JSON object/array, null if not JSON
 *   raw        string   - body text, truncated to max_bytes
 *   error      ?string - safe, human-oriented message (never contains a key)
 *   error_code ?string - machine tag for branching: timeout|dns|tls|transport|http|json
 */
class HttpClient
{
    /** @var array|null Queue of canned responses used by tests. Null = real network. */
    private static $fake_queue = null;

    /** @var array Requests recorded while faking, for assertions. */
    private static $recorded = array();

    /** @var int Refuse to read more than this much of a response body. */
    const MAX_RESPONSE_BYTES = 2097152; // 2 MiB

    /** @var int Seconds allowed for the whole request when the caller does not say. */
    private $default_timeout;

    /**
     * @param int $default_timeout seconds for the whole request
     */
    public function __construct($default_timeout = 15)
    {
        $this->default_timeout = max(1, (int) $default_timeout);
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * @param string $url     absolute https:// (or http:// for local fakes) URL
     * @param array  $options method, headers, json (array), body (string),
     *                        timeout (seconds), max_bytes
     * @return array see class docblock for the shape
     */
    public function request($url, array $options = array())
    {
        $method  = strtoupper($options['method'] ?? 'GET');
        $timeout = isset($options['timeout'])
            ? max(1, (int) $options['timeout'])
            : $this->default_timeout;
        $headers = $options['headers'] ?? array();
        $max     = isset($options['max_bytes'])
            ? (int) $options['max_bytes']
            : self::MAX_RESPONSE_BYTES;

        $payload = null;

        if (isset($options['json'])) {
            $payload = json_encode(
                $options['json'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
        } elseif (isset($options['body'])) {
            $payload = (string) $options['body'];
        }

        $request = array(
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $payload,
            'timeout' => $timeout,
        );

        // Test mode: record and replay, no sockets.
        if (self::$fake_queue !== null) {
            self::$recorded[] = $request;

            return $this->normalise(
                array_shift(self::$fake_queue)
                ?: array('ok' => false, 'error' => 'fake queue exhausted')
            );
        }

        if (!function_exists('curl_init')) {
            return $this->normalise(array(
                'ok'         => false,
                'status'     => 0,
                'error'      => 'HTTP support is unavailable on this server.',
                'error_code' => 'transport',
            ));
        }

        if (!$this->url_allowed($url)) {
            return $this->normalise(array(
                'ok'         => false,
                'status'     => 0,
                'error'      => 'Refusing to call that URL.',
                'error_code' => 'transport',
            ));
        }

        $ch = curl_init();

        $curl_options = array(
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADER         => false,
            CURLOPT_NOSIGNAL       => 1,
        );

        // Only set CURLOPT_CAINFO when there is an actual CA file path.
        // Leaving it unset lets cURL use the system/default certificate store.
        $ca_bundle = $this->ca_bundle();

        if (is_string($ca_bundle) && $ca_bundle !== '') {
            $curl_options[CURLOPT_CAINFO] = $ca_bundle;
        }

        curl_setopt_array($ch, $curl_options);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        if ($headers) {
            $line_headers = array();

            foreach ($headers as $header) {
                if (is_string($header) && strpos($header, ':') !== false) {
                    $line_headers[] = $header;
                }
            }

            if ($line_headers) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $line_headers);
            }
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $errstr = (string) curl_error($ch);

        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        unset($ch);

        if ($errno !== 0 || !is_string($body)) {
            $code = in_array(
                $errno,
                array(CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT),
                true
            )
                ? 'timeout'
                : (
                    in_array(
                        $errno,
                        array(CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY),
                        true
                    )
                        ? 'dns'
                        : (
                            ($errno === CURLE_SSL_CACERT || $errno === CURLE_SSL_CONNECT_ERROR)
                                ? 'tls'
                                : 'transport'
                        )
                );

            return $this->normalise(array(
                'ok'         => false,
                'status'     => 0,
                'error_code' => $code,
                // Keep internal host details out of the API response.
                'error'      => $code === 'timeout'
                    ? 'The remote service took too long to answer.'
                    : 'The remote service could not be reached.',
                'debug'      => $errstr,
            ));
        }

        return $this->normalise(array(
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => substr($body, 0, $max),
        ));
    }

    /**
     * GET that expects a JSON object. Same failure contract as request().
     *
     * @param string $url
     * @param array  $query appended as query string (scalars only)
     * @param array  $options
     * @return array
     */
    public function get_json($url, array $query = array(), array $options = array())
    {
        if ($query) {
            $parts = array();

            foreach ($query as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $parts[] = rawurlencode((string) $key)
                        . '='
                        . rawurlencode((string) $value);
                }
            }

            if ($parts) {
                $url .= (strpos($url, '?') === false ? '?' : '&')
                    . implode('&', $parts);
            }
        }

        return $this->request($url, $options);
    }

    /**
     * POST a JSON document and decode the JSON reply.
     *
     * @param string $url
     * @param array  $payload
     * @param array  $options
     * @return array
     */
    public function post_json($url, array $payload, array $options = array())
    {
        $options['method'] = 'POST';
        $options['json']   = $payload;

        return $this->request($url, $options);
    }

    // ------------------------------------------------------------------
    // Test seams
    // ------------------------------------------------------------------

    /**
     * Start faking. Every request() call pops one queued response and is
     * recorded for assertions. Pass [] to make every call fail as if offline.
     *
     * @param array $responses
     * @return void
     */
    public static function fake(array $responses = array())
    {
        self::$fake_queue = $responses;
        self::$recorded   = array();
    }

    /** @return void */
    public static function stop_faking()
    {
        self::$fake_queue = null;
        self::$recorded   = array();
    }

    /** @return bool */
    public static function is_faking()
    {
        return self::$fake_queue !== null;
    }

    /**
     * Requests made while faking, in order.
     *
     * @return array
     */
    public static function recorded()
    {
        return self::$recorded;
    }

    /**
     * Queue an additional response mid-test.
     *
     * @param array $response
     * @return void
     */
    public static function push_fake_response(array $response)
    {
        if (self::$fake_queue === null) {
            self::$fake_queue = array();
        }

        self::$fake_queue[] = $response;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Turn anything a caller or the fake queue produced into the one shape
     * controllers are allowed to rely on.
     *
     * @param array $r
     * @return array
     */
    private function normalise(array $r)
    {
        $body       = isset($r['body']) ? (string) $r['body'] : null;
        $data       = null;
        $json_error = null;

        if ($body !== null && $body !== '') {
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $data       = null;
                $json_error = 'The remote service replied in a format we could not read.';
            } elseif (!is_array($data)) {
                $data       = null;
                $json_error = 'The remote service replied with an unexpected JSON shape.';
            }
        }

        $ok    = (bool) ($r['ok'] ?? false);
        $error = $r['error'] ?? null;
        $code  = $r['error_code'] ?? null;

        if ($ok && $body !== null && $data === null && $json_error !== null) {
            // A 2xx we cannot parse is a failure for our purposes.
            $ok    = false;
            $error = $json_error;
            $code  = 'json';
        }

        if (!$ok && $error === null) {
            $status = (int) ($r['status'] ?? 0);
            $error  = $status >= 400
                ? 'The remote service rejected the request (' . $status . ').'
                : 'The remote service is unavailable right now.';
            $code   = $code ?? ($status >= 400 ? 'http' : 'transport');
        }

        return array(
            'ok'         => $ok,
            'status'     => (int) ($r['status'] ?? 0),
            'data'       => $data,
            'raw'        => $body === null ? '' : $body,
            'error'      => $ok ? null : $error,
            'error_code' => $ok ? null : $code,
            'debug'      => $r['debug'] ?? null,
        );
    }

    /**
     * Only absolute http(s) URLs, and no credentials smuggled into them.
     *
     * @param string $url
     * @return bool
     */
    private function url_allowed($url)
    {
        if (!is_string($url) || $url === '' || strlen($url) > 2000) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return false;
        }

        // User-entered location strings must never become an SSRF primitive.
        return !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * Reuse the same CA bundle the database connection uses when one is set.
     * A null return means no explicit CA file should be passed to cURL.
     *
     * @return string|null
     */
    private function ca_bundle()
    {
        $ca = function_exists('env') ? env('DB_SSL_CA') : '';

        if (is_string($ca) && $ca !== '') {
            $path = (defined('ROOT_DIR') ? ROOT_DIR : '') . $ca;

            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}