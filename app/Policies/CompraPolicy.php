<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Compra;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CompraPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:compra');
    }

    public function view(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('view:compra');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:compra');
    }

    public function update(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('update:compra');
    }

    /**
     * Só o rascunho pode ser excluído: compra confirmada tem movimentações de
     * estoque e títulos que ficariam órfãos. A exclusão em massa deve usar
     * authorizeIndividualRecords('delete'), já que deleteAny não vê o registro.
     */
    public function delete(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('delete:compra') && $compra->isRascunho();
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:compra');
    }

    public function restore(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('restore:compra');
    }

    public function forceDelete(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('force_delete:compra');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:compra');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:compra');
    }

    public function replicate(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('replicate:compra');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:compra');
    }

    /**
     * Confirmar uma compra em rascunho gera estoque + conta a pagar —
     * ação financeira/operacional sensível.
     */
    public function confirm(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('confirm:compra');
    }

    /**
     * Registrar devolução de itens ao fornecedor gera crédito financeiro
     * (PrestadorCredito) além de mexer no estoque — mesma sensibilidade de confirm().
     */
    public function registrarDevolucao(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('registrar:devolucao_compra');
    }

    /**
     * Corrigir a data de entrada de uma compra confirmada mexe em movimentações
     * de estoque, lotes e vencimentos de contas a pagar.
     */
    public function corrigirDataEntrada(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('corrigir:data_entrada_compra');
    }

    /**
     * Estornar uma compra confirmada devolve o estoque (com replay do custo
     * médio) e cancela os títulos: mexe em histórico já consolidado.
     */
    public function estornar(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('estornar:compra');
    }
}
