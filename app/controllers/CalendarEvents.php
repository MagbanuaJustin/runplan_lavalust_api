<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: CalendarEvents
 *
 * POST /api/calendar/sync runs an idempotent sync of upcoming planned sessions.
 * The controller handles HTTP/auth only; all SQL and Google calls stay in
 * CalendarSyncModel and its provider service.
 */
class CalendarEvents extends Controller {

    public function sync()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('CalendarSyncModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $this->api->rate_limit('calendar_sync_' . $user_id, 5, 60);
        header('Cache-Control: no-store');

        // The endpoint returns a safe state/result even when Google is not
        // configured or is temporarily unavailable. Core plan operations do
        // not depend on this request succeeding.
        $this->api->respond(array(
            'data' => $this->CalendarSyncModel->sync_for_user($user_id),
        ));
    }
}