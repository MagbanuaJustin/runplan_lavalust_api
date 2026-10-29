<?php

/**
 * Migration 016 - preserve Google event mappings when a training session is deleted.
 *
 * Plan revisions replace future session rows. The old ON DELETE CASCADE erased
 * their mappings before Google Calendar could be asked to delete the events.
 * This migration keeps the mapping row with a NULL session_id instead; the next
 * explicit calendar sync retries provider deletion and only then removes it.
 */
class Preserve_calendar_mapping_orphans {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    private function session_foreign_key()
    {
        return $this->_lava->db->raw(
            "SELECT kcu.CONSTRAINT_NAME, rc.DELETE_RULE
               FROM information_schema.KEY_COLUMN_USAGE kcu
               JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                AND rc.TABLE_NAME = kcu.TABLE_NAME
                AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = 'calendar_event_mappings'
                AND kcu.COLUMN_NAME = 'session_id'
                AND kcu.REFERENCED_TABLE_NAME = 'training_sessions'
              LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('calendar_event_mappings')) {
            return;
        }

        $foreign_key = $this->session_foreign_key();
        if ($foreign_key && strtoupper((string) $foreign_key['DELETE_RULE']) !== 'SET NULL') {
            $name = str_replace('`', '``', (string) $foreign_key['CONSTRAINT_NAME']);
            $this->_lava->db->raw(
                'ALTER TABLE `calendar_event_mappings` DROP FOREIGN KEY `' . $name . '`'
            );
            $foreign_key = null;
        }

        $column = $this->_lava->db->raw(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'calendar_event_mappings'
                AND COLUMN_NAME = 'session_id'
              LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if ($column && strtoupper((string) $column['IS_NULLABLE']) !== 'YES') {
            $this->_lava->db->raw(
                'ALTER TABLE `calendar_event_mappings` MODIFY COLUMN `session_id` INT(11) UNSIGNED NULL'
            );
        }

        if (!$foreign_key) {
            $this->_lava->db->raw(
                'ALTER TABLE `calendar_event_mappings`
                 ADD CONSTRAINT `calendar_event_mappings_session_fk`
                 FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`)
                 ON DELETE SET NULL ON UPDATE CASCADE'
            );
        }
    }

    public function down()
    {
        // Intentionally do not restore ON DELETE CASCADE. Rows with session_id
        // NULL are retryable Google-event deletion tombstones; cascading them
        // away would recreate the orphan-event bug this migration prevents.
    }
}