<script setup lang="ts">
type Connection = {
  name: string
  driver: string | null
  status: 'checking' | 'available' | 'unavailable'
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
        <th class="connection-list__status-column">{{ $t('connections.columnStatus') }}</th>
        <th class="connection-list__count-column">{{ $t('connections.columnModels') }}</th>
      </tr>
    </thead>
    <tbody>
      <!-- EX-206/EX-207 : seule une connexion disponible navigue vers le module 3 —
           tant que le statut n'est pas résolu (checking), la ligne reste désactivée
           comme une connexion indisponible -->
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
        <!-- EX-212 : indicateur de chargement dédié, à la place du statut ET
             du nombre de modèles, tant que la réponse individuelle n'est pas
             arrivée -->
        <template v-if="connection.status === 'checking'">
            <td v-if="connection.status === 'checking'" class="connection-list__status-column">
              <span class="connection-list__checking">
                <Spinner /> {{ $t('connections.statusChecking') }}
              </span>
            </td>
			<td class="connection-list__count-column" />
		</template>
        <template v-else>
          <td class="connection-list__status-column">{{ connection.status === 'available' ? $t('connections.statusAvailable') : $t('connections.statusUnavailable') }}</td>
          <td class="connection-list__count connection-list__count-column">{{ connection.status === 'available' ? connection.model_count : '—' }}</td>
        </template>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.connection-list__row--unavailable {
  opacity: 0.5;
  cursor: not-allowed;
}

/* EX-112 ne s'applique qu'aux lignes navigables : une connexion non
   disponible (indisponible ou en cours de vérification) n'est pas une
   action interactive */
.connection-list__row--unavailable:hover {
  outline: none;
}

.connection-list__checking {
  display: inline-flex;
  align-items: center;
  vertical-align: top;
  gap: 0.5rem;
  color: var(--color-text-muted);
}

.connection-list__count {
  text-align: right;
}

.connection-list__count-column {
  width: 3rem;
}

/* EX-212 : largeur figée, calée sur le libellé le plus long (spinner +
   "Vérification…" en français), pour que la colonne ne se redimensionne
   pas quand le statut se résout */
.connection-list__status-column {
  width: 10rem;
}
</style>
