<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Invitation à gérer une kermesse</title>
</head>
<body style="margin:0; padding:0; background:#F8FAFC; font-family:system-ui,-apple-system,sans-serif; font-size:16px; line-height:1.5; color:#111827;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F8FAFC;">
<tr><td align="center" style="padding:32px 16px;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:560px; background:#FFFFFF; border:1px solid #CBD5E1; border-radius:8px;">
    <tr><td style="padding:32px 24px;">
        <h1 style="margin:0 0 16px; font-size:24px; font-weight:700; color:#111827;">
            Vous êtes invité·e à gérer une kermesse
        </h1>
        <p style="margin:0 0 24px; color:#475569;">
            Vous avez été invité·e comme <strong><?= esc($roleLabel ?? '') ?></strong> de la kermesse
            <strong>« <?= esc($kermesseName ?? '') ?> »</strong>. Cliquez sur le bouton ci-dessous pour vous connecter et accéder à son tableau de bord. Ce lien est valable <strong><?= (int) ($ttlMinutes ?? 15) ?> minutes</strong> et ne peut être utilisé qu'une seule fois.
        </p>
        <table cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
        <tr><td style="border-radius:8px; background:#2563EB;">
            <a href="<?= esc($invitationUrl ?? '') ?>"
               style="display:inline-block; padding:14px 28px; font-size:16px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:8px;">
                Accéder à la kermesse
            </a>
        </td></tr>
        </table>
        <p style="margin:0 0 16px; color:#475569; font-size:14px;">
            Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :
        </p>
        <p style="margin:0 0 24px; word-break:break-all; color:#2563EB; font-size:14px;">
            <?= esc($invitationUrl ?? '') ?>
        </p>
        <hr style="border:none; border-top:1px solid #CBD5E1; margin:24px 0;">
        <p style="margin:0; color:#475569; font-size:13px;">
            Vous recevez cet email parce qu'une personne organisant cette kermesse vous a invité·e à l'aider. Si vous pensez qu'il s'agit d'une erreur, vous pouvez ignorer cet email.
        </p>
    </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
