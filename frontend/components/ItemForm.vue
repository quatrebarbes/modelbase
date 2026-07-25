<script setup lang="ts">
// EX-412/EX-413/EX-414/EX-415/EX-416/EX-417 : formulaire de création/
// modification d'un item, construit à partir du schéma de colonnes
// (GET .../columns) plutôt que d'un item existant — valable aussi bien pour
// la création (aucun item) que pour la modification (valeurs préremplies).
type ColumnSchema = {
  column: string
  type: 'string' | 'number' | 'boolean' | 'date' | 'json' | 'foreign_key'
  technical: boolean
  fillable: boolean
  foreign_key?: { table: string; model: string | null }
}

// EX-421 : une colonne non fillable côté modèle hôte, hors colonne technique
// (déjà couverte par EX-416), est traitée en lecture seule au même titre.
function isReadOnly(column: ColumnSchema): boolean {
  return column.technical || !column.fillable
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
  cancellable: false,
})

const { t } = useI18n()
const resolvedSubmitLabel = computed(() => props.submitLabel ?? t('itemForm.save'))

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
  <form class="item-form field-grid" @submit.prevent="handleSubmit">
    <div v-for="column in columns" :key="column.column" class="item-form__field">
      <label :for="`field-${column.column}`" class="field-grid__label">
        {{ column.column }}
        <!-- EX-416 : colonnes techniques affichées mais non modifiables -->
        <span v-if="column.technical" class="item-form__technical">{{ $t('itemForm.technicalReadOnly') }}</span>
        <!-- EX-421 : colonne non fillable côté modèle hôte, non modifiable au même titre -->
        <span v-else-if="!column.fillable" class="item-form__technical">{{ $t('itemForm.notEditable') }}</span>
      </label>

      <div class="item-form__control">
        <!-- EX-415 : sélecteur d'item existant pour une colonne FK -->
        <ItemPicker
          v-if="column.type === 'foreign_key' && column.foreign_key?.model && !isReadOnly(column)"
          v-model="values[column.column]"
          :connection="connection"
          :model="column.foreign_key.model"
          nullable
        />

        <input
          v-else-if="column.type === 'boolean'"
          :id="`field-${column.column}`"
          type="checkbox"
          class="item-form__checkbox"
          :checked="Boolean(values[column.column])"
          :disabled="isReadOnly(column) || disabled"
          @change="values[column.column] = ($event.target as HTMLInputElement).checked"
        >

        <input
          v-else-if="column.type === 'number'"
          :id="`field-${column.column}`"
          type="number"
          step="any"
          :value="values[column.column] ?? ''"
          :disabled="isReadOnly(column) || disabled"
          @input="values[column.column] = ($event.target as HTMLInputElement).value"
        >

        <input
          v-else-if="column.type === 'date'"
          :id="`field-${column.column}`"
          type="datetime-local"
          :value="values[column.column] ?? ''"
          :disabled="isReadOnly(column) || disabled"
          @input="values[column.column] = ($event.target as HTMLInputElement).value"
        >

        <textarea
          v-else-if="column.type === 'json'"
          :id="`field-${column.column}`"
          :value="jsonDrafts[column.column]"
          :disabled="isReadOnly(column) || disabled"
          rows="4"
          @input="updateJsonDraft(column.column, ($event.target as HTMLTextAreaElement).value)"
        />

        <input
          v-else
          :id="`field-${column.column}`"
          type="text"
          :value="values[column.column] ?? ''"
          :disabled="isReadOnly(column) || disabled"
          @input="values[column.column] = ($event.target as HTMLInputElement).value"
        >

        <p v-if="column.type === 'json' && jsonErrors[column.column]" class="item-form__error">{{ $t('itemForm.invalidJson') }}</p>
        <!-- EX-417 : erreurs de validation natives de la colonne, remontées telles quelles -->
        <p v-for="message in (errors[column.column] ?? [])" :key="message" class="item-form__error">{{ message }}</p>
      </div>
    </div>

    <p v-for="message in (errors._general ?? [])" :key="message" class="item-form__error item-form__error--general">{{ message }}</p>

    <div class="item-form__actions">
      <button type="submit" class="btn btn--primary" :disabled="disabled">
        {{ resolvedSubmitLabel }}
      </button>
      <button
        v-if="cancellable"
        type="button"
        class="btn"
        :disabled="disabled"
        @click="emit('cancel')"
      >
        {{ $t('itemForm.cancel') }}
      </button>
    </div>
  </form>
</template>

<style scoped>
.item-form__field {
  display: contents;
}

.item-form__technical {
  display: block;
  font-weight: 400;
  font-size: 0.8rem;
  font-style: italic;
  color: var(--color-text-muted);
}

.item-form__control {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  min-width: 0;
}

.item-form__control input:not(.item-form__checkbox),
.item-form__control select,
.item-form__control textarea {
  width: 100%;
  box-sizing: border-box;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-bg);
  color: var(--color-text);
  font: inherit;
  transition: border-color 0.15s ease;
}

/* EX-112 : un champ adopte la couleur de survol — juste le contour */
.item-form__control input:not(.item-form__checkbox):not(:disabled):hover,
.item-form__control select:not(:disabled):hover,
.item-form__control textarea:not(:disabled):hover {
  border-color: var(--color-hover);
}

.item-form__control input:not(.item-form__checkbox):focus,
.item-form__control select:focus,
.item-form__control textarea:focus {
  outline: none;
  border-color: var(--color-border-focus);
}

.item-form__control input:disabled,
.item-form__control select:disabled,
.item-form__control textarea:disabled {
  background: var(--color-bg-muted);
  color: var(--color-text-muted);
}

.item-form__checkbox {
  justify-self: start;
  margin-top: 0.55rem;
}

.item-form__error {
  color: var(--color-error-text);
  font-size: 0.85rem;
  margin: 0;
}

.item-form__error--general {
  grid-column: 1 / -1;
}

.item-form__actions {
  grid-column: 1 / -1;
  display: flex;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
</style>
