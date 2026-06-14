<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class BackfillBenevolRoleFromSignups extends Migration
{
    public function up(): void
    {
        // Assign the benevole role to every volunteer who has an active signup
        // but no existing row in kermesse_user_roles (i.e. they signed up via
        // the public page before the SignupService was fixed to insert the role).
        // INSERT IGNORE preserves any higher role (owner/admin/gestionnaire) if
        // the user was already a member of the kermesse for another reason.
        $this->db->query(
            'INSERT IGNORE INTO kermesse_user_roles (kermesse_id, user_id, role)
             SELECT DISTINCT sl.kermesse_id, si.user_id, \'benevole\'
             FROM signups si
             JOIN slots sl ON sl.id = si.slot_id
             WHERE si.status = \'active\''
        );
    }

    public function down(): void
    {
        // Intentionally a no-op: we cannot safely distinguish backfilled rows
        // from rows created by SignupService after the fix without a dedicated
        // audit column. Rolling back this migration would require recreating the
        // missing roles anyway.
    }
}
