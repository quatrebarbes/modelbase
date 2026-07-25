<script setup lang="ts">
// EX-207 : liste des connexions, point d'entrée du parcours (module 2).
const api = useApiClient()
const { t } = useI18n()
const { data } = await useAsyncData('connections', () => api('/connections'))

const connections = computed(() => data.value?.data ?? [])

useHead({ title: t('common.databases') })
</script>

<template>
  <main>
    <h1>{{ $t('common.databases') }}</h1>
    <ConnectionList :connections="connections" />
  </main>
</template>
