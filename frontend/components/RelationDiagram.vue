<script setup lang="ts">
// EX-306/EX-307/EX-308/EX-309 : diagramme de classe Mermaid des relations
// Eloquent déclarées par le modèle courant, limité à celui-ci et aux modèles
// qu'il relie directement (sans remonter leurs propres relations).
type Relation = {
  name: string
  type: string
  multiplicity: 'one' | 'many'
  related_model: string
  related_connection: string
  related_table: string
  navigable: boolean
}

const props = defineProps<{
  connection: string
  model: string
}>()

const emit = defineEmits<{ close: [] }>()

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', handleKeydown))
onUnmounted(() => window.removeEventListener('keydown', handleKeydown))

const api = useApiClient()
const { t } = useI18n()
const svg = ref('')
const renderError = ref(false)

const { data } = await useAsyncData(
  `relations-${props.connection}-${props.model}`,
  () => api(`/connections/${props.connection}/models/${props.model}/relations`)
)

const relations = computed<Relation[]>(() => data.value?.data ?? [])

function buildDefinition(items: Relation[]): string {
  const lines = ['classDiagram', `class ${props.model}`]
  const declared = new Set([props.model])

  for (const relation of items) {
    if (!declared.has(relation.related_model)) {
      // Limite SFD : un modèle cible non navigable reste affiché (jamais
      // omis), avec une indication d'indisponibilité plutôt qu'un lien.
      lines.push(relation.navigable
        ? `class ${relation.related_model}`
        : `class ${relation.related_model}["${relation.related_model} (${t('relations.unavailableModel')})"]`)
      declared.add(relation.related_model)
    }

    const arity = relation.multiplicity === 'many' ? '*' : '1'
    // EX-309 : type et multiplicité de la relation, sans détail de colonnes.
    lines.push(`${props.model} "1" --> "${arity}" ${relation.related_model} : ${relation.name} (${relation.type})`)
  }

  return lines.join('\n')
}

async function render() {
  if (relations.value.length === 0) return

  const { default: mermaid } = await import('mermaid')
  const dark = window.matchMedia('(prefers-color-scheme: dark)').matches
  // EX-107/EX-110 : le fond des boîtes de classe reprend la palette du
  // plug-in (--color-bg-muted, déjà utilisé pour un fond discret ailleurs)
  // plutôt que la couleur générique des thèmes Mermaid (`mainBkg`, une
  // valeur codée en dur par thème — #1f2020 en sombre, #ECECFF en clair —
  // indépendante de `primaryColor` malgré la coïncidence de valeur en
  // sombre ; c'est bien `mainBkg` que le rendu des boîtes de classe
  // consomme directement, d'où la nécessité de la surcharger elle aussi).
  const bgMuted = getComputedStyle(document.documentElement).getPropertyValue('--color-bg-muted').trim()

  mermaid.initialize({
    startOnLoad: false,
    theme: dark ? 'dark' : 'default',
    themeVariables: { primaryColor: bgMuted, mainBkg: bgMuted },
  })

  try {
    const { svg: rendered } = await mermaid.render(`relation-diagram-${props.model}`, buildDefinition(relations.value))
    svg.value = rendered
  } catch {
    renderError.value = true
  }
}

onMounted(render)
</script>

<template>
  <Teleport to="body">
    <div class="relation-panel">
      <Transition name="relation-panel-backdrop">
        <div class="relation-panel__backdrop" @click="emit('close')" />
      </Transition>
      <Transition name="relation-panel-content">
        <aside class="relation-panel__content" role="dialog" aria-modal="true">
          <div class="relation-diagram__header">
            <h2>{{ $t('relations.diagramTitle', { model }) }}</h2>
            <button type="button" class="btn" @click="emit('close')">{{ $t('relations.close') }}</button>
          </div>

          <!-- Limite SFD : message explicite plutôt qu'un diagramme vide -->
          <p v-if="relations.length === 0">{{ $t('relations.diagramEmpty') }}</p>
          <p v-else-if="renderError">{{ $t('relations.diagramError') }}</p>
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div v-else class="relation-diagram__canvas" v-html="svg" />
        </aside>
      </Transition>
    </div>
  </Teleport>
</template>

<style scoped>
.relation-panel {
  position: fixed;
  inset: 0;
  z-index: 120;
}

.relation-panel__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
}

.relation-panel__content {
  position: absolute;
  top: 1.25rem;
  right: 1.25rem;
  bottom: 1.25rem;
  width: min(90vw, 45rem);
  overflow-y: auto;
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: -4px 0 16px rgba(0, 0, 0, 0.15);
  padding: 1.25rem;
}

.relation-diagram__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.relation-diagram__canvas {
  overflow-x: auto;
}

.relation-panel-backdrop-enter-active,
.relation-panel-backdrop-leave-active {
  transition: opacity 0.2s ease;
}

.relation-panel-backdrop-enter-from,
.relation-panel-backdrop-leave-to {
  opacity: 0;
}

.relation-panel-content-enter-active,
.relation-panel-content-leave-active {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.relation-panel-content-enter-from,
.relation-panel-content-leave-to {
  transform: translateX(1rem);
  opacity: 0;
}
</style>
