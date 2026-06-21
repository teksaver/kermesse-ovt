<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Inscription annulée</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8f9fa; border-left: 4px solid #dc3545; padding: 16px 20px; margin-bottom: 24px; }
        .slot-box { background: #fff3f3; border: 1px solid #f5c6cb; border-radius: 6px; padding: 12px 16px; margin: 20px 0; }
        .footer { margin-top: 32px; font-size: 0.85em; color: #666; border-top: 1px solid #eee; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0; font-size:1.2em;">Inscription annulée</h1>
    </div>

    <p>Bonjour <?= esc($firstName) ?>,</p>

    <p>
        L'organisateur de la kermesse <strong><?= esc($kermesseName) ?></strong> a annulé
        l'une de vos inscriptions :
    </p>

    <?php if (($slotLabel ?? '') !== '') : ?>
    <div class="slot-box">
        <strong><?= esc($slotLabel) ?></strong>
    </div>
    <?php endif ?>

    <p>
        Vos autres inscriptions à cette kermesse ne sont pas concernées par ce message.
        Si vous avez des questions, contactez directement les organisateurs.
    </p>

    <div class="footer">
        <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre directement.</p>
    </div>
</body>
</html>
