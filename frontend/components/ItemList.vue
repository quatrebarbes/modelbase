<script setup lang="ts">
type ItemRow = Record<string, unknown>

const props = defineProps<{
  connection: string
  model: string
  items: ItemRow[]
}>()

const columns = computed(() => (props.items.length ? Object.keys(props.items[0]) : []))
</script>

<template>
  <!-- EX-404 : message dédié, pas d'erreur, si le modèle ne contient aucun item -->
  <p v-if="items.length === 0">Aucun item disponible pour ce modèle.</p>
  <table v-else class="data-table">
    <thead>
      <tr>
        <th v-for="column in columns" :key="column">{{ column }}</th>
      </tr>
    </thead>
    <tbody>
      <!-- EX-405 : navigue vers la fiche détail de l'item sélectionné -->
      <tr
        v-for="item in items"
        :key="String(item.id)"
        @click="navigateTo(`/connections/${connection}/models/${model}/items/${item.id}`)"
      >
        <td v-for="column in columns" :key="column">{{ item[column] }}</td>
      </tr>
    </tbody>
  </table>
</template>
