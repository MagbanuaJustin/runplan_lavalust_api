<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| EXTERNAL INTEGRATIONS
| -------------------------------------------------------------------
| One place for every provider RunPlan talks to. Values come from the
| environment (.env in development, real env vars in production) so no
| credential is ever committed. See .env.example.
|
| Two rules this file exists to enforce:
|
|   1. The core planner must keep working when every provider is off.
|      Anything reading these settings has to treat "not configured" as
|      a normal state, not an error (spec 7.3, NFR-04).
|   2. Secrets stay server-side. Nothing in here is ever serialised into
|      an API response - use integration_public_config() for the browser.
|
| 'enabled' is the operator's kill switch and is independent of
| 'configured': you can have a key present but still have the feature
| turned off. integration_ready() requires both.
*/

$config['integrations'] = array(

    /*
    | -----------------------------------------------------------------
    | Google Gemini - plan drafting + training feedback
    | -----------------------------------------------------------------
    | Requests are capped so development use stays within a predictable
    | limit. The API key must remain in the backend environment.
    */
    'gemini' => array(
        'enabled'          => env('GEMINI_ENABLED', 'false') === 'true',
        'api_key'          => env('GEMINI_API_KEY'),
        'model'            => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        'base_uri' => env(
           'GEMINI_BASE_URI',
           'https://generativelanguage.googleapis.com/v1beta'
         ),
        'timeout'          => (int) env('GEMINI_TIMEOUT', 25),
        'max_tokens'       => (int) env('GEMINI_MAX_TOKENS', 3000),
        'monthly_call_cap' => (int) env('GEMINI_MONTHLY_CALL_CAP', 500),
        // Drafted plans are always re-validated by PlanModel::build() rules.
        'plan_schema_version' => '1',
    ),

    /*
    | -----------------------------------------------------------------
    | OpenWeatherMap - advisory weather for a runner's location (spec 5.2)
    | -----------------------------------------------------------------
    | 'onecall' has a 8-day forward horizon and needs a paid subscription;
    | 'forecast16' is the free 5-day/3-hour endpoint. Whichever is used, the
    | horizon must be published so the UI can say "no forecast that far out"
    | instead of inventing one.
    */
    'openweathermap' => array(
        'enabled'     => env('OWM_ENABLED', 'false') === 'true',
        'api_key'     => env('OWM_API_KEY'),
        'base_uri'    => env('OWM_BASE_URI', 'https://api.openweathermap.org'),
        'product'     => env('OWM_PRODUCT', 'forecast16'),   // forecast16 | onecall
        'units'       => 'metric',
        'forecast_days' => (int) env('OWM_FORECAST_DAYS', 5),
        'timeout'     => (int) env('OWM_TIMEOUT', 10),
        'cache_minutes' => (int) env('OWM_CACHE_MINUTES', 60),
    ),

    /*
    | -----------------------------------------------------------------
    | Google Calendar - opt-in event sync (spec 5.3)
    | -----------------------------------------------------------------
    | Separate consent from Google sign-in, always. Scopes are the narrowest
    | that can create and update events.
    */
    'google_calendar' => array(
        'enabled'       => env('GCAL_ENABLED', 'false') === 'true',
        'client_id'     => env('GCAL_CLIENT_ID'),
        'client_secret' => env('GCAL_CLIENT_SECRET'),
        'redirect_uri'  => env('GCAL_REDIRECT_URI'),
        // event-scoped write access only: no calendar list, no read of other events
        'scopes'        => array('https://www.googleapis.com/auth/calendar.events'),
        'token_aad'     => env('GCAL_TOKEN_AAD'),   // 32-byte hex key used to encrypt refresh tokens at rest
        'timeout'       => (int) env('GCAL_TIMEOUT', 15),
        'event_length_minutes' => (int) env('GCAL_EVENT_MINUTES', 60),
        // Where the browser lands after Google's consent screen. The SPA origin,
        // not the API: the callback stores the tokens, then redirects here with
        // ?calendar=connected|denied|...
        'return_url'    => env('GCAL_RETURN_URL', 'http://localhost:5173/account'),
        'timezone' => env('GCAL_TIMEZONE', 'Asia/Manila'),
    ),

    /*
    | -----------------------------------------------------------------
    | Push reminders (spec 5.4)
    | -----------------------------------------------------------------
    | driver=vapid  : standard Web Push, sent straight from PHP. No vendor
    |                 account needed, works over http://localhost in dev.
    | driver=fcm    : Firebase Web Push, as the spec names. Needs a project.
    | The sender is chosen by config so both share one subscription table and
    | one notification_jobs queue.
    */
    'push' => array(
        'enabled'   => env('PUSH_ENABLED', 'false') === 'true',
        'driver'    => env('PUSH_DRIVER', 'vapid'),            // vapid | fcm
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject'     => env('VAPID_SUBJECT', 'mailto:runplan@localhost'),
        'fcm_project' => env('FCM_PROJECT_ID'),
        'fcm_credential_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
        'timeout'     => (int) env('PUSH_TIMEOUT', 10),
    ),

    /*
    | -----------------------------------------------------------------
    | Google Identity Services - Sign in with Google (spec 5.6)
    | -----------------------------------------------------------------
    | We verify the ID token server-side: issuer, audience, expiry, and the
    | JWS signature against Google's published JWKS. A browser-supplied email
    | is never treated as proof of identity, and accounts are never merged
    | because their email strings match.
    */
    'google_identity' => array(
        'enabled'    => env('GOOGLE_LOGIN_ENABLED', 'false') === 'true',
        'client_id'  => env('GOOGLE_CLIENT_ID'),
        'jwks_uri'   => env('GOOGLE_JWKS_URI', 'https://www.googleapis.com/oauth2/v3/certs'),
        'issuers'    => array('https://accounts.google.com', 'accounts.google.com'),
        'timeout'    => (int) env('GOOGLE_TIMEOUT', 10),
        'jwks_cache_seconds' => 3600,
        'leeway_seconds'     => 120,
    ),

    /*
    | -----------------------------------------------------------------
    | Google reCAPTCHA - anti-bot on public account forms (spec 5.7)
    | -----------------------------------------------------------------
    | v3        : invisible, score-based, site key + secret (default here).
    | v2        : the "I'm not a robot" checkbox, same key pair.
    | enterprise: keyless site key + project id, score thresholds.
    | Chosen by config because the verify payload and the widget differ but
    | the rule does not: an unverifiable challenge is NEVER a pass.
    */
    'recaptcha' => array(
        'enabled'     => env('RECAPTCHA_ENABLED', 'false') === 'true',
        'version'     => env('RECAPTCHA_VERSION', 'v3'),        // v3 | v2 | enterprise
        'site_key'    => env('RECAPTCHA_SITE_KEY'),
        'secret'      => env('RECAPTCHA_SECRET'),
        'project_id'  => env('RECAPTCHA_PROJECT_ID'),
        'verify_uri'  => env('RECAPTCHA_VERIFY_URI', 'https://www.google.com/recaptcha/api/siteverify'),
        'min_score'   => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
        'timeout'     => (int) env('RECAPTCHA_TIMEOUT', 10),
        // Actions we protect. Register + recovery are required by the spec;
        // login stays risk-triggered so a human is not punished on every attempt.
        'actions'     => array(
            'register'       => 'register',
            'forgot_password' => 'forgot_password',
            'login'          => 'login',
        ),
        'require_on'  => array('register', 'forgot_password'),
    ),
);