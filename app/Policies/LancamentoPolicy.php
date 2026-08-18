<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lancamento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LancamentoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:lancamento');
    }

    public function view(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('view:lancamento');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:lancamento');
    }

    public function update(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('update:lancamento');
    }

    public function delete(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('delete:lancamento');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:lancamento');
    }

    public function restore(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('restore:lancamento');
    }

    public function forceDelete(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('force_delete:lancamento');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:lancamento');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:lancamento');
    }

    public function replicate(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('replicate:lancamento');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:lancamento');
    }

    public function markAsPaid(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('approve:lancamento');
    }

    /**
     * Ação financeira mais sensível do módulo: desfaz um pagamento já
     * confirmado, mexendo no saldo. Mesma permission de negócio de Gerente.
     */
    public function reversePayment(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('estornar:pagamento');
    }

    /**
     * Compensar um título a pagar com pedidos de um fornecedor que também é cliente
     * (ver LancamentosTable::acaoCompensar()) — registra pagamento em dois títulos
     * de uma vez, mesma sensibilidade de markAsPaid.
     */
    public function compensar(AuthUser $authUser, Lancamento $lancamento): bool
    {
        return $authUser->can('approve:lancamento');
    }
}
