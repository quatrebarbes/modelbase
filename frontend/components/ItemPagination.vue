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
    <span>{{ $t('items.page', { current: meta.current_page, last: meta.last_page, total: meta.total }) }}</span>
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
