<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= esc($kermesse['name']) ?> – Espace admin – Kermesse</title>
    <meta name="description" content="Espace d'administration de votre kermesse.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="form-panel" aria-labelledby="page-title">
        <h1 id="page-title" class="page-title"><?= esc($kermesse['name']) ?></h1>

        <div class="status-badge status-badge--preparation" aria-label="Statut : Inscriptions en préparation">
            Inscriptions en préparation
        </div>

	        <div class="info-box info-box--spaced">
            <p>
                Votre kermesse est en cours de configuration.<br>
                La gestion des stands et des créneaux sera disponible prochainement.
            </p>
        </div>
    </section>
</main>
</body>
</html>
