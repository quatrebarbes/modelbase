<script setup lang="ts">
// EX-306/EX-308/EX-309 : diagramme des relations Eloquent déclarées par le
// modèle courant, limité à celui-ci et aux modèles qu'il relie directement
// (sans remonter leurs propres relations). EX-310 : le modèle courant est
// disposé au centre, les modèles liés sur un même niveau autour de lui, via
// un layout `concentric` Cytoscape.js (poids 2 pour le centre, 1 pour les
// modèles liés, largeur de niveau fixe à 1 : un seul anneau extérieur, quel
// que soit le sens réel de chaque relation, cf. limite SFD) — un
// `classDiagram` Mermaid (layout dagre) ne garantissait aucune position
// relative, cf. docs/roadmap.md Phase 9bis.
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
const canvas = ref<HTMLElement | null>(null)
const renderError = ref(false)
let cy: import('cytoscape').Core | null = null

const { data } = await useAsyncData(
  `relations-${props.connection}-${props.model}`,
  () => api(`/connections/${props.connection}/models/${props.model}/relations`)
)

const relations = computed<Relation[]>(() => data.value?.data ?? [])

function buildElements(items: Relation[]) {
  const nodes = [{ data: { id: props.model, label: props.model, center: true, unavailable: false } }]
  const declared = new Set([props.model])
  const edges: Array<{ data: Record<string, unknown> }> = []

  items.forEach((relation, index) => {
    if (!declared.has(relation.related_model)) {
      // Limite SFD : un modèle cible non navigable reste affiché (jamais
      // omis), avec une indication d'indisponibilité plutôt qu'un lien.
      nodes.push({
        data: {
          id: relation.related_model,
          label: relation.navigable
            ? relation.related_model
            : `${relation.related_model} (${t('relations.unavailableModel')})`,
          center: false,
          unavailable: !relation.navigable,
        },
      })
      declared.add(relation.related_model)
    }

    // EX-309 : nom et multiplicité de la relation, sans détail de colonnes ni
    // du type technique Eloquent (belongsTo, morphOne...) — multiplicité
    // portée par chaque extrémité du lien (notation UML), « 1 » côté modèle
    // courant (une instance interrogée à la fois) et « 1 » ou « * » côté
    // modèle lié selon `relation.multiplicity`.
    edges.push({
      data: {
        id: `edge-${index}`,
        source: props.model,
        target: relation.related_model,
        label: relation.name,
        sourceArity: '1',
        targetArity: relation.multiplicity === 'many' ? '*' : '1',
      },
    })
  })

  return [...nodes, ...edges]
}

async function render() {
  if (relations.value.length === 0 || !canvas.value) return

  const { default: cytoscape } = await import('cytoscape')
  const style = getComputedStyle(document.documentElement)
  const colorBg = style.getPropertyValue('--color-bg').trim()
  const colorBgMuted = style.getPropertyValue('--color-bg-muted').trim()
  const colorBorder = style.getPropertyValue('--color-border').trim()
  const colorText = style.getPropertyValue('--color-text').trim()
  const colorPrimary = style.getPropertyValue('--color-primary').trim()

  try {
    cy = cytoscape({
      container: canvas.value,
      elements: buildElements(relations.value),
      userZoomingEnabled: true,
      userPanningEnabled: true,
      boxSelectionEnabled: false,
      autoungrabify: true,
      style: [
        {
          selector: 'node',
          style: {
            label: 'data(label)',
            'text-wrap': 'wrap',
            'text-max-width': '90px',
            'text-valign': 'center',
            'text-halign': 'center',
            'background-color': colorBgMuted,
            'border-color': colorBorder,
            'border-width': 1,
            color: colorText,
            'font-size': 12,
            shape: 'round-rectangle',
            padding: '12px',
            width: 'label',
            height: 'label',
          },
        },
        {
          selector: 'node[?center]',
          style: {
            'background-color': colorPrimary,
            color: colorBg,
            'border-width': 0,
            'font-weight': 'bold',
          },
        },
        {
          selector: 'node[?unavailable]',
          style: {
            'border-style': 'dashed',
            'text-opacity': 0.7,
          },
        },
        {
          selector: 'edge',
          style: {
            width: 1.5,
            'line-color': colorBorder,
            // Pas de fléchage : EX-310 impose une position équivalente pour
            // un modèle lié quel que soit le sens réel de la relation
            // (source ou cible) — un lien directionnel suggérerait une
            // hiérarchie que le schéma ne représente pas.
            'target-arrow-shape': 'none',
            'source-arrow-shape': 'none',
            'curve-style': 'bezier',
            // Écarte davantage les liens parallèles (ex. Category -> Product
            // via `products` et `latestProduct`) que le défaut Cytoscape
            // (40) : sans quoi leurs étiquettes, positionnées au milieu de
            // courbes trop rapprochées, se chevauchent et deviennent
            // illisibles.
            'control-point-step-size': 90,
            label: 'data(label)',
            'font-size': 10,
            color: colorText,
            'text-background-color': colorBg,
            'text-background-opacity': 0.35,
            'text-background-padding': '2px',
            'text-background-shape': 'roundrectangle',
            // Multiplicités en bout de lien (notation UML) plutôt que
            // mêlées au nom/type de la relation, pour rester lisibles même
            // sur un schéma dense (limite SFD : pas de plafond sur le
            // nombre de modèles liés, EX-310).
            'source-label': 'data(sourceArity)',
            'target-label': 'data(targetArity)',
            'source-text-offset': 8,
            'target-text-offset': 8,
          },
        },
      ],
      layout: {
        name: 'concentric',
        // EX-310 : le modèle courant (poids 2) occupe seul le centre, tous
        // les modèles liés (poids 1) se retrouvent sur le même anneau
        // extérieur — `levelWidth` fixe à 1 empêche tout étagement entre eux.
        concentric: (node) => (node.data('center') ? 2 : 1),
        levelWidth: () => 1,
        equidistant: true,
        // Espacement généreux : le schéma porte désormais aussi les
        // multiplicités en bout de lien (ci-dessus), qui ont besoin de
        // place autour de chaque nœud pour rester lisibles.
        minNodeSpacing: 80,
      } as unknown as import('cytoscape').LayoutOptions,
    })

    cy.fit(undefined, 40)
  } catch {
    renderError.value = true
  }
}

onMounted(render)
onUnmounted(() => cy?.destroy())
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
          <div v-show="relations.length > 0 && !renderError" ref="canvas" class="relation-diagram__canvas" />
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
  width: 100%;
  height: min(70vh, 40rem);
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
