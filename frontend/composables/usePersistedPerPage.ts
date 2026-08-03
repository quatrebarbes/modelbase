const STORAGE_PREFIX = 'modelbase:per_page:'

/**
 * EX-455/EX-456 : nombre de lignes par page mémorisé par contexte de listing
 * (un modèle donné en listing standard, une relation donnée en tableau
 * d'objets liés — jamais une valeur unique partagée par l'utilisateur), donc
 * une clé `localStorage` distincte par `context` plutôt qu'une clé globale.
 */
export function usePersistedPerPage(context: string, fallback: number) {
  const key = STORAGE_PREFIX + context
  const stored = import.meta.client ? Number(localStorage.getItem(key)) : NaN
  const perPage = ref(Number.isFinite(stored) && stored > 0 ? stored : fallback)

  watch(perPage, (value) => {
    if (import.meta.client) localStorage.setItem(key, String(value))
  })

  return perPage
}
