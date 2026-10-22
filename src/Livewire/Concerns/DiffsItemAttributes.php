<?php

namespace Quatrebarbes\Modelbase\Livewire\Concerns;

/**
 * EX-465 à EX-468 : compare une valeur de formulaire à sa valeur initiale
 * pour ne soumettre que les colonnes réellement modifiées. Comparaison
 * structurelle (par valeur, insensible à l'ordre des clés) pour un tableau
 * (colonne JSON, EX-467), par coercition texte pour un scalaire (« 5 »
 * ressaisi sur un `5` numérique n'est pas un changement).
 */
trait DiffsItemAttributes
{
    protected function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return $this->normalizeForCompare($a) === $this->normalizeForCompare($b);
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    private function normalizeForCompare(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn ($v) => $this->normalizeForCompare($v), $value);

        if (array_is_list($normalized)) {
            return $normalized;
        }

        ksort($normalized);

        return $normalized;
    }
}
