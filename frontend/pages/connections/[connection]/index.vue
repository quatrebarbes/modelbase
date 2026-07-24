<script setup lang="ts">
// EX-301/EX-302/EX-304 : liste des modèles d'une connexion, filtrable par nom.
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
    <input v-model="search" type="search" placeholder="Filtrer par nom" />
    <ModelList :connection="connection" :models="models" />
  </main>
</template>
