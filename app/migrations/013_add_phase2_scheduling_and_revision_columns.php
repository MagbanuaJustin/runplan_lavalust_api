<?php

class Add_phase2_scheduling_and_revision_columns {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    private function run($sql)
    {
        $this->_lava->db->raw($sql);
    }

    private function has_col($table, $column)
    {
        return $this->_lava->dbforge->column_exists($table, $column);
    }

    private function has_index($table, $index_name)
    {
        $stmt = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index_name]
        );
        return (int) $stmt->fetchColumn() > 0;
    }

    private function add_col($table, $column, $definition)
    {
        if (!$this->has_col($table, $column)) {
            $this->run("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function drop_col($table, $column)
    {
        if ($this->has_col($table, $column)) {
            $this->run("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }

    public function up()
    {
        // 1. runner_profiles: optional weekly run frequency preference (2-6 days/wk)
        $this->add_col(
            'runner_profiles',
            'desired_sessions_per_week',
            'TINYINT UNSIGNED NULL DEFAULT NULL AFTER `available_days`'
        );

        // 2. training_sessions: preferred start time + skip reason
        $this->add_col(
            'training_sessions',
            'planned_start_time',
            'TIME NULL DEFAULT NULL AFTER `scheduled_date`'
        );
        $this->add_col(
            'training_sessions',
            'skip_reason',
            'VARCHAR(255) NULL DEFAULT NULL AFTER `status`'
        );

        // 3. plan_revisions: support propose -> apply / reject workflow & audit columns
        if ($this->has_index('plan_revisions', 'plan_version_unique')) {
            if (!$this->has_index('plan_revisions', 'plan_version_idx')) {
                $this->run('ALTER TABLE `plan_revisions` ADD INDEX `plan_version_idx` (`plan_id`, `version`)');
            }
            $this->run('ALTER TABLE `plan_revisions` DROP INDEX `plan_version_unique`');
        }

        $this->add_col(
            'plan_revisions',
            'previous_version',
            'INT UNSIGNED NULL DEFAULT NULL AFTER `version`'
        );
        $this->add_col(
            'plan_revisions',
            'resulting_version',
            'INT UNSIGNED NULL DEFAULT NULL AFTER `previous_version`'
        );
        $this->add_col(
            'plan_revisions',
            'decision',
            "ENUM('applied','pending','rejected') NOT NULL DEFAULT 'applied' AFTER `resulting_version`"
        );
        $this->add_col(
            'plan_revisions',
            'reason',
            'VARCHAR(255) NULL DEFAULT NULL AFTER `change_summary`'
        );
        $this->add_col(
            'plan_revisions',
            'proposed_changes',
            'JSON NULL DEFAULT NULL AFTER `snapshot_json`'
        );
        $this->add_col(
            'plan_revisions',
            'decision_timestamp',
            'DATETIME NULL DEFAULT NULL AFTER `proposed_changes`'
        );

        // Backfill resulting_version on existing applied revision rows
        $this->run(
            "UPDATE `plan_revisions`
                SET `resulting_version` = `version`,
                    `decision_timestamp` = COALESCE(`decision_timestamp`, `created_at`)
              WHERE `decision` = 'applied' AND `resulting_version` IS NULL"
        );
    }

    public function down()
    {
        foreach (['decision_timestamp', 'proposed_changes', 'reason', 'decision', 'resulting_version', 'previous_version'] as $col) {
            $this->drop_col('plan_revisions', $col);
        }

        if (!$this->has_index('plan_revisions', 'plan_version_unique')) {
            $this->run('ALTER TABLE `plan_revisions` ADD UNIQUE INDEX `plan_version_unique` (`plan_id`, `version`)');
        }
        if ($this->has_index('plan_revisions', 'plan_version_idx')) {
            $this->run('ALTER TABLE `plan_revisions` DROP INDEX `plan_version_idx`');
        }

        $this->drop_col('training_sessions', 'skip_reason');
        $this->drop_col('training_sessions', 'planned_start_time');
        $this->drop_col('runner_profiles', 'desired_sessions_per_week');
    }
}