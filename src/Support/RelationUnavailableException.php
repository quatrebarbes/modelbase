<?php

namespace Quatrebarbes\Modelbase\Support;

use RuntimeException;

/**
 * EX-431 : levée quand la connexion ciblée par une relation est indisponible
 * — le listing paginé des objets liés n'est jamais tenté sur une connexion
 * injoignable (contrairement à une requête qui échouerait en erreur SQL),
 * traduite en 409 par RelationController — même principe qu'EnsureConnectionIsNavigable
 * pour la navigation de premier niveau (EX-206).
 */
class RelationUnavailableException extends RuntimeException
{
}
