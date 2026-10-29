<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Account
 * 
 * Automatically generated via CLI.
 */
class Account extends Controller {

    public function show()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $uid     = (int) $payload['sub'];

        $summary = $this->UserModel->account_summary($uid);
        if (!$summary) {
            $this->api->respond_error('Unauthorized', 401);
        }

        $events = $this->SecurityEventModel->recent_for_user($uid, 8);

        header('Cache-Control: no-store');
        $this->api->respond([
            'data' => [
                'account'         => $summary,
                'security_events' => $events,
            ],
        ]);
    }

    public function change_password()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('RefreshTokenModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $uid     = (int) $payload['sub'];

        $this->api->rate_limit('acct_pw_' . $uid, 10, 600);

        $user = $this->UserModel->find_active($uid);
        if (!$user) {
            $this->api->respond_error('Unauthorized', 401);
        }

        $input   = $this->read_json();
        $current = (string) ($input['current_password'] ?? '');
        $next    = (string) ($input['new_password'] ?? '');

        $errors = [];
        if ($this->UserModel->has_password($user) && $current === '') {
            $errors['current_password'] = 'Enter your current password.';
        }
        if ($problem = UserModel::password_problem($next)) {
            $errors['new_password'] = $problem;
        }
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        if ($this->UserModel->has_password($user)) {
            if (!$this->UserModel->verify_password($user, $current)) {
                $this->SecurityEventModel->record('password_change_failed', $uid, ['reason' => 'wrong_current_password']);
                $this->api->respond([
                    'error'  => 'Validation failed',
                    'fields' => ['current_password' => 'Your current password is incorrect.'],
                ], 422);
            }
            if ($current === $next) {
                $this->api->respond([
                    'error'  => 'Validation failed',
                    'fields' => ['new_password' => 'Choose a password different from your current one.'],
                ], 422);
            }
        }

        $this->UserModel->set_password($uid, $next);
        $revoked = $this->RefreshTokenModel->revoke_all_for_user($uid);

        // Rotate current session so this browser stays signed in while revoking all other devices
        $tokens = $this->api->issue_tokens([
            'id'   => $uid,
            'role' => $user['role'],
        ]);
        $this->set_refresh_cookie($tokens['refresh_token']);

        $this->SecurityEventModel->record('password_changed', $uid, [
            'revoked_sessions' => $revoked,
        ]);

        header('Cache-Control: no-store');
        $this->api->respond([
            'data' => [
                'ok'           => true,
                'message'      => 'Password updated. Other signed-in sessions have been revoked.',
                'access_token' => $tokens['access_token'],
                'token_type'   => 'Bearer',
                'expires_in'   => $tokens['expires_in'],
            ],
        ]);
    }

    public function destroy()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('SecurityEventModel');

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if (!in_array($method, ['DELETE', 'POST'], true)) {
            $this->api->respond_error('Method not allowed', 405);
        }

        $payload = $this->api->require_jwt();
        $uid     = (int) $payload['sub'];

        $this->api->rate_limit('acct_del_' . $uid, 5, 600);

        $user = $this->UserModel->find_active($uid);
        if (!$user) {
            $this->api->respond_error('Unauthorized', 401);
        }

        $input    = $this->read_json();
        $confirm  = strtoupper(trim((string) ($input['confirm_text'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $reason   = $input['reason'] ?? null;

        $errors = [];
        if ($confirm !== 'DELETE') {
            $errors['confirm_text'] = 'Type DELETE to confirm permanent account removal.';
        }
        if ($this->UserModel->has_password($user)) {
            if ($password === '') {
                $errors['password'] = 'Enter your current password to confirm.';
            } elseif (!$this->UserModel->verify_password($user, $password)) {
                $errors['password'] = 'Your password is incorrect.';
            }
        }
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $this->SecurityEventModel->record('account_deleted', null, [
            'deleted_user_id' => $uid,
        ]);

        $this->UserModel->delete_account_and_data($uid, $reason);
        $this->clear_refresh_cookie();

        header('Cache-Control: no-store');
        $this->api->respond([
            'data' => [
                'ok'      => true,
                'message' => 'Your account and all training data have been permanently deleted.',
            ],
        ]);
    }

    private function read_json()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }
        return $input;
    }

    private function is_https()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private function set_refresh_cookie($token)
    {
        setcookie('rp_refresh', $token, [
            'expires'  => time() + (int) config_item('refresh_token_expiration'),
            'path'     => '/api/auth',
            'secure'   => $this->is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clear_refresh_cookie()
    {
        setcookie('rp_refresh', '', [
            'expires'  => time() - 3600,
            'path'     => '/api/auth',
            'secure'   => $this->is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}