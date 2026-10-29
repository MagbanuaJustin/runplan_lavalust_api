<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Weather
 * 
 * Automatically generated via CLI.
 */
class Weather extends Controller {

    public function forecast()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('WeatherModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('weather_' . $user_id, 40, 60);

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $this->WeatherModel->forecast_for_user($user_id, $this->today_from_browser())]);
    }

    /**
     * The browser's local date is accepted only when it is within a day of the
     * server's UTC date, so a wrong clock cannot shift the whole horizon.
     *
     * @return DateTimeImmutable
     */
    private function today_from_browser()
    {
        $utc    = new DateTimeZone('UTC');
        $server = new DateTimeImmutable('today', $utc);
        $asked  = (string) ($_GET['today'] ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked)) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $asked, $utc);
            if ($d && $d->format('Y-m-d') === $asked
                && abs((int) $server->diff($d)->format('%r%a')) <= 1) {
                return $d;
            }
        }

        return $server;
    }
}