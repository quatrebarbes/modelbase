<script setup lang="ts">
// EX-301/EX-302/EX-304 : liste des modèles d'une connexion, filtrable par nom ou par table.
const route = useRoute()
const connection = route.params.connection as string

const api = useApiClient()
const search = ref('')

const { data } = await useAsyncData(
  () => `models-${connection}-${search.value}`,
  () => api(`/connections/${connection}/models`, {
    query: search.value ? { search: search.value } : {},
  }),
  { watch: [search] }
)

const models = computed(() => data.value?.data ?? [])
</script>

<template>
  <main>
    <h1>Modèles — {{ connection }}</h1>
    <div class="toolbar">
      <NuxtLink to="/" class="toolbar__link">← Bases de données</NuxtLink>
      <input
        v-model="search"
        type="search"
        placeholder="Filtrer par nom ou table"
        class="toolbar__search"
      />
    </div>
    <ModelList :connection="connection" :models="models" />
  </main>
</template>
