<script setup lang="ts">
// EX-114 : reflète la langue active sur l'élément racine (accessibilité) —
// pas de `useLocaleHead()` (liens hreflang/SEO hors périmètre d'un outil
// d'administration interne, non indexé, et qui exigerait un `baseUrl` fixe).
const { locale } = useI18n()
useHead({ htmlAttrs: { lang: locale } })
</script>

<template>
  <div>
    <NuxtLoadingIndicator />
    <!-- EX-116 : en-tête global minimal, seul emplacement de navigation persistant du plug-in -->
    <header class="app-header">
      <LocaleSwitcher />
    </header>
    <NuxtPage />
    <Toast />
  </div>
</template>

<style scoped>
.app-header {
  position: fixed;
  top: 0;
  right: 0;
  z-index: 50;
  padding: 0.6rem 1.25rem;
}
</style>
