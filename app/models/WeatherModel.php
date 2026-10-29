<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: WeatherModel
 *
 * Automatically generated via CLI.
 */
require_once dirname(__DIR__) . '/libraries/OpenWeatherService.php';

class WeatherModel extends Model
{
    protected $table       = 'weather_cache';
    protected $primary_key = 'id';

    const STATE_OK                  = 'ok';
    const STATE_STALE               = 'stale_cache';
    const STATE_NOT_CONFIGURED      = 'not_configured';
    const STATE_NO_LOCATION         = 'no_location';
    const STATE_UNRESOLVED_LOCATION = 'unresolved_location';
    const STATE_UNAVAILABLE         = 'unavailable';

    /**
     * Advisory forecast for the next horizon_days() days.
     *
     * @param int                     $user_id
     * @param DateTimeImmutable|null  $today
     * @param OpenWeatherService|null $service injectable so tests can fake it
     * @return array
     */
    public function forecast_for_user($user_id, ?DateTimeImmutable $today = null, ?OpenWeatherService $service = null)
    {
        $uid     = (int) $user_id;
        $ref     = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client  = $service ?: new OpenWeatherService();
        $horizon = $client->horizon_days();

        $from = $ref->format('Y-m-d');
        $to   = $ref->modify('+' . max(0, $horizon - 1) . ' days')->format('Y-m-d');
        $loc  = $this->profile_location($uid);

        if (!$client->is_ready()) {
            return $this->build(
                self::STATE_NOT_CONFIGURED,
                $loc,
                $client,
                array(),
                false,
                'Advisory weather is off. Add OWM_API_KEY and set OWM_ENABLED=true in .env to turn it on.'
            );
        }

        if ($loc === null || ($loc['label'] === null && $loc['has_coordinates'] === false)) {
            return $this->build(
                self::STATE_NO_LOCATION,
                $loc,
                $client,
                array(),
                false,
                'Set a training location on your Runner Profile to see weather on your scheduled runs.'
            );
        }

        $latitude  = $loc['latitude'];
        $longitude = $loc['longitude'];

        // A typed city name is geocoded once, then remembered so the next
        // dashboard load costs a single forecast call instead of two.
        if ($loc['has_coordinates'] === false) {
            $geo = $client->geocode($loc['label']);

            if (!$geo['ok']) {
                $state = $geo['error_code'] === 'not_configured'
                    ? self::STATE_NOT_CONFIGURED
                    : self::STATE_UNRESOLVED_LOCATION;

                return $this->build(
                    $state,
                    $loc,
                    $client,
                    array(),
                    false,
                    $geo['error'] ?: 'That location could not be found.'
                );
            }

            $latitude  = $geo['latitude'];
            $longitude = $geo['longitude'];

            $loc['latitude']        = $latitude;
            $loc['longitude']       = $longitude;
            $loc['has_coordinates'] = true;
            $loc['label']           = $geo['label'] ?: $loc['label'];

            $this->remember_coordinates($uid, $latitude, $longitude, $geo['label']);
        }

        $key   = $this->location_key($latitude, $longitude);
        $cache = $this->read_cache($key, $from, $to);

        if ($this->cache_is_fresh($cache, $from)) {
            return $this->build(
                self::STATE_OK,
                $loc,
                $client,
                $this->rows_to_days($cache),
                true,
                'Cached forecast, refreshed every ' . $client->cache_minutes() . ' minutes.'
            );
        }

        $res = $client->daily_forecast($latitude, $longitude);

        if ($res['ok'] && $res['days']) {
            $kept = array();

            foreach ($res['days'] as $day) {
                if ($day['date'] >= $from && $day['date'] <= $to) {
                    $this->store_day($key, $day, $client->cache_minutes());
                    $kept[] = $day;
                }
            }

            if ($kept) {
                return $this->build(
                    self::STATE_OK,
                    $loc,
                    $client,
                    $kept,
                    false,
                    'Live forecast from OpenWeatherMap.'
                );
            }
        }

        // Provider is down or answered nothing usable: never lose a forecast we
        // already paid for, and never invent one we do not have.
        if ($cache) {
            return $this->build(
                self::STATE_STALE,
                $loc,
                $client,
                $this->rows_to_days($cache),
                true,
                'The weather service is unavailable, so this is the last forecast we received.'
            );
        }

        return $this->build(
            $res['error_code'] === 'not_configured'
                ? self::STATE_NOT_CONFIGURED
                : self::STATE_UNAVAILABLE,
            $loc,
            $client,
            array(),
            false,
            $res['error'] ?: 'The weather service could not be reached.'
        );
    }

    /**
     * Convenience for the Calendar: notes only for the dates it is showing.
     *
     * @param int                     $user_id
     * @param string[]                $dates 'YYYY-MM-DD'
     * @param DateTimeImmutable|null  $today
     * @param OpenWeatherService|null $service
     * @return array map date => {summary, advisories, condition_label}
     */
    public function advisories_for_dates($user_id, array $dates, ?DateTimeImmutable $today = null, ?OpenWeatherService $service = null)
    {
        $wanted = array();

        foreach ($dates as $date) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $wanted[$date] = true;
            }
        }

        if (!$wanted) {
            return array();
        }

        $forecast = $this->forecast_for_user($user_id, $today, $service);
        $out      = array();

        foreach ($forecast['days'] as $day) {
            if (isset($wanted[$day['date']])) {
                $out[$day['date']] = array(
                    'summary'         => $day['summary'],
                    'condition'       => $day['condition'],
                    'condition_label' => $day['condition_label'],
                    'advisories'      => $day['advisories'],
                );
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    /** Stable cache key: coordinates at 5 dp, which is what the column allows. */
    public function location_key($latitude, $longitude)
    {
        return sprintf('%.5F,%.5F', (float) $latitude, (float) $longitude);
    }

    /**
     * @return array date => {payload: array, expires_at: string}
     */
    private function read_cache($key, $from, $to)
    {
        try {
            $rows = $this->db->table($this->table)
                ->select('forecast_date, weather_payload, expires_at')
                ->where('location_key', $key)
                ->between('forecast_date', $from, $to)
                ->order_by('forecast_date', 'ASC')
                ->get_all(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return array();
        }

        $out = array();

        foreach ($rows ?: array() as $row) {
            $decoded = json_decode((string) ($row['weather_payload'] ?? ''), true);

            if (!is_array($decoded)) {
                continue;
            }

            $out[(string) $row['forecast_date']] = array(
                'payload'    => $decoded,
                'expires_at' => (string) $row['expires_at'],
            );
        }

        return $out;
    }

    /**
     * A cached set is fresh when today's row exists and has not expired. The
     * whole set is written in one go, so one fresh row means fresh all round.
     */
    private function cache_is_fresh(array $cache, $from)
    {
        if (!isset($cache[$from])) {
            return false;
        }

        return $cache[$from]['expires_at'] > gmdate('Y-m-d H:i:s');
    }

    private function rows_to_days(array $cache)
    {
        ksort($cache);
        $days = array();

        foreach ($cache as $row) {
            $days[] = $row['payload'];
        }

        return $days;
    }

    private function store_day($key, array $day, $ttl_minutes)
    {
        $date       = (string) $day['date'];
        $payload    = json_encode($day, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expires_at = gmdate('Y-m-d H:i:s', time() + max(5, (int) $ttl_minutes) * 60);

        try {
            $existing = $this->db->table($this->table)
                ->select('id')
                ->where('location_key', $key)
                ->where('forecast_date', $date)
                ->limit(1)
                ->get(PDO::FETCH_ASSOC);

            if ($existing) {
                $this->db->table($this->table)
                    ->where('id', (int) $existing['id'])
                    ->update(array(
                        'weather_payload' => $payload,
                        'expires_at'      => $expires_at,
                    ));

                return;
            }

            $this->db->table($this->table)->insert(array(
                'location_key'    => $key,
                'forecast_date'   => $date,
                'weather_payload' => $payload,
                'expires_at'      => $expires_at,
            ));

            // Keep the table from growing without bound; two weeks is far longer
            // than any stale-cache fallback is useful for.
            $cutoff = gmdate('Y-m-d H:i:s', time() - 14 * 86400);

            $this->db->table($this->table)
                ->where('location_key', $key)
                ->where('expires_at', '<', $cutoff)
                ->delete();
        } catch (Throwable $e) {
            // A cache miss is a performance problem, never a functional one.
        }
    }

    /**
     * The runner's stored location. Returns null when the profile (or the
     * migration 014 columns) is missing, which the caller treats as "no
     * location" rather than an error.
     */
    private function profile_location($user_id)
    {
        try {
            $row = $this->db->table('runner_profiles')
                ->select('location_label, latitude, longitude')
                ->where('user_id', (int) $user_id)
                ->limit(1)
                ->get(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $label = isset($row['location_label']) && $row['location_label'] !== null
            ? (string) $row['location_label']
            : null;

        $latitude = isset($row['latitude']) && $row['latitude'] !== null
            ? (float) $row['latitude']
            : null;

        $longitude = isset($row['longitude']) && $row['longitude'] !== null
            ? (float) $row['longitude']
            : null;

        return array(
            'label'           => $label,
            'latitude'        => $latitude,
            'longitude'       => $longitude,
            'has_coordinates' => $latitude !== null && $longitude !== null,
        );
    }

    /** Best effort: a failed write here only costs one extra geocode call. */
    private function remember_coordinates($user_id, $latitude, $longitude, $label)
    {
        $coordinates = array(
            'latitude'  => round((float) $latitude, 5),
            'longitude' => round((float) $longitude, 5),
        );

        if (is_string($label) && trim($label) !== '' && mb_strlen($label) <= 120) {
            $coordinates['location_label'] = trim($label);
        }

        try {
            $this->db->table('runner_profiles')
                ->where('user_id', (int) $user_id)
                ->update($coordinates);
        } catch (Throwable $e) {
            // Ignore - see the method comment.
        }
    }

    // ------------------------------------------------------------------
    // Reply shape
    // ------------------------------------------------------------------

    private function build($state, $location, OpenWeatherService $client, array $days, $cached, $message)
    {
        $clean_location = array(
            'label'           => $location['label'] ?? null,
            'latitude'        => $location['latitude'] ?? null,
            'longitude'       => $location['longitude'] ?? null,
            'has_coordinates' => (bool) ($location['has_coordinates'] ?? false),
        );

        return array(
            'status'            => $state,
            'ok'                => $state === self::STATE_OK || $state === self::STATE_STALE,
            'message'           => $message,
            'location'          => $clean_location,
            'days'              => array_values($days),
            'cached'            => (bool) $cached,
            'horizon_days'      => $client->horizon_days(),
            'cache_ttl_minutes' => $client->cache_minutes(),
            'product'           => $client->product(),
            // Advisory only: nothing here may change a session.
            'mutates_schedule'  => false,
            'generated_at'      => gmdate('Y-m-d H:i:s'),
        );
    }
}