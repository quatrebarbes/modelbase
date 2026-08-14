<script setup lang="ts">
type ItemRow = Record<string, unknown>

type ColumnSchema = {
  column: string
  type: 'string' | 'number' | 'boolean' | 'date' | 'json' | 'foreign_key'
  technical: boolean
  fillable: boolean
  foreign_key?: { table: string; model: string | null }
}

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

// EX-476 : l'ordre des en-têtes suit toujours le schéma exposé par /columns
// (EX-422), jamais les clés de la ligne renvoyée par le listing — sinon deux
// pages d'un même listing pourraient présenter leurs colonnes dans un ordre
// différent. `is_trashed` (EX-439) n'apparaît donc pas ici : ce n'est pas une
// colonne du modèle hôte, mais un indicateur dédié (rendu à part, cf. tbody
// ci-dessous).
const rowColumns = computed(() => props.columns.map((c) => c.column))

const columnTypeByName = computed(() => Object.fromEntries(props.columns.map((c) => [c.column, c.type])))

function keyOf(item: ItemRow) {
  return item[props.primaryKey]
}

function goToItem(item: ItemRow) {
  navigateTo(`/connections/${props.connection}/models/${props.model}/items/${keyOf(item)}`)
}

const { sortEntries, sortRank, sortDirection, toggleSort, setFilter, clearFiltersAndSort, hasActiveFilterOrSort } =
  useSortAndFilter(sort, filters)

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
              class="data-table__sort-btn"
              @click="toggleSort(column, $event.shiftKey)"
            >
              {{ column }}
              <span v-if="sortRank(column)" class="data-table__sort-indicator">
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
              class="data-table__filter-input"
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
          class="item-list__row"
          :class="{ 'item-list__row--trashed': item.is_trashed }"
          @click="goToItem(item)"
          @keydown.enter="goToItem(item)"
        >
          <td v-for="column in rowColumns" :key="column">
            <div class="data-table__cell">
              <!-- EX-439 : indicateur visuel distinctif d'un item soft-deleted, porté par la colonne deleted_at elle-même -->
              <span v-if="column === 'deleted_at' && item.is_trashed" class="item-list__trashed-date">{{ item[column] }}</span>
              <template v-else>{{ item[column] }}</template>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="items.length === 0 && hasActiveFilterOrSort">{{ $t('items.empty') }}</p>
  </div>
</template>

<style scoped>
.item-list__row--trashed {
  color: var(--color-text-muted);
  font-style: italic;
}

.item-list__trashed-date {
  color: var(--color-error-text);
}
</style>
