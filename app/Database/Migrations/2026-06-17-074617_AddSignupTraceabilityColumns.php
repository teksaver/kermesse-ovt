<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSignupTraceabilityColumns extends Migration
{
    public function up()
    {
        $fields = [
            'created_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'viewed_at'   => ['type' => 'DATETIME', 'null' => true],
            'accepted_at' => ['type' => 'DATETIME', 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'canceled_at' => ['type' => 'DATETIME', 'null' => true],
            'canceled_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ];

        $this->forge->addColumn('signups', $fields);

        // Story 5.14 : user_id becomes nullable (anonymous signups)
        $this->forge->modifyColumn('signups', [
            'user_id' => [
                'name' => 'user_id',
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
            ],
        ]);

        // Index for email-based duplicate/overlap checks introduced in Story 5.11/5.14.
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_signups_email ON ' . $this->db->prefixTable('signups') . ' (email)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS idx_signups_email ON ' . $this->db->prefixTable('signups'));
        $this->forge->dropColumn('signups', 'created_by');
        $this->forge->dropColumn('signups', 'viewed_at');
        $this->forge->dropColumn('signups', 'accepted_at');
        $this->forge->dropColumn('signups', 'rejected_at');
        $this->forge->dropColumn('signups', 'canceled_at');
        $this->forge->dropColumn('signups', 'canceled_by');

        // Note: CodeIgniter's dropColumn does not support reverting modifyColumn easily.
        // In a real rollback, we would need to ensure no nulls exist and revert to NOT NULL.
        $this->forge->modifyColumn('signups', [
            'user_id' => [
                'name' => 'user_id',
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
            ],
        ]);
    }
}
