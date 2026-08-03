<script setup lang="ts">
type Meta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const props = defineProps<{ meta: Meta }>()

// EX-403 : navigation entre les pages du listing.
const page = defineModel<number>('page', { required: true })
// EX-452 : nombre de lignes par page, choisi parmi un ensemble prédéfini.
const perPage = defineModel<number>('perPage', { required: true })

// EX-452 : ensemble de valeurs prédéfinies non figé par la SFD (cf. <limite> EX-452).
const PER_PAGE_OPTIONS = [10, 25, 50, 100]

// EX-453 : accès direct à n'importe quelle page — un seul champ éditable
// affichant la page courante, plutôt que le texte "Page X / Y" et un champ de
// saisie séparé qui répétaient tous deux le même numéro de page.
const pageInput = ref(String(props.meta.current_page))

watch(() => props.meta.current_page, (value) => { pageInput.value = String(value) })

function goToPage() {
  const requested = Number(pageInput.value)
  // EX-454 : hors bornes -> repli sur la première/dernière page, sans erreur
  // (même repli appliqué côté API, cf. ItemRepository::paginate()).
  const clamped = Number.isFinite(requested)
    ? Math.min(Math.max(Math.trunc(requested), 1), props.meta.last_page)
    : props.meta.current_page

  pageInput.value = String(clamped)
  page.value = clamped
}
</script>

<template>
  <div class="item-pagination">
    <div class="item-pagination__nav">
      <button
        type="button"
        class="btn item-pagination__step"
        :disabled="page <= 1"
        :aria-label="$t('items.previousPage')"
        @click="page--"
      >
        ‹
      </button>
      <span class="item-pagination__goto">
        {{ $t('items.pageLabel') }}
        <input
          v-model="pageInput"
          type="number"
          min="1"
          :max="meta.last_page"
          :aria-label="$t('items.goToPage')"
          class="item-pagination__control item-pagination__goto-input"
          @change="goToPage"
          @keydown.enter="goToPage"
        />
        {{ $t('items.pageOf', { last: meta.last_page, total: meta.total }) }}
      </span>
      <button
        type="button"
        class="btn item-pagination__step"
        :disabled="page >= meta.last_page"
        :aria-label="$t('items.nextPage')"
        @click="page++"
      >
        ›
      </button>
    </div>
    <label class="item-pagination__per-page">
      {{ $t('items.perPage') }}
      <select v-model.number="perPage" class="item-pagination__control item-pagination__per-page-select">
        <option v-for="option in PER_PAGE_OPTIONS" :key="option" :value="option">{{ option }}</option>
      </select>
    </label>
  </div>
</template>

<style scoped>
.item-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem 1.5rem;
  margin-top: 0.75rem;
}

.item-pagination__nav {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.item-pagination__step {
  padding: 0.5rem 0.9rem;
  line-height: 1;
}

.item-pagination__goto {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  white-space: nowrap;
}

/* Style commun, compact, aux deux contrôles (EX-452/EX-453) — le <select> ne
   reprend délibérément pas la classe .btn, plus haute (padding + rendu natif
   d'un select) que ce champ, ce qui dénivelait le groupe "lignes par page"
   par rapport au groupe navigation à sa gauche. */
.item-pagination__control {
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-bg);
  color: var(--color-text);
  font: inherit;
  transition: border-color 0.15s ease;
}

.item-pagination__control:hover {
  border-color: var(--color-hover);
}

.item-pagination__control:focus {
  outline: none;
  border-color: var(--color-border-focus);
}

.item-pagination__goto-input {
  width: 3.5rem;
  text-align: center;
}

.item-pagination__per-page-select {
  cursor: pointer;
}

.item-pagination__per-page {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
</style>
