<script setup lang="ts">
// EX-412/EX-414/EX-415/EX-416/EX-417 : création d'un nouvel item du modèle.
const route = useRoute()
const connection = route.params.connection as string
const model = route.params.model as string

const api = useApiClient()
const { data: columnsData } = await useAsyncData(
  `create-columns-${connection}-${model}`,
  () => api(`/connections/${connection}/models/${model}/columns`)
)
const columns = computed(() => columnsData.value?.data ?? [])

const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)

async function handleSubmit(values: Record<string, unknown>) {
  submitting.value = true
  errors.value = {}

  try {
    const response = await api(`/connections/${connection}/models/${model}/items`, {
      method: 'POST',
      body: values,
    })

    await navigateTo(`/connections/${connection}/models/${model}/items/${response.data.id}`)
  } catch (error: any) {
    errors.value = error?.data?.errors ?? { _general: ['La création a échoué.'] }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main>
    <h1>{{ model }} — nouvel item</h1>
    <NuxtLink :to="`/connections/${connection}/models/${model}`">← Listing</NuxtLink>

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
