<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\QueryException;

/**
 * EX-417 : traduit la QueryException levée par une écriture d'item (create/
 * update) en {column, rule, message} exploitable par ItemRepository, à partir
 * du seul message d'erreur natif du moteur de BDD — jamais d'une règle de
 * validation redéfinie par le plug-in. Chaque pilote a un format d'erreur
 * différent (cf. ColumnIntrospector::scalarType pour le même constat sur les
 * types de colonne) ; les motifs ci-dessous ont été vérifiés contre de vraies
 * erreurs mysql 8.4 et pgsql 16 via l'environnement docker-compose (INSERT
 * direct déclenchant chacune des 4 contraintes), en plus des tests sqlite.
 *
 * Limite : l'extraction du nom de colonne peut échouer sur un pilote/format
 * non couvert ici (ex. sqlsrv, jamais testé faute d'instance joignable, cf.
 * Phase 2) ou sur un index unique nommé manuellement (hors convention Laravel
 * `{table}_{colonne}_unique`) — dans ce cas `column` vaut `null` et seul le
 * message brut du moteur est remonté (rule 'unknown').
 */
class DatabaseErrorTranslator
{
    /**
     * @return array{column: string|null, rule: string, message: string}
     */
    public function translate(QueryException $exception, string $driver, string $table): array
    {
        $raw = $exception->errorInfo[2] ?? $exception->getMessage();

        return match ($driver) {
            'pgsql' => $this->pgsql($exception, $raw),
            'sqlite' => $this->sqlite($raw, $table),
            'mysql', 'mariadb' => $this->mysql($raw, $table),
            default => $this->result(null, 'unknown', $raw),
        };
    }

    /**
     * SQLSTATE pgsql identifie directement la contrainte violée (23502 not-
     * null, 23505 unique, 23503 foreign key) ; le nom de colonne est en
     * revanche absent du message pour une violation de format (22007/22P02),
     * pgsql ne le mentionnant jamais dans ce cas.
     */
    private function pgsql(QueryException $exception, string $raw): array
    {
        $sqlstate = $exception->errorInfo[0] ?? null;

        return match ($sqlstate) {
            '23502' => $this->result($this->matchOne('/column "(\w+)"/', $raw), 'required', $raw),
            '23505' => $this->result($this->matchOne('/Key \((\w+)\)/', $raw), 'unique', $raw),
            '23503' => $this->result($this->matchOne('/Key \((\w+)\)/', $raw), 'foreign_key', $raw),
            default => $this->result(null, str_starts_with((string) $sqlstate, '22') ? 'format' : 'unknown', $raw),
        };
    }

    private function sqlite(string $raw, string $table): array
    {
        $prefix = preg_quote($table, '/');

        return match (true) {
            (bool) preg_match("/NOT NULL constraint failed: {$prefix}\.(\w+)/", $raw, $m) => $this->result($m[1], 'required', $raw),
            (bool) preg_match("/UNIQUE constraint failed: {$prefix}\.(\w+)/", $raw, $m) => $this->result($m[1], 'unique', $raw),
            str_contains($raw, 'FOREIGN KEY constraint failed') => $this->result(null, 'foreign_key', $raw),
            default => $this->result(null, 'unknown', $raw),
        };
    }

    private function mysql(string $raw, string $table): array
    {
        return match (true) {
            (bool) preg_match("/Column '(\w+)' cannot be null/", $raw, $m) => $this->result($m[1], 'required', $raw),
            (bool) preg_match("/Field '(\w+)' doesn't have a default value/", $raw, $m) => $this->result($m[1], 'required', $raw),
            (bool) preg_match("/Incorrect .*? value: '.*?' for column '(\w+)'/", $raw, $m) => $this->result($m[1], 'format', $raw),
            (bool) preg_match("/Duplicate entry '.*?' for key '(?:".preg_quote($table, '/')."\.)?([^']+)'/", $raw, $m) => $this->result($this->mysqlUniqueColumn($m[1], $table), 'unique', $raw),
            (bool) preg_match('/FOREIGN KEY \(`?(\w+)`?\)/', $raw, $m) => $this->result($m[1], 'foreign_key', $raw),
            default => $this->result(null, 'unknown', $raw),
        };
    }

    /**
     * Convention Laravel pour un index unique sans nom explicite :
     * `{table}_{colonne}_unique` (Blueprint::unique()). Une clé primaire
     * dupliquée porte le nom générique 'PRIMARY', sans colonne à en extraire.
     */
    private function mysqlUniqueColumn(string $key, string $table): ?string
    {
        if ($key === 'PRIMARY') {
            return null;
        }

        $column = preg_replace('/^'.preg_quote($table, '/').'_/', '', $key);
        $column = preg_replace('/_unique$/', '', $column);

        return $column !== '' ? $column : null;
    }

    private function matchOne(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) ? $m[1] : null;
    }

    /**
     * @return array{column: string|null, rule: string, message: string}
     */
    private function result(?string $column, string $rule, string $message): array
    {
        return ['column' => $column, 'rule' => $rule, 'message' => $message];
    }
}
