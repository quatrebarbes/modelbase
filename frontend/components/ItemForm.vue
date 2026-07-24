<script setup lang="ts">
// EX-412/EX-413/EX-414/EX-415/EX-416/EX-417 : formulaire de création/
// modification d'un item, construit à partir du schéma de colonnes
// (GET .../columns) plutôt que d'un item existant — valable aussi bien pour
// la création (aucun item) que pour la modification (valeurs préremplies).
type ColumnSchema = {
  column: string
  type: 'string' | 'number' | 'boolean' | 'date' | 'json' | 'foreign_key'
  technical: boolean
  foreign_key?: { table: string; model: string | null }
}

const props = withDefaults(defineProps<{
  columns: ColumnSchema[]
  connection: string
  initialValues?: Record<string, unknown>
  errors?: Record<string, string[]>
  disabled?: boolean
  submitLabel?: string
  cancellable?: boolean
}>(), {
  initialValues: () => ({}),
  errors: () => ({}),
  disabled: false,
  submitLabel: 'Enregistrer',
  cancellable: false,
})

const emit = defineEmits<{
  submit: [values: Record<string, unknown>]
  cancel: []
}>()

const values = reactive<Record<string, unknown>>({ ...props.initialValues })

// EX-414 : l'éditeur JSON manipule une représentation texte à part, JSON.parse
// n'étant tenté qu'à la saisie — un textarea ne peut pas être lié directement
// à une valeur qui n'est pas une chaîne.
const jsonDrafts = reactive<Record<string, string>>({})
const jsonErrors = reactive<Record<string, boolean>>({})

for (const column of props.columns) {
  if (column.type === 'json') {
    jsonDrafts[column.column] = JSON.stringify(props.initialValues?.[column.column] ?? null, null, 2)
  }
}

function updateJsonDraft(column: string, text: string): void {
  jsonDrafts[column] = text

  try {
    values[column] = JSON.parse(text)
    jsonErrors[column] = false
  } catch {
    jsonErrors[column] = true
  }
}

function handleSubmit(): void {
  if (Object.values(jsonErrors).some(Boolean)) return

  emit('submit', { ...values })
}
</script>

<template>
  <form class="item-form" @submit.prevent="handleSubmit">
    <div v-for="column in columns" :key="column.column" class="item-form__field">
      <label :for="`field-${column.column}`">
        {{ column.column }}
        <!-- EX-416 : colonnes techniques affichées mais non modifiables -->
        <span v-if="column.technical" class="item-form__technical">(technique, lecture seule)</span>
      </label>

      <!-- EX-415 : sélecteur d'item existant pour une colonne FK -->
      <ItemPicker
        v-if="column.type === 'foreign_key' && column.foreign_key?.model && !column.technical"
        v-model="values[column.column]"
        :connection="connection"
        :model="column.foreign_key.model"
        nullable
      />

      <input
        v-else-if="column.type === 'boolean'"
        :id="`field-${column.column}`"
        type="checkbox"
        :checked="Boolean(values[column.column])"
        :disabled="column.technical || disabled"
        @change="values[column.column] = ($event.target as HTMLInputElement).checked"
      >

      <input
        v-else-if="column.type === 'number'"
        :id="`field-${column.column}`"
        type="number"
        step="any"
        :value="values[column.column] ?? ''"
        :disabled="column.technical || disabled"
        @input="values[column.column] = ($event.target as HTMLInputElement).value"
      >

      <input
        v-else-if="column.type === 'date'"
        :id="`field-${column.column}`"
        type="datetime-local"
        :value="values[column.column] ?? ''"
        :disabled="column.technical || disabled"
        @input="values[column.column] = ($event.target as HTMLInputElement).value"
      >

      <textarea
        v-else-if="column.type === 'json'"
        :id="`field-${column.column}`"
        :value="jsonDrafts[column.column]"
        :disabled="column.technical || disabled"
        rows="4"
        @input="updateJsonDraft(column.column, ($event.target as HTMLTextAreaElement).value)"
      />

      <input
        v-else
        :id="`field-${column.column}`"
        type="text"
        :value="values[column.column] ?? ''"
        :disabled="column.technical || disabled"
        @input="values[column.column] = ($event.target as HTMLInputElement).value"
      >

      <p v-if="column.type === 'json' && jsonErrors[column.column]" class="item-form__error">JSON invalide.</p>
      <!-- EX-417 : erreurs de validation natives de la colonne, remontées telles quelles -->
      <p v-for="message in (errors[column.column] ?? [])" :key="message" class="item-form__error">{{ message }}</p>
    </div>

    <p v-for="message in (errors._general ?? [])" :key="message" class="item-form__error">{{ message }}</p>

    <div class="item-form__actions">
      <button type="submit" :disabled="disabled">{{ submitLabel }}</button>
      <button v-if="cancellable" type="button" :disabled="disabled" @click="emit('cancel')">Annuler</button>
    </div>
  </form>
</template>

<style scoped>
.item-form__field {
  margin-bottom: 0.75rem;
}

.item-form__technical {
  font-size: 0.8rem;
  font-style: italic;
  opacity: 0.6;
}

.item-form__error {
  color: #b91c1c;
  font-size: 0.85rem;
  margin: 0.15rem 0 0;
}

.item-form__actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 1rem;
}
</style>
