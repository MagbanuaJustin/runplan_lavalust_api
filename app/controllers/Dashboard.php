<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Dashboard
 * 
 * Automatically generated via CLI.
 */
class Dashboard extends Controller {

    public function index()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('DashboardModel');
        $this->call->model('SessionModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('dashboard_' . $user_id, 60, 60);

        // The browser's local date is accepted only if it is within a day of the server's date
        $utc    = new DateTimeZone('UTC');
        $server = new DateTimeImmutable('today', $utc);
        $today  = $server;
        $asked  = (string) ($_GET['today'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked)) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $asked, $utc);
            if ($d && $d->format('Y-m-d') === $asked && abs((int) $server->diff($d)->format('%r%a')) <= 1) {
                $today = $d;
            }
        }

        $this->SessionModel->mark_missed_for_user($user_id, $today);

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $this->DashboardModel->summary($user_id, $today)]);
    }
}
