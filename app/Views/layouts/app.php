<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Padlapin') ?></title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
    <nav class="app-nav">
        <div class="app-nav__inner">
            <a href="/" class="app-nav__brand">Padlapin</a>
            <form method="post" action="<?= site_url('auth/logout') ?>" class="app-nav__logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-secondary">Se déconnecter</button>
            </form>
        </div>
    </nav>
    <main class="container">
        <?= $this->renderSection('content') ?>
    </main>
</body>
</html>
