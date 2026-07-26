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

// EX-306 : accès au diagramme des relations depuis le listing des items.
const showDiagram = ref(false)

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
    </div>
    <RelationDiagram
      v-if="showDiagram"
      :connection="connection"
      :model="model"
      @close="showDiagram = false"
    />
    <ItemList :connection="connection" :model="model" :items="items" :primary-key="meta?.primary_key" />
    <div v-if="meta && meta.total > 0" class="item-pagination-row">
      <ItemPagination v-model:page="page" :meta="meta" />
      <Spinner v-if="pending" />
    </div>
  </main>
</template>
