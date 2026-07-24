// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  // EX-104/EX-105 : le front consomme l'API du plug-in sous le préfixe
  // configuré côté Laravel (config/modelbase.php: route_prefix), suivi du
  // segment "api". Surchargeable via la variable d'env NUXT_PUBLIC_API_BASE
  // sans avoir à modifier le build (ex. si l'app hôte change le préfixe).
  runtimeConfig: {
    public: {
      apiBase: '/modelbase/api',
    },
  },
})
