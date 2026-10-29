<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: PlanModel
 *
 * Automatically generated via CLI.
 */
class PlanModel extends Model
{
    protected $table = 'training_plans';
    protected $primary_key = 'id';

    const RACE_KM      = ['5k' => 5.0, '10k' => 10.0, 'half_marathon' => 21.1, 'marathon' => 42.2];
    const MIN_WEEKS    = ['5k' => 4, '10k' => 6, 'half_marathon' => 8, 'marathon' => 12];
    const MAX_WEEKS    = 30;
    const PEAK_LONG    = ['5k' => 10.0, '10k' => 14.0, 'half_marathon' => 20.0, 'marathon' => 32.0, 'general_fitness' => 12.0];
    const TAPER        = ['5k' => [0.6], '10k' => [0.6], 'half_marathon' => [0.7, 0.5],
                          'marathon' => [0.75, 0.6, 0.45], 'general_fitness' => []];
    const MAX_SESSIONS = ['beginner' => 4, 'intermediate' => 5, 'advanced' => 6];
    const START_LONG   = ['beginner' => 4.0, 'intermediate' => 6.0, 'advanced' => 8.0];
    const EASY_PACE    = ['beginner' => 480, 'intermediate' => 390, 'advanced' => 330]; // seconds per km
    const MAX_EASY     = ['beginner' => 5.0, 'intermediate' => 7.0, 'advanced' => 10.0];
    const RACE_PACE    = ['5k' => 0.92, '10k' => 0.95, 'half_marathon' => 1.0, 'marathon' => 1.05];
    const DOW          = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    const TIME_SLOTS   = [
        'morning'   => '06:30:00',
        'afternoon' => '14:00:00',
        'evening'   => '18:30:00',
    ];
    const NOTES        = [
        'easy'     => 'Easy, conversational pace.',
        'long'     => 'Long run: steady and relaxed. Walk breaks are fine.',
        'tempo'    => 'Warm up, then a comfortably hard effort for the main part.',
        'interval' => 'Short fast efforts with easy jogging in between.',
        'race'     => 'Race day. Start easy and enjoy it.',
    ];

    // ------------------------------------------------------------------
    // Generator. Returns [result, errors]
    // ------------------------------------------------------------------
    public function build(array $p, DateTimeImmutable $today, array $opts = [])
    {
        $utc      = new DateTimeZone('UTC');
        $goal     = $p['goal'];
        $exp      = $p['experience'];
        $start    = $today->modify('+1 day');
        $race     = null;
        $rebuild  = !empty($opts['rebuild']);
        $warnings = [];

        if ($goal === 'general_fitness') {
            $weeks = 8;
            $end   = $start->modify('+55 days');
        } else {
            $race  = new DateTimeImmutable($p['target_race_date'], $utc);
            $days  = (int) $start->diff($race)->format('%r%a');
            $weeks = (int) ceil(($days + 1) / 7);

            if ($rebuild) {
                if ($weeks < 1) {
                    return [null, ['target_race_date' =>
                        'Your race date has already passed. Update it in your profile first.']];
                }
            } else {
                if ($weeks < self::MIN_WEEKS[$goal]) {
                    return [null, ['target_race_date' =>
                        'Your race is too close for this goal. A safe plan needs at least '
                        . self::MIN_WEEKS[$goal] . ' weeks.']];
                }

                if ($weeks > self::MAX_WEEKS) {
                    return [null, ['target_race_date' =>
                        'Your race is more than ' . self::MAX_WEEKS
                        . ' weeks away. Come back closer to the date, or choose general fitness.']];
                }
            }

            $end = $race;
        }

        // ---- training days, spread out, always including the last chosen day ----
        $avail = array_values($p['available_days']);
        $count = count($avail);
        $n     = min($count, self::MAX_SESSIONS[$exp]);

        if (!empty($p['desired_sessions_per_week'])) {
            $desired = max(2, min(6, (int) $p['desired_sessions_per_week']));

            if ($desired > self::MAX_SESSIONS[$exp]) {
                $warnings[] = 'Weekly sessions capped at ' . self::MAX_SESSIONS[$exp]
                    . ' for ' . $exp . ' level safety.';
            }

            $n = min($n, $desired);
        }

        $chosen = [];

        for ($i = 0; $i < $n; $i++) {
            $idx = $n === 1 ? 0 : (int) round($i * ($count - 1) / ($n - 1));
            $chosen[] = $avail[$idx];
        }

        $chosen_dow = array_values(array_unique(array_map(fn($d) => self::DOW[$d], $chosen)));
        $long_dow   = end($chosen_dow);

        $quality_dow = null;

        if ($exp !== 'beginner' && count($chosen_dow) >= 3) {
            $cand = $chosen_dow[(int) floor((count($chosen_dow) - 1) / 2)];

            if ($cand !== $long_dow && abs($cand - $long_dow) > 1) {
                $quality_dow = $cand;
            }
        }

        $pref_time  = $p['preferred_time'] ?? null;
        $start_time = ($pref_time !== null && isset(self::TIME_SLOTS[$pref_time]))
            ? self::TIME_SLOTS[$pref_time]
            : null;

        // ---- distances and pace ----
        $peak_target = self::PEAK_LONG[$goal];
        $base_km     = $p['baseline_distance_km'] ?? null;

        if ($rebuild && !empty($opts['start_long'])) {
            $L0 = round(max(3.0, min((float) $opts['start_long'], 0.9 * $peak_target)), 1);
        } else {
            $L0 = $base_km ? (float) $base_km : self::START_LONG[$exp];
            $L0 = round(max(3.0, min($L0, 0.7 * $peak_target)), 1);
        }

        $easy_pace = !empty($p['baseline_pace'])
            ? (int) round($p['baseline_pace'] * 1.10)
            : self::EASY_PACE[$exp];
        $easy_pace = (float) max(240, min(720, $easy_pace));

        // ---- week-by-week long run ----
        $taper_mult  = $race ? self::TAPER[$goal] : [];
        $build_weeks = $weeks - count($taper_mult);
        $week        = [];
        $prev        = $L0;
        $achieved    = 0.0;

        $prog_total = 0;

        for ($w = 1; $w <= $build_weeks; $w++) {
            if ($w % 4 !== 0) {
                $prog_total++;
            }
        }

        $step = $prog_total > 1 ? ($peak_target - $L0) / ($prog_total - 1) : 0;
        $k    = 0;

        for ($w = 1; $w <= $build_weeks; $w++) {
            if ($w % 4 === 0) {
                $week[$w] = ['long' => max(3.0, round($prev * 0.75, 1)), 'kind' => 'cutback'];
                continue;
            }

            $k++;

            if ($k === 1) {
                $long = $L0;
            } else {
                $linear = $L0 + $step * ($k - 1);
                $long = min($peak_target, round($prev * 1.10 + 0.4, 1), round($linear, 1));
            }

            $prev     = $long;
            $achieved = max($achieved, $long);
            $week[$w] = ['long' => $long, 'kind' => 'build'];
        }

        foreach ($taper_mult as $i => $mult) {
            $week[$build_weeks + $i + 1] = [
                'long' => max(3.0, round($achieved * $mult, 1)),
                'kind' => 'taper',
            ];
        }

        if ($rebuild) {
            $achieved = max($achieved, $L0);
        }

        if ($achieved < 0.85 * $peak_target) {
            $warnings[] = 'Your longest run peaks at ' . $achieved
                . ' km because of the time available. A later start date would allow more.';
        }

        if (empty($p['baseline_distance_km'])) {
            $warnings[] = 'No recent run was entered, so pace and starting distance are estimated from your experience.';
        }

        // ---- sessions ----
        $sessions = [];
        $race_eve = $race ? $race->modify('-1 day') : null;

        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $w    = intdiv((int) $start->diff($date)->days, 7) + 1;
            $info = $week[$w] ?? null;

            if ($info === null) {
                break;
            }

            if ($race && $date == $race) {
                $sessions[] = $this->make_session(
                    $date,
                    '07:00:00',
                    'race',
                    self::RACE_KM[$goal],
                    $easy_pace * self::RACE_PACE[$goal]
                );
                continue;
            }

            if ($race_eve && $date == $race_eve) {
                continue; // rest the day before the race
            }

            $dow = (int) $date->format('N');

            if (!in_array($dow, $chosen_dow, true)) {
                continue;
            }

            $final  = ($race !== null && $w === $weeks);
            $long   = $info['long'];
            $factor = ($info['kind'] === 'taper') ? 0.3 : 0.4;
            $easy   = max(3.0, min(self::MAX_EASY[$exp], round($long * $factor, 1)));

            if ($dow === $long_dow && !$final) {
                $sessions[] = $this->make_session($date, $start_time, 'long', $long, $easy_pace * 1.03);
            } elseif ($dow === $quality_dow && $info['kind'] === 'build' && $w >= 3) {
                $type = ($exp === 'advanced' && $w % 2 === 0) ? 'interval' : 'tempo';
                $sessions[] = $this->make_session(
                    $date,
                    $start_time,
                    $type,
                    max(3.0, round($easy * 0.9, 1)),
                    $easy_pace * ($type === 'tempo' ? 0.88 : 0.82)
                );
            } else {
                $km = $final ? 3.0 : $easy;
                $sessions[] = $this->make_session($date, $start_time, 'easy', $km, $easy_pace);
            }
        }

        return [[
            'plan' => [
                'goal'              => $goal,
                'start_date'        => $start->format('Y-m-d'),
                'end_date'          => $end->format('Y-m-d'),
                'sessions_per_week' => count($chosen_dow),
            ],
            'sessions' => $sessions,
            'warnings' => $warnings,
        ], []];
    }

    private function make_session(DateTimeImmutable $date, $start_time, $type, $km, $pace)
    {
        return [
            'scheduled_date'           => $date->format('Y-m-d'),
            'planned_start_time'       => $start_time,
            'type'                     => $type,
            'planned_distance_km'      => round($km, 2),
            'planned_duration_seconds' => (int) round($km * $pace),
            'notes'                    => self::NOTES[$type],
        ];
    }

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------
    public function create_draft($user_id, array $plan, array $sessions, $creation_mode = 'generated')
    {
        $user_id = (int) $user_id;
        $creation_mode = $creation_mode === 'manual' ? 'manual' : 'generated';

        $this->db->transaction();

        $this->db->table('training_plans')
            ->where('user_id', $user_id)
            ->where('status', 'draft')
            ->delete();

        $this->db->table($this->table)->insert([
            'user_id'           => $user_id,
            'goal'              => $plan['goal'],
            'start_date'        => $plan['start_date'],
            'end_date'          => $plan['end_date'],
            'sessions_per_week' => $plan['sessions_per_week'],
            'creation_mode'     => $creation_mode,
            'status'            => 'draft',
            'version'           => 1,
        ]);

        $plan_id = (int) $this->db->last_id();

        $this->insert_sessions($plan_id, $user_id, $sessions);

        $this->db->commit();

        return $plan_id;
    }

    public function find_for_user($user_id, $plan_id)
    {
        $row = $this->db->table('training_plans')
            ->where('id', (int) $plan_id)
            ->where('user_id', (int) $user_id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        return $row ? $this->with_sessions($row) : null;
    }

    public function current_for_user($user_id)
    {
        $this->mark_missed_for_user($user_id);

        $out = ['active' => null, 'draft' => null];

        $rows = $this->db->table('training_plans')
            ->where('user_id', (int) $user_id)
            ->in('status', ['active', 'draft'])
            ->order_by('id', 'DESC')
            ->get_all(PDO::FETCH_ASSOC);

        foreach ($rows ?: array() as $row) {
            if ($out[$row['status']] === null) {
                $out[$row['status']] = $this->with_sessions($row);
            }
        }

        if ($out['active'] !== null) {
            $out['active']['pending_revision'] = $this->pending_revision_for_plan(
                (int) $user_id,
                (int) $out['active']['plan']['id']
            );
        }

        return $out;
    }

    public function approve_for_user($user_id, $plan_id)
    {
        $user_id = (int) $user_id;
        $plan_id = (int) $plan_id;

        $draft = $this->db->table('training_plans')
            ->select('id, version')
            ->where('id', $plan_id)
            ->where('user_id', $user_id)
            ->where('status', 'draft')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$draft) {
            return null;
        }

        $now_utc = gmdate('Y-m-d H:i:s');

        $this->db->transaction();

        $this->db->table('training_plans')
            ->where('user_id', $user_id)
            ->where('status', 'active')
            ->update([
                'status'     => 'archived',
                'updated_at' => $now_utc,
            ]);

        $this->db->table('training_plans')
            ->where('id', $plan_id)
            ->where('user_id', $user_id)
            ->update([
                'status'      => 'active',
                'approved_at' => $now_utc,
                'updated_at'  => $now_utc,
            ]);

        $this->add_revision(
            $user_id,
            $plan_id,
            null,
            (int) $draft['version'],
            'Plan approved',
            null
        );

        $this->db->commit();

        return $this->find_for_user($user_id, $plan_id);
    }

    public function discard_draft_for_user($user_id, $plan_id)
    {
        $uid = (int) $user_id;
        $pid = (int) $plan_id;

        $draft = $this->db->table('training_plans')
            ->select('id')
            ->where('id', $pid)
            ->where('user_id', $uid)
            ->where('status', 'draft')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$draft) {
            return false;
        }

        $this->db->transaction();

        $this->db->table('training_sessions')
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->delete();

        $this->db->table('training_plans')
            ->where('id', $pid)
            ->where('user_id', $uid)
            ->where('status', 'draft')
            ->delete();

        $this->db->commit();

        return true;
    }

    public function mark_missed_for_user($user_id, ?DateTimeImmutable $today = null)
    {
        $ref    = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $cutoff = $ref->modify('-1 day')->format('Y-m-d');
        $uid    = (int) $user_id;

        $active_plans = $this->db->table('training_plans')
            ->select('id')
            ->where('user_id', $uid)
            ->where('status', 'active')
            ->get_all(PDO::FETCH_ASSOC);

        $active_plan_ids = array_map(
            'intval',
            array_column($active_plans ?: array(), 'id')
        );

        if (empty($active_plan_ids)) {
            return;
        }

        $this->db->table('training_sessions')
            ->where('user_id', $uid)
            ->where('status', 'planned')
            ->where('scheduled_date', '<', $cutoff)
            ->in('plan_id', $active_plan_ids)
            ->update([
                'status'     => 'missed',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    // Returns the updated session, null if not found, false if it is already completed.
    public function set_session_status($user_id, $session_id, $status, $skip_reason = null)
    {
        $uid = (int) $user_id;
        $sid = (int) $session_id;

        $row = $this->db->table('training_sessions')
            ->select(
                'training_sessions.id, training_sessions.plan_id, training_sessions.scheduled_date, ' .
                'training_sessions.type, training_sessions.status'
            )
            ->join(
                'training_plans',
                'training_plans.id = training_sessions.plan_id'
            )
            ->where('training_sessions.id', $sid)
            ->where('training_sessions.user_id', $uid)
            ->where('training_plans.status', 'active')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($row['status'] === 'completed') {
            return false;
        }

        $clean_reason = null;

        if ($status === 'skipped' && $skip_reason !== null && trim((string) $skip_reason) !== '') {
            $clean_reason = mb_substr(trim((string) $skip_reason), 0, 255);
        }

        $this->db->transaction();

        $this->db->table('training_sessions')
            ->where('id', $sid)
            ->where('user_id', $uid)
            ->where('status', '<>', 'completed')
            ->update([
                'status'     => $status,
                'skip_reason' => $clean_reason,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        $summary = $status === 'skipped'
            ? ('Skipped ' . $row['type'] . ' run on ' . $row['scheduled_date'])
            : ('Restored ' . $row['type'] . ' run on ' . $row['scheduled_date'] . ' to planned');

        $this->bump_version($uid, (int) $row['plan_id'], $summary, $clean_reason);
        $this->db->commit();

        $session = $this->db->table('training_sessions')
            ->select(
                'id, scheduled_date, planned_start_time, type, planned_distance_km, ' .
                'planned_duration_seconds, status, skip_reason, notes'
            )
            ->where('id', $sid)
            ->where('user_id', $uid)
            ->get(PDO::FETCH_ASSOC);

        return $this->present_session($session);
    }

    private function present_session(array $s)
    {
        return [
            'id'                       => (int) $s['id'],
            'scheduled_date'           => $s['scheduled_date'],
            'planned_start_time'       => $s['planned_start_time'] ?? null,
            'type'                     => $s['type'],
            'planned_distance_km'      => $s['planned_distance_km'] !== null ? (float) $s['planned_distance_km'] : null,
            'planned_duration_seconds' => $s['planned_duration_seconds'] !== null ? (int) $s['planned_duration_seconds'] : null,
            'status'                   => $s['status'],
            'skip_reason'              => $s['skip_reason'] ?? null,
            'notes'                    => $s['notes'],
        ];
    }

    // ---------- revisions ----------
    private function current_snapshot($user_id, $plan_id)
    {
        return $this->db->table('training_sessions')
            ->select(
                'id, scheduled_date, planned_start_time, type, planned_distance_km, ' .
                'planned_duration_seconds, status, skip_reason'
            )
            ->where('plan_id', (int) $plan_id)
            ->where('user_id', (int) $user_id)
            ->order_by('scheduled_date', 'ASC')
            ->get_all(PDO::FETCH_ASSOC) ?: [];
    }

    private function add_revision($user_id, $plan_id, $prev_version, $new_version, $summary, $reason = null)
    {
        $snapshot = $this->current_snapshot($user_id, $plan_id);
        $now_utc  = gmdate('Y-m-d H:i:s');
        $clean_r  = ($reason !== null && trim((string) $reason) !== '')
            ? mb_substr(trim((string) $reason), 0, 255)
            : null;

        $this->db->table('plan_revisions')->insert([
            'plan_id'            => (int) $plan_id,
            'user_id'            => (int) $user_id,
            'version'            => (int) $new_version,
            'previous_version'   => $prev_version !== null ? (int) $prev_version : null,
            'resulting_version'  => (int) $new_version,
            'decision'           => 'applied',
            'change_summary'     => mb_substr((string) $summary, 0, 255),
            'reason'             => $clean_r,
            'snapshot_json'      => json_encode($snapshot),
            'decision_timestamp' => $now_utc,
        ]);
    }

    private function bump_version($user_id, $plan_id, $summary, $reason = null)
    {
        $version_row = $this->db->table('training_plans')
            ->select('version')
            ->where('id', (int) $plan_id)
            ->where('user_id', (int) $user_id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        $prev = (int) ($version_row['version'] ?? 0);

        $this->db->table('training_plans')
            ->where('id', (int) $plan_id)
            ->where('user_id', (int) $user_id)
            ->increment('version');

        $this->db->table('training_plans')
            ->where('id', (int) $plan_id)
            ->where('user_id', (int) $user_id)
            ->update([
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        $next = $prev + 1;

        $this->add_revision(
            $user_id,
            $plan_id,
            $prev,
            $next,
            $summary,
            $reason
        );

        return $next;
    }

    private function insert_sessions($plan_id, $user_id, array $sessions)
    {
        if (!$sessions) {
            return;
        }

        $rows = [];

        foreach ($sessions as $session) {
            $rows[] = [
                'plan_id'                  => (int) $plan_id,
                'user_id'                  => (int) $user_id,
                'scheduled_date'           => $session['scheduled_date'],
                'planned_start_time'       => $session['planned_start_time'] ?? null,
                'type'                     => $session['type'],
                'planned_distance_km'      => $session['planned_distance_km'],
                'planned_duration_seconds' => $session['planned_duration_seconds'],
                'status'                   => 'planned',
                'notes'                    => $session['notes'],
            ];
        }

        $this->db->table('training_sessions')->bulk_insert($rows);
    }

    public function pending_revision_for_plan($user_id, $plan_id)
    {
        $row = $this->db->table('plan_revisions')
            ->select('id, previous_version, reason, proposed_changes, created_at')
            ->where('plan_id', (int) $plan_id)
            ->where('user_id', (int) $user_id)
            ->where('decision', 'pending')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $proposal = json_decode((string) ($row['proposed_changes'] ?? ''), true);

        if (!is_array($proposal)) {
            return null;
        }

        return $this->present_pending_proposal(
            (int) $row['id'],
            (int) ($row['previous_version'] ?? 1),
            $row['reason'] ?? null,
            $proposal,
            $row['created_at'] ?? null
        );
    }

    private function present_pending_proposal($rev_id, $prev_version, $reason, array $proposal, $created_at)
    {
        return [
            'id'                  => (int) $rev_id,
            'decision'            => 'pending',
            'previous_version'    => (int) $prev_version,
            'reason'              => $reason,
            'from_date'           => $proposal['from_date'] ?? null,
            'plan'                => $proposal['plan'] ?? null,
            'warnings'            => $proposal['warnings'] ?? [],
            'replaced_count'      => (int) ($proposal['replaced_count'] ?? 0),
            'preserved_completed' => (int) ($proposal['preserved_completed'] ?? 0),
            'proposed_count'      => (int) ($proposal['proposed_count'] ?? 0),
            'sessions'            => $proposal['sessions'] ?? [],
            'created_at'          => $created_at,
        ];
    }

    public function revisions_for_user($user_id, $plan_id)
    {
        $owned = $this->db->table('training_plans')
            ->select('id')
            ->where('id', (int) $plan_id)
            ->where('user_id', (int) $user_id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$owned) {
            return null;
        }

        $rows = $this->db->table('plan_revisions')
            ->select(
                'id, version, previous_version, resulting_version, decision, ' .
                'change_summary, reason, created_at, decision_timestamp'
            )
            ->where('plan_id', (int) $plan_id)
            ->where('user_id', (int) $user_id)
            ->order_by('id', 'DESC')
            ->get_all(PDO::FETCH_ASSOC);

        return array_map(fn($r) => [
            'id'                 => (int) $r['id'],
            'version'            => (int) $r['version'],
            'previous_version'   => $r['previous_version'] !== null ? (int) $r['previous_version'] : null,
            'resulting_version'  => $r['resulting_version'] !== null ? (int) $r['resulting_version'] : null,
            'decision'           => $r['decision'] ?? 'applied',
            'summary'            => $r['change_summary'],
            'reason'             => $r['reason'] ?? null,
            'created_at'         => $r['created_at'],
            'decision_timestamp' => $r['decision_timestamp'] ?? null,
        ], $rows ?: []);
    }

    // Returns [session, null] or [null, [http_status, message]].
    public function reschedule_session($user_id, $session_id, $new_date)
    {
        $uid = (int) $user_id;
        $utc = new DateTimeZone('UTC');

        $session = $this->db->table('training_sessions')
            ->select(
                'training_sessions.id, training_sessions.plan_id, training_sessions.scheduled_date, ' .
                'training_sessions.type, training_sessions.status, training_plans.goal, ' .
                'training_plans.start_date, training_plans.end_date'
            )
            ->join(
                'training_plans',
                'training_plans.id = training_sessions.plan_id'
            )
            ->where('training_sessions.id', (int) $session_id)
            ->where('training_sessions.user_id', $uid)
            ->where('training_plans.status', 'active')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$session) {
            return [null, [404, 'Session not found.']];
        }

        if ($session['status'] === 'completed') {
            return [null, [409, 'A completed run cannot be moved.']];
        }

        if ($session['type'] === 'race') {
            return [null, [422, 'Race day cannot be moved. Change your race date in your profile and rebuild the plan.']];
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $new_date, $utc);

        if (!$date || $date->format('Y-m-d') !== $new_date) {
            return [null, [422, 'Enter a valid date.']];
        }

        $min = max(
            (new DateTimeImmutable('today', $utc))->modify('-1 day')->format('Y-m-d'),
            $session['start_date']
        );

        $last = $session['goal'] === 'general_fitness'
            ? $session['end_date']
            : (new DateTimeImmutable($session['end_date'], $utc))->modify('-2 days')->format('Y-m-d');

        if ($new_date < $min || $new_date > $last) {
            return [null, [422, "Choose a date between $min and $last."]];
        }

        if ($new_date === $session['scheduled_date']) {
            return [null, [422, 'That is already the date of this session.']];
        }

        $busy = $this->db->table('training_sessions')
            ->select('id')
            ->where('plan_id', (int) $session['plan_id'])
            ->where('scheduled_date', $new_date)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if ($busy) {
            return [null, [422, 'Another session is already planned on that day.']];
        }

        $this->db->transaction();

        $this->db->table('training_sessions')
            ->where('id', (int) $session_id)
            ->where('user_id', $uid)
            ->update([
                'scheduled_date' => $new_date,
                'status'          => 'planned',
                'skip_reason'     => null,
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ]);

        $this->bump_version(
            $uid,
            (int) $session['plan_id'],
            'Moved ' . $session['type'] . ' run from ' . $session['scheduled_date'] . ' to ' . $new_date
        );

        $this->db->commit();

        $row = $this->db->table('training_sessions')
            ->select(
                'id, scheduled_date, planned_start_time, type, planned_distance_km, ' .
                'planned_duration_seconds, status, skip_reason, notes'
            )
            ->where('id', (int) $session_id)
            ->where('user_id', $uid)
            ->get(PDO::FETCH_ASSOC);

        return [$this->present_session($row), null];
    }

    /**
     * Step 1 of two-step rebuild (Spec D1 / Module 4): generate a validated
     * rebuild preview and store it as a `pending` revision WITHOUT mutating
     * live `training_sessions`.
     *
     * Returns [proposal, null], [null, 'not_found'], or [null, [field => msg]].
     */
        public function propose_rebuild($user_id, $plan_id, array $profile, DateTimeImmutable $today, $recent_long, $reason = null)
    {
        $uid = (int) $user_id;
        $pid = (int) $plan_id;

        $plan = $this->db->table('training_plans')
            ->select('id, version, creation_mode')
            ->where('id', $pid)
            ->where('user_id', $uid)
            ->where('status', 'active')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$plan || ($plan['creation_mode'] ?? 'generated') !== 'generated') {
            return [null, 'not_found'];
        }

        [$built, $errors] = $this->build(
            $profile,
            $today,
            ['rebuild' => true, 'start_long' => $recent_long]
        );

        if ($errors) {
            return [null, $errors];
        }

        $start = $built['plan']['start_date'];

        $completed_rows = $this->db->table('training_sessions')
            ->select('scheduled_date')
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->where('scheduled_date', '>=', $start)
            ->where('status', 'completed')
            ->get_all(PDO::FETCH_ASSOC);

        $taken = array_column($completed_rows ?: array(), 'scheduled_date');

        $replaced_row = $this->db->table('training_sessions')
            ->select_count('id', 'session_count')
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->where('scheduled_date', '>=', $start)
            ->where('status', '<>', 'completed')
            ->get(PDO::FETCH_ASSOC);

        $replaced_count = (int) ($replaced_row['session_count'] ?? 0);

        $proposed_sessions = array_values(array_filter(
            $built['sessions'],
            fn($session) => !in_array($session['scheduled_date'], $taken, true)
        ));

        $clean_reason = ($reason !== null && trim((string) $reason) !== '')
            ? mb_substr(trim((string) $reason), 0, 255)
            : null;

        $proposal_payload = [
            'from_date'           => $start,
            'plan'                => $built['plan'],
            'warnings'            => $built['warnings'],
            'replaced_count'      => $replaced_count,
            'preserved_completed' => count($taken),
            'proposed_count'      => count($proposed_sessions),
            'sessions'            => $proposed_sessions,
        ];

        $now_utc  = gmdate('Y-m-d H:i:s');
        $cur_ver  = (int) $plan['version'];
        $snapshot = $this->current_snapshot($uid, $pid);
        $summary  = 'Proposed rebuild from ' . $start . ' (' . count($proposed_sessions) . ' upcoming sessions)';

        $this->db->transaction();

        // Supersede any older pending proposal on this plan.
        $this->db->table('plan_revisions')
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->where('decision', 'pending')
            ->update([
                'decision'           => 'rejected',
                'decision_timestamp' => $now_utc,
            ]);

        $this->db->table('plan_revisions')->insert([
            'plan_id'            => $pid,
            'user_id'            => $uid,
            'version'            => $cur_ver,
            'previous_version'   => $cur_ver,
            'resulting_version'  => null,
            'decision'           => 'pending',
            'change_summary'     => mb_substr($summary, 0, 255),
            'reason'             => $clean_reason,
            'snapshot_json'      => json_encode($snapshot),
            'proposed_changes'   => json_encode($proposal_payload),
            'decision_timestamp' => null,
            'created_at'         => $now_utc,
        ]);

        $rev_id = (int) $this->db->last_id();
        $this->db->commit();

        return [
            $this->present_pending_proposal(
                $rev_id,
                $cur_ver,
                $clean_reason,
                $proposal_payload,
                $now_utc
            ),
            null,
        ];
    }

    /**
     * Step 2a of two-step rebuild: apply a `pending` revision proposal, bump
     * plan version, preserve completed runs, and return the updated plan.
     *
     * Returns [data, null] or [null, 'not_found'].
     */
        public function apply_revision($user_id, $plan_id, $revision_id)
    {
        $uid = (int) $user_id;
        $pid = (int) $plan_id;
        $rid = (int) $revision_id;

        $plan = $this->db->table('training_plans')
            ->select('id, version, creation_mode')
            ->where('id', $pid)
            ->where('user_id', $uid)
            ->where('status', 'active')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$plan || ($plan['creation_mode'] ?? 'generated') !== 'generated') {
            return [null, 'not_found'];
        }

        $revision = $this->db->table('plan_revisions')
            ->select('id, proposed_changes')
            ->where('id', $rid)
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->where('decision', 'pending')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$revision) {
            return [null, 'not_found'];
        }

        $proposal = json_decode((string) ($revision['proposed_changes'] ?? ''), true);

        if (!is_array($proposal) || empty($proposal['plan']) || !isset($proposal['sessions'])) {
            return [null, 'not_found'];
        }

        $start    = (string) $proposal['from_date'];
        $new_plan = $proposal['plan'];

        // Re-check completed dates at apply time so runs logged after preview stay protected.
        $completed_rows = $this->db->table('training_sessions')
            ->select('scheduled_date')
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->where('scheduled_date', '>=', $start)
            ->where('status', 'completed')
            ->get_all(PDO::FETCH_ASSOC);

        $taken = array_column($completed_rows ?: array(), 'scheduled_date');

        $sessions = array_values(array_filter(
            $proposal['sessions'],
            fn($session) => !in_array($session['scheduled_date'], $taken, true)
        ));

        $prev_ver = (int) $plan['version'];
        $next_ver = $prev_ver + 1;
        $now_utc  = gmdate('Y-m-d H:i:s');

        $this->db->transaction();

        $this->db->table('training_sessions')
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->where('scheduled_date', '>=', $start)
            ->where('status', '<>', 'completed')
            ->delete();

        $this->insert_sessions($pid, $uid, $sessions);

        $this->db->table('training_plans')
            ->where('id', $pid)
            ->where('user_id', $uid)
            ->update([
                'goal'              => $new_plan['goal'],
                'end_date'          => $new_plan['end_date'],
                'sessions_per_week' => (int) $new_plan['sessions_per_week'],
                'version'           => $next_ver,
                'updated_at'        => $now_utc,
            ]);

        $after_snapshot = $this->current_snapshot($uid, $pid);
        $summary = 'Applied rebuild from ' . $start . ' (' . count($sessions) . ' upcoming sessions)';

        $this->db->table('plan_revisions')
            ->where('id', $rid)
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->update([
                'version'            => $next_ver,
                'previous_version'   => $prev_ver,
                'resulting_version'  => $next_ver,
                'decision'           => 'applied',
                'change_summary'     => mb_substr($summary, 0, 255),
                'snapshot_json'      => json_encode($after_snapshot),
                'decision_timestamp' => $now_utc,
            ]);

        $this->db->commit();

        $data = $this->find_for_user($uid, $pid);
        $data['warnings'] = $proposal['warnings'] ?? [];

        return [$data, null];
    }

    /**
     * Step 2b of two-step rebuild: cancel a pending revision proposal without
     * altering any training sessions.
     */
    public function reject_revision($user_id, $plan_id, $revision_id)
    {
        $uid = (int) $user_id;
        $pid = (int) $plan_id;
        $rid = (int) $revision_id;
        $now_utc = gmdate('Y-m-d H:i:s');

        $updated = $this->db->table('plan_revisions')
            ->where('id', $rid)
            ->where('plan_id', $pid)
            ->where('user_id', $uid)
            ->where('decision', 'pending')
            ->update([
                'decision'           => 'rejected',
                'decision_timestamp' => $now_utc,
            ]);

        if ((int) $updated !== 1) {
            return null;
        }

        return [
            'id'                 => $rid,
            'decision'           => 'rejected',
            'decision_timestamp' => $now_utc,
        ];
    }

    // Legacy immediate helper (kept compatible via propose + apply).
    public function rebuild_remaining($user_id, $plan_id, array $profile, DateTimeImmutable $today, $recent_long, $reason = null)
    {
        [$proposal, $err] = $this->propose_rebuild(
            $user_id,
            $plan_id,
            $profile,
            $today,
            $recent_long,
            $reason
        );

        if ($err) {
            return [null, $err];
        }

        return $this->apply_revision($user_id, $plan_id, $proposal['id']);
    }

        private function with_sessions(array $r)
    {
        $sessions = $this->db->table('training_sessions')
            ->select(
                'id, scheduled_date, planned_start_time, type, planned_distance_km, ' .
                'planned_duration_seconds, status, skip_reason, notes'
            )
            ->where('plan_id', (int) $r['id'])
            ->where('user_id', (int) $r['user_id'])
            ->order_by('scheduled_date', 'ASC')
            ->get_all(PDO::FETCH_ASSOC);

        return [
            'plan' => [
                'id'                => (int) $r['id'],
                'goal'              => $r['goal'],
                'start_date'        => $r['start_date'],
                'end_date'          => $r['end_date'],
                'sessions_per_week' => (int) $r['sessions_per_week'],
                'creation_mode'     => $r['creation_mode'] ?? 'generated',
                'status'            => $r['status'],
                'version'           => (int) $r['version'],
                'approved_at'       => $r['approved_at'] ?? null,
            ],
            'sessions' => array_map([$this, 'present_session'], $sessions ?: array()),
        ];
    }
}