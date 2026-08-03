<script setup lang="ts">
// EX-401/EX-402/EX-403/EX-404 : listing paginé des items d'un modèle.
// EX-432 à EX-436 : filtre/tri par colonne, état reflété dans l'URL (query
// params) pour rester partageable/rechargeable.
const route = useRoute()
const router = useRouter()
const connection = route.params.connection as string
const model = route.params.model as string

const api = useApiClient()
const { t } = useI18n()
// EX-455/EX-456 : mémorisé par modèle de listing standard (connexion + modèle).
const perPage = usePersistedPerPage(`model:${connection}:${model}`, 25)

function parseInitialFilters(): Record<string, string> {
  const initial: Record<string, string> = {}

  for (const [key, value] of Object.entries(route.query)) {
    const match = key.match(/^filter\[(.+)\]$/)
    if (match && typeof value === 'string') initial[match[1]] = value
  }

  return initial
}

const page = ref(route.query.page ? Number(route.query.page) : 1)
const sort = ref(typeof route.query.sort === 'string' ? route.query.sort : '')
const filters = ref<Record<string, string>>(parseInitialFilters())
// EX-438 : '' (défaut, items actifs uniquement), 'with' ou 'only'.
const trashed = ref(typeof route.query.trashed === 'string' ? route.query.trashed : '')
watch(trashed, () => { page.value = 1 })
// Filtres texte débounced (300 ms) pour ne pas relancer une requête à chaque
// caractère tapé, même pattern que connections/[connection]/index.vue.
const debouncedFilters = ref<Record<string, string>>({ ...filters.value })
let debounceTimer: ReturnType<typeof setTimeout>
watch(filters, (value) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    debouncedFilters.value = { ...value }
    page.value = 1
  }, 300)
}, { deep: true })

// Changer le tri remet la page à 1, une page 2 pouvant ne plus exister une
// fois le tri (ou un filtre, ci-dessus) appliqué. Changer le nombre de lignes
// par page (EX-452) a le même effet, le numéro de page courant n'ayant plus
// forcément de sens avec le nouveau découpage.
watch(sort, () => { page.value = 1 })
watch(perPage, () => { page.value = 1 })

const queryParams = computed(() => {
  const query: Record<string, string | number> = { page: page.value, per_page: perPage.value }

  if (sort.value) query.sort = sort.value
  if (trashed.value) query.trashed = trashed.value

  for (const [column, value] of Object.entries(debouncedFilters.value)) {
    query[`filter[${column}]`] = value
  }

  return query
})

// EX-432 à EX-436 : reflète page/filtre/tri dans l'URL pour rester
// partageable/rechargeable, sans empiler l'historique de navigation.
watch(queryParams, (query) => router.replace({ query }))

const { data, pending } = await useAsyncData(
  () => `items-${connection}-${model}-${JSON.stringify(queryParams.value)}`,
  () => api(`/connections/${connection}/models/${model}/items`, { query: queryParams.value }),
  { watch: [queryParams] }
)

const { data: columnsData } = await useAsyncData(
  `items-columns-${connection}-${model}`,
  () => api(`/connections/${connection}/models/${model}/columns`)
)

const items = computed(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)
const columns = computed(() => columnsData.value?.data ?? [])
// EX-443 : le filtre « avec/uniquement supprimés » n'est proposé que pour un
// modèle utilisant SoftDeletes.
const softDeletes = computed(() => meta.value?.soft_deletes ?? false)

// EX-306 : accès au diagramme des relations depuis le listing des items.
const showDiagram = ref(false)

// Réf vers ItemList pour afficher son bouton "effacer filtres/tris" dans la
// toolbar de la page (même ligne que "+ Nouvel item"), cf. ItemList.vue.
const itemListRef = ref<{ hasActiveFilterOrSort: boolean; clearFiltersAndSort: () => void } | null>(null)

useHead({ title: t('items.title', { model }) })
</script>

<template>
  <main>
    <Breadcrumb :items="[
      { label: $t('common.databases'), to: '/' },
      { label: connection, to: `/connections/${connection}` },
      { label: model },
    ]" />
    <h1>{{ $t('items.title', { model }) }}</h1>
    <div class="toolbar">
      <div class="toolbar__actions">
        <!-- EX-412 : point d'entrée vers la création d'un nouvel item -->
        <NuxtLink
          :to="`/connections/${connection}/models/${model}/items/create`"
          class="btn btn--primary"
        >
          {{ $t('items.new') }}
        </NuxtLink>
        <!-- EX-306 : ouvre le diagramme des relations Eloquent du modèle dans un panneau latéral -->
        <button type="button" class="btn" @click="showDiagram = true">
          {{ $t('relations.showDiagram') }}
        </button>
      </div>
      <div class="toolbar__actions">
        <button
          v-if="itemListRef?.hasActiveFilterOrSort"
          type="button"
          class="btn"
          @click="itemListRef?.clearFiltersAndSort()"
        >
          {{ $t('items.clearFiltersAndSorts') }}
        </button>
        <!-- EX-438 : filtre d'affichage des items soft-deleted, uniquement pour un modèle SoftDeletes (EX-443) -->
        <select v-if="softDeletes" v-model="trashed" :aria-label="$t('items.trashedFilterLabel')" class="btn">
          <option value="">{{ $t('items.trashedFilterActiveOnly') }}</option>
          <option value="with">{{ $t('items.trashedFilterWithTrashed') }}</option>
          <option value="only">{{ $t('items.trashedFilterOnlyTrashed') }}</option>
        </select>
      </div>
    </div>
    <RelationDiagram
      v-if="showDiagram"
      :connection="connection"
      :model="model"
      @close="showDiagram = false"
    />
    <ItemList
      ref="itemListRef"
      v-model:filters="filters"
      v-model:sort="sort"
      :connection="connection"
      :model="model"
      :items="items"
      :primary-key="meta?.primary_key"
      :columns="columns"
    />
    <div v-if="meta && meta.total > 0" class="item-pagination-row">
      <ItemPagination v-model:page="page" v-model:per-page="perPage" :meta="meta" />
      <Spinner v-if="pending" />
    </div>
  </main>
</template>
