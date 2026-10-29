<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Health
 * 
 * Automatically generated via CLI.
 */
class Health extends Controller {

    public function index()
    {
        $this->call->library('api');   // loads Api.php: sets CORS + JSON headers
        $this->call->database();       // throws if the DB is unreachable
        $this->api->respond([
            'status'  => 'ok',
            'service' => 'runplan-api',
            'time'    => gmdate('c'),
        ]);
    }

    /**
     * Handles cross-origin OPTIONS preflight requests routed under /api/*.
     * Api::__construct() emits CORS headers and exits 204 on OPTIONS.
     */
    public function preflight()
    {
        $this->call->library('api');
        http_response_code(204);
        exit;
    }
}
