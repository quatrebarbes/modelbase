<script setup lang="ts">
// EX-401/EX-402/EX-403/EX-404 : listing paginé des items d'un modèle.
const route = useRoute()
const connection = route.params.connection as string
const model = route.params.model as string

const api = useApiClient()
const page = ref(1)
const perPage = 15

const { data } = await useAsyncData(
  () => `items-${connection}-${model}-${page.value}`,
  () => api(`/connections/${connection}/models/${model}/items`, {
    query: { page: page.value, per_page: perPage },
  }),
  { watch: [page] }
)

const items = computed(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)
</script>

<template>
  <main>
    <h1>Items — {{ model }}</h1>
    <div class="toolbar">
      <NuxtLink :to="`/connections/${connection}`" class="toolbar__link">← Modèles</NuxtLink>
      <!-- EX-412 : point d'entrée vers la création d'un nouvel item -->
      <NuxtLink
        :to="`/connections/${connection}/models/${model}/items/create`"
        class="btn btn--primary"
      >
        + Nouvel item
      </NuxtLink>
    </div>
    <ItemList :connection="connection" :model="model" :items="items" />
    <ItemPagination v-if="meta && meta.total > 0" v-model:page="page" :meta="meta" />
  </main>
</template>
