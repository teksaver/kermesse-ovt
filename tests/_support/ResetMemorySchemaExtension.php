<?php

declare(strict_types=1);

namespace Tests\Support;

use Config\Database;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Réinitialise le schéma de la base de test SQLite :memory: avant chaque test.
 *
 * Pourquoi (contrainte d'infra de test) : CodeIgniter partage l'unique connexion
 * :memory: pour tout le process PHPUnit. La majorité des tests fabriquent leurs tables
 * à la main via « CREATE TABLE IF NOT EXISTS db_… », si bien que le PREMIER test à créer
 * une table fige son schéma pour TOUS les suivants (first-creator-wins). Dès qu'un schéma
 * diverge entre fichiers (cf. refactor 5.13 Signup→SlotSignup), des tests sans rapport
 * cassent en suite alors qu'ils passent en isolation.
 *
 * En droppant les tables métier (préfixe db_) avant chaque test, on garantit que chaque
 * setUp() reconstruit exactement le schéma qu'il déclare. Les tables du framework
 * (migrations, factories) sont préservées pour ne pas casser $migrateOnce.
 */
final class ResetMemorySchemaExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                // Évince la connexion :memory: partagée du cache CI4 avant chaque test, de
                // sorte que le prochain db_connect() construise un OBJET neuf. Cela réinitialise
                // d'un coup :
                //   - le SCHÉMA : nouvelle base :memory: vide → plus de first-creator-wins
                //     entre fichiers qui définissent des schémas db_* divergents (refactor 5.13) ;
                //   - l'ÉTAT de connexion : transDepth/transStatus repartent à zéro. Un simple
                //     close() ne suffit pas — il ferme le connID mais réutilise le même objet,
                //     dont la transaction reste sale après un test qui simule une panne
                //     (SlotServiceTest::...TableUnavailable : DROP TABLE puis INSERT en erreur
                //     sous transBegin).
                // Aucun test SQLite ne dépend de la table `factories` (Fabricator), donc
                // repartir d'une base vide est sûr.
                //
                // BORNÉ À SQLITE : le problème est spécifique à la connexion :memory: partagée.
                // En CI, les tests du groupe `mariadb` tournent sur un vrai serveur persistant
                // avec l'isolation transactionnelle de DatabaseTestTrait ; évincer leur connexion
                // à chaque test la perturbe inutilement. On reste donc inerte hors SQLite.
                if (Database::connect('tests')->getPlatform() !== 'SQLite3') {
                    return;
                }

                $instances = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
                foreach ((array) $instances->getValue() as $connection) {
                    if (is_object($connection) && method_exists($connection, 'close')) {
                        $connection->close();
                    }
                }
                $instances->setValue(null, []);
            }
        });
    }
}
