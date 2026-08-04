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
  // EX-463 : texte long (colonne SQL text/mediumtext/longtext), par
  // opposition à varchar/char — reste de type 'string' (EX-407), rendu en
  // éditeur multiligne plutôt qu'un simple champ texte.
  long?: boolean
}

// EX-464 : une colonne non fillable côté modèle hôte, hors colonne technique
// (déjà couverte par EX-416), est traitée en lecture seule au même titre.
function isReadOnly(column: ColumnSchema): boolean {
  return column.technical || !column.fillable
}

function isLongText(column: ColumnSchema): boolean {
  return column.type === 'string' && column.long === true
}

function isWide(column: ColumnSchema): boolean {
  return column.type === 'json' || isLongText(column)
}

const props = withDefaults(defineProps<{
  columns: ColumnSchema[]
  connection: string
  initialValues?: Record<string, unknown>
  errors?: Record<string, string[]>
  disabled?: boolean
  submitLabel?: string
  cancellable?: boolean
  // EX-465/EX-468 : bascule le formulaire en mode modification différentielle
  // (payload limité aux colonnes changées, validation désactivée tant
  // qu'aucune colonne n'est modifiée) — la création (EX-412, `<limite>`)
  // continue de transmettre toutes les colonnes renseignées.
  editing?: boolean
}>(), {
  initialValues: () => ({}),
  errors: () => ({}),
  disabled: false,
  cancellable: false,
  editing: false,
})

const { t } = useI18n()
const resolvedSubmitLabel = computed(() => props.submitLabel ?? t('itemForm.save'))

const emit = defineEmits<{
  submit: [values: Record<string, unknown>]
  cancel: []
}>()

const values = reactive<Record<string, unknown>>({ ...props.initialValues })

// L'API renvoie une colonne JSON sous forme de texte encodé, pas de valeur
// déjà structurée (ItemRepository::decorate() ne json_decode() pas la valeur
// lue en base) — reparsée ici pour disposer d'une valeur structurée aussi
// bien pour l'affichage initial de l'éditeur (EX-414) que pour la
// comparaison EX-467, plutôt que de comparer un objet (une fois retouché par
// l'utilisateur, cf. updateJsonDraft()) à la chaîne brute initiale.
function parseJsonValue(value: unknown): unknown {
  if (typeof value !== 'string') return value

  try {
    return JSON.parse(value)
  } catch {
    return value
  }
}

// EX-414 : l'éditeur JSON manipule une représentation texte à part, JSON.parse
// n'étant tenté qu'à la saisie — un textarea ne peut pas être lié directement
// à une valeur qui n'est pas une chaîne.
const jsonDrafts = reactive<Record<string, string>>({})
const jsonErrors = reactive<Record<string, boolean>>({})

for (const column of props.columns) {
  if (column.type === 'json') {
    values[column.column] = parseJsonValue(props.initialValues?.[column.column])
    jsonDrafts[column.column] = JSON.stringify(values[column.column] ?? null, null, 2)
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

// EX-467 : comparaison structurée (récursive, indépendante de l'ordre des
// clés) plutôt que textuelle — un simple reformatage de l'éditeur JSON ne
// doit pas être vu comme un changement.
function deepEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) return false

  const aIsArray = Array.isArray(a)
  const bIsArray = Array.isArray(b)
  if (aIsArray !== bIsArray) return false

  if (aIsArray && bIsArray) {
    return a.length === b.length && a.every((item, index) => deepEqual(item, b[index]))
  }

  const aKeys = Object.keys(a as Record<string, unknown>)
  const bKeys = Object.keys(b as Record<string, unknown>)
  return aKeys.length === bKeys.length
    && aKeys.every((key) => deepEqual((a as Record<string, unknown>)[key], (b as Record<string, unknown>)[key]))
}

// Comparaison par valeur (EX-465, `<limite>` sur le rétablissement manuel
// d'une valeur à son état initial) — tolère les changements de
// représentation qui ne changent pas la valeur effective (ex. un champ
// numérique retapé à l'identique arrive en chaîne, l'initiale en nombre).
function valuesEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true
  if (a === null || a === undefined || b === null || b === undefined) return false
  if (typeof a === 'object' || typeof b === 'object') return deepEqual(a, b)

  return String(a) === String(b)
}

function hasChanged(column: ColumnSchema): boolean {
  const initial = column.type === 'json'
    ? parseJsonValue(props.initialValues?.[column.column])
    : props.initialValues?.[column.column]

  return !valuesEqual(values[column.column], initial)
}

// EX-465 : colonnes effectivement modifiées par rapport aux valeurs
// initialement chargées, seules celles-ci sont transmises en mode
// modification (`editing`). EX-468 : tant qu'elle est vide, la validation du
// formulaire de modification n'est pas proposée à l'utilisateur.
const changedColumns = computed(() => props.columns.filter(hasChanged))
const hasChanges = computed(() => changedColumns.value.length > 0)

function handleSubmit(): void {
  if (Object.values(jsonErrors).some(Boolean)) return

  if (!props.editing) {
    emit('submit', { ...values })
    return
  }

  emit('submit', Object.fromEntries(changedColumns.value.map((column) => [column.column, values[column.column]])))
}

// EX-451 (exception) : les colonnes à rendu volumineux (JSON, texte long —
// pleine largeur de la grille, EX-450) sont replacées en fin de grille plutôt
// que laissées à leur position naturelle — même raisonnement que
// ItemDetail.vue.
const orderedColumns = computed(() => [
  ...props.columns.filter((column) => !isWide(column)),
  ...props.columns.filter((column) => isWide(column)),
])
</script>

<template>
  <form class="item-form field-grid" @submit.prevent="handleSubmit">
    <!-- EX-450 : l'éditeur JSON/texte long occupe toute la largeur de la grille. -->
    <div
      v-for="column in orderedColumns"
      :key="column.column"
      class="field-grid__field"
      :class="{ 'field-grid__field--wide': isWide(column) }"
    >
      <!-- L'annotation technique/lecture seule est affichée sous le contrôle
           (cf. ci-dessous) plutôt que dans le libellé : un libellé toujours
           sur une seule ligne garde les contrôles de la même rangée de la
           grille alignés à la même hauteur, quel que soit le nombre de
           champs annotés parmi eux. -->
      <label :for="`field-${column.column}`" class="field-grid__label">{{ column.column }}</label>

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

        <!-- EX-463 : un champ de texte long (SQL text/mediumtext/longtext)
             est rendu en éditeur multiligne plutôt qu'un simple champ texte. -->
        <textarea
          v-else-if="isLongText(column)"
          :id="`field-${column.column}`"
          :value="values[column.column] ?? ''"
          :disabled="isReadOnly(column) || disabled"
          rows="4"
          @input="values[column.column] = ($event.target as HTMLTextAreaElement).value"
        />

        <input
          v-else
          :id="`field-${column.column}`"
          type="text"
          :value="values[column.column] ?? ''"
          :disabled="isReadOnly(column) || disabled"
          @input="values[column.column] = ($event.target as HTMLInputElement).value"
        >

        <!-- EX-416 : colonnes techniques affichées mais non modifiables -->
        <p v-if="column.technical" class="item-form__technical">{{ $t('itemForm.technicalReadOnly') }}</p>
        <!-- EX-464 : colonne non fillable côté modèle hôte, non modifiable au même titre -->
        <p v-else-if="!column.fillable" class="item-form__technical">{{ $t('itemForm.notEditable') }}</p>

        <p v-if="column.type === 'json' && jsonErrors[column.column]" class="item-form__error">{{ $t('itemForm.invalidJson') }}</p>
        <!-- EX-417 : erreurs de validation natives de la colonne, remontées telles quelles -->
        <p v-for="message in (errors[column.column] ?? [])" :key="message" class="item-form__error">{{ message }}</p>
      </div>
    </div>

    <p v-for="message in (errors._general ?? [])" :key="message" class="item-form__error item-form__error--general">{{ message }}</p>

    <div class="item-form__actions">
      <button type="submit" class="btn btn--primary" :disabled="disabled || (editing && !hasChanges)">
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
.item-form__technical {
  margin: 0;
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
