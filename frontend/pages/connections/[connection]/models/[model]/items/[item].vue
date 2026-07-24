<script setup lang="ts">
// EX-405/EX-406/EX-407/EX-408/EX-409/EX-410/EX-411 : fiche détail d'un item.
const route = useRoute()
const connection = route.params.connection as string
const model = route.params.model as string
const item = route.params.item as string

const api = useApiClient()
const { data } = await useAsyncData(
  `item-${connection}-${model}-${item}`,
  () => api(`/connections/${connection}/models/${model}/items/${item}`)
)

const values = computed(() => data.value?.data?.values ?? [])
</script>

<template>
  <main>
    <h1>{{ model }} — item {{ item }}</h1>
    <!-- EX-411 : retour au listing des items du modèle -->
    <NuxtLink :to="`/connections/${connection}/models/${model}`">← Listing</NuxtLink>
    <ItemDetail :connection="connection" :values="values" />
  </main>
</template>
