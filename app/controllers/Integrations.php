<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Integrations
 *
 * Read-only operational status for configured providers, plus this runner's
 * Google Calendar connection state. Credentials and token contents are never
 * included. Core planner features remain usable when providers are off.
 */
class Integrations extends Controller {

    public function status()
    {
        $this->call->library('api');
        $this->call->helper('integration');
        $this->call->database();
        $this->call->model('CalendarLinkModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $calendar_connection = $this->CalendarLinkModel->status_for_user((int) $payload['sub']);

        header('Cache-Control: no-store');
        $this->api->respond(array('data' => array(
            'providers' => integration_public_config(),
            // Per-runner consent state is distinct from deployment readiness.
            // CalendarLinkModel only returns browser-safe metadata here.
            'google_calendar_connection' => $calendar_connection,
            'core_enabled' => true,
        )));
    }
}