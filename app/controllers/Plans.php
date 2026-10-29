<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Plans
 *
 * Plan generation, draft approval, two-step revision workflow
 * (`propose` -> `apply` / `reject`), and revision audit history.
 */
class Plans extends Controller {

    public function generate()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');
        $this->call->model('PlanModel');
        $this->call->model('AiCoachingModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_generate_' . $user_id, 10, 3600);

        $profile = $this->ProfileModel->find_for_user($user_id);
        if (!$profile) {
            $this->api->respond([
                'error'  => 'Complete your profile first.',
                'fields' => ['profile' => 'Save your profile before generating a plan.'],
            ], 422);
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        [$built, $errors] = $this->PlanModel->build($profile, $today);
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $plan_id = $this->PlanModel->create_draft($user_id, $built['plan'], $built['sessions']);
        $data    = $this->PlanModel->find_for_user($user_id, $plan_id);
        if (!$data) {
            $this->api->respond_error('The plan could not be saved. Please try again.', 500);
        }
        $data['warnings'] = $built['warnings'];
        $data['ai_brief'] = $this->AiCoachingModel->record_plan_generation($user_id, $plan_id, $profile, $built, $today);

        $this->api->respond(['data' => $data], 201);
    }

        public function manual()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_manual_' . $user_id, 10, 3600);

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body.', 400);
        }

        $errors = [];

        $goal = trim((string) ($input['goal'] ?? ''));
        if ($goal === '' || mb_strlen($goal) > 80) {
            $errors['goal'] = 'Enter a goal using no more than 80 characters.';
        }

        $start_date = (string) ($input['start_date'] ?? '');
        $end_date = (string) ($input['end_date'] ?? '');

        $is_valid_date = static function ($value) {
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                (string) $value,
                new DateTimeZone('UTC')
            );

            return $date && $date->format('Y-m-d') === (string) $value;
        };

        if (!$is_valid_date($start_date)) {
            $errors['start_date'] = 'Enter a valid start date in YYYY-MM-DD format.';
        }

        if (!$is_valid_date($end_date)) {
            $errors['end_date'] = 'Enter a valid end date in YYYY-MM-DD format.';
        }

        if (
            $is_valid_date($start_date)
            && $is_valid_date($end_date)
            && $start_date > $end_date
        ) {
            $errors['end_date'] = 'The end date must be on or after the start date.';
        }

        $sessions_per_week = filter_var(
            $input['sessions_per_week'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($sessions_per_week === false || $sessions_per_week < 2 || $sessions_per_week > 7) {
            $errors['sessions_per_week'] = 'Choose between 2 and 7 sessions per week.';
        }

        $input_sessions = $input['sessions'] ?? null;
        if (
            !is_array($input_sessions)
            || count($input_sessions) < 2
            || count($input_sessions) > 364
        ) {
            $errors['sessions'] = 'Add between 2 and 364 sessions to the schedule.';
            $input_sessions = [];
        }

        $allowed_types = [
            'easy',
            'tempo',
            'interval',
            'long',
            'race',
            'recovery',
        ];

        $sessions = [];
        $used_dates = [];
        $weekly_counts = [];

        foreach ($input_sessions as $index => $session) {
            $row_number = $index + 1;

            if (!is_array($session)) {
                $errors["sessions.{$index}"] = "Session {$row_number} is invalid.";
                continue;
            }

            $scheduled_date = (string) ($session['scheduled_date'] ?? '');
            if (!$is_valid_date($scheduled_date)) {
                $errors["sessions.{$index}.scheduled_date"] =
                    "Enter a valid date for session {$row_number}.";
                continue;
            }

            if (
                $is_valid_date($start_date)
                && $is_valid_date($end_date)
                && ($scheduled_date < $start_date || $scheduled_date > $end_date)
            ) {
                $errors["sessions.{$index}.scheduled_date"] =
                    "Session {$row_number} must be within the plan dates.";
            }

            if (isset($used_dates[$scheduled_date])) {
                $errors["sessions.{$index}.scheduled_date"] =
                    'Add no more than one session per day.';
            }
            $used_dates[$scheduled_date] = true;

            $type = (string) ($session['type'] ?? '');
            if (!in_array($type, $allowed_types, true)) {
                $errors["sessions.{$index}.type"] =
                    "Choose a valid workout type for session {$row_number}.";
            }

            $distance = $session['planned_distance_km'] ?? null;
            if (!is_numeric($distance) || (float) $distance <= 0 || (float) $distance > 100) {
                $errors["sessions.{$index}.planned_distance_km"] =
                    "Session {$row_number} distance must be greater than 0 and no more than 100 km.";
            }

            $pace = trim((string) ($session['target_pace'] ?? ''));
            if (!preg_match('/^(\d{1,2}):([0-5]\d)$/', $pace, $pace_parts)) {
                $errors["sessions.{$index}.target_pace"] =
                    "Enter session {$row_number} pace as mm:ss per kilometer.";
                continue;
            }

            $pace_seconds = ((int) $pace_parts[1] * 60) + (int) $pace_parts[2];
            if ($pace_seconds < 120 || $pace_seconds > 1800) {
                $errors["sessions.{$index}.target_pace"] =
                    'Pace must be between 2:00 and 30:00 per kilometer.';
            }

            $start_time = trim((string) ($session['planned_start_time'] ?? ''));
            if ($start_time !== '') {
                if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start_time)) {
                    $start_time .= ':00';
                } elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $start_time)) {
                    $errors["sessions.{$index}.planned_start_time"] =
                        "Enter session {$row_number} start time as HH:MM.";
                }
            } else {
                $start_time = null;
            }

            $notes = trim((string) ($session['notes'] ?? ''));
            if (mb_strlen($notes) > 1000) {
                $errors["sessions.{$index}.notes"] =
                    "Keep session {$row_number} notes under 1,000 characters.";
            }

            $week_key = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $scheduled_date,
                new DateTimeZone('UTC')
            )->format('o-W');

            $weekly_counts[$week_key] = ($weekly_counts[$week_key] ?? 0) + 1;
            if (
                $sessions_per_week !== false
                && $weekly_counts[$week_key] > $sessions_per_week
            ) {
                $errors["sessions.{$index}.scheduled_date"] =
                    'A week cannot contain more sessions than the selected weekly frequency.';
            }

            if (
                is_numeric($distance)
                && (float) $distance > 0
                && $pace_seconds >= 120
                && $pace_seconds <= 1800
                && in_array($type, $allowed_types, true)
            ) {
                $distance = round((float) $distance, 2);

                $sessions[] = [
                    'scheduled_date' => $scheduled_date,
                    'planned_start_time' => $start_time,
                    'type' => $type,
                    'planned_distance_km' => $distance,
                    'planned_duration_seconds' => (int) round($distance * $pace_seconds),
                    'notes' => $notes,
                ];
            }
        }

        if ($errors) {
            $this->api->respond([
                'error' => 'Validation failed',
                'fields' => $errors,
            ], 422);
        }

        $plan = [
            'goal' => $goal,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'sessions_per_week' => $sessions_per_week,
        ];

        $plan_id = $this->PlanModel->create_draft(
            $user_id,
            $plan,
            $sessions,
            'manual'
        );

        $data = $this->PlanModel->find_for_user($user_id, $plan_id);
        if (!$data) {
            $this->api->respond_error(
                'The manual plan could not be saved. Please try again.',
                500
            );
        }

        $this->api->respond(['data' => $data], 201);
    }

    public function current()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $this->PlanModel->current_for_user((int) $payload['sub'])]);
    }

    public function approve($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_approve_' . $user_id, 20, 600);

        $plan = ctype_digit((string) $id) ? $this->PlanModel->approve_for_user($user_id, $id) : null;
        if (!$plan) {
            $this->api->respond_error('Draft plan not found.', 404);
        }

        $this->api->respond(['data' => $plan]);
    }

    public function discard($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['DELETE', 'POST'], true)) {
            $this->api->respond_error('Method not allowed', 405);
        }
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_discard_' . $user_id, 30, 600);

        $ok = ctype_digit((string) $id) ? $this->PlanModel->discard_draft_for_user($user_id, $id) : false;
        if (!$ok) {
            $this->api->respond_error('Draft plan not found.', 404);
        }

        $this->api->respond(['data' => ['discarded' => true]]);
    }

    public function propose_revision($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');
        $this->call->model('PlanModel');
        $this->call->model('ActivityModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_propose_' . $user_id, 20, 3600);

        if (!ctype_digit((string) $id)) {
            $this->api->respond_error('Active plan not found.', 404);
        }

        $raw_body = file_get_contents('php://input');
        $input    = ($raw_body !== false && trim($raw_body) !== '') ? json_decode($raw_body, true) : [];
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        $reason = $input['reason'] ?? null;
        if ($reason !== null && (!is_scalar($reason) || mb_strlen(trim((string) $reason)) > 255)) {
            $this->api->respond([
                'error'  => 'Validation failed',
                'fields' => ['reason' => 'Keep the revision reason under 255 characters.'],
            ], 422);
        }

        $profile = $this->ProfileModel->find_for_user($user_id);
        if (!$profile) {
            $this->api->respond([
                'error'  => 'Complete your profile first.',
                'fields' => ['profile' => 'Save your profile before rebuilding your plan.'],
            ], 422);
        }

        $recent = $this->ActivityModel->longest_recent_run_km($user_id, 21);
        $today  = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        [$proposal, $err] = $this->PlanModel->propose_rebuild($user_id, $id, $profile, $today, $recent, $reason);

        if ($err === 'not_found') {
            $this->api->respond_error('Active plan not found.', 404);
        }
        if ($err) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $err], 422);
        }

        $this->api->respond(['data' => $proposal], 201);
    }

    public function apply_revision($id = '', $rev_id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_apply_' . $user_id, 20, 3600);

        if (!ctype_digit((string) $id) || !ctype_digit((string) $rev_id)) {
            $this->api->respond_error('Pending revision not found.', 404);
        }

        [$data, $err] = $this->PlanModel->apply_revision($user_id, $id, $rev_id);
        if ($err === 'not_found' || !$data) {
            $this->api->respond_error('Pending revision not found.', 404);
        }

        $this->api->respond(['data' => $data]);
    }

    public function reject_revision($id = '', $rev_id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_reject_' . $user_id, 30, 3600);

        if (!ctype_digit((string) $id) || !ctype_digit((string) $rev_id)) {
            $this->api->respond_error('Pending revision not found.', 404);
        }

        $rejected = $this->PlanModel->reject_revision($user_id, $id, $rev_id);
        if (!$rejected) {
            $this->api->respond_error('Pending revision not found.', 404);
        }

        $this->api->respond(['data' => $rejected]);
    }

    public function rebuild($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');
        $this->call->model('PlanModel');
        $this->call->model('ActivityModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_rebuild_' . $user_id, 10, 3600);

        if (!ctype_digit((string) $id)) {
            $this->api->respond_error('Active plan not found.', 404);
        }

        $raw_body = file_get_contents('php://input');
        $input    = ($raw_body !== false && trim($raw_body) !== '') ? json_decode($raw_body, true) : [];
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }
        $reason = isset($input['reason']) && is_scalar($input['reason']) ? (string) $input['reason'] : null;

        $profile = $this->ProfileModel->find_for_user($user_id);
        if (!$profile) {
            $this->api->respond([
                'error'  => 'Complete your profile first.',
                'fields' => ['profile' => 'Save your profile before rebuilding your plan.'],
            ], 422);
        }

        // Longest run of the last 3 weeks: the rebuilt plan continues from what the runner really did
        $recent = $this->ActivityModel->longest_recent_run_km($user_id, 21);

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        [$data, $err] = $this->PlanModel->rebuild_remaining($user_id, $id, $profile, $today, $recent, $reason);

        if ($err === 'not_found') {
            $this->api->respond_error('Active plan not found.', 404);
        }
        if ($err) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $err], 422);
        }
        $this->api->respond(['data' => $data]);
    }

    public function revisions($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        $list = ctype_digit((string) $id)
            ? $this->PlanModel->revisions_for_user((int) $payload['sub'], $id)
            : null;
        if ($list === null) {
            $this->api->respond_error('Plan not found.', 404);
        }

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $list]);
    }
}