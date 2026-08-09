<?php

namespace Quatrebarbes\Modelbase\Support;

/**
 * Assemble le listing des connexions déclarées dans `config/database.php`
 * (EX-201), sans les connexions exclues (`modelbase.excluded_connections`).
 * Aucune information sensible (hôte, port, identifiants) n'est exposée
 * (EX-203). Statut et comptage de modèles (EX-202/EX-205) sont résolus à part
 * par `status()`, connexion par connexion (EX-209/EX-210), pour ne pas
 * pénaliser l'affichage du listing par une connexion lente ou injoignable.
 */
class ConnectionRepository
{
    public function __construct(
        private ConnectionAvailability $availability,
        private EloquentModelFinder $models,
    ) {
    }

    /**
     * EX-209/EX-215 : nom et driver uniquement, sans E/S (pas de résolution
     * de statut ni de comptage de modèles), triés par nom (insensible à la
     * casse — tri PHP simple, sans dépendance à `ext-intl`, les noms de
     * connexion étant des identifiants techniques plutôt que du texte
     * utilisateur nécessitant un tri sensible aux accents).
     *
     * @return array<int, array{name: string, driver: string|null}>
     */
    public function all(): array
    {
        $excluded = config('modelbase.excluded_connections', []);

        return collect(config('database.connections', []))
            ->reject(fn ($config, string $name) => in_array($name, $excluded, true))
            ->map(fn ($config, string $name) => [
                'name' => $name,
                'driver' => $config['driver'] ?? null,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * EX-202/EX-205/EX-208 : statut et comptage de modèles d'une seule
     * connexion, recalculés à chaque appel, sans mise en cache. `null` si la
     * connexion est inconnue ou exclue — à traiter en 404 par l'appelant.
     *
     * @return array{status: string, model_count: int|null}|null
     */
    public function status(string $name): ?array
    {
        $connections = config('database.connections', []);
        $excluded = config('modelbase.excluded_connections', []);

        if (! array_key_exists($name, $connections) || in_array($name, $excluded, true)) {
            return null;
        }

        $available = $this->availability->isAvailable($name);

        return [
            'status' => $available ? 'available' : 'unavailable',
            'model_count' => $available ? count($this->models->forConnection($name)) : null,
        ];
    }
}
