<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * EX-204/EX-208 : tente une connexion à chaud à chaque appel — la connexion
 * est purgée avant et après la tentative pour ne jamais réutiliser un statut
 * résolu plus tôt dans la même requête (pas de mise en cache entre deux
 * affichages du listing).
 */
class ConnectionAvailability
{
    public function isAvailable(string $connection): bool
    {
        DB::purge($connection);

        try {
            DB::connection($connection)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            DB::purge($connection);
        }
    }
}
