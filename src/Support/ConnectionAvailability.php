<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Connectors\ConnectionFactory;
use PDO;
use Throwable;

/**
 * EX-204/EX-208 : tente une connexion à chaud à chaque appel, via un objet
 * `Connection` jetable construit directement par `ConnectionFactory` —
 * jamais enregistré dans le `DatabaseManager` ni écrit dans le repository de
 * config partagé de l'application. Contrairement à `DB::connection($name)`,
 * rien n'est donc à purger avant/après, et aucune mutation de config ne peut
 * fuiter vers une autre requête traitée par le même worker (Octane).
 *
 * `modelbase.connection_timeout` borne cette tentative pour les drivers qui
 * exposent un réglage de timeout de connexion (mysql/mariadb via
 * PDO::ATTR_TIMEOUT, sqlsrv via login_timeout) : sans ça, une connexion vers
 * un hôte injoignable peut bloquer l'affichage du listing sur le timeout,
 * bien plus long, de l'OS ou du driver. pgsql et sqlite n'exposent pas
 * d'équivalent via les connecteurs Laravel.
 */
class ConnectionAvailability
{
    public function __construct(private ConnectionFactory $factory)
    {
    }

    public function isAvailable(string $connection): bool
    {
        $config = config("database.connections.{$connection}", []);

        try {
            $this->factory->make($this->withTimeout($config), $connection)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withTimeout(array $config): array
    {
        $timeout = config('modelbase.connection_timeout', 3);

        return match ($config['driver'] ?? null) {
            'mysql', 'mariadb' => [
                ...$config,
                'options' => ($config['options'] ?? []) + [PDO::ATTR_TIMEOUT => $timeout],
            ],
            'sqlsrv' => [...$config, 'login_timeout' => $config['login_timeout'] ?? $timeout],
            default => $config,
        };
    }
}
