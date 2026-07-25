<script setup lang="ts">
// EX-425 à EX-431 : un tableau paginé par relation Eloquent déclarée par le
// modèle hôte, sous les valeurs de colonnes de la fiche détail d'un item.
type RelationDescriptor = {
  name: string
  type: string
  multiplicity: 'one' | 'many'
  related_model: string
  related_connection: string
  related_table: string
  navigable: boolean
}

const props = withDefaults(defineProps<{
  connection: string
  model: string
  item: string
  relation: RelationDescriptor
  showTitle?: boolean
}>(), {
  showTitle: true,
})

const api = useApiClient()
const page = ref(1)
const perPage = 15

// EX-431 : une relation non navigable n'est jamais interrogée — l'API
// bloquerait de toute façon la requête (409), la connexion étant injoignable.
const { data, pending } = await useAsyncData(
  `relation-items-${props.connection}-${props.model}-${props.item}-${props.relation.name}-${page.value}`,
  () => props.relation.navigable
    ? api(`/connections/${props.connection}/models/${props.model}/items/${props.item}/relations/${props.relation.name}`, {
        query: { page: page.value, per_page: perPage },
      })
    : Promise.resolve(null),
  { watch: [page] }
)

const rows = computed<Array<Record<string, unknown>>>(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)
const columns = computed(() => (rows.value.length ? Object.keys(rows.value[0]) : []))

function goToRelatedItem(row: Record<string, unknown>) {
  // EX-428 : navigue vers la fiche détail de l'item lié, dans son propre
  // modèle (potentiellement une autre connexion que l'item courant).
  navigateTo(`/connections/${props.relation.related_connection}/models/${props.relation.related_model}/items/${row.id}`)
}
</script>

<template>
  <section class="relation-table">
    <!-- EX-426 : quand plusieurs relations sont affichées via des onglets
         (RelationTabs.vue), le libellé de l'onglet actif porte déjà ce nom —
         le titre ici serait redondant. -->
    <h2 v-if="showTitle">{{ relation.name }}</h2>

    <!-- EX-431 : connexion cible indisponible, indication sans lien -->
    <p v-if="!relation.navigable" class="relation-table__unavailable">
      {{ $t('relations.unavailableConnection', { connection: relation.related_connection }) }}
    </p>

    <template v-else>
      <!-- EX-430 : message dédié, pas d'erreur, si la relation n'a aucun objet lié -->
      <p v-if="!pending && rows.length === 0">{{ $t('relations.empty') }}</p>
      <table v-else class="data-table">
        <thead>
          <tr>
            <th v-for="column in columns" :key="column">{{ column }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in rows"
            :key="String(row.id)"
            tabindex="0"
            @click="goToRelatedItem(row)"
            @keydown.enter="goToRelatedItem(row)"
          >
            <td v-for="column in columns" :key="column">{{ row[column] }}</td>
          </tr>
        </tbody>
      </table>
      <!-- EX-429 : pagination identique au listing standard d'un modèle -->
      <div v-if="meta && meta.total > 0" class="item-pagination-row">
        <ItemPagination v-model:page="page" :meta="meta" />
        <Spinner v-if="pending" />
      </div>
    </template>
  </section>
</template>

<style scoped>
.relation-table {
  margin-top: 1.5rem;
}

.relation-table__unavailable {
  color: var(--color-text-muted);
  font-style: italic;
}
</style>
