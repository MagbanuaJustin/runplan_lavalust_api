<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Sessions
 *
 * Updates a training session on the runner's active plan (either moving its
 * date or toggling `planned` / `skipped` status with an optional `skip_reason`).
 */
class Sessions extends Controller {

    public function update($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('SessionModel');

        $this->api->require_method('PATCH');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('session_write_' . $user_id, 60, 60);

        if (!ctype_digit((string) $id)) {
            $this->api->respond_error('Session not found.', 404);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        $has_date   = array_key_exists('scheduled_date', $input);
        $has_status = array_key_exists('status', $input);

        if ($has_date && $has_status) {
            $this->api->respond([
                'error'  => 'Validation failed',
                'fields' => [
                    'scheduled_date' => 'Send either scheduled_date or status per request, not both.',
                ],
            ], 422);
        }

        // Move to another day
        if ($has_date) {
            [$session, $err] = $this->SessionModel->reschedule($user_id, $id, (string) $input['scheduled_date']);
            if ($err) {
                if ($err[0] === 422) {
                    $this->api->respond([
                        'error'  => 'Validation failed',
                        'fields' => ['scheduled_date' => $err[1]],
                    ], 422);
                }
                $this->api->respond_error($err[1], $err[0]);
            }
            $this->api->respond(['data' => $session]);
        }

        // Skip or un-skip
        $status = $input['status'] ?? null;
        if (!in_array($status, ['planned', 'skipped'], true)) {
            $this->api->respond([
                'error'  => 'Validation failed',
                'fields' => ['status' => 'Use planned or skipped.'],
            ], 422);
        }

        $skip_reason = $input['skip_reason'] ?? null;
        if ($skip_reason !== null && (!is_scalar($skip_reason) || mb_strlen(trim((string) $skip_reason)) > 255)) {
            $this->api->respond([
                'error'  => 'Validation failed',
                'fields' => ['skip_reason' => 'Keep the skip reason under 255 characters.'],
            ], 422);
        }

        $result = $this->SessionModel->set_status($user_id, $id, $status, $skip_reason);
        if ($result === null) {
            $this->api->respond_error('Session not found.', 404);
        }
        if ($result === false) {
            $this->api->respond_error('A completed run cannot be changed here.', 409);
        }

        $this->api->respond(['data' => $result]);
    }
}