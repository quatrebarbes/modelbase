<script setup lang="ts">
type ModelSummary = {
  name: string
  table: string
  item_count: string
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
  <p v-if="models.length === 0">{{ $t('models.empty') }}</p>
  <table v-else class="data-table">
    <thead>
      <tr>
        <th>{{ $t('models.columnName') }}</th>
        <th>{{ $t('models.columnTable') }}</th>
        <th class="model-list__count-column">{{ $t('models.columnItems') }}</th>
        <th class="model-list__count-column">{{ $t('models.columnColumns') }}</th>
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
        <td class="model-list__count model-list__count-column">{{ model.item_count }}</td>
        <td class="model-list__count model-list__count-column">{{ model.column_count }}</td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.model-list__count {
  text-align: right;
}

.model-list__count-column {
  width: 3rem;
}
</style>
