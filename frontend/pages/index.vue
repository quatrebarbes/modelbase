<script setup lang="ts">
// EX-207 : liste des connexions, point d'entrée du parcours (module 2).
const api = useApiClient()
const { data } = await useAsyncData('connections', () => api('/connections'))

const connections = computed(() => data.value?.data ?? [])

useHead({ title: 'Bases de données' })
</script>

<template>
  <main>
    <h1>Bases de données</h1>
    <ConnectionList :connections="connections" />
  </main>
</template>
