<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Activities
 * 
 * Automatically generated via CLI.
 */
class Activities extends Controller {

    public function index()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ActivityModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        $filters = [
            'from' => $_GET['from'] ?? null,
            'to'   => $_GET['to'] ?? null,
        ];

        header('Cache-Control: no-store');
        $this->api->respond([
            'data' => $this->ActivityModel->for_user((int) $payload['sub'], $filters),
        ]);
    }

    public function show($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ActivityModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        $row = ctype_digit((string) $id)
            ? $this->ActivityModel->find_for_user((int) $payload['sub'], $id)
            : null;
        if (!$row) {
            $this->api->respond_error('Run not found.', 404);
        }

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $row]);
    }

    public function destroy($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ActivityModel');

        $this->api->require_method('DELETE');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('activity_write_' . $user_id, 60, 60);

        if (!ctype_digit((string) $id) || !$this->ActivityModel->find_for_user($user_id, $id)) {
            $this->api->respond_error('Run not found.', 404);
        }

        $this->ActivityModel->delete_for_user($user_id, $id);
        $this->api->respond(['data' => ['deleted' => true]]);
    }

    public function update($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ActivityModel');

        $this->api->require_method('PUT');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('activity_write_' . $user_id, 60, 60);

        if (!ctype_digit((string) $id) || !$this->ActivityModel->find_for_user($user_id, $id)) {
            $this->api->respond_error('Run not found.', 404);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        [$clean, $errors] = $this->ActivityModel->validate($input);
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $this->ActivityModel->update_for_user($user_id, $id, $clean);
        $this->api->respond(['data' => $this->ActivityModel->find_for_user($user_id, $id)]);
    }

    public function store()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ActivityModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('activity_write_' . $user_id, 60, 60);

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        [$clean, $errors] = $this->ActivityModel->validate($input);

        $session_id = $input['session_id'] ?? null;
        if ($session_id === null || $session_id === '') {
            $session_id = null;
        } else {
            $session = $this->ActivityModel->linkable_session($user_id, $session_id);
            if (!$session) {
                $errors['session_id'] = 'Choose a session from your active plan.';
            } elseif ($session['status'] === 'completed') {
                $errors['session_id'] = 'That session already has a logged run.';
            }
        }

        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $row = $this->ActivityModel->create_for_user($user_id, $clean, $session_id);
        if ($row === null) {
            $this->api->respond(['error' => 'Validation failed',
                'fields' => ['session_id' => 'That session already has a logged run.']], 422);
        }

        $this->api->respond(['data' => $row], 201);
    }
}
