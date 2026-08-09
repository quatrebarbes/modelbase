import type { Ref } from 'vue'

type SortEntry = { column: string; direction: 'asc' | 'desc' }

function parseSort(value: string): SortEntry[] {
  return value
    ? value.split(',').filter(Boolean).map((segment) => segment.startsWith('-')
      ? { column: segment.slice(1), direction: 'desc' as const }
      : { column: segment, direction: 'asc' as const })
    : []
}

function stringifySort(entries: SortEntry[]): string {
  return entries.map((entry) => (entry.direction === 'desc' ? `-${entry.column}` : entry.column)).join(',')
}

/**
 * EX-432 à EX-436 (listing standard) / EX-470 à EX-473 (tableaux d'objets
 * liés) : mécanique de tri multi-colonnes (clic = tri simple, Maj+clic =
 * ajoute/retire une colonne de tri secondaire sans perdre les colonnes déjà
 * actives) et de filtre par colonne, partagée par ItemList.vue et
 * RelationTable.vue — `sort`/`filters` sont fournis par l'appelant (defineModel
 * ou ref locale selon que l'état doit être reflété dans l'URL ou pas).
 */
export function useSortAndFilter(
  sort: Ref<string>,
  filters: Ref<Record<string, string>>,
  options?: { onClear?: () => void }
) {
  const sortEntries = computed(() => parseSort(sort.value))

  function sortRank(column: string): number | null {
    const index = sortEntries.value.findIndex((entry) => entry.column === column)
    return index === -1 ? null : index + 1
  }

  function sortDirection(column: string): 'asc' | 'desc' | undefined {
    return sortEntries.value.find((entry) => entry.column === column)?.direction
  }

  function toggleSort(column: string, additive: boolean) {
    let entries = sortEntries.value
    const index = entries.findIndex((entry) => entry.column === column)

    if (!additive) {
      entries = entries.length === 1 && index === 0
        ? (entries[0].direction === 'asc' ? [{ column, direction: 'desc' as const }] : [])
        : [{ column, direction: 'asc' as const }]
    } else if (index === -1) {
      entries = [...entries, { column, direction: 'asc' as const }]
    } else if (entries[index].direction === 'asc') {
      entries = entries.map((entry, i) => (i === index ? { ...entry, direction: 'desc' as const } : entry))
    } else {
      entries = entries.filter((_, i) => i !== index)
    }

    sort.value = stringifySort(entries)
  }

  function setFilter(column: string, value: string) {
    const next = { ...filters.value }

    if (value === '') {
      delete next[column]
    } else {
      next[column] = value
    }

    filters.value = next
  }

  function clearFiltersAndSort() {
    filters.value = {}
    sort.value = ''
    options?.onClear?.()
  }

  const hasActiveFilterOrSort = computed(() => sort.value !== '' || Object.keys(filters.value).length > 0)

  return { sortEntries, sortRank, sortDirection, toggleSort, setFilter, clearFiltersAndSort, hasActiveFilterOrSort }
}
