<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once dirname(__DIR__) . '/libraries/GeminiService.php';

/**
 * Model: AiCoachingModel
 *
 * Persists weekly coaching feedback and AI request audit rows.
 * Interactive chat receives only whitelisted first-party training data.
 */
class AiCoachingModel extends Model
{
    protected $table       = 'ai_feedback';
    protected $primary_key = 'id';

    /** @var bool|null true = tables verified, false = schema unusable */
    private static $schema_ok = null;

    /**
     * Verifies and creates AI audit tables when needed.
     */
    private function ensure_tables()
    {
        if (self::$schema_ok === true) {
            return;
        }

        self::$schema_ok = false;

        try {
            $this->call->dbforge();
            $forge = lava_instance()->properties['dbforge'];

            $has_plan_requests = $forge->table_exists('ai_plan_requests');
            $has_feedback      = $forge->table_exists('ai_feedback');
            $has_chat_requests = $forge->table_exists('ai_chat_requests');

            if ($has_plan_requests && $has_feedback && $has_chat_requests) {
                self::$schema_ok = true;
                return;
            }

            // DBForge prints a success message; buffer it to keep API responses clean.
            $buffer_level = ob_get_level();
            ob_start();

            try {
                if (!$has_plan_requests) {
                    $forge->add_field(array(
                        'id' => array(
                            'type'           => 'INT',
                            'constraint'     => 11,
                            'unsigned'       => true,
                            'auto_increment' => true,
                            'null'           => false,
                        ),
                        'user_id' => array(
                            'type'       => 'INT',
                            'constraint' => 11,
                            'unsigned'   => true,
                            'null'       => false,
                        ),
                        'plan_id' => array(
                            'type'       => 'INT',
                            'constraint' => 11,
                            'unsigned'   => true,
                            'null'       => true,
                            'default'    => null,
                        ),
                        'provider' => array(
                            'type'       => 'VARCHAR',
                            'constraint' => 30,
                            'null'       => false,
                        ),
                        'status' => array(
                            'type'       => 'ENUM',
                            'constraint' => "'pending','succeeded','failed','fallback'",
                            'null'       => false,
                            'default'    => 'pending',
                        ),
                        'request_summary' => array(
                            'type'       => 'VARCHAR',
                            'constraint' => 500,
                            'null'       => true,
                            'default'    => null,
                        ),
                        'created_at' => array(
                            'type'    => 'DATETIME',
                            'null'    => false,
                            'default' => 'CURRENT_TIMESTAMP',
                        ),
                    ))
                        ->add_key('id', true)
                        ->add_key(
                            array('user_id', 'created_at'),
                            false,
                            false,
                            'user_created_idx'
                        )
                        ->add_key('plan_id', false, false, 'plan_id_idx')
                        ->create_table('ai_plan_requests', true);
                }

                if (!$has_feedback) {
                    $forge->add_field(array(
                        'id' => array(
                            'type'           => 'INT',
                            'constraint'     => 11,
                            'unsigned'       => true,
                            'auto_increment' => true,
                            'null'           => false,
                        ),
                        'user_id' => array(
                            'type'       => 'INT',
                            'constraint' => 11,
                            'unsigned'   => true,
                            'null'       => false,
                        ),
                        'period_start' => array(
                            'type' => 'DATE',
                            'null' => false,
                        ),
                        'period_end' => array(
                            'type' => 'DATE',
                            'null' => false,
                        ),
                        'metrics_snapshot' => array(
                            'type' => 'JSON',
                            'null' => false,
                        ),
                        'feedback_text' => array(
                            'type' => 'TEXT',
                            'null' => true,
                        ),
                        'provider_status' => array(
                            'type'       => 'VARCHAR',
                            'constraint' => 30,
                            'null'       => false,
                            'default'    => 'ok',
                        ),
                        'created_at' => array(
                            'type'    => 'DATETIME',
                            'null'    => false,
                            'default' => 'CURRENT_TIMESTAMP',
                        ),
                    ))
                        ->add_key('id', true)
                        ->add_key(
                            array('user_id', 'period_start'),
                            false,
                            true,
                            'user_period_unique'
                        )
                        ->create_table('ai_feedback', true);
                }

                if (!$has_chat_requests) {
                    $forge->add_field(array(
                        'id' => array(
                            'type'           => 'INT',
                            'constraint'     => 11,
                            'unsigned'       => true,
                            'auto_increment' => true,
                            'null'           => false,
                        ),
                        'user_id' => array(
                            'type'       => 'INT',
                            'constraint' => 11,
                            'unsigned'   => true,
                            'null'       => false,
                        ),
                        'provider' => array(
                            'type'       => 'VARCHAR',
                            'constraint' => 30,
                            'null'       => false,
                        ),
                        'status' => array(
                            'type'       => 'ENUM',
                            'constraint' => "'pending','succeeded','failed'",
                            'null'       => false,
                            'default'    => 'pending',
                        ),
                        'request_summary' => array(
                            'type'       => 'VARCHAR',
                            'constraint' => 120,
                            'null'       => false,
                        ),
                        'created_at' => array(
                            'type'    => 'DATETIME',
                            'null'    => false,
                            'default' => 'CURRENT_TIMESTAMP',
                        ),
                    ))
                        ->add_key('id', true)
                        ->add_key(
                            array('provider', 'status', 'created_at'),
                            false,
                            false,
                            'provider_status_created_idx'
                        )
                        ->add_key(
                            array('user_id', 'created_at'),
                            false,
                            false,
                            'user_created_idx'
                        )
                        ->create_table('ai_chat_requests', true);
                }
            } finally {
                while (ob_get_level() > $buffer_level) {
                    ob_end_clean();
                }
            }

            self::$schema_ok =
                $forge->table_exists('ai_plan_requests')
                && $forge->table_exists('ai_feedback')
                && $forge->table_exists('ai_chat_requests');
        } catch (Throwable $e) {
            self::$schema_ok = false;
        }
    }

    public function latest_feedback_for_user($user_id)
    {
        $this->ensure_tables();

        if (self::$schema_ok !== true) {
            return null;
        }

        try {
            $row = $this->db->table('ai_feedback')
                ->select(
                    'id, period_start, period_end, metrics_snapshot, feedback_text, provider_status, created_at'
                )
                ->where('user_id', (int) $user_id)
                ->order_by('period_start', 'DESC')
                ->limit(1)
                ->get(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }

        return $row ? $this->present_feedback($row) : null;
    }

    /**
     * Answers one question using the runner's own training data.
     *
     * Question text and AI response text are not persisted.
     */
    public function answer_training_question($user_id, $question, ?DateTimeImmutable $today = null, ?GeminiService $service = null)
    {
        $this->ensure_tables();

        if (self::$schema_ok !== true) {
            return array(
                'text'            => null,
                'provider_status' => 'audit_unavailable',
                'error'           => 'AI request tracking is unavailable. No external request was sent.',
            );
        }

        $client = $service ?: new GeminiService();

        if (!$client->is_ready()) {
            return array(
                'text'            => null,
                'provider_status' => 'not_configured',
                'error'           => 'Gemini is disabled or not configured.',
            );
        }

        $ref = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));

        if ($this->monthly_calls_used($ref) >= $client->monthly_cap()) {
            return array(
                'text'            => null,
                'provider_status' => 'cap_reached',
                'error'           => 'The monthly AI request limit has been reached.',
            );
        }

        $request_id = $this->start_chat_request((int) $user_id);

        if (!$request_id) {
            return array(
                'text'            => null,
                'provider_status' => 'audit_unavailable',
                'error'           => 'The AI request could not be recorded, so no external request was sent.',
            );
        }

        $context = $this->build_chat_context((int) $user_id, $ref);
        $result  = $client->answer_training_question($context, $question);

        $call_status = !empty($result['ok']) ? 'succeeded' : 'failed';
        $this->finish_chat_request($request_id, (int) $user_id, $call_status);

        return array(
            'text'            => $result['text'] ?? null,
            'provider_status' => $result['provider_status'] ?? 'fallback',
            'error'           => $result['error'] ?? null,
        );
    }

    /**
     * Collects a bounded set of non-identifying details for interactive chat.
     * Manual runs only are included. Names, email addresses and run notes are excluded.
     */
    private function build_chat_context($user_id, DateTimeImmutable $today)
    {
        $uid     = (int) $user_id;
        $context = array();

        try {
            $profile = $this->db->table('runner_profiles')
                ->select('goal, experience')
                ->where('user_id', $uid)
                ->limit(1)
                ->get(PDO::FETCH_ASSOC);

            if ($profile) {
                $context['runner'] = array(
                    'goal'       => $profile['goal'] ?? null,
                    'experience' => $profile['experience'] ?? null,
                );
            }
        } catch (Throwable $e) {
            // The remaining available context can still be used.
        }

        try {
            $context['weekly_metrics'] = $this->build_weekly_metrics($uid, $today);
        } catch (Throwable $e) {
            // Continue with recent runs and plan data if metrics are unavailable.
        }

        try {
            $rows = $this->db->table('activities')
                ->select('activity_date, distance_km, duration_seconds, effort')
                ->where('user_id', $uid)
                ->where('source', 'manual')
                ->order_by('activity_date', 'DESC')
                ->order_by('id', 'DESC')
                ->limit(12)
                ->get_all(PDO::FETCH_ASSOC);

            $recent_runs = array();

            foreach ($rows ?: array() as $row) {
                $distance = (float) ($row['distance_km'] ?? 0);
                $duration = (int) ($row['duration_seconds'] ?? 0);

                $run = array(
                    'activity_date'    => $row['activity_date'] ?? null,
                    'distance_km'      => $distance,
                    'duration_seconds' => $duration,
                );

                if ($distance > 0 && $duration > 0) {
                    $run['pace_seconds_per_km'] = (int) round($duration / $distance);
                }

                if (isset($row['effort']) && $row['effort'] !== null) {
                    $run['effort'] = (int) $row['effort'];
                }

                $recent_runs[] = $run;
            }

            $context['recent_runs'] = $recent_runs;
        } catch (Throwable $e) {
            // Continue with profile or plan context if activity history is unavailable.
        }

        try {
            $plan = $this->db->table('training_plans')
                ->select('id, goal, start_date, end_date, sessions_per_week, creation_mode, version')
                ->where('user_id', $uid)
                ->where('status', 'active')
                ->order_by('version', 'DESC')
                ->limit(1)
                ->get(PDO::FETCH_ASSOC);

            if ($plan) {
                $context['active_plan'] = array(
                    'goal'              => $plan['goal'] ?? null,
                    'start_date'        => $plan['start_date'] ?? null,
                    'end_date'          => $plan['end_date'] ?? null,
                    'sessions_per_week' => $plan['sessions_per_week'] ?? null,
                    'creation_mode'     => $plan['creation_mode'] ?? null,
                    'version'           => $plan['version'] ?? null,
                );

                $sessions = $this->db->table('training_sessions')
                    ->select('scheduled_date, type, planned_distance_km, status')
                    ->where('user_id', $uid)
                    ->where('plan_id', (int) $plan['id'])
                    ->where('status', 'planned')
                    ->where('scheduled_date', '>=', $today->format('Y-m-d'))
                    ->order_by('scheduled_date', 'ASC')
                    ->limit(14)
                    ->get_all(PDO::FETCH_ASSOC);

                $context['upcoming_sessions'] = $sessions ?: array();
            }
        } catch (Throwable $e) {
            // Continue without plan information if the plan schema differs.
        }

        return $context;
    }

    private function start_chat_request($user_id)
    {
        try {
            $this->db->table('ai_chat_requests')->insert(array(
                'user_id'         => (int) $user_id,
                'provider'        => 'gemini',
                'status'          => 'pending',
                'request_summary' => 'interactive_training_chat',
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ));

            return (int) $this->db->last_id();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function finish_chat_request($request_id, $user_id, $status)
    {
        if (!in_array($status, array('succeeded', 'failed'), true)) {
            return;
        }

        try {
            $this->db->table('ai_chat_requests')
                ->where('id', (int) $request_id)
                ->where('user_id', (int) $user_id)
                ->update(array(
                    'status' => $status,
                ));
        } catch (Throwable $e) {
            // The pending row still counts against the monthly cap.
        }
    }

    public function generate_weekly_feedback($user_id, ?DateTimeImmutable $today = null, ?GeminiService $service = null)
    {
        $this->ensure_tables();

        $uid     = (int) $user_id;
        $ref     = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client  = $service ?: new GeminiService();
        $metrics = $this->build_weekly_metrics($uid, $ref);

        if (!$client->is_ready()) {
            $text   = $this->deterministic_feedback($metrics);
            $status = 'not_configured';
        } elseif ($this->monthly_calls_used($ref) >= $client->monthly_cap()) {
            $text   = $this->deterministic_feedback($metrics);
            $status = 'cap_reached';
        } else {
            $res = $client->generate_weekly_feedback($metrics);

            if ($res['ok'] && !empty($res['text'])) {
                $text   = $res['text'];
                $status = 'ok';
            } else {
                $text   = $this->deterministic_feedback($metrics);
                $status = 'fallback';
            }
        }

        return $this->persist_or_degrade(
            $uid,
            $metrics,
            $text,
            $status
        );
    }

    /**
     * Persists the review, or returns it unsaved when the database cannot store it.
     */
    private function persist_or_degrade($user_id, array $metrics, $text, $provider_status)
    {
        try {
            return $this->save_feedback(
                $user_id,
                $metrics['period_start'],
                $metrics['period_end'],
                $metrics,
                $text,
                $provider_status
            );
        } catch (Throwable $e) {
            return array(
                'id'              => null,
                'period_start'    => $metrics['period_start'],
                'period_end'      => $metrics['period_end'],
                'metrics'         => $metrics,
                'feedback_text'   => $text,
                'provider_status' => $provider_status,
                'persisted'       => false,
                'persist_error'   => $this->describe_error($e),
                'created_at'      => gmdate('Y-m-d H:i:s'),
            );
        }
    }

    /**
     * A short, non-secret reason for a failed write.
     * Only surfaced while APP_ENV=development.
     */
    private function describe_error(Throwable $e)
    {
        $env = function_exists('env')
            ? (string) env('APP_ENV', 'development')
            : 'development';

        if (strtolower($env) !== 'development') {
            return null;
        }

        return mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 240);
    }

    /**
     * Records a plan generation audit entry and returns a concise coaching brief.
     */
    public function record_plan_generation($user_id, $plan_id, array $profile, array $built, ?DateTimeImmutable $today = null, ?GeminiService $service = null)
    {
        $uid    = (int) $user_id;
        $pid    = $plan_id !== null ? (int) $plan_id : null;
        $ref    = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client = $service ?: new GeminiService();

        $sessions = $built['sessions'] ?? array();
        $total_km = 0.0;
        $peak_km  = 0.0;

        foreach ($sessions as $session) {
            $km = (float) ($session['planned_distance_km'] ?? 0);
            $total_km += $km;

            if (($session['type'] ?? '') === 'long' && $km > $peak_km) {
                $peak_km = $km;
            }
        }

        $start_dt = new DateTimeImmutable($built['plan']['start_date'], new DateTimeZone('UTC'));
        $end_dt   = new DateTimeImmutable($built['plan']['end_date'], new DateTimeZone('UTC'));
        $weeks    = max(1, (int) ceil(((int) $start_dt->diff($end_dt)->days + 1) / 7));

        $summary = array(
            'goal'              => $built['plan']['goal'],
            'experience'        => $profile['experience'] ?? 'intermediate',
            'start_date'        => $built['plan']['start_date'],
            'end_date'          => $built['plan']['end_date'],
            'weeks'             => $weeks,
            'sessions_per_week' => (int) $built['plan']['sessions_per_week'],
            'total_km'          => round($total_km, 1),
            'peak_long_km'      => round($peak_km, 1),
        );

        $summary_line = sprintf(
            'goal=%s weeks=%d runs_wk=%d total_km=%.1f',
            $summary['goal'],
            $summary['weeks'],
            $summary['sessions_per_week'],
            $summary['total_km']
        );

        $req_status      = 'fallback';
        $provider_status = 'not_configured';
        $brief_text      = $this->deterministic_plan_brief($summary);

        if ($client->is_ready() && $this->monthly_calls_used($ref) < $client->monthly_cap()) {
            $res = $client->generate_plan_brief($summary);

            if ($res['ok'] && !empty($res['text'])) {
                $req_status      = 'succeeded';
                $provider_status = 'ok';
                $brief_text      = $res['text'];
            } else {
                $req_status      = 'failed';
                $provider_status = 'fallback';
            }
        }

        $this->ensure_tables();

        if (self::$schema_ok === true) {
            try {
                $this->db->table('ai_plan_requests')->insert(array(
                    'user_id'         => $uid,
                    'plan_id'         => $pid,
                    'provider'        => 'gemini',
                    'status'          => $req_status,
                    'request_summary' => mb_substr($summary_line, 0, 500),
                    'created_at'      => gmdate('Y-m-d H:i:s'),
                ));
            } catch (Throwable $e) {
                // The audit row is diagnostic only; plan generation already succeeded.
            }
        }

        return array(
            'text'            => $brief_text,
            'provider_status' => $provider_status,
        );
    }

    /**
     * Collects non-identifying first-party metrics for the past 7 days.
     * Only manual runs are included.
     */
    public function build_weekly_metrics($user_id, ?DateTimeImmutable $today = null)
    {
        $uid   = (int) $user_id;
        $ref   = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $start = $ref->modify('-6 days')->format('Y-m-d');
        $end   = $ref->format('Y-m-d');

        $profile = $this->db->table('runner_profiles')
            ->select('goal, experience')
            ->where('user_id', $uid)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        $runs = $this->db->table('activities')
            ->select_count('id', 'runs_logged')
            ->select_sum('distance_km', 'distance_km')
            ->select_sum('duration_seconds', 'duration_seconds')
            ->select_avg('effort', 'avg_effort')
            ->select_max('distance_km', 'longest_run_km')
            ->where('user_id', $uid)
            ->where('source', 'manual')
            ->between('activity_date', $start, $end)
            ->get(PDO::FETCH_ASSOC);

        $active_plans = $this->db->table('training_plans')
            ->select('id')
            ->where('user_id', $uid)
            ->where('status', 'active')
            ->get_all(PDO::FETCH_ASSOC);

        $active_plan_ids = array_map(
            'intval',
            array_column($active_plans ?: array(), 'id')
        );

        $session_counts = array(
            'completed_cnt' => 0,
            'planned_cnt'   => 0,
            'skipped_cnt'   => 0,
            'missed_cnt'    => 0,
        );

        if (!empty($active_plan_ids)) {
            $sessions = $this->db->table('training_sessions')
                ->select('status')
                ->where('user_id', $uid)
                ->in('plan_id', $active_plan_ids)
                ->between('scheduled_date', $start, $end)
                ->get_all(PDO::FETCH_ASSOC);

            $status_to_count = array(
                'completed' => 'completed_cnt',
                'planned'   => 'planned_cnt',
                'skipped'   => 'skipped_cnt',
                'missed'    => 'missed_cnt',
            );

            foreach ($sessions ?: array() as $session) {
                $status = $session['status'] ?? '';

                if (isset($status_to_count[$status])) {
                    $count_key = $status_to_count[$status];
                    $session_counts[$count_key]++;
                }
            }
        }

        return array(
            'period_start'       => $start,
            'period_end'         => $end,
            'goal'               => $profile['goal'] ?? 'general_fitness',
            'experience'         => $profile['experience'] ?? 'beginner',
            'runs_logged'        => (int) ($runs['runs_logged'] ?? 0),
            'distance_km'        => round((float) ($runs['distance_km'] ?? 0), 2),
            'duration_seconds'   => (int) ($runs['duration_seconds'] ?? 0),
            'avg_effort'         => isset($runs['avg_effort']) && $runs['avg_effort'] !== null
                ? round((float) $runs['avg_effort'], 1)
                : null,
            'longest_run_km'     => round((float) ($runs['longest_run_km'] ?? 0), 2),
            'sessions_completed' => $session_counts['completed_cnt'],
            'sessions_planned'   => $session_counts['planned_cnt'],
            'sessions_skipped'   => $session_counts['skipped_cnt'],
            'sessions_missed'    => $session_counts['missed_cnt'],
        );
    }

    public function monthly_calls_used(?DateTimeImmutable $today = null)
    {
        $this->ensure_tables();

        if (self::$schema_ok !== true) {
            return 0;
        }

        $ref         = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $month_start = $ref->format('Y-m-01') . ' 00:00:00';

        try {
            $plan_call_row = $this->db->table('ai_plan_requests')
                ->select_count('id', 'call_count')
                ->where('provider', 'gemini')
                ->in('status', array('succeeded', 'failed'))
                ->where('created_at', '>=', $month_start)
                ->get(PDO::FETCH_ASSOC);

            $feedback_call_row = $this->db->table('ai_feedback')
                ->select_count('id', 'call_count')
                ->where('provider_status', 'ok')
                ->where('created_at', '>=', $month_start)
                ->get(PDO::FETCH_ASSOC);

            $chat_call_row = $this->db->table('ai_chat_requests')
                ->select_count('id', 'call_count')
                ->where('provider', 'gemini')
                ->in('status', array('pending', 'succeeded', 'failed'))
                ->where('created_at', '>=', $month_start)
                ->get(PDO::FETCH_ASSOC);

            $plan_calls     = (int) ($plan_call_row['call_count'] ?? 0);
            $feedback_calls = (int) ($feedback_call_row['call_count'] ?? 0);
            $chat_calls     = (int) ($chat_call_row['call_count'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }

        return $plan_calls + $feedback_calls + $chat_calls;
    }

    private function save_feedback($user_id, $period_start, $period_end, array $metrics, $text, $provider_status)
    {
        if (self::$schema_ok !== true) {
            throw new RuntimeException('AI feedback tables are not available.');
        }

        $now_utc = gmdate('Y-m-d H:i:s');
        $json    = json_encode($metrics, JSON_UNESCAPED_SLASHES);
        $user_id = (int) $user_id;

        $existing = $this->db->table('ai_feedback')
            ->select('id')
            ->where('user_id', $user_id)
            ->where('period_start', $period_start)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if ($existing) {
            $id = (int) $existing['id'];

            $this->db->table('ai_feedback')
                ->where('id', $id)
                ->where('user_id', $user_id)
                ->update(array(
                    'period_end'       => $period_end,
                    'metrics_snapshot' => $json,
                    'feedback_text'    => $text,
                    'provider_status'  => $provider_status,
                    'created_at'       => $now_utc,
                ));
        } else {
            $this->db->table('ai_feedback')->insert(array(
                'user_id'          => $user_id,
                'period_start'     => $period_start,
                'period_end'       => $period_end,
                'metrics_snapshot' => $json,
                'feedback_text'    => $text,
                'provider_status'  => $provider_status,
                'created_at'       => $now_utc,
            ));

            $id = (int) $this->db->last_id();
        }

        $row = $this->db->table('ai_feedback')
            ->select(
                'id, period_start, period_end, metrics_snapshot, feedback_text, provider_status, created_at'
            )
            ->where('id', $id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        return $this->present_feedback($row);
    }

    private function present_feedback(array $row)
    {
        $decoded = json_decode((string) ($row['metrics_snapshot'] ?? ''), true);

        return array(
            'id'              => (int) $row['id'],
            'period_start'    => $row['period_start'],
            'period_end'      => $row['period_end'],
            'metrics'         => is_array($decoded) ? $decoded : array(),
            'feedback_text'   => $row['feedback_text'],
            'provider_status' => $row['provider_status'],
            'persisted'       => true,
            'created_at'      => $row['created_at'],
        );
    }

    private function deterministic_feedback(array $metrics)
    {
        $runs    = (int) $metrics['runs_logged'];
        $km      = (float) $metrics['distance_km'];
        $longest = (float) $metrics['longest_run_km'];
        $skipped = (int) $metrics['sessions_skipped'];
        $missed  = (int) $metrics['sessions_missed'];
        $effort  = $metrics['avg_effort'];

        if ($runs === 0) {
            $opening = 'No manual runs were logged between '
                . $metrics['period_start']
                . ' and '
                . $metrics['period_end']
                . '.';
            $tips = 'Focus next week on completing 2 short, conversational-pace runs first rather than trying to make up missed mileage all at once.';

            if ($missed > 0 || $skipped > 0) {
                $tips .= ' Consider proposing a plan revision on the Plan page if your weekly availability has shifted.';
            }

            return $opening . ' ' . $tips;
        }

        $summary = sprintf(
            'Over the past 7 days (%s to %s), you logged %d %s totaling %.1f km, with a longest effort of %.1f km.',
            $metrics['period_start'],
            $metrics['period_end'],
            $runs,
            $runs === 1 ? 'run' : 'runs',
            $km,
            $longest
        );

        if ($missed > 0 || $skipped > 0) {
            $adherence = sprintf(
                ' You had %d skipped and %d missed scheduled %s — resist doubling up workouts to compensate.',
                $skipped,
                $missed,
                ($skipped + $missed) === 1 ? 'session' : 'sessions'
            );
        } else {
            $adherence = ' Consistency looked steady with zero missed workouts on your active schedule.';
        }

        $load_note = ' Keep easy days genuinely relaxed so you arrive fresh for your next long run.';

        if ($effort !== null && $effort >= 7.5) {
            $load_note = ' Your average perceived effort (' . $effort . '/10) ran fairly high, so dial back easy-day pace by 15–20 sec/km next week for better recovery.';
        }

        return $summary . $adherence . $load_note;
    }

    private function deterministic_plan_brief(array $summary)
    {
        $goal_label = str_replace('_', ' ', (string) $summary['goal']);

        return sprintf(
            'Built a %d-week %s progression at %d runs/week (%.1f km total volume, peaking with a %.1f km long run) with built-in cutback recovery every 4th week.',
            (int) $summary['weeks'],
            $goal_label,
            (int) $summary['sessions_per_week'],
            (float) $summary['total_km'],
            (float) $summary['peak_long_km']
        );
    }
}