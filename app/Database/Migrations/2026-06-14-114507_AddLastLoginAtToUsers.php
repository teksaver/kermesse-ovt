<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLastLoginAtToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'last_login_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'default' => null,
            ],
        ]);

        // Mettre à jour les utilisateurs existants pour qu'ils soient considérés comme "acceptés"
        // On utilise la date actuelle comme approximation de leur dernière connexion
        $this->db->table('users')->update(['last_login_at' => date('Y-m-d H:i:s')]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'last_login_at');
    }
}
