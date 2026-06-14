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
            <?php if (session()->has('user_id')): ?>
                <?php $currentUser = model(\App\Models\UserModel::class)->find(session('user_id')); ?>
                <div style="display:flex; align-items:center; gap:16px;">
                    <?php if ($currentUser): ?>
                        <span style="font-size:14px; color:var(--color-text-muted);">
                            <?= esc(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) ?: $currentUser['email']) ?>
                        </span>
                    <?php endif; ?>
                    <form method="post" action="<?= site_url('auth/logout') ?>" class="app-nav__logout" style="margin:0;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-secondary">Se déconnecter</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="post" action="<?= site_url('auth/logout') ?>" class="app-nav__logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-secondary">Se déconnecter</button>
                </form>
            <?php endif; ?>
        </div>
    </nav>
    <main class="container">
        <?= $this->renderSection('content') ?>
    </main>
</body>
</html>
