<?php

namespace App\Services\Ponto;

use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\User;

/**
 * Regras de quem pode agir sobre o ponto de quem. Ficam aqui, e não só nas
 * Policies, porque o Admin passa em qualquer Policy (Gate::before do Shield):
 * "ninguém corrige nem aprova o próprio ponto" só é garantido no Service.
 */
class PermissoesPonto
{
    public function podeCorrigir(User $gestor, Colaborador $colaborador): bool
    {
        return $gestor->can('corrigir:ponto') && ! $this->ehOProprioPonto($gestor, $colaborador);
    }

    public function podeAprovarAjuste(User $gestor, Colaborador $colaborador): bool
    {
        return $gestor->can('aprovar:ponto_ajuste') && ! $this->ehOProprioPonto($gestor, $colaborador);
    }

    /** Espelho próprio, ou de qualquer um com a permission de gestão. */
    public function podeVerEspelho(User $user, Colaborador $colaborador): bool
    {
        return $colaborador->user_id === $user->id || $user->can('view_espelho:ponto');
    }

    /** @throws PontoException */
    public function garantirCorrecao(User $gestor, Colaborador $colaborador): void
    {
        if ($this->ehOProprioPonto($gestor, $colaborador)) {
            throw new PontoException('Você não pode corrigir o próprio ponto. Peça a outro gestor ou ao administrador.');
        }

        if (! $gestor->can('corrigir:ponto')) {
            throw new PontoException('Você não tem permissão para corrigir o ponto.');
        }
    }

    /** @throws PontoException */
    public function garantirAprovacao(User $gestor, Colaborador $colaborador): void
    {
        if ($this->ehOProprioPonto($gestor, $colaborador)) {
            throw new PontoException('Você não pode aprovar ajuste do próprio ponto.');
        }

        if (! $gestor->can('aprovar:ponto_ajuste')) {
            throw new PontoException('Você não tem permissão para aprovar ajustes de ponto.');
        }
    }

    /** O Admin só escapa da regra se config('ponto.admin_corrige_proprio') estiver ligado. */
    private function ehOProprioPonto(User $user, Colaborador $colaborador): bool
    {
        if ($colaborador->user_id === null || $colaborador->user_id !== $user->id) {
            return false;
        }

        return ! ($user->hasRole('Admin') && config('ponto.admin_corrige_proprio'));
    }
}
