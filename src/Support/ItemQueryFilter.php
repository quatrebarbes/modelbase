<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Query\Builder;

/**
 * EX-432 à EX-436, EX-484 à EX-495 : construit les clauses WHERE/ORDER BY du listing standard
 * d'items à partir des paramètres filter[...]/sort de la requête. Les noms de
 * colonnes sont toujours validés contre `$columns` (l'allowlist calculée
 * par `ItemRepository::columnsFor()`, EX-422) avant toute construction de
 * requête — jamais un nom de colonne brut fourni par le client, pour écarter
 * tout risque d'injection via un nom de colonne/direction de tri.
 *
 * `$table` qualifie les colonnes par le nom de leur table, nécessaire quand
 * la requête joint d'autres tables partageant certains noms de colonnes
 * (relation hasManyThrough/belongsToMany, EX-472).
 */
class ItemQueryFilter
{
    /**
     * EX-492/EX-495 : description du format attendu, reprise dans le message
     * d'erreur de l'API (le front a ses propres libellés traduits, EX-494).
     */
    private const FORMAT_DESCRIPTIONS = [
        'number' => 'un nombre (chiffres, signe moins facultatif, au plus un séparateur décimal point ou virgule)',
        'boolean' => '1 (oui) ou 0 (non)',
        'datetime' => 'AAAA, AAAA-MM, AAAA-MM-JJ, AAAA-MM-JJ HH, AAAA-MM-JJ HH:MM ou AAAA-MM-JJ HH:MM:SS',
        'date' => 'AAAA, AAAA-MM ou AAAA-MM-JJ',
        'time' => 'HH, HH:MM ou HH:MM:SS',
        'text' => 'une valeur texte',
    ];

    /**
     * EX-484 à EX-491 : chaque filtre est interprété selon le type de rendu
     * de sa colonne (cf. `applyFilter()`). EX-434 : plusieurs filtres
     * appliqués au même Builder sont combinés en ET par défaut chez Laravel,
     * sans code dédié. EX-495 : une colonne inconnue ou une valeur invalide
     * (cf. `invalidFilters()`) rejette l'ensemble de la requête.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, array{type: string, temporal?: ?string, key_format?: ?string}>  $columns  colonnes exposées, indexées par nom
     *
     * @throws ItemFilterException
     */
    public function applyFilters(Builder $query, array $filters, array $columns, ?string $table = null): void
    {
        $errors = [];
        $invalid = $this->invalidFilters($filters, $columns);

        foreach ($filters as $column => $value) {
            if (! isset($columns[$column])) {
                $errors[$column] = ["La colonne « {$column} » n'existe pas ou n'est pas filtrable."];

                continue;
            }

            if (isset($invalid[$column])) {
                $errors[$column] = ["La valeur du filtre de la colonne « {$column} » est invalide : format attendu ".self::FORMAT_DESCRIPTIONS[$invalid[$column]].'.'];

                continue;
            }

            $this->applyFilter($query, $this->qualify($column, $table), (string) $value, $columns[$column]);
        }

        if ($errors !== []) {
            throw new ItemFilterException($errors);
        }
    }

    /**
     * EX-492 : filtres dont la valeur ne respecte pas le format accepté par
     * le type de leur colonne, associés à ce format (clé de
     * `FORMAT_DESCRIPTIONS`). Une colonne inconnue n'y figure pas (rejetée
     * séparément par `applyFilters()`). Utilisé par le front pour ignorer ces
     * filtres et les signaler (EX-493/EX-494), sans jamais lever d'erreur.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, array{type: string, temporal?: ?string}>  $columns
     * @return array<string, string>
     */
    public function invalidFilters(array $filters, array $columns): array
    {
        $invalid = [];

        foreach ($filters as $column => $value) {
            if (! isset($columns[$column])) {
                continue;
            }

            $format = $this->expectedFormat($columns[$column]);

            if (! is_scalar($value)) {
                $invalid[$column] = $format ?? 'text';
            } elseif ($format !== null && ! $this->isValid((string) $value, $format)) {
                $invalid[$column] = $format;
            }
        }

        return $invalid;
    }

    /**
     * Format de saisie contrôlé pour une colonne, `null` si toute saisie y
     * est acceptée (texte EX-484, clé étrangère EX-487).
     *
     * @param  array{type: string, temporal?: ?string}  $column
     */
    public function expectedFormat(array $column): ?string
    {
        return match ($column['type']) {
            ColumnType::NUMBER->value => 'number',
            ColumnType::BOOLEAN->value => 'boolean',
            ColumnType::DATE->value => $column['temporal'] ?? 'datetime',
            default => null,
        };
    }

    private function isValid(string $value, string $format): bool
    {
        return match ($format) {
            'number' => $this->parseNumber($value) !== null,
            'boolean' => $this->parseBoolean($value) !== null,
            'date', 'datetime', 'time' => $this->parsePeriod($value, $format) !== null,
            default => true,
        };
    }

    /**
     * @param  array{type: string, temporal?: ?string, key_format?: ?string}  $definition
     */
    private function applyFilter(Builder $query, string $column, string $value, array $definition): void
    {
        switch ($definition['type']) {
            case ColumnType::STRING->value:
                // EX-484 : « contient », insensible à la casse.
                $query->whereLike($column, '%'.$value.'%');

                break;

            case ColumnType::NUMBER->value:
                // EX-485 : égalité numérique. Une valeur entière est liée
                // telle quelle (index utilisable) ; une valeur décimale est
                // comparée en promouvant les deux termes en décimal — une
                // colonne entière pgsql refuse sinon le littéral `12.5`, et
                // sqlite ne compare jamais égaux un réel et un texte.
                $number = $this->parseNumber($value);

                if (is_int($number)) {
                    $query->where($column, $number);
                } else {
                    $query->whereRaw($query->getGrammar()->wrap($column).' * 1.0 = ? * 1.0', [$number]);
                }

                break;

            case ColumnType::BOOLEAN->value:
                // EX-486 : `'1'`/`'0'` en chaîne, seule forme acceptée à la
                // fois par un booléen pgsql et un entier mysql/sqlite.
                $query->where($column, $this->parseBoolean($value) ? '1' : '0');

                break;

            case ColumnType::DATE->value:
                // EX-491 : [début de la période ; début de la période suivante[.
                [$start, $end] = $this->parsePeriod($value, $definition['temporal'] ?? 'datetime');
                $query->where($column, '>=', $start);

                if ($end !== null) {
                    $query->where($column, '<', $end);
                }

                break;

            default:
                // EX-487 : égalité stricte sur la valeur brute, sans contrôle
                // de format ; une saisie incompatible avec le type SQL de la
                // colonne ne retient aucun item, sans erreur (EX-492).
                $compatible = match ($definition['key_format'] ?? null) {
                    'integer' => preg_match('/^-?\d+$/', $value) === 1,
                    'uuid' => preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $value) === 1,
                    default => true,
                };

                $compatible ? $query->where($column, $value) : $query->whereRaw('1 = 0');
        }
    }

    /**
     * EX-485 : chiffres, signe moins facultatif, au plus un séparateur
     * décimal (point ou virgule) ; ni espace, ni notation scientifique.
     * Renvoie un entier quand la valeur est entière (`12`, `12.0`, `12,00`)
     * et représentable, sinon sa forme décimale normalisée (point).
     */
    private function parseNumber(string $value): int|string|null
    {
        if (preg_match('/^-?(\d+([.,]\d*)?|[.,]\d+)$/', $value) !== 1) {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $normalized, $parts) === 1 && rtrim($parts[3] ?? '', '0') === '') {
            $integer = $parts[1].(ltrim($parts[2], '0') ?: '0');

            if ((string) (int) $integer === $integer || $integer === '-0') {
                return (int) $integer;
            }
        }

        return $normalized;
    }

    /**
     * EX-486 : `1`/`0` (valeurs émises par la liste du front), `true`/`false`
     * acceptés également via l'API.
     */
    private function parseBoolean(string $value): ?bool
    {
        return match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
    }

    /**
     * EX-488 à EX-491 : période désignée par la saisie, sous la forme
     * [début inclus, début de la période suivante exclu] dans le format de
     * stockage de la colonne (comparaison directe, dans le fuseau des valeurs
     * affichées — aucune conversion de fuseau). Le second terme est `null`
     * quand la période suivante sort du domaine de la colonne (au-delà de
     * 23:59:59 pour une heure seule, de l'an 9999 pour une date). `null` pour
     * une saisie mal formée ou désignant une date/heure inexistante.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function parsePeriod(string $value, string $temporal): ?array
    {
        $time = '(\d{2})(?::(\d{2})(?::(\d{2}))?)?';
        $date = '(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?';

        $pattern = match ($temporal) {
            'date' => "/^{$date}$/",
            'time' => "/^{$time}$/",
            default => "/^(\d{4})(?:-(\d{2})(?:-(\d{2})(?:[ T]{$time})?)?)?$/",
        };

        if (preg_match($pattern, $value, $matches) !== 1) {
            return null;
        }

        // Composantes saisies, de la plus grande à la plus petite.
        $parts = array_values(array_filter(array_slice($matches, 1), fn (string $part) => $part !== ''));
        $components = $temporal === 'time'
            ? array_merge([1, 1, 1], array_map('intval', $parts))
            : array_map('intval', $parts);
        $precision = count($components);
        [$year, $month, $day, $hour, $minute, $second] = $components + [1, 1, 1, 0, 0, 0];

        if ($year < 1 || ! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $start = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second);
        $end = $start->modify('+1 '.['year', 'month', 'day', 'hour', 'minute', 'second'][$precision - 1]);

        $format = match ($temporal) {
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            default => 'Y-m-d H:i:s',
        };
        $overflows = $temporal === 'time'
            ? $end->format('Y-m-d') !== $start->format('Y-m-d')
            : (int) $end->format('Y') > 9999;

        return [$start->format($format), $overflows ? null : $end->format($format)];
    }

    /**
     * EX-435 : tri sur une ou plusieurs colonnes, chacune avec sa direction —
     * liste séparée par des virgules, un nom de colonne préfixé de `-` triant
     * en ordre descendant. EX-436 : l'ordre de priorité entre colonnes de tri
     * est celui de la liste, `orderBy()` étant appelé dans cet ordre (l'ordre
     * d'appel est déjà l'ordre de priorité chez Laravel).
     *
     * @param  array<string, mixed>  $columns  colonnes exposées, indexées par nom
     *
     * @throws ItemFilterException
     */
    public function applySort(Builder $query, string $sort, array $columns, ?string $table = null): void
    {
        $errors = [];

        foreach (explode(',', $sort) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $direction = 'asc';
            $column = $segment;

            if (str_starts_with($segment, '-')) {
                $direction = 'desc';
                $column = substr($segment, 1);
            }

            if (! isset($columns[$column])) {
                $errors[$column] = ["La colonne « {$column} » n'existe pas ou n'est pas triable."];

                continue;
            }

            $query->orderBy($this->qualify($column, $table), $direction);
        }

        if ($errors !== []) {
            throw new ItemFilterException($errors);
        }
    }

    private function qualify(string $column, ?string $table): string
    {
        return $table === null ? $column : $table.'.'.$column;
    }
}
