<script setup lang="ts">
type ModelSummary = {
  name: string
  table: string
  item_count: number
  column_count: number
}

const props = defineProps<{
  connection: string
  models: ModelSummary[]
}>()

function goToModel(model: ModelSummary) {
  navigateTo(`/connections/${props.connection}/models/${model.name}`)
}
</script>

<template>
  <!-- EX-301, limite « aucun modèle disponible » : message, pas d'erreur -->
  <p v-if="models.length === 0">Aucun modèle disponible pour cette connexion.</p>
  <table v-else class="data-table">
    <thead>
      <tr>
        <th>Nom</th>
        <th>Table</th>
        <th>Items</th>
        <th>Colonnes</th>
      </tr>
    </thead>
    <tbody>
      <!-- EX-303 : navigue vers le listing des items du modèle (module 4) -->
      <tr
        v-for="model in models"
        :key="model.name"
        tabindex="0"
        @click="goToModel(model)"
        @keydown.enter="goToModel(model)"
      >
        <td>{{ model.name }}</td>
        <td>{{ model.table }}</td>
        <td>{{ model.item_count }}</td>
        <td>{{ model.column_count }}</td>
      </tr>
    </tbody>
  </table>
</template>
