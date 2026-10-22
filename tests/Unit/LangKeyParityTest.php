<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Tests\TestCase;

/**
 * EX-119 : une clé manquante doit retomber sur le français, jamais sur une
 * clé technique brute ou un texte vide — garanti ici par un invariant testé
 * (parité stricte des groupes et des clés fr/en) plutôt que par un mécanisme
 * de repli runtime à travers un espace de noms que l'app hôte ne maîtrise
 * pas.
 */
class LangKeyParityTest extends TestCase
{
    private const LANG_PATH = __DIR__.'/../../resources/lang';

    public function test_french_and_english_expose_the_same_translation_groups(): void
    {
        $this->assertSame($this->groupsFor('fr'), $this->groupsFor('en'));
    }

    public function test_french_and_english_translation_groups_expose_the_same_keys(): void
    {
        foreach ($this->groupsFor('fr') as $group) {
            // require (pas require_once) : Laravel a déjà chargé certains de
            // ces fichiers via son propre résolveur de traductions plus tôt
            // dans le même process PHPUnit — require_once y renverrait
            // `true` au lieu du tableau de traductions.
            $fr = require self::LANG_PATH."/fr/{$group}.php";
            $en = require self::LANG_PATH."/en/{$group}.php";

            $this->assertSame(
                $this->flattenKeys($fr),
                $this->flattenKeys($en),
                "Clés divergentes entre fr/{$group}.php et en/{$group}.php.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function groupsFor(string $locale): array
    {
        $groups = array_map(
            fn (string $path) => basename($path, '.php'),
            glob(self::LANG_PATH."/{$locale}/*.php") ?: [],
        );

        sort($groups);

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function flattenKeys(array $translations, string $prefix = ''): array
    {
        $keys = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                array_push($keys, ...$this->flattenKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        sort($keys);

        return $keys;
    }
}
