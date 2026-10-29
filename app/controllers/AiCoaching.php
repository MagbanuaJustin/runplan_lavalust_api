<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: AiCoaching
 */
class AiCoaching extends Controller
{
    public function show()
    {
        $this->call->library('api');
        $this->call->helper('integration');
        $this->call->database();
        $this->call->model('AiCoachingModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $public_config = integration_public_config();

        header('Cache-Control: no-store');

        $this->api->respond(array(
            'data' => array(
                'feedback' => $this->AiCoachingModel->latest_feedback_for_user($user_id),
                'gemini'   => $public_config['gemini'] ?? array(
                    'ready'      => false,
                    'configured' => false,
                ),
            ),
        ));
    }

    public function generate()
    {
        $this->call->library('api');
        $this->call->helper('integration');
        $this->call->database();
        $this->call->model('AiCoachingModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $this->api->rate_limit('ai_feedback_' . $user_id, 10, 3600);

        $feedback      = $this->AiCoachingModel->generate_weekly_feedback($user_id);
        $public_config = integration_public_config();

        header('Cache-Control: no-store');

        $this->api->respond(array(
            'data' => array(
                'feedback' => $feedback,
                'gemini'   => $public_config['gemini'] ?? array(
                    'ready'      => false,
                    'configured' => false,
                ),
            ),
        ), 201);
    }

    public function chat()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('AiCoachingModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $this->api->rate_limit('ai_chat_' . $user_id, 30, 3600);

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body.', 400);
        }

        $message = $input['message'] ?? null;

        if (!is_string($message)) {
            $this->api->respond(array(
                'error'  => 'Validation failed.',
                'fields' => array(
                    'message' => 'Enter a question about your training.',
                ),
            ), 422);
        }

        $message = trim($message);

        if ($message === '' || mb_strlen($message) > 1000) {
            $this->api->respond(array(
                'error'  => 'Validation failed.',
                'fields' => array(
                    'message' => 'Your question must be between 1 and 1000 characters.',
                ),
            ), 422);
        }

        $result = $this->AiCoachingModel->answer_training_question($user_id, $message);

        header('Cache-Control: no-store');

        if (empty($result['text'])) {
            $provider_status = $result['provider_status'] ?? 'unavailable';
            $http_status = 503;

            if ($provider_status === 'cap_reached') {
                $http_status = 429;
            }

            $this->api->respond(array(
                'error'           => $result['error'] ?? 'The AI coach is unavailable right now.',
                'provider_status' => $provider_status,
            ), $http_status);
        }

        $this->api->respond(array(
            'data' => array(
                'answer'          => $result['text'],
                'provider_status' => $result['provider_status'] ?? 'ok',
            ),
        ));
    }
}