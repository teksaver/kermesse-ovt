<?php
// Slot times are rendered defensively: a malformed datetime must not break the
// email (the signup is already recorded) — fall back to the raw string.
try {
    $slotDate  = \CodeIgniter\I18n\Time::parse((string) $slotStartsAt)->format('d/m/Y');
    $slotHours = \CodeIgniter\I18n\Time::parse((string) $slotStartsAt)->format('H:i')
        . ' - ' . \CodeIgniter\I18n\Time::parse((string) $slotEndsAt)->format('H:i');
} catch (\Exception $e) {
    $slotDate  = (string) $slotStartsAt;
    $slotHours = '? - ' . (string) $slotEndsAt;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Votre inscription est confirmée</title>
</head>
<body style="margin:0; padding:0; background:#F8FAFC; font-family:system-ui,-apple-system,sans-serif; font-size:16px; line-height:1.5; color:#111827;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F8FAFC;">
<tr><td align="center" style="padding:32px 16px;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:560px; background:#FFFFFF; border:1px solid #CBD5E1; border-radius:8px;">
    <tr><td style="padding:32px 24px;">
        <h1 style="margin:0 0 16px; font-size:24px; font-weight:700; color:#111827;">
            Merci, <?= esc($firstName) ?> !
        </h1>
        <p style="margin:0 0 16px; color:#475569;">
            Votre inscription à la kermesse <strong>« <?= esc($kermesseName) ?> »</strong>
            est bien confirmée. Voici le récapitulatif de votre créneau :
        </p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
               style="margin:0 0 24px; background:#F8FAFC; border:1px solid #CBD5E1; border-radius:8px;">
        <tr><td style="padding:16px 24px;">
            <p style="margin:0 0 8px; color:#111827;">
                <strong>Stand :</strong> <?= esc($standName) ?>
            </p>
            <p style="margin:0 0 8px; color:#111827;">
                <strong>Date :</strong> <?= esc($slotDate) ?>
            </p>
            <p style="margin:0; color:#111827;">
                <strong>Horaires :</strong> <?= esc($slotHours) ?>
            </p>
        </td></tr>
        </table>
        <p style="margin:0 0 16px; color:#475569;">
            Pensez à noter ce créneau dans votre agenda. Toute l'équipe compte sur vous !
        </p>
        <?php if (!empty($magicLinkUrl)): ?>
        <p style="margin:0 0 16px; color:#475569;">
            Pour retrouver ou gérer vos participations, cliquez sur le bouton ci-dessous.
            Ce lien est valable <strong><?= (int) ($ttlMinutes ?? 15) ?> minutes</strong> et ne peut être utilisé qu'une seule fois.
        </p>
        <table cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
        <tr><td style="border-radius:8px; background:#2563EB;">
            <a href="<?= esc($magicLinkUrl) ?>"
               style="display:inline-block; padding:14px 28px; font-size:16px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:8px;">
                Gérer mes participations
            </a>
        </td></tr>
        </table>
        <p style="margin:0 0 24px; word-break:break-all; color:#2563EB; font-size:14px;">
            <?= esc($magicLinkUrl) ?>
        </p>
        <?php endif; ?>
        <hr style="border:none; border-top:1px solid #CBD5E1; margin:24px 0;">
        <p style="margin:0; color:#475569; font-size:13px;">
            Vous recevez cet email parce que cette adresse a été utilisée pour
            s'inscrire comme bénévole. Si vous n'êtes pas à l'origine de cette
            inscription, vous pouvez ignorer cet email.
        </p>
    </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
