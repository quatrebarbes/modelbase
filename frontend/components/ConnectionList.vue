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

function goToConnection(connection: Connection) {
  if (connection.status === 'available') {
    navigateTo(`/connections/${connection.name}`)
  }
}
</script>

<template>
  <table class="data-table">
    <thead>
      <tr>
        <th>{{ $t('connections.columnName') }}</th>
        <th>{{ $t('connections.columnDriver') }}</th>
        <th>{{ $t('connections.columnStatus') }}</th>
        <th>{{ $t('connections.columnModels') }}</th>
      </tr>
    </thead>
    <tbody>
      <!-- EX-206/EX-207 : seule une connexion disponible navigue vers le module 3 -->
      <tr
        v-for="connection in connections"
        :key="connection.name"
        :class="{ 'connection-list__row--unavailable': connection.status !== 'available' }"
        :tabindex="connection.status === 'available' ? 0 : undefined"
        @click="goToConnection(connection)"
        @keydown.enter="goToConnection(connection)"
      >
        <td>{{ connection.name }}</td>
        <td>{{ connection.driver }}</td>
        <td>{{ connection.status === 'available' ? $t('connections.statusAvailable') : $t('connections.statusUnavailable') }}</td>
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

/* EX-112 ne s'applique qu'aux lignes navigables : une connexion
   indisponible n'est pas une action interactive */
.connection-list__row--unavailable:hover {
  outline: none;
}
</style>
