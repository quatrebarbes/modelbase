<script setup lang="ts">
// EX-425 à EX-431 : un tableau paginé par relation Eloquent déclarée par le
// modèle hôte, sous les valeurs de colonnes de la fiche détail d'un item.
// EX-470 à EX-473 : filtre par colonne et tri, mécanique partagée avec
// ItemList.vue (EX-432 à EX-436) via useSortAndFilter/useFilteredListing,
// mais avec un état propre à cette instance du composant (EX-471) — ni
// partagé entre tableaux, ni reflété dans l'URL de la page.
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

const { queryParams } = useFilteredListing({ page, perPage, filters, sort })

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
// EX-427/EX-476 : l'ordre des en-têtes suit toujours le schéma exposé par
// /columns du modèle lié (même principe qu'ItemList.vue), jamais les clés de
// la ligne renvoyée par le listing.
const rowColumns = computed(() => columns.value.map((c) => c.column))

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

// EX-471 : filtre/tri sur le modèle d'ItemList.vue (EX-432 à EX-436), mais
// avec un état propre à cette instance (ni partagé entre tableaux, ni
// reflété dans l'URL) — cf. useSortAndFilter().
const { sortEntries, sortRank, sortDirection, toggleSort, setFilter, hasActiveFilterOrSort } =
  useSortAndFilter(sort, filters)
</script>

<template>
  <section class="relation-table">
    <!-- EX-426 : quand plusieurs relations sont affichées via des onglets
         (RelationTabs.vue), le libellé de l'onglet actif porte déjà ce nom —
         le titre ici serait redondant. -->
    <h2 v-if="showTitle">{{ relation.name }}</h2>

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
            <!-- EX-433 : pas de champ pour une colonne JSON (égalité stricte sur une valeur sérialisée, sans utilité) -->
            <th v-for="column in rowColumns" :key="column">
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
          <tr
            v-for="row in rows"
            :key="String(keyOf(row))"
            tabindex="0"
            @click="goToRelatedItem(row)"
            @keydown.enter="goToRelatedItem(row)"
          >
            <td v-for="column in rowColumns" :key="column">
              <div class="data-table__cell">{{ row[column] }}</div>
            </td>
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
.relation-table__unavailable {
  color: var(--color-text-muted);
  font-style: italic;
}
</style>
