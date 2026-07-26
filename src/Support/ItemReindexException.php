<?php

namespace Quatrebarbes\Modelbase\Support;

use RuntimeException;

/**
 * EX-446 : porte l'échec de la mise à jour de l'index Scout d'un item
 * (exception levée par le driver Scout du modèle hôte lors de l'invocation de
 * `searchable()`), pour permettre au contrôleur de traduire cet échec en
 * réponse HTTP explicite plutôt que de laisser remonter une erreur serveur
 * générique.
 */
class ItemReindexException extends RuntimeException
{
}
