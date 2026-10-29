<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/HttpClient.php';
class OpenWeatherService {

    const PRODUCT_FORECAST = 'forecast16';
    const PRODUCT_ONECALL  = 'onecall';

    /** Advisory thresholds, in the units the config asks for (metric). */
    const HEAT_WARNING_C  = 38.0;
    const HEAT_CAUTION_C  = 32.0;
    const RAIN_WARNING_PC = 70;
    const RAIN_CAUTION_PC = 40;
    const RAIN_WARNING_MM = 5.0;
    const RAIN_CAUTION_MM = 1.0;
    const WIND_WARNING_KMH = 50.0;
    const WIND_CAUTION_KMH = 30.0;

    /** @var array */
    private $config;

    /** @var HttpClient */
    private $http;

    public function __construct(?array $config = null, ?HttpClient $http = null)
    {
        if ($config !== null) {
            $this->config = $config;
        } elseif (function_exists('integration_config')) {
            $this->config = integration_config('openweathermap');
        } else {
            $this->config = array();
        }

        $timeout    = max(3, (int) ($this->config['timeout'] ?? 10));
        $this->http = $http ?: new HttpClient($timeout);
    }

    public function is_ready()
    {
        $enabled = filter_var($this->config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $key     = trim((string) ($this->config['api_key'] ?? ''));
        return $enabled === true && $key !== '';
    }

    public function product()
    {
        $product = strtolower(trim((string) ($this->config['product'] ?? self::PRODUCT_FORECAST)));
        return in_array($product, array(self::PRODUCT_FORECAST, self::PRODUCT_ONECALL), true)
            ? $product
            : self::PRODUCT_FORECAST;
    }

    /** How many days ahead this product can answer for. Published, never guessed. */
    public function horizon_days()
    {
        $published = (int) ($this->config['forecast_days'] ?? 5);
        $ceiling   = $this->product() === self::PRODUCT_ONECALL ? 8 : 5;
        return max(1, min($ceiling, $published > 0 ? $published : $ceiling));
    }

    public function cache_minutes()
    {
        return max(5, (int) ($this->config['cache_minutes'] ?? 60));
    }

    // ------------------------------------------------------------------
    // Geocoding
    // ------------------------------------------------------------------

    /**
     * Turns a place into coordinates. A string that already looks like
     * "13.2233,120.5956" is accepted as-is without spending a request.
     *
     * @param string $place
     * @return array {ok, latitude, longitude, label, error, error_code}
     */
    public function geocode($place)
    {
        $place = trim((string) $place);

        if ($place === '') {
            return $this->fail('no_location', 'Set a training location to see weather.');
        }

        if (preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $place, $m)) {
            $lat = (float) $m[1];
            $lon = (float) $m[2];
            if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
                return $this->good(array(
                    'latitude'  => $lat,
                    'longitude' => $lon,
                    'label'     => $place,
                ));
            }
            return $this->fail('no_match', 'Those coordinates are outside the valid range.');
        }

        if (!$this->is_ready()) {
            return $this->fail('not_configured', 'Weather is not configured on this server.');
        }

        $res = $this->http->get_json(
            $this->base_uri() . '/geo/1.0/direct',
            array('q' => $place, 'limit' => 1, 'appid' => $this->api_key()),
            array('timeout' => $this->timeout())
        );

        if (!$res['ok'] || !is_array($res['data'])) {
            return $this->fail($this->code_for($res), $res['error'] ?: 'The weather service could not be reached.');
        }

        $hit = $res['data'][0] ?? null;
        if (!is_array($hit) || !isset($hit['lat'], $hit['lon'])) {
            return $this->fail('no_match', 'No place called "' . $place . '" could be found.');
        }

        return $this->good(array(
            'latitude'  => (float) $hit['lat'],
            'longitude' => (float) $hit['lon'],
            'label'     => $this->place_label($hit, $place),
        ));
    }

    // ------------------------------------------------------------------
    // Forecast
    // ------------------------------------------------------------------

    /**
     * Daily forecast for a coordinate pair, normalised so the UI never has to
     * know which provider product produced it.
     *
     * @param float $latitude
     * @param float $longitude
     * @return array {ok, days, error, error_code}
     */
    public function daily_forecast($latitude, $longitude)
    {
        $lat = (float) $latitude;
        $lon = (float) $longitude;

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return $this->fail('no_location', 'The stored coordinates are outside the valid range.');
        }

        if (!$this->is_ready()) {
            return $this->fail('not_configured', 'Weather is not configured on this server.');
        }

        $res = $this->product() === self::PRODUCT_ONECALL
            ? $this->fetch_onecall($lat, $lon)
            : $this->fetch_forecast16($lat, $lon);

        if (!$res['ok'] || !is_array($res['data'])) {
            return $this->fail($this->code_for($res), $res['error'] ?: 'The weather service could not be reached.');
        }

        $days = $this->product() === self::PRODUCT_ONECALL
            ? $this->normalise_onecall($res['data'])
            : $this->normalise_forecast16($res['data']);

        if (!$days) {
            return $this->fail('unavailable', 'The weather service returned no usable forecast.');
        }

        return $this->good(array('days' => array_slice($days, 0, $this->horizon_days())));
    }

    private function fetch_forecast16($lat, $lon)
    {
        return $this->http->get_json(
            $this->base_uri() . '/data/2.5/forecast',
            array(
                'lat'   => round($lat, 5),
                'lon'   => round($lon, 5),
                'units' => 'metric',
                'appid' => $this->api_key(),
            ),
            array('timeout' => $this->timeout())
        );
    }

    private function fetch_onecall($lat, $lon)
    {
        return $this->http->get_json(
            $this->base_uri() . '/data/3.0/onecall',
            array(
                'lat'     => round($lat, 5),
                'lon'     => round($lon, 5),
                'units'   => 'metric',
                'exclude' => 'minutely,hourly,alerts',
                'appid'   => $this->api_key(),
            ),
            array('timeout' => $this->timeout())
        );
    }

    /**
     * 5-day/3-hour list -> one row per local day.
     *
     * The local date comes from `dt` plus the city's own UTC offset, not from
     * the server's timezone: a 07:00 run in Manila is the 24th, and the server
     * thinking in UTC must not label it the 23rd.
     */
    private function normalise_forecast16(array $data)
    {
        $entries = isset($data['list']) && is_array($data['list']) ? $data['list'] : array();
        $offset  = (int) ($data['city']['timezone'] ?? 0);

        return $this->aggregate_by_day($entries, function ($entry) use ($offset) {
            $dt = isset($entry['dt']) ? (int) $entry['dt'] : 0;
            if ($dt > 0) {
                return gmdate('Y-m-d', $dt + $offset);
            }
            $txt = (string) ($entry['dt_txt'] ?? '');
            return preg_match('/^\d{4}-\d{2}-\d{2}/', $txt) ? substr($txt, 0, 10) : '';
        }, function ($entry) {
            return array(
                'temp'    => $entry['main']['temp'] ?? null,
                'temp_min' => $entry['main']['temp_min'] ?? null,
                'temp_max' => $entry['main']['temp_max'] ?? null,
                'feels'   => $entry['main']['feels_like'] ?? null,
                'wind'    => $entry['wind']['speed'] ?? null,          // m/s
                'gust'    => $entry['wind']['gust'] ?? null,           // m/s
                'pop'     => $entry['pop'] ?? null,                    // 0..1
                'rain'    => $entry['rain']['3h'] ?? ($entry['rain'] ?? null),
                'weather' => $entry['weather'][0] ?? array(),
            );
        });
    }

    private function normalise_onecall(array $data)
    {
        $entries = isset($data['daily']) && is_array($data['daily']) ? $data['daily'] : array();
        $offset  = (int) ($data['timezone_offset'] ?? 0);

        return $this->aggregate_by_day($entries, function ($entry) use ($offset) {
            $dt = isset($entry['dt']) ? (int) $entry['dt'] : 0;
            return $dt > 0 ? gmdate('Y-m-d', $dt + $offset) : '';
        }, function ($entry) {
            return array(
                'temp'    => $entry['temp']['day'] ?? null,
                'temp_min' => $entry['temp']['min'] ?? null,
                'temp_max' => $entry['temp']['max'] ?? null,
                'feels'   => $entry['feels_like']['day'] ?? ($entry['feels_like']['max'] ?? null),
                'wind'    => $entry['wind_speed'] ?? null,
                'gust'    => $entry['wind_gust'] ?? null,
                'pop'     => $entry['pop'] ?? null,
                'rain'    => $entry['rain'] ?? null,
                'weather' => $entry['weather'][0] ?? array(),
            );
        });
    }

    /**
     * @param array    $entries
     * @param callable $date_of   entry -> 'YYYY-MM-DD'
     * @param callable $flatten   entry -> normalised numbers
     * @return array list of daily rows, ascending by date
     */
    private function aggregate_by_day(array $entries, callable $date_of, callable $flatten)
    {
        $by_date = array();

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $date = (string) $date_of($entry);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $row = $flatten($entry);
            $by_date[$date][] = $row;
        }

        ksort($by_date);

        $days = array();
        foreach ($by_date as $date => $rows) {
            $days[] = $this->fold_day($date, $rows);
        }

        return $days;
    }

    private function fold_day($date, array $rows)
    {
        $mins   = array();
        $maxs   = array();
        $feels  = array();
        $winds  = array();
        $gusts  = array();
        $pops   = array();
        $rain   = 0.0;
        $worst  = array();

        foreach ($rows as $r) {
            foreach (array('temp', 'temp_min', 'temp_max', 'feels', 'wind', 'gust', 'pop', 'rain') as $k) {
                if (isset($r[$k]) && is_numeric($r[$k])) {
                    $r[$k] = (float) $r[$k];
                } else {
                    $r[$k] = null;
                }
            }

            $low  = $r['temp_min'] !== null ? $r['temp_min'] : $r['temp'];
            $high = $r['temp_max'] !== null ? $r['temp_max'] : $r['temp'];
            if ($low !== null)  { $mins[]  = $low; }
            if ($high !== null) { $maxs[]  = $high; }
            if ($r['feels'] !== null) { $feels[] = $r['feels']; }
            if ($r['wind'] !== null)  { $winds[] = $r['wind'] * 3.6; }   // m/s -> km/h
            if ($r['gust'] !== null)  { $gusts[] = $r['gust'] * 3.6; }
            if ($r['pop'] !== null)   { $pops[]  = (int) round($r['pop'] * 100); }
            if ($r['rain'] !== null)  { $rain   += $r['rain']; }

            $weather = is_array($r['weather']) ? $r['weather'] : array();
            $rank    = $this->severity_rank($this->condition_of($weather));
            if ($worst === array() || $rank > $worst['rank']) {
                $worst = array(
                    'rank'        => $rank,
                    'condition'   => $this->condition_of($weather),
                    'description' => (string) ($weather['description'] ?? ''),
                    'icon'        => (string) ($weather['icon'] ?? ''),
                );
            }
        }

        $day = array(
            'date'               => $date,
            'temp_min_c'         => $mins ? round(min($mins), 1) : null,
            'temp_max_c'         => $maxs ? round(max($maxs), 1) : null,
            'feels_like_max_c'   => $feels ? round(max($feels), 1) : null,
            'wind_max_kmh'       => $winds ? round(max($winds), 1) : null,
            'gust_max_kmh'       => $gusts ? round(max($gusts), 1) : null,
            'rain_probability'   => $pops ? max($pops) : 0,
            'rain_mm'            => round($rain, 1),
            'condition'          => $worst['condition'] ?? 'other',
            'condition_label'    => $this->condition_label($worst['condition'] ?? 'other'),
            'description'        => $worst['description'] ?? '',
            'icon'               => $worst['icon'] ?? '',
        );

        $day['summary']    = $this->summary_line($day);
        $day['advisories'] = $this->advisories($day);

        return $day;
    }

    // ------------------------------------------------------------------
    // Advice
    // ------------------------------------------------------------------

    /**
     * Safety notes for one day. Kept to at most three, ordered by severity, so
     * the UI can render them as plain badges.
     *
     * These are suggestions, never instructions: the runner decides.
     *
     * @param array $day normalised day
     * @return array list of {level, kind, title, detail}
     */
    public function advisories(array $day)
    {
        $notes = array();

        $feels = $day['feels_like_max_c'];
        if ($feels !== null && $feels >= self::HEAT_WARNING_C) {
            $notes[] = array(
                'level'  => 'warning',
                'kind'   => 'heat',
                'title'  => 'Heat warning',
                'detail' => sprintf('Feels like %d °C. Run early morning or after sunset, slow the pace, and carry water.', (int) round($feels)),
            );
        } elseif ($feels !== null && $feels >= self::HEAT_CAUTION_C) {
            $notes[] = array(
                'level'  => 'caution',
                'kind'   => 'heat',
                'title'  => 'Warm conditions',
                'detail' => sprintf('Feels like %d °C. Add 10–20 sec/km to your easy pace and hydrate before you start.', (int) round($feels)),
            );
        }

        $pc   = (int) ($day['rain_probability'] ?? 0);
        $mm   = (float) ($day['rain_mm'] ?? 0);
        if ($pc >= self::RAIN_WARNING_PC || $mm >= self::RAIN_WARNING_MM) {
            $notes[] = array(
                'level'  => 'warning',
                'kind'   => 'rain',
                'title'  => 'Rain likely',
                'detail' => sprintf('%d%% chance, about %s mm. Wear a cap and high-visibility layer, and expect a slower surface.', $pc, rtrim(rtrim(number_format($mm, 1, '.', ''), '0'), '.')),
            );
        } elseif ($pc >= self::RAIN_CAUTION_PC || $mm >= self::RAIN_CAUTION_MM) {
            $notes[] = array(
                'level'  => 'caution',
                'kind'   => 'rain',
                'title'  => 'Possible showers',
                'detail' => sprintf('%d%% chance of rain. A light jacket is enough; the session does not need to change.', $pc),
            );
        }

        $wind = $day['wind_max_kmh'];
        if ($wind !== null && $wind >= self::WIND_WARNING_KMH) {
            $notes[] = array(
                'level'  => 'warning',
                'kind'   => 'wind',
                'title'  => 'Strong wind',
                'detail' => sprintf('%d km/h sustained. Do the hard part of the session into the wind and come home with it.', (int) round($wind)),
            );
        } elseif ($wind !== null && $wind >= self::WIND_CAUTION_KMH) {
            $notes[] = array(
                'level'  => 'caution',
                'kind'   => 'wind',
                'title'  => 'Breezy',
                'detail' => sprintf('%d km/h wind. It will cost a few seconds per km but is safe to train in.', (int) round($wind)),
            );
        }

        if ($day['condition'] === 'storm') {
            $notes[] = array(
                'level'  => 'warning',
                'kind'   => 'storm',
                'title'  => 'Thunderstorms',
                'detail' => 'Lightning risk. Move this session indoors or shift it to another day; do not run through a storm.',
            );
        }

        if ($day['temp_max_c'] !== null && $day['temp_max_c'] <= 1.0) {
            $notes[] = array(
                'level'  => 'caution',
                'kind'   => 'cold',
                'title'  => 'Near freezing',
                'detail' => 'Watch for ice on shaded paths and warm up indoors before heading out.',
            );
        }

        $ranked = array('warning' => 0, 'caution' => 1, 'info' => 2);
        usort($notes, function ($a, $b) use ($ranked) {
            return $ranked[$a['level']] <=> $ranked[$b['level']];
        });

        return array_slice($notes, 0, 3);
    }

    /** One line the UI can print under a session badge. */
    public function summary_line(array $day)
    {
        $parts = array();

        if ($day['temp_min_c'] !== null && $day['temp_max_c'] !== null) {
            $parts[] = sprintf('%d–%d °C', (int) round($day['temp_min_c']), (int) round($day['temp_max_c']));
        } elseif ($day['temp_max_c'] !== null) {
            $parts[] = sprintf('%d °C', (int) round($day['temp_max_c']));
        }

        if ((int) ($day['rain_probability'] ?? 0) > 0) {
            $parts[] = sprintf('%d%% rain', (int) $day['rain_probability']);
        }

        if ($day['wind_max_kmh'] !== null) {
            $parts[] = sprintf('%d km/h wind', (int) round($day['wind_max_kmh']));
        }

        return implode(' · ', $parts);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function condition_of($weather)
    {
        $main = strtolower(trim((string) (is_array($weather) ? ($weather['main'] ?? '') : '')));

        $map = array(
            'clear'        => 'clear',
            'clouds'       => 'clouds',
            'rain'         => 'rain',
            'drizzle'      => 'drizzle',
            'thunderstorm' => 'storm',
            'snow'         => 'snow',
            'mist'         => 'fog',
            'fog'          => 'fog',
            'haze'         => 'fog',
            'smoke'        => 'fog',
            'dust'         => 'fog',
            'sand'         => 'fog',
            'ash'          => 'fog',
            'squall'       => 'wind',
            'tornado'      => 'wind',
        );

        return $map[$main] ?? 'other';
    }

    private function condition_label($condition)
    {
        $labels = array(
            'clear'   => 'Clear',
            'clouds'  => 'Cloudy',
            'rain'    => 'Rain',
            'drizzle' => 'Drizzle',
            'storm'   => 'Storm',
            'snow'    => 'Snow',
            'fog'     => 'Low visibility',
            'wind'    => 'Windy',
            'other'   => 'Mixed',
        );
        return $labels[$condition] ?? 'Mixed';
    }

    private function severity_rank($condition)
    {
        $ranks = array(
            'clear' => 0, 'clouds' => 1, 'fog' => 2, 'wind' => 2,
            'drizzle' => 3, 'rain' => 4, 'snow' => 5, 'storm' => 6, 'other' => 1,
        );
        return $ranks[$condition] ?? 1;
    }

    private function place_label(array $hit, $fallback)
    {
        $bits = array();
        foreach (array('name', 'state', 'country') as $key) {
            $value = trim((string) ($hit[$key] ?? ''));
            if ($value !== '' && !in_array($value, $bits, true)) {
                $bits[] = $value;
            }
        }
        return $bits ? implode(', ', $bits) : $fallback;
    }

    private function base_uri()
    {
        return rtrim((string) ($this->config['base_uri'] ?? 'https://api.openweathermap.org'), '/');
    }

    private function api_key()
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }

    private function timeout()
    {
        return max(3, (int) ($this->config['timeout'] ?? 10));
    }

    /**
     * Maps a transport failure onto the small set of codes the model branches
     * on. HttpClient's own tags (http/timeout/dns/tls/transport/json) are an
     * implementation detail: from here on, everything that is not an auth or
     * quota problem is simply "unavailable".
     */
    private function code_for(array $res)
    {
        $status = (int) ($res['status'] ?? 0);

        if ($status === 401 || $status === 403) {
            return 'unauthorized';
        }
        if ($status === 429) {
            return 'rate_limited';
        }

        return 'unavailable';
    }

    private function good(array $data)
    {
        return array_merge(array(
            'ok'         => true,
            'latitude'   => null,
            'longitude'  => null,
            'label'      => null,
            'days'       => array(),
            'error'      => null,
            'error_code' => null,
        ), $data);
    }

    private function fail($code, $message)
    {
        return array(
            'ok'         => false,
            'latitude'   => null,
            'longitude'  => null,
            'label'      => null,
            'days'       => array(),
            'error'      => (string) $message,
            'error_code' => (string) $code,
        );
    }
}