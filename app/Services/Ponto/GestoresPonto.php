<?php

namespace App\Services\Ponto;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quem recebe os avisos de gestão do ponto (ajuste solicitado, marcação
 * faltante, marcação em período fechado): Admin e Gerente.
 */
final class GestoresPonto
{
    /** @return Collection<int, User> */
    public static function destinatarios(): Collection
    {
        return User::query()->role(['Admin', 'Gerente'])->get();
    }
}
