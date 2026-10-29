<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: DashboardModel
 *
 * First-party performance analytics and deterministic metric summaries used by
 * both GET /api/dashboard and the Gemini AI training feedback service.
 *
 * Spec 5.5 / TC-10 / TC-15 boundary: every query on `activities` filters
 * `source = 'manual'` so external/restricted records can never enter
 * RunPlan analytics or AI coaching prompts.
 */
class DashboardModel extends Model
{
    protected $table = 'activities';
    protected $primary_key = 'id';

    const ELIGIBLE_SOURCE = 'manual';

    public function summary($user_id, DateTimeImmutable $today)
    {
        $uid = (int) $user_id;
        $t   = $today->format('Y-m-d');
        $ago = fn($n) => $today->modify("-{$n} days")->format('Y-m-d');

        return [
            'today'     => $t,
            'last7'     => $this->window($uid, $ago(6), $t),
            'previous7' => $this->window($uid, $ago(13), $ago(7)),
            'plan'      => $this->plan_block($uid, $today),
            'weeks'     => $this->weekly($uid, $today),
            'recent'    => $this->recent($uid),
        ];
    }

    public function coaching_snapshot($user_id, DateTimeImmutable $today, $days = 28)
    {
        $uid        = (int) $user_id;
        $days       = max(14, min(90, (int) $days));
        $half       = intdiv($days, 2);
        $end_str    = $today->format('Y-m-d');
        $start_str  = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $mid_end    = $today->modify('-' . $half . ' days')->format('Y-m-d');
        $recent_beg = $today->modify('-' . ($half - 1) . ' days')->format('Y-m-d');

        $overall = $this->window($uid, $start_str, $end_str);
        $prior   = $this->window($uid, $start_str, $mid_end);
        $recent  = $this->window($uid, $recent_beg, $end_str);

        $pace_trend     = 'insufficient_data';
        $pace_delta_sec = null;

        if ($prior['runs'] >= 2
            && $recent['runs'] >= 2
            && $prior['pace_seconds_per_km'] !== null
            && $recent['pace_seconds_per_km'] !== null
        ) {
            $pace_delta_sec = $recent['pace_seconds_per_km'] - $prior['pace_seconds_per_km'];

            if ($pace_delta_sec <= -8) {
                $pace_trend = 'faster';
            } elseif ($pace_delta_sec >= 8) {
                $pace_trend = 'slower';
            } else {
                $pace_trend = 'steady';
            }
        }

        // Scheduled session adherence within the analyzed period on active plan.
        $adherence = [
            'has_active_plan'   => false,
            'goal'              => null,
            'scheduled_due'     => 0,
            'completed'         => 0,
            'skipped'           => 0,
            'missed'            => 0,
            'completion_rate'   => null,
            'skipped_treatment' => 'Skipped sessions remain in the scheduled-due denominator (completion_rate = completed / scheduled_due * 100) so deliberate skips are distinguished from unlogged missed runs without inflating adherence.',
        ];

        $plan = $this->db->table('training_plans')
            ->select('id, goal')
            ->where('user_id', $uid)
            ->where('status', 'active')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if ($plan) {
            $sessions = $this->db->table('training_sessions')
                ->select('status, scheduled_date')
                ->where('plan_id', (int) $plan['id'])
                ->where('user_id', $uid)
                ->where('scheduled_date', '>=', $start_str)
                ->get_all(PDO::FETCH_ASSOC);

            $due       = 0;
            $completed = 0;
            $skipped   = 0;
            $missed    = 0;

            foreach ($sessions ?: array() as $session) {
                $session_status = (string) ($session['status'] ?? '');
                $scheduled_date = (string) ($session['scheduled_date'] ?? '');

                if ($scheduled_date <= $end_str || $session_status === 'completed') {
                    $due++;

                    if ($session_status === 'completed') {
                        $completed++;
                    } elseif ($session_status === 'skipped') {
                        $skipped++;
                    } elseif ($session_status === 'missed') {
                        $missed++;
                    }
                }
            }

            $adherence = [
                'has_active_plan'   => true,
                'goal'              => $plan['goal'],
                'scheduled_due'     => $due,
                'completed'         => $completed,
                'skipped'           => $skipped,
                'missed'            => $missed,
                'completion_rate'   => $due > 0 ? (int) round(100 * $completed / $due) : null,
                'skipped_treatment' => $adherence['skipped_treatment'],
            ];
        }

        return [
            'period_start'    => $start_str,
            'period_end'      => $end_str,
            'window_days'     => $days,
            'eligible_source' => self::ELIGIBLE_SOURCE,
            'totals'          => $overall,
            'pace_comparison' => [
                'prior_half'   => $prior,
                'recent_half'  => $recent,
                'trend'        => $pace_trend,
                'delta_sec_km' => $pace_delta_sec,
            ],
            'adherence'       => $adherence,
        ];
    }

    private function pace($seconds, $km)
    {
        if ($km <= 0 || $seconds <= 0) {
            return [null, null];
        }

        $p = (int) round($seconds / $km);

        return [$p, sprintf('%d:%02d', intdiv($p, 60), $p % 60)];
    }

    private function window($uid, $from, $to)
    {
        $r = $this->db->table('activities')
            ->select_count('id', 'runs')
            ->select_sum('distance_km', 'km')
            ->select_sum('duration_seconds', 'sec')
            ->where('user_id', $uid)
            ->where('source', self::ELIGIBLE_SOURCE)
            ->between('activity_date', $from, $to)
            ->get(PDO::FETCH_ASSOC);

        $km  = (float) ($r['km'] ?? 0);
        $sec = (int) ($r['sec'] ?? 0);
        [$pace, $label] = $this->pace($sec, $km);

        return [
            'runs'                => (int) ($r['runs'] ?? 0),
            'distance_km'         => round($km, 1),
            'duration_seconds'    => $sec,
            'pace_seconds_per_km' => $pace,
            'pace_label'          => $label,
        ];
    }

    private function plan_block($uid, DateTimeImmutable $today)
    {
        $plan = $this->db->table('training_plans')
            ->select('id, goal, start_date, end_date')
            ->where('user_id', $uid)
            ->where('status', 'active')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$plan) {
            return null;
        }

        $t = $today->format('Y-m-d');

        $sessions = $this->db->table('training_sessions')
            ->select('status, scheduled_date')
            ->where('plan_id', (int) $plan['id'])
            ->where('user_id', $uid)
            ->get_all(PDO::FETCH_ASSOC);

        $due       = 0;
        $completed = 0;
        $skipped   = 0;
        $missed    = 0;

        foreach ($sessions ?: array() as $session) {
            $session_status = (string) ($session['status'] ?? '');
            $scheduled_date = (string) ($session['scheduled_date'] ?? '');

            if ($scheduled_date < $t || $session_status === 'completed') {
                $due++;

                if ($session_status === 'completed') {
                    $completed++;
                } elseif ($session_status === 'skipped') {
                    $skipped++;
                } elseif ($session_status === 'missed') {
                    $missed++;
                }
            }
        }

        $next = $this->db->table('training_sessions')
            ->select('id, scheduled_date, type, planned_distance_km, planned_duration_seconds, notes')
            ->where('plan_id', (int) $plan['id'])
            ->where('user_id', $uid)
            ->where('scheduled_date', '>=', $t)
            ->where('status', 'planned')
            ->order_by('scheduled_date', 'ASC')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($plan['start_date'], $utc);
        $end   = new DateTimeImmutable($plan['end_date'], $utc);
        $total = intdiv((int) $start->diff($end)->days, 7) + 1;
        $since = (int) $start->diff($today)->format('%r%a');

        return [
            'id'              => (int) $plan['id'],
            'goal'            => $plan['goal'],
            'start_date'      => $plan['start_date'],
            'end_date'        => $plan['end_date'],
            'week'            => $since < 0 ? 0 : min($total, intdiv($since, 7) + 1),
            'total_weeks'     => $total,
            'days_left'       => (int) $today->diff($end)->format('%r%a'),
            'due'             => $due,
            'completed'       => $completed,
            'skipped'         => $skipped,
            'missed'          => $missed,
            'completion_rate' => $due > 0 ? (int) round(100 * $completed / $due) : null,
            'next_session'    => $next ? [
                'id'                       => (int) $next['id'],
                'scheduled_date'           => $next['scheduled_date'],
                'type'                     => $next['type'],
                'planned_distance_km'      => (float) $next['planned_distance_km'],
                'planned_duration_seconds' => (int) $next['planned_duration_seconds'],
                'notes'                    => $next['notes'],
            ] : null,
        ];
    }

    private function weekly($uid, DateTimeImmutable $today)
    {
        $utc  = new DateTimeZone('UTC');
        $t    = $today->format('Y-m-d');
        $from = $today->modify('-55 days')->format('Y-m-d');

        $actual_km  = [];
        $actual_sec = [];
        $actual_cnt = [];

        $rows = $this->db->table('activities')
            ->select('activity_date, distance_km, duration_seconds')
            ->where('user_id', $uid)
            ->where('source', self::ELIGIBLE_SOURCE)
            ->between('activity_date', $from, $t)
            ->get_all(PDO::FETCH_ASSOC);

        foreach ($rows ?: array() as $r) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $r['activity_date'], $utc);

            if (!$d) {
                continue;
            }

            $b = intdiv((int) $d->diff($today)->days, 7);

            if ($b < 0 || $b > 7) {
                continue;
            }

            $actual_km[$b]  = ($actual_km[$b] ?? 0.0) + (float) $r['distance_km'];
            $actual_sec[$b] = ($actual_sec[$b] ?? 0) + (int) $r['duration_seconds'];
            $actual_cnt[$b] = ($actual_cnt[$b] ?? 0) + 1;
        }

        $planned = [];

        $rows = $this->db->table('training_sessions')
            ->select('training_sessions.scheduled_date, training_sessions.planned_distance_km')
            ->join(
                'training_plans',
                'training_plans.id = training_sessions.plan_id'
            )
            ->where('training_sessions.user_id', $uid)
            ->where('training_plans.status', 'active')
            ->where('training_sessions.status', '<>', 'skipped')
            ->between('training_sessions.scheduled_date', $from, $t)
            ->get_all(PDO::FETCH_ASSOC);

        foreach ($rows ?: array() as $r) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $r['scheduled_date'], $utc);

            if (!$d) {
                continue;
            }

            $b = intdiv((int) $d->diff($today)->days, 7);

            if ($b >= 0 && $b <= 7) {
                $planned[$b] = ($planned[$b] ?? 0.0) + (float) $r['planned_distance_km'];
            }
        }

        $weeks = [];

        for ($i = 7; $i >= 0; $i--) {
            $km  = $actual_km[$i] ?? 0.0;
            $sec = $actual_sec[$i] ?? 0;
            [$pace_sec, $pace_lbl] = $this->pace($sec, $km);

            $weeks[] = [
                'start'                      => $today->modify('-' . (6 + 7 * $i) . ' days')->format('Y-m-d'),
                'end'                        => $today->modify('-' . (7 * $i) . ' days')->format('Y-m-d'),
                'actual_km'                  => round($km, 1),
                'planned_km'                 => round($planned[$i] ?? 0.0, 1),
                'runs'                       => $actual_cnt[$i] ?? 0,
                'actual_duration_seconds'    => $sec,
                'actual_pace_seconds_per_km' => $pace_sec,
                'actual_pace_label'          => $pace_lbl,
            ];
        }

        return $weeks;
    }

    private function recent($uid)
    {
        $rows = $this->db->table('activities')
            ->select('id, activity_date, distance_km, duration_seconds, effort, session_id')
            ->where('user_id', $uid)
            ->where('source', self::ELIGIBLE_SOURCE)
            ->get_all(PDO::FETCH_ASSOC);

        $rows = $rows ?: array();

        // Sort by activity date and then id to match the original ordering.
        usort($rows, static function ($left, $right) {
            $date_order = strcmp(
                (string) ($right['activity_date'] ?? ''),
                (string) ($left['activity_date'] ?? '')
            );

            if ($date_order !== 0) {
                return $date_order;
            }

            return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
        });

        $rows = array_slice($rows, 0, 5);

        return array_map(function ($r) {
            [, $label] = $this->pace(
                (int) $r['duration_seconds'],
                (float) $r['distance_km']
            );

            return [
                'id'               => (int) $r['id'],
                'activity_date'    => $r['activity_date'],
                'distance_km'      => (float) $r['distance_km'],
                'duration_seconds' => (int) $r['duration_seconds'],
                'pace_label'       => $label,
                'effort'           => $r['effort'] !== null ? (int) $r['effort'] : null,
                'from_plan'        => $r['session_id'] !== null,
            ];
        }, $rows);
    }
}