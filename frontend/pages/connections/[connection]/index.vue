<script setup lang="ts">
// EX-301/EX-302/EX-304 : liste des modèles d'une connexion, filtrable par nom
// ou par table. Un seul appel API (Phase 17) : le filtre s'applique ensuite
// côté client sur la liste déjà chargée, sans nouvel appel à chaque frappe.
const route = useRoute()
const connection = route.params.connection as string

const api = useApiClient()
const { t } = useI18n()
const search = ref('')

const { data, pending } = await useAsyncData(
  () => `models-${connection}`,
  () => api(`/connections/${connection}/models`)
)

const allModels = computed(() => data.value?.data ?? [])

const models = computed(() => {
  const needle = search.value.trim().toLowerCase()

  if (!needle) {
    return allModels.value
  }

  return allModels.value.filter((model: { name: string, table: string }) =>
    model.name.toLowerCase().includes(needle) || model.table.toLowerCase().includes(needle)
  )
})

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
