// EX-119 : un texte non traduit dans la langue sélectionnée retombe en
// français plutôt que d'afficher une clé technique brute ou un texte vide.
export default defineI18nConfig(() => ({
  legacy: false,
  fallbackLocale: 'fr',
}))
