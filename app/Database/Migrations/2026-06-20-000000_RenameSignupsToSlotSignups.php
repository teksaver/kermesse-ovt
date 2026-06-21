<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameSignupsToSlotSignups extends Migration
{
    public function up()
    {
        // SQLite (tests) uses ALTER TABLE … RENAME TO; MariaDB supports the same syntax.
        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('signups') . ' RENAME TO ' . $this->db->prefixTable('slot_signups'));
    }

    public function down()
    {
        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('slot_signups') . ' RENAME TO ' . $this->db->prefixTable('signups'));
    }
}
