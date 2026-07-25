// EX-117 : le choix de langue manuel est mémorisé (localStorage, pas de
// cookie — cf. roadmap Phase 8) et réappliqué aux visites suivantes. Relu ici
// avant le montage de l'app (plugin par défaut, exécuté après le plugin
// interne 'pre' du module i18n mais avant le rendu) pour éviter un flash
// FR→EN au premier affichage.
const STORAGE_KEY = 'modelbase-locale'

export default defineNuxtPlugin(async (nuxtApp) => {
  // `useI18n()` s'appuie sur `getCurrentInstance()` (Vue), indisponible ici :
  // un plugin s'exécute avant qu'un composant ne soit monté. `$i18n` expose
  // le même composer sans dépendre d'une instance de composant.
  const i18n = nuxtApp.$i18n

  const stored = localStorage.getItem(STORAGE_KEY)
  const available = i18n.locales.value.map((entry) => (typeof entry === 'string' ? entry : entry.code))

  if (stored && available.includes(stored) && stored !== i18n.locale.value) {
    await i18n.setLocale(stored)
  }

  watch(i18n.locale, (value) => {
    localStorage.setItem(STORAGE_KEY, value)
  })
})
