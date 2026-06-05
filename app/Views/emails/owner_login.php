<?php
$safeLoginUrl = filter_var($loginUrl ?? '', FILTER_VALIDATE_URL) ? (string) $loginUrl : '';
$loginScheme  = strtolower((string) parse_url($safeLoginUrl, PHP_URL_SCHEME));

if ($safeLoginUrl === '' || ! in_array($loginScheme, ['http', 'https'], true)) {
    throw new InvalidArgumentException('A valid absolute login URL is required.');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connectez-vous à votre kermesse</title>
</head>
<body style="margin:0; padding:0; background:#F8FAFC; font-family:system-ui,-apple-system,sans-serif; font-size:16px; line-height:1.5; color:#111827;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F8FAFC;">
<tr><td align="center" style="padding:32px 16px;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:560px; background:#FFFFFF; border:1px solid #CBD5E1; border-radius:8px;">
    <tr><td style="padding:32px 24px;">
        <h1 style="margin:0 0 16px; font-size:24px; font-weight:700; color:#111827;">
            Bonjour, <?= esc($ownerName) ?> !
        </h1>
        <p style="margin:0 0 16px; color:#475569;">
            Vous avez demandé un lien de connexion pour votre kermesse
            <strong>« <?= esc($kermesseName) ?> »</strong>.
            Cliquez sur le bouton ci-dessous pour accéder à l'espace d'administration.
        </p>
        <p style="margin:0 0 24px; text-align:center;">
            <a href="<?= esc($safeLoginUrl, 'attr') ?>"
               style="display:inline-block; padding:12px 32px; background:#166534; color:#FFFFFF; text-decoration:none; border-radius:8px; font-weight:700; font-size:16px;">
                Accéder à l'espace admin
            </a>
        </p>
        <p style="margin:0 0 8px; color:#475569; font-size:14px;">
            Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :
        </p>
        <p style="margin:0 0 16px; word-break:break-all; color:#2563EB; font-size:14px;">
            <?= esc($safeLoginUrl) ?>
        </p>
        <hr style="border:none; border-top:1px solid #CBD5E1; margin:24px 0;">
        <p style="margin:0; color:#475569; font-size:13px;">
            Ce lien est valable <?= (int) ($ttlMinutes ?? 15) ?> minutes. Si vous n'avez pas fait cette demande,
            vous pouvez ignorer cet email.
        </p>
    </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
