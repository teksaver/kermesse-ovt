<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Inscription annulée</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8f9fa; border-left: 4px solid #dc3545; padding: 16px 20px; margin-bottom: 24px; }
        .footer { margin-top: 32px; font-size: 0.85em; color: #666; border-top: 1px solid #eee; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0; font-size:1.2em;">Inscription annulée</h1>
    </div>

    <p>Bonjour <?= esc($firstName) ?>,</p>

    <p>
        Votre inscription à la kermesse <strong><?= esc($kermesseName) ?></strong> a été annulée
        par l'équipe organisatrice.
    </p>

    <p>
        Si vous avez des questions, contactez directement les organisateurs de la kermesse.
    </p>

    <div class="footer">
        <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre directement.</p>
    </div>
</body>
</html>
