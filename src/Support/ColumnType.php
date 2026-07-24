<?php

namespace Quatrebarbes\Modelbase\Support;

/**
 * Type de rendu d'une colonne (EX-407), tel que défini dans le modèle de
 * données du module 4. Une colonne détectée comme clé étrangère (cf.
 * ColumnIntrospector) porte ce type plutôt que son type scalaire sous-jacent.
 */
enum ColumnType: string
{
    case STRING = 'string';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case DATE = 'date';
    case JSON = 'json';
    case FOREIGN_KEY = 'foreign_key';
}
