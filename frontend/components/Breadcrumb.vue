<script setup lang="ts">
type Crumb = {
  label: string
  to?: string
}

defineProps<{
  items: Crumb[]
}>()
</script>

<template>
  <nav class="breadcrumb" :aria-label="$t('common.breadcrumbLabel')">
    <template v-for="(item, index) in items" :key="index">
      <NuxtLink v-if="item.to" :to="item.to" class="breadcrumb__link">{{ item.label }}</NuxtLink>
      <span v-else class="breadcrumb__current" aria-current="page">{{ item.label }}</span>
      <span v-if="index < items.length - 1" class="breadcrumb__separator">›</span>
    </template>
  </nav>
</template>

<style scoped>
.breadcrumb {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.5rem;
  font-size: 0.9rem;
  color: var(--color-text-muted);
}

.breadcrumb__link {
  color: var(--color-text-muted);
  text-decoration: none;
}

.breadcrumb__link:hover {
  text-decoration: underline;
}

.breadcrumb__current {
  font-weight: 600;
  color: #333;
}

.breadcrumb__separator {
  color: var(--color-border-focus);
}
</style>
