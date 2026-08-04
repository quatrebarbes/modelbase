<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Connection;

/**
 * Estimation approchée du nombre de lignes d'une table à partir des
 * statistiques du SGBD (EX-312), pour éviter un `COUNT(*)` exact coûteux sur
 * une table de grande taille. Retourne `null` quand le driver n'expose
 * aucune statistique exploitable (ex. sqlite) : `ModelRepository` retombe
 * alors sur un comptage exact.
 *
 * Ces statistiques ne sont mises à jour par le SGBD qu'à intervalle
 * irrégulier (ANALYZE/VACUUM en pgsql, notamment) : `ModelRepository` ne s'y
 * fie donc que lorsque l'estimation dépasse déjà le premier seuil d'affichage
 * (EX-312), pour ne jamais afficher une valeur fantaisiste (ex. 0) sur une
 * table de petite taille, où un comptage exact reste de toute façon peu
 * coûteux.
 *
 * Constaté en vérifiant manuellement contre pgsql réel (docker-compose,
 * Phase 17) : `pg_class.reltuples` vaut `-1`, pas `0`, pour une table jamais
 * analysée — traité explicitement comme une estimation inconnue plutôt que
 * comme un nombre de lignes négatif.
 */
class ItemCountEstimator
{
    public function estimate(Connection $connection, string $table): ?int
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $this->fromInformationSchema($connection, $table),
            'pgsql' => $this->fromPgClass($connection, $table),
            'sqlsrv' => $this->fromSysPartitions($connection, $table),
            default => null,
        };
    }

    private function fromInformationSchema(Connection $connection, string $table): ?int
    {
        $row = $connection->selectOne(
            'select TABLE_ROWS as estimate from information_schema.tables where table_schema = ? and table_name = ?',
            [$connection->getDatabaseName(), $connection->getTablePrefix().$table]
        );

        return $row?->estimate !== null ? (int) $row->estimate : null;
    }

    private function fromPgClass(Connection $connection, string $table): ?int
    {
        $row = $connection->selectOne(
            'select reltuples::bigint as estimate from pg_class where oid = to_regclass(?)',
            [$connection->getTablePrefix().$table]
        );

        if ($row?->estimate === null) {
            return null;
        }

        // `reltuples` vaut -1 pour une table jamais analysée (ANALYZE/
        // VACUUM) : une sentinelle pgsql, pas un nombre de lignes.
        $estimate = (int) $row->estimate;

        return $estimate >= 0 ? $estimate : null;
    }

    /**
     * Non vérifié faute d'instance sqlsrv joignable dans l'environnement de
     * développement (même limite que `ColumnIntrospector`, Phase 4a).
     */
    private function fromSysPartitions(Connection $connection, string $table): ?int
    {
        $row = $connection->selectOne(
            'select sum(p.rows) as estimate from sys.partitions p '.
            'join sys.tables t on p.object_id = t.object_id '.
            'where t.name = ? and p.index_id in (0, 1)',
            [$connection->getTablePrefix().$table]
        );

        return $row?->estimate !== null ? (int) $row->estimate : null;
    }
}
