<script setup lang="ts">
// EX-405/EX-406/EX-407/EX-408/EX-409/EX-410/EX-411 : fiche détail d'un item.
// EX-413/EX-414/EX-415/EX-416/EX-417 : bascule vers un formulaire de
// modification sur la même page plutôt qu'une route dédiée.
const route = useRoute()
const connection = route.params.connection as string
const model = route.params.model as string
const item = route.params.item as string

const api = useApiClient()
const { data, refresh } = await useAsyncData(
  `item-${connection}-${model}-${item}`,
  () => api(`/connections/${connection}/models/${model}/items/${item}`)
)
const { data: columnsData } = await useAsyncData(
  `item-columns-${connection}-${model}`,
  () => api(`/connections/${connection}/models/${model}/columns`)
)

const values = computed(() => data.value?.data?.values ?? [])
const columns = computed(() => columnsData.value?.data ?? [])
const initialValues = computed(() => Object.fromEntries(
  values.value.map((entry: { column: string; value: unknown }) => [entry.column, entry.value])
))

const editing = ref(false)
const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)

async function handleSubmit(submitted: Record<string, unknown>) {
  submitting.value = true
  errors.value = {}

  try {
    await api(`/connections/${connection}/models/${model}/items/${item}`, {
      method: 'PATCH',
      body: submitted,
    })

    await refresh()
    editing.value = false
  } catch (error: any) {
    errors.value = error?.data?.errors ?? { _general: ['La modification a échoué.'] }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main>
    <h1>{{ model }} — item {{ item }}</h1>
    <div class="toolbar">
      <!-- EX-411 : retour au listing des items du modèle -->
      <NuxtLink :to="`/connections/${connection}/models/${model}`" class="toolbar__link">← Listing</NuxtLink>
      <button v-if="!editing" type="button" class="btn btn--primary" @click="editing = true">Modifier</button>
    </div>

    <ItemForm
      v-if="editing"
      :columns="columns"
      :connection="connection"
      :initial-values="initialValues"
      :errors="errors"
      :disabled="submitting"
      cancellable
      submit-label="Enregistrer"
      @submit="handleSubmit"
      @cancel="editing = false"
    />
    <ItemDetail v-else :connection="connection" :values="values" />
  </main>
</template>
