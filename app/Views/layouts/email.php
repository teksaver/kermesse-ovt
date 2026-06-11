<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Kermesse') ?></title>
</head>
<body style="margin:0;padding:16px;font-family:system-ui,-apple-system,sans-serif;background:#F8FAFC;color:#111827;">
    <?= $this->renderSection('content') ?>
</body>
</html>
