<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Changement dans l'équipe</title>
</head>
<body style="margin:0; padding:0; background:#F8FAFC; font-family:system-ui,-apple-system,sans-serif; font-size:16px; line-height:1.5; color:#111827;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F8FAFC;">
<tr><td align="center" style="padding:32px 16px;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:560px; background:#FFFFFF; border:1px solid #CBD5E1; border-radius:8px;">
    <tr><td style="padding:32px 24px;">
        <h1 style="margin:0 0 16px; font-size:24px; font-weight:700; color:#111827;">
            Changement dans votre équipe
        </h1>
        <p style="margin:0 0 8px; color:#475569;">
            Bonjour <?= esc($ownerFirstName ?? '') ?>,
        </p>
        <p style="margin:0 0 24px; color:#475569;">
            Un changement vient d'être effectué dans l'équipe de la kermesse
            <strong>« <?= esc($kermesseName ?? '') ?> »</strong>&nbsp;:
        </p>

        <?php
        $action       = $action       ?? '';
        $memberName   = $memberName   ?? '';
        $actorName    = $actorName    ?? '';
        $roleLabel    = $roleLabel    ?? '';
        $oldRoleLabel = $oldRoleLabel ?? '';
        ?>

        <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
               style="margin:0 0 24px; border-radius:8px; border:1px solid #E2E8F0; background:#F8FAFC;">
        <tr><td style="padding:20px 24px;">
            <?php if ($action === 'joined'): ?>
                <p style="margin:0; font-size:16px; color:#111827;">
                    ✅ <strong><?= esc($memberName) ?></strong> a rejoint votre équipe
                    en tant que <strong><?= esc($roleLabel) ?></strong>.
                </p>
                <p style="margin:8px 0 0; font-size:14px; color:#64748B;">
                    Invité·e par <strong><?= esc($actorName) ?></strong>.
                </p>
            <?php elseif ($action === 'left'): ?>
                <p style="margin:0; font-size:16px; color:#111827;">
                    👋 <strong><?= esc($memberName) ?></strong> a quitté votre équipe de son plein gré.
                </p>
                <p style="margin:8px 0 0; font-size:14px; color:#64748B;">
                    Son rôle était : <strong><?= esc($roleLabel) ?></strong>.
                </p>
            <?php elseif ($action === 'removed'): ?>
                <p style="margin:0; font-size:16px; color:#111827;">
                    🗑️ <strong><?= esc($memberName) ?></strong> a été retiré·e de l'équipe.
                </p>
                <p style="margin:8px 0 0; font-size:14px; color:#64748B;">
                    Action effectuée par <strong><?= esc($actorName) ?></strong>.
                    Rôle retiré : <strong><?= esc($roleLabel) ?></strong>.
                </p>
            <?php elseif ($action === 'role_changed'): ?>
                <p style="margin:0; font-size:16px; color:#111827;">
                    🔄 Le rôle de <strong><?= esc($memberName) ?></strong> a été modifié.
                </p>
                <p style="margin:8px 0 0; font-size:14px; color:#64748B;">
                    <?= esc(ucfirst($oldRoleLabel)) ?> → <strong><?= esc($roleLabel) ?></strong>,
                    par <strong><?= esc($actorName) ?></strong>.
                </p>
            <?php else: ?>
                <p style="margin:0; font-size:16px; color:#111827;">
                    Un changement a été effectué pour <strong><?= esc($memberName) ?></strong>.
                </p>
            <?php endif; ?>
        </td></tr>
        </table>

        <hr style="border:none; border-top:1px solid #CBD5E1; margin:24px 0;">
        <p style="margin:0; color:#475569; font-size:13px;">
            Vous recevez cet email en tant que propriétaire de cette kermesse.
            Connectez-vous à votre tableau de bord pour consulter l'état de votre équipe.
        </p>
    </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
