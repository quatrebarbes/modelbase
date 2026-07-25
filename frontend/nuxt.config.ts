// https://nuxt.com/docs/api/configuration/nuxt-config

// EX-106 : en dev/test (docker-compose, cf. Phase 0/3), le front tourne dans
// son propre conteneur SSR derrière le proxy Nitro (routeRules ci-dessous).
// Pour la publication du plug-in (vendor:publish, cf. ModelbaseServiceProvider
// et `npm run build:package`), on bascule sur un build SPA statique
// (ssr: false, servi comme un simple fichier index.html par le plug-in, cf.
// routes/web.php + SpaController) — un seul et même nuxt.config, sans
// dupliquer la configuration entre les deux modes.
const isPackageBuild = process.env.MODELBASE_PACKAGE_BUILD === 'true'

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  ssr: !isPackageBuild,
  modules: ['@nuxtjs/i18n'],

  app: {
    head: {
      titleTemplate: '%s — Modelbase',
    },
    // EX-105 : servi par le plug-in sous {route_prefix}/app (routes/web.php).
    // Sans objet en dev/test (front à la racine de son propre conteneur).
    baseURL: isPackageBuild ? (process.env.NUXT_APP_BASE_URL ?? '/modelbase/app/') : '/',
  },

  css: ['~/assets/css/main.css'],

  // EX-114 à EX-119 : enveloppe de l'IHM du plug-in traduite FR/EN (Phase 8).
  // Ne couvre jamais les données métier (noms de modèles/colonnes, valeurs
  // d'items, messages bruts de `DatabaseErrorTranslator`, EX-118).
  i18n: {
    locales: [
      { code: 'fr', name: 'Français', file: 'fr.json' },
      { code: 'en', name: 'English', file: 'en.json' },
    ],
    langDir: 'locales',
    lazy: true,
    // EX-115 : le français s'affiche au premier accès, indépendamment de la
    // langue du navigateur — pas de redirection/détection automatique.
    defaultLocale: 'fr',
    strategy: 'no_prefix',
    detectBrowserLanguage: false,
  },

  // EX-104/EX-105 : le front consomme l'API du plug-in sous le préfixe
  // configuré côté Laravel (config/modelbase.php: route_prefix), suivi du
  // segment "api". Surchargeable via la variable d'env NUXT_PUBLIC_API_BASE
  // sans avoir à modifier le build (ex. si l'app hôte change le préfixe).
  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE ?? '/modelbase/api',
    },
  },

  // Proxifie les appels vers l'API Laravel de l'app hôte pour rester
  // same-origin côté navigateur (le serveur Nuxt tourne sur un port distinct
  // en dev) : évite toute configuration CORS et garde la session de l'app
  // hôte utilisable telle quelle (EX-101, guard session-based par défaut).
  // Cible surchargeable via MODELBASE_API_ORIGIN (ex. si l'app hôte n'écoute
  // pas sur localhost:8000). Sans objet pour le build SPA statique (EX-106) :
  // servi par l'app hôte elle-même, same-origin par construction.
  routeRules: isPackageBuild ? {} : {
    '/modelbase/api/**': {
      proxy: `${process.env.MODELBASE_API_ORIGIN ?? 'http://localhost:8000'}/modelbase/api/**`,
    },
  },
})
