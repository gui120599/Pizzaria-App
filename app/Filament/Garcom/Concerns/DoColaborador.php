<?php

namespace App\Filament\Garcom\Concerns;

use App\Models\Colaborador;
use Illuminate\Support\Facades\Auth;

/**
 * Telas do colaborador no /garcom (ponto, espelho, repasses): só para quem
 * tem cadastro na folha, sempre sobre o próprio cadastro.
 */
trait DoColaborador
{
    public static function canAccess(): bool
    {
        return Auth::user()?->colaborador()->exists() ?? false;
    }

    protected function colaborador(): Colaborador
    {
        return Auth::user()->colaborador()->firstOrFail();
    }
}
