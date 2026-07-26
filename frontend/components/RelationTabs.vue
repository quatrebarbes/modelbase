<script setup lang="ts">
// Regroupe les tableaux de plusieurs relations (EX-425 à EX-431) sous un
// système d'onglets plutôt que de les empiler verticalement — un seul
// tableau (celui de l'onglet actif) est monté/interrogé à la fois.
type RelationDescriptor = {
  name: string
  type: string
  multiplicity: 'one' | 'many'
  related_model: string
  related_connection: string
  related_table: string
  navigable: boolean
}

const props = defineProps<{
  connection: string
  model: string
  item: string
  relations: RelationDescriptor[]
}>()

const active = ref(props.relations[0]?.name)

// Si la liste des relations change (navigation vers un autre item/modèle),
// l'onglet actif retombe sur le premier plutôt que de pointer vers un nom
// devenu obsolète.
watch(() => props.relations, (relations) => {
  if (!relations.some((relation) => relation.name === active.value)) {
    active.value = relations[0]?.name
  }
})

const activeRelation = computed(() => props.relations.find((relation) => relation.name === active.value) ?? null)
</script>

<template>
  <RelationTable
    v-if="relations.length === 1"
    :connection="connection"
    :model="model"
    :item="item"
    :relation="relations[0]"
  />
  <div v-else-if="relations.length > 1" class="relation-tabs">
    <div class="relation-tabs__bar" role="tablist">
      <button
        v-for="relation in relations"
        :key="relation.name"
        type="button"
        role="tab"
        class="relation-tabs__tab"
        :class="{ 'relation-tabs__tab--active': relation.name === active }"
        :aria-selected="relation.name === active"
        @click="active = relation.name"
      >
        {{ relation.name }}
      </button>
    </div>
    <RelationTable
      v-if="activeRelation"
      :key="activeRelation.name"
      :connection="connection"
      :model="model"
      :item="item"
      :relation="activeRelation"
      :show-title="false"
    />
  </div>
</template>

<style scoped>
.relation-tabs {
  margin-top: 1.5rem;
}

.relation-tabs__bar {
  display: flex;
  gap: 1.25rem;
  flex-wrap: wrap;
  border-bottom: 1px solid var(--color-border);
}

/* Onglets « soulignés » plutôt que des pastilles pleines : un indicateur en
   trait sous le libellé (pas de fond, pas de contour) pour se distinguer
   nettement des boutons d'action (.btn) du reste de l'IHM. */
.relation-tabs__tab {
  padding: 0.5rem 0.1rem;
  border: none;
  border-bottom: 2px solid transparent;
  background: none;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
  margin-bottom: -1px;
}

/* EX-112 : survol par contour — ici le trait inférieur — plutôt qu'un aplat de fond */
.relation-tabs__tab:hover {
  border-bottom-color: var(--color-hover);
}

.relation-tabs__tab--active {
  border-bottom-color: var(--color-primary);
  color: var(--color-text);
  font-weight: 600;
}
</style>
