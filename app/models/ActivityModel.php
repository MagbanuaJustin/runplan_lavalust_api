<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: ActivityModel
 *
 * Manual first-party running activities.
 *
 */
class ActivityModel extends Model
{
    protected $table = 'activities';
    protected $primary_key = 'id';

    const ELIGIBLE_SOURCE = 'manual';

    protected $fillable = [
        'session_id',
        'activity_date',
        'distance_km',
        'duration_seconds',
        'effort',
        'notes',
    ];

    /**
     * Explicit gate used by analytics and AI summary builders (TC-10, TC-15).
     */
    public static function is_eligible_for_analytics(array $row)
    {
        return ($row['source'] ?? '') === self::ELIGIBLE_SOURCE;
    }

    public function for_user($user_id, array $filters = [])
    {
        $query = $this->db->table($this->table)
            ->where('user_id', (int) $user_id)
            ->where('source', self::ELIGIBLE_SOURCE);

        if (
            !empty($filters['from'])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['from'])
        ) {
            $query->where(
                'activity_date',
                '>=',
                (string) $filters['from']
            );
        }

        if (
            !empty($filters['to'])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['to'])
        ) {
            $query->where(
                'activity_date',
                '<=',
                (string) $filters['to']
            );
        }

        $rows = $query->get_all(PDO::FETCH_ASSOC) ?: [];

        // Keep the original order: newest date first, then highest ID first.
        usort($rows, static function ($a, $b) {
            $date_order = strcmp(
                (string) $b['activity_date'],
                (string) $a['activity_date']
            );

            if ($date_order !== 0) {
                return $date_order;
            }

            return (int) $b['id'] <=> (int) $a['id'];
        });

        return array_map([$this, 'with_pace'], $rows);
    }

    public function with_pace(array $row)
    {
        $km  = (float) $row['distance_km'];
        $sec = (int) $row['duration_seconds'];

        $row['id']               = (int) $row['id'];
        $row['distance_km']      = $km;
        $row['duration_seconds'] = $sec;
        $row['session_id']       = $row['session_id'] !== null
            ? (int) $row['session_id']
            : null;
        $row['effort']            = $row['effort'] !== null
            ? (int) $row['effort']
            : null;

        if ($km > 0 && $sec > 0) {
            $pace = (int) round($sec / $km);
            $row['pace_seconds_per_km'] = $pace;
            $row['pace_label'] = sprintf('%d:%02d', intdiv($pace, 60), $pace % 60);
        } else {
            $row['pace_seconds_per_km'] = null;
            $row['pace_label'] = null;
        }

        return $row;
    }

    // Returns [clean_data, errors]. Reused by store (POST) and update (PUT).
    public function validate(array $in)
    {
        $clean  = [];
        $errors = [];

        $date = (string) ($in['activity_date'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $date);

        if (!$d || $d->format('Y-m-d') !== $date) {
            $errors['activity_date'] = 'Enter a valid date.';
        } else {
            $max = (new DateTime('now', new DateTimeZone('UTC')))
                ->modify('+1 day')
                ->format('Y-m-d');

            if ($date > $max) {
                $errors['activity_date'] = 'The date cannot be in the future.';
            } elseif ($date < '2000-01-01') {
                $errors['activity_date'] = 'That date is too far in the past.';
            } else {
                $clean['activity_date'] = $date;
            }
        }

        $km = $in['distance_km'] ?? null;

        if (!is_numeric($km) || (float) $km < 0.01 || (float) $km > 300) {
            $errors['distance_km'] = 'Distance must be between 0.01 and 300 km.';
        } else {
            $clean['distance_km'] = round((float) $km, 2);
        }

        $sec = $in['duration_seconds'] ?? null;

        if (!is_numeric($sec) || (int) $sec != $sec || (int) $sec < 1 || (int) $sec > 172800) {
            $errors['duration_seconds'] = 'Enter a duration between 1 second and 48 hours.';
        } else {
            $clean['duration_seconds'] = (int) $sec;
        }

        // Plausibility: only when both numbers are valid.
        if (isset($clean['distance_km'], $clean['duration_seconds'])) {
            $pace = $clean['duration_seconds'] / $clean['distance_km'];

            if ($pace < 120) {
                $errors['duration_seconds'] = 'That pace is faster than 2:00 /km. Check the distance and time.';
            } elseif ($pace > 1500) {
                $errors['duration_seconds'] = 'That pace is slower than 25:00 /km. Check the distance and time.';
            }
        }

        $effort = $in['effort'] ?? null;

        if ($effort === null || $effort === '') {
            $clean['effort'] = null;
        } elseif (
            filter_var(
                $effort,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 10]]
            ) === false
        ) {
            $errors['effort'] = 'Effort must be a whole number from 1 to 10.';
        } else {
            $clean['effort'] = (int) $effort;
        }

        $notes = $in['notes'] ?? null;

        if ($notes === null || trim((string) $notes) === '') {
            $clean['notes'] = null;
        } elseif (mb_strlen((string) $notes) > 1000) {
            $errors['notes'] = 'Notes can be at most 1000 characters.';
        } else {
            $clean['notes'] = trim((string) $notes);
        }

        return [$clean, $errors];
    }

    public function find_for_user($user_id, $id)
    {
        $row = $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where('user_id', (int) $user_id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        return $row ? $this->with_pace($row) : null;
    }

    /**
     * Longest first-party run in the last N days, used to seed a rebuilt plan
     * from what the runner actually did instead of what they once declared.
     *
     * Strictly scoped to ELIGIBLE_SOURCE (`manual`) per Spec 5.5 / TC-10.
     *
     * @param int                    $user_id
     * @param int                    $days
     * @param DateTimeImmutable|null $today
     * @return float|null
     */
    public function longest_recent_run_km($user_id, $days = 21, ?DateTimeImmutable $today = null)
    {
        $ref = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $cutoff = $ref
            ->modify('-' . max(1, (int) $days) . ' days')
            ->format('Y-m-d');

        $row = $this->db->table($this->table)
            ->select_max('distance_km', 'd')
            ->where('user_id', (int) $user_id)
            ->where('source', self::ELIGIBLE_SOURCE)
            ->where('activity_date', '>=', $cutoff)
            ->get(PDO::FETCH_ASSOC);

        return ($row && $row['d'] !== null) ? (float) $row['d'] : null;
    }

    // Returns the new activity, or null if the session was completed meanwhile.
    public function create_for_user($user_id, array $clean, $session_id = null)
    {
        $row = array_merge(
            $clean,
            [
                'user_id' => (int) $user_id,
                'source' => self::ELIGIBLE_SOURCE,
            ]
        );

        if ($session_id !== null) {
            $row['session_id'] = (int) $session_id;
        }

        $this->db->transaction();

        $this->db->table($this->table)->insert($row);
        $id = (int) $this->db->last_id();

        if ($session_id !== null) {
            $done = $this->db->table('training_sessions')
                ->where('id', (int) $session_id)
                ->where('user_id', (int) $user_id)
                ->where('status', '<>', 'completed')
                ->update([
                    'status' => 'completed',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

            if ((int) $done === 0) {
                $this->db->roll_back();
                return null;
            }

            // Cancel any pending reminder job for a session now completed.
            $this->db->table('notification_jobs')
                ->where('session_id', (int) $session_id)
                ->where('user_id', (int) $user_id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                ]);
        }

        $this->db->commit();

        return $this->find_for_user($user_id, $id);
    }

    public function delete_for_user($user_id, $id)
    {
        $found = $this->db->table($this->table)
            ->select('session_id')
            ->where('id', (int) $id)
            ->where('user_id', (int) $user_id)
            ->get(PDO::FETCH_ASSOC);

        if (!$found) {
            return;
        }

        $this->db->transaction();

        $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where('user_id', (int) $user_id)
            ->delete();

        if ($found['session_id'] !== null) {
            $this->db->table('training_sessions')
                ->where('id', (int) $found['session_id'])
                ->where('user_id', (int) $user_id)
                ->where('status', 'completed')
                ->update([
                    'status' => 'planned',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
        }

        $this->db->commit();
    }

    public function update_for_user($user_id, $id, array $clean)
    {
        $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where('user_id', (int) $user_id)
            ->update([
                'activity_date' => $clean['activity_date'],
                'distance_km' => $clean['distance_km'],
                'duration_seconds' => $clean['duration_seconds'],
                'effort' => $clean['effort'],
                'notes' => $clean['notes'],
            ]);
    }

    public function linkable_session($user_id, $session_id)
    {
        if (!ctype_digit((string) $session_id)) {
            return null;
        }

        $row = $this->db->table('training_sessions s')
            ->select('s.id, s.status')
            ->join('training_plans p', 'p.id = s.plan_id')
            ->where('s.id', (int) $session_id)
            ->where('s.user_id', (int) $user_id)
            ->where('p.status', 'active')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}