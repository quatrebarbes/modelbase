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
const { t } = useI18n()
const { data, refresh } = await useAsyncData(
  `item-${connection}-${model}-${item}`,
  () => api(`/connections/${connection}/models/${model}/items/${item}`)
)
const { data: columnsData } = await useAsyncData(
  `item-columns-${connection}-${model}`,
  () => api(`/connections/${connection}/models/${model}/columns`)
)
// EX-425 : un tableau par relation déclarée par le modèle hôte, sous les
// valeurs de colonnes — `belongsTo` exclue (déjà couverte par la valeur de
// colonne de clé étrangère, EX-408).
const { data: relationsData } = await useAsyncData(
  `item-relations-${connection}-${model}`,
  () => api(`/connections/${connection}/models/${model}/relations`)
)

const values = computed(() => data.value?.data?.values ?? [])
// EX-439 : indicateur d'item soft-deleted, porté par la fiche détail.
const isTrashed = computed(() => data.value?.data?.is_trashed ?? false)
// Indique si le modèle gère SoftDeletes (indépendamment du statut de cet item
// précis) — nécessaire pour choisir le bon message de confirmation avant une
// suppression standard (EX-419) : pour ce modèle, "Supprimer" ne fait qu'un
// soft-delete (EX-437), pas une suppression physique, contrairement au
// message générique historique qui annonçait à tort une suppression
// définitive.
const softDeletes = computed(() => data.value?.data?.soft_deletes ?? false)
const columns = computed(() => columnsData.value?.data ?? [])
const relations = computed(() => (relationsData.value?.data ?? []).filter((relation: { type: string }) => relation.type !== 'BelongsTo'))
const initialValues = computed(() => Object.fromEntries(
  values.value.map((entry: { column: string; value: unknown }) => [entry.column, entry.value])
))

const editing = ref(false)
const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)
const restoring = ref(false)
const forceDeleting = ref(false)
const forceDeleteError = ref<string | null>(null)

useHead({ title: t('itemDetail.title', { model, item }) })

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
    toast.show(t('itemDetail.saved'))
  } catch (error: any) {
    errors.value = error?.data?.errors ?? { _general: [t('itemDetail.updateFailed')] }
  } finally {
    submitting.value = false
  }
}

// EX-418/EX-419/EX-420 : suppression, avec confirmation préalable obligatoire
// et affichage de l'éventuelle erreur d'intégrité référentielle renvoyée par
// l'API (409), sans jamais forcer la suppression. EX-437 : pour un modèle
// SoftDeletes, cette même action ne fait que soft-delete l'item (restaurable,
// cf. EX-440) — le message de confirmation le précise pour ne pas laisser
// croire à une suppression physique.
async function handleDelete() {
  const message = softDeletes.value ? t('itemDetail.confirmSoftDelete') : t('itemDetail.confirmDelete')
  if (!window.confirm(message)) return

  deleting.value = true
  deleteError.value = null

  try {
    await api(`/connections/${connection}/models/${model}/items/${item}`, { method: 'DELETE' })
    await navigateTo(`/connections/${connection}/models/${model}`)
    toast.show(t('itemDetail.deleted'))
  } catch (error: any) {
    deleteError.value = error?.data?.message ?? t('itemDetail.deleteFailed')
    deleting.value = false
  }
}

// EX-440 : restauration d'un item soft-deleted, proposée uniquement quand
// isTrashed est vrai (cf. template).
async function handleRestore() {
  restoring.value = true

  try {
    await api(`/connections/${connection}/models/${model}/items/${item}/restore`, { method: 'POST' })
    await refresh()
    toast.show(t('itemDetail.restored'))
  } catch {
    toast.show(t('itemDetail.restoreFailed'))
  } finally {
    restoring.value = false
  }
}

// EX-441/EX-442 : suppression définitive (physique), distincte de la
// suppression standard — confirmation dédiée en plus de celle déjà requise
// pour la suppression standard (EX-419), affichage d'une éventuelle erreur
// d'intégrité référentielle (même principe que handleDelete), sans jamais
// forcer la suppression.
async function handleForceDelete() {
  if (!window.confirm(t('itemDetail.confirmForceDelete'))) return

  forceDeleting.value = true
  forceDeleteError.value = null

  try {
    await api(`/connections/${connection}/models/${model}/items/${item}/force`, { method: 'DELETE' })
    await navigateTo(`/connections/${connection}/models/${model}`)
    toast.show(t('itemDetail.forceDeleted'))
  } catch (error: any) {
    forceDeleteError.value = error?.data?.message ?? t('itemDetail.forceDeleteFailed')
    forceDeleting.value = false
  }
}
</script>

<template>
  <main>
    <!-- EX-411 : retour au listing des items du modèle, assuré par le fil d'Ariane -->
    <Breadcrumb :items="[
      { label: $t('common.databases'), to: '/' },
      { label: connection, to: `/connections/${connection}` },
      { label: model, to: `/connections/${connection}/models/${model}` },
      { label: $t('itemDetail.breadcrumbItem', { item }) },
    ]" />
    <h1>
      {{ $t('itemDetail.title', { model, item }) }}
      <!-- EX-439 : indicateur visuel distinctif d'un item soft-deleted -->
      <span v-if="isTrashed" class="item-detail__trashed-badge">{{ $t('itemDetail.trashedBadge') }}</span>
    </h1>
    <div v-if="!editing" class="toolbar">
      <!-- EX-440/EX-441 : depuis la fiche détail d'un item soft-deleted, seules
           la restauration et la suppression définitive sont proposées — ni
           modification, ni suppression standard (déjà appliquée, EX-443). -->
      <div v-if="isTrashed" class="toolbar__actions">
        <button type="button" class="btn btn--primary" :disabled="restoring" @click="handleRestore">
          {{ $t('itemDetail.restore') }}
        </button>
        <!-- EX-442 : confirmation dédiée, en plus de celle de la suppression standard -->
        <button type="button" class="btn btn--danger" :disabled="forceDeleting" @click="handleForceDelete">
          {{ $t('itemDetail.forceDelete') }}
        </button>
      </div>
      <div v-else class="toolbar__actions">
        <button type="button" class="btn btn--primary" @click="editing = true">{{ $t('itemDetail.edit') }}</button>
        <!-- EX-418/EX-419 : confirmation obligatoire avant suppression -->
        <button type="button" class="btn btn--danger" :disabled="deleting" @click="handleDelete">{{ $t('itemDetail.delete') }}</button>
      </div>
    </div>

    <!-- EX-420 : erreur d'intégrité référentielle renvoyée par l'API après confirmation -->
    <p v-if="deleteError" class="item-detail__delete-error">{{ deleteError }}</p>
    <!-- Même protection qu'EX-420, appliquée à la suppression définitive -->
    <p v-if="forceDeleteError" class="item-detail__delete-error">{{ forceDeleteError }}</p>

    <ItemForm
      v-if="editing"
      :columns="columns"
      :connection="connection"
      :initial-values="initialValues"
      :errors="errors"
      :disabled="submitting"
      cancellable
      @submit="handleSubmit"
      @cancel="editing = false"
    />
    <template v-else>
      <ItemDetail :connection="connection" :values="values" />
      <!-- EX-425 à EX-431 : tableaux paginés des objets liés par relation,
           regroupés sous des onglets dès qu'il y en a plus d'une -->
      <RelationTabs
        :connection="connection"
        :model="model"
        :item="item"
        :relations="relations"
      />
    </template>
  </main>
</template>

<style scoped>
.item-detail__delete-error {
  color: var(--color-error-text);
}

.item-detail__trashed-badge {
  display: inline-block;
  vertical-align: middle;
  margin-inline-start: 0.5rem;
  padding: 0.15rem 0.6rem;
  border-radius: var(--radius-pill);
  background: var(--color-error-text);
  color: var(--color-bg);
  font-size: 0.6em;
  font-weight: 600;
  text-transform: uppercase;
}
</style>
