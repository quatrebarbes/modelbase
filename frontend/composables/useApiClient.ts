function xsrfTokenFromCookieHeader(cookieHeader: string | undefined | null): string | null {
  if (!cookieHeader) return null

  const match = cookieHeader.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

  return match ? decodeURIComponent(match[1]) : null
}

export function useApiClient() {
  const config = useRuntimeConfig()

  // En SSR, ce $fetch tourne côté serveur Nuxt : sans reprendre le cookie de
  // session de la requête entrante, l'appel à l'API (authentifiée, EX-101)
  // partirait sans cookie et échouerait en 401 malgré un navigateur connecté.
  const headers = import.meta.server ? useRequestHeaders(['cookie']) : undefined

  return $fetch.create({
    baseURL: config.public.apiBase,
    headers,
    onRequest({ options }) {
      const method = (options.method ?? 'GET').toUpperCase()

      if (method === 'GET' || method === 'HEAD') return

      // EX-412/EX-413 : le groupe de middleware "web" des routes du plug-in
      // (cf. routes/api.php) protège les mutations par CSRF ; contrairement à
      // axios, $fetch ne relit pas automatiquement le cookie XSRF-TOKEN pour
      // le renvoyer en en-tête — à faire explicitement ici.
      const token = import.meta.client
        ? xsrfTokenFromCookieHeader(document.cookie)
        : xsrfTokenFromCookieHeader(headers?.cookie)

      if (token) {
        options.headers = new Headers(options.headers)
        options.headers.set('X-XSRF-TOKEN', token)
      }
    },
  })
}
