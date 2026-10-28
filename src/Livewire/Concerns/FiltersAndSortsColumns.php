<?php

namespace Quatrebarbes\Modelbase\Livewire\Concerns;

use Quatrebarbes\Modelbase\Support\ItemQueryFilter;

/**
 * Encodage du tri partagé avec `Support\ItemQueryFilter::applySort()` — liste
 * de colonnes séparées par des virgules, `-colonne` pour un tri descendant,
 * l'ordre de la liste étant l'ordre de priorité (EX-435/EX-436). Portage de
 * `frontend/composables/useSortAndFilter.ts` : clic simple = remplace par un
 * tri mono-colonne (cycle asc → desc → aucun tri en re-cliquant la même
 * colonne) ; clic + Maj = tri multi-colonnes additif (ajoute/retire/bascule
 * la colonne sans toucher aux autres).
 */
trait FiltersAndSortsColumns
{
    /**
     * EX-492/EX-493 : sépare les filtres saisis (`$this->filters`, conservés
     * tels quels) entre ceux à appliquer — non vides et valides — et ceux
     * dont la valeur ne respecte pas le format de leur colonne, ignorés et
     * signalés avec ce format (EX-494).
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    protected function partitionFilters(): array
    {
        $filters = array_filter($this->filters, fn ($v) => $v !== null && $v !== '');
        $invalid = app(ItemQueryFilter::class)->invalidFilters($filters, collect($this->columns)->keyBy('column')->all());

        return [array_diff_key($filters, $invalid), $invalid];
    }

    /**
     * @return list<array{column: string, dir: 'asc'|'desc'}>
     */
    protected function parseSort(string $sort): array
    {
        $segments = array_filter(array_map('trim', explode(',', $sort)), fn ($s) => $s !== '');

        return array_values(array_map(function (string $segment) {
            if (str_starts_with($segment, '-')) {
                return ['column' => substr($segment, 1), 'dir' => 'desc'];
            }

            return ['column' => $segment, 'dir' => 'asc'];
        }, $segments));
    }

    /**
     * @param  list<array{column: string, dir: 'asc'|'desc'}>  $sort
     */
    protected function stringifySort(array $sort): string
    {
        return implode(',', array_map(
            fn (array $s) => ($s['dir'] === 'desc' ? '-' : '').$s['column'],
            $sort,
        ));
    }

    protected function toggleSortString(string $currentSort, string $column, bool $additive): string
    {
        $current = $this->parseSort($currentSort);
        $index = null;

        foreach ($current as $i => $entry) {
            if ($entry['column'] === $column) {
                $index = $i;

                break;
            }
        }

        if ($additive) {
            if ($index === null) {
                $current[] = ['column' => $column, 'dir' => 'asc'];
            } elseif ($current[$index]['dir'] === 'asc') {
                $current[$index]['dir'] = 'desc';
            } else {
                array_splice($current, $index, 1);
            }
        } elseif (count($current) === 1 && $current[0]['column'] === $column) {
            $current = $current[0]['dir'] === 'asc'
                ? [['column' => $column, 'dir' => 'desc']]
                : [];
        } else {
            $current = [['column' => $column, 'dir' => 'asc']];
        }

        return $this->stringifySort($current);
    }
}
