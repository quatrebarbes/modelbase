<script setup lang="ts">
// EX-415 : sélecteur d'item existant pour une colonne de type clé étrangère —
// liste paginée du modèle référencé, réutilisant l'endpoint de listing
// existant (module 4a) plutôt qu'un endpoint dédié.
const props = defineProps<{
  connection: string
  model: string
  modelValue: unknown
  nullable?: boolean
}>()

defineEmits<{ 'update:modelValue': [value: string | null] }>()

const api = useApiClient()
const { data } = await useAsyncData(
  `item-picker-${props.connection}-${props.model}`,
  () => api(`/connections/${props.connection}/models/${props.model}/items`, { query: { per_page: 100 } })
)

const options = computed(() => (data.value?.data ?? []) as Array<Record<string, unknown>>)
// EX-415 : la colonne de clé primaire du modèle référencé n'est pas
// forcément nommée `id` (cf. `meta.primary_key`, ItemRepository::paginate()).
const primaryKey = computed(() => data.value?.meta?.primary_key ?? 'id')

function keyOf(row: Record<string, unknown>): unknown {
  return row[primaryKey.value]
}

function labelFor(row: Record<string, unknown>): string {
  const previewKey = Object.keys(row).find((key) => key !== primaryKey.value)
  const preview = previewKey ? row[previewKey] : undefined
  const key = keyOf(row)

  return preview !== undefined ? `#${key} — ${preview}` : `#${key}`
}
</script>

<template>
  <select
    :value="modelValue != null ? String(modelValue) : ''"
    @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value || null)"
  >
    <option v-if="nullable" value="">—</option>
    <option v-for="row in options" :key="String(keyOf(row))" :value="String(keyOf(row))">
      {{ labelFor(row) }}
    </option>
  </select>
</template>
