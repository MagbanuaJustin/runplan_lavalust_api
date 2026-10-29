<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * TokenVault - encrypts OAuth credentials before they reach the database.
 *
 * Why this exists at all:
 *
 *   A Google refresh token is a long-lived password to a runner's calendar.
 *   Storing it as plain text means one database dump, one SQL injection, or one
 *   copied backup hands over a runner's calendar for as long as Google keeps the
 *   grant. `integration_accounts.encrypted_tokens` therefore only ever holds a
 *   sealed envelope, and the key (GCAL_TOKEN_AAD) lives in .env, never in the
 *   database and never in the repository.
 *
 * What it is:
 *
 *   AES-256-GCM via openssl, with a fresh 12-byte IV per seal and the 16-byte
 *   authentication tag stored next to the ciphertext. GCM is authenticated
 *   encryption, so a modified or truncated value fails to open instead of
 *   returning garbage tokens. Each envelope also carries a version byte so the
 *   format can change later without guessing what is in the column.
 *
 * Contract, deliberately boring:
 *
 *   - seal() returns a string, or null when no usable key is configured.
 *   - open() returns an array, or null for ANY failure (no key, bad base64,
 *     tampered ciphertext, wrong key, non-JSON payload).
 *   - Neither method ever throws. A calendar integration that cannot read its
 *     tokens must degrade to "reconnect required", not fatal-error the app
 *     (NFR-04).
 */
class TokenVault {

    /** @var string OpenSSL cipher - authenticated, 256-bit key, 96-bit IV. */
    const CIPHER = 'aes-256-gcm';

    /** @var int Envelope format version, so old blobs stay readable if this changes. */
    const VERSION = 1;

    /**
     * @var string Extra authenticated data. Not secret - it only binds an
     *             envelope to this purpose, so a sealed value stolen from
     *             another column cannot be replayed here.
     */
    const AAD = 'runplan.integration_accounts.v1';

    /** @var int Key length in bytes (AES-256). Hex keys are twice this. */
    const KEY_BYTES = 32;

    /** @var string|null Raw 32-byte key, or null when the vault is not usable. */
    private $key;

    /**
     * @param string|null $key_hex 64 hex characters. Omit to read GCAL_TOKEN_AAD.
     *                             Tests pass it explicitly so they never depend on
     *                             a real .env.
     */
    public function __construct($key_hex = null)
    {
        $hex = $key_hex === null
            ? (string) integration_value('google_calendar', 'token_aad', '')
            : (string) $key_hex;
        $hex = trim($hex);

        $this->key = (strlen($hex) === self::KEY_BYTES * 2 && ctype_xdigit($hex) && self::crypto_available())
            ? hex2bin($hex)
            : null;
    }

    /**
     * Is the openssl extension able to do authenticated encryption here?
     *
     * @return bool
     */
    public static function crypto_available()
    {
        return function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && defined('OPENSSL_RAW_DATA')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    /**
     * @return bool true when a valid key is loaded and crypto is available
     */
    public function is_ready()
    {
        return $this->key !== null;
    }

    /**
     * Encrypt a token payload.
     *
     * @param array $payload e.g. ['refresh_token' => '...', 'expires_at' => 123]
     * @return string|null base64 envelope, or null when the vault is not ready
     */
    public function seal(array $payload)
    {
        if ($this->key === null) {
            return null;
        }

        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($plain === false) {
            return null;
        }

        $iv  = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if ($cipher === false || strlen($tag) !== 16) {
            return null;
        }

        $envelope = json_encode([
            'v'   => self::VERSION,
            'iv'  => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct'  => base64_encode($cipher),
        ]);

        return $envelope === false ? null : base64_encode($envelope);
    }

    /**
     * Decrypt a sealed payload.
     *
     * @param string|null $blob value previously returned by seal()
     * @return array|null the original payload, or null on any failure
     */
    public function open($blob)
    {
        if ($this->key === null || !is_string($blob) || $blob === '') {
            return null;
        }

        $json = base64_decode($blob, true);
        if ($json === false) {
            return null;
        }

        $envelope = json_decode($json, true);
        if (!is_array($envelope)
            || (int) ($envelope['v'] ?? 0) !== self::VERSION
            || !isset($envelope['iv'], $envelope['tag'], $envelope['ct'])) {
            return null;
        }

        $iv  = base64_decode((string) $envelope['iv'], true);
        $tag = base64_decode((string) $envelope['tag'], true);
        $ct  = base64_decode((string) $envelope['ct'], true);

        if ($iv === false || $tag === false || $ct === false || strlen($iv) !== 12 || strlen($tag) !== 16) {
            return null;
        }

        $plain = openssl_decrypt($ct, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if ($plain === false) {
            return null;
        }

        $data = json_decode($plain, true);
        return is_array($data) ? $data : null;
    }
}