<?php

class Add_plan_creation_mode
{
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

    private function has_column($table, $column)
    {
        return $this->_lava->dbforge->column_exists($table, $column);
    }

    public function up()
    {
        if (!$this->has_column('training_plans', 'creation_mode')) {
            $this->run(
                "ALTER TABLE `training_plans`
                 ADD COLUMN `creation_mode`
                 ENUM('generated', 'manual')
                 NOT NULL DEFAULT 'generated'
                 AFTER `sessions_per_week`"
            );
        }
    }

    public function down()
    {
        if ($this->has_column('training_plans', 'creation_mode')) {
            $this->run(
                'ALTER TABLE `training_plans` DROP COLUMN `creation_mode`'
            );
        }
    }
}