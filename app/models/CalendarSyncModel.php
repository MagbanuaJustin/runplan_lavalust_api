<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CalendarSyncModel extends Model
{
    /**
     * Sync upcoming sessions on the current active plan.
     *
     * The mapping table provides the session-to-Google-event link. Event IDs are
     * deterministic, so retrying after a timeout or PHP crash cannot create a
     * second copy when Google accepted the first request but RunPlan did not get
     * to save its response.
     *
     * @param int                            $user_id
     * @param GoogleCalendarService|null     $oauth
     * @param GoogleCalendarEventService|null $events
     * @param TokenVault|null                $vault
     * @param DateTimeImmutable|null         $now injectable clock for tests
     * @return array safe summary for the API/UI
     */
    public function sync_for_user(
        $user_id,
        ?GoogleCalendarService $oauth = null,
        ?GoogleCalendarEventService $events = null,
        ?TokenVault $vault = null,
        ?DateTimeImmutable $now = null
    ) {
        $uid    = (int) $user_id;
        $oauth  = $oauth ?: new GoogleCalendarService();
        $events = $events ?: new GoogleCalendarEventService();
        $vault  = $vault ?: new TokenVault();
        $now    = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $links  = new CalendarLinkModel();

        if ($uid <= 0) {
            return $this->outcome(
                false,
                'unavailable',
                0,
                0,
                0,
                0,
                'Sign in again before syncing Google Calendar.'
            );
        }

        $status = $links->status_for_user($uid, $oauth->is_ready(), $vault);

        if ($status['status'] !== CalendarLinkModel::STATE_CONNECTED) {
            return $this->outcome(
                false,
                (string) $status['status'],
                0,
                0,
                0,
                0,
                (string) $status['message']
            );
        }

        if (!$status['sync_enabled']) {
            return $this->outcome(
                true,
                'paused',
                0,
                0,
                0,
                0,
                'Google Calendar is connected, but syncing is paused.'
            );
        }

        $tokens = $links->tokens_for_user($uid, $vault);

        if (!is_array($tokens)) {
            return $this->outcome(
                false,
                CalendarLinkModel::STATE_NEEDS_RECONNECT,
                0,
                0,
                0,
                0,
                'Reconnect Google Calendar before syncing.'
            );
        }

        try {
            $account = $this->db->table('integration_accounts')
                ->select('calendar_id, sync_enabled')
                ->where('user_id', $uid)
                ->where('provider', CalendarLinkModel::PROVIDER)
                ->limit(1)
                ->get(PDO::FETCH_ASSOC);

            if (!$account) {
                return $this->outcome(
                    false,
                    CalendarLinkModel::STATE_DISCONNECTED,
                    0,
                    0,
                    0,
                    0,
                    'Google Calendar is not connected yet.'
                );
            }

            if ((int) ($account['sync_enabled'] ?? 1) !== 1) {
                return $this->outcome(
                    true,
                    'paused',
                    0,
                    0,
                    0,
                    0,
                    'Google Calendar is connected, but syncing is paused.'
                );
            }

            $calendar_id = trim((string) ($account['calendar_id'] ?? '')) ?: 'primary';
            $timezone    = $this->event_timezone();
            $now_local   = $now->setTimezone($timezone);
            $today       = $now_local->format('Y-m-d');

            $sessions = $this->db->table('training_sessions')
                ->select(
                    'training_sessions.id, training_sessions.scheduled_date, ' .
                    'training_sessions.planned_start_time, training_sessions.type, ' .
                    'training_sessions.status, training_sessions.planned_distance_km, ' .
                    'training_sessions.planned_duration_seconds, training_sessions.notes'
                )
                ->join(
                    'training_plans',
                    'training_plans.id = training_sessions.plan_id ' .
                    'AND training_plans.user_id = training_sessions.user_id'
                )
                ->where('training_sessions.user_id', $uid)
                ->where('training_plans.status', 'active')
                ->in('training_sessions.status', array('planned', 'skipped'))
                ->where('training_sessions.scheduled_date', '>=', $today)
                ->order_by('training_sessions.scheduled_date', 'ASC')
                ->get_all(PDO::FETCH_ASSOC);

            $sessions = $sessions ?: array();

            // The query builder orders by one field at a time. Apply the
            // remaining original ordering fields in PHP.
            usort($sessions, static function ($left, $right) {
                $date_order = strcmp(
                    (string) ($left['scheduled_date'] ?? ''),
                    (string) ($right['scheduled_date'] ?? '')
                );

                if ($date_order !== 0) {
                    return $date_order;
                }

                $time_order = strcmp(
                    (string) ($left['planned_start_time'] ?? ''),
                    (string) ($right['planned_start_time'] ?? '')
                );

                if ($time_order !== 0) {
                    return $time_order;
                }

                return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
            });

            // Migration 016 preserves mappings as tombstones when sessions are
            // deleted. Clean those Google events before syncing current sessions.
            $orphan_mappings = $this->orphan_mappings_for_user($uid);
        } catch (Throwable $e) {
            return $this->outcome(
                false,
                'unavailable',
                0,
                0,
                0,
                0,
                'Calendar sync is temporarily unavailable. Your training plan is unaffected.'
            );
        }

        $total = count($sessions) + count($orphan_mappings);

        if ($total === 0) {
            $links->mark_synced($uid, $calendar_id);

            return $this->outcome(
                true,
                'synced',
                0,
                0,
                0,
                0,
                'There are no upcoming planned sessions or obsolete calendar events to sync.'
            );
        }

        $now_unix = $now->getTimestamp();

        if ($this->token_needs_refresh($tokens, $now_unix)) {
            $refresh = $this->refresh_and_store($uid, $tokens, $oauth, $vault, $links);

            if (!$refresh['ok']) {
                return $this->outcome(
                    false,
                    (string) $refresh['status'],
                    $total,
                    0,
                    0,
                    0,
                    (string) $refresh['message']
                );
            }

            $tokens = $refresh['tokens'];
        }

        $created             = 0;
        $updated             = 0;
        $failed              = 0;
        $done                = 0;
        $last_error          = null;
        $fatal_status        = null;
        $refreshed_after_401 = false;
        $rate_limited        = false;
        $duration_default    = (int) integration_value('google_calendar', 'event_length_minutes', 60);
        $deleted             = 0;

        // Session rows are deleted during plan revisions. Their mappings are
        // retained with session_id = NULL, so remove the old Google events first.
        foreach ($orphan_mappings as $orphan) {
            $mapping_id = (int) ($orphan['id'] ?? 0);
            $event_id   = trim((string) ($orphan['provider_event_id'] ?? ''));

            if ($event_id === '') {
                if ($this->delete_orphan_mapping($uid, $mapping_id)) {
                    $done++;
                    continue;
                }

                $failed++;
                $done++;
                $last_error = 'RunPlan could not remove an obsolete calendar mapping.';
                break;
            }

            try {
                $result = $events->delete_event(
                    (string) ($tokens['access_token'] ?? ''),
                    $calendar_id,
                    $event_id
                );

                if (!$result['ok']
                    && ($result['error_code'] ?? '') === 'unauthorized'
                    && !$refreshed_after_401
                ) {
                    $refreshed_after_401 = true;
                    $refresh = $this->refresh_and_store($uid, $tokens, $oauth, $vault, $links);

                    if ($refresh['ok']) {
                        $tokens = $refresh['tokens'];
                        $result = $events->delete_event(
                            (string) ($tokens['access_token'] ?? ''),
                            $calendar_id,
                            $event_id
                        );
                    } else {
                        $fatal_status = (string) $refresh['status'];
                        $result = array(
                            'ok'         => false,
                            'error_code' => $fatal_status,
                            'message'    => (string) $refresh['message'],
                        );
                    }
                }

                if (!$result['ok']) {
                    $failed++;
                    $done++;
                    $last_error = (string) ($result['message'] ?? 'Google Calendar could not remove an obsolete event.');
                    $this->mark_orphan_mapping_failed($uid, $mapping_id);

                    if (($result['error_code'] ?? '') === 'rate_limited') {
                        $rate_limited = true;
                    }

                    break;
                }

                if (!$this->delete_orphan_mapping($uid, $mapping_id)) {
                    $failed++;
                    $done++;
                    $last_error = 'Google removed an obsolete event, but RunPlan could not remove its local mapping.';
                    break;
                }

                $deleted++;
                $done++;
            } catch (Throwable $e) {
                $failed++;
                $done++;
                $last_error = 'RunPlan could not process an obsolete calendar mapping.';
                $this->mark_orphan_mapping_failed($uid, $mapping_id);
                break;
            }
        }

        foreach (($failed === 0) ? $sessions : array() as $session) {
            $session_id = (int) ($session['id'] ?? 0);
            $stable_id  = $this->stable_event_id($uid, $session_id);

            try {
                $mapping = $this->mapping_for_session($uid, $session_id);

                // A skipped future run should no longer occupy the runner's
                // calendar. A missing provider event is already a successful
                // cleanup, so deleting the mapping is safe to retry.
                if (($session['status'] ?? '') === 'skipped') {
                    if (!$mapping) {
                        $done++;
                        continue;
                    }

                    $event_id = trim((string) ($mapping['provider_event_id'] ?? ''));

                    if ($event_id === '') {
                        if ($this->delete_mapping($uid, $session_id)) {
                            $done++;
                            continue;
                        }

                        $failed++;
                        $done++;
                        $last_error = 'RunPlan could not remove the skipped session mapping.';
                        break;
                    }

                    $result = $events->delete_event(
                        (string) ($tokens['access_token'] ?? ''),
                        $calendar_id,
                        $event_id
                    );

                    if (!$result['ok']
                        && $result['error_code'] === 'unauthorized'
                        && !$refreshed_after_401
                    ) {
                        $refreshed_after_401 = true;
                        $refresh = $this->refresh_and_store($uid, $tokens, $oauth, $vault, $links);

                        if ($refresh['ok']) {
                            $tokens = $refresh['tokens'];
                            $result = $events->delete_event(
                                (string) ($tokens['access_token'] ?? ''),
                                $calendar_id,
                                $event_id
                            );
                        } else {
                            $fatal_status = (string) $refresh['status'];
                            $result = array(
                                'ok'         => false,
                                'error_code' => $fatal_status,
                                'message'    => (string) $refresh['message'],
                            );
                        }
                    }

                    if (!$result['ok']) {
                        $failed++;
                        $done++;
                        $last_error = (string) ($result['message'] ?? 'Google Calendar could not remove this skipped session.');
                        $this->save_mapping($uid, $session_id, $event_id, 'failed');

                        if (($result['error_code'] ?? '') === 'rate_limited') {
                            $rate_limited = true;
                        }

                        break;
                    }

                    if (!$this->delete_mapping($uid, $session_id)) {
                        $failed++;
                        $done++;
                        $last_error = 'Google removed the event, but RunPlan could not remove its local mapping.';
                        break;
                    }

                    $deleted++;
                    $done++;
                    continue;
                }

                $event_data = $this->event_for_session(
                    $session,
                    $timezone,
                    $duration_default,
                    $stable_id
                );

                if (!$event_data['ok']) {
                    $failed++;
                    $done++;
                    $last_error = (string) $event_data['message'];
                    continue;
                }

                $event_id = trim((string) ($mapping['provider_event_id'] ?? '')) ?: $stable_id;

                // Persist a pending deterministic id BEFORE the network call. If
                // the PHP process exits after Google accepts the event, the next
                // run patches the same id instead of inserting a duplicate.
                if (!$this->save_mapping($uid, $session_id, $event_id, 'pending')) {
                    $failed++;
                    $done++;
                    $last_error = 'RunPlan could not save the calendar event mapping.';
                    continue;
                }

                $result = $events->upsert(
                    (string) ($tokens['access_token'] ?? ''),
                    $calendar_id,
                    $mapping['provider_event_id'] ?? null,
                    $event_data['event'],
                    $stable_id
                );

                // A token can be invalidated before its recorded expiry. Refresh
                // once and retry once; do not loop against Google's token endpoint.
                if (!$result['ok']
                    && $result['error_code'] === 'unauthorized'
                    && !$refreshed_after_401
                ) {
                    $refreshed_after_401 = true;
                    $refresh = $this->refresh_and_store($uid, $tokens, $oauth, $vault, $links);

                    if ($refresh['ok']) {
                        $tokens = $refresh['tokens'];
                        $result = $events->upsert(
                            (string) ($tokens['access_token'] ?? ''),
                            $calendar_id,
                            $mapping['provider_event_id'] ?? null,
                            $event_data['event'],
                            $stable_id
                        );
                    } else {
                        $fatal_status = (string) $refresh['status'];
                        $result = array(
                            'ok'         => false,
                            'error_code' => $fatal_status,
                            'message'    => (string) $refresh['message'],
                        );
                    }
                }

                if (!$result['ok']) {
                    $failed++;
                    $done++;
                    $last_error = (string) ($result['message'] ?? 'Google Calendar could not sync this session.');
                    $this->save_mapping($uid, $session_id, $event_id, 'failed');

                    if (($result['error_code'] ?? '') === 'rate_limited') {
                        $rate_limited = true;
                    }

                    // Provider failures are usually account-wide (expired grant,
                    // quota, or outage). Stop the batch instead of issuing many
                    // more calls that are likely to fail the same way.
                    break;
                }

                $saved_id = (string) ($result['event_id'] ?? $event_id);

                if (!$this->save_mapping($uid, $session_id, $saved_id, 'synced')) {
                    $failed++;
                    $done++;
                    $last_error = 'Google accepted an event, but RunPlan could not update its mapping.';
                    continue;
                }

                if (($result['action'] ?? '') === 'created') {
                    $created++;
                } else {
                    $updated++;
                }

                $done++;
            } catch (Throwable $e) {
                $failed++;
                $done++;
                $last_error = 'RunPlan could not save the Google Calendar sync result.';

                try {
                    $this->save_mapping($uid, $session_id, $stable_id, 'failed');
                } catch (Throwable $ignored) {
                    // Keep the public failure result safe if the DB is unavailable.
                }
            }
        }

        if ($failed === 0 && $done === $total) {
            $links->mark_synced($uid, $calendar_id);
            $changes = array();

            if ($created > 0) {
                $changes[] = 'added ' . $created . ' session' . ($created === 1 ? '' : 's');
            }

            if ($updated > 0) {
                $changes[] = 'updated ' . $updated . ' session' . ($updated === 1 ? '' : 's');
            }

            if ($deleted > 0) {
                $changes[] = 'removed ' . $deleted . ' obsolete calendar event' . ($deleted === 1 ? '' : 's');
            }

            $message = $changes
                ? 'Google Calendar sync complete: ' . implode(', ', $changes) . '.'
                : 'Google Calendar is up to date.';

            return $this->outcome(
                true,
                'synced',
                $total,
                $created,
                $updated,
                0,
                $message,
                $done,
                $deleted
            );
        }

        $safe_error = $last_error ?: 'Some sessions could not be synced. Try again shortly.';

        if ($rate_limited) {
            $safe_error = 'Google Calendar rate limited this sync. Wait a minute, then try again.';
        }

        $links->mark_error($uid, $safe_error);

        $failure_status = $fatal_status !== null
            ? $fatal_status
            : 'partial';

        return $this->outcome(
            false,
            $failure_status,
            $total,
            $created,
            $updated,
            $failed,
            $safe_error,
            $done,
            $deleted
        );
    }

    // ------------------------------------------------------------------
    // Event and token helpers
    // ------------------------------------------------------------------

    private function event_timezone()
    {
        $name = trim((string) integration_value('google_calendar', 'timezone', 'Asia/Manila'));

        try {
            return new DateTimeZone($name !== '' ? $name : 'Asia/Manila');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }

    private function event_for_session(array $session, DateTimeZone $timezone, $default_minutes, $stable_id)
    {
        $date = trim((string) ($session['scheduled_date'] ?? ''));
        $time = trim((string) ($session['planned_start_time'] ?? ''));

        if ($time === '') {
            $time = '06:00:00';
        } elseif (preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time .= ':00';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)
        ) {
            return array(
                'ok'      => false,
                'message' => 'A planned session has an invalid date or start time.',
            );
        }

        $start  = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($start === false
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            return array(
                'ok'      => false,
                'message' => 'A planned session has an invalid date or start time.',
            );
        }

        $seconds = (int) ($session['planned_duration_seconds'] ?? 0);

        if ($seconds <= 0) {
            $minutes = max(15, min(1440, (int) $default_minutes));
            $seconds = $minutes * 60;
        }

        $seconds = max(60, min(86400, $seconds));
        $end     = $start->modify('+' . $seconds . ' seconds');

        $type     = trim((string) ($session['type'] ?? 'run'));
        $label    = ucwords(str_replace('_', ' ', $type));
        $distance = $session['planned_distance_km'] === null
            ? null
            : number_format((float) $session['planned_distance_km'], 1, '.', '');
        $summary = 'RunPlan: ' . $label . ($distance === null ? '' : ' (' . $distance . ' km)');

        $description = array('Planned workout from RunPlan.', 'Workout: ' . $label);

        if ($distance !== null) {
            $description[] = 'Distance: ' . $distance . ' km';
        }

        $duration_minutes = (int) ceil($seconds / 60);
        $description[]    = 'Estimated duration: ' . $duration_minutes . ' minutes';
        $notes            = trim((string) ($session['notes'] ?? ''));

        if ($notes !== '') {
            $notes = function_exists('mb_substr')
                ? mb_substr($notes, 0, 1000)
                : substr($notes, 0, 1000);

            $description[] = 'Notes: ' . $notes;
        }

        return array(
            'ok'    => true,
            'event' => array(
                'id'          => $stable_id,
                'summary'     => $summary,
                'description' => implode("\n", $description),
                'start'       => array(
                    'dateTime' => $start->format('Y-m-d\TH:i:sP'),
                    'timeZone' => $timezone->getName(),
                ),
                'end'         => array(
                    'dateTime' => $end->format('Y-m-d\TH:i:sP'),
                    'timeZone' => $timezone->getName(),
                ),
            ),
        );
    }

    private function stable_event_id($user_id, $session_id)
    {
        return 'r' . hash('sha256', 'runplan-google-calendar:' . (int) $user_id . ':' . (int) $session_id);
    }

    private function token_needs_refresh(array $tokens, $now)
    {
        return trim((string) ($tokens['access_token'] ?? '')) === ''
            || (int) ($tokens['expires_at'] ?? 0) <= ((int) $now + 120);
    }

    private function refresh_and_store($user_id, array $tokens, GoogleCalendarService $oauth, TokenVault $vault, CalendarLinkModel $links)
    {
        $refresh = $oauth->refresh((string) ($tokens['refresh_token'] ?? ''), $tokens);

        if (!$refresh['ok']) {
            $message = (string) ($refresh['error'] ?? 'Google Calendar could not refresh its access token.');

            if (($refresh['error_code'] ?? '') === 'revoked') {
                $this->mark_needs_reconnect($user_id, $message);

                return array(
                    'ok'      => false,
                    'status'  => CalendarLinkModel::STATE_NEEDS_RECONNECT,
                    'message' => $message,
                );
            }

            $links->mark_error($user_id, $message);

            return array(
                'ok'      => false,
                'status'  => 'unavailable',
                'message' => $message,
            );
        }

        $fresh = $refresh['tokens'] ?? null;

        if (!is_array($fresh) || trim((string) ($fresh['access_token'] ?? '')) === '') {
            $message = 'Google did not return a refreshed access token.';
            $links->mark_error($user_id, $message);

            return array(
                'ok'      => false,
                'status'  => 'unavailable',
                'message' => $message,
            );
        }

        if (!$this->store_tokens($user_id, $fresh, $vault)) {
            $message = 'Google refreshed the token, but RunPlan could not store it securely.';
            $links->mark_error($user_id, $message);

            return array(
                'ok'      => false,
                'status'  => 'unavailable',
                'message' => $message,
            );
        }

        return array('ok' => true, 'tokens' => $fresh);
    }

    private function store_tokens($user_id, array $tokens, TokenVault $vault)
    {
        $blob = $vault->seal($tokens);

        if ($blob === null) {
            return false;
        }

        try {
            $this->db->table('integration_accounts')
                ->where('user_id', (int) $user_id)
                ->where('provider', CalendarLinkModel::PROVIDER)
                ->update(array(
                    'encrypted_tokens' => $blob,
                    'updated_at'       => gmdate('Y-m-d H:i:s'),
                ));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function mark_needs_reconnect($user_id, $message)
    {
        try {
            // This is a non-secret marker, not a credential. TokenVault::open()
            // fails closed on it so the ordinary status endpoint reports
            // `needs_reconnect`; OAuth reconnect replaces it with a sealed value.
            $this->db->table('integration_accounts')
                ->where('user_id', (int) $user_id)
                ->where('provider', CalendarLinkModel::PROVIDER)
                ->update(array(
                    'encrypted_tokens' => 'reconnect-required',
                    'sync_enabled'     => 0,
                    'last_error'       => substr(trim((string) $message), 0, 255),
                    'updated_at'       => gmdate('Y-m-d H:i:s'),
                ));
        } catch (Throwable $e) {
            // The caller still receives a reconnect-required result.
        }
    }

    // ------------------------------------------------------------------
    // Mapping table
    // ------------------------------------------------------------------

    private function delete_mapping($user_id, $session_id)
    {
        try {
            $this->db->table('calendar_event_mappings')
                ->where('user_id', (int) $user_id)
                ->where('session_id', (int) $session_id)
                ->delete();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function orphan_mappings_for_user($user_id)
    {
        return $this->db->table('calendar_event_mappings')
            ->select('id, provider_event_id, sync_status')
            ->where('user_id', (int) $user_id)
            ->where_null('session_id')
            ->order_by('id', 'ASC')
            ->get_all(PDO::FETCH_ASSOC);
    }

    private function delete_orphan_mapping($user_id, $mapping_id)
    {
        try {
            $this->db->table('calendar_event_mappings')
                ->where('id', (int) $mapping_id)
                ->where('user_id', (int) $user_id)
                ->where_null('session_id')
                ->delete();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function mark_orphan_mapping_failed($user_id, $mapping_id)
    {
        try {
            $this->db->table('calendar_event_mappings')
                ->where('id', (int) $mapping_id)
                ->where('user_id', (int) $user_id)
                ->where_null('session_id')
                ->update(array(
                    'sync_status' => 'failed',
                    'updated_at'  => gmdate('Y-m-d H:i:s'),
                ));
        } catch (Throwable $e) {
            // The tombstone remains for the next sync attempt even if this
            // diagnostic update cannot be written.
        }
    }

    private function mapping_for_session($user_id, $session_id)
    {
        $row = $this->db->table('calendar_event_mappings')
            ->select('id, provider_event_id, sync_status')
            ->where('user_id', (int) $user_id)
            ->where('session_id', (int) $session_id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function save_mapping($user_id, $session_id, $event_id, $sync_status)
    {
        try {
            $existing = $this->mapping_for_session($user_id, $session_id);

            $data = array(
                'provider_event_id' => (string) $event_id,
                'sync_status'       => in_array($sync_status, array('pending', 'synced', 'failed'), true)
                    ? $sync_status
                    : 'failed',
                'updated_at'        => gmdate('Y-m-d H:i:s'),
            );

            if ($existing) {
                $this->db->table('calendar_event_mappings')
                    ->where('id', (int) $existing['id'])
                    ->where('user_id', (int) $user_id)
                    ->update($data);

                return true;
            }

            $this->db->table('calendar_event_mappings')->insert(array_merge($data, array(
                'user_id'    => (int) $user_id,
                'session_id' => (int) $session_id,
            )));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function outcome($ok, $status, $total, $created, $updated, $failed, $message, $processed = 0, $deleted = 0)
    {
        return array(
            'ok'        => (bool) $ok,
            'status'    => (string) $status,
            'total'     => (int) $total,
            'processed' => (int) $processed,
            'remaining' => max(0, (int) $total - (int) $processed),
            'created'   => (int) $created,
            'updated'   => (int) $updated,
            'deleted'   => (int) $deleted,
            'failed'    => (int) $failed,
            'message'   => (string) $message,
        );
    }
}