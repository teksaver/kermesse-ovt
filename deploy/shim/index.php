<?php

/**
 * Résout le répertoire de la release active sous le layout Ouvaton :
 *
 *   <home>/kermesse/current             → symlink vers releases/<name>  (méthode 1)
 *   <home>/kermesse/CURRENT_RELEASE     → fichier texte avec le nom du dossier (méthode 2)
 *
 * @param string $kermDir Chemin absolu vers <home>/kermesse/
 *
 * @return string|false Chemin réel de la release active, ou false si aucune release n'est trouvée.
 */
function shim_find_release_dir(string $kermDir): string|false
{
    // Méthode 1 : symlink `current` → releases/<name>
    $currentLink = $kermDir . '/current';
    if (is_dir($currentLink)) {
        $resolved = realpath($currentLink);
        if ($resolved !== false) {
            return $resolved;
        }
    }

    // Méthode 2 (repli) : fichier pointeur `CURRENT_RELEASE`
    $pointerFile = $kermDir . '/CURRENT_RELEASE';
    if (is_readable($pointerFile)) {
        $releaseName = trim((string) file_get_contents($pointerFile));
        if ($releaseName !== '') {
            $candidate = $kermDir . '/releases/' . $releaseName;
            if (is_dir($candidate)) {
                $resolved = realpath($candidate);
                if ($resolved !== false) {
                    return $resolved;
                }
            }
        }
    }

    return false;
}

// Ce bloc est ignoré quand le shim est chargé par les tests unitaires.
if (!defined('KERMESSE_SHIM_TESTING')) {
    $shimMinPhp = '8.2';
    if (version_compare(PHP_VERSION, $shimMinPhp, '<')) {
        header('HTTP/1.1 503 Service Unavailable.', true, 503);
        echo sprintf('PHP %s+ requis. Version actuelle : %s', $shimMinPhp, PHP_VERSION);
        exit(1);
    }

    // Layout Ouvaton :
    //   <home>/httpdocs/index.php   ← ce fichier (web root)
    //   <home>/kermesse/            ← dossier privé hors web root
    $shimFcPath = __DIR__ . DIRECTORY_SEPARATOR;   // .../httpdocs/
    $kermDir    = dirname(__DIR__) . '/kermesse';  // .../kermesse/

    $releaseDir = shim_find_release_dir($kermDir);

    if ($releaseDir === false) {
        header('HTTP/1.1 503 Service Unavailable.', true, 503);
        echo 'Aucune release active trouvée.';
        exit(1);
    }

    // FCPATH = répertoire du contrôleur frontal (httpdocs/) — constante CodeIgniter
    define('FCPATH', $shimFcPath);

    // Chargement de la configuration des chemins depuis la release active.
    // Config\Paths::$systemDirectory et $appDirectory sont calculés par __DIR__
    // depuis app/Config/ — ils pointent correctement dans la release.
    require $releaseDir . '/app/Config/Paths.php';
    $paths = new Config\Paths();

    // Surcharge des chemins qui vivent dans shared/ (hors releases) :
    // - writable/ : cache, session, logs, uploads
    // - envDirectory : emplacement du .env de production
    $paths->writableDirectory = $kermDir . '/shared/writable';
    $paths->envDirectory      = $kermDir . '/shared';

    // Démarrage de CodeIgniter depuis la release active.
    require $paths->systemDirectory . '/Boot.php';
    exit(\CodeIgniter\Boot::bootWeb($paths));
}
