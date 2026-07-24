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
  <table class="data-table">
    <thead>
      <tr>
        <th>Nom</th>
        <th>Driver</th>
        <th>Statut</th>
        <th>Modèles</th>
      </tr>
    </thead>
    <tbody>
      <!-- EX-206/EX-207 : seule une connexion disponible navigue vers le module 3 -->
      <tr
        v-for="connection in connections"
        :key="connection.name"
        :class="{ 'connection-list__row--unavailable': connection.status !== 'available' }"
        @click="connection.status === 'available' && navigateTo(`/connections/${connection.name}`)"
      >
        <td>{{ connection.name }}</td>
        <td>{{ connection.driver }}</td>
        <td>{{ connection.status === 'available' ? 'disponible' : 'indisponible' }}</td>
        <td>{{ connection.status === 'available' ? connection.model_count : '—' }}</td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.connection-list__row--unavailable {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
