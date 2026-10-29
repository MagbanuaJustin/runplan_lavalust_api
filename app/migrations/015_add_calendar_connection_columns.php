<?php

/**
 * Migration 015 - Google Calendar connection metadata (spec 5.3).
 *
 * Migration 009 already created `integration_accounts` with the two columns that
 * matter for security: `encrypted_tokens` and `scopes`. Those are enough to
 * connect an account. What is missing is everything a runner can *see* and
 * control afterwards:
 *
 *   calendar_id    - which Google calendar receives the sessions ('primary').
 *   sync_enabled   - pause syncing without revoking access.
 *   last_synced_at - "last synced 4 minutes ago" in the UI.
 *   last_error     - why the last attempt failed, so a runner is not left
 *                    guessing after a revoked grant.
 *   updated_at     - when the row last changed, for support and debugging.
 *
 * Additive and idempotent: `column_exists()` guards every ALTER, so re-running
 * the migration is a no-op and an existing connection keeps working.
 */
class Add_calendar_connection_columns {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    private function has_col($table, $column)
    {
        return $this->_lava->dbforge->column_exists($table, $column);
    }

    private function add_col($table, $column, $definition)
    {
        if (!$this->has_col($table, $column)) {
            $this->_lava->db->raw(
                'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition
            );
        }
    }

    private function drop_col($table, $column)
    {
        if ($this->has_col($table, $column)) {
            $this->_lava->db->raw(
                'ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`'
            );
        }
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('integration_accounts')) {
            return;
        }

        $this->add_col(
            'integration_accounts',
            'calendar_id',
            'VARCHAR(191) NULL DEFAULT NULL AFTER `scopes`'
        );

        $this->add_col(
            'integration_accounts',
            'sync_enabled',
            'TINYINT(1) NOT NULL DEFAULT 1 AFTER `calendar_id`'
        );

        $this->add_col(
            'integration_accounts',
            'last_synced_at',
            'DATETIME NULL DEFAULT NULL AFTER `sync_enabled`'
        );

        $this->add_col(
            'integration_accounts',
            'last_error',
            'VARCHAR(255) NULL DEFAULT NULL AFTER `last_synced_at`'
        );

        $this->add_col(
            'integration_accounts',
            'updated_at',
            'DATETIME NULL DEFAULT NULL AFTER `last_error`'
        );
    }

    public function down()
    {
        if (!$this->_lava->dbforge->table_exists('integration_accounts')) {
            return;
        }

        foreach (['updated_at', 'last_error', 'last_synced_at', 'sync_enabled', 'calendar_id'] as $column) {
            $this->drop_col('integration_accounts', $column);
        }
    }
}