<script setup lang="ts">
type ForeignKey = {
  table: string
  model: string | null
  navigable: boolean
}

type ItemValue = {
  column: string
  type: 'string' | 'number' | 'boolean' | 'date' | 'json' | 'foreign_key'
  value: unknown
  is_null: boolean
  foreign_key?: ForeignKey
}

defineProps<{
  connection: string
  values: ItemValue[]
}>()
</script>

<template>
  <dl class="item-detail">
    <template v-for="entry in values" :key="entry.column">
      <dt>{{ entry.column }}</dt>
      <dd>
        <!-- EX-409 : rendu distinct d'une valeur nulle vs une chaîne vide -->
        <span v-if="entry.is_null" class="item-detail__null">NULL</span>

        <!-- EX-408/EX-410 : lien vers l'item référencé, ou indicateur de FK cassée -->
        <template v-else-if="entry.type === 'foreign_key'">
          <NuxtLink
            v-if="entry.foreign_key?.navigable"
            :to="`/connections/${connection}/models/${entry.foreign_key.model}/items/${entry.value}`"
          >
            {{ entry.value }} ({{ entry.foreign_key.model }})
          </NuxtLink>
          <span v-else class="item-detail__broken-fk">
            {{ entry.value }} — item référencé introuvable
          </span>
        </template>

        <!-- EX-407 : rendu adapté au type de la colonne -->
        <pre v-else-if="entry.type === 'json'">{{ JSON.stringify(entry.value, null, 2) }}</pre>
        <span v-else-if="entry.type === 'boolean'">{{ entry.value ? 'Oui' : 'Non' }}</span>
        <span v-else-if="entry.value === ''" class="item-detail__empty-string">(chaîne vide)</span>
        <span v-else>{{ entry.value }}</span>
      </dd>
    </template>
  </dl>
</template>

<style scoped>
.item-detail {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.5rem 1rem;
}

.item-detail dt {
  font-weight: 600;
}

.item-detail__null,
.item-detail__empty-string {
  font-style: italic;
  opacity: 0.6;
}

.item-detail__broken-fk {
  color: #b91c1c;
}
</style>
