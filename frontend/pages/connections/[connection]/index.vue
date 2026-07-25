<script setup lang="ts">
// EX-301/EX-302/EX-304 : liste des modèles d'une connexion, filtrable par nom ou par table.
const route = useRoute()
const connection = route.params.connection as string

const api = useApiClient()
const { t } = useI18n()
const search = ref('')

// Attend une pause de frappe avant d'interroger l'API, pour ne pas relancer
// une requête à chaque caractère tapé.
const debouncedSearch = ref('')
let debounceTimer: ReturnType<typeof setTimeout>
watch(search, (value) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => { debouncedSearch.value = value }, 300)
})

const { data, pending } = await useAsyncData(
  () => `models-${connection}-${debouncedSearch.value}`,
  () => api(`/connections/${connection}/models`, {
    query: debouncedSearch.value ? { search: debouncedSearch.value } : {},
  }),
  { watch: [debouncedSearch] }
)

const models = computed(() => data.value?.data ?? [])

useHead({ title: t('models.title', { connection }) })
</script>

<template>
  <main>
    <Breadcrumb :items="[{ label: $t('common.databases'), to: '/' }, { label: connection }]" />
    <h1>{{ $t('models.title', { connection }) }}</h1>
    <div class="toolbar">
      <input
        v-model="search"
        type="search"
        :placeholder="$t('models.searchPlaceholder')"
        class="toolbar__search"
      />
      <Spinner v-if="pending" />
    </div>
    <ModelList :connection="connection" :models="models" />
  </main>
</template>
