<?php

namespace App\Filament\Garcom\Concerns;

/**
 * Telas de mesa/retirada do /garcom: fora do alcance de quem entra no painel
 * só para executar checklists (User::CHECKLIST_PANEL_ROLES).
 */
trait RestritoAoSalao
{
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->atendeSalao();
    }
}
