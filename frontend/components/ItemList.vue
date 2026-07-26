<script setup lang="ts">
type ItemRow = Record<string, unknown>

type ColumnSchema = {
  column: string
  type: 'string' | 'number' | 'boolean' | 'date' | 'json' | 'foreign_key'
  technical: boolean
  fillable: boolean
  foreign_key?: { table: string; model: string | null }
}

type SortEntry = { column: string; direction: 'asc' | 'desc' }

const props = withDefaults(defineProps<{
  connection: string
  model: string
  items: ItemRow[]
  primaryKey?: string
  columns: ColumnSchema[]
}>(), {
  primaryKey: 'id',
})

// EX-432 : filtre par colonne, restreint côté serveur aux colonnes exposées
// par ItemRepository::columnsFor() — le parent reste propriétaire de l'état
// (reflété dans l'URL), ce composant ne fait qu'émettre les changements.
const filters = defineModel<Record<string, string>>('filters', { default: () => ({}) })
// EX-435/EX-436 : liste "colonne,-colonne2", l'ordre étant la priorité de tri.
const sort = defineModel<string>('sort', { default: '' })

// EX-402 (point ouvert) : le listing renvoie toutes les colonnes brutes de la
// ligne, potentiellement plus large que le schéma exposé par /columns — les
// en-têtes suivent donc les données réelles quand il y en a, et ne se
// rabattent sur le schéma que pour un listing vide (sinon aucun en-tête
// n'apparaîtrait pour construire les filtres).
const rowColumns = computed(() => (props.items.length ? Object.keys(props.items[0]) : props.columns.map((c) => c.column)))

const columnTypeByName = computed(() => Object.fromEntries(props.columns.map((c) => [c.column, c.type])))

function keyOf(item: ItemRow) {
  return item[props.primaryKey]
}

function goToItem(item: ItemRow) {
  navigateTo(`/connections/${props.connection}/models/${props.model}/items/${keyOf(item)}`)
}

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

const sortEntries = computed(() => parseSort(sort.value))

function sortRank(column: string): number | null {
  const index = sortEntries.value.findIndex((entry) => entry.column === column)
  return index === -1 ? null : index + 1
}

function sortDirection(column: string): 'asc' | 'desc' | undefined {
  return sortEntries.value.find((entry) => entry.column === column)?.direction
}

// Clic simple = tri par cette seule colonne (asc -> desc -> aucun tri) ;
// Maj+clic = ajoute/retire cette colonne comme critère de tri secondaire,
// sans perdre les colonnes de tri déjà actives (priorité = ordre d'ajout).
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
}

const hasActiveFilterOrSort = computed(() => sort.value !== '' || Object.keys(filters.value).length > 0)

// Exposé pour que la page parente affiche le bouton "effacer" à côté de ses
// propres actions de toolbar (ex. "+ Nouvel item"), sur la même ligne.
defineExpose({ hasActiveFilterOrSort, clearFiltersAndSort })
</script>

<template>
  <div>
    <!-- EX-404 : message dédié, pas d'erreur, si le modèle ne contient aucun item -->
    <p v-if="items.length === 0 && !hasActiveFilterOrSort">{{ $t('items.empty') }}</p>
    <table v-else class="data-table">
      <thead>
        <tr>
          <th v-for="column in rowColumns" :key="column">
            <button
              v-if="columnTypeByName[column]"
              type="button"
              class="item-list__sort-btn"
              @click="toggleSort(column, $event.shiftKey)"
            >
              {{ column }}
              <span v-if="sortRank(column)" class="item-list__sort-indicator">
                {{ sortDirection(column) === 'desc' ? '▼' : '▲' }}<sup v-if="sortEntries.length > 1">{{ sortRank(column) }}</sup>
              </span>
            </button>
            <span v-else>{{ column }}</span>
          </th>
        </tr>
        <tr class="item-list__filter-row">
          <th v-for="column in rowColumns" :key="column">
            <!-- EX-433 : pas de champ pour une colonne JSON (égalité stricte sur une valeur sérialisée, sans utilité) -->
            <input
              v-if="columnTypeByName[column] && columnTypeByName[column] !== 'json'"
              type="text"
              class="item-list__filter-input"
              :aria-label="column"
              :placeholder="$t('items.filterPlaceholder')"
              :value="filters[column] ?? ''"
              @input="setFilter(column, ($event.target as HTMLInputElement).value)"
            />
          </th>
        </tr>
      </thead>
      <tbody>
        <!-- EX-405 : navigue vers la fiche détail de l'item sélectionné -->
        <tr
          v-for="item in items"
          :key="String(keyOf(item))"
          tabindex="0"
          @click="goToItem(item)"
          @keydown.enter="goToItem(item)"
        >
          <td v-for="column in rowColumns" :key="column">{{ item[column] }}</td>
        </tr>
      </tbody>
    </table>
    <p v-if="items.length === 0 && hasActiveFilterOrSort">{{ $t('items.empty') }}</p>
  </div>
</template>

<style scoped>
.item-list__sort-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  border: none;
  background: none;
  padding: 0;
  font: inherit;
  font-weight: 600;
  color: inherit;
  cursor: pointer;
}

.item-list__sort-indicator {
  font-size: 0.75em;
}

.item-list__filter-input {
  width: 100%;
  box-sizing: border-box;
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-bg);
  color: var(--color-text);
  font: inherit;
  transition: border-color 0.15s ease;
}

.item-list__filter-input:hover {
  border-color: var(--color-hover);
}

.item-list__filter-input:focus {
  outline: none;
  border-color: var(--color-border-focus);
}
</style>
