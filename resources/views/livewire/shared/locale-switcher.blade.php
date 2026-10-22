<div role="group" aria-label="{{ __('modelbase::common.languageSelector') }}">
    @foreach ($locales as $locale)
        <button
            type="button"
            wire:click="switchTo('{{ $locale }}')"
            class="btn{{ $locale === $current ? ' btn--primary' : '' }}"
            @disabled($locale === $current)
        >
            {{ __('modelbase::locale.'.$locale) }}
        </button>
    @endforeach
</div>
