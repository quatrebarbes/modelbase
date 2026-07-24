export function useApiClient() {
  const config = useRuntimeConfig()

  // En SSR, ce $fetch tourne côté serveur Nuxt : sans reprendre le cookie de
  // session de la requête entrante, l'appel à l'API (authentifiée, EX-101)
  // partirait sans cookie et échouerait en 401 malgré un navigateur connecté.
  const headers = import.meta.server ? useRequestHeaders(['cookie']) : undefined

  return $fetch.create({
    baseURL: config.public.apiBase,
    headers,
  })
}
