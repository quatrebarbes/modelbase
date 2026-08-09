import type { Ref } from 'vue'

type QueryParams = Record<string, string | number>

/**
 * EX-432 à EX-436 (listing standard) / EX-470 à EX-473 (tableaux d'objets
 * liés) : débounce des filtres texte (300 ms, pour ne pas relancer une
 * requête à chaque caractère tapé) et construction des query params de
 * pagination/tri/filtre, partagés par connections/[connection]/models/
 * [model]/index.vue et RelationTable.vue. Le fetch lui-même (useAsyncData)
 * reste local à chaque appelant : l'endpoint, la clé de cache et les gardes
 * (ex. EX-431/EX-473 : relation non navigable) diffèrent selon le contexte.
 */
export function useFilteredListing(options: {
  page: Ref<number>
  perPage: Ref<number>
  filters: Ref<Record<string, string>>
  sort: Ref<string>
  extraQuery?: () => QueryParams
}) {
  const { page, perPage, filters, sort, extraQuery } = options

  const debouncedFilters = ref<Record<string, string>>({ ...filters.value })
  let debounceTimer: ReturnType<typeof setTimeout>

  watch(filters, (value) => {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
      debouncedFilters.value = { ...value }
      page.value = 1
    }, 300)
  }, { deep: true })

  watch(sort, () => { page.value = 1 })
  watch(perPage, () => { page.value = 1 })

  const queryParams = computed<QueryParams>(() => {
    const query: QueryParams = { page: page.value, per_page: perPage.value, ...extraQuery?.() }

    if (sort.value) query.sort = sort.value

    for (const [column, value] of Object.entries(debouncedFilters.value)) {
      query[`filter[${column}]`] = value
    }

    return query
  })

  return { debouncedFilters, queryParams }
}
