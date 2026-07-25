<script setup lang="ts">
// EX-401/EX-402/EX-403/EX-404 : listing paginé des items d'un modèle.
const route = useRoute()
const connection = route.params.connection as string
const model = route.params.model as string

const api = useApiClient()
const { t } = useI18n()
const page = ref(1)
const perPage = 15

const { data, pending } = await useAsyncData(
  () => `items-${connection}-${model}-${page.value}`,
  () => api(`/connections/${connection}/models/${model}/items`, {
    query: { page: page.value, per_page: perPage },
  }),
  { watch: [page] }
)

const items = computed(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)

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
      <!-- EX-412 : point d'entrée vers la création d'un nouvel item -->
      <NuxtLink
        :to="`/connections/${connection}/models/${model}/items/create`"
        class="btn btn--primary"
      >
        {{ $t('items.new') }}
      </NuxtLink>
    </div>
    <ItemList :connection="connection" :model="model" :items="items" />
    <div v-if="meta && meta.total > 0" class="item-pagination-row">
      <ItemPagination v-model:page="page" :meta="meta" />
      <Spinner v-if="pending" />
    </div>
  </main>
</template>
