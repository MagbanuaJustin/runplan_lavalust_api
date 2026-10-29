<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SessionModel extends Model
{
    protected $table       = 'training_sessions';
    protected $primary_key = 'id';
    protected $timestamps  = true;

    public function find_for_user($user_id, $session_id)
    {
        $row = $this->db->table($this->table)
            ->select(
                'training_sessions.id, training_sessions.plan_id, training_sessions.scheduled_date, ' .
                'training_sessions.planned_start_time, training_sessions.type, ' .
                'training_sessions.planned_distance_km, training_sessions.planned_duration_seconds, ' .
                'training_sessions.status, training_sessions.skip_reason, training_sessions.notes'
            )
            ->join(
                'training_plans',
                'training_plans.id = training_sessions.plan_id'
            )
            ->where('training_sessions.id', (int) $session_id)
            ->where('training_sessions.user_id', (int) $user_id)
            ->where('training_plans.status', 'active')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id'                       => (int) $row['id'],
            'plan_id'                  => (int) $row['plan_id'],
            'scheduled_date'           => $row['scheduled_date'],
            'planned_start_time'       => $row['planned_start_time'] ?? null,
            'type'                     => $row['type'],
            'planned_distance_km'      => $row['planned_distance_km'] !== null
                ? (float) $row['planned_distance_km']
                : null,
            'planned_duration_seconds' => $row['planned_duration_seconds'] !== null
                ? (int) $row['planned_duration_seconds']
                : null,
            'status'                   => $row['status'],
            'skip_reason'              => $row['skip_reason'] ?? null,
            'notes'                    => $row['notes'],
        ];
    }

    /**
     * Marks planned sessions older than yesterday (UTC) on the active plan as
     * `missed`. Uses ISO-date comparison so MySQL and SQLite behave identically.
     */
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

        $this->db->table($this->table)
            ->where('user_id', $uid)
            ->where('status', 'planned')
            ->where('scheduled_date', '<', $cutoff)
            ->in('plan_id', $active_plan_ids)
            ->update([
                'status'     => 'missed',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Reschedules a non-completed session on the active plan and records a
     * `plan_revisions` entry.
     *
     * @return array [session|null, [http_status, message]|null]
     */
    public function reschedule($user_id, $session_id, $new_date)
    {
        $lava = lava_instance();
        $lava->call->model('PlanModel');

        return $lava->PlanModel->reschedule_session(
            (int) $user_id,
            (int) $session_id,
            (string) $new_date
        );
    }

    /**
     * Updates session status (`planned` or `skipped`) with optional `skip_reason`.
     * Completed sessions are protected and return `false`.
     *
     * @return array|false|null
     */
    public function set_status($user_id, $session_id, $status, $skip_reason = null)
    {
        $lava = lava_instance();
        $lava->call->model('PlanModel');

        return $lava->PlanModel->set_session_status(
            (int) $user_id,
            (int) $session_id,
            (string) $status,
            $skip_reason
        );
    }
}