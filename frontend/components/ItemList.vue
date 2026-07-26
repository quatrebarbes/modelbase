<script setup lang="ts">
type ItemRow = Record<string, unknown>

const props = withDefaults(defineProps<{
  connection: string
  model: string
  items: ItemRow[]
  primaryKey?: string
}>(), {
  primaryKey: 'id',
})

const columns = computed(() => (props.items.length ? Object.keys(props.items[0]) : []))

function keyOf(item: ItemRow) {
  return item[props.primaryKey]
}

function goToItem(item: ItemRow) {
  navigateTo(`/connections/${props.connection}/models/${props.model}/items/${keyOf(item)}`)
}
</script>

<template>
  <!-- EX-404 : message dédié, pas d'erreur, si le modèle ne contient aucun item -->
  <p v-if="items.length === 0">{{ $t('items.empty') }}</p>
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
        :key="String(keyOf(item))"
        tabindex="0"
        @click="goToItem(item)"
        @keydown.enter="goToItem(item)"
      >
        <td v-for="column in columns" :key="column">{{ item[column] }}</td>
      </tr>
    </tbody>
  </table>
</template>
