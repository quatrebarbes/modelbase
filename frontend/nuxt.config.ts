// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  css: ['~/assets/css/main.css'],

  // EX-104/EX-105 : le front consomme l'API du plug-in sous le préfixe
  // configuré côté Laravel (config/modelbase.php: route_prefix), suivi du
  // segment "api". Surchargeable via la variable d'env NUXT_PUBLIC_API_BASE
  // sans avoir à modifier le build (ex. si l'app hôte change le préfixe).
  runtimeConfig: {
    public: {
      apiBase: '/modelbase/api',
    },
  },

  // Proxifie les appels vers l'API Laravel de l'app hôte pour rester
  // same-origin côté navigateur (le serveur Nuxt tourne sur un port distinct
  // en dev) : évite toute configuration CORS et garde la session de l'app
  // hôte utilisable telle quelle (EX-101, guard session-based par défaut).
  // Cible surchargeable via MODELBASE_API_ORIGIN (ex. si l'app hôte n'écoute
  // pas sur localhost:8000).
  routeRules: {
    '/modelbase/api/**': {
      proxy: `${process.env.MODELBASE_API_ORIGIN ?? 'http://localhost:8000'}/modelbase/api/**`,
    },
  },
})
