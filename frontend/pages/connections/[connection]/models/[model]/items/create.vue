<script setup lang="ts">
// EX-412/EX-414/EX-415/EX-416/EX-417 : création d'un nouvel item du modèle.
const route = useRoute()
const connection = route.params.connection as string
const model = route.params.model as string

const api = useApiClient()
const toast = useToast()
const { data: columnsData } = await useAsyncData(
  `create-columns-${connection}-${model}`,
  () => api(`/connections/${connection}/models/${model}/columns`)
)
const columns = computed(() => columnsData.value?.data ?? [])

const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)

useHead({ title: `${model} — nouvel item` })

async function handleSubmit(values: Record<string, unknown>) {
  submitting.value = true
  errors.value = {}

  try {
    const response = await api(`/connections/${connection}/models/${model}/items`, {
      method: 'POST',
      body: values,
    })

    await navigateTo(`/connections/${connection}/models/${model}/items/${response.data.id}`)
    toast.show('Item créé.')
  } catch (error: any) {
    errors.value = error?.data?.errors ?? { _general: ['La création a échoué.'] }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main>
    <Breadcrumb :items="[
      { label: 'Bases de données', to: '/' },
      { label: connection, to: `/connections/${connection}` },
      { label: model, to: `/connections/${connection}/models/${model}` },
      { label: 'Nouvel item' },
    ]" />
    <h1>{{ model }} — nouvel item</h1>

    <ItemForm
      :columns="columns"
      :connection="connection"
      :errors="errors"
      :disabled="submitting"
      submit-label="Créer"
      @submit="handleSubmit"
    />
  </main>
</template>
