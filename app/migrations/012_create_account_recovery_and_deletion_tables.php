<?php

class Create_account_recovery_and_deletion_tables {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    private function id()
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE];
    }

    private function ref($null = FALSE)
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => $null];
    }

    private function now()
    {
        return ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'];
    }

    public function up()
    {
        $f = $this->_lava->dbforge;

        if (!$f->table_exists('password_reset_tokens')) {
            $f->add_field([
                'id'         => $this->id(),
                'user_id'    => $this->ref(),
                'token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => FALSE],
                'expires_at' => ['type' => 'DATETIME', 'null' => FALSE],
                'used_at'    => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
                'created_at' => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('token_hash', unique: TRUE, name: 'token_hash_unique')
            ->add_key(['user_id', 'expires_at'], name: 'user_expires_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('password_reset_tokens');
        }

        if (!$f->table_exists('account_deletion_requests')) {
            $f->add_field([
                'id'           => $this->id(),
                'user_id'      => $this->ref(TRUE),
                'email_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => FALSE],
                'status'       => ['type' => 'ENUM', 'constraint' => "'requested','completed','failed'",
                                   'null' => FALSE, 'default' => 'completed'],
                'reason'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE, 'default' => NULL],
                'requested_at' => $this->now(),
                'completed_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('user_id', name: 'user_id_idx')
            ->add_key(['status', 'requested_at'], name: 'status_requested_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'SET NULL', 'CASCADE')
            ->create_table('account_deletion_requests');
        }
    }

    public function down()
    {
        foreach (['account_deletion_requests', 'password_reset_tokens'] as $t) {
            $this->_lava->dbforge->drop_table($t);
        }
    }
}