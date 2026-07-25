<script setup lang="ts">
// EX-405/EX-406/EX-407/EX-408/EX-409/EX-410/EX-411 : fiche détail d'un item.
// EX-413/EX-414/EX-415/EX-416/EX-417 : bascule vers un formulaire de
// modification sur la même page plutôt qu'une route dédiée.
const route = useRoute()
const connection = route.params.connection as string
const model = route.params.model as string
const item = route.params.item as string

const api = useApiClient()
const toast = useToast()
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
const deleting = ref(false)
const deleteError = ref<string | null>(null)

useHead({ title: `${model} — item ${item}` })

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
    toast.show('Modifications enregistrées.')
  } catch (error: any) {
    errors.value = error?.data?.errors ?? { _general: ['La modification a échoué.'] }
  } finally {
    submitting.value = false
  }
}

// EX-418/EX-419/EX-420 : suppression, avec confirmation préalable obligatoire
// et affichage de l'éventuelle erreur d'intégrité référentielle renvoyée par
// l'API (409), sans jamais forcer la suppression.
async function handleDelete() {
  if (!window.confirm('Supprimer définitivement cet item ?')) return

  deleting.value = true
  deleteError.value = null

  try {
    await api(`/connections/${connection}/models/${model}/items/${item}`, { method: 'DELETE' })
    await navigateTo(`/connections/${connection}/models/${model}`)
    toast.show('Item supprimé.')
  } catch (error: any) {
    deleteError.value = error?.data?.message ?? 'La suppression a échoué.'
    deleting.value = false
  }
}
</script>

<template>
  <main>
    <!-- EX-411 : retour au listing des items du modèle, assuré par le fil d'Ariane -->
    <Breadcrumb :items="[
      { label: 'Bases de données', to: '/' },
      { label: connection, to: `/connections/${connection}` },
      { label: model, to: `/connections/${connection}/models/${model}` },
      { label: `Item ${item}` },
    ]" />
    <h1>{{ model }} — item {{ item }}</h1>
    <div v-if="!editing" class="toolbar">
      <div class="toolbar__actions">
        <button type="button" class="btn btn--primary" @click="editing = true">Modifier</button>
        <!-- EX-418/EX-419 : confirmation obligatoire avant suppression -->
        <button type="button" class="btn btn--danger" :disabled="deleting" @click="handleDelete">Supprimer</button>
      </div>
    </div>

    <!-- EX-420 : erreur d'intégrité référentielle renvoyée par l'API après confirmation -->
    <p v-if="deleteError" class="item-detail__delete-error">{{ deleteError }}</p>

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

<style scoped>
.item-detail__delete-error {
  color: var(--color-error);
}
</style>
