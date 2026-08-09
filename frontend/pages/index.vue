<script setup lang="ts">
// EX-207 : liste des connexions, point d'entrée du parcours (module 2).
type ConnectionStatus = 'checking' | 'available' | 'unavailable'
type Connection = {
  name: string
  driver: string | null
  status: ConnectionStatus
  model_count: number | null
}

const api = useApiClient()
const { t } = useI18n()
// EX-209 : le listing brut ne renvoie plus que name/driver, sans attendre la
// résolution du statut ni le comptage de modèles.
const { data, pending } = await useAsyncData('connections', () => api('/connections'))

// EX-211/EX-213 : chaque connexion démarre au statut "checking" ; les mises
// à jour ultérieures mutent ces mêmes entrées en place, sans jamais
// reconstruire ni réordonner ce tableau.
const connections = ref<Connection[]>(
  (data.value?.data ?? []).map((connection: { name: string; driver: string | null }) => ({
    ...connection,
    status: 'checking',
    model_count: null,
  })),
)

// EX-210 : un appel de statut par connexion, tous déclenchés indépendamment
// les uns des autres, uniquement côté client (onMounted ne s'exécute pas en
// SSR) — le chargement progressif n'a de sens qu'une fois la page affichée.
onMounted(() => {
  connections.value.forEach(checkConnectionStatus)
})

function checkConnectionStatus(connection: Connection) {
  api(`/connections/${connection.name}/status`)
    .then((response: { status: 'available' | 'unavailable'; model_count: number | null }) => {
      applyStatus(connection, response.status, response.model_count)
    })
    .catch(() => {
      applyStatus(connection, 'unavailable', null)
    })
}

function applyStatus(connection: Connection, status: 'available' | 'unavailable', modelCount: number | null) {
  connection.status = status
  connection.model_count = modelCount
}

useHead({ title: t('common.databases') })
</script>

<template>
  <main>
    <div class="toolbar">
      <h1>{{ $t('common.databases') }}</h1>
      <Spinner v-if="pending" />
    </div>
    <ConnectionList :connections="connections" />
  </main>
</template>
