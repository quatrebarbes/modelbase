<?php

namespace Quatrebarbes\Modelbase\Support;

/**
 * Formate un nombre d'items pour l'affichage (EX-312) : nombre brut si
 * strictement inférieur à 10^3, sinon suffixe K/G/T. Une décimale au plus,
 * tronquée (jamais arrondie au-dessus) pour ne jamais afficher une valeur
 * qui semble appartenir au palier supérieur (ex. 999 999 → "999.9K", pas
 * "1000K").
 */
final class ApproximateCount
{
    public static function format(int $count): string
    {
        if ($count >= 1_000_000_000) {
            return self::withSuffix($count, 1_000_000_000, 'T');
        }

        if ($count >= 1_000_000) {
            return self::withSuffix($count, 1_000_000, 'G');
        }

        if ($count >= 1_000) {
            return self::withSuffix($count, 1_000, 'K');
        }

        return (string) $count;
    }

    private static function withSuffix(int $count, int $divisor, string $suffix): string
    {
        $value = floor(($count / $divisor) * 10) / 10;
        $formatted = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $formatted.$suffix;
    }
}
