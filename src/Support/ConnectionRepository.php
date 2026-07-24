<?php

namespace Quatrebarbes\Modelbase\Support;

/**
 * Assemble le listing des connexions déclarées dans `config/database.php`
 * (EX-201), sans les connexions exclues (`modelbase.excluded_connections`),
 * avec statut de disponibilité recalculé à chaque appel (EX-204/EX-208) et
 * comptage des modèles limité aux connexions disponibles (EX-205). Aucune
 * information sensible (hôte, port, identifiants) n'est exposée (EX-203).
 */
class ConnectionRepository
{
    public function __construct(
        private ConnectionAvailability $availability,
        private EloquentModelFinder $models,
    ) {
    }

    /**
     * @return array<int, array{name: string, driver: string|null, status: string, model_count: int|null}>
     */
    public function all(): array
    {
        $excluded = config('modelbase.excluded_connections', []);

        return collect(config('database.connections', []))
            ->reject(fn ($config, string $name) => in_array($name, $excluded, true))
            ->map(fn ($config, string $name) => $this->describe($name, $config))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{name: string, driver: string|null, status: string, model_count: int|null}
     */
    private function describe(string $name, array $config): array
    {
        $available = $this->availability->isAvailable($name);

        return [
            'name' => $name,
            'driver' => $config['driver'] ?? null,
            'status' => $available ? 'available' : 'unavailable',
            'model_count' => $available ? count($this->models->forConnection($name)) : null,
        ];
    }
}
