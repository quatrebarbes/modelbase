<template>
  <span class="spinner" role="status" :aria-label="$t('common.loading')" />
</template>

<style scoped>
.spinner {
  display: inline-block;
  box-sizing: border-box;
  width: 1rem;
  height: 1rem;
  border: 2px solid var(--color-border);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spinner-rotate 0.6s linear infinite;
  vertical-align: middle;
}

@keyframes spinner-rotate {
  to {
    transform: rotate(360deg);
  }
}
</style>
