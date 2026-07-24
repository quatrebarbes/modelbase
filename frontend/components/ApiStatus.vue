<script setup lang="ts">
defineProps<{
  status?: string
}>()
</script>

<template>
  <p>API : {{ status ?? 'indisponible' }}</p>
</template>
