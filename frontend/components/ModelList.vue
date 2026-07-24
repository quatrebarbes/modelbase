<script setup lang="ts">
type ModelSummary = {
  name: string
  table: string
  item_count: number
  column_count: number
}

defineProps<{
  connection: string
  models: ModelSummary[]
}>()
</script>

<template>
  <!-- EX-301, limite « aucun modèle disponible » : message, pas d'erreur -->
  <p v-if="models.length === 0">Aucun modèle disponible pour cette connexion.</p>
  <ul v-else class="model-list">
    <li v-for="model in models" :key="model.name" class="model-list__item">
      <!-- EX-303 : navigue vers le listing des items du modèle (module 4) -->
      <NuxtLink :to="`/connections/${connection}/models/${model.name}`">
        <strong>{{ model.name }}</strong>
        <span>{{ model.table }}</span>
        <span>{{ model.item_count }} item(s)</span>
        <span>{{ model.column_count }} colonne(s)</span>
      </NuxtLink>
    </li>
  </ul>
</template>

<style scoped>
.model-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.model-list__item a {
  display: flex;
  gap: 0.75rem;
  align-items: baseline;
}
</style>
