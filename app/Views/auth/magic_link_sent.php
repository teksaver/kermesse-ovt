<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lien envoyé — Kermesse</title>
    <link rel="stylesheet" href="<?= site_url('assets/css/app.css') ?>">
</head>
<body>
<main class="container container--narrow">
    <h1 class="page-title">Vérifiez votre boîte mail</h1>
    <p>Un lien de connexion a été envoyé à <strong><?= esc($email ?? '') ?></strong>.</p>
    <p>Le lien est valable 15 minutes. Si vous ne le trouvez pas, vérifiez vos spams.</p>
</main>
</body>
</html>
