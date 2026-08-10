<script setup lang="ts">
type ModelSummary = {
  name: string
  table: string
  table_exists: boolean
  item_count: string
  item_count_raw: number
  column_count: number
}

type SortableColumn = 'name' | 'table' | 'item_count_raw' | 'column_count'

const props = defineProps<{
  connection: string
  models: ModelSummary[]
}>()

function goToModel(model: ModelSummary) {
  // limite EX-303/EX-305 : une table absente n'a pas de listing d'items à
  // ouvrir, la ligne n'est donc pas navigable.
  if (!model.table_exists) {
    return
  }

  navigateTo(`/connections/${props.connection}/models/${model.name}`)
}

// EX-313 à EX-316 : tri mono-critère du listing, appliqué après le filtre
// déjà réalisé par la page parente (props.models). Un seul critère actif à
// la fois (EX-314) : cliquer sur un nouvel en-tête remplace le précédent,
// contrairement au tri multi-colonnes du listing des items (EX-435/EX-436),
// jugé disproportionné pour ce tableau non paginé chargé en un seul appel
// (Phase 17). Logique volontairement locale à ce composant plutôt
// qu'extraite dans un composable partagé avec `ItemList.vue` : le cycle
// mono-critère (asc → desc → aucun tri, sans Maj+clic ni rang) diffère
// suffisamment du tri multi-critère de ce dernier pour qu'un composable
// commun n'apporte rien à un seul autre consommateur.
const columns: { key: SortableColumn; label: string; numeric: boolean }[] = [
  { key: 'name', label: 'columnName', numeric: false },
  { key: 'table', label: 'columnTable', numeric: false },
  { key: 'item_count_raw', label: 'columnItems', numeric: true },
  { key: 'column_count', label: 'columnColumns', numeric: true },
]

const sortColumn = ref<SortableColumn | null>(null)
const sortDirection = ref<'asc' | 'desc'>('asc')

function toggleSort(column: SortableColumn) {
  if (sortColumn.value !== column) {
    sortColumn.value = column
    sortDirection.value = 'asc'
  } else if (sortDirection.value === 'asc') {
    sortDirection.value = 'desc'
  } else {
    sortColumn.value = null
  }
}

function directionFor(column: SortableColumn) {
  return sortColumn.value === column ? sortDirection.value : undefined
}

function compareByColumn(column: SortableColumn) {
  return (a: ModelSummary, b: ModelSummary) => {
    const av = a[column]
    const bv = b[column]

    return typeof av === 'number' && typeof bv === 'number'
      ? av - bv
      : String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' })
  }
}

const sortedModels = computed(() => {
  if (!sortColumn.value) {
    return props.models
  }

  const column = sortColumn.value
  const direction = sortDirection.value

  // limite : une table absente n'a pas de nom comparable, elle reste en fin
  // de liste quel que soit le sens de tri (asc/desc) plutôt que de sauter
  // en tête au tri descendant.
  if (column === 'table') {
    const withTable = props.models.filter((model) => model.table_exists).sort(compareByColumn(column))
    const withoutTable = props.models.filter((model) => !model.table_exists)

    return [...(direction === 'desc' ? withTable.reverse() : withTable), ...withoutTable]
  }

  const sorted = [...props.models].sort(compareByColumn(column))

  return direction === 'desc' ? sorted.reverse() : sorted
})
</script>

<template>
  <!-- EX-301, limite « aucun modèle disponible » : message, pas d'erreur -->
  <p v-if="models.length === 0">{{ $t('models.empty') }}</p>
  <table v-else class="data-table">
    <thead>
      <tr>
        <th v-for="column in columns" :key="column.key" :class="{ 'model-list__count-column': column.numeric }">
          <button type="button" class="model-list__sort-btn" @click="toggleSort(column.key)">
            {{ $t(`models.${column.label}`) }}
            <!-- EX-315 : indicateur visuel du critère de tri actif -->
            <span v-if="directionFor(column.key)" class="model-list__sort-indicator">
              {{ directionFor(column.key) === 'desc' ? '▼' : '▲' }}
            </span>
          </button>
        </th>
      </tr>
    </thead>
    <tbody>
      <!-- EX-303 : navigue vers le listing des items du modèle (module 4),
           sauf si sa table est absente (limite EX-303/EX-305) -->
      <tr
        v-for="model in sortedModels"
        :key="model.name"
        :class="{ 'model-list__row--disabled': !model.table_exists }"
        :tabindex="model.table_exists ? 0 : -1"
        @click="goToModel(model)"
        @keydown.enter="goToModel(model)"
      >
        <td>{{ model.name }}</td>
        <td>{{ model.table_exists ? model.table : $t('models.tableMissing') }}</td>
        <td class="model-list__count model-list__count-column">{{ model.item_count }}</td>
        <td class="model-list__count model-list__count-column">{{ model.column_count }}</td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.model-list__row--disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* cohérent avec ConnectionList.vue : une ligne non navigable n'a pas
   d'affordance de survol */
.model-list__row--disabled:hover {
  outline: none;
}

.model-list__count {
  text-align: right;
}

.model-list__count-column {
  width: 3rem;
}

.model-list__sort-btn {
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

.model-list__sort-indicator {
  font-size: 0.75em;
}
</style>
