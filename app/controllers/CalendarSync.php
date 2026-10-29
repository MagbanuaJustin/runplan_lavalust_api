<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: CalendarSync
 * 
 * Automatically generated via CLI.
 */
class CalendarSync extends Controller {

    /**
     * Hand the browser a consent URL.
     *
     * 200 with `auth_url: null` when the integration is off, so the UI can show
     * a sentence instead of treating a missing key as a failure (NFR-04).
     */
    public function connect()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('CalendarLinkModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $this->api->rate_limit('calendar_connect_' . $user_id, 10, 60);

        header('Cache-Control: no-store');
        $this->api->respond(array('data' => $this->CalendarLinkModel->connect_url($user_id)));
    }

    /**
     * Google's redirect target.
     *
     * Never renders anything: it stores the result and sends the browser back to
     * the SPA with `?calendar=<outcome>`. Only our own small enum of reasons is
     * ever placed in the URL - never a code, a token, or Google's raw message.
     */
    public function callback()
    {
        $this->call->helper('integration');
        $this->call->database();
        $this->call->model('CalendarLinkModel');

        $state = isset($_GET['state']) ? (string) $_GET['state'] : '';
        $code  = isset($_GET['code']) ? (string) $_GET['code'] : '';
        $error = isset($_GET['error']) ? (string) $_GET['error'] : '';

        // The runner pressed "Cancel" on Google's screen, or Google refused.
        if ($error !== '') {
            $reason = 'denied';
        } else {
            $result = $this->CalendarLinkModel->finish_connection($state, $code);
            $reason = (string) $result['reason'];
        }

        header('Location: ' . $this->return_to($reason === 'ok' ? 'connected' : $reason), true, 302);
        exit;
    }

    /**
     * Connection state for the Account page.
     */
    public function status()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('CalendarLinkModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        header('Cache-Control: no-store');
        $this->api->respond(array('data' => $this->CalendarLinkModel->status_for_user($user_id)));
    }

    /**
     * Revoke at Google and forget the grant locally.
     */
    public function disconnect()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('CalendarLinkModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $this->api->rate_limit('calendar_disconnect_' . $user_id, 10, 60);

        $result = $this->CalendarLinkModel->disconnect($user_id);

        header('Cache-Control: no-store');
        $this->api->respond(array(
            'data' => array(
                'connection' => $this->CalendarLinkModel->status_for_user($user_id),
                'disconnected' => (bool) $result['ok'],
                'revoked'      => (bool) $result['revoked'],
                'message'      => (string) $result['message'],
            ),
        ));
    }

    /**
     * Pause or resume event creation while keeping the Google grant.
     */
    public function toggle()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('CalendarLinkModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $body    = $this->api->body();
        $enabled = filter_var($body['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            $this->api->respond_error('Send {"enabled": true} or {"enabled": false}.', 422);
            return;
        }

        $status = $this->CalendarLinkModel->status_for_user($user_id);

        if ($status['status'] !== CalendarLinkModel::STATE_CONNECTED) {
            header('Cache-Control: no-store');
            $this->api->respond(array(
                'data' => array_merge($status, array(
                    'ok'      => false,
                    'message' => 'Connect Google Calendar before turning sync on or off.',
                )),
            ));
            return;
        }

        $this->CalendarLinkModel->set_sync_enabled($user_id, $enabled);

        header('Cache-Control: no-store');
        $this->api->respond(array('data' => $this->CalendarLinkModel->status_for_user($user_id)));
    }

    /**
     * Where the browser lands after the consent screen.
     *
     * Configurable (GCAL_RETURN_URL) because the SPA origin differs between
     * localhost, a preview build, and production.
     *
     * @param string $outcome one of our reason codes
     * @return string
     */
    private function return_to($outcome)
    {
        $base = trim((string) integration_value('google_calendar', 'return_url', ''));
        if ($base === '') {
            $base = 'http://localhost:5173/account';
        }

        $allowed = array(
            'connected', 'denied', 'invalid_state', 'not_configured', 'storage_failed',
            'revoked', 'unauthorized', 'rate_limited', 'unavailable', 'error',
        );
        $reason = in_array($outcome, $allowed, true) ? $outcome : 'error';

        $separator = strpos($base, '?') === false ? '?' : '&';
        return $base . $separator . 'calendar=' . rawurlencode($reason);
    }
}