<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Integration helper
 *
 * Small read-side API over app/config/integrations.php so that controllers,
 * models and library classes all ask the same question the same way.
 *
 * Deliberately conservative: a missing provider block is "not ready", never
 * a fatal error, because the core planner has to keep working with nothing
 * configured at all (spec 7.3, NFR-04).
 */

if (!function_exists('integration_config')) {
    /**
     * Whole config block, or one provider's block.
     *
     * @param string|null $provider
     * @return array
     */
    function integration_config($provider = null)
    {
        static $all = null;

        if ($all === null) {
            $loaded = config_item('integrations');
            $all = is_array($loaded) ? $loaded : array();
        }

        if ($provider === null) {
            return $all;
        }

        $block = $all[$provider] ?? null;
        return is_array($block) ? $block : array();
    }
}

if (!function_exists('integration_value')) {
    /**
     * Read one setting with a fallback, for example:
     * integration_value('gemini', 'timeout', 25)
     *
     * @param string $provider
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    function integration_value($provider, $key, $default = null)
    {
        $block = integration_config($provider);
        return array_key_exists($key, $block) ? $block[$key] : $default;
    }
}

if (!function_exists('integration_credential_present')) {
    /**
     * Does this provider have the secrets it needs? This ignores the kill
     * switch, so an operator can tell "configured but disabled" apart from
     * "not configured at all" in the status endpoint.
     *
     * @param string $provider
     * @return bool
     */
    function integration_credential_present($provider)
    {
        $block = integration_config($provider);
        if (!$block) {
            return false;
        }

        $required = array(
            'gemini'          => array('api_key'),
            'openweathermap'  => array('api_key'),
            'google_calendar' => array('client_id', 'client_secret', 'redirect_uri', 'token_aad'),
            'google_identity' => array('client_id'),
            'recaptcha'       => array('site_key', 'secret'),
            // Push needs a key pair for one driver and a project for the other.
            'push'            => array(),
        );

        if ($provider === 'push') {
            if (strtolower((string) ($block['driver'] ?? 'vapid')) === 'fcm') {
                $needed = array('fcm_project');
            } else {
                $needed = array('public_key', 'private_key');
            }
        } else {
            $needed = $required[$provider] ?? array();
        }

        foreach ($needed as $key) {
            $value = $block[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                return false;
            }
        }

        // reCAPTCHA enterprise authenticates with a project id, not a shared secret.
        if ($provider === 'recaptcha'
            && strtolower((string) ($block['version'] ?? '')) === 'enterprise'
            && trim((string) ($block['project_id'] ?? '')) === '') {
            return false;
        }

        return true;
    }
}

if (!function_exists('integration_ready')) {
    /**
     * Enabled AND configured. Every feature gate in the app checks this.
     *
     * @param string $provider
     * @return bool
     */
    function integration_ready($provider)
    {
        $block = integration_config($provider);
        if (!$block) {
            return false;
        }

        $enabled = $block['enabled'] ?? false;
        $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $enabled === true && integration_credential_present($provider);
    }
}

if (!function_exists('integration_public_config')) {
    /**
     * The only shape of this config that may reach a browser: non-secret
     * booleans and public keys, used by GET /api/integrations.
     *
     * Never add a secret, private key, API key or credential JSON here.
     *
     * @return array
     */
    function integration_public_config()
    {
        return array(
            'gemini' => array(
                'ready'      => integration_ready('gemini'),
                'enabled'    => (bool) filter_var(
                    integration_value('gemini', 'enabled', false),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
                'configured' => integration_credential_present('gemini'),
                'model'      => integration_value('gemini', 'model'),
            ),
            'weather' => array(
                'ready'         => integration_ready('openweathermap'),
                'configured'    => integration_credential_present('openweathermap'),
                'product'       => integration_value('openweathermap', 'product'),
                'forecast_days' => (int) integration_value('openweathermap', 'forecast_days', 0),
            ),
            'google_calendar' => array(
                'ready'      => integration_ready('google_calendar'),
                'configured' => integration_credential_present('google_calendar'),
                'scopes'     => integration_value('google_calendar', 'scopes', array()),
            ),
            'push' => array(
                'ready'      => integration_ready('push'),
                'configured' => integration_credential_present('push'),
                'driver'     => integration_value('push', 'driver'),
                'public_key' => integration_value('push', 'public_key'),
            ),
            'google_identity' => array(
                'ready'      => integration_ready('google_identity'),
                'configured' => integration_credential_present('google_identity'),
                'client_id'  => integration_value('google_identity', 'client_id'),
            ),
            'recaptcha' => array(
                'ready'       => integration_ready('recaptcha'),
                'configured'  => integration_credential_present('recaptcha'),
                'version'     => integration_value('recaptcha', 'version'),
                'site_key'    => integration_value('recaptcha', 'site_key'),
                'required_on' => integration_value('recaptcha', 'require_on', array()),
            ),
            'strava' => array(
                'ready'      => false,
                'configured' => false,
                'reason'     => 'Disabled pending review of the current Strava API Agreement and Policy.',
            ),
        );
    }
}