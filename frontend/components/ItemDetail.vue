<script setup lang="ts">
type ForeignKey = {
  table: string
  model: string | null
  navigable: boolean
}

type ItemValue = {
  column: string
  type: 'string' | 'number' | 'boolean' | 'date' | 'json' | 'foreign_key'
  value: unknown
  is_null: boolean
  foreign_key?: ForeignKey
  // EX-450 : texte long (colonne SQL text/mediumtext/longtext), par
  // opposition à varchar/char — reste de type 'string' (EX-407), seul le
  // rendu en grille en tient compte (pleine largeur, fin de grille).
  long?: boolean
}

const props = defineProps<{
  connection: string
  values: ItemValue[]
}>()

function isWide(entry: ItemValue): boolean {
  return entry.type === 'json' || entry.long === true
}

// EX-451 (exception) : les champs à rendu volumineux (JSON, texte long —
// pleine largeur de la grille, EX-450) sont replacés en fin de grille plutôt
// que laissés à leur position naturelle — un champ pleine largeur au milieu
// de la grille aurait forcé les champs suivants à repartir en début de
// rangée, quelle que soit leur affinité avec ceux déjà en place sur cette
// même rangée. L'ordre relatif des champs non concernés entre eux, lui, reste
// celui exposé par le modèle ; de même entre eux pour les champs replacés.
const orderedValues = computed(() => [
  ...props.values.filter((entry) => !isWide(entry)),
  ...props.values.filter((entry) => isWide(entry)),
])
</script>

<template>
  <dl class="item-detail field-grid">
    <div
      v-for="entry in orderedValues"
      :key="entry.column"
      class="field-grid__field"
      :class="{ 'field-grid__field--wide': isWide(entry) }"
    >
      <dt class="field-grid__label">{{ entry.column }}</dt>
      <dd>
        <!-- EX-409 : rendu distinct d'une valeur nulle vs une chaîne vide -->
        <span v-if="entry.is_null" class="item-detail__null">{{ $t('itemDetail.null') }}</span>

        <!-- EX-408/EX-410 : lien vers l'item référencé, ou indicateur de FK cassée -->
        <template v-else-if="entry.type === 'foreign_key'">
          <NuxtLink
            v-if="entry.foreign_key?.navigable"
            :to="`/connections/${connection}/models/${entry.foreign_key.model}/items/${entry.value}`"
          >
            {{ entry.value }} ({{ entry.foreign_key.model }})
          </NuxtLink>
          <span v-else class="item-detail__broken-fk">
            {{ entry.value }} — {{ $t('itemDetail.brokenForeignKey') }}
          </span>
        </template>

        <!-- EX-407 : rendu adapté au type de la colonne -->
        <pre v-else-if="entry.type === 'json'">{{ JSON.stringify(entry.value, null, 2) }}</pre>
        <span v-else-if="entry.type === 'boolean'">{{ entry.value ? $t('itemDetail.yes') : $t('itemDetail.no') }}</span>
        <span v-else-if="entry.value === ''" class="item-detail__empty-string">{{ $t('itemDetail.emptyString') }}</span>
        <span v-else>{{ entry.value }}</span>
      </dd>
    </div>
  </dl>
</template>

<style scoped>
.item-detail dd {
  margin: 0;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  min-width: 0;
  overflow-wrap: anywhere;
}

.item-detail dd pre {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  font: inherit;
}

.item-detail__null,
.item-detail__empty-string {
  font-style: italic;
  color: var(--color-text-muted);
}

.item-detail__broken-fk {
  color: var(--color-error-text);
}

.item-detail dd a {
  color: var(--color-text-muted);
}

/* EX-112 : un lien adopte la couleur de survol */
.item-detail dd a:hover {
  color: var(--color-hover-text);
}
</style>
