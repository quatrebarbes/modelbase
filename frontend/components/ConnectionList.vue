<script setup lang="ts">
type Connection = {
  name: string
  driver: string | null
  status: 'available' | 'unavailable'
  model_count: number | null
}

defineProps<{
  connections: Connection[]
}>()
</script>

<template>
  <ul class="connection-list">
    <li
      v-for="connection in connections"
      :key="connection.name"
      class="connection-list__item"
      :class="`connection-list__item--${connection.status}`"
    >
      <!-- EX-206/EX-207 : seule une connexion disponible navigue vers le module 3 -->
      <NuxtLink
        v-if="connection.status === 'available'"
        :to="`/connections/${connection.name}`"
      >
        <strong>{{ connection.name }}</strong>
        <span>{{ connection.driver }}</span>
        <span>{{ connection.model_count }} modèle(s)</span>
      </NuxtLink>
      <span v-else class="connection-list__unavailable">
        <strong>{{ connection.name }}</strong>
        <span>{{ connection.driver }}</span>
        <span>indisponible</span>
      </span>
    </li>
  </ul>
</template>

<style scoped>
.connection-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.connection-list__item a,
.connection-list__unavailable {
  display: flex;
  gap: 0.75rem;
  align-items: baseline;
}

.connection-list__item--unavailable {
  opacity: 0.5;
}

.connection-list__unavailable {
  cursor: not-allowed;
}
</style>
