<script setup lang="ts">
type Meta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

defineProps<{ meta: Meta }>()

// EX-403 : navigation entre les pages du listing.
const page = defineModel<number>('page', { required: true })
</script>

<template>
  <div class="item-pagination">
    <button type="button" class="btn" :disabled="page <= 1" @click="page--">&lt;</button>
    <span>Page {{ meta.current_page }} / {{ meta.last_page }} ({{ meta.total }} item(s))</span>
    <button type="button" class="btn" :disabled="page >= meta.last_page" @click="page++">&gt;</button>
  </div>
</template>

<style scoped>
.item-pagination {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  margin-top: 0.75rem;
}
</style>
