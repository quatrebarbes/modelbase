<script setup lang="ts">
// EX-207 : liste des connexions, point d'entrée du parcours (module 2).
type ConnectionStatus = 'checking' | 'available' | 'unavailable'
type Connection = {
  name: string
  driver: string | null
  status: ConnectionStatus
  model_count: number | null
}

const STATUS_TIMEOUT_MS = 10_000

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
  const controller = new AbortController()
  // EX-214 : plafond de 10 secondes, tous drivers confondus, en complément
  // du réglage serveur `modelbase.connection_timeout` (mysql/mariadb/sqlsrv
  // uniquement).
  const timeout = setTimeout(() => controller.abort(), STATUS_TIMEOUT_MS)

  api(`/connections/${connection.name}/status`, { signal: controller.signal })
    .then((response: { status: 'available' | 'unavailable'; model_count: number | null }) => {
      applyStatus(connection, response.status, response.model_count)
    })
    .catch(() => {
      applyStatus(connection, 'unavailable', null)
    })
    .finally(() => clearTimeout(timeout))
}

function applyStatus(connection: Connection, status: 'available' | 'unavailable', modelCount: number | null) {
  // EX-214 (limite) : une réponse arrivant après que le délai a déjà fait
  // basculer la ligne à "unavailable" est ignorée pour l'affichage en cours.
  if (connection.status !== 'checking') return

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
