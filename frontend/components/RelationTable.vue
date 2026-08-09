<script setup lang="ts">
// EX-425 à EX-431 : un tableau paginé par relation Eloquent déclarée par le
// modèle hôte, sous les valeurs de colonnes de la fiche détail d'un item.
// EX-470 à EX-473 : filtre par colonne et tri, sur le modèle d'ItemList.vue
// (EX-432 à EX-436), mais avec un état propre à cette instance du composant
// (EX-471) — ni partagé entre tableaux, ni reflété dans l'URL de la page.
type RelationDescriptor = {
  name: string
  type: string
  multiplicity: 'one' | 'many'
  related_model: string
  related_connection: string
  related_table: string
  navigable: boolean
}

type ColumnSchema = {
  column: string
  type: 'string' | 'number' | 'boolean' | 'date' | 'json' | 'foreign_key'
  technical: boolean
  fillable: boolean
}

type SortEntry = { column: string; direction: 'asc' | 'desc' }

const props = withDefaults(defineProps<{
  connection: string
  model: string
  item: string
  relation: RelationDescriptor
  showTitle?: boolean
}>(), {
  showTitle: true,
})

const api = useApiClient()
const page = ref(1)
// EX-455/EX-456 : mémorisé par relation (connexion + modèle + nom de la
// relation), pas par item — plusieurs items du même modèle partagent donc la
// même préférence pour une relation donnée.
const perPage = usePersistedPerPage(`relation:${props.connection}:${props.model}:${props.relation.name}`, 10)

const filters = ref<Record<string, string>>({})
const sort = ref('')
// Filtres texte débounced (300 ms), même pattern que
// connections/[connection]/models/[model]/index.vue.
const debouncedFilters = ref<Record<string, string>>({})
let debounceTimer: ReturnType<typeof setTimeout>
watch(filters, (value) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    debouncedFilters.value = { ...value }
    page.value = 1
  }, 300)
}, { deep: true })

// Changer le nombre de lignes par page (EX-452), le tri ou un filtre remet la
// page courante à 1, qui peut ne plus exister avec le nouveau découpage.
watch(perPage, () => { page.value = 1 })
watch(sort, () => { page.value = 1 })

// EX-472 : schéma des colonnes du modèle lié, pour connaître leur type
// (filtre "contient" vs égalité stricte, EX-433) — n'est interrogé que si la
// relation est navigable (EX-473), même garde que pour le listing d'objets
// liés lui-même ci-dessous.
const { data: columnsData } = await useAsyncData(
  () => `relation-columns-${props.relation.related_connection}-${props.relation.related_model}`,
  () => props.relation.navigable
    ? api(`/connections/${props.relation.related_connection}/models/${props.relation.related_model}/columns`)
    : Promise.resolve(null)
)

const columns = computed<ColumnSchema[]>(() => columnsData.value?.data ?? [])
const columnTypeByName = computed(() => Object.fromEntries(columns.value.map((c) => [c.column, c.type])))

const queryParams = computed(() => {
  const query: Record<string, string | number> = { page: page.value, per_page: perPage.value }

  if (sort.value) query.sort = sort.value

  for (const [column, value] of Object.entries(debouncedFilters.value)) {
    query[`filter[${column}]`] = value
  }

  return query
})

// EX-431/EX-473 : une relation non navigable n'est jamais interrogée — ni
// pour son listing, ni pour son filtre/tri — l'API bloquerait de toute façon
// la requête (409), la connexion étant injoignable.
const { data, pending } = await useAsyncData(
  () => `relation-items-${props.connection}-${props.model}-${props.item}-${props.relation.name}-${JSON.stringify(queryParams.value)}`,
  () => props.relation.navigable
    ? api(`/connections/${props.connection}/models/${props.model}/items/${props.item}/relations/${props.relation.name}`, {
        query: queryParams.value,
      })
    : Promise.resolve(null),
  { watch: [queryParams] }
)

const rows = computed<Array<Record<string, unknown>>>(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)
// EX-427 : les en-têtes suivent les données réelles quand il y en a (même
// principe qu'ItemList.vue), et ne se rabattent sur le schéma que pour un
// tableau vide (sinon aucun en-tête n'apparaîtrait pour construire les
// filtres).
const rowColumns = computed(() => (rows.value.length ? Object.keys(rows.value[0]) : columns.value.map((c) => c.column)))

// EX-428 : la colonne de clé primaire du modèle lié n'est pas forcément
// nommée `id` (cf. `meta.primary_key`, ItemRepository::paginate()).
function keyOf(row: Record<string, unknown>) {
  return row[meta.value?.primary_key ?? 'id']
}

function goToRelatedItem(row: Record<string, unknown>) {
  // EX-428 : navigue vers la fiche détail de l'item lié, dans son propre
  // modèle (potentiellement une autre connexion que l'item courant).
  navigateTo(`/connections/${props.relation.related_connection}/models/${props.relation.related_model}/items/${keyOf(row)}`)
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
// sans perdre les colonnes de tri déjà actives (priorité = ordre d'ajout) —
// même comportement qu'ItemList.vue (EX-436).
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
  debouncedFilters.value = {}
  sort.value = ''
}

const hasActiveFilterOrSort = computed(() => sort.value !== '' || Object.keys(filters.value).length > 0)
</script>

<template>
  <section class="relation-table">
    <div v-if="showTitle || hasActiveFilterOrSort" class="relation-table__header">
      <!-- EX-426 : quand plusieurs relations sont affichées via des onglets
           (RelationTabs.vue), le libellé de l'onglet actif porte déjà ce nom —
           le titre ici serait redondant. -->
      <h2 v-if="showTitle">{{ relation.name }}</h2>
      <button
        v-if="hasActiveFilterOrSort"
        type="button"
        class="btn"
        @click="clearFiltersAndSort"
      >
        {{ $t('items.clearFiltersAndSorts') }}
      </button>
    </div>

    <!-- EX-431 : connexion cible indisponible, indication sans lien — jamais
         de filtre ni de tri proposé dans ce cas (EX-473). -->
    <p v-if="!relation.navigable" class="relation-table__unavailable">
      {{ $t('relations.unavailableConnection', { connection: relation.related_connection }) }}
    </p>

    <template v-else>
      <!-- EX-430 : message dédié, pas d'erreur, si la relation n'a aucun objet lié -->
      <p v-if="!pending && rows.length === 0 && !hasActiveFilterOrSort">{{ $t('relations.empty') }}</p>
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
            <!-- EX-433 : pas de champ pour une colonne JSON (égalité stricte sur une valeur sérialisée, sans utilité) -->
            <th v-for="column in rowColumns" :key="column">
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
          <tr
            v-for="row in rows"
            :key="String(keyOf(row))"
            tabindex="0"
            @click="goToRelatedItem(row)"
            @keydown.enter="goToRelatedItem(row)"
          >
            <td v-for="column in rowColumns" :key="column">{{ row[column] }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="!pending && rows.length === 0 && hasActiveFilterOrSort">{{ $t('items.empty') }}</p>
      <!-- EX-429 : pagination identique au listing standard d'un modèle -->
      <div v-if="meta && meta.total > 0" class="item-pagination-row">
        <ItemPagination v-model:page="page" v-model:per-page="perPage" :meta="meta" />
        <Spinner v-if="pending" />
      </div>
    </template>
  </section>
</template>

<style scoped>
.relation-table__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.relation-table__unavailable {
  color: var(--color-text-muted);
  font-style: italic;
}

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
