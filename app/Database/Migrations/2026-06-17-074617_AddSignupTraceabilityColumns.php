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

    public function down()
    {
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
