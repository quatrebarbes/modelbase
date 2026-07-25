<script setup lang="ts">
// EX-116 : sélecteur de langue, visible à tout moment depuis l'en-tête global
// (app.vue), plutôt que dupliqué sur chaque page.
const { locale, locales, setLocale } = useI18n()
</script>

<template>
  <div class="locale-switcher" :aria-label="$t('common.languageSelector')">
    <button
      v-for="entry in locales"
      :key="typeof entry === 'string' ? entry : entry.code"
      type="button"
      class="locale-switcher__option"
      :class="{ 'locale-switcher__option--active': (typeof entry === 'string' ? entry : entry.code) === locale }"
      :aria-pressed="(typeof entry === 'string' ? entry : entry.code) === locale"
      @click="setLocale(typeof entry === 'string' ? entry : entry.code)"
    >
      {{ typeof entry === 'string' ? entry : (entry.name ?? entry.code) }}
    </button>
  </div>
</template>

<style scoped>
.locale-switcher {
  display: flex;
  gap: 0.3rem;
}

.locale-switcher__option {
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-pill-bg);
  color: #333;
  font: inherit;
  font-size: 0.8rem;
  cursor: pointer;
}

.locale-switcher__option:hover {
  background: var(--color-pill-bg-hover);
}

.locale-switcher__option--active {
  border-color: transparent;
  background: var(--color-primary);
  color: #fff;
}
</style>
