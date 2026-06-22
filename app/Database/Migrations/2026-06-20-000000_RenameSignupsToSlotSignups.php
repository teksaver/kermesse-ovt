<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameSignupsToSlotSignups extends Migration
{
    public function up()
    {
        /** @var \CodeIgniter\Database\BaseConnection $db */
        $db = $this->db;
        // SQLite (tests) uses ALTER TABLE … RENAME TO; MariaDB supports the same syntax.
        $db->query('ALTER TABLE ' . $db->prefixTable('signups') . ' RENAME TO ' . $db->prefixTable('slot_signups'));
    }

    public function down()
    {
        /** @var \CodeIgniter\Database\BaseConnection $db */
        $db = $this->db;
        $db->query('ALTER TABLE ' . $db->prefixTable('slot_signups') . ' RENAME TO ' . $db->prefixTable('signups'));
    }
}
