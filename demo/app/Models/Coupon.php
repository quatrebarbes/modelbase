<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle de démo sans migration associée (Phase 20, limite EX-303/EX-305) :
 * illustre l'affichage d'un modèle dont la table n'existe pas encore en base.
 */
class Coupon extends Model
{
    protected $fillable = ['code', 'discount_percent'];
}
