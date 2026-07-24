<?php

namespace Quatrebarbes\Modelbase\Support;

use RuntimeException;

/**
 * EX-420 : porte l'erreur d'intégrité référentielle rencontrée lors de la
 * suppression d'un item encore référencé par une clé étrangère d'un autre
 * enregistrement, telle que traduite par DatabaseErrorTranslator à partir de
 * la QueryException levée par le moteur de BDD — la suppression n'est jamais
 * forcée, l'erreur native est simplement relayée à l'utilisateur.
 */
class ItemDeletionException extends RuntimeException
{
}
