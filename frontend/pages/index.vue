<script setup lang="ts">
// Squelette Phase 0 : sera remplacé par le listing des connexions (module 2).
const api = useApiClient()
const { data } = await useAsyncData('ping', () => api('/ping'))
</script>

<template>
  <main>
    <h1>Modelbase</h1>
    <ApiStatus :status="data?.status" />
  </main>
</template>
