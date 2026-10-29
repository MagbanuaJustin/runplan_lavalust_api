<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/HttpClient.php';

/**
 * GoogleCalendarEventService - create or update an event through Calendar API v3.
 *
 * OAuth/token exchange is kept in GoogleCalendarService. This class receives an
 * already-valid access token and only speaks to the events endpoint. Every call
 * uses HttpClient so the API can be tested without a Google account or network.
 */
class GoogleCalendarEventService {

    const EVENTS_URI = 'https://www.googleapis.com/calendar/v3/calendars';

    /** @var HttpClient */
    private $http;

    /** @var int */
    private $timeout;

    /**
     * @param array|null      $config provider config override (tests)
     * @param HttpClient|null $http
     */
    public function __construct(?array $config = null, ?HttpClient $http = null)
    {
        $config = $config === null ? integration_config('google_calendar') : $config;
        $this->timeout = max(1, (int) ($config['timeout'] ?? 15));
        $this->http = $http ?: new HttpClient($this->timeout);
    }

    /**
     * Create an event, or patch the existing event represented by $event_id.
     *
     * If an update returns 404, it creates the event again. If the process died
     * after Google accepted a create but before RunPlan saved its mapping, the
     * deterministic id in the payload makes the retry receive 409; that case is
     * recovered by patching the event with that id. Both paths are idempotent.
     *
     * @param string      $access_token
     * @param string      $calendar_id Google calendar id, normally "primary"
     * @param string|null $event_id    existing provider id, or null on first sync
     * @param array       $event       Google Calendar Event resource
     * @param string      $stable_id   deterministic Google-compatible event id
     * @return array {ok,event_id,action,error_code?,message?}
     */
    public function upsert($access_token, $calendar_id, $event_id, array $event, $stable_id)
    {
        $access_token = trim((string) $access_token);
        $calendar_id  = trim((string) $calendar_id);
        $stable_id    = strtolower(trim((string) $stable_id));

        if ($access_token === '' || $calendar_id === '' || !$this->valid_event_id($stable_id)) {
            return $this->failure('unavailable', 'The calendar event request is incomplete.');
        }

        $current_id = trim((string) ($event_id ?? ''));
        if ($current_id !== '') {
            if (!$this->valid_event_id($current_id)) {
                return $this->failure('unavailable', 'The saved calendar event reference is invalid.');
            }

            $updated = $this->patch($access_token, $calendar_id, $current_id, $event);
            if ($updated['ok']) {
                return array('ok' => true, 'event_id' => $current_id, 'action' => 'updated');
            }
            if ($updated['error_code'] !== 'not_found') {
                return $updated;
            }
        }

        // Use the same ID on every retry. Google event ids allow lowercase
        // base32hex characters; CalendarSyncModel produces `r` + SHA-256 hex.
        $event['id'] = $current_id !== '' ? $current_id : $stable_id;
        $created = $this->create($access_token, $calendar_id, $event);

        if ($created['ok']) {
            return $created;
        }

        if ($created['error_code'] === 'conflict') {
            $recovered_id = (string) ($event['id'] ?? $stable_id);
            $recovered = $this->patch($access_token, $calendar_id, $recovered_id, $event);
            if ($recovered['ok']) {
                return array('ok' => true, 'event_id' => $recovered_id, 'action' => 'updated');
            }
            return $recovered;
        }

        return $created;
    }

    /**
     * Delete a mapped event. A 404 is already deleted and is treated as success.
     * This is used when an upcoming session is skipped.
     *
     * @param string $access_token
     * @param string $calendar_id
     * @param string $event_id
     * @return array {ok,event_id,action,error_code?,message?}
     */
    public function delete_event($access_token, $calendar_id, $event_id)
    {
        $access_token = trim((string) $access_token);
        $calendar_id  = trim((string) $calendar_id);
        $event_id     = strtolower(trim((string) $event_id));

        if ($access_token === '' || $calendar_id === '' || !$this->valid_event_id($event_id)) {
            return $this->failure('unavailable', 'The calendar event request is incomplete.');
        }

        $res = $this->request('DELETE', $access_token, $calendar_id, $event_id);
        if ($res['ok'] || $res['error_code'] === 'not_found') {
            return array('ok' => true, 'event_id' => $event_id, 'action' => 'deleted');
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // HTTP details
    // ------------------------------------------------------------------

    private function create($access_token, $calendar_id, array $event)
    {
        $res = $this->request('POST', $access_token, $calendar_id, null, $event);
        if (!$res['ok']) {
            return $res;
        }

        $id = (string) ($res['data']['id'] ?? ($event['id'] ?? ''));
        if ($id === '' || !$this->valid_event_id($id)) {
            return $this->failure('unavailable', 'Google did not return a usable event id.');
        }

        return array('ok' => true, 'event_id' => $id, 'action' => 'created');
    }

    private function patch($access_token, $calendar_id, $event_id, array $event)
    {
        unset($event['id']); // PATCH the managed fields without replacing user-added fields.
        $res = $this->request('PATCH', $access_token, $calendar_id, $event_id, $event);
        if ($res['ok']) {
            return array('ok' => true, 'event_id' => $event_id, 'action' => 'updated');
        }
        return $res;
    }

    private function request($method, $access_token, $calendar_id, $event_id = null, $payload = null)
    {
        $url = self::EVENTS_URI . '/' . rawurlencode((string) $calendar_id) . '/events';
        if ($event_id !== null && $event_id !== '') {
            $url .= '/' . rawurlencode((string) $event_id);
        }

        $options = array(
            'method'  => $method,
            'headers' => array(
                'Authorization: Bearer ' . $access_token,
                'Accept: application/json',
            ),
            'timeout' => $this->timeout,
        );
        if ($payload !== null) {
            $options['json'] = $payload;
        }

        $res = $this->http->request($url, $options);
        if ($res['ok']) {
            return array('ok' => true, 'data' => is_array($res['data']) ? $res['data'] : array());
        }

        $status = (int) ($res['status'] ?? 0);
        if ($status === 404 || $status === 410) {
            return $this->failure('not_found', 'The calendar event no longer exists.');
        }
        if ($status === 409) {
            return $this->failure('conflict', 'The calendar event already exists.');
        }
        if ($status === 401) {
            return $this->failure('unauthorized', 'Google rejected calendar access. Refresh the connection and try again.');
        }
        if ($status === 403) {
            return $this->failure('forbidden', 'Google denied this event change. Check Calendar API access and reconnect if needed.');
        }
        if ($status === 429) {
            return $this->failure('rate_limited', 'Google Calendar rate limited this sync. Try again shortly.');
        }

        return $this->failure('unavailable', 'Google Calendar could not complete this event sync. Try again shortly.');
    }

    private function failure($code, $message)
    {
        return array('ok' => false, 'event_id' => null, 'error_code' => $code, 'message' => $message);
    }

    /**
     * Google custom event ids use lowercase base32hex characters, 5-1024 chars.
     */
    private function valid_event_id($event_id)
    {
        return is_string($event_id)
            && strlen($event_id) >= 5
            && strlen($event_id) <= 1024
            && preg_match('/^[a-v0-9]+$/', $event_id) === 1;
    }
}